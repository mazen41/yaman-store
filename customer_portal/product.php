<?php
session_start();

// --- CONFIGURATION ---
require_once __DIR__ . '/../config/database.php';

$token = $_GET['token'] ?? '';
$product_id = $_GET['id'] ?? 0;

if (empty($token) || empty($product_id)) {
    die('<div class="p-10 text-center text-red-600 text-xl font-bold">رابط غير صالح.</div>');
}

// 1. Validate Customer Token
$stmt = $db->prepare("SELECT id, name, city_name, currency FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('<div class="p-10 text-center text-red-600 text-xl font-bold">الرابط غير صالح أو منتهي الصلاحية.</div>');
}

$customer_id = $customer['id'];
$customer_currency = $customer['currency'] ?? 'YER';
$customer_city_name = $customer['city_name'];
$shipping_cost = 0.00;

// 2. Fetch Product Data
try {
    // Fetch Product & Category
    $stmt = $db->prepare("SELECT p.*, c.name as category_name 
                          FROM products p 
                          LEFT JOIN categories c ON p.category_id = c.id 
                          WHERE p.id = ? AND p.is_active = 1");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        die('<div class="p-10 text-center text-red-600 text-xl font-bold">هذا المنتج غير موجود أو غير متاح حالياً.</div>');
    }

    // Fetch Product Images
    $img_stmt = $db->prepare("SELECT image_url, is_main FROM product_images WHERE product_id = ? ORDER BY is_main DESC, display_order ASC");
    $img_stmt->execute([$product_id]);
    $images = $img_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Attributes & Colors (Specific to this product's variants)
    $stmt_colors = $db->prepare("SELECT DISTINCT c.id, c.name, c.hex_code 
                                 FROM product_variants pv 
                                 JOIN colors c ON pv.color_id = c.id 
                                 WHERE pv.product_id = ? AND pv.is_active = 1 
                                 ORDER BY c.display_order");
    $stmt_colors->execute([$product_id]);
    $colors = $stmt_colors->fetchAll(PDO::FETCH_ASSOC);

    $stmt_attrs = $db->prepare("SELECT DISTINCT a.id AS attribute_id, a.name AS attribute_name, 
                                       av.id, av.value, a.display_order, av.display_order as v_order
                                FROM product_variants pv
                                JOIN attribute_values av ON pv.attribute_value_id = av.id
                                JOIN attributes a ON av.attribute_id = a.id
                                WHERE pv.product_id = ? AND pv.is_active = 1 
                                ORDER BY a.display_order, av.display_order");
    $stmt_attrs->execute([$product_id]);
    $raw_attributes = $stmt_attrs->fetchAll(PDO::FETCH_ASSOC);

    $attributes = [];
    $attribute_values = [];
    $processed_attr_ids = [];

    foreach ($raw_attributes as $row) {
        if (!in_array($row['attribute_id'], $processed_attr_ids)) {
            $attributes[] = [
                'id' => $row['attribute_id'],
                'name' => $row['attribute_name']
            ];
            $processed_attr_ids[] = $row['attribute_id'];
        }
        $attribute_values[] = [
            'id' => $row['id'],
            'attribute_id' => $row['attribute_id'],
            'value' => $row['value']
        ];
    }

    // Shipping Cost
    if (!empty($customer_city_name)) {
        $ship_stmt = $db->prepare("SELECT shipping_cost FROM cities WHERE name = ? AND is_active = 1 LIMIT 1");
        $ship_stmt->execute([$customer_city_name]);
        $city_data = $ship_stmt->fetch(PDO::FETCH_ASSOC);
        if ($city_data) {
            $shipping_cost = (float)$city_data['shipping_cost'];
        }
    }

} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

$base_price = (float)$product['price'];
$discount_price = ($product['discount_price'] > 0 && $product['discount_price'] < $base_price) ? (float)$product['discount_price'] : null;
$final_price = $discount_price ?? $base_price;
$main_image = !empty($images) ? $images[0]['image_url'] : '';

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($product['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; -webkit-tap-highlight-color: transparent; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        /* Options Selection Styles */
        .opt-btn { transition: all 0.2s ease; }
        .opt-btn.active { border-color: #111827; background-color: #111827; color: #ffffff; font-weight: bold; }
        .color-btn.active { border-color: #111827 !important; ring: 2px solid #d1d5db; ring-offset: 2px; }
        
        /* Sidebar Animations */
        #cart-sidebar { transition: transform 0.4s cubic-bezier(0.4,0,0.2,1); }
        .translate-x-full-rtl { transform: translateX(100%); }
        .navbar-custom-gradient { background: linear-gradient(135deg, #C7A46D, #9e7f4e); }
        .cart-item { background: #fff; border-radius: 16px; padding: 12px; display: flex; gap: 12px; border: 1px solid #f0f0ee; }
    </style>
</head>
<body class="bg-[#F5F4F0] pb-12">

    <!-- Navbar -->
    <nav class="navbar-custom-gradient sticky top-0 z-40 text-white border-b border-white/10 shadow-sm">
        <div class="max-w-5xl mx-auto px-4 h-16 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <a href="products.php?token=<?php echo htmlspecialchars($token); ?>" class="hover:bg-black/10 px-3 py-1.5 rounded-lg transition flex items-center gap-2 border border-transparent hover:border-white/20">
                    <i class="fas fa-arrow-right"></i>
                    <span class="font-bold text-sm">العودة للمتجر</span>
                </a>
            </div>
            <div class="flex items-center gap-4">
                <button onclick="toggleCart()" class="relative flex items-center justify-center p-2 text-white hover:text-gray-200 transition">
                    <i class="fas fa-shopping-bag text-2xl"></i>
                    <span id="cart-badge" class="absolute -top-0 -right-0 bg-gray-900 text-white text-[10px] font-black w-4 h-4 flex items-center justify-center rounded-full shadow">0</span>
                </button>
            </div>
        </div>
    </nav>

    <!-- Breadcrumbs -->
    <div class="max-w-5xl mx-auto px-4 py-4 text-[11px] sm:text-xs text-gray-500 font-semibold">
        <a href="products.php?token=<?php echo htmlspecialchars($token); ?>" class="hover:text-[#C7A46D]">الرئيسية</a> 
        <i class="fas fa-chevron-left text-[8px] mx-1.5"></i>
        <span class="text-[#C7A46D]"><?php echo htmlspecialchars($product['category_name']); ?></span>
        <i class="fas fa-chevron-left text-[8px] mx-1.5"></i>
        <span class="text-gray-800 line-clamp-1 inline-block align-bottom"><?php echo htmlspecialchars($product['name']); ?></span>
    </div>

    <!-- Product Details Section -->
    <div class="max-w-5xl mx-auto px-4">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden flex flex-col md:flex-row p-2 sm:p-4 gap-4 sm:gap-8">
            
            <!-- Right: Image Gallery -->
            <div class="md:w-5/12 flex flex-col items-center">
                <div class="w-full aspect-square bg-gray-50 rounded-2xl overflow-hidden shadow-sm mb-3 relative flex items-center justify-center border border-gray-100">
                    <?php if (!empty($images)): ?>
                        <img id="main-product-image" src="../<?php echo $images[0]['image_url']; ?>" alt="Product" class="w-full h-full object-cover transition duration-300">
                    <?php else: ?>
                        <i class="fas fa-image text-6xl text-gray-300"></i>
                    <?php endif; ?>
                    
                    <?php if ($discount_price): ?>
                        <?php $pct = round((($base_price - $discount_price) / $base_price) * 100); // Fixed PHP round() ?>
                        <span class="absolute top-3 right-3 bg-[#FF6B00] text-white px-2 py-1 rounded-md text-[11px] font-bold shadow-md">خصم <?php echo $pct; ?>%</span>
                    <?php endif; ?>
                </div>

                <?php if (count($images) > 1): ?>
                    <div class="flex gap-2 overflow-x-auto w-full pb-2 hide-scrollbar">
                        <?php foreach ($images as $index => $img): ?>
                            <button onclick="changeMainImage('../<?php echo $img['image_url']; ?>')" class="w-16 h-16 sm:w-20 sm:h-20 flex-shrink-0 border-2 rounded-xl overflow-hidden <?php echo $index === 0 ? 'border-[#C7A46D]' : 'border-gray-200'; ?> hover:border-[#C7A46D] transition">
                                <img src="../<?php echo $img['image_url']; ?>" class="w-full h-full object-cover">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Left: Product Info -->
            <div class="md:w-7/12 flex flex-col py-2 sm:py-4">
                <span class="text-[10px] font-bold text-[#C7A46D] mb-2 bg-[#C7A46D]/10 w-fit px-2 py-0.5 rounded-full tracking-wide uppercase"><?php echo htmlspecialchars($product['category_name']); ?></span>
                <h1 class="text-2xl sm:text-3xl font-black text-gray-900 mb-3 leading-tight"><?php echo htmlspecialchars($product['name']); ?></h1>
                
                <!-- Price -->
                <div class="flex items-end gap-2 mb-5 pb-5 border-b border-gray-100">
                    <span class="text-3xl sm:text-4xl font-black text-gray-900"><?php echo number_format($final_price, 2); ?></span>
                    <span class="text-xs text-gray-500 font-bold mb-1 sm:mb-2"><?php echo $customer_currency; ?></span>
                    <?php if ($discount_price): ?>
                        <span class="text-sm sm:text-base text-gray-400 line-through font-bold mb-1 sm:mb-2 mr-2"><?php echo number_format($base_price, 2); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Description -->
                <div class="text-gray-600 leading-relaxed mb-6">
                    <p class="font-bold text-gray-800 mb-1 text-sm">الوصف:</p>
                    <div class="text-xs sm:text-sm whitespace-pre-line text-gray-500"><?php echo htmlspecialchars($product['description'] ?: 'لا يوجد وصف متاح لهذا المنتج.'); ?></div>
                </div>

                <!-- Variants Selection -->
                <div class="space-y-5 mb-8 flex-grow">
                    
                    <!-- Colors -->
                    <?php if (!empty($colors)): ?>
                    <div>
                        <p class="text-[11px] font-bold text-gray-400 mb-2 uppercase tracking-wide">اللون <span class="text-red-500">*</span></p>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($colors as $color): ?>
                                <button type="button" id="btn-color-<?php echo $color['id']; ?>" onclick="selectVariant('color', <?php echo $color['id']; ?>, this)" class="opt-btn color-btn border-2 border-gray-200 rounded-xl px-3 py-1.5 text-xs font-semibold flex items-center gap-2 hover:border-gray-900 transition bg-white text-gray-600">
                                    <span class="w-4 h-4 rounded-full border border-gray-300 shadow-sm" style="background-color: <?php echo $color['hex_code']; ?>"></span>
                                    <?php echo htmlspecialchars($color['name']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Attributes (Sizes, Weights, etc.) -->
                    <?php foreach ($attributes as $attr): 
                        $vals = array_filter($attribute_values, function($v) use ($attr) { return $v['attribute_id'] == $attr['id']; });
                        if (!empty($vals)):
                    ?>
                    <div>
                        <p class="text-[11px] font-bold text-gray-400 mb-2 uppercase tracking-wide"><?php echo htmlspecialchars($attr['name']); ?> <span class="text-red-500">*</span></p>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($vals as $val): ?>
                                <button type="button" id="btn-attr-<?php echo $val['id']; ?>" onclick="selectVariant('attr', <?php echo $val['id']; ?>, this, <?php echo $attr['id']; ?>)" class="opt-btn attr-btn-<?php echo $attr['id']; ?> border border-gray-200 text-gray-600 bg-white rounded-xl px-4 py-1.5 text-sm font-semibold hover:border-gray-900 transition">
                                    <?php echo htmlspecialchars($val['value']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; endforeach; ?>

                </div>

                <!-- Action Area -->
                <div class="pt-4 border-t border-gray-100 flex gap-3 items-center mt-auto">
                    <div class="flex items-center border-2 border-gray-200 rounded-2xl overflow-hidden h-12 w-32 flex-shrink-0 bg-white">
                        <button type="button" onclick="changeQty(-1)" class="w-10 h-full flex items-center justify-center text-gray-500 hover:bg-gray-100 font-bold text-xl transition select-none">−</button>
                        <input type="number" id="product-qty" value="1" min="1" class="w-full h-full text-center bg-transparent font-extrabold text-sm outline-none pointer-events-none text-gray-800" readonly>
                        <button type="button" onclick="changeQty(1)" class="w-10 h-full flex items-center justify-center text-gray-500 hover:bg-gray-100 font-bold text-xl transition select-none">+</button>
                    </div>
                    <button onclick="addToCart()" class="flex-1 h-12 bg-gray-900 hover:bg-[#C7A46D] text-white rounded-2xl font-bold text-sm sm:text-base transition-colors flex items-center justify-center gap-2 shadow-md relative overflow-hidden">
                        <i class="fas fa-shopping-bag text-sm"></i> 
                        إضافة إلى السلة
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- CART SIDEBAR -->
    <!-- ========================================== -->
    <div id="cart-overlay" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[70] hidden" onclick="toggleCart()"></div>
    
    <div id="cart-sidebar" class="fixed top-0 right-0 h-full w-full sm:w-[390px] bg-white shadow-2xl z-[80] translate-x-full-rtl flex flex-col">
        <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
            <h3 class="font-black text-gray-900 flex items-center gap-2">
                <i class="fas fa-shopping-bag text-[#C7A46D]"></i> سلة المشتريات
                <span id="cart-header-count" class="bg-gray-100 text-gray-600 text-xs px-2 py-0.5 rounded-full">0</span>
            </h3>
            <button onclick="toggleCart()" class="w-8 h-8 bg-gray-100 hover:bg-gray-200 rounded-full flex items-center justify-center text-gray-500 transition text-sm"><i class="fas fa-times"></i></button>
        </div>
        
        <div id="cart-items-container" class="flex-grow overflow-y-auto p-4 space-y-3 bg-gray-50/60">
            <!-- Items injected by JS -->
        </div>
        
        <div class="p-5 bg-white border-t border-gray-100">
            <div class="space-y-2 mb-4 text-sm font-semibold">
                <div class="flex justify-between text-gray-500">
                    <span>المجموع الفرعي</span>
                    <span id="cart-subtotal" class="text-gray-800">0.00 <?php echo $customer_currency; ?></span>
                </div>
                
                <!-- Total Items Discount Row -->
                <div id="cart-total-discount-row" class="flex justify-between text-green-600 hidden">
                    <span>خصم السلة (توفير)</span>
                    <span id="cart-total-discount" class="font-bold">-0.00 <?php echo $customer_currency; ?></span>
                </div>
                
                <div class="flex justify-between text-gray-500 pb-3 border-b border-dashed border-gray-200">
                    <span>رسوم التوصيل</span>
                    <span class="text-[#C7A46D]"><?php echo number_format($shipping_cost, 2); ?> <?php echo $customer_currency; ?></span>
                </div>
                <div class="flex justify-between items-end pt-1">
                    <span class="text-gray-900 font-bold">الإجمالي</span>
                    <div class="text-left leading-none">
                        <span id="cart-total-final" class="text-2xl font-black text-gray-900">0.00</span>
                        <span class="text-[10px] text-gray-400 block"><?php echo $customer_currency; ?></span>
                    </div>
                </div>
            </div>
            <button onclick="checkout()" id="checkout-btn" class="w-full h-13 py-3 bg-gray-900 hover:bg-[#C7A46D] text-white rounded-2xl font-bold text-base transition-colors flex justify-center items-center gap-2 shadow-lg">
                <i class="fas fa-check-circle text-sm"></i> إتمام الطلب
            </button>
        </div>
    </div>


    <!-- ========================================== -->
    <!-- JAVASCRIPT LOGIC -->
    <!-- ========================================== -->
    <script>
        // --- DATA INJECTION FROM PHP ---
        const PRODUCT_ID = <?php echo $product_id; ?>;
        const PRODUCT_NAME = <?php echo json_encode($product['name']); ?>;
        
        // Passing both prices for discount logic
        const PRODUCT_FINAL_PRICE = <?php echo $final_price; ?>;
        const PRODUCT_ORIGINAL_PRICE = <?php echo $base_price; ?>;
        
        const PRODUCT_IMG = "<?php echo $main_image; ?>";
        
        const ALL_COLORS = <?php echo json_encode($colors); ?>;
        const ALL_ATTRIBUTES = <?php echo json_encode($attributes); ?>;
        const ALL_ATTRIBUTE_VALUES = <?php echo json_encode($attribute_values); ?>;
        
        const SHIPPING_COST = <?php echo $shipping_cost; ?>;
        const CURRENCY = "<?php echo $customer_currency; ?>";
        const CUSTOMER_ID = <?php echo $customer_id; ?>;
        const TOKEN = "<?php echo htmlspecialchars($token); ?>";

        // --- STATE ---
        let cart = JSON.parse(localStorage.getItem('cart_' + CUSTOMER_ID)) || [];
        let selectedOptions = { color: null, attributes: {} };
        
        // Track requirements for validation
        window.requiredSelections = { color: false, attrs: [] };

        // --- INIT PAGE ---
        document.addEventListener('DOMContentLoaded', () => {
            renderCart();
            setupAutoSelectAndValidation();
        });

        // Set up requirement rules and auto-select the FIRST option of the FIRST group only
        function setupAutoSelectAndValidation() {
            let firstRenderedGroup = null;
            let firstItemToClick = null;

            // Colors setup
            if (ALL_COLORS.length > 0) {
                window.requiredSelections.color = true;
                firstRenderedGroup = 'color';
                firstItemToClick = `btn-color-${ALL_COLORS[0].id}`;
            }

            // Attributes setup
            ALL_ATTRIBUTES.forEach(attr => {
                window.requiredSelections.attrs.push(attr.id);
                if (!firstRenderedGroup) {
                    firstRenderedGroup = `attr_${attr.id}`;
                    const firstVal = ALL_ATTRIBUTE_VALUES.find(v => v.attribute_id == attr.id);
                    if (firstVal) firstItemToClick = `btn-attr-${firstVal.id}`;
                }
            });

            // Auto-click the first item
            if (firstItemToClick) {
                setTimeout(() => {
                    const btn = document.getElementById(firstItemToClick);
                    if (btn) btn.click();
                }, 50);
            }
        }

        // Image Gallery Swap
        function changeMainImage(src) {
            document.getElementById('main-product-image').src = src;
            const btns = event.target.closest('.flex').querySelectorAll('button');
            btns.forEach(b => { b.classList.remove('border-[#C7A46D]'); b.classList.add('border-gray-200'); });
            event.target.closest('button').classList.remove('border-gray-200');
            event.target.closest('button').classList.add('border-[#C7A46D]');
        }

        // Quantity Control
        function changeQty(delta) {
            const input = document.getElementById('product-qty');
            let val = parseInt(input.value) + delta;
            if (val < 1) val = 1;
            input.value = val;
        }

        // Variant Selection
        function selectVariant(type, id, btnEl, attrId = null) {
            const groupClass = type === 'color' ? '.color-btn' : `.attr-btn-${attrId}`;
            
            document.querySelectorAll(groupClass).forEach(b => {
                b.classList.remove('active', 'border-gray-900', 'bg-gray-900', 'text-white');
                if(type !== 'color') b.classList.add('text-gray-600', 'bg-white');
            });
            
            btnEl.classList.add('active', 'border-gray-900');
            if(type !== 'color') {
                btnEl.classList.remove('text-gray-600', 'bg-white');
                btnEl.classList.add('bg-gray-900', 'text-white');
            }

            if (type === 'color') {
                selectedOptions.color = id;
            } else if (type === 'attr') {
                selectedOptions.attributes[attrId] = id;
            }
        }

        // --- CART LOGIC ---
        function addToCart() {
            // 1. Validation
            if (window.requiredSelections.color && selectedOptions.color === null) {
                alert('يرجى اختيار اللون.'); return;
            }
            for (let attrId of window.requiredSelections.attrs) {
                if (!selectedOptions.attributes[attrId]) {
                    const attrName = ALL_ATTRIBUTES.find(a => a.id == attrId)?.name || 'الخاصية';
                    alert(`يرجى اختيار ${attrName}.`); return;
                }
            }

            const qty = parseInt(document.getElementById('product-qty').value);

            // 2. Create Unique Cart Key
            let keyParts = [PRODUCT_ID];
            if(selectedOptions.color) keyParts.push(`c${selectedOptions.color}`);
            
            Object.keys(selectedOptions.attributes).sort().forEach(attrId => {
                keyParts.push(`a${attrId}v${selectedOptions.attributes[attrId]}`);
            });
            const cartKey = keyParts.join('_');

            // 3. Format variant label (using ' / ' so it splits nicely in renderCart)
            let variantLabelParts = [];
            if(selectedOptions.color) {
                const cName = ALL_COLORS.find(c => c.id == selectedOptions.color)?.name;
                if(cName) variantLabelParts.push(`${cName}`);
            }
            for(let aId in selectedOptions.attributes) {
                const vId = selectedOptions.attributes[aId];
                const valName = ALL_ATTRIBUTE_VALUES.find(v => v.id == vId)?.value;
                if(valName) variantLabelParts.push(`${valName}`);
            }
            const variantText = variantLabelParts.join(' / ');

            // 4. Add to Array
            const existingItemIndex = cart.findIndex(item => item.cartKey === cartKey);
            if(existingItemIndex > -1) {
                cart[existingItemIndex].qty += qty;
            } else {
                cart.push({
                    cartKey: cartKey,
                    productId: PRODUCT_ID,
                    name: PRODUCT_NAME,
                    price: PRODUCT_FINAL_PRICE,
                    originalPrice: PRODUCT_ORIGINAL_PRICE,
                    qty: qty,
                    variantText: variantText,
                    img: PRODUCT_IMG
                });
            }

            saveCart();
            toggleCart(true); // Open Sidebar
        }

        function saveCart() {
            localStorage.setItem('cart_' + CUSTOMER_ID, JSON.stringify(cart));
            renderCart();
        }

        function updateCartQty(cartKey, delta) {
            const item = cart.find(i => i.cartKey === cartKey);
            if(item) {
                item.qty += delta;
                if(item.qty <= 0) {
                    removeCartItem(cartKey);
                    return;
                }
                saveCart();
            }
        }

        function removeCartItem(cartKey) {
            cart = cart.filter(i => i.cartKey !== cartKey);
            saveCart();
        }

        function renderCart() {
            const container          = document.getElementById('cart-items-container');
            const badge              = document.getElementById('cart-badge');
            const headerCount        = document.getElementById('cart-header-count');
            const subtotalEl         = document.getElementById('cart-subtotal');
            const totalDiscountRowEl = document.getElementById('cart-total-discount-row');
            const totalDiscountEl    = document.getElementById('cart-total-discount');
            const totalEl            = document.getElementById('cart-total-final');
            const checkoutBtn        = document.getElementById('checkout-btn');

            let totalQty = 0;
            let sumOfFinalPrices = 0;    
            let sumOfOriginalPrices = 0; 

            container.innerHTML = '';

            if (cart.length === 0) {
                container.innerHTML = `<div class="h-full flex flex-col items-center justify-center text-gray-400 mt-16"><div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mb-4"><i class="fas fa-shopping-bag text-3xl text-gray-300"></i></div><p class="font-bold text-gray-500">السلة فارغة</p></div>`;
                badge.innerText = '0'; headerCount.innerText = '0'; subtotalEl.innerText = `0.00 ${CURRENCY}`;
                totalDiscountRowEl.classList.add('hidden'); totalDiscountEl.innerText = `-0.00 ${CURRENCY}`;
                totalEl.innerText = '0.00'; checkoutBtn.disabled = true; checkoutBtn.classList.add('opacity-50','cursor-not-allowed'); return;
            }

            checkoutBtn.disabled = false; checkoutBtn.classList.remove('opacity-50','cursor-not-allowed');

            cart.forEach(item => {
                totalQty += item.qty;
                const itemOriginalPrice = item.originalPrice || item.price; 
                sumOfFinalPrices += item.qty * item.price;
                sumOfOriginalPrices += item.qty * itemOriginalPrice;

                const itemHasDiscount = itemOriginalPrice > item.price;
                const imgHtml = item.img ? `<img src="../${item.img}" class="w-16 h-20 object-cover rounded-xl flex-shrink-0 border border-gray-100">` : `<div class="w-16 h-20 bg-gray-100 rounded-xl flex items-center justify-center text-gray-300 flex-shrink-0"><i class="fas fa-image"></i></div>`;
                
                // Formatted Tags for chosen attributes
                let attributesHtml = '';
                if (item.variantText) {
                    const parts = item.variantText.split(' / ');
                    parts.forEach(part => {
                        attributesHtml += `<span class="inline-block bg-[#C7A46D]/10 text-[#C7A46D] border border-[#C7A46D]/20 text-[10px] px-1.5 py-0.5 rounded font-bold mr-1 mb-1">${part}</span>`;
                    });
                }

                // Show Individual item discount clearly
                let discountHtml = '';
                if (itemHasDiscount) {
                    discountHtml = `
                        <div class="flex items-center gap-1.5 mt-1 bg-green-50 p-1.5 rounded border border-green-100 w-max">
                            <span class="text-[10px] text-gray-500 line-through">${itemOriginalPrice.toFixed(2)} ${CURRENCY}</span>
                            <span class="text-[10px] font-black text-green-600"><i class="fas fa-tags"></i> خصم القطعة: ${(itemOriginalPrice - item.price).toFixed(2)} ${CURRENCY}</span>
                        </div>
                    `;
                }

                container.innerHTML += `
                    <div class="cart-item">
                        ${imgHtml}
                        <div class="flex-1 flex flex-col min-w-0 py-1">
                            <div class="flex justify-between items-start gap-1">
                                <h4 class="text-xs font-bold text-gray-800 leading-snug line-clamp-2 flex-1">${item.name}</h4>
                                <button onclick="removeCartItem('${item.cartKey}')" class="text-gray-300 hover:text-red-500 transition flex-shrink-0 text-xs ml-1"><i class="fas fa-trash-alt"></i></button>
                            </div>
                            
                            <div class="mt-1">${attributesHtml}</div>
                            ${discountHtml}

                            <div class="flex items-center justify-between mt-auto pt-2">
                                <span class="text-sm font-black text-gray-900">${item.price.toFixed(2)} <span class="text-[9px] text-gray-400">${CURRENCY}</span></span>
                                <div class="flex items-center border border-gray-200 rounded-xl overflow-hidden h-7">
                                    <button onclick="updateCartQty('${item.cartKey}',-1)" class="w-7 h-full flex items-center justify-center text-gray-500 hover:bg-gray-100 transition text-xs">−</button>
                                    <span class="w-6 text-center text-xs font-bold text-gray-800">${item.qty}</span>
                                    <button onclick="updateCartQty('${item.cartKey}',1)" class="w-7 h-full flex items-center justify-center text-gray-500 hover:bg-gray-100 transition text-xs">+</button>
                                </div>
                            </div>
                        </div>
                    </div>`;
            });

            const totalDiscountAmount = sumOfOriginalPrices - sumOfFinalPrices;

            badge.innerText = totalQty; headerCount.innerText = totalQty;
            subtotalEl.innerText = `${sumOfOriginalPrices.toFixed(2)} ${CURRENCY}`; 

            if (totalDiscountAmount > 0.01) { 
                totalDiscountRowEl.classList.remove('hidden');
                totalDiscountEl.innerText = `-${totalDiscountAmount.toFixed(2)} ${CURRENCY}`;
            } else { totalDiscountRowEl.classList.add('hidden'); }

            totalEl.innerText = (sumOfFinalPrices + SHIPPING_COST).toFixed(2); 
        }

        function toggleCart(forceOpen = false) {
            const sidebar  = document.getElementById('cart-sidebar');
            const overlay  = document.getElementById('cart-overlay');
            const isOpen   = !sidebar.classList.contains('translate-x-full-rtl');
            if (forceOpen || !isOpen) { sidebar.classList.remove('translate-x-full-rtl'); overlay.classList.remove('hidden'); document.body.style.overflow = 'hidden'; } 
            else { sidebar.classList.add('translate-x-full-rtl'); overlay.classList.add('hidden'); document.body.style.overflow = ''; }
        }

        function checkout() { if (cart.length === 0) return; window.location.href = `checkout.php?token=${TOKEN}`; }

    </script>
</body>
</html>