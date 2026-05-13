<?php
/**
 * Order Details Page (Customer Portal)
 * Updated to match Admin Index Data Logic
 * Design Updated: No Tables, Animations Added, Gold Theme, Products Removed, Arabic Payments
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- TIMEZONE SETTING ---
date_default_timezone_set('Asia/Aden');

function formatToYemenTime($dateString, $format = 'Y-m-d h:i A') {
    if (empty($dateString)) return '-';
    try {
        // Create DateTime object from the string (assuming DB is UTC)
        $date = new DateTime($dateString, new DateTimeZone('UTC'));
        // Convert to Yemen Time
        $date->setTimezone(new DateTimeZone('Asia/Aden'));
        return $date->format($format);
    } catch (Exception $e) {
        // Fallback if date parsing fails
        return $dateString;
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/status_helpers.php';

// Get token and order_id
$token = $_GET['token'] ?? '';
$order_id = $_GET['order_id'] ?? 0;

if (empty($token) || empty($order_id)) {
    die('Invalid access.');
}

// 1. Verify customer
$stmt = $db->prepare("SELECT * FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('Link expired or invalid.');
}

try {
    // --- NEW: Fetch all available statuses for translation ---
    $statuses_stmt = $db->query("SELECT status_key, status_name_ar FROM customer_order_statuses");
    $all_statuses_raw = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);
    $status_translations = array_column($all_statuses_raw, 'status_name_ar', 'status_key');


    // 2. Fetch Main Order Details
    $stmt = $db->prepare("
        SELECT o.*,
               COALESCE((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id), 0) as total_quantity
        FROM customer_orders o
        WHERE o.id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$order_id, $customer['id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die('Order not found.');
    }

    // 3. Fetch Order Items (Kept for logic, but not displayed)
    $items_stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch Order Images
    $images_stmt = $db->prepare("SELECT * FROM order_images WHERE order_id = ? ORDER BY display_order");
    $images_stmt->execute([$order_id]);
    $images = $images_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Fetch History (General Edits)
    $history_stmt = $db->prepare("
        SELECT h.*, u.username
        FROM order_status_history h
        LEFT JOIN users u ON h.created_by = u.id
        WHERE h.order_id = ?
        ORDER BY h.created_at DESC
    ");
    $history_stmt->execute([$order_id]);
    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Fetch State History (Status Changes)
    $state_history_stmt = $db->prepare("
        SELECT h.*, u.username
        FROM order_state_history h
        LEFT JOIN users u ON h.changed_by_id = u.id
        WHERE h.order_id = ?
        ORDER BY h.created_at DESC
    ");
    $state_history_stmt->execute([$order_id]);
    $state_history = $state_history_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. Fetch Damaged Items
    $damaged_stmt = $db->prepare("SELECT * FROM order_damaged_items WHERE order_id = ?");
    $damaged_stmt->execute([$order_id]);
    $damaged_items = $damaged_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. Fetch Coupon Info
    $coupon_stmt = $db->prepare("
        SELECT c.coupon_code FROM coupon_usage cu
        JOIN coupons c ON cu.coupon_id = c.id WHERE cu.order_id = ?
    ");
    $coupon_stmt->execute([$order_id]);
    $coupon_code = $coupon_stmt->fetchColumn();

    // 9. Fetch Payments (via Invoices)
    $payments_stmt = $db->prepare("
        SELECT cp.*, ci.invoice_number, ba.bank_name
        FROM customer_payments cp
        INNER JOIN customer_invoices ci ON cp.invoice_id = ci.id
        LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
        WHERE ci.order_id = ?
        ORDER BY cp.payment_date DESC
    ");
    $payments_stmt->execute([$order_id]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('Database Error: ' . $e->getMessage());
}

// --- FINANCIAL CALCULATIONS ---
$subtotal = $order['subtotal_amount'] ?? 0;
$auto_discount_amount = $order['automatic_discount_amount'] ?? 0;
$auto_discount_percent = $order['automatic_discount_percentage'] ?? 0;
$total_discount_amount = $order['discount_amount'] ?? 0;

// Coupon discount is total discount minus auto discount
$coupon_discount = $total_discount_amount - $auto_discount_amount;

// Calculate Damaged Total
$damaged_total = 0;
foreach ($damaged_items as $d) {
    $damaged_total += floatval($d['price']);
}

$additional_discount = $order['additional_discount'] ?? 0;
$shipping_cost = $order['shipping_cost'] ?? 0;
$final_amount = $order['final_amount'] ?? 0;
$paid_amount = $order['paid_amount'] ?? 0;
$remaining_amount = $final_amount - $paid_amount;

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل الطلب - <?php echo htmlspecialchars(formatOrderNumber($order['order_number'] ?? '')); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; }
        @media print { .no-print { display: none !important; } }
        
        /* Timeline Styles */
        .timeline { position: relative; padding-right: 1.5rem; }
        .timeline::before { content: ''; position: absolute; top: 0; bottom: 0; right: 7px; width: 2px; background-color: #e5e7eb; }
        .timeline-item { position: relative; margin-bottom: 1.5rem; }
        .timeline-marker { position: absolute; right: -1px; top: 5px; width: 18px; height: 18px; border-radius: 50%; border: 3px solid white; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        .timeline-content { padding-right: 1rem; }

        /* Animation Keyframes */
        @keyframes fadeSlideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Animation Class */
        .animate-entry {
            opacity: 0; /* Start hidden */
            animation: fadeSlideUp 0.6s ease-out forwards;
        }

        /* Hover transitions for list items */
        .list-card {
            transition: all 0.3s ease;
        }
        .list-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            background-color: #f8fafc;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen pb-10">

    <!-- Navbar with Custom Gold Gradient -->
    <nav style="background: linear-gradient(to left, #C7A46D, #B8956A);" class="text-white shadow-lg no-print sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <a href="portal.php?token=<?php echo htmlspecialchars($token ?? ''); ?>" class="text-white hover:text-gray-200 ml-4 transition transform hover:-translate-x-1">
                        <i class="fas fa-arrow-right text-xl"></i>
                    </a>
                    <div>
                        <h1 class="text-lg font-bold">تفاصيل الطلب</h1>
                        <p class="text-xs text-white/80">#<?php echo htmlspecialchars($order['order_number'] ?? ''); ?></p>
                    </div>
                </div>
                <button onclick="window.print()" class="bg-white/10 hover:bg-white/20 backdrop-blur-sm text-white px-4 py-2 rounded-lg text-sm font-bold transition flex items-center gap-2">
                    <i class="fas fa-print"></i> <span>طباعة</span>
                </button>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- RIGHT COLUMN (Main Content) -->
            <div class="lg:col-span-2 space-y-6">

                <!-- 1. Order Info Card -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden animate-entry" style="animation-delay: 0.1s;">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="font-bold text-gray-700 flex items-center"><i class="fas fa-info-circle ml-2 text-[#C7A46D]"></i> بيانات الطلب</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-6">
                            <div class="bg-orange-50/50 p-3 rounded-lg border border-orange-100">
                                <span class="text-xs text-gray-500 block mb-1">رقم الطلب</span>
                                <span class="font-bold text-[#B8956A] text-lg">#<?php echo htmlspecialchars($order['order_number'] ?? ''); ?></span>
                            </div>
                            <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                                <span class="text-xs text-gray-500 block mb-1">تاريخ الإنشاء</span>
                                <span class="font-bold text-gray-800 dir-ltr text-sm"><?php echo formatToYemenTime($order['created_at']); ?></span>
                            </div>
                            <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                                <span class="text-xs text-gray-500 block mb-1">عدد القطع</span>
                                <span class="font-bold text-gray-800"><?php echo (int)($order['total_quantity'] ?? 0); ?></span>
                            </div>
                            <div class="bg-gray-50 p-3 rounded-lg border border-gray-100">
                                <span class="text-xs text-gray-500 block mb-1">الحالة الحالية</span>
                                <?php
                                $status = $order['status'] ?? 'new';
                                if ($status === 'purchased') {
                                    $status = 'new';
                                }
                                echo getOrderStatusBadge($status);
                                ?>
                            </div>
                        </div>
                        
                        <!-- Links -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-3 bg-white rounded-lg border border-gray-200 shadow-sm flex justify-between items-center group hover:border-[#C7A46D] transition">
                                <span class="text-xs text-gray-500">رابط الطلب الأساسي</span>
                                <?php if (!empty($order['order_link'])): ?>
                                    <a href="<?php echo htmlspecialchars($order['order_link'] ?? ''); ?>" target="_blank" class="text-[#B8956A] bg-orange-50 px-3 py-1 rounded-full text-xs font-bold hover:bg-orange-100 transition flex items-center">
                                        فتح <i class="fas fa-external-link-alt mr-1"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400 text-sm">-</span>
                                <?php endif; ?>
                            </div>
                            <div class="p-3 bg-white rounded-lg border border-gray-200 shadow-sm flex justify-between items-center group hover:border-purple-400 transition">
                                <span class="text-xs text-gray-500">رابط إضافي</span>
                                <?php if (!empty($order['additional_link'])): ?>
                                    <a href="<?php echo htmlspecialchars($order['additional_link'] ?? ''); ?>" target="_blank" class="text-purple-600 bg-purple-50 px-3 py-1 rounded-full text-xs font-bold hover:bg-purple-100 transition flex items-center">
                                        فتح <i class="fas fa-external-link-alt mr-1"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400 text-sm">-</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Order Notes -->
                        <?php if (!empty($order['notes'])): ?>
                        <div class="mt-4 p-4 bg-yellow-50 border border-yellow-100 rounded-lg">
                            <span class="text-xs text-yellow-700 font-bold block mb-1"><i class="fas fa-sticky-note ml-1"></i> ملاحظات:</span>
                            <p class="text-sm text-gray-700 break-words"><?php echo nl2br(htmlspecialchars($order['notes'] ?? '')); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3. Payments List (NO TABLE) -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden animate-entry" style="animation-delay: 0.2s;">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                        <h3 class="font-bold text-green-700 flex items-center"><i class="fas fa-money-bill-wave ml-2"></i> سجل الدفعات</h3>
                    </div>
                    
                    <div class="p-0">
                        <?php if (empty($payments)): ?>
                            <div class="p-8 text-center flex flex-col items-center justify-center text-gray-400">
                                <i class="fas fa-file-invoice-dollar text-4xl mb-3 opacity-30"></i>
                                <span class="text-sm">لا توجد دفعات مسجلة لهذا الطلب.</span>
                            </div>
                        <?php else: ?>
                            <div class="divide-y divide-gray-100">
                                <?php foreach ($payments as $pay): 
                                    // Translate Payment Method
                                    $raw_method = strtolower($pay['payment_method'] ?? '');
                                    $method_display = $pay['payment_method'] ?? ''; // fallback
                                    
                                    $translations = [
                                        'transfer'    => 'تحويل بنكي',
                                        'credit_card' => 'بطاقة ائتمان',
                                        'cash'        => 'نقدي',
                                        'check'       => 'شيك',
                                        'other'       => 'أخرى'
                                    ];
                                    
                                    if (array_key_exists($raw_method, $translations)) {
                                        $method_display = $translations[$raw_method];
                                    }
                                ?>
                                <!-- Payment Card -->
                                <div class="p-5 list-card flex flex-col sm:flex-row justify-between items-center gap-4">
                                    
                                    <!-- Icon & Info -->
                                    <div class="flex items-center w-full sm:w-auto">
                                        <div class="w-10 h-10 rounded-full bg-green-50 text-green-600 flex items-center justify-center ml-4 shrink-0">
                                            <i class="fas fa-check"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-bold text-gray-800">
                                                <?php 
                                                echo htmlspecialchars($method_display); 
                                                if(!empty($pay['bank_name'])) echo ' <span class="text-gray-400 font-normal text-xs">(' . htmlspecialchars($pay['bank_name'] ?? '') . ')</span>';
                                                ?>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1 font-mono">
                                                <i class="fas fa-hashtag text-[10px]"></i> <?php echo htmlspecialchars(($pay['reference_number'] ?? $pay['invoice_number'] ?? '')); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Amount & Date -->
                                    <div class="flex items-center justify-between w-full sm:w-auto gap-6 bg-green-50/50 sm:bg-transparent p-3 sm:p-0 rounded-lg">
                                        <div class="text-right">
                                            <span class="block text-xs text-gray-400 sm:hidden">التاريخ</span>
                                            <span class="text-xs text-gray-500 dir-ltr font-mono"><i class="far fa-clock ml-1"></i><?php echo formatToYemenTime($pay['payment_date'], 'Y-m-d'); ?></span>
                                        </div>
                                        <div class="text-left sm:text-left">
                                            <span class="block text-xs text-gray-400 sm:hidden">المبلغ</span>
                                            <span class="font-bold text-green-600 dir-ltr text-lg"><?php echo number_format($pay['amount'] ?? 0, 0); ?> ريال</span>
                                        </div>
                                        <?php if (!empty($pay['receipt_image_path'])):
                                            $receipt_path = $pay['receipt_image_path'];
                                            if (strpos($receipt_path, 'uploads/') === 0) {
                                                $receipt_path = '../' . $receipt_path;
                                            }
                                        ?>
                                            <button type="button" onclick="openPaymentImageModal('<?php echo htmlspecialchars($receipt_path, ENT_QUOTES); ?>')" class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition flex items-center justify-center" title="عرض صورة الدفع">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 4. Images -->
                <?php if (!empty($images)): ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden animate-entry" style="animation-delay: 0.3s;">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                        <h3 class="font-bold text-purple-700 flex items-center"><i class="fas fa-images ml-2"></i> المرفقات والصور</h3>
                    </div>
                    <div class="p-6 grid grid-cols-2 md:grid-cols-4 gap-4">
                        <?php foreach ($images as $img):
                             $path = $img['image_path'];
                             if (strpos($path, 'uploads/') === 0) {
                                 $path = '../' . $path;
                             }
                        ?>
                        <a href="<?php echo htmlspecialchars($path ?? ''); ?>" target="_blank" class="block group relative overflow-hidden rounded-xl border border-gray-200 shadow-sm">
                            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition z-10"></div>
                            <img src="<?php echo htmlspecialchars($path ?? ''); ?>" class="w-full h-32 object-cover transform group-hover:scale-110 transition duration-500 ease-in-out">
                            <div class="absolute bottom-2 right-2 z-20 opacity-0 group-hover:opacity-100 transition translate-y-2 group-hover:translate-y-0">
                                <span class="bg-white/90 p-1.5 rounded-full shadow text-gray-700 text-xs"><i class="fas fa-search-plus"></i></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 5. History Logs (General & Status) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 animate-entry" style="animation-delay: 0.4s;">
                    
                    <!-- General History -->
                    <?php if (!empty($history)): ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 h-full">
                        <h3 class="font-bold text-orange-600 mb-6 text-sm border-b pb-3 flex justify-between">
                            <span><i class="fas fa-history ml-2"></i> سجل العمليات</span>
                        </h3>
                        <div class="timeline">
                            <?php foreach ($history as $log): 
                                $status_str = $log['status'] ?? '';
                                $raw_status = strtolower(trim(str_replace(':', '', $status_str)));
                                $color = 'bg-gray-400';
                                $status_text = $status_str;

                                if ($raw_status === 'new') {
                                    $color = 'bg-blue-500';
                                    $status_text = 'تم الإنشاء';
                                } elseif ($raw_status === 'modified' || $raw_status === 'modifed') {
                                    $color = 'bg-amber-500';
                                    $status_text = 'تعديل';
                                } elseif ($raw_status === 'deleted') {
                                    $color = 'bg-red-500';
                                    $status_text = 'حذف';
                                }
                            ?>
                            <div class="timeline-item group">
                                <div class="timeline-marker <?php echo $color; ?> group-hover:scale-110 transition"></div>
                                <div class="timeline-content">
                                    <div class="flex justify-between items-start">
                                        <div class="font-bold text-gray-800 text-xs mb-1"><?php echo htmlspecialchars($status_text ?? ''); ?></div>
                                        <div class="text-[10px] text-gray-400 dir-ltr bg-gray-50 px-1 rounded"><?php echo formatToYemenTime($log['created_at']); ?></div>
                                    </div>
                                    <div class="text-xs text-gray-600 break-words mt-1 border-r-2 border-gray-100 pr-2">
                                        <?php 
                                            $notes = $log['notes'] ?? '-';
                                            // This regex finds numbers with comma separators ending in .00 and removes the .00
                                            $cleaned_notes = preg_replace('/(\d{1,3}(?:,\d{3})*)\.00\b/', '$1', $notes);
                                            echo htmlspecialchars($cleaned_notes); 
                                        ?>
                                   
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- State History -->
                    <?php if (!empty($state_history)): ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 h-full">
                        <h3 class="font-bold text-blue-600 mb-6 text-sm border-b pb-3 flex justify-between">
                            <span><i class="fas fa-exchange-alt ml-2"></i> تتبع الحالة</span>
                        </h3>
                        <div class="timeline">
                            <?php foreach ($state_history as $log): ?>
                            <div class="timeline-item group">
                                <div class="timeline-marker bg-blue-500 group-hover:ring-4 ring-blue-100 transition"></div>
                                <div class="timeline-content">
                                    <div class="flex justify-between items-start">
                                        <?php
                                            // Translate status from key to Arabic name
                                            $status_key = $log['status'] ?? '';
                                            $status_display_name = $status_translations[$status_key] ?? ucfirst($status_key); // Fallback
                                        ?>
                                        <div class="font-bold text-gray-800 text-sm mb-1"><?php echo htmlspecialchars($status_display_name); ?></div>
                                        <div class="text-[10px] text-gray-400 dir-ltr bg-gray-50 px-1 rounded"><?php echo formatToYemenTime($log['created_at']); ?></div>
                                    </div>
                                    <div class="text-xs text-gray-500 break-words mt-1">
                                        <?php if(!empty($log['notes'])): ?>
                                            <?php
                                                $notes = $log['notes'];
                                                // This regex finds numbers with comma separators ending in .00 and removes the .00
                                                $cleaned_notes = preg_replace('/(\d{1,3}(?:,\d{3})*)\.00\b/', '$1', $notes);
                                            ?>
                                            <div class="bg-blue-50/50 p-2 rounded mb-1"><?php echo nl2br(htmlspecialchars($cleaned_notes)); ?></div>
                                        <?php endif; ?>
                                       
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- LEFT COLUMN (Sidebar - Financials) -->
            <div class="lg:col-span-1 space-y-6">
                
                <!-- Financial Summary -->
                <div class="bg-white rounded-xl shadow-md border-t-4 border-[#C7A46D] p-6 animate-entry sticky top-24" style="animation-delay: 0.5s;">
                    <h3 class="font-bold text-lg text-[#B8956A] mb-6 flex items-center">
                        <div class="w-8 h-8 rounded-full bg-orange-50 flex items-center justify-center ml-2">
                            <i class="fas fa-file-invoice-dollar text-[#B8956A] text-sm"></i>
                        </div>
                        الملخص المالي
                    </h3>
                    
                    <div class="space-y-4 text-sm">
                        <!-- Subtotal -->
                        <div class="flex justify-between items-center border-b border-gray-100 pb-2">
                            <span class="text-gray-600">المجموع الفرعي</span>
                            <span class="font-bold text-gray-800 dir-ltr"><?php echo number_format($subtotal, 0); ?> ريال</span>
                        </div>

                        <!-- Discounts (All Shown even if 0) -->
                        <div class="flex justify-between items-center text-amber-600 bg-gray-50 px-2 py-1 rounded">
                            <span>خصم تلقائي (<?php echo floatval($auto_discount_percent); ?>%)</span>
                            <span class="dir-ltr font-bold text-gray-800"><?php echo ($auto_discount_amount > 0 ? '-' : ''); ?><?php echo number_format($auto_discount_amount, 0); ?></span>
                        </div>

                        <div class="flex justify-between items-center text-green-600 bg-gray-50 px-2 py-1 rounded">
                            <span class="flex items-center">
                                كوبون
                                <?php if($coupon_code): ?><span class="mr-1 px-1 bg-white border border-green-200 text-green-700 text-[10px] rounded"><?php echo htmlspecialchars($coupon_code ?? ''); ?></span><?php endif; ?>
                            </span>
                            <span class="dir-ltr font-bold text-gray-800"><?php echo ($coupon_discount > 0 ? '-' : ''); ?><?php echo number_format($coupon_discount, 0); ?></span>
                        </div>

                        <div class="flex justify-between items-center text-red-600 bg-gray-50 px-2 py-1 rounded">
                            <span>خصم تالف/منتهي</span>
                            <span class="dir-ltr font-bold text-gray-800"><?php echo ($damaged_total > 0 ? '-' : ''); ?><?php echo number_format($damaged_total, 0); ?></span>
                        </div>

                        <div class="flex justify-between items-center text-orange-600 bg-gray-50 px-2 py-1 rounded">
                            <span>خصم إضافي</span>
                            <span class="dir-ltr font-bold text-gray-800"><?php echo ($additional_discount > 0 ? '-' : ''); ?><?php echo number_format($additional_discount, 0); ?></span>
                        </div>

                        <!-- Shipping -->
                        <div class="flex justify-between items-center pt-2">
                            <span class="text-gray-600">تكلفة الشحن</span>
                            <span class="font-bold text-gray-800 dir-ltr"><?php echo number_format($shipping_cost, 0); ?> ريال</span>
                        </div>

                        <!-- Separator -->
                        <div class="border-t-2 border-dashed border-gray-200 my-2"></div>

                        <!-- Final Totals -->
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 shadow-inner">
                            <div class="flex justify-between items-center mb-3">
                                <span class="font-bold text-gray-800 text-base">الإجمالي النهائي</span>
                                <span class="font-bold text-[#B8956A] text-xl dir-ltr"><?php echo number_format($final_amount, 0); ?> ريال</span>
                            </div>
                            
                            <div class="flex justify-between items-center text-green-600 text-sm mb-1">
                                <span>المدفوع</span>
                                <span class="dir-ltr font-bold"><?php echo number_format($paid_amount, 0); ?> ريال</span>
                            </div>
                            
                            <div class="flex justify-between items-center text-red-600 text-sm font-bold border-t border-gray-200 pt-2 mt-2">
                                <span>المتبقي</span>
                                <span class="dir-ltr"><?php echo number_format($remaining_amount, 0); ?> ريال</span>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Customer Info -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 animate-entry" style="animation-delay: 0.6s;">
                    <h3 class="font-bold text-gray-700 mb-4 border-b pb-2 flex items-center"><i class="fas fa-user-circle ml-2 text-[#C7A46D]"></i> معلوماتك</h3>
                    <div class="space-y-4 text-sm">
                        <div class="text-center mb-4 bg-gray-50 p-4 rounded-xl">
                            <div class="w-14 h-14 bg-white border border-gray-200 shadow-sm rounded-full flex items-center justify-center mx-auto text-[#B8956A] mb-2">
                                <i class="fas fa-user text-2xl"></i>
                            </div>
                            <div class="font-bold text-gray-800 text-base"><?php echo htmlspecialchars($customer['name'] ?? ''); ?></div>
                            <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($customer['customer_code'] ?? ''); ?></div>
                        </div>
                        
                        <div class="flex items-center p-2 hover:bg-gray-50 rounded transition">
                            <div class="w-8 h-8 rounded bg-orange-50 text-[#B8956A] flex items-center justify-center ml-3">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <div>
                                <span class="text-xs text-gray-400 block">الجوال</span>
                                <span class="dir-ltr text-gray-800 font-bold"><?php echo htmlspecialchars($customer['mobile_number'] ?? ''); ?></span>
                            </div>
                        </div>
                        
                        <?php if(!empty($customer['city_name'])): ?>
                        <div class="flex items-center p-2 hover:bg-gray-50 rounded transition">
                            <div class="w-8 h-8 rounded bg-orange-50 text-[#B8956A] flex items-center justify-center ml-3">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div>
                                <span class="text-xs text-gray-400 block">المدينة</span>
                                <span class="text-gray-800 font-bold"><?php echo htmlspecialchars($customer['city_name'] ?? ''); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if(!empty($customer['address'])): ?>
                        <div class="p-3 bg-gray-50 rounded border border-gray-100 mt-2">
                            <span class="text-xs text-gray-400 block mb-1">العنوان بالتفصيل</span>
                            <p class="text-gray-700 text-xs leading-relaxed"><?php echo nl2br(htmlspecialchars($customer['address'] ?? '')); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>
    </div>
    
    <!-- Footer Simple -->
    <div class="text-center text-gray-400 py-6 text-xs no-print animate-entry" style="animation-delay: 0.7s;">
        &copy; <?php echo date('Y'); ?> جميع الحقوق محفوظة
    </div>


<div id="paymentImageModal" class="fixed inset-0 z-[9999] hidden bg-black/80 items-center justify-center p-4" onclick="if(event.target === this) closePaymentImageModal()">
    <button type="button" onclick="closePaymentImageModal()" class="absolute top-4 left-4 w-10 h-10 rounded-full bg-white text-gray-800 flex items-center justify-center shadow"><i class="fas fa-times"></i></button>
    <img id="paymentImagePreview" src="" alt="Payment receipt" class="max-w-full max-h-[85vh] rounded-lg shadow-2xl bg-white object-contain">
</div>
<script>
function openPaymentImageModal(src) {
    const modal = document.getElementById('paymentImageModal');
    document.getElementById('paymentImagePreview').src = src;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closePaymentImageModal() {
    const modal = document.getElementById('paymentImageModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.getElementById('paymentImagePreview').src = '';
}
</script>
</body>
</html>