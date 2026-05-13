<?php
/**
 * Purchases by Supplier Report
 * Advanced reporting system for Yassin Admin
 * 
 * @author Senior PHP Engineer
 * @version 1.0
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'المشتريات حسب المورد';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$min_amount = $_GET['min_amount'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'total_amount';
$sort_dir = $_GET['sort_dir'] ?? 'desc';

// Validate sort parameters
$valid_sort_fields = ['name', 'order_count', 'total_amount', 'avg_order_value', 'last_order_date'];
if (!in_array($sort_by, $valid_sort_fields)) {
    $sort_by = 'total_amount';
}

$valid_sort_dirs = ['asc', 'desc'];
if (!in_array($sort_dir, $valid_sort_dirs)) {
    $sort_dir = 'desc';
}

// Fetch purchases by supplier data
$sql = "
    SELECT 
        s.id,
        s.name,
        s.contact_person,
        s.phone,
        s.email,
        COUNT(po.id) as order_count,
        SUM(po.total_amount) as total_amount,
        AVG(po.total_amount) as avg_order_value,
        MAX(po.order_date) as last_order_date,
        COUNT(CASE WHEN po.status = 'received' THEN 1 END) as received_count,
        COUNT(CASE WHEN po.status = 'ordered' THEN 1 END) as ordered_count,
        COUNT(CASE WHEN po.status = 'pending' THEN 1 END) as pending_count
    FROM suppliers s
    LEFT JOIN purchase_orders po ON s.id = po.supplier_id AND po.order_date BETWEEN ? AND ?
    WHERE s.is_active = 1
";
$params = [$start_date, $end_date];

if ($status_filter) {
    $sql .= " AND (po.status = ? OR po.id IS NULL)";
    $params[] = $status_filter;
}

$sql .= " GROUP BY s.id, s.name, s.contact_person, s.phone, s.email";

if ($min_amount) {
    $sql .= " HAVING SUM(po.total_amount) >= ?";
    $params[] = $min_amount;
}

$sql .= " ORDER BY $sort_by $sort_dir";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$suppliers_data = $stmt->fetchAll();

// Calculate totals
$total_suppliers = count($suppliers_data);
$total_orders = 0;
$total_purchases = 0;
$total_avg_order = 0;
$total_received = 0;
$total_ordered = 0;
$total_pending = 0;

foreach ($suppliers_data as $supplier) {
    $total_orders += $supplier['order_count'];
    $total_purchases += $supplier['total_amount'];
    $total_received += $supplier['received_count'];
    $total_ordered += $supplier['ordered_count'];
    $total_pending += $supplier['pending_count'];
}

if ($total_suppliers > 0) {
    $total_avg_order = $total_purchases / $total_suppliers;
}

// Get top 5 suppliers for chart
$top_suppliers = array_slice($suppliers_data, 0, 5);

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
                        <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
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
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">إلى تاريخ</label>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>" 
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                        <select name="status" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 bg-white">
                            <option value="">جميع الحالات</option>
                            <option value="received" <?php echo $status_filter == 'received' ? 'selected' : ''; ?>>تم الاستلام</option>
                            <option value="ordered" <?php echo $status_filter == 'ordered' ? 'selected' : ''; ?>>تم الطلب</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>معلق</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">الحد الأدنى للمشتريات</label>
                        <input type="number" name="min_amount" value="<?php echo $min_amount; ?>" placeholder="أي مبلغ"
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ترتيب حسب</label>
                        <select name="sort_by" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 bg-white">
                            <option value="total_amount" <?php echo $sort_by == 'total_amount' ? 'selected' : ''; ?>>إجمالي المشتريات</option>
                            <option value="order_count" <?php echo $sort_by == 'order_count' ? 'selected' : ''; ?>>عدد الطلبات</option>
                            <option value="avg_order_value" <?php echo $sort_by == 'avg_order_value' ? 'selected' : ''; ?>>متوسط قيمة الطلب</option>
                            <option value="last_order_date" <?php echo $sort_by == 'last_order_date' ? 'selected' : ''; ?>>تاريخ آخر طلب</option>
                            <option value="name" <?php echo $sort_by == 'name' ? 'selected' : ''; ?>>اسم المورد</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">اتجاه الترتيب</label>
                        <select name="sort_dir" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 bg-white">
                            <option value="desc" <?php echo $sort_dir == 'desc' ? 'selected' : ''; ?>>تنازلي</option>
                            <option value="asc" <?php echo $sort_dir == 'asc' ? 'selected' : ''; ?>>تصاعدي</option>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-filter ml-2"></i>فلترة
                    </button>
                    <a href="purchases-by-supplier.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                        <i class="fas fa-redo ml-2"></i>إعادة تعيين
                    </a>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-truck text-2xl text-blue-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي الموردين</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_suppliers, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-shopping-cart text-2xl text-purple-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي الطلبات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_orders, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-money-bill-wave text-2xl text-amber-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي المشتريات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_purchases, 0, '', ''); ?> ر.ي</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-chart-line text-2xl text-orange-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">متوسط قيمة الطلب</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_avg_order, 0, '', ''); ?> ر.ي</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white p-6 rounded-lg shadow border-r-4 border-amber-500">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-2xl text-amber-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">تم الاستلام</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_received, 0, '', ''); ?></p>
                    </div>
                </div>
                <div class="mt-2">
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <?php $received_percentage = $total_orders > 0 ? ($total_received / $total_orders) * 100 : 0; ?>
                        <div class="bg-amber-600 h-2.5 rounded-full" style="width: <?php echo $received_percentage; ?>%"></div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?php echo number_format($received_percentage, 0, '', ''); ?>% من إجمالي الطلبات</p>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow border-r-4 border-blue-500">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-truck text-2xl text-blue-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">تم الطلب</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_ordered, 0, '', ''); ?></p>
                    </div>
                </div>
                <div class="mt-2">
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <?php $ordered_percentage = $total_orders > 0 ? ($total_ordered / $total_orders) * 100 : 0; ?>
                        <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo $ordered_percentage; ?>%"></div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?php echo number_format($ordered_percentage, 0, '', ''); ?>% من إجمالي الطلبات</p>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow border-r-4 border-yellow-500">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-clock text-2xl text-yellow-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">معلق</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_pending, 0, '', ''); ?></p>
                    </div>
                </div>
                <div class="mt-2">
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <?php $pending_percentage = $total_orders > 0 ? ($total_pending / $total_orders) * 100 : 0; ?>
                        <div class="bg-yellow-600 h-2.5 rounded-full" style="width: <?php echo $pending_percentage; ?>%"></div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?php echo number_format($pending_percentage, 0, '', ''); ?>% من إجمالي الطلبات</p>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Top Suppliers Chart -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">أفضل 5 موردين من حيث المشتريات</h3>
                </div>
                <div class="p-6">
                    <canvas id="topSuppliersChart" height="300"></canvas>
                </div>
            </div>

            <!-- Order Status Distribution -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">توزيع حالات الطلبات</h3>
                </div>
                <div class="p-6">
                    <canvas id="orderStatusChart" height="300"></canvas>
                </div>
            </div>
        </div>

        <!-- Suppliers Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">تفاصيل المشتريات حسب المورد</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المورد</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">معلومات الاتصال</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">عدد الطلبات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">إجمالي المشتريات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">متوسط قيمة الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">تم الاستلام</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">تم الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">معلق</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">آخر طلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">النسبة من الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($suppliers_data)): ?>
                        <tr>
                            <td colspan="10" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-truck text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد بيانات مشتريات للموردين في الفترة المحددة</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($suppliers_data as $supplier): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($supplier['name']); ?>
                                <?php if ($supplier['contact_person']): ?>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($supplier['contact_person']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div>
                                    <?php if ($supplier['phone']): ?>
                                    <div><i class="fas fa-phone ml-1 text-gray-400"></i> <?php echo htmlspecialchars($supplier['phone']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($supplier['email']): ?>
                                    <div><i class="fas fa-envelope ml-1 text-gray-400"></i> <?php echo htmlspecialchars($supplier['email']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($supplier['order_count'], 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                <?php echo number_format($supplier['total_amount'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($supplier['avg_order_value'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm text-amber-600">
                                <?php echo number_format($supplier['received_count'], 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-blue-600">
                                <?php echo number_format($supplier['ordered_count'], 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-yellow-600">
                                <?php echo number_format($supplier['pending_count'], 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo $supplier['last_order_date'] ? date('d/m/Y', strtotime($supplier['last_order_date'])) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php 
                                $percentage = $total_purchases > 0 ? 
                                    ($supplier['total_amount'] / $total_purchases) * 100 : 0;
                                ?>
                                <div class="flex items-center">
                                    <span class="text-sm font-medium text-gray-900"><?php echo number_format($percentage, 0, '', ''); ?>%</span>
                                    <div class="mr-2 w-24 bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Summary Row -->
                        <tr class="bg-gray-50 font-bold">
                            <td class="px-6 py-4 text-sm text-gray-900" colspan="2">المجموع (<?php echo $total_suppliers; ?> مورد)</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_orders, 0, '', ''); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_purchases, 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_avg_order, 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-amber-600"><?php echo number_format($total_received, 0, '', ''); ?></td>
                            <td class="px-6 py-4 text-sm text-blue-600"><?php echo number_format($total_ordered, 0, '', ''); ?></td>
                            <td class="px-6 py-4 text-sm text-yellow-600"><?php echo number_format($total_pending, 0, '', ''); ?></td>
                            <td class="px-6 py-4"></td>
                            <td class="px-6 py-4 text-sm text-gray-900">100%</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Top Suppliers Chart
const topSuppliersCtx = document.getElementById('topSuppliersChart').getContext('2d');
const topSuppliersChart = new Chart(topSuppliersCtx, {
    type: 'bar',
    data: {
        labels: [
            <?php foreach ($top_suppliers as $supplier): ?>
            '<?php echo addslashes($supplier['name']); ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            label: 'إجمالي المشتريات (ر.ي)',
            data: [
                <?php foreach ($top_suppliers as $supplier): ?>
                <?php echo $supplier['total_amount']; ?>,
                <?php endforeach; ?>
            ],
            backgroundColor: [
                'rgba(59, 130, 246, 0.7)',
                'rgba(16, 185, 129, 0.7)',
                'rgba(245, 158, 11, 0.7)',
                'rgba(239, 68, 68, 0.7)',
                'rgba(139, 92, 246, 0.7)'
            ],
            borderColor: [
                'rgb(59, 130, 246)',
                'rgb(199, 164, 109)',
                'rgb(245, 158, 11)',
                'rgb(239, 68, 68)',
                'rgb(139, 92, 246)'
            ],
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'المشتريات (ر.ي)'
                }
            }
        }
    }
});

// Order Status Distribution Chart
const orderStatusCtx = document.getElementById('orderStatusChart').getContext('2d');
const orderStatusChart = new Chart(orderStatusCtx, {
    type: 'pie',
    data: {
        labels: ['تم الاستلام', 'تم الطلب', 'معلق'],
        datasets: [{
            label: 'توزيع حالات الطلبات',
            data: [
                <?php echo $total_received; ?>,
                <?php echo $total_ordered; ?>,
                <?php echo $total_pending; ?>
            ],
            backgroundColor: [
                'rgba(16, 185, 129, 0.7)',
                'rgba(59, 130, 246, 0.7)',
                'rgba(245, 158, 11, 0.7)'
            ],
            borderColor: [
                'rgb(199, 164, 109)',
                'rgb(59, 130, 246)',
                'rgb(245, 158, 11)'
            ],
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed !== null) {
                            label += context.parsed + ' طلب';
                        }
                        return label;
                    }
                }
            }
        }
    }
});
</script>

<style media="print">
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .bg-gray-50 { background: white !important; }
    .shadow { box-shadow: none !important; }
</style>

<?php include '../../includes/footer.php'; ?>
