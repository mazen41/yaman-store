<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'عرض بيانات العميل';
$error_message = '';

// Check if customer ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$customer_id = intval($_GET['id']);
$active_tab = $_GET['tab'] ?? 'details';

// Fetch customer data
try {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND is_active = 1");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        header('Location: index.php');
        exit();
    }
    
    // Fetch customer orders
    $orders_stmt = $db->prepare("
        SELECT co.*, 
               COUNT(oi.id) as items_count,
               SUM(oi.quantity) as total_quantity
        FROM customer_orders co 
        LEFT JOIN order_items oi ON co.id = oi.order_id 
        WHERE co.customer_id = ? 
        GROUP BY co.id 
        ORDER BY co.created_at DESC 
        LIMIT 10
    ");
    $orders_stmt->execute([$customer_id]);
    $orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count total orders: all non-cancelled orders for this customer
    $orders_count_stmt = $db->prepare("SELECT COUNT(*) FROM customer_orders WHERE customer_id = ? AND status != 'cancelled'");
    $orders_count_stmt->execute([$customer_id]);
    $total_orders = $orders_count_stmt->fetchColumn();
    
    // Calculate total spent (exclude cancelled)
    $spent_stmt = $db->prepare("SELECT SUM(final_amount) FROM customer_orders WHERE customer_id = ? AND status != 'cancelled'");
    $spent_stmt->execute([$customer_id]);
    $total_spent = $spent_stmt->fetchColumn() ?? 0;
    
    // **MODIFIED**: Fetch customer invoices and calculate the total paid amount for each
    $invoices_stmt = $db->prepare("
        SELECT 
            ci.*, 
            co.order_number,
            (SELECT SUM(cp.amount) FROM customer_payments cp WHERE cp.invoice_id = ci.id) as total_paid
        FROM customer_invoices ci
        LEFT JOIN customer_orders co ON ci.order_id = co.id
        WHERE ci.customer_id = ?
        ORDER BY ci.created_at DESC
        LIMIT 10
    ");
    $invoices_stmt->execute([$customer_id]);
    $invoices = $invoices_stmt->fetchAll(PDO::FETCH_ASSOC);
    $visible_invoices_count = count($invoices);
    
    // Count total invoices (exclude cancelled)
    $invoices_count_stmt = $db->prepare("SELECT COUNT(*) FROM customer_invoices WHERE customer_id = ? AND status != 'cancelled'");
    $invoices_count_stmt->execute([$customer_id]);
    $total_invoices = $invoices_count_stmt->fetchColumn();
    
    // Fetch customer payments
    $payments_stmt = $db->prepare("
        SELECT cp.*, ci.invoice_number 
        FROM customer_payments cp
        LEFT JOIN customer_invoices ci ON cp.invoice_id = ci.id
        WHERE cp.customer_id = ?
        ORDER BY cp.created_at DESC
        LIMIT 10
    ");
    $payments_stmt->execute([$customer_id]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count total payments
    $payments_count_stmt = $db->prepare("SELECT COUNT(*) FROM customer_payments WHERE customer_id = ?");
    $payments_count_stmt->execute([$customer_id]);
    $total_payments = $payments_count_stmt->fetchColumn();
    
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات العميل';
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">بيانات العميل</h1>
                        <p class="text-gray-600 mt-1"><?php echo htmlspecialchars($customer['name']); ?></p>
                    </div>
                    <div class="flex space-x-2 space-x-reverse">
                        <a href="edit.php?id=<?php echo $customer_id; ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200"><i class="fas fa-edit ml-2"></i>تعديل</a>
                        <a href="../orders/sync_customer_invoices.php?customer_id=<?php echo $customer_id; ?>&redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200"><i class="fas fa-sync ml-2"></i>مزامنة الفواتير</a>
                        <a href="../../customer_portal/login.php" target="_blank" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition duration-200"><i class="fas fa-external-link-alt ml-2"></i>بوابة العميل</a>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200"><i class="fas fa-arrow-right ml-2"></i>العودة للقائمة</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white p-5 shadow rounded-lg flex items-center"><i class="fas fa-shopping-cart text-2xl text-amber-600"></i><div class="mr-3"><dt class="text-sm font-medium text-gray-500 truncate">إجمالي الطلبات</dt><dd class="text-lg font-medium text-gray-900"><?php echo $total_orders; ?></dd></div></div>
            <div class="bg-white p-5 shadow rounded-lg flex items-center"><i class="fas fa-coins text-2xl text-blue-600"></i><div class="mr-3"><dt class="text-sm font-medium text-gray-500 truncate">إجمالي المبلغ</dt><dd class="text-lg font-medium text-gray-900"><?php echo number_format($total_spent, 0, '.', ''); ?></dd></div></div>
            <div class="bg-white p-5 shadow rounded-lg flex items-center"><i class="fas fa-file-invoice text-2xl text-orange-600"></i><div class="mr-3"><dt class="text-sm font-medium text-gray-500 truncate">الفواتير</dt><dd class="text-lg font-medium text-gray-900"><?php echo $visible_invoices_count; ?></dd></div></div>
            <div class="bg-white p-5 shadow rounded-lg flex items-center"><i class="fas fa-credit-card text-2xl text-purple-600"></i><div class="mr-3"><dt class="text-sm font-medium text-gray-500 truncate">المدفوعات</dt><dd class="text-lg font-medium text-gray-900"><?php echo $total_payments ?? 0; ?></dd></div></div>
        </div>

        <!-- Tabs -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <nav class="flex space-x-8 space-x-reverse" aria-label="Tabs">
                    <a href="?id=<?php echo $customer_id; ?>&tab=details" class="<?php echo $active_tab == 'details' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm">تفاصيل العميل</a>
                    <a href="?id=<?php echo $customer_id; ?>&tab=orders" class="<?php echo $active_tab == 'orders' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm">طلبات العميل (<?php echo $total_orders; ?>)</a>
                    <a href="?id=<?php echo $customer_id; ?>&tab=invoices" class="<?php echo $active_tab == 'invoices' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm">فواتير العميل (<?php echo $visible_invoices_count; ?>)</a>
                    <a href="?id=<?php echo $customer_id; ?>&tab=payments" class="<?php echo $active_tab == 'payments' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm">مدفوعات العميل (<?php echo $total_payments ?? 0; ?>)</a>
                </nav>
            </div>
            
            <div class="p-6">
                <?php if ($active_tab == 'details'): ?>
                    <!-- Customer Details Tab -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <div class="flex items-center mb-4">
                                <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center text-blue-600"><i class="fas <?php echo $customer['customer_type'] == 'company' ? 'fa-building' : 'fa-user'; ?> text-2xl"></i></div>
                                <div class="mr-4">
                                    <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($customer['name']); ?></h3>
                                    <p class="text-sm text-gray-600"><span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800"><?php echo htmlspecialchars($customer['customer_type']); ?></span></p>
                                </div>
                            </div>
                            <div class="mt-4 space-y-4">
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <h4 class="text-sm font-medium text-gray-500 mb-3">المعلومات الأساسية</h4>
                                    <div class="space-y-2">
                                        <div class="flex justify-between"><span class="text-sm text-gray-600">رقم العميل:</span><span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($customer['customer_code']); ?></span></div>
                                        <div class="flex justify-between"><span class="text-sm text-gray-600">الرصيد الحالي:</span><span class="text-sm font-medium <?php echo ($customer['current_balance'] ?? 0) >= 0 ? 'text-amber-600' : 'text-red-600'; ?>"><?php echo number_format($customer['current_balance'] ?? 0, 0, '.', ''); ?> ريال</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <div class="bg-gray-50 p-4 rounded-lg mb-4">
                                <h4 class="text-sm font-medium text-gray-500 mb-3">معلومات الاتصال</h4>
                                <div class="space-y-3">
                                    <div class="flex items-center"><i class="fas fa-phone text-gray-400 w-5"></i><span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></span></div>
                                    <div class="flex items-center"><i class="fas fa-mobile-alt text-gray-400 w-5"></i><span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['mobile_number'] ?? '-'); ?></span></div>
                                    <div class="flex items-center"><i class="fab fa-whatsapp text-amber-400 w-5"></i><span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['whatsapp_number'] ?? '-'); ?></span></div>
                                    <div class="flex items-center"><i class="fas fa-envelope text-gray-400 w-5"></i><span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['email'] ?? '-'); ?></span></div>
                                </div>
                            </div>
                            <?php if (!empty($customer['address'])): ?>
                            <div class="bg-gray-50 p-4 rounded-lg"><h4 class="text-sm font-medium text-gray-500 mb-3">العنوان</h4><p class="text-sm text-gray-900"><?php echo nl2br(htmlspecialchars($customer['address'])); ?></p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($active_tab == 'orders'): ?>
                    <!-- Orders Tab -->
                    <div class="space-y-4">
                        <?php if (empty($orders)): ?>
                            <div class="text-center py-12"><i class="fas fa-shopping-cart text-4xl text-gray-300 mb-4"></i><p class="text-gray-500">لا توجد طلبات لهذا العميل</p></div>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                            <div class="bg-gray-50 rounded-lg p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h4 class="text-lg font-semibold text-gray-900">طلب #<?php echo htmlspecialchars($order['order_number']); ?></h4>
                                        <p class="text-sm text-gray-600"><?php echo date('d/m/Y', strtotime($order['created_at'])); ?></p>
                                        <div class="mt-2 flex space-x-4 space-x-reverse"><span class="text-sm text-gray-600">عدد الأصناف: <strong><?php echo $order['items_count']; ?></strong></span><span class="text-sm text-gray-600">إجمالي الكمية: <strong><?php echo $order['total_quantity']; ?></strong></span></div>
                                    </div>
                                    <div class="text-left">
                                        <span class="text-lg font-bold text-gray-900"><?php echo number_format($order['final_amount'], 0, '.', ''); ?> ريال</span>
                                        <div class="mt-2"><span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800"><?php echo htmlspecialchars($order['status']); ?></span></div>
                                        <div class="mt-2"><a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm"><i class="fas fa-eye ml-1"></i>عرض التفاصيل</a></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                <?php elseif ($active_tab == 'invoices'): ?>
                    <!-- **MODIFIED**: Invoices Tab -->
                    <div class="space-y-4">
                        <?php if (empty($invoices)): ?>
                            <div class="text-center py-12"><i class="fas fa-file-invoice text-4xl text-gray-300 mb-4"></i><p class="text-gray-500">لا توجد فواتير لهذا العميل</p></div>
                        <?php else: ?>
                            <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">رقم الفاتورة</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">مبلغ الفاتورة</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">المبلغ المدفوع</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">المبلغ المتبقي</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">الحالة</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">تاريخ الإصدار</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">العمليات</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($invoices as $invoice): ?>
                                        <?php
                                            $invoice_amount = $invoice['total_amount'];
                                            $paid_amount = $invoice['total_paid'] ?? 0;
                                            $remaining_amount = $invoice_amount - $paid_amount;
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo number_format($invoice_amount, 0, '', ''); ?> ريال</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-amber-600"><?php echo number_format($paid_amount, 0, '', ''); ?> ريال</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?php echo $remaining_amount > 0 ? 'text-red-600' : 'text-gray-500'; ?>"><?php echo number_format($remaining_amount, 0, '', ''); ?> ريال</td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php 
                                                    if ($remaining_amount <= 0 && $invoice_amount > 0) echo 'bg-amber-100 text-amber-800';
                                                    elseif ($paid_amount > 0) echo 'bg-yellow-100 text-yellow-800';
                                                    else echo 'bg-blue-100 text-blue-800';
                                                ?>">
                                                    <?php 
                                                        if ($remaining_amount <= 0 && $invoice_amount > 0) echo 'مدفوعة بالكامل';
                                                        elseif ($paid_amount > 0) echo 'مدفوعة جزئياً';
                                                        else echo 'قيد الانتظار';
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('d/m/Y', strtotime($invoice['created_at'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex space-x-2 space-x-reverse">
                                                    <a href="../invoices/view.php?id=<?php echo $invoice['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض"><i class="fas fa-eye"></i></a>
                                                    <?php if ($remaining_amount > 0): ?>
                                                    <a href="../payments/add.php?invoice_id=<?php echo $invoice['id']; ?>" class="text-purple-600 hover:text-purple-900" title="تسجيل دفعة"><i class="fas fa-money-bill-wave"></i></a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($active_tab == 'payments'): ?>
                    <!-- Payments Tab -->
                    <div class="space-y-4">
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-12"><i class="fas fa-credit-card text-4xl text-gray-300 mb-4"></i><p class="text-gray-500">لا توجد مدفوعات لهذا العميل</p></div>
                        <?php else: ?>
                            <div class="flex justify-between items-center mb-4"><h3 class="text-lg font-semibold">مدفوعات العميل</h3><a href="../payments/add.php?customer_id=<?php echo $customer_id; ?>" class="text-sm text-blue-600 hover:text-blue-800"><i class="fas fa-plus-circle ml-1"></i>إضافة دفعة جديدة</a></div>
                            <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">رقم الدفعة</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">رقم الفاتورة</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">المبلغ</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">طريقة الدفع</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">تاريخ الدفع</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">العمليات</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($payments as $payment): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['payment_number']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($payment['invoice_number'] ?? '-'); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo number_format($payment['amount'], 0, '', ''); ?> ريال</td>
                                            <td class="px-6 py-4 whitespace-nowrap"><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800"><?php echo htmlspecialchars($payment['payment_method']); ?></span></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium"><div class="flex space-x-2 space-x-reverse"><a href="../payments/view.php?id=<?php echo $payment['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض"><i class="fas fa-eye"></i></a></div></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>