<?php
session_start();

// --- CONFIGURATION ---
require_once __DIR__ . '/../config/database.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('<div class="p-10 text-center text-red-600 text-xl font-bold">وصول غير صالح. يرجى استخدام الرابط الذي تم تزويدك به.</div>');
}

// 1. Validate Customer Token
$stmt = $db->prepare("SELECT id, name, city_name, currency, enable_create_self_order FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('<div class="p-10 text-center text-red-600 text-xl font-bold">الرابط غير صالح أو منتهي الصلاحية. يرجى التواصل مع الدعم.</div>');
}

$customer_id = $customer['id'];
$customer_name = $customer['name'];
$customer_city_name = $customer['city_name'];
$customer_currency = $customer['currency'] ?? 'YER';

// 2. Fetch Necessary Data
$categories_flat = [];
$products = [];
$colors = [];
$attributes = [];
$attribute_values_flat = [];
$shipping_cost = 0.00;
$slides = [];
$product_images = [];
$product_variants_data = [];

try {
    // Fetch Active Slides
    $slides_stmt = $db->query("SELECT * FROM product_slides WHERE is_active = 1 ORDER BY display_order ASC, created_at DESC");
    $slides = $slides_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Categories (all, including parent_id info)
    $categories_stmt = $db->query("SELECT id, name, parent_id, image_url FROM categories WHERE is_active = 1 ORDER BY display_order ASC, name ASC");
    $categories_flat = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build hierarchical category structure: main cats + their subs
    $main_categories = [];
    $sub_categories_map = []; // keyed by parent_id

    foreach ($categories_flat as $cat) {
        if ($cat['parent_id'] === null) {
            $main_categories[] = $cat;
        } else {
            $sub_categories_map[$cat['parent_id']][] = $cat;
        }
    }

    // Products
    $products_sql = "SELECT 
                        p.id, p.name, p.sku, p.unit, p.tagline, p.category_id, p.subcategory_id,
                        p.description, p.price, p.discount_price, p.display_order, p.total_quantity,
                        p.purchase_amount, p.product_quantity, p.product_type, p.mandub_name, p.manager_notes,
                        p.is_active, p.created_at, p.updated_at,
                        c_main.name as category_name,
                        c_sub.name as subcategory_name
                     FROM products p
                     LEFT JOIN categories c_main ON p.category_id = c_main.id
                     LEFT JOIN categories c_sub ON p.subcategory_id = c_sub.id
                     WHERE p.is_active = 1
                     ORDER BY p.display_order ASC, p.created_at DESC";
    $products_stmt = $db->query($products_sql);
    $products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all images for all products
    $all_images_stmt = $db->query("SELECT id, product_id, image_url, is_main, display_order FROM product_images ORDER BY product_id, is_main DESC, display_order ASC");
    while ($img = $all_images_stmt->fetch(PDO::FETCH_ASSOC)) {
        $product_images[$img['product_id']][] = $img;
    }

    // Fetch all variants for all products
    $all_variants_stmt = $db->query("SELECT pv.id, pv.product_id, pv.color_id, pv.attribute_value_id, pv.price, pv.quantity, pv.image_url,
                                             c.name as color_name, c.hex_code as color_hex,
                                             av.value as attribute_value_name, a.name as attribute_name
                                      FROM product_variants pv
                                      LEFT JOIN colors c ON pv.color_id = c.id
                                      LEFT JOIN attribute_values av ON pv.attribute_value_id = av.id
                                      LEFT JOIN attributes a ON av.attribute_id = a.id
                                      ORDER BY pv.product_id, pv.color_id, pv.attribute_value_id");
    while ($variant = $all_variants_stmt->fetch(PDO::FETCH_ASSOC)) {
        $product_variants_data[$variant['product_id']][] = $variant;
    }
    
    $colors_stmt = $db->query("SELECT id, name, hex_code FROM colors WHERE is_active = 1 ORDER BY display_order ASC, name ASC");
    $colors = $colors_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $attributes_stmt = $db->query("SELECT id, name FROM attributes WHERE is_active = 1 ORDER BY display_order ASC, name ASC");
    $attributes = $attributes_stmt->fetchAll(PDO::FETCH_ASSOC);

    $all_attribute_values_stmt = $db->query("SELECT av.id, av.attribute_id, av.value, a.name as attribute_name FROM attribute_values av JOIN attributes a ON av.attribute_id = a.id WHERE av.is_active = 1 AND a.is_active = 1 ORDER BY a.display_order ASC, av.display_order ASC");
    $attribute_values_flat = $all_attribute_values_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($customer_city_name)) {
        $shipping_stmt = $db->prepare("SELECT shipping_cost FROM cities WHERE name = ? AND is_active = 1 LIMIT 1");
        $shipping_stmt->execute([$customer_city_name]);
        $city_data = $shipping_stmt->fetch(PDO::FETCH_ASSOC);
        if ($city_data) {
            $shipping_cost = (float)$city_data['shipping_cost'];
        }
    }

} catch (PDOException $e) {
    $error_message = "خطأ في تحميل البيانات: " . $e->getMessage();
}

