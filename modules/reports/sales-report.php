<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تقرير المبيعات اليومية';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$customer_filter = $_GET['customer_id'] ?? '';
$export_type = $_GET['export'] ?? '';

// Handle exports
if ($export_type) {
    if ($export_type == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="sales_report_' . date('Y-m-d') . '.xls"');
    } elseif ($export_type == 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="sales_report_' . date('Y-m-d') . '.pdf"');
    }
}

// Fetch sales data
$sql = "
    SELECT 
        co.id,
        co.order_number,
        co.order_date,
        c.name as customer_name,
        c.phone as customer_phone,
        co.subtotal,
        co.tax_amount,
        co.discount_amount,
        co.total_amount,
        co.status,
        co.payment_method,
        u.full_name as created_by
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN users u ON co.created_by = u.id
    WHERE co.order_date BETWEEN ? AND ?
";
$params = [$start_date, $end_date];

if ($customer_filter) {
    $sql .= " AND co.customer_id = ?";
    $params[] = $customer_filter;
}

$sql .= " ORDER BY co.order_date DESC, co.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$sales_data = $stmt->fetchAll();

// Calculate totals
$total_sales = 0;
$total_tax = 0;
$total_discount = 0;
$order_count = count($sales_data);

foreach ($sales_data as $sale) {
    $total_sales += $sale['total_amount'];
    $total_tax += $sale['tax_amount'];
    $total_discount += $sale['discount_amount'];
}

// Get customers for filter
$customers_stmt = $db->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name");
$customers = $customers_stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                        <p class="text-gray-600 mt-1">
                            من <?php echo date('d/m/Y', strtotime($start_date)); ?> 
                            إلى <?php echo date('d/m/Y', strtotime($end_date)); ?>
                        </p>
                    </div>
                    <div class="flex space-x-3 space-x-reverse">
                        <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            <i class="fas fa-print ml-2"></i>
                            طباعة
                        </button>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">من تاريخ</label>
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>" 
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">إلى تاريخ</label>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>" 
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">العميل</label>
                        <select name="customer_id" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 bg-white">
                            <option value="">جميع العملاء</option>
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>" <?php echo $customer_filter == $customer['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                        <i class="fas fa-filter ml-2"></i>فلترة
                    </button>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" 
                       class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                        <i class="fas fa-file-excel ml-2"></i>Excel
                    </a>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-shopping-cart text-2xl text-blue-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">عدد الطلبات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($order_count, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-money-bill-wave text-2xl text-amber-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي المبيعات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_sales, 0, '', ''); ?> ر.ي</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-receipt text-2xl text-purple-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي الضرائب</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_tax, 0, '', ''); ?> ر.ي</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-tags text-2xl text-orange-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي الخصومات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_discount, 0, '', ''); ?> ر.ي</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sales Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">تفاصيل المبيعات</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">رقم الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">التاريخ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">العميل</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المجموع الفرعي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الضريبة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الخصم</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الإجمالي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحالة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">طريقة الدفع</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($sales_data)): ?>
                        <tr>
                            <td colspan="10" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-chart-line text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد مبيعات في الفترة المحددة</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($sales_data as $index => $sale): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo $index + 1; ?></td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($sale['order_number']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo date('d/m/Y', strtotime($sale['order_date'])); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div>
                                    <div class="font-medium"><?php echo htmlspecialchars($sale['customer_name'] ?? 'عميل مجهول'); ?></div>
                                    <?php if ($sale['customer_phone']): ?>
                                    <div class="text-gray-500 text-xs"><?php echo htmlspecialchars($sale['customer_phone']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($sale['subtotal'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($sale['tax_amount'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm text-red-600">
                                -<?php echo number_format($sale['discount_amount'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                <?php echo number_format($sale['total_amount'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php
                                $status_colors = [
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    'completed' => 'bg-amber-100 text-amber-800',
                                    'cancelled' => 'bg-red-100 text-red-800',
                                    'processing' => 'bg-blue-100 text-blue-800'
                                ];
                                $status_labels = [
                                    'pending' => 'معلق',
                                    'completed' => 'مكتمل',
                                    'cancelled' => 'ملغي',
                                    'processing' => 'قيد المعالجة'
                                ];
                                ?>
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $status_colors[$sale['status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo $status_labels[$sale['status']] ?? $sale['status']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php 
                                $payment_methods = [
                                    'cash' => 'نقدي',
                                    'card' => 'بطاقة ائتمان',
                                    'bank_transfer' => 'تحويل بنكي',
                                    'check' => 'شيك'
                                ];
                                echo $payment_methods[$sale['payment_method']] ?? 'غير محدد';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Summary Row -->
                        <tr class="bg-gray-50 font-bold">
                            <td colspan="4" class="px-6 py-4 text-sm text-gray-900">المجموع الكلي:</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_sales - $total_tax + $total_discount, 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_tax, 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-red-600">-<?php echo number_format($total_discount, 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_sales, 0, '', ''); ?> ر.ي</td>
                            <td colspan="2" class="px-6 py-4 text-sm text-gray-900"><?php echo $order_count; ?> طلب</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style media="print">
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .bg-gray-50 { background: white !important; }
    .shadow { box-shadow: none !important; }
</style>

<?php include '../../includes/footer.php'; ?>
