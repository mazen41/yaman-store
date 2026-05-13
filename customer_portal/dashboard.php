<?php
session_start();

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/status_helpers.php';

$customer_id = $_SESSION['customer_id'];

// Get customer data
$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];

// Total orders
$stmt = $db->prepare("SELECT COUNT(*) as total FROM customer_orders WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$stats['total_orders'] = $stmt->fetchColumn();

// Total amount
$stmt = $db->prepare("SELECT SUM(final_amount) as total FROM customer_orders WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$stats['total_amount'] = $stmt->fetchColumn() ?? 0;

// Pending orders
$stmt = $db->prepare("SELECT COUNT(*) as total FROM customer_orders WHERE customer_id = ? AND status IN ('new', 'processing')");
$stmt->execute([$customer_id]);
$stats['pending_orders'] = $stmt->fetchColumn();

// Total invoices
$stmt = $db->prepare("SELECT COUNT(*) as total FROM customer_invoices WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$stats['total_invoices'] = $stmt->fetchColumn();

// Recent orders
$stmt = $db->prepare("
    SELECT o.*, 
           (SELECT GROUP_CONCAT(invoice_number SEPARATOR ', ') 
            FROM customer_invoices 
            WHERE order_id = o.id) as invoice_numbers
    FROM customer_orders o
    WHERE o.customer_id = ?
    ORDER BY o.created_at DESC
    LIMIT 5
");
$stmt->execute([$customer_id]);
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent invoices
$stmt = $db->prepare("
    SELECT * FROM customer_invoices 
    WHERE customer_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$customer_id]);
$recent_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent payments
try {
    $stmt = $db->prepare("
        SELECT * FROM customer_payments 
        WHERE customer_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$customer_id]);
    $recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_payments = [];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - <?php echo htmlspecialchars((string)($customer['name'] ?? '')); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <nav class="bg-gradient-to-r from-blue-600 to-purple-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <i class="fas fa-user-circle text-3xl ml-3"></i>
                    <div>
                        <h1 class="text-xl font-bold"><?php echo htmlspecialchars((string)($customer['name'] ?? '')); ?></h1>
                        <p class="text-sm text-blue-100"><?php echo htmlspecialchars((string)($customer['customer_code'] ?? '')); ?></p>
                    </div>
                </div>
                <a href="logout.php" class="bg-white text-blue-600 px-4 py-2 rounded-lg hover:bg-blue-50 transition">
                    <i class="fas fa-sign-out-alt ml-2"></i>
                    تسجيل الخروج
                </a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">إجمالي الطلبات</p>
                        <p class="text-3xl font-bold text-gray-800"><?php echo $stats['total_orders']; ?></p>
                    </div>
                    <div class="bg-blue-100 p-4 rounded-full">
                        <i class="fas fa-shopping-cart text-blue-600 text-2xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">إجمالي المبلغ</p>
                        <p class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['total_amount'], 0, '', ''); ?></p>
                        <p class="text-xs text-gray-500">ريال</p>
                    </div>
                    <div class="bg-green-100 p-4 rounded-full">
                        <i class="fas fa-money-bill-wave text-green-600 text-2xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-yellow-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">طلبات قيد المعالجة</p>
                        <p class="text-3xl font-bold text-gray-800"><?php echo $stats['pending_orders']; ?></p>
                    </div>
                    <div class="bg-yellow-100 p-4 rounded-full">
                        <i class="fas fa-clock text-yellow-600 text-2xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">إجمالي الفواتير</p>
                        <p class="text-3xl font-bold text-gray-800"><?php echo $stats['total_invoices']; ?></p>
                    </div>
                    <div class="bg-purple-100 p-4 rounded-full">
                        <i class="fas fa-file-invoice text-purple-600 text-2xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="bg-white rounded-xl shadow-lg mb-8">
            <div class="border-b border-gray-200">
                <nav class="flex -mb-px">
                    <button onclick="showTab('orders')" id="tab-orders" class="tab-button active py-4 px-6 border-b-2 font-medium text-sm">
                        <i class="fas fa-shopping-cart ml-2"></i>
                        الطلبات
                    </button>
                    <button onclick="showTab('invoices')" id="tab-invoices" class="tab-button py-4 px-6 border-b-2 font-medium text-sm">
                        <i class="fas fa-file-invoice ml-2"></i>
                        الفواتير
                    </button>
                    <button onclick="showTab('payments')" id="tab-payments" class="tab-button py-4 px-6 border-b-2 font-medium text-sm">
                        <i class="fas fa-credit-card ml-2"></i>
                        المدفوعات
                    </button>
                    <button onclick="showTab('profile')" id="tab-profile" class="tab-button py-4 px-6 border-b-2 font-medium text-sm">
                        <i class="fas fa-user ml-2"></i>
                        الملف الشخصي
                    </button>
                </nav>
            </div>

            <!-- Orders Tab -->
            <div id="content-orders" class="tab-content p-6">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">طلباتي</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">رقم الطلب</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">التاريخ</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المبلغ</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحالة</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">رقم الفاتورة</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">العمليات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_orders as $order): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars(formatOrderNumber($order['order_number'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('Y-m-d', strtotime($order['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo number_format($order['final_amount'], 0, '', ''); ?> ريال
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $status_colors = [
                                        'new' => 'bg-blue-100 text-blue-800',
                                        'processing' => 'bg-yellow-100 text-yellow-800',
                                        'completed' => 'bg-green-100 text-green-800',
                                        'cancelled' => 'bg-red-100 text-red-800'
                                    ];
                                    $status_text = [
                                        'new' => 'جديد',
                                        'processing' => 'قيد المعالجة',
                                        'completed' => 'مكتمل',
                                        'cancelled' => 'ملغي'
                                    ];
                                    $color = $status_colors[$order['status']] ?? 'bg-gray-100 text-gray-800';
                                    $text = $status_text[$order['status']] ?? $order['status'];
                                    ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $color; ?>">
                                        <?php echo $text; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo $order['invoice_numbers'] ? htmlspecialchars((string)$order['invoice_numbers']) : '-'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="order_details.php?id=<?php echo $order['id']; ?>" class="text-blue-600 hover:text-blue-900">
                                        <i class="fas fa-eye ml-1"></i>
                                        عرض التفاصيل
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Invoices Tab -->
            <div id="content-invoices" class="tab-content p-6 hidden">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">فواتيري</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">رقم الفاتورة</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">التاريخ</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المبلغ</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحالة</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_invoices as $invoice): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars((string)($invoice['invoice_number'] ?? '')); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('Y-m-d', strtotime($invoice['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo number_format($invoice['total_amount'], 0, '', ''); ?> ريال
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $status_colors = [
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'paid' => 'bg-green-100 text-green-800',
                                        'cancelled' => 'bg-red-100 text-red-800'
                                    ];
                                    $status_text = [
                                        'pending' => 'معلقة',
                                        'paid' => 'مدفوعة',
                                        'cancelled' => 'ملغاة'
                                    ];
                                    $color = $status_colors[$invoice['status']] ?? 'bg-gray-100 text-gray-800';
                                    $text = $status_text[$invoice['status']] ?? $invoice['status'];
                                    ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $color; ?>">
                                        <?php echo $text; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Payments Tab -->
            <div id="content-payments" class="tab-content p-6 hidden">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">مدفوعاتي</h2>
                <?php if (empty($recent_payments)): ?>
                <p class="text-gray-500 text-center py-8">لا توجد مدفوعات بعد</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">رقم الدفعة</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">التاريخ</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المبلغ</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">طريقة الدفع</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_payments as $payment): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars((string)($payment['payment_number'] ?? '')); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo number_format($payment['amount'], 0, '', ''); ?> ريال
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars((string)($payment['payment_method'] ?? '')); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Profile Tab -->
            <div id="content-profile" class="tab-content p-6 hidden">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">الملف الشخصي</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-500">رقم العميل</p>
                        <p class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars((string)($customer['customer_code'] ?? '')); ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-500">الاسم</p>
                        <p class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars((string)($customer['name'] ?? '')); ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-500">البريد الإلكتروني</p>
                        <p class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars((string)($customer['email'] ?? '')); ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-500">رقم الجوال</p>
                        <p class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars((string)($customer['mobile_number'] ?? '')); ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-500">رقم الواتساب</p>
                        <p class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars((string)($customer['whatsapp_number'] ?? '')); ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-500">المدينة</p>
                        <p class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars((string)($customer['city_name'] ?? '')); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active', 'border-blue-500', 'text-blue-600');
                btn.classList.add('border-transparent', 'text-gray-500');
            });
            
            // Show selected tab
            document.getElementById('content-' + tabName).classList.remove('hidden');
            
            // Add active class to selected button
            const activeBtn = document.getElementById('tab-' + tabName);
            activeBtn.classList.add('active', 'border-blue-500', 'text-blue-600');
            activeBtn.classList.remove('border-transparent', 'text-gray-500');
        }
        
        // Initialize first tab
        document.getElementById('tab-orders').classList.add('border-blue-500', 'text-blue-600');
        document.getElementById('tab-orders').classList.remove('border-transparent', 'text-gray-500');
    </script>
</body>
</html>
