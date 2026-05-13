<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('<div class="p-10 text-center text-red-600 text-xl font-bold">وصول غير صالح.</div>');
}

// 1. Validate Customer
$stmt = $db->prepare("SELECT id, name, city_name, currency FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('<div class="p-10 text-center text-red-600 text-xl font-bold">الرابط غير صالح.</div>');
}

$customer_id = $customer['id'];
$customer_name = $customer['name'];
$customer_city = $customer['city_name'];
$currency = $customer['currency'] ?? 'YER';

// 2. Fetch Shipping Cost
$shipping_cost = 0.00;
if (!empty($customer_city)) {
    $ship_stmt = $db->prepare("SELECT shipping_cost FROM cities WHERE name = ? AND is_active = 1 LIMIT 1");
    $ship_stmt->execute([$customer_city]);
    $city_data = $ship_stmt->fetch(PDO::FETCH_ASSOC);
    if ($city_data) {
        $shipping_cost = (float)$city_data['shipping_cost'];
    }
}

// 3. Fetch Active Bank Accounts
$banks_stmt = $db->query("SELECT bank_name, account_name, account_holder_name, account_number, iban FROM bank_accounts WHERE is_active = 1");
$bank_accounts = $banks_stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Fetch Company Phone
$phone_stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_phone'");
$company_phone = $phone_stmt->fetchColumn() ?: '';