// Map images and variants to products
foreach ($products as $key => $product) {
    $products[$key]['images'] = $product_images[$product['id']] ?? [];
    $products[$key]['variants'] = $product_variants_data[$product['id']] ?? [];
    $main_img = array_filter($products[$key]['images'], fn($img) => $img['is_main'] == 1);
    if (empty($main_img) && !empty($products[$key]['images'])) {
        $main_img = [$products[$key]['images'][0]];
    }
    $products[$key]['main_image_url'] = !empty($main_img) ? "../" . current($main_img)['image_url'] : '';
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>متجر المنتجات</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; -webkit-tap-highlight-color: transparent; box-sizing: border-box; }
        body { background: #F5F4F0; }

        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        /* --- SLIDER STYLES --- */
        .portal-slider { 
            position: relative; width: 100%; height: 350px; overflow: hidden; border-radius: 20px; 
            background: #f3f4f6; user-select: none; direction: ltr;
        }
        .slider-container { display: flex; height: 100%; width: 100%; cursor: grab; }
        .slider-container:active { cursor: grabbing; }
        .slide { flex: 0 0 100%; width: 100%; height: 100%; position: relative; }
        .slide-img-main { width: 100%; height: 100%; object-fit: cover; display: block; }
        .slider-dots { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); display: flex; gap: 6px; z-index: 20; }
        .slider-dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.5); cursor: pointer; transition: all 0.3s ease; }
        .slider-dot.active { background: #C7A46D; width: 24px; border-radius: 10px; }
        
        @media (max-width: 768px) { .portal-slider { height: 180px; } }

        /* ── Category Circles ── */
        .cat-item { display: flex; flex-direction: column; align-items: center; gap: 5px; cursor: pointer; flex-shrink: 0; }
        .cat-ring { width: 64px; height: 64px; border-radius: 50%; border: 2.5px solid #e5e7eb; overflow: hidden; position: relative; background: #fff; transition: border-color 0.25s, transform 0.25s, box-shadow 0.25s; }
        .cat-ring img { width: 100%; height: 100%; object-fit: cover; }
        .cat-ring .cat-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 22px; background: linear-gradient(135deg, #f3f4f6, #e9e9e5); color: #9ca3af; }
        .cat-ring-sub { width: 48px; height: 48px; border-radius: 50%; border: 2px solid #e5e7eb; overflow: hidden; position: relative; background: #fff; transition: border-color 0.25s, transform 0.25s, box-shadow 0.25s; }
        .cat-ring-sub img { width: 100%; height: 100%; object-fit: cover; }
        .cat-ring-sub .cat-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 16px; background: linear-gradient(135deg, #f9f9f7, #f0f0ec); color: #b0b7c3; }
        .cat-item.active .cat-ring, .cat-item.active .cat-ring-sub { border-color: #C7A46D; box-shadow: 0 0 0 3px rgba(199,164,109,0.2); transform: scale(1.08); }
        .cat-item.active .cat-label, .cat-item.active .cat-label-sub { color: #C7A46D; font-weight: 700; }
        
        /* ── Product Cards Animations ── */
        .product-card { animation: fadeUp 0.4s ease both; cursor: pointer; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }

        /* Product Card Slider */
        .product-card-img-slider { position: relative; width: 100%; height: 100%; overflow: hidden; user-select: none; }
        .product-card-img-wrapper { display: flex; height: 100%; width: 100%; transition: transform 0.3s ease-in-out; }
        .product-card-img { flex: 0 0 100%; width: 100%; height: 100%; object-fit: cover; display: block; }
        .product-card-nav-btn { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.4); color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; z-index: 10; opacity: 0; transition: opacity 0.2s; }
        .product-card:hover .product-card-nav-btn { opacity: 1; }
        .product-card-nav-btn.prev { right: 4px; }
        .product-card-nav-btn.next { left: 4px; }
        .product-card-dots { position: absolute; bottom: 6px; left: 50%; transform: translateX(-50%); display: flex; gap: 4px; z-index: 10; }
        .product-card-dot { width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,0.6); transition: background 0.2s; }
        .product-card-dot.active { background: #C7A46D; }

        /* ── Cart Sidebar & Modals ── */
        #cart-sidebar { transition: transform 0.4s cubic-bezier(0.4,0,0.2,1); }
        .translate-x-full-rtl { transform: translateX(100%); }
        #product-modal { transition: opacity 0.25s ease; }
        #modal-content { transition: transform 0.25s ease; }

        .navbar-custom-gradient { background: linear-gradient(135deg, #C7A46D, #9e7f4e); }
        .cart-item { background: #fff; border-radius: 16px; padding: 12px; display: flex; gap: 12px; border: 1px solid #f0f0ee; }
        
        @keyframes bounceCart { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.3); } }
        .bounce-cart { animation: bounceCart 0.3s ease-in-out; }
    </style>
</head>
<body class="pb-24">

    <!-- Navbar -->
    <nav class="navbar-custom-gradient sticky top-0 z-50 text-white border-b border-white/10 shadow-sm">
        <div class="max-w-5xl mx-auto px-4 h-16 py-3 flex justify-between items-center">
            <div class="flex items-center gap-2.5">
                <img src="../assets/images/logo.png" alt="Logo" class="w-9 h-9 rounded-lg object-contain bg-white/10 p-0.5" 
                     onerror="this.outerHTML='<div class=\'w-9 h-9 rounded-lg bg-black/20 flex items-center justify-center\'><i class=\'fas fa-store text-white text-sm\'></i></div>'">
                <span class="font-bold text-base tracking-wide">متجر المنتجات</span>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                <a href="orders.php?token=<?php echo htmlspecialchars($token); ?>" class="flex items-center gap-1.5 text-xs font-semibold text-white bg-black/15 hover:bg-black/30 px-3 py-1.5 rounded-lg transition border border-white/10 shadow-sm">
                    <i class="fas fa-list-alt"></i> <span class="hidden sm:inline">طلباتي</span>
                </a>
                <a href="../customer_portal/portal.php?token=<?php echo htmlspecialchars($token); ?>" class="flex items-center gap-1.5 text-xs font-semibold text-white bg-black/15 hover:bg-black/30 px-3 py-1.5 rounded-lg transition border border-white/10 shadow-sm">
                    <i class="fas fa-arrow-right"></i> <span class="hidden sm:inline">البوابة</span>
                </a>
                <button onclick="toggleCart()" class="relative p-2 text-white hover:text-gray-200 transition ml-1" id="cart-btn-nav">
                    <i class="fas fa-shopping-bag text-xl"></i>
                    <span id="cart-badge" class="absolute -top-0.5 -right-0.5 bg-gray-900 text-white text-[10px] font-black w-4 h-4 flex items-center justify-center rounded-full shadow">0</span>
                </button>
            </div>
        </div>
    </nav>

    <div class="max-w-5xl mx-auto px-4 mt-4">
        
        <?php if (isset($error_message)): ?>
            <div class="bg-red-50 border-r-4 border-red-500 text-red-700 px-4 py-3 rounded-xl mb-4 text-sm">
                <i class="fas fa-exclamation-circle ml-2"></i><?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- ─── Image Slider Section ─── -->
        <?php if (!empty($slides)): ?>
        <div class="portal-slider mb-6 shadow-sm">
            <div class="slider-container" id="sliderContainer">
                <?php foreach ($slides as $index => $slide): 
                    $imgUrl = "../" . htmlspecialchars($slide['image_path']);
                ?>
                <div class="slide">
                    <?php if (!empty($slide['link_url'])): ?><a href="<?php echo htmlspecialchars($slide['link_url']); ?>" class="w-full h-full block relative"><?php endif; ?>
                    <img src="<?php echo $imgUrl; ?>" alt="Slide" class="slide-img-main" loading="lazy">
                    <?php if(!empty($slide['title']) || !empty($slide['description'])): ?>
                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-4 pb-8 text-white text-right" dir="rtl">
                            <?php if(!empty($slide['title'])): ?><h3 class="font-bold text-sm mb-1"><?= htmlspecialchars($slide['title']) ?></h3><?php endif; ?>
                            <?php if(!empty($slide['description'])): ?><p class="text-[11px] opacity-90 truncate"><?= htmlspecialchars($slide['description']) ?></p><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($slide['link_url'])): ?></a><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($slides) > 1): ?>
            <div class="slider-dots">
                <?php foreach ($slides as $i => $s): ?>
                <button class="slider-dot <?php echo $i === 0 ? 'active' : ''; ?>" onclick="goToSlide(<?php echo $i; ?>)"></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <script>
            let currentSlide = 0; const totalSlides = <?php echo count($slides); ?>;
            const container = document.getElementById('sliderContainer');
            const dots = document.querySelectorAll('.slider-dot'); let autoSlideInterval;
            container.style.transition = 'transform 0.5s cubic-bezier(0.25, 1, 0.5, 1)';
            function updateSliderPosition() { container.style.transform = `translateX(-${currentSlide * 100}%)`; dots.forEach((dot, idx) => dot.classList.toggle('active', idx === currentSlide)); }
            function goToSlide(index) { currentSlide = index; if (currentSlide < 0) currentSlide = totalSlides - 1; if (currentSlide >= totalSlides) currentSlide = 0; container.style.transition = 'transform 0.5s cubic-bezier(0.25, 1, 0.5, 1)'; updateSliderPosition(); }
            function startAutoSlide() { clearInterval(autoSlideInterval); if(totalSlides > 1) { autoSlideInterval = setInterval(() => { goToSlide(currentSlide + 1); }, 4000); } }
            let isDragging = false; let startX = 0;
            container.addEventListener('touchstart', (e) => { startX = e.touches[0].clientX; isDragging = true; clearInterval(autoSlideInterval); }, {passive: true});
            container.addEventListener('mousedown', (e) => { startX = e.clientX; isDragging = true; clearInterval(autoSlideInterval); e.preventDefault(); });
            container.addEventListener('touchend', (e) => { if(!isDragging) return; handleSwipe(startX, e.changedTouches[0].clientX); isDragging = false; startAutoSlide(); });
            container.addEventListener('mouseup', (e) => { if(!isDragging) return; handleSwipe(startX, e.clientX); isDragging = false; startAutoSlide(); });
            container.addEventListener('mouseleave', (e) => { if (isDragging) { handleSwipe(startX, e.clientX); isDragging = false; startAutoSlide(); } });
            function handleSwipe(start, end) { const threshold = 50; if (start - end > threshold) goToSlide(currentSlide + 1); else if (end - start > threshold) goToSlide(currentSlide - 1); }
            startAutoSlide();
        </script>
        <?php endif; ?>

        <!-- ─── Search and Filtering ─── -->
        <div class="bg-white rounded-xl p-3 mb-6 shadow-sm border border-gray-100">
            <div class="flex gap-2 items-center">
                <div class="relative flex-1">
                    <input type="text" id="search-input" placeholder="البحث عن منتج بالاسم أو الوصف..." class="w-full pl-10 pr-10 py-2.5 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#C7A46D] focus:border-transparent transition-all outline-none text-sm">
                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400"><i class="fas fa-search"></i></div>
                </div>
                <button onclick="toggleAdvancedFilters()" class="w-11 h-11 flex items-center justify-center bg-gray-50 border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-100 hover:text-[#C7A46D] transition-colors focus:outline-none focus:ring-2 focus:ring-[#C7A46D]">
                    <i class="fas fa-sliders-h"></i>
                </button>
            </div>
            <div id="advanced-filters" class="hidden mt-3 pt-3 border-t border-gray-100 grid-cols-2 gap-3">
                <div>
                    <label for="min-price" class="text-[10px] font-bold text-gray-500 block mb-1">الحد الأدنى للسعر</label>
                    <input type="number" id="min-price" placeholder="من..." min="0" step="0.01" class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#C7A46D] outline-none text-sm transition-all">
                </div>
                <div>
                    <label for="max-price" class="text-[10px] font-bold text-gray-500 block mb-1">الحد الأقصى للسعر</label>
                    <input type="number" id="max-price" placeholder="إلى..." min="0" step="0.01" class="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#C7A46D] outline-none text-sm transition-all">
                </div>
            </div>
        </div>

        <!-- ─── Category Circles (Hierarchical) ─── -->
        <div class="pb-3 border-b border-gray-200 mb-5">
            <div class="flex gap-4 overflow-x-auto hide-scrollbar pb-2 items-start" id="main-category-scroll">
                <div class="cat-item active" data-filter-type="all" data-category-id="all" onclick="filterCategory(this)">
                    <div class="cat-ring"><div class="cat-fallback"><i class="fas fa-th-large"></i></div></div>
                    <span class="cat-label text-xs text-center whitespace-normal leading-tight w-16">الكل</span>
                </div>
                <?php foreach ($main_categories as $main_cat): ?>
                <div class="cat-item" data-filter-type="main" data-category-id="<?php echo $main_cat['id']; ?>" onclick="filterCategory(this)">
                    <div class="cat-ring">
                        <?php if (!empty($main_cat['image_url'])): ?><img src="../<?php echo htmlspecialchars($main_cat['image_url']); ?>" alt="<?php echo htmlspecialchars($main_cat['name']); ?>" loading="lazy"><?php else: ?><div class="cat-fallback"><i class="fas fa-tag"></i></div><?php endif; ?>
                    </div>
                    <span class="cat-label text-xs text-center whitespace-normal leading-tight w-16"><?php echo htmlspecialchars($main_cat['name']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div id="sub-category-wrapper" class="hidden mt-2 pt-3 border-t border-gray-100">
                <?php foreach ($main_categories as $main_cat): if (isset($sub_categories_map[$main_cat['id']]) && count($sub_categories_map[$main_cat['id']]) > 0): ?>
                <div class="sub-cat-group hidden flex gap-4 overflow-x-auto hide-scrollbar items-start" id="sub-cats-<?php echo $main_cat['id']; ?>">
                    <?php foreach ($sub_categories_map[$main_cat['id']] as $sub_cat): ?>
                    <div class="cat-item sub-cat-item" data-filter-type="sub" data-category-id="<?php echo $sub_cat['id']; ?>" onclick="filterSubCategory(this)">
                        <div class="cat-ring-sub">
                            <?php if (!empty($sub_cat['image_url'])): ?><img src="../<?php echo htmlspecialchars($sub_cat['image_url']); ?>" alt="<?php echo htmlspecialchars($sub_cat['name']); ?>" loading="lazy"><?php else: ?><div class="cat-fallback"><i class="fas fa-tag" style="font-size:11px;"></i></div><?php endif; ?>
                        </div>
                        <span class="cat-label-sub text-[10px] text-center whitespace-normal leading-tight w-14"><?php echo htmlspecialchars($sub_cat['name']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; endforeach; ?>
            </div>
        </div>

        <!-- ─── Products Section ─── -->
        <main>
            <div id="products-grid" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3 sm:gap-4"></div>
             <div id="no-products-found" class="col-span-full text-center py-20 text-gray-400 hidden">
                <i class="fas fa-box-open text-5xl mb-3 opacity-40"></i>
                <p class="font-bold text-gray-500 mt-2">لا توجد منتجات مطابقة للبحث والفلاتر.</p>
            </div>
        </main>
    </div>

    <!-- ── Modal ── -->
    <div id="product-modal" class="fixed inset-0 bg-black/60 z-[60] hidden items-center justify-center p-3 sm:p-5 opacity-0" style="display:none">
        <div id="modal-content" class="bg-white rounded-3xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col md:flex-row overflow-hidden relative scale-95" onclick="event.stopPropagation()">
            <button type="button" onclick="closeProductModal(event)" class="absolute top-3 left-3 z-20 w-8 h-8 rounded-full bg-white/90 shadow flex items-center justify-center text-gray-700 hover:bg-gray-100 transition text-sm">
                <i class="fas fa-times"></i>
            </button>
            <div class="w-full md:w-5/12 bg-gray-50 relative" style="min-height:260px">
                <img id="modal-img" src="" class="absolute inset-0 w-full h-full object-cover hidden">
                <div id="modal-img-placeholder" class="absolute inset-0 flex items-center justify-center text-gray-300 bg-gray-100"><i class="fas fa-image text-5xl"></i></div>
            </div>
            <div class="w-full md:w-7/12 p-5 sm:p-7 flex flex-col overflow-y-auto hide-scrollbar" style="max-height:60vh; md:max-height:none">
                <p id="modal-category" class="text-xs text-[#C7A46D] font-bold mb-1 uppercase tracking-wide truncate"></p>
                <h2 id="modal-title" class="text-lg font-black text-gray-900 mb-2 leading-tight"></h2>
                <div id="modal-stock-alert" class="hidden text-orange-500 bg-orange-50 px-3 py-1.5 rounded-lg text-xs font-bold mb-3 w-max border border-orange-100"></div>
                <div class="flex items-baseline gap-2 mb-4">
                    <span id="modal-price" class="text-2xl font-black text-gray-900"></span>
                    <span id="modal-price-old" class="text-sm text-gray-400 line-through hidden"></span>
                    <span class="text-xs text-gray-400"><?php echo $customer_currency; ?></span>
                </div>
                <p id="modal-desc" class="text-xs text-gray-500 mb-5 leading-relaxed"></p>
                <div id="modal-options" class="flex-grow space-y-4 mb-5"></div>
                <div class="pt-4 border-t border-gray-100 flex gap-3 items-center">
                    <div class="flex items-center border-2 border-gray-200 rounded-2xl overflow-hidden h-11 w-28 flex-shrink-0">
                        <button onclick="changeModalQty(-1)" class="w-10 h-full flex items-center justify-center text-gray-500 hover:bg-gray-100 font-bold transition text-lg select-none">−</button>
                        <input type="number" id="modal-qty" value="1" min="1" class="flex-1 h-full text-center bg-transparent font-bold text-gray-800 outline-none border-none pointer-events-none text-sm" readonly>
                        <button onclick="changeModalQty(1)" class="w-10 h-full flex items-center justify-center text-gray-500 hover:bg-gray-100 font-bold transition text-lg select-none">+</button>
                    </div>
                    <button id="modal-add-btn" class="flex-1 h-11 bg-gray-900 hover:bg-[#C7A46D] text-white rounded-2xl font-bold text-sm transition-colors flex items-center justify-center gap-2 shadow relative overflow-hidden">
                        <i class="fas fa-shopping-bag text-xs"></i> <span>إضافة للسلة</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Cart Sidebar ── -->
    <div id="cart-overlay" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[70] hidden" onclick="toggleCart()"></div>
    <div id="cart-sidebar" class="fixed top-0 right-0 h-full w-full sm:w-[390px] bg-white shadow-2xl z-[80] translate-x-full-rtl flex flex-col">
        <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
            <h3 class="font-black text-gray-900 flex items-center gap-2">
                <i class="fas fa-shopping-bag text-[#C7A46D]"></i> سلة المشتريات
                <span id="cart-header-count" class="bg-gray-100 text-gray-600 text-xs px-2 py-0.5 rounded-full">0</span>
            </h3>
            <button onclick="toggleCart()" class="w-8 h-8 bg-gray-100 hover:bg-gray-200 rounded-full flex items-center justify-center text-gray-500 transition text-sm"><i class="fas fa-times"></i></button>
        </div>
        <div id="cart-items-container" class="flex-grow overflow-y-auto p-4 space-y-3 bg-gray-50/60"></div>
        <div class="p-5 bg-white border-t border-gray-100">
            <div class="space-y-2 mb-4 text-sm font-semibold">
                <div class="flex justify-between text-gray-500"><span>المجموع الفرعي</span><span id="cart-subtotal" class="text-gray-800">0.00 <?php echo $customer_currency; ?></span></div>
                
                <div class="flex justify-between text-gray-500 pb-3 border-b border-dashed border-gray-200"><span>رسوم التوصيل</span><span class="text-[#C7A46D]"><?php echo number_format($shipping_cost,2); ?> <?php echo $customer_currency; ?></span></div>
                
                <!-- Final Total: two modes -->
                <div class="flex justify-between items-end pt-1">
                    <span class="text-gray-900 font-bold">الإجمالي</span>
                    <div class="text-left leading-none">
                        <!-- With discount: show "500.00 - 200.00" above "= 300.00" -->
                        <div id="cart-total-with-discount" class="hidden">
                            <div class="text-[11px] text-gray-500 mb-1 whitespace-nowrap">
                                <span id="cart-pre-discount-total" class="line-through">0.00</span>
                                <span class="text-red-500 mx-1">-</span>
                                <span id="cart-discount-inline" class="text-red-500 font-bold">0.00</span>
                            </div>
                            <div class="flex items-baseline justify-end gap-1">
                                <span class="text-gray-400 text-base">=</span>
                                <span id="cart-total-final" class="text-2xl font-black text-gray-900">0.00</span>
                            </div>
                            <span class="text-[10px] text-gray-400 block"><?php echo $customer_currency; ?></span>
                        </div>
                        <!-- No discount: plain total -->
                        <div id="cart-total-no-discount">
                            <span id="cart-total-final-plain" class="text-2xl font-black text-gray-900">0.00</span>
                            <span class="text-[10px] text-gray-400 block"><?php echo $customer_currency; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <button onclick="checkout()" id="checkout-btn" class="w-full h-13 py-3 bg-gray-900 hover:bg-[#C7A46D] text-white rounded-2xl font-bold text-base transition-colors flex justify-center items-center gap-2 shadow-lg">
                <i class="fas fa-check-circle text-sm"></i> إتمام الطلب
            </button>
        </div>
    </div>

    <!-- ── JS Logic ── -->
    <script>
        const ALL_PRODUCTS          = <?php echo json_encode($products); ?>;
        const ALL_COLORS            = <?php echo json_encode($colors); ?>;
        const ALL_ATTRIBUTES        = <?php echo json_encode($attributes); ?>;
        const ALL_ATTRIBUTE_VALUES  = <?php echo json_encode($attribute_values_flat); ?>;
        const SHIPPING_COST         = <?php echo (float)$shipping_cost; ?>;
        const CURRENCY              = "<?php echo $customer_currency; ?>";
        const CUSTOMER_ID           = <?php echo (int)$customer_id; ?>;
        const TOKEN                 = "<?php echo htmlspecialchars($token); ?>";
        const PRODUCT_IMG_BASE_PATH = "../";

        let cart = JSON.parse(localStorage.getItem('cart_' + CUSTOMER_ID)) || [];
        let currentModalProduct = null;
        let selectedOptions = { color: null, attributes: {} };
        let selectedVariant = null;

        let currentFilterType     = 'all';
        let currentFilterCatId    = 'all';
        let currentSearchTerm     = '';
        let currentMinPrice       = '';
        let currentMaxPrice       = '';

        function init() {
            renderCart();
            renderProducts(ALL_PRODUCTS);
            document.getElementById('product-modal').addEventListener('click', function(event) { if (event.target === this) closeProductModal(event); });
            document.getElementById('search-input').addEventListener('input', debounce(applyFilters, 300));
            document.getElementById('min-price').addEventListener('input', debounce(applyFilters, 300));
            document.getElementById('max-price').addEventListener('input', debounce(applyFilters, 300));
        }

        function toggleAdvancedFilters() {
            const filterDiv = document.getElementById('advanced-filters');
            if(filterDiv.classList.contains('hidden')){ filterDiv.classList.remove('hidden'); filterDiv.classList.add('grid'); } 
            else { filterDiv.classList.add('hidden'); filterDiv.classList.remove('grid'); }
        }

        function debounce(func, delay) {
            let timeout;
            return function(...args) {
                const context = this; clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), delay);
            };
        }

        function applyFilters() {
            currentSearchTerm = document.getElementById('search-input').value.toLowerCase();
            currentMinPrice = parseFloat(document.getElementById('min-price').value);
            currentMaxPrice = parseFloat(document.getElementById('max-price').value);

            let filtered = ALL_PRODUCTS.filter(product => {
                const productName = product.name.toLowerCase();
                const productDescription = product.description ? product.description.toLowerCase() : '';
                const finalPrice = (product.discount_price > 0 && product.discount_price < product.price) ? parseFloat(product.discount_price) : parseFloat(product.price);

                if (currentFilterType === 'main') { if (product.category_id != currentFilterCatId) return false; } 
                else if (currentFilterType === 'sub') { if (product.subcategory_id != currentFilterCatId) return false; }
                if (currentSearchTerm && !(productName.includes(currentSearchTerm) || productDescription.includes(currentSearchTerm))) return false;
                if (currentMinPrice && finalPrice < currentMinPrice) return false;
                if (currentMaxPrice && finalPrice > currentMaxPrice) return false;
                return true;
            });
            renderProducts(filtered);
        }

        function filterCategory(el) {
            document.querySelectorAll('#main-category-scroll .cat-item').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
            document.querySelectorAll('.sub-cat-item').forEach(c => c.classList.remove('active'));

            currentFilterType  = el.dataset.filterType;
            currentFilterCatId = el.dataset.categoryId;

            const wrapper = document.getElementById('sub-category-wrapper');
            const allGroups = document.querySelectorAll('.sub-cat-group');
            allGroups.forEach(g => g.classList.add('hidden'));

            if (currentFilterType === 'main') {
                const subGroup = document.getElementById('sub-cats-' + currentFilterCatId);
                if (subGroup) { subGroup.classList.remove('hidden'); wrapper.classList.remove('hidden'); } 
                else { wrapper.classList.add('hidden'); }
            } else { wrapper.classList.add('hidden'); }
            applyFilters();
        }

        function filterSubCategory(el) {
            document.querySelectorAll('.sub-cat-item').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
            currentFilterType  = el.dataset.filterType;
            currentFilterCatId = el.dataset.categoryId;
            applyFilters();
        }

        function initProductCardSliders() {
            document.querySelectorAll('.product-card').forEach(card => {
                const imageWrapper = card.querySelector('.product-card-img-wrapper');
                const images = card.querySelectorAll('.product-card-img');
                const prevBtn = card.querySelector('.product-card-nav-btn.prev');
                const nextBtn = card.querySelector('.product-card-nav-btn.next');
                const dotsContainer = card.querySelector('.product-card-dots');

                if (!imageWrapper || images.length <= 1) {
                    if (prevBtn) prevBtn.remove(); if (nextBtn) nextBtn.remove(); if (dotsContainer) dotsContainer.remove();
                    return;
                }

                let currentSlide = 0; let isHovering = false; let autoSlideInterval;
                dotsContainer.innerHTML = '';
                images.forEach((_, i) => {
                    const dot = document.createElement('div'); dot.classList.add('product-card-dot');
                    dot.addEventListener('click', (e) => { e.stopPropagation(); goToSlide(i); });
                    dotsContainer.appendChild(dot);
                });

                function updateSliderPosition() {
                    imageWrapper.style.transform = `translateX(-${currentSlide * 100}%)`;
                    dotsContainer.querySelectorAll('.product-card-dot').forEach((dot, idx) => { dot.classList.toggle('active', idx === currentSlide); });
                }
                function goToSlide(index) { currentSlide = (index + images.length) % images.length; updateSliderPosition(); }
                function startAutoSlide() { stopAutoSlide(); if (images.length > 1 && !isHovering) { autoSlideInterval = setInterval(() => { goToSlide(currentSlide + 1); }, 3000); } }
                function stopAutoSlide() { clearInterval(autoSlideInterval); }

                if (prevBtn) prevBtn.addEventListener('click', (e) => { e.stopPropagation(); goToSlide(currentSlide - 1); startAutoSlide(); });
                if (nextBtn) nextBtn.addEventListener('click', (e) => { e.stopPropagation(); goToSlide(currentSlide + 1); startAutoSlide(); });

                card.addEventListener('mouseenter', () => { isHovering = true; stopAutoSlide(); });
                card.addEventListener('mouseleave', () => { isHovering = false; startAutoSlide(); });
                updateSliderPosition(); startAutoSlide();
            });
        }

        function effectiveProductStock(product) {
            const tq = parseInt(product['total_quantity'], 10);
            const pq = parseInt(product['product_quantity'], 10);
            if (!isNaN(tq) && tq > 0) return tq;
            if (!isNaN(pq) && pq > 0) return pq;
            return 0;
        }

        function renderProducts(productsToRender) {
            const grid = document.getElementById('products-grid');
            const noProductsFound = document.getElementById('no-products-found');
            grid.innerHTML = '';

            if (productsToRender.length === 0) { noProductsFound.classList.remove('hidden'); return; }
            noProductsFound.classList.add('hidden');

            productsToRender.forEach((product, index) => {
                const qty            = effectiveProductStock(product);
                const base_price     = parseFloat(product['price']);
                const discount_price = parseFloat(product['discount_price']);
                const has_discount   = (discount_price > 0 && discount_price < base_price);
                const final_price    = has_discount ? discount_price : base_price;
                
                let badge = null;
                const created_time = new Date(product['created_at']).getTime();
                const seven_days_ago = new Date().getTime() - (7 * 24 * 60 * 60 * 1000);

                if (qty < 1) { badge = { text: 'نفذت الكمية', class: 'bg-gray-700' }; }
                else if (qty < 5) { badge = { text: `تبقى ${qty} فقط`, class: 'bg-orange-500' }; } 
                else if (has_discount) { const pct = Math.round(((base_price - discount_price) / base_price) * 100); badge = { text: `خصم ${pct}%`, class: 'bg-[#FF6B00]' }; } 
                else if (created_time > seven_days_ago) { badge = { text: 'جديد', class: 'bg-purple-500' }; }

                let imagesHtml = '';
                if (product.images.length > 0) {
                    imagesHtml = `<div class="product-card-img-slider"><div class="product-card-img-wrapper" style="direction: ltr;">`;
                    product.images.forEach(img => { imagesHtml += `<img src="${PRODUCT_IMG_BASE_PATH}${img.image_url}" class="product-card-img" loading="lazy" alt="${product.name}">`; });
                    imagesHtml += `</div>`;
                    if (product.images.length > 1) {
                        imagesHtml += `<button class="product-card-nav-btn prev"><i class="fas fa-chevron-left"></i></button>
                                       <button class="product-card-nav-btn next"><i class="fas fa-chevron-right"></i></button><div class="product-card-dots"></div>`;
                    }
                    imagesHtml += `</div>`;
                } else {
                    imagesHtml = `<div class="w-full h-full flex items-center justify-center text-gray-300"><i class="fas fa-image text-4xl"></i></div>`;
                }

                let categoryDisplay = product.category_name || '';
                if(product.subcategory_name) { categoryDisplay += ` <span class="text-gray-400 text-[8px] mx-0.5"><i class="fas fa-chevron-left"></i></span> ${product.subcategory_name}`; }

                // Note: The quick add button directly calls openProductModal() to ensure attributes are chosen
                grid.innerHTML += `
                    <div class="product-card group block bg-white rounded-lg overflow-hidden hover:shadow-lg transition-all duration-200 relative border border-gray-100 ${qty <= 0 ? 'opacity-75' : ''}"
                         data-product-id="${product.id}" onclick="window.location.href='product.php?id=${product.id}&token=${TOKEN}'">

                        <div class="relative aspect-square overflow-hidden bg-gray-50">
                            ${imagesHtml}
                            ${badge ? `<div class="absolute bottom-2 right-2 z-20"><div class="text-white text-[10px] font-bold px-1.5 py-0.5 rounded shadow-sm ${badge.class}">${badge.text}</div></div>` : ''}
                        </div>

                        <div class="p-2.5 pb-3">
                            ${has_discount ? `<div class="text-[10px] text-gray-400 line-through mb-0.5">${number_format(base_price, 2)} ${CURRENCY}</div>` : `<div class="text-[10px] text-transparent mb-0.5 select-none">&nbsp;</div>`}
                            <div class="flex items-center justify-between mb-1.5">
                                <div class="flex items-baseline gap-1"><span class="text-sm sm:text-base font-black text-gray-900">${number_format(final_price, 2)}</span><span class="text-[9px] text-gray-500">${CURRENCY}</span></div>
                                <button onclick="event.preventDefault(); event.stopPropagation(); ${qty > 0 ? `openProductModal(${product.id})` : ''}"
                                        class="flex-shrink-0 w-7 h-7 sm:w-8 sm:h-8 rounded-full border border-gray-300 flex items-center justify-center transition-all duration-150 ${qty <= 0 ? 'opacity-50 bg-gray-100 cursor-not-allowed text-gray-400' : 'text-gray-700 hover:border-[#C7A46D] hover:bg-[#C7A46D]/10 hover:text-[#C7A46D] active:scale-95'}"
                                        ${qty <= 0 ? 'disabled' : ''} aria-label="Add to cart">
                                    <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>
                                </button>
                            </div>
                            <div class="text-[10px] text-[#C7A46D] font-bold mb-1 line-clamp-1">${categoryDisplay}</div>
                            <h3 class="text-[11px] sm:text-xs font-semibold text-gray-700 leading-snug line-clamp-2 min-h-[2rem]">${product.name}</h3>
                            <div class="flex items-center gap-1.5 text-[9px] text-gray-400 mt-1.5">
                                <div class="flex items-center gap-0.5 text-[#C7A46D]">
                                    <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                    <span class="font-bold text-gray-600">4.9</span>
                                </div>
                                ${product.total_quantity && (product.total_quantity - product.product_quantity) > 0 ? `<span>|</span><span>تم بيع ${Math.max(0, product.total_quantity - product.product_quantity) >= 1000 ? (Math.max(0, product.total_quantity - product.product_quantity)/1000).toFixed(1)+'K' : Math.max(0, product.total_quantity - product.product_quantity)}</span>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            initProductCardSliders();
        }

        function number_format(number, decimals) { return parseFloat(number).toFixed(decimals); }

        function openProductModal(productId) {
            const product = ALL_PRODUCTS.find(p => p.id == productId);
            if (!product) return;

            currentModalProduct = product;
            selectedOptions = { color: null, attributes: {} };
            selectedVariant = null;

            document.getElementById('modal-qty').value = 1;
            document.getElementById('modal-title').innerText = product.name;
            let categoryText = product.category_name || '';
            if(product.subcategory_name) categoryText += ` > ${product.subcategory_name}`;
            document.getElementById('modal-category').innerText = categoryText;
            document.getElementById('modal-desc').innerHTML = (product.description || 'لا يوجد وصف متاح.').replace(/\n/g,'<br>');

            updateModalPriceAndStock(product);
            setModalImage(product.main_image_url);
            
            // Build options and trigger default selection logic
            buildModalOptions(product);
            
            document.getElementById('modal-add-btn').onclick = () => addCurrentToCart();

            const modal   = document.getElementById('product-modal');
            const content = document.getElementById('modal-content');
            modal.style.display = 'flex'; void modal.offsetWidth;
            modal.classList.remove('opacity-0'); modal.classList.add('opacity-100');
            content.classList.remove('scale-95'); content.classList.add('scale-100');
        }

        function closeProductModal(event) {
            if (event) { event.preventDefault(); event.stopPropagation(); }
            const modal   = document.getElementById('product-modal');
            const content = document.getElementById('modal-content');
            modal.classList.remove('opacity-100'); modal.classList.add('opacity-0');
            content.classList.remove('scale-100'); content.classList.add('scale-95');
            setTimeout(() => { modal.style.display = 'none'; }, 250);
        }

        function setModalImage(imageUrl) {
            const imgEl = document.getElementById('modal-img');
            const phEl  = document.getElementById('modal-img-placeholder');
            if (imageUrl) { imgEl.src = imageUrl; imgEl.classList.remove('hidden'); phEl.classList.add('hidden'); } 
            else { imgEl.classList.add('hidden'); phEl.classList.remove('hidden'); }
        }

        function updateModalPriceAndStock(productOrVariant) {
            const basePrice = parseFloat(productOrVariant.price || currentModalProduct.price);
            const discountPrice = parseFloat(productOrVariant.discount_price || currentModalProduct.discount_price || 0);
            const hasDiscount = discountPrice > 0 && discountPrice < basePrice;
            const finalPrice = hasDiscount ? discountPrice : basePrice;
            const maxStock = (() => {
                if (productOrVariant.quantity !== undefined && productOrVariant.quantity !== null && productOrVariant.quantity !== '') {
                    const v = parseInt(productOrVariant.quantity, 10);
                    if (!isNaN(v) && v > 0) return v;
                }
                return effectiveProductStock(currentModalProduct || productOrVariant);
            })();

            document.getElementById('modal-price').innerText = finalPrice.toFixed(2);
            const oldEl = document.getElementById('modal-price-old');
            if (hasDiscount) { oldEl.innerText = basePrice.toFixed(2); oldEl.classList.remove('hidden'); }
            else { oldEl.classList.add('hidden'); }

            const alertBox = document.getElementById('modal-stock-alert');
            if (maxStock < 5 && maxStock > 0) {
                alertBox.innerText = `تبقى ${maxStock} فقط في المخزون!`; alertBox.classList.remove('hidden');
            } else if (maxStock <= 0) {
                alertBox.innerText = `نفذت الكمية!`; alertBox.classList.remove('hidden');
                document.getElementById('modal-add-btn').disabled = true;
                document.getElementById('modal-add-btn').classList.add('opacity-50', 'cursor-not-allowed');
            } else { 
                alertBox.classList.add('hidden'); 
                document.getElementById('modal-add-btn').disabled = false;
                document.getElementById('modal-add-btn').classList.remove('opacity-50', 'cursor-not-allowed');
            }
            document.getElementById('modal-qty').setAttribute('max', maxStock);
            if (parseInt(document.getElementById('modal-qty').value) > maxStock) { document.getElementById('modal-qty').value = maxStock > 0 ? maxStock : 1; } 
            else if (parseInt(document.getElementById('modal-qty').value) < 1 && maxStock > 0) { document.getElementById('modal-qty').value = 1; } 
            else if (maxStock === 0) { document.getElementById('modal-qty').value = 0; }
        }

        function changeModalQty(delta) {
            const input = document.getElementById('modal-qty');
            let v = parseInt(input.value) + delta;
            const maxQty = selectedVariant ? parseInt(selectedVariant.quantity, 10) : (currentModalProduct ? effectiveProductStock(currentModalProduct) : 0);
            if (v > maxQty) { alert(`نعتذر، المتوفر في المخزون هو ${maxQty} فقط.`); v = maxQty; }
            if (v < 1) v = 1;
            if (maxQty === 0) v = 0;
            input.value = v;
        }

        function buildModalOptions(product) {
            const container = document.getElementById('modal-options');
            container.innerHTML = '';
            const availableVariants = product.variants;
            let optionsHtml = '';

            window.requiredSelections = { color: false, attrs: [] };
            let firstRenderedGroup = null; 
            let firstAvailableItemToClick = null; 

            // Check Colors
            const hasColors = availableVariants.some(v => v.color_id !== null);
            if (hasColors && ALL_COLORS.length > 0) {
                window.requiredSelections.color = true;
                if (!firstRenderedGroup) firstRenderedGroup = 'color';

                optionsHtml += `<div><p class="text-[11px] font-bold text-gray-400 mb-2 uppercase tracking-wide">اللون <span class="text-red-500">*</span></p><div class="flex flex-wrap gap-2">`;
                let foundFirst = false;
                ALL_COLORS.forEach(c => {
                    const isAvailable = availableVariants.some(v => v.color_id == c.id);
                    if (!isAvailable) return; // Only show available colors for this product
                    
                    if (firstRenderedGroup === 'color' && !foundFirst) {
                        firstAvailableItemToClick = { type: 'color', id: c.id };
                        foundFirst = true;
                    }
                    const btnId = `btn-color-${c.id}`;
                    optionsHtml += `<button id="${btnId}" onclick="selectOption('color',${c.id},this)" title="${c.name}" 
                                            class="opt-btn-color w-8 h-8 rounded-full border-2 border-gray-200 hover:scale-110 transition-transform relative flex items-center justify-center" 
                                            style="background-color:${c.hex_code}">
                                            <span class="check-icon hidden text-white text-[10px] drop-shadow"><i class="fas fa-check"></i></span>
                                     </button>`;
                });
                optionsHtml += `</div></div>`;
            }

            // Check Attributes
            const usedAttrIds = [...new Set(availableVariants.filter(v => v.attribute_value_id !== null).map(v => {
                const av = ALL_ATTRIBUTE_VALUES.find(a => a.id == v.attribute_value_id);
                return av ? av.attribute_id : null;
            }).filter(Boolean))];

            ALL_ATTRIBUTES.forEach(attr => {
                if (usedAttrIds.includes(attr.id)) {
                    window.requiredSelections.attrs.push(attr.id);
                    if (!firstRenderedGroup) firstRenderedGroup = `attr_${attr.id}`;

                    const values = ALL_ATTRIBUTE_VALUES.filter(v => v.attribute_id == attr.id);
                    optionsHtml += `<div><p class="text-[11px] font-bold text-gray-400 mb-2 uppercase tracking-wide">${attr.name} <span class="text-red-500">*</span></p><div class="flex flex-wrap gap-2">`;
                    let foundFirstAttr = false;
                    values.forEach(v => {
                         const isAvailable = availableVariants.some(variant => variant.attribute_value_id == v.id);
                         if (!isAvailable) return; // Only show available attributes for this product

                         if (firstRenderedGroup === `attr_${attr.id}` && !foundFirstAttr) {
                             firstAvailableItemToClick = { type: 'attr', id: v.id, attrId: attr.id };
                             foundFirstAttr = true;
                         }
                         const btnId = `btn-attr-${v.id}`;
                         optionsHtml += `<button id="${btnId}" onclick="selectOption('attr',${v.id},this,${attr.id})" 
                                                class="opt-btn-attr-${attr.id} border border-gray-200 text-gray-600 bg-white rounded-xl px-3 py-1 text-xs font-semibold hover:border-gray-900 transition-colors">
                                                ${v.value}
                                        </button>`;
                    });
                    optionsHtml += `</div></div>`;
                }
            });
            container.innerHTML = optionsHtml;

            // Auto-select only the first item of the very first attribute group
            setTimeout(() => {
                if (firstAvailableItemToClick) {
                    if (firstAvailableItemToClick.type === 'color') {
                        const btn = document.getElementById(`btn-color-${firstAvailableItemToClick.id}`);
                        if (btn) btn.click();
                    } else if (firstAvailableItemToClick.type === 'attr') {
                        const btn = document.getElementById(`btn-attr-${firstAvailableItemToClick.id}`);
                        if (btn) btn.click();
                    }
                }
            }, 50);
        }

        window.selectOption = function(type, id, btnEl, attrId = null) {
            if (type === 'color') {
                document.querySelectorAll('.opt-btn-color').forEach(b => {
                    b.classList.remove('border-gray-900','ring-2','ring-gray-300','ring-offset-2');
                    b.querySelector('.check-icon').classList.add('hidden');
                });
                if (selectedOptions.color === id) { selectedOptions.color = null; }
                else {
                    btnEl.classList.add('border-gray-900','ring-2','ring-gray-300','ring-offset-2');
                    btnEl.querySelector('.check-icon').classList.remove('hidden');
                    selectedOptions.color = id;
                }
            } else {
                document.querySelectorAll(`.opt-btn-attr-${attrId}`).forEach(b => {
                    b.classList.remove('border-gray-900','bg-gray-900','text-white');
                    b.classList.add('bg-white','text-gray-600');
                });
                if (selectedOptions.attributes[attrId] === id) { delete selectedOptions.attributes[attrId]; }
                else {
                    btnEl.classList.remove('bg-white','text-gray-600');
                    btnEl.classList.add('border-gray-900','bg-gray-900','text-white');
                    selectedOptions.attributes[attrId] = id;
                }
            }
            updateModalVariant();
        }

        function updateModalVariant() {
            selectedVariant = null; let matchingVariant = null;
            if (currentModalProduct && currentModalProduct.variants.length > 0) {
                matchingVariant = currentModalProduct.variants.find(v => {
                    let colorMatch = (selectedOptions.color === null && v.color_id === null) || (v.color_id == selectedOptions.color);
                    if (!colorMatch) return false;
                    let attributesMatch = true;
                    for (let attrId in selectedOptions.attributes) {
                        if (v.attribute_value_id !== parseInt(selectedOptions.attributes[attrId])) attributesMatch = false;
                    }
                    return colorMatch && attributesMatch;
                });
            }
            if (matchingVariant) {
                selectedVariant = matchingVariant; updateModalPriceAndStock(selectedVariant);
                if (selectedVariant.image_url) setModalImage(PRODUCT_IMG_BASE_PATH + selectedVariant.image_url);
                else setModalImage(currentModalProduct.main_image_url);
            } else {
                selectedVariant = null; updateModalPriceAndStock(currentModalProduct); setModalImage(currentModalProduct.main_image_url);
            }
        }

        function triggerFlyAnimation(imgSrc, startRect) {
            const cartBtn = document.getElementById('cart-btn-nav');
            const targetRect = cartBtn.getBoundingClientRect();
            const ghost = document.createElement('img');
            ghost.src = imgSrc;
            ghost.style.position = 'fixed'; ghost.style.left = startRect.left + 'px'; ghost.style.top = startRect.top + 'px';
            ghost.style.width = startRect.width + 'px'; ghost.style.height = startRect.height + 'px';
            ghost.style.objectFit = 'cover'; ghost.style.borderRadius = '50%'; ghost.style.zIndex = '99999';
            ghost.style.transition = 'all 0.6s cubic-bezier(0.25, 1, 0.5, 1)'; ghost.style.boxShadow = '0 10px 25px rgba(0,0,0,0.2)';
            document.body.appendChild(ghost); void ghost.offsetWidth;
            ghost.style.left = targetRect.left + 'px'; ghost.style.top = targetRect.top + 'px';
            ghost.style.width = '24px'; ghost.style.height = '24px'; ghost.style.opacity = '0.3';
            setTimeout(() => { document.body.removeChild(ghost); cartBtn.classList.add('bounce-cart'); setTimeout(() => cartBtn.classList.remove('bounce-cart'), 300); }, 600);
        }

        function addCurrentToCart() {
            if (!currentModalProduct) return;

            // --- Validation for Required Options ---
            if (window.requiredSelections) {
                if (window.requiredSelections.color && selectedOptions.color === null) {
                    alert('يرجى اختيار اللون.'); return;
                }
                for (let attrId of window.requiredSelections.attrs) {
                    if (!selectedOptions.attributes[attrId]) {
                        const attrName = ALL_ATTRIBUTES.find(a => a.id == attrId)?.name || 'الخاصية';
                        alert(`يرجى اختيار ${attrName}.`); return;
                    }
                }
            }

            const qty = parseInt(document.getElementById('modal-qty').value);
            let itemPrice, originalPrice, maxStock, itemImage, variantText = '';
            
            const productForPriceCalc = selectedVariant || currentModalProduct;
            const itemBasePrice = parseFloat(productForPriceCalc.price || currentModalProduct.price);
            const itemDiscountPrice = parseFloat(productForPriceCalc.discount_price || currentModalProduct.discount_price || 0);
            const itemHasDiscount = itemDiscountPrice > 0 && itemDiscountPrice < itemBasePrice;
            const itemFinalPrice = itemHasDiscount ? itemDiscountPrice : itemBasePrice;

            itemPrice = itemFinalPrice; originalPrice = itemBasePrice; 

            if (selectedVariant) {
                maxStock = parseInt(selectedVariant.quantity);
                itemImage = selectedVariant.image_url || currentModalProduct.main_image_url;
                let variantParts = [];
                if (selectedVariant.color_name) variantParts.push(selectedVariant.color_name);
                if (selectedVariant.attribute_value_name) variantParts.push(selectedVariant.attribute_value_name);
                variantText = variantParts.join(' / ');
            } else {
                maxStock = effectiveProductStock(currentModalProduct);
                itemImage = currentModalProduct.main_image_url;
            }

            if (qty <= 0 || maxStock <= 0) { alert('المنتج غير متوفر أو الكمية غير صالحة.'); return; }

            let keyParts = [currentModalProduct.id];
            if (selectedVariant) { keyParts.push('v' + selectedVariant.id); } 
            else {
                if (selectedOptions.color) keyParts.push('c' + selectedOptions.color);
                Object.keys(selectedOptions.attributes).sort().forEach(a => keyParts.push('a'+a+'v'+selectedOptions.attributes[a]));
            }
            const cartKey = keyParts.join('_');

            const existing = cart.findIndex(i => i.cartKey === cartKey);
            let potentialTotal = qty;
            if (existing > -1) potentialTotal += cart[existing].qty;
            if (potentialTotal > maxStock) { alert(`لا يمكنك إضافة المزيد. إجمالي المتوفر في المخزون هو ${maxStock}.`); return; }

            if (existing > -1) { cart[existing].qty += qty; } 
            else { cart.push({ cartKey, productId: currentModalProduct.id, name: currentModalProduct.name, price: itemPrice, originalPrice: originalPrice, qty, variantText: variantText, img: itemImage }); }
            
            const modalImg = document.getElementById('modal-img');
            if (!modalImg.classList.contains('hidden')) { triggerFlyAnimation(modalImg.src, modalImg.getBoundingClientRect()); }
            saveCart(); closeProductModal();
        }

        function saveCart() { localStorage.setItem('cart_' + CUSTOMER_ID, JSON.stringify(cart)); renderCart(); }

        function updateCartQty(cartKey, delta) {
            const item = cart.find(i => i.cartKey === cartKey);
            if (!item) return;

            let maxQty = Infinity;
            const product = ALL_PRODUCTS.find(p => p.id == item.productId);
            if (product) {
                if (item.cartKey.includes('v')) { 
                    const variantId = item.cartKey.split('v')[1].split('_')[0];
                    const variant = product.variants.find(v => v.id == variantId);
                    if (variant) maxQty = parseInt(variant.quantity);
                } else { maxQty = effectiveProductStock(product); }
            }
            
            if (item.qty + delta > maxQty) { alert(`نعتذر، المتوفر في المخزون هو ${maxQty}`); return; }
            item.qty += delta;
            if (item.qty <= 0) { removeCartItem(cartKey); return; }
            saveCart();
        }

        function removeCartItem(cartKey) { cart = cart.filter(i => i.cartKey !== cartKey); saveCart(); }

        function renderCart() {
            const container           = document.getElementById('cart-items-container');
            const badge               = document.getElementById('cart-badge');
            const headerCount         = document.getElementById('cart-header-count');
            const subtotalEl          = document.getElementById('cart-subtotal');
            const withDiscountBox     = document.getElementById('cart-total-with-discount');
            const noDiscountBox       = document.getElementById('cart-total-no-discount');
            const preDiscountTotalEl  = document.getElementById('cart-pre-discount-total');
            const discountInlineEl    = document.getElementById('cart-discount-inline');
            const totalEl             = document.getElementById('cart-total-final');
            const totalPlainEl        = document.getElementById('cart-total-final-plain');
            const checkoutBtn         = document.getElementById('checkout-btn');
            
            let totalQty = 0;
            let sumOfFinalPrices = 0;    
            let sumOfOriginalPrices = 0; 
            
            container.innerHTML = '';

            if (cart.length === 0) {
                container.innerHTML = `<div class="h-full flex flex-col items-center justify-center text-gray-400 mt-16"><div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mb-4"><i class="fas fa-shopping-bag text-3xl text-gray-300"></i></div><p class="font-bold text-gray-500">السلة فارغة</p></div>`;
                badge.innerText = '0'; headerCount.innerText = '0'; subtotalEl.innerText = `0.00 ${CURRENCY}`;
                withDiscountBox.classList.add('hidden');
                noDiscountBox.classList.remove('hidden');
                totalPlainEl.innerText = '0.00';
                checkoutBtn.disabled = true; checkoutBtn.classList.add('opacity-50','cursor-not-allowed'); return;
            }

            checkoutBtn.disabled = false; checkoutBtn.classList.remove('opacity-50','cursor-not-allowed');

            cart.forEach(item => {
                totalQty += item.qty;
                const itemOriginalPrice = item.originalPrice || item.price; 
                sumOfFinalPrices += item.qty * item.price;
                sumOfOriginalPrices += item.qty * itemOriginalPrice;

                const itemHasDiscount = itemOriginalPrice > item.price;
                const imgUrl = item.img.startsWith('../') ? item.img : (item.img ? PRODUCT_IMG_BASE_PATH + item.img : '');
                const imgHtml = imgUrl ? `<img src="${imgUrl}" class="w-16 h-20 object-cover rounded-xl flex-shrink-0 border border-gray-100">` : `<div class="w-16 h-20 bg-gray-100 rounded-xl flex items-center justify-center text-gray-300 flex-shrink-0"><i class="fas fa-image"></i></div>`;
                
                // Formatted Tags for chosen attributes (L, S, Colors)
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
            // Pre-discount total = original subtotal + shipping (what would be paid without any discount)
            const preDiscountTotal = sumOfOriginalPrices + SHIPPING_COST;
            const finalTotal = sumOfFinalPrices + SHIPPING_COST;

            badge.innerText = totalQty; headerCount.innerText = totalQty;
            subtotalEl.innerText = `${sumOfOriginalPrices.toFixed(2)} ${CURRENCY}`; 

            // Toggle between the two total display modes
            if (totalDiscountAmount > 0.01) { 
                withDiscountBox.classList.remove('hidden');
                noDiscountBox.classList.add('hidden');
                preDiscountTotalEl.innerText = preDiscountTotal.toFixed(2);
                discountInlineEl.innerText = totalDiscountAmount.toFixed(2);
                totalEl.innerText = finalTotal.toFixed(2);
            } else { 
                withDiscountBox.classList.add('hidden');
                noDiscountBox.classList.remove('hidden');
                totalPlainEl.innerText = finalTotal.toFixed(2);
            }
        }

        function toggleCart(forceOpen = false) {
            const sidebar  = document.getElementById('cart-sidebar');
            const overlay  = document.getElementById('cart-overlay');
            const isOpen   = !sidebar.classList.contains('translate-x-full-rtl');
            if (forceOpen || !isOpen) { sidebar.classList.remove('translate-x-full-rtl'); overlay.classList.remove('hidden'); document.body.style.overflow = 'hidden'; } 
            else { sidebar.classList.add('translate-x-full-rtl'); overlay.classList.add('hidden'); document.body.style.overflow = ''; }
        }

        function checkout() { if (cart.length === 0) return; window.location.href = `checkout.php?token=${TOKEN}`; }
        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html>