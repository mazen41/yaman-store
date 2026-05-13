<?php
/**
 * Order Details Page - Complete view with invoices and payments
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/status_helpers.php';

// Get token and order_id from URL
$token = $_GET['token'] ?? '';
$order_id = $_GET['order_id'] ?? 0;

if (empty($token) || empty($order_id)) {
    die('Invalid access.');
}

// Verify customer by token
$stmt = $db->prepare("SELECT * FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('Invalid or expired link.');
}

// Get order details
$stmt = $db->prepare("
    SELECT o.*,
           (COALESCE(o.discount_amount, 0) + COALESCE(o.additional_discount, 0) + COALESCE(o.automatic_discount_amount, 0)) as total_discounts
    FROM customer_orders o
    WHERE o.id = ? AND o.customer_id = ?
");
$stmt->execute([$order_id, $customer['id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found.');
}

// Get order items
try {
    $stmt = $db->prepare("
        SELECT 
            oi.*,
            oi.product_name,
            oi.quantity,
            oi.unit_price,
            oi.total_price,
            oi.product_link,
            oi.notes
        FROM order_items oi
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ");
    $stmt->execute([$order_id]);
    $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $order_items = [];
}

// Calculate total quantity from baskets
$order['total_quantity'] = array_sum(array_column($order_items, 'quantity'));

// Set subtotal_amount if not exists (for compatibility)
if (!isset($order['subtotal_amount'])) {
    $order['subtotal_amount'] = $order['subtotal'] ?? 0;
}

// Set automatic_discount if not exists
if (!isset($order['automatic_discount'])) {
    $order['automatic_discount'] = $order['automatic_discount_amount'] ?? 0;
}

// Get invoices for this order
$stmt = $db->prepare("SELECT * FROM customer_invoices WHERE order_id = ? ORDER BY created_at DESC");
$stmt->execute([$order_id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payments for this order (payments are linked to invoices, not orders directly)
try {
    $stmt = $db->prepare("
        SELECT 
            cp.*, 
            ba.bank_name, 
            ba.account_holder_name
        FROM customer_payments cp
        INNER JOIN customer_invoices ci ON cp.invoice_id = ci.id
        LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
        WHERE ci.order_id = ?
        ORDER BY cp.payment_date DESC
    ");
    $stmt->execute([$order_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
}

// Get order images
try {
    $stmt = $db->prepare("SELECT * FROM order_images WHERE order_id = ? ORDER BY display_order");
    $stmt->execute([$order_id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $images = [];
}

// Get order history
try {
    $stmt = $db->prepare("SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at DESC");
    $stmt->execute([$order_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $history = [];
}

// Get damaged items (for financial summary)
try {
    $stmt = $db->prepare("SELECT * FROM order_damaged_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $damaged_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $damaged_items = [];
}

// Calculate totals - use paid_amount from order table
$total_paid = $order['paid_amount'] ?? 0;
$remaining = $order['final_amount'] - $total_paid;

// Helper to improve readability of status history comments in Arabic
function translateHistoryNote($note) {
    if ($note === null || $note === '') {
        return '';
    }

    // Normalize spacing
    $text = trim((string)$note);

    // Replace English phrase with Arabic
    $text = str_ireplace('status changed to', 'تم تغيير الحالة إلى', $text);

    // Replace known English status keys with their Arabic equivalents
    $status_keys = [
        'new', 'pending', 'approved', 'in_preparation', 'processing', 'shipped', 'notes',
        'under_sorting', 'sorted', 'in_delivery', 'delivered', 'received', 'completed',
        'cancelled', 'rejected', 'on_hold', 'returned', 'refunded'
    ];

    foreach ($status_keys as $key) {
        if (stripos($text, $key) !== false) {
            $arabic = getOrderStatusText($key);
            $text = str_ireplace($key, $arabic, $text);
        }
    }

    return $text;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل الطلب - <?php echo htmlspecialchars(formatOrderNumber($order['order_number'])); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; }
        @media print {
            .no-print { display: none !important; }
        }
        /* Timeline Styles */
        .timeline-item {
            position: relative;
            padding-right: 2rem;
            border-right: 2px solid #e5e7eb;
            padding-bottom: 1.5rem;
        }
        .timeline-item:last-child {
            border-right: 2px solid transparent;
        }
        .timeline-marker {
            position: absolute;
            right: -9px;
            top: 0;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #3b82f6;
            border: 2px solid white;
            box-shadow: 0 0 0 2px #e5e7eb;
        }
        .timeline-date {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 0.25rem;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <nav class="bg-gradient-to-r from-blue-600 to-purple-600 text-white shadow-lg no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <a href="portal.php?token=<?php echo $token; ?>" class="text-white hover:text-blue-200 ml-4">
                        <i class="fas fa-arrow-right text-xl"></i>
                    </a>
                    <div>
                        <h1 class="text-xl font-bold">تفاصيل الطلب</h1>
                        <p class="text-sm text-blue-100"><?php echo htmlspecialchars(formatOrderNumber($order['order_number'])); ?></p>
                    </div>
                </div>
                <button onclick="window.print()" class="bg-white text-blue-600 px-4 py-2 rounded-lg hover:bg-blue-50 transition">
                    <i class="fas fa-print ml-2"></i>
                    طباعة
                </button>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Right Column -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- Order Info Card -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-3 flex items-center">
                        <i class="fas fa-info-circle ml-2 text-blue-600"></i>
                        معلومات الطلب
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">رقم الطلب</p>
                            <p class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars(formatOrderNumber($order['order_number'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">تاريخ الطلب</p>
                            <p class="text-lg font-bold text-gray-800"><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">عدد القطع</p>
                            <p class="text-lg font-bold text-gray-800"><?php echo (int)($order['total_quantity'] ?? 0); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">الحالة</p>
                            <?php echo getOrderStatusBadge($order['status'] ?? 'new', 'text-sm'); ?>
                        </div>
                    </div>

                    <?php if (!empty($order['notes'])): ?>
                    <div class="p-4 bg-yellow-50 border-r-4 border-yellow-400 rounded mb-4">
                        <p class="text-sm font-semibold text-gray-700 mb-1">
                            <i class="fas fa-sticky-note ml-2"></i>
                            ملاحظات
                        </p>
                        <p class="text-gray-600"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="flex flex-wrap gap-3">
                        <?php if (!empty($order['order_link'])): ?>
                        <a href="<?php echo htmlspecialchars($order['order_link']); ?>" target="_blank" 
                           class="inline-flex items-center px-4 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition border border-blue-200">
                            <i class="fas fa-external-link-alt ml-2"></i>
                            رابط الطلب
                        </a>
                        <?php endif; ?>
                        
                        <?php if (!empty($order['additional_link'])): ?>
                        <a href="<?php echo htmlspecialchars($order['additional_link']); ?>" target="_blank" 
                           class="inline-flex items-center px-4 py-2 bg-purple-50 text-purple-700 rounded-lg hover:bg-purple-100 transition border border-purple-200">
                            <i class="fas fa-link ml-2"></i>
                            رابط إضافي
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order Images -->
                <?php if (!empty($images)): ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-3 flex items-center">
                        <i class="fas fa-images ml-2 text-purple-600"></i>
                        صور ومرفقات
                    </h2>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <?php foreach ($images as $img): 
                            // Adjust path for customer portal if needed, usually relative from root works if configured right
                            // Assuming images are in uploads/orders/images/
                            // The DB path is usually uploads/orders/images/... or similar.
                            // We need to make sure it's accessible.
                            $image_src = '../' . $img['image_path']; // Add ../ to go up from customer_portal/
                            if (strpos($img['image_path'], '../../') === 0) {
                                $image_src = $img['image_path']; // Already has relative path
                            } elseif (strpos($img['image_path'], 'uploads') === 0) {
                                $image_src = '../' . $img['image_path'];
                            }
                        ?>
                        <a href="<?php echo htmlspecialchars($image_src); ?>" target="_blank" class="block group">
                            <img src="<?php echo htmlspecialchars($image_src); ?>" 
                                 alt="Order Image" 
                                 class="w-full h-32 object-cover rounded-lg border border-gray-200 shadow-sm group-hover:shadow-md transition">
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Invoices & Payments -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 border-b pb-3 flex items-center">
                        <i class="fas fa-file-invoice-dollar ml-2 text-green-600"></i>
                        الفواتير والدفعات
                    </h2>
                    
                    <!-- Invoices -->
                    <?php if (!empty($invoices)): ?>
                    <h3 class="text-sm font-bold text-gray-700 mb-2">الفواتير</h3>
                    <div class="overflow-x-auto mb-6">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-right text-xs text-gray-500">رقم الفاتورة</th>
                                    <th class="px-4 py-2 text-right text-xs text-gray-500">التاريخ</th>
                                    <th class="px-4 py-2 text-right text-xs text-gray-500">المبلغ</th>
                                    <th class="px-4 py-2 text-right text-xs text-gray-500">الحالة</th>
                                    <th class="px-4 py-2 text-right text-xs text-gray-500 no-print"></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td class="px-4 py-2 text-sm"><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($invoice['created_at'])); ?></td>
                                    <td class="px-4 py-2 text-sm font-bold"><?php echo number_format($invoice['amount'], 0, '.', ''); ?> ريال</td>
                                    <td class="px-4 py-2 text-sm"><?php echo getInvoiceStatusBadge($invoice['status']); ?></td>
                                    <td class="px-4 py-2 text-sm no-print">
                                        <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>&token=<?php echo $token; ?>" target="_blank" class="text-blue-600 hover:underline">طباعة</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <!-- Payments -->
                    <?php if (!empty($payments)): ?>
                    <h3 class="text-sm font-bold text-gray-700 mb-2">سجل الدفعات</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-right text-xs text-gray-500">رقم المرجع</th>
                                    <th class="px-4 py-2 text-right text-xs text-gray-500">مبلغ الدفع</th>
                                    <th class="px-4 py-2 text-right text-xs text-gray-500">نوع الحوالة</th>
                                    <th class="px-4 py-2 text-right text-xs text-gray-500">تاريخ الدفع</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($payments as $payment): ?>
                                <?php
                                    // Build payment type label
                                    $method = $payment['payment_method'] ?? 'cash';
                                    $type_label = 'نقدي';
                                    if ($method === 'transfer') {
                                        $bank_part = !empty($payment['bank_name'])
                                            ? (' - ' . $payment['bank_name'])
                                            : '';
                                        $type_label = 'حوالة بنكية' . $bank_part;
                                    } elseif ($method === 'credit_card') {
                                        $type_label = 'بطاقة ائتمانية';
                                    } elseif ($method === 'check') {
                                        $type_label = 'شيك';
                                    } elseif ($method === 'other') {
                                        $type_label = 'أخرى';
                                    }
                                    $reference = trim($payment['reference_number'] ?? '');
                                    if ($reference === '') {
                                        $reference = 'PAY-' . $payment['id'];
                                    }
                                ?>
                                <tr>
                                    <td class="px-4 py-2 text-sm text-gray-600 break-all"><?php echo htmlspecialchars($reference); ?></td>
                                    <td class="px-4 py-2 text-sm font-bold text-green-600 dir-ltr"><?php echo number_format($payment['amount'], 0, '.', ''); ?> ريال</td>
                                    <td class="px-4 py-2 text-sm"><?php echo htmlspecialchars($type_label); ?></td>
                                    <td class="px-4 py-2 text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Left Column -->
            <div class="lg:col-span-1 space-y-6">
                
                <!-- Financial Summary Card -->
                <div class="bg-white rounded-lg shadow-md p-6 border-t-4 border-green-500">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-wallet ml-2 text-green-600"></i>
                        التفاصيل المالية (جديد)
                    </h2>
                    
                    <?php
                    // Prepare values
                    $original_amount   = $order['subtotal_amount'] ?? 0;
                    $total_discount    = $order['discount_amount'] ?? 0; // includes automatic/coupon/etc
                    $discount_percent  = $order['automatic_discount_percentage'] ?? 0;
                    $damaged_total     = 0;
                    foreach ($damaged_items as $d) { $damaged_total += ($d['price'] ?? 0); }
                    $final_amount      = $order['final_amount'] ?? 0;
                    $paid_amount       = $total_paid;
                    $remaining_amount  = $remaining;
                    ?>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">المبلغ الأصلي</span>
                            <span class="font-semibold"><?php echo number_format($original_amount, 0, '.', ''); ?> ريال</span>
                        </div>

                        <div class="flex justify-between text-green-600">
                            <span>الخصم</span>
                            <span class="dir-ltr">-<?php echo number_format($total_discount, 0, '.', ''); ?> ريال</span>
                        </div>

                        <div class="flex justify-between text-amber-600">
                            <span>نسبة الخصم</span>
                            <span><?php echo $discount_percent > 0 ? number_format($discount_percent, 0, '', '') . '%' : '0.0%'; ?></span>
                        </div>

                        <div class="flex justify-between text-red-600">
                            <span>مبلغ التوالف</span>
                            <span class="dir-ltr">-<?php echo number_format($damaged_total, 0, '.', ''); ?> ريال</span>
                        </div>

                        <div class="border-t my-2"></div>

                        <div class="flex justify-between">
                            <span class="text-gray-600">المبلغ النهائي</span>
                            <span class="font-bold text-blue-600"><?php echo number_format($final_amount, 0, '.', ''); ?> ريال</span>
                        </div>

                        <div class="flex justify-between text-green-600 text-xs font-semibold mt-1">
                            <span>المدفوع</span>
                            <span><?php echo number_format($paid_amount, 0, '.', ''); ?> ريال</span>
                        </div>
                        <div class="flex justify-between text-red-600 text-xs font-semibold mt-1">
                            <span>المتبقي</span>
                            <span><?php echo number_format($remaining_amount, 0, '.', ''); ?> ريال</span>
                        </div>
                    </div>
                </div>

                <!-- Order History Timeline -->
                <?php if (!empty($history)): ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-history ml-2 text-gray-600"></i>
                        سجل الحالة
                    </h2>
                    <div class="space-y-0">
                        <?php foreach ($history as $log): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker"></div>
                            <div class="timeline-date"><?php echo date('Y-m-d H:i', strtotime($log['created_at'])); ?></div>
                            <div class="font-bold text-gray-800 text-sm"><?php echo htmlspecialchars(getOrderStatusText($log['status'])); ?></div>
                            <?php if (!empty($log['notes'])): ?>
                            <div class="text-xs text-gray-600 mt-1 bg-gray-50 p-2 rounded"><?php echo htmlspecialchars(translateHistoryNote($log['notes'])); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Customer Info (Verification) -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-user ml-2 text-gray-600"></i>
                        بيانات العميل
                    </h2>
                    <div class="text-sm space-y-3">
                        <div class="flex items-center">
                            <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center ml-3 text-gray-500">
                                <i class="fas fa-user"></i>
                            </div>
                            <div>
                                <p class="font-bold text-gray-800"><?php echo htmlspecialchars($customer['name']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($customer['customer_code']); ?></p>
                            </div>
                        </div>
                        <?php if (!empty($customer['mobile_number'])): ?>
                        <div class="flex items-center text-gray-600">
                            <i class="fas fa-phone ml-3 w-4 text-center"></i>
                            <span class="dir-ltr"><?php echo htmlspecialchars($customer['mobile_number']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($customer['city_name'])): ?>
                        <div class="flex items-center text-gray-600">
                            <i class="fas fa-map-marker-alt ml-3 w-4 text-center"></i>
                            <span><?php echo htmlspecialchars($customer['city_name']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</body>
</html>