// --- Handle Form Submission (POST) ---
$order_success = false;
$whatsapp_message = "";
$error_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    
    $cart_json = $_POST['cart_data'] ?? '[]';
    $cart_items = json_decode($cart_json, true);
    $applied_coupon_code = $_POST['applied_coupon'] ?? null;
    $coupon_discount_amount = (float)($_POST['discount_amount'] ?? 0); // This is only the coupon discount

    if (empty($cart_items)) {
        $error_msg = "السلة فارغة، لا يمكن إتمام الطلب.";
    } elseif (empty($_FILES['payment_evidence']['name'])) {
        $error_msg = "يرجى إرفاق صورة إيصال التحويل البنكي.";
    } else {
        try {
            // Upload Image
            $upload_dir = __DIR__ . '/../uploads/payments/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $file_extension = pathinfo($_FILES['payment_evidence']['name'], PATHINFO_EXTENSION);
            $new_filename = 'pay_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;
            
            $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
            if (!in_array(strtolower($file_extension), $allowed_types)) {
                throw new Exception("صيغة الملف غير مسموحة. يرجى رفع صورة أو PDF.");
            }
            
            if (!move_uploaded_file($_FILES['payment_evidence']['tmp_name'], $target_file)) {
                throw new Exception("فشل في رفع إيصال الدفع.");
            }
            $payment_url = 'uploads/payments/' . $new_filename;

            // --- Recalculate Totals from cart_items ---
            $original_products_subtotal = 0; // Sum of items at their original prices * qty
            $final_products_total_after_product_discounts = 0; // Sum of items at their discounted prices * qty
            $total_product_discount = 0; // Sum of (originalPrice - price) * qty for all items

            foreach ($cart_items as $item) {
                $item_original_price = (float)($item['originalPrice'] ?? $item['price']); // Fallback if originalPrice not set
                $item_final_price = (float)$item['price'];
                $item_qty = (int)$item['qty'];

                $original_products_subtotal += ($item_original_price * $item_qty);
                $final_products_total_after_product_discounts += ($item_final_price * $item_qty);
                $total_product_discount += (($item_original_price - $item_final_price) * $item_qty);
            }

            // Total discount combines product-level discounts and coupon discount
            $total_combined_discount = $total_product_discount + $coupon_discount_amount;
            
            // Final total = (Original subtotal - all discounts + shipping)
            $final_order_total = ($original_products_subtotal - $total_combined_discount + $shipping_cost);
            if ($final_order_total < 0) $final_order_total = 0; // Ensure total doesn't go negative

            // Generate Order Number
            $order_number = 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);

            // Insert into Database (Transaction)
            $db->beginTransaction();

            $insert_order = $db->prepare("INSERT INTO shop_orders (order_number, customer_id, subtotal, shipping_fee, discount_amount, total_amount, coupon_code, payment_evidence_url, order_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'طلب جديد')");
            $insert_order->execute([
                $order_number, 
                $customer_id, 
                $original_products_subtotal, // Store original subtotal
                $shipping_cost, 
                $total_combined_discount,    // Store combined discounts
                $final_order_total,          // Store final calculated total
                $applied_coupon_code, 
                $payment_url
            ]);
            
            $order_id = $db->lastInsertId();

            $insert_item = $db->prepare("INSERT INTO shop_order_items (order_id, product_id, product_name, variant_text, quantity, unit_price, original_unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($cart_items as $item) {
                $item_original_price = (float)($item['originalPrice'] ?? $item['price']);
                $item_final_price = (float)$item['price'];
                $item_qty = (int)$item['qty'];
                $item_total = $item_final_price * $item_qty;

                $insert_item->execute([
                    $order_id, 
                    $item['productId'], 
                    $item['name'], 
                    $item['variantText'] ?? '', 
                    $item_qty, 
                    $item_final_price,       // unit_price stores final price
                    $item_original_price,    // new column to store original unit price
                    $item_total
                ]);
            }

            $db->commit();
            $order_success = true;

            // ==========================================
            // جلب رسالة الواتساب الديناميكية من قاعدة البيانات
            // ==========================================
            $stmt_tpl = $db->prepare("SELECT message_content FROM whatsapp_template WHERE target_event = 'checkout_order_created_admin' AND is_active = 1 LIMIT 1");
            $stmt_tpl->execute();
            $template = $stmt_tpl->fetch(PDO::FETCH_ASSOC);

            if ($template && !empty($template['message_content'])) {
                // إذا وجد قالب مفعل، قم باستبدال المتغيرات
                $whatsapp_message = $template['message_content'];
                $whatsapp_message = str_replace('{{customer-name}}', $customer_name, $whatsapp_message);
                $whatsapp_message = str_replace('{{order-number}}', $order_number, $whatsapp_message);
                $whatsapp_message = str_replace('{{total-amount}}', number_format($final_order_total, 2), $whatsapp_message); // Use final total for WhatsApp
                $whatsapp_message = str_replace('{{currency}}', $currency, $whatsapp_message);
            } else {
                // الرسالة الافتراضية في حال عدم وجود قالب أو إذا كان غير مفعل
                $whatsapp_message = "مرحباً،\nلدي طلب جديد من البوابة.\n\n*الاسم:* $customer_name\n*رقم الطلب:* $order_number\n*الإجمالي:* " . number_format($final_order_total, 2) . " $currency\n\nيرجى مراجعة واعتماد الطلب في النظام.";
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_msg = "حدث خطأ أثناء حفظ الطلب: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إتمام الطلب والدفع</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; }
        /* Custom File Input Styling */
        input[type="file"]::file-selector-button {
            border: none;
            background: #C7A46D;
            padding: 10px 20px;
            border-radius: 8px;
            color: #fff;
            cursor: pointer;
            font-weight: bold;
            transition: background .2s ease-in-out;
            margin-left: 15px;
        }
        input[type="file"]::file-selector-button:hover {
            background: #b59563;
        }
        /* Scrollbar styling */
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-gray-50 pb-16">

    <!-- Navbar -->
    <nav class="text-white shadow-lg sticky top-0 z-40 bg-gray-900">
        <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">
            <h1 class="text-lg sm:text-xl font-bold text-[#C7A46D]"><i class="fas fa-lock ml-2"></i> إتمام الطلب والدفع</h1>
            <a href="products.php?token=<?php echo htmlspecialchars($token); ?>" class="text-xs sm:text-sm bg-gray-700 hover:bg-gray-600 px-3 py-2 rounded-lg transition">العودة للتسوق</a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <?php if (!empty($error_msg)): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-sm text-sm font-bold">
                <i class="fas fa-exclamation-triangle ml-2"></i> <?php echo $error_msg; ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="checkout-form" class="flex flex-col lg:flex-row gap-8">
            
            <!-- Hidden inputs to send to backend -->
            <input type="hidden" name="cart_data" id="cart-data-input">
            <input type="hidden" name="applied_coupon" id="applied-coupon-input" value="">
            <input type="hidden" name="discount_amount" id="discount-amount-input" value="0"> <!-- This will store coupon discount only -->

            <!-- Right Column: Payment & Details -->
            <div class="lg:w-2/3 space-y-6">
                
                <!-- 1. Shipping Details -->
                <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h2 class="text-lg font-bold text-gray-800 mb-4 border-b pb-3"><i class="fas fa-map-marker-alt text-[#C7A46D] ml-2"></i> بيانات الشحن</h2>
                    <p class="text-sm text-gray-600 mb-2"><strong>الاسم:</strong> <?php echo htmlspecialchars($customer_name); ?></p>
                    <p class="text-sm text-gray-600"><strong>مدينة الشحن المعتمدة:</strong> <span class="text-blue-600 font-bold"><?php echo htmlspecialchars($customer_city ?: 'غير محددة'); ?></span></p>
                </div>

                <!-- 2. Bank Accounts (3 in a row layout) -->
                <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h2 class="text-lg font-bold text-gray-800 mb-3 border-b pb-3"><i class="fas fa-university text-[#C7A46D] ml-2"></i> الحسابات البنكية</h2>
                    <p class="text-xs sm:text-sm text-gray-500 mb-4">يرجى تحويل إجمالي المبلغ لأحد الحسابات التالية، ثم إرفاق صورة الإيصال في الأسفل.</p>
                    
                    <!-- Grid: 3 columns unconditionally -->
                    <div class="grid grid-cols-3 gap-2 sm:gap-3">
                        <?php foreach ($bank_accounts as $bank): ?>
                            <div class="border border-gray-200 rounded-xl p-2 sm:p-3 hover:border-[#C7A46D] hover:shadow-md transition bg-gray-50 flex flex-col justify-center text-center">
                                <h3 class="font-bold text-[10px] sm:text-sm text-gray-800 mb-1 leading-tight line-clamp-1" title="<?php echo htmlspecialchars($bank['bank_name']); ?>">
                                    <i class="fas fa-building text-gray-400 hidden sm:inline-block ml-1"></i><?php echo htmlspecialchars($bank['bank_name']); ?>
                                </h3>
                                <p class="text-[9px] sm:text-xs text-gray-600 mb-1 line-clamp-1" title="<?php echo htmlspecialchars($bank['account_name'] ?: $bank['account_holder_name']); ?>">
                                    <?php echo htmlspecialchars($bank['account_name'] ?: $bank['account_holder_name']); ?>
                                </p>
                                <p class="text-[10px] sm:text-sm font-bold text-blue-700 select-all mb-1 leading-none">
                                    <?php echo htmlspecialchars($bank['account_number']); ?>
                                </p>
                                <?php if (!empty($bank['iban'])): ?>
                                    <p class="text-[8px] sm:text-[10px] text-gray-400 select-all line-clamp-1 leading-none" title="<?php echo htmlspecialchars($bank['iban']); ?>">
                                        <?php echo htmlspecialchars($bank['iban']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 3. Upload Payment Evidence with Preview -->
                <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-sm border border-gray-100 border-r-4 border-r-[#C7A46D]">
                    <h2 class="text-lg font-bold text-gray-800 mb-3"><i class="fas fa-file-invoice-dollar text-[#C7A46D] ml-2"></i> إرفاق إيصال التحويل (مطلوب)</h2>
                    <p class="text-xs sm:text-sm text-gray-500 mb-4">يجب إرفاق صورة واضحة لعملية التحويل البنكي ليتم مراجعة واعتماد طلبك.</p>
                    
                    <div class="relative">
                        <input type="file" id="payment_evidence" name="payment_evidence" accept="image/jpeg, image/png, image/jpg, application/pdf" required 
                               class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 border border-gray-300 rounded-lg p-2 cursor-pointer transition-all">
                        
                        <!-- Image Preview Container -->
                        <div id="image-preview-container" class="hidden mt-4 relative bg-gray-100 rounded-xl border-2 border-dashed border-gray-300 p-2 flex justify-center items-center">
                            <img id="image-preview" src="" alt="معاينة الإيصال" class="max-h-56 object-contain rounded-lg shadow-sm">
                            <button type="button" onclick="removeImage()" class="absolute -top-3 -right-3 bg-red-500 hover:bg-red-600 text-white rounded-full w-8 h-8 flex items-center justify-center shadow-md transition-transform hover:scale-110">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Left Column: Order Summary & Coupon -->
            <div class="lg:w-1/3 space-y-6">
                
                <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-sm border border-gray-100 sticky top-20">
                    <h2 class="text-lg font-bold text-gray-800 mb-4 border-b pb-3"><i class="fas fa-receipt text-[#C7A46D] ml-2"></i> ملخص الطلب</h2>
                    
                    <!-- Cart Items List -->
                    <div id="checkout-items-list" class="space-y-3 mb-6 max-h-64 overflow-y-auto pl-2">
                        <!-- Populated by JS -->
                    </div>

                    <!-- Coupon Area -->
                    <div class="mb-6 bg-gray-50 p-3 rounded-lg border border-gray-200">
                        <label class="block text-xs sm:text-sm font-bold text-gray-700 mb-2">كود الخصم (إن وجد)</label>
                        <div class="flex gap-2">
                            <input type="text" id="coupon-code" class="flex-grow border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#C7A46D]" placeholder="أدخل الكوبون هنا">
                            <button type="button" onclick="applyCoupon()" id="apply-coupon-btn" class="bg-gray-800 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-bold transition">تطبيق</button>
                        </div>
                        <p id="coupon-msg" class="text-xs mt-2 hidden"></p>
                    </div>

                    <!-- Totals -->
                    <div class="space-y-3 text-gray-700 text-sm font-semibold border-b pb-4 mb-4">
                        <div class="flex justify-between">
                            <span>المجموع الفرعي:</span>
                            <span id="summary-subtotal">0.00 <?php echo $currency; ?></span>
                        </div>
                        <div class="flex justify-between text-blue-600">
                            <span>رسوم الشحن:</span>
                            <span><?php echo number_format($shipping_cost, 2) . ' ' . $currency; ?></span>
                        </div>
                        
                        <!-- Coupon Discount -->
                        <div id="coupon-discount-row" class="flex justify-between text-red-500 hidden">
                            <span>خصم الكوبون (<span id="discount-label"></span>):</span>
                            <span id="summary-coupon-discount">-0.00 <?php echo $currency; ?></span>
                        </div>

                        <!-- Total Savings Row (Product discounts + Coupon) -->
                        <div id="total-savings-row" class="flex justify-between text-green-600 font-bold hidden border-t border-gray-100 pt-2 mt-2 bg-green-50 px-2 py-1.5 rounded">
                            <span>إجمالي التوفير والخصومات:</span>
                            <span id="summary-total-savings">0.00 <?php echo $currency; ?></span>
                        </div>
                    </div>

                    <div class="flex justify-between items-center mb-6">
                        <span class="text-lg font-black text-gray-900">الإجمالي النهائي:</span>
                        <div class="text-left">
                            <span id="summary-total" class="text-2xl font-black text-green-600">0.00</span>
                            <span class="text-xs text-gray-500 block"><?php echo $currency; ?></span>
                        </div>
                    </div>

                    <button type="submit" name="submit_order" id="submit-btn" class="w-full py-4 bg-green-600 hover:bg-green-700 text-white rounded-xl font-bold text-lg transition shadow-lg flex justify-center items-center gap-2">
                        <i class="fas fa-check-circle"></i> تأكيد الطلب وإرسال
                    </button>
                </div>

            </div>
        </form>
    </div>

    <!-- SUCCESS MODAL -->
    <?php if ($order_success): ?>
    <div class="fixed inset-0 bg-black bg-opacity-80 z-50 flex items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl shadow-2xl p-8 max-w-md w-full text-center transform transition-all scale-100">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-white shadow-lg">
                <i class="fas fa-check text-4xl text-green-500"></i>
            </div>
            <h2 class="text-2xl font-black text-gray-800 mb-2">تم استلام طلبك بنجاح!</h2>
            <p class="text-gray-600 mb-6">رقم طلبك هو: <strong class="text-blue-600 text-lg"><?php echo $order_number; ?></strong></p>
            <p class="text-sm text-gray-500 mb-6 border-t border-gray-100 pt-4">سيتم الآن تحويلك إلى الواتساب لإرسال إشعار للشركة وتأكيد الطلب.</p>
            
            <?php 
                // ترميز الرسالة الديناميكية لتناسب رابط الواتساب
                $encoded_message = urlencode($whatsapp_message);
                $whatsapp_url = "https://wa.me/{$company_phone}?text={$encoded_message}";
            ?>
            <a href="<?php echo $whatsapp_url; ?>" onclick="clearCartAndGo()" target="_blank" class="block w-full py-3.5 bg-[#25D366] hover:bg-[#1ebd5a] text-white rounded-xl font-bold text-lg transition shadow-lg mb-4">
                <i class="fab fa-whatsapp text-xl ml-2 align-middle"></i> إرسال الإشعار للشركة
            </a>
            <a href="products.php?token=<?php echo htmlspecialchars($token); ?>" onclick="clearCart()" class="block text-sm text-gray-400 hover:text-gray-800 underline font-bold">العودة للتسوق</a>
        </div>
    </div>
    <script>
        function clearCart() { localStorage.removeItem('cart_<?php echo $customer_id; ?>'); }
        function clearCartAndGo() { 
            clearCart(); 
            // Give a short delay for WhatsApp to open before redirecting
            setTimeout(() => { 
                // Check if WhatsApp opened
                const newWindow = window.open('about:blank', '_blank'); // Open a blank window first
                newWindow.location.href = "<?php echo $whatsapp_url; ?>"; // Then redirect it
                
                // You might need a more robust way to detect if WhatsApp opened.
                // For now, after a delay, redirect the current page.
                setTimeout(() => { window.location.href = "products.php?token=<?php echo htmlspecialchars($token); ?>"; }, 2000); 
            }, 500); // Small delay to let the user see success message before redirecting.
        }
    </script>
    <?php endif; ?>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        const CUSTOMER_ID = <?php echo $customer_id; ?>;
        const CURRENCY = "<?php echo $currency; ?>";
        const SHIPPING_COST = <?php echo $shipping_cost; ?>;
        
        let cart = JSON.parse(localStorage.getItem('cart_' + CUSTOMER_ID)) || [];
        let originalSubtotal = 0; // Sum of items at their original prices
        let currentCouponDiscountAmount = 0; // Discount from coupon only
        let totalProductDiscount = 0; // Sum of (originalPrice - price) * qty for all items

        // Image Preview Logic
        document.getElementById('payment_evidence').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const previewContainer = document.getElementById('image-preview-container');
            const previewImage = document.getElementById('image-preview');

            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    previewImage.src = event.target.result;
                    previewContainer.classList.remove('hidden');
                }
                reader.readAsDataURL(file);
            } else {
                // If it's a PDF or invalid
                previewContainer.classList.add('hidden');
                previewImage.src = '';
            }
        });

        function removeImage() {
            document.getElementById('payment_evidence').value = '';
            document.getElementById('image-preview-container').classList.add('hidden');
            document.getElementById('image-preview').src = '';
        }

        document.addEventListener('DOMContentLoaded', () => {
            if(cart.length === 0 && !<?php echo $order_success ? 'true' : 'false'; ?>) {
                alert("السلة فارغة، سيتم إعادتك للمتجر.");
                window.location.href = "products.php?token=<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>";
                return;
            }

            // Populate Hidden Input for PHP
            document.getElementById('cart-data-input').value = JSON.stringify(cart);
            renderCartSummary();
        });

        function renderCartSummary() {
            const listEl = document.getElementById('checkout-items-list');
            listEl.innerHTML = '';
            originalSubtotal = 0;
            totalProductDiscount = 0;

            cart.forEach(item => {
                const itemFinalTotal = item.price * item.qty;
                const itemOriginalPrice = item.originalPrice || item.price; // Fallback for old cart items
                const itemOriginalTotal = itemOriginalPrice * item.qty;

                originalSubtotal += itemOriginalTotal; // Accumulate original prices for the subtotal
                totalProductDiscount += (itemOriginalTotal - itemFinalTotal); // Accumulate product discounts
                
                let originalPriceHtml = '';
                if (itemOriginalPrice > item.price) {
                    originalPriceHtml = `<span class="text-gray-400 line-through text-[10px] mr-1">${itemOriginalPrice.toFixed(2)}</span>`;
                }
                
                const variantHtml = item.variantText ? `<p class="text-[10px] text-gray-500 bg-gray-100 inline-block px-1.5 py-0.5 rounded mt-1">${item.variantText}</p>` : '';

                listEl.innerHTML += `
                    <div class="flex justify-between items-center text-sm border-b border-gray-100 pb-3 mt-2">
                        <div class="flex-grow min-w-0 pr-2 text-right">
                            <p class="font-bold text-gray-800 truncate text-xs">${item.name}</p>
                            ${variantHtml}
                            <p class="text-[11px] text-gray-600 mt-1">${item.qty} × <span class="font-bold text-gray-800">${item.price.toFixed(2)}</span> ${originalPriceHtml} ${CURRENCY}</p>
                        </div>
                        <div class="font-bold text-[#C7A46D] whitespace-nowrap bg-[#C7A46D]/10 px-2 py-1 rounded-lg text-xs">
                            ${itemFinalTotal.toFixed(2)}
                        </div>
                    </div>
                `;
            });

            document.getElementById('summary-subtotal').innerText = `${originalSubtotal.toFixed(2)} ${CURRENCY}`;
            updateTotal();
        }

        function updateTotal() {
            const totalCombinedDiscount = currentCouponDiscountAmount + totalProductDiscount;
            let finalTotal = (originalSubtotal - totalCombinedDiscount + SHIPPING_COST);
            
            if(finalTotal < 0) finalTotal = 0;
            
            document.getElementById('summary-total').innerText = finalTotal.toFixed(2);
            
            // Update total savings row
            const savingsRow = document.getElementById('total-savings-row');
            if (totalCombinedDiscount > 0.01) { // Check for > 0.01 to avoid showing for tiny floating point errors
                savingsRow.classList.remove('hidden');
                document.getElementById('summary-total-savings').innerText = `${totalCombinedDiscount.toFixed(2)} ${CURRENCY}`;
            } else {
                savingsRow.classList.add('hidden');
            }

            // Update coupon discount row (if applicable)
            const couponDiscountRow = document.getElementById('coupon-discount-row');
            if (currentCouponDiscountAmount > 0.01) {
                couponDiscountRow.classList.remove('hidden');
                document.getElementById('summary-coupon-discount').innerText = `-${currentCouponDiscountAmount.toFixed(2)} ${CURRENCY}`;
            } else {
                couponDiscountRow.classList.add('hidden');
            }

            // Update hidden inputs for backend submission
            document.getElementById('discount-amount-input').value = currentCouponDiscountAmount; // Only send coupon discount here
        }

        async function applyCoupon() {
            const code = document.getElementById('coupon-code').value.trim();
            const msgEl = document.getElementById('coupon-msg');
            const btn = document.getElementById('apply-coupon-btn');
            
            if(!code) {
                msgEl.innerText = "الرجاء إدخال كود الكوبون.";
                msgEl.className = "text-xs mt-2 text-red-500 font-bold block";
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            try {
                // Pass the originalSubtotal (sum of original prices) for coupon validation
                const response = await fetch(`check_coupon.php?coupon_code=${encodeURIComponent(code)}&total_amount=${originalSubtotal}&customer_id=${CUSTOMER_ID}&currency=${CURRENCY}`);
                const data = await response.json();

                msgEl.classList.remove('hidden');

                if(data.success) {
                    msgEl.innerText = data.message;
                    msgEl.className = "text-xs mt-2 text-green-600 font-bold block";
                    
                    currentCouponDiscountAmount = parseFloat(data.coupon_discount_amount);
                    
                    // Show coupon discount row
                    document.getElementById('coupon-discount-row').classList.remove('hidden');
                    document.getElementById('discount-label').innerText = data.coupon_discount_percentage_display;
                    document.getElementById('summary-coupon-discount').innerText = `-${currentCouponDiscountAmount.toFixed(2)} ${CURRENCY}`;
                    
                    // Set hidden input for PHP
                    document.getElementById('applied-coupon-input').value = code;
                    
                    // Lock input
                    document.getElementById('coupon-code').readOnly = true;
                    btn.classList.add('hidden');
                } else {
                    msgEl.innerText = data.message;
                    msgEl.className = "text-xs mt-2 text-red-500 font-bold block";
                    resetCoupon();
                }
            } catch (error) {
                console.error("Coupon check error:", error);
                msgEl.innerText = "حدث خطأ في التحقق من الشبكة.";
                msgEl.className = "text-xs mt-2 text-red-500 font-bold block";
                resetCoupon();
            }

            updateTotal();
            btn.disabled = false;
            btn.innerText = 'تطبيق';
        }

        function resetCoupon() {
            currentCouponDiscountAmount = 0;
            document.getElementById('coupon-discount-row').classList.add('hidden');
            document.getElementById('applied-coupon-input').value = '';
            document.getElementById('coupon-code').readOnly = false;
            document.getElementById('coupon-code').value = '';
            document.getElementById('apply-coupon-btn').classList.remove('hidden');
            document.getElementById('coupon-msg').classList.add('hidden'); // Hide message on reset
            updateTotal();
        }

        // Prevent submission if cart empty or form already submitted
        document.getElementById('checkout-form').addEventListener('submit', function(e) {
            if(cart.length === 0) {
                e.preventDefault();
                alert('السلة فارغة!');
                return;
            }
            const btn = document.getElementById('submit-btn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';
            btn.classList.add('opacity-70', 'cursor-not-allowed');
        });
    </script>
</body>
</html>