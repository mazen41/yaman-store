<?php
session_start();

// --- 1. SET TIMEZONE & DATE HELPER ---
date_default_timezone_set('Asia/Aden');

/**
 * Formats a date string to Yemen Time (Asia/Aden).
 * Updated to use 12-hour format with AM/PM (h:i A).
 */
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

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/status_helpers.php';

// Determine permissions for current user
$canEditOrder = hasPermission($_SESSION['user_id'], 'orders', 'edit');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$order_id = intval($_GET['id']);
$page_title = 'عرض تفاصيل الطلب';

// --- Payment Method Translations (UPDATED) ---
$payment_methods_ar = [
    'cash' => 'نقدي',
    'transfer' => 'تحويل بنكي',
    'check' => 'شيك',
    'credit_card' => 'بطاقة ائتمانية',
    'customer_card' => 'بطاقة عميل', // Added for consistency
    'wallet' => 'محفظة إلكترونية',
    'other' => 'أخرى'
];

try {
    // 1. Fetch Main Order Details
    $stmt = $db->prepare("
        SELECT o.*,
               c.name as customer_name, c.customer_code, c.mobile_number, c.whatsapp_number,
               c.email, c.city_name, c.address, u.username as creator_name,
               COALESCE((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id), 0) as total_quantity
        FROM customer_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u ON o.created_by = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $_SESSION['error_message'] = 'لم يتم العثور على الطلب المطلوب.';
        header('Location: index.php');
        exit();
    }

    // 2. Fetch Order Items
    $items_stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Order Images
    $images_stmt = $db->prepare("SELECT * FROM order_images WHERE order_id = ? ORDER BY display_order");
    $images_stmt->execute([$order_id]);
    $images = $images_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ############### START: FETCHING FROM BOTH HISTORY TABLES ###############

    // 4. Fetch Order History (GENERAL EDITS/LOGS from `order_status_history`)
    $history_stmt = $db->prepare("
        SELECT h.*, u.username
        FROM order_status_history h
        LEFT JOIN users u ON h.created_by = u.id
        WHERE h.order_id = ?
        ORDER BY h.created_at DESC
    ");
    $history_stmt->execute([$order_id]);
    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Fetch State Change History (NEW SYSTEM from `order_state_history`)
    $state_history_stmt = $db->prepare("
        SELECT h.*, u.username
        FROM order_state_history h
        LEFT JOIN users u ON h.changed_by_id = u.id
        WHERE h.order_id = ?
        ORDER BY h.created_at DESC
    ");
    $state_history_stmt->execute([$order_id]);
    $state_history = $state_history_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ############### END: FETCHING FROM BOTH HISTORY TABLES ###############

    // 6. Fetch Notifications
    $notif_stmt = $db->prepare("SELECT * FROM order_notifications WHERE order_id = ? ORDER BY created_at DESC");
    $notif_stmt->execute([$order_id]);
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. Fetch Damaged Items (for financials)
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

    // 9. Fetch Payments for this order (via invoices) --- (MODIFIED QUERY)
    $payments_stmt = $db->prepare("
        SELECT 
            cp.*, 
            ci.invoice_number,
            u_adder.username AS adder_name
        FROM customer_payments cp
        INNER JOIN customer_invoices ci ON cp.invoice_id = ci.id
        LEFT JOIN users u_adder ON cp.added_by = u_adder.id
        WHERE ci.order_id = ? 
        ORDER BY cp.payment_date DESC
    ");
    $payments_stmt->execute([$order_id]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('حدث خطأ في قاعدة البيانات: ' . $e->getMessage());
}

include '../../includes/header.php';
?>

<style>
    .card-box { background: #fff; border-radius: 12px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); margin-bottom: 24px; overflow: hidden; border: 1px solid #e5e7eb; }
    .card-header { padding: 16px 20px; border-bottom: 1px solid #e5e7eb; background: #f9fafb; display: flex; align-items: center; justify-content: space-between; }
    .card-title { font-size: 16px; font-weight: 700; color: #1f2937; margin: 0; display: flex; align-items: center; gap: 8px; }
    .card-body { padding: 20px; }
    .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
    .info-row:last-child { border-bottom: none; }
    .info-label { color: #6b7280; font-weight: 500; }
    .info-value { color: #111827; font-weight: 600; }
    .status-badge { padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
    .status-new { background: #eff6ff; color: #1d4ed8; }
    .status-completed { background: #ecfdf5; color: #047857; }
    .status-cancelled { background: #fef2f2; color: #b91c1c; }
    .status-processing { background: #fffbeb; color: #b45309; }
    .table-custom th { background: #f9fafb; color: #374151; font-weight: 600; text-align: right; padding: 12px; font-size: 13px; }
    .table-custom td { padding: 12px; border-bottom: 1px solid #e5e7eb; color: #4b5563; }
    .img-thumbnail { width: 100%; height: 150px; object-fit: cover; border-radius: 8px; border: 1px solid #e5e7eb; transition: transform 0.2s; }
    .img-thumbnail:hover { transform: scale(1.02); }
</style>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-xl shadow-lg p-6 mb-8 text-white">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2"><i class="fas fa-file-alt"></i> عرض تفاصيل الطلب #<?php echo htmlspecialchars(formatOrderNumber($order['order_number'])); ?></h1>
                    <p class="text-blue-100 mt-2 opacity-90">
                        <i class="far fa-clock ml-1"></i> تاريخ الإنشاء: <?php echo formatToYemenTime($order['created_at']); ?> 
                        <span class="mx-2">|</span> 
                        <i class="fas fa-user ml-1"></i> بواسطة: <?php echo htmlspecialchars($order['creator_name'] ?? 'غير معروف'); ?>
                    </p>
                </div>
                <div class="flex gap-3">
                    <?php if ($canEditOrder): ?><a href="edit.php?id=<?php echo $order_id; ?>" class="bg-white text-blue-600 hover:bg-blue-50 px-4 py-2 rounded-lg font-bold transition shadow-sm"><i class="fas fa-edit ml-1"></i> تعديل الطلب</a><?php endif; ?>
                    <a href="print.php?id=<?php echo $order_id; ?>" target="_blank" class="bg-blue-500 hover:bg-blue-400 text-white px-4 py-2 rounded-lg font-bold transition shadow-sm"><i class="fas fa-print ml-1"></i> طباعة</a>
                    <a href="index.php" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg font-bold transition shadow-sm"><i class="fas fa-arrow-left ml-1"></i> عودة</a>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Right Column -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Order Info -->
                 <div class="card-box">
                    <div class="card-header">
                        <h3 class="card-title text-gray-700"><i class="fas fa-info-circle"></i> بيانات الطلب</h3>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                            <div class="info-row md:block border-b md:border-b-0 pb-2 md:pb-0">
                                <span class="info-label">رقم الطلب</span>
                                <span class="info-value">#<?php echo htmlspecialchars($order['order_number']); ?></span>
                            </div>
                            <div class="info-row md:block border-b md:border-b-0 pb-2 md:pb-0">
                                <span class="info-label">تاريخ الطلب</span>
                                <span class="info-value dir-ltr text-right"><?php echo formatToYemenTime($order['created_at']); ?></span>
                            </div>
                            <div class="info-row md:block border-b md:border-b-0 pb-2 md:pb-0">
                                <span class="info-label">عدد القطع</span>
                                <span class="info-value"><?php echo (int) $order['total_quantity']; ?></span>
                            </div>
                            <div class="info-row md:block">
                                <span class="info-label">الحالة</span>
                                <span class="status-badge status-<?php echo htmlspecialchars($order['status']); ?>">
                                    <?php echo htmlspecialchars(getOrderStatusText($order['status'])); ?>
                                </span>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                             <div class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                                <p class="text-xs text-gray-500 mb-1">رابط الطلب الأساسي</p>
                                <?php if (!empty($order['order_link'])): ?>
                                    <a href="<?php echo htmlspecialchars($order['order_link']); ?>" target="_blank" class="text-blue-600 hover:underline flex items-center gap-2 break-all"><i class="fas fa-external-link-alt text-sm"></i><?php echo htmlspecialchars($order['order_link']); ?></a>
                                <?php else: ?>
                                    <span class="text-gray-400 text-sm">غير محدد</span>
                                <?php endif; ?>
                            </div>
                            <div class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                                <p class="text-xs text-gray-500 mb-1">رابط إضافي</p>
                                <?php if (!empty($order['additional_link'])): ?>
                                    <a href="<?php echo htmlspecialchars($order['additional_link']); ?>" target="_blank" class="text-purple-600 hover:underline flex items-center gap-2 break-all"><i class="fas fa-external-link-alt text-sm"></i><?php echo htmlspecialchars($order['additional_link']); ?></a>
                                <?php else: ?>
                                    <span class="text-gray-400 text-sm">غير محدد</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="card-box">
                    <div class="card-header">
                        <h3 class="card-title text-indigo-600"><i class="fas fa-shopping-cart"></i> منتجات الطلب (<?php echo count($items); ?>)</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full table-custom">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>المنتج</th>
                                    <th>الرابط</th>
                                    <th>ملاحظات</th>
                                    <th class="text-center">الكمية</th>
                                    <th class="text-center">السعر</th>
                                    <th class="text-center">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $index => $item): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td class="font-semibold text-gray-800"><?php echo htmlspecialchars($item['product_name']); ?></td>
                                        <td>
                                            <?php if (!empty($item['product_link'])): ?>
                                                <a href="<?php echo htmlspecialchars($item['product_link']); ?>" target="_blank" class="text-blue-500 hover:text-blue-700"><i class="fas fa-external-link-alt"></i> رابط</a>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-sm"><?php echo htmlspecialchars($item['notes'] ?: '-'); ?></td>
                                        <td class="text-center font-bold bg-gray-50"><?php echo $item['quantity']; ?></td>
                                        <td class="text-center dir-ltr text-gray-600"><?php echo number_format($item['unit_price'], 0, ',', '.'); ?></td>
                                        <td class="text-center dir-ltr font-bold text-indigo-600"><?php echo number_format($item['total_price'], 0, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Payments -- (MODIFIED TABLE) -->
                <div class="card-box">
                    <div class="card-header"><h3 class="card-title text-green-700"><i class="fas fa-money-bill-wave"></i> جدول الدفعات</h3></div>
                    <div class="card-body">
                        <?php if (empty($payments)): ?>
                            <div class="text-center text-gray-400 py-4"><i class="fas fa-money-check-alt text-2xl mb-2"></i><p>لا توجد دفعات مسجلة لهذا الطلب</p></div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full table-custom">
                                    <thead><tr><th>رقم المرجع</th><th>مبلغ الدفع</th><th>طريقة الدفع</th><th>تاريخ الدفع</th><th>أضيف بواسطة</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                            <tr>
                                                <td class="text-sm text-gray-600"><?php echo htmlspecialchars($payment['reference_number'] ?: ('PAY-' . $payment['id'])); ?></td>
                                                <td class="text-center font-bold text-green-600 dir-ltr"><?php echo number_format($payment['amount'], 0, ',', '.'); ?> ريال</td>
                                                <td class="text-sm">
                                                    <?php 
                                                        $pm_key = $payment['payment_method'];
                                                        echo htmlspecialchars($payment_methods_ar[$pm_key] ?? ucfirst(str_replace('_', ' ', $pm_key))); 
                                                    ?>
                                                </td>
                                                <td class="text-sm text-gray-500 dir-ltr"><?php echo formatToYemenTime($payment['payment_date'], 'Y-m-d'); ?></td>
                                                <td class="text-sm text-gray-500"><?php echo htmlspecialchars($payment['adder_name'] ?? 'غير محدد'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Order Images -->
                <?php if (!empty($images)): ?>
                    <div class="card-box">
                        <div class="card-header"><h3 class="card-title text-purple-600"><i class="fas fa-images"></i> صور ومرفقات الطلب</h3></div>
                        <div class="card-body">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <?php foreach ($images as $img):
                                    $stored_path = $img['image_path'] ?? '';
                                    if ($stored_path !== '' && strpos($stored_path, 'uploads/') === 0) {
                                        $image_url = '../../' . $stored_path;
                                    } else {
                                        $image_url = $stored_path;
                                    }
                                    ?>
                                    <a href="<?php echo htmlspecialchars($image_url); ?>" target="_blank" class="block group">
                                        <img src="<?php echo htmlspecialchars($image_url); ?>" alt="Order Image" class="img-thumbnail">
                                        <div class="mt-1 text-xs text-center text-gray-500 truncate"><?php echo htmlspecialchars($img['image_name']); ?></div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ############### START: NEW DISPLAY SECTIONS ############### -->
                
                <!-- 1. GENERAL HISTORY (order_status_history) - SHOWING EDITS/PRICES/ETC -->
                <div class="card-box">
                    <div class="card-header">
                        <h3 class="card-title text-orange-600"><i class="fas fa-edit"></i> سجل التعديلات والعمليات (الأرشيف)</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($history)): ?>
                            <div class="text-center text-gray-400 py-4">
                                <i class="fas fa-history text-2xl mb-2"></i>
                                <p>لا يوجد سجل تعديلات لهذا الطلب.</p>
                            </div>
                        <?php else: ?>
                            <div class="relative border-r-2 border-gray-200 mr-3 space-y-6">
                                <?php foreach ($history as $log): 
                                    // Translate English status to Arabic
                                    $dotColor = 'gray';
                                    $status_text = $log['status'];
                                    
                                    // Normalize the status string (lowercase, remove colon if present)
                                    $check_status = strtolower(trim(str_replace(':', '', $status_text)));

                                    if ($check_status === 'new') {
                                        $dotColor = 'blue';
                                        $status_text = 'جديد';
                                    } elseif ($check_status === 'modified' || $check_status === 'modifed') {
                                        $dotColor = 'amber';
                                        $status_text = 'تم التعديل';
                                    } elseif ($check_status === 'deleted') {
                                        $dotColor = 'red';
                                        $status_text = 'محذوف';
                                    }
                                ?>
                                    <div class="relative flex items-start mr-[-9px]">
                                        <div class="w-4 h-4 rounded-full bg-<?php echo $dotColor; ?>-500 border-2 border-white shadow-sm mt-1"></div>
                                        <div class="mr-4 flex-1">
                                            <div class="flex items-center justify-between mb-1">
                                                <span class="text-sm font-bold text-gray-800">
                                                    <?php echo htmlspecialchars($status_text); ?>
                                                </span>
                                                <span class="text-xs text-gray-500 dir-ltr"><?php echo formatToYemenTime($log['created_at']); ?></span>
                                            </div>
                                            <!-- The notes contain the detailed info (e.g., price changed from X to Y) -->
                                            <p class="text-sm text-gray-600 bg-gray-50 p-2 rounded border"><?php echo htmlspecialchars($log['notes'] ?: 'لا توجد تفاصيل'); ?></p>
                                            <span class="text-xs text-gray-400 mt-1 block">بواسطة: <?php echo htmlspecialchars($log['username'] ?? 'النظام'); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. STATE CHANGE HISTORY (NEW SYSTEM - order_state_history) -->
                <div class="card-box">
                    <div class="card-header">
                        <h3 class="card-title text-blue-600"><i class="fas fa-exchange-alt"></i> سجل تغييرات الحالة (النظام الجديد)</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($state_history)): ?>
                            <div class="text-center text-gray-400 py-4">
                                <i class="fas fa-history text-2xl mb-2"></i>
                                <p>لا يوجد سجل تغييرات جديد لهذا الطلب.</p>
                            </div>
                        <?php else: ?>
                            <div class="relative border-r-2 border-gray-200 mr-3 space-y-6">
                                <?php foreach ($state_history as $log): ?>
                                    <div class="relative flex items-start mr-[-9px]">
                                        <div class="w-4 h-4 rounded-full bg-blue-500 border-2 border-white shadow-sm mt-1"></div>
                                        <div class="mr-4 flex-1">
                                            <div class="flex items-center justify-between mb-1">
                                                <span class="text-sm font-bold text-gray-800">
                                                    <?php echo htmlspecialchars($log['status']); ?>
                                                </span>
                                                <span class="text-xs text-gray-500 dir-ltr"><?php echo formatToYemenTime($log['created_at']); ?></span>
                                            </div>
                                            <p class="text-sm text-gray-600 bg-gray-50 p-2 rounded border"><?php echo htmlspecialchars($log['notes'] ?: 'لا توجد ملاحظات'); ?></p>
                                            <span class="text-xs text-gray-400 mt-1 block">بواسطة: <?php echo htmlspecialchars($log['username'] ?? 'النظام'); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ############### END: NEW DISPLAY SECTIONS ############### -->
            </div>

            <!-- Left Column (Sidebar) -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Financial Summary -->
                <div class="card-box shadow-md border-t-4 border-t-green-500">
                    <div class="card-header"><h3 class="card-title text-green-700"><i class="fas fa-file-invoice-dollar"></i> الملخص المالي</h3></div>
                    <div class="card-body space-y-3">
                        <div class="info-row"><span class="info-label">المجموع الفرعي</span><span class="info-value dir-ltr"><?php echo number_format($order['subtotal_amount'], 0, ',', '.'); ?> ريال يمني</span></div>
                        <?php if ($order['automatic_discount_amount'] > 0): ?>
                            <div class="info-row text-amber-600"><span class="info-label text-amber-600">خصم تلقائي (<?php echo floatval($order['automatic_discount_percentage']); ?>%)</span><span class="info-value dir-ltr">-<?php echo number_format($order['automatic_discount_amount'], 0, ',', '.'); ?> ريال يمني</span></div>
                        <?php endif; ?>
                        <?php $coupon_discount = $order['discount_amount'] - $order['automatic_discount_amount']; if ($coupon_discount > 0): ?>
                            <div class="info-row text-green-600">
                                <span class="info-label text-green-600">خصم كوبون <?php if ($coupon_code): ?><span class="text-xs bg-green-100 px-2 py-0.5 rounded-full mr-1 border border-green-200 font-mono"><?php echo htmlspecialchars($coupon_code); ?></span><?php endif; ?></span>
                                <span class="info-value dir-ltr">-<?php echo number_format($coupon_discount, 0, ',', '.'); ?> ريال يمني</span>
                            </div>
                        <?php endif; ?>
                        <?php $damaged_total = 0; foreach ($damaged_items as $d) $damaged_total += $d['price']; if ($damaged_total > 0): ?>
                            <div class="info-row text-red-600"><span class="info-label text-red-600">خصم تالف/منتهي</span><span class="info-value dir-ltr">-<?php echo number_format($damaged_total, 0, ',', '.'); ?> ريال يمني</span></div>
                        <?php endif; ?>
                        <?php if ($order['additional_discount'] > 0): ?>
                            <div class="info-row text-orange-600"><span class="info-label text-orange-600">خصم إضافي</span><span class="info-value dir-ltr">-<?php echo number_format($order['additional_discount'], 0, ',', '.'); ?> ريال يمني</span></div>
                        <?php endif; ?>
                        <div class="border-t my-2"></div>
                        <div class="info-row"><span class="info-label">تكلفة الشحن</span><span class="info-value dir-ltr"><?php echo number_format($order['shipping_cost'], 0, ',', '.'); ?> ريال يمني</span></div>
                        <div class="bg-gray-50 p-3 rounded-lg mt-2 border border-gray-200">
                            <div class="flex justify-between items-center mb-2"><span class="text-gray-700 font-bold text-lg">الإجمالي النهائي</span><span class="text-indigo-600 font-bold text-xl dir-ltr"><?php echo number_format($order['final_amount'], 0, ',', '.'); ?> ريال يمني</span></div>
                            <div class="flex justify-between items-center text-sm text-green-600"><span>المدفوع</span><span class="dir-ltr"><?php echo number_format($order['paid_amount'], 0, ',', '.'); ?> ريال يمني</span></div>
                            <div class="flex justify-between items-center text-sm text-red-600 font-semibold mt-1"><span>المتبقي</span><span class="dir-ltr"><?php echo number_format($order['final_amount'] - $order['paid_amount'], 0, ',', '.'); ?> ريال يمني</span></div>
                        </div>
                    </div>
                </div>

                <!-- Customer Info -->
                <div class="card-box">
                    <div class="card-header"><h3 class="card-title text-blue-600"><i class="fas fa-user-circle"></i> معلومات العميل</h3></div>
                    <div class="card-body space-y-3">
                        <div class="text-center mb-4">
                            <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto text-2xl mb-2"><i class="fas fa-user"></i></div>
                            <h4 class="font-bold text-gray-800"><?php echo htmlspecialchars($order['customer_name']); ?></h4>
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full mt-1 inline-block"><?php echo htmlspecialchars($order['customer_code']); ?></span>
                        </div>
                        <div class="info-row"><span class="info-label"><i class="fas fa-mobile-alt ml-2"></i>الجوال</span><a href="tel:<?php echo htmlspecialchars($order['mobile_number']); ?>" class="info-value hover:text-blue-600 dir-ltr text-right"><?php echo htmlspecialchars($order['mobile_number'] ?: '-'); ?></a></div>
                        <div class="info-row"><span class="info-label"><i class="fab fa-whatsapp ml-2 text-green-500"></i>واتساب</span><a href="https://wa.me/<?php echo htmlspecialchars($order['whatsapp_number']); ?>" target="_blank" class="info-value hover:text-green-600 dir-ltr text-right"><?php echo htmlspecialchars($order['whatsapp_number'] ?: '-'); ?></a></div>
                        <div class="info-row"><span class="info-label"><i class="fas fa-envelope ml-2 text-gray-400"></i>الإيميل</span><span class="info-value text-sm"><?php echo htmlspecialchars($order['email'] ?: '-'); ?></span></div>
                        <div class="info-row"><span class="info-label"><i class="fas fa-map-marker-alt ml-2 text-red-400"></i>المدينة</span><span class="info-value"><?php echo htmlspecialchars($order['city_name'] ?: '-'); ?></span></div>
                        <?php if (!empty($order['address'])): ?>
                            <div class="mt-3 pt-3 border-t"><p class="text-sm text-gray-500 mb-1">العنوان التفصيلي:</p><p class="text-sm text-gray-800"><?php echo nl2br(htmlspecialchars($order['address'])); ?></p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Additional Order Details -->
                <div class="card-box">
                    <div class="card-header"><h3 class="card-title text-gray-600"><i class="fas fa-info-circle"></i> بيانات إضافية</h3></div>
                    <div class="card-body space-y-3">
                        <div class="info-row">
                            <span class="info-label">الحالة الحالية</span>
                            <span class="status-badge status-<?php echo $order['status']; ?>"><?php echo htmlspecialchars(getOrderStatusText($order['status'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">تاريخ التوصيل المتوقع</span>
                            <span class="info-value"><?php echo formatToYemenTime($order['expected_delivery_date'], 'Y-m-d'); ?></span>
                        </div>
                        <?php if (!empty($order['notes'])): ?>
                            <div class="mt-3 pt-3 border-t"><p class="text-sm text-gray-500 mb-1">ملاحظات الطلب:</p><p class="text-sm text-gray-800 bg-yellow-50 p-2 rounded border border-yellow-100 break-words"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p></div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>