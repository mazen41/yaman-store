<?php
/**
 * Sales by Customer Report
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

$page_title = 'المبيعات حسب العميل';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$customer_type = $_GET['customer_type'] ?? '';
$min_amount = $_GET['min_amount'] ?? '';
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

// Fetch sales by customer data
$sql = "
    SELECT 
        c.id,
        c.name,
        c.customer_type,
        c.phone,
        c.email,
        COUNT(co.id) as order_count,
        SUM(co.total_amount) as total_amount,
        AVG(co.total_amount) as avg_order_value,
        MAX(co.order_date) as last_order_date
    FROM customers c
    LEFT JOIN customer_orders co ON c.id = co.customer_id AND co.order_date BETWEEN ? AND ?
    WHERE c.is_active = 1
";
$params = [$start_date, $end_date];

if ($customer_type) {
    $sql .= " AND c.customer_type = ?";
    $params[] = $customer_type;
}

$sql .= " GROUP BY c.id, c.name, c.customer_type, c.phone, c.email";

if ($min_amount) {
    $sql .= " HAVING SUM(co.total_amount) >= ?";
    $params[] = $min_amount;
}

$sql .= " ORDER BY $sort_by $sort_dir";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers_data = $stmt->fetchAll();

// Calculate totals
$total_customers = count($customers_data);
$total_orders = 0;
$total_sales = 0;
$total_avg_order = 0;

foreach ($customers_data as $customer) {
    $total_orders += $customer['order_count'];
    $total_sales += $customer['total_amount'];
}

if ($total_customers > 0) {
    $total_avg_order = $total_sales / $total_customers;
}

// Get top 5 customers for chart
$top_customers = array_slice($customers_data, 0, 5);

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
                        <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">نوع العميل</label>
                        <select name="customer_type" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 bg-white">
                            <option value="">جميع الأنواع</option>
                            <option value="individual" <?php echo $customer_type == 'individual' ? 'selected' : ''; ?>>فرد</option>
                            <option value="company" <?php echo $customer_type == 'company' ? 'selected' : ''; ?>>شركة</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">الحد الأدنى للمبيعات</label>
                        <input type="number" name="min_amount" value="<?php echo $min_amount; ?>" placeholder="أي مبلغ"
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ترتيب حسب</label>
                        <select name="sort_by" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 bg-white">
                            <option value="total_amount" <?php echo $sort_by == 'total_amount' ? 'selected' : ''; ?>>إجمالي المبيعات</option>
                            <option value="order_count" <?php echo $sort_by == 'order_count' ? 'selected' : ''; ?>>عدد الطلبات</option>
                            <option value="avg_order_value" <?php echo $sort_by == 'avg_order_value' ? 'selected' : ''; ?>>متوسط قيمة الطلب</option>
                            <option value="last_order_date" <?php echo $sort_by == 'last_order_date' ? 'selected' : ''; ?>>تاريخ آخر طلب</option>
                            <option value="name" <?php echo $sort_by == 'name' ? 'selected' : ''; ?>>اسم العميل</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">اتجاه الترتيب</label>
                        <select name="sort_dir" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 bg-white">
                            <option value="desc" <?php echo $sort_dir == 'desc' ? 'selected' : ''; ?>>تنازلي</option>
                            <option value="asc" <?php echo $sort_dir == 'asc' ? 'selected' : ''; ?>>تصاعدي</option>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                        <i class="fas fa-filter ml-2"></i>فلترة
                    </button>
                    <a href="sales-by-customer.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
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
                        <i class="fas fa-users text-2xl text-blue-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي العملاء</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_customers, 0, '', ''); ?></p>
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
                        <p class="text-sm font-medium text-gray-500">إجمالي المبيعات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_sales, 0, '', ''); ?> ر.ي</p>
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

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Top Customers Chart -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">أفضل 5 عملاء من حيث المبيعات</h3>
                </div>
                <div class="p-6">
                    <canvas id="topCustomersChart" height="300"></canvas>
                </div>
            </div>

            <!-- Customer Type Distribution -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">توزيع المبيعات حسب نوع العميل</h3>
                </div>
                <div class="p-6">
                    <canvas id="customerTypeChart" height="300"></canvas>
                </div>
            </div>
        </div>

        <!-- Customers Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">تفاصيل المبيعات حسب العميل</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">العميل</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">النوع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">معلومات الاتصال</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">عدد الطلبات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">إجمالي المبيعات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">متوسط قيمة الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">آخر طلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">النسبة من الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($customers_data)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-users text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد بيانات مبيعات للعملاء في الفترة المحددة</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($customers_data as $customer): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $customer['customer_type'] == 'company' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800'; ?>">
                                    <?php echo $customer['customer_type'] == 'company' ? 'شركة' : 'فرد'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div>
                                    <?php if ($customer['phone']): ?>
                                    <div><i class="fas fa-phone ml-1 text-gray-400"></i> <?php echo htmlspecialchars($customer['phone']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($customer['email']): ?>
                                    <div><i class="fas fa-envelope ml-1 text-gray-400"></i> <?php echo htmlspecialchars($customer['email']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($customer['order_count'], 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                <?php echo number_format($customer['total_amount'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($customer['avg_order_value'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo $customer['last_order_date'] ? date('d/m/Y', strtotime($customer['last_order_date'])) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php 
                                $percentage = $total_sales > 0 ? 
                                    ($customer['total_amount'] / $total_sales) * 100 : 0;
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
                            <td class="px-6 py-4 text-sm text-gray-900">المجموع (<?php echo $total_customers; ?> عميل)</td>
                            <td class="px-6 py-4"></td>
                            <td class="px-6 py-4"></td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_orders, 0, '', ''); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_sales, 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_avg_order, 0, '', ''); ?> ر.ي</td>
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
// Calculate customer type distribution
<?php
$individual_count = 0;
$individual_amount = 0;
$company_count = 0;
$company_amount = 0;

foreach ($customers_data as $customer) {
    if ($customer['customer_type'] == 'individual') {
        $individual_count++;
        $individual_amount += $customer['total_amount'];
    } else {
        $company_count++;
        $company_amount += $customer['total_amount'];
    }
}
?>

// Top Customers Chart
const topCustomersCtx = document.getElementById('topCustomersChart').getContext('2d');
const topCustomersChart = new Chart(topCustomersCtx, {
    type: 'bar',
    data: {
        labels: [
            <?php foreach ($top_customers as $customer): ?>
            '<?php echo addslashes($customer['name']); ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            label: 'إجمالي المبيعات (ر.ي)',
            data: [
                <?php foreach ($top_customers as $customer): ?>
                <?php echo $customer['total_amount']; ?>,
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
                    text: 'المبيعات (ر.ي)'
                }
            }
        }
    }
});

// Customer Type Distribution Chart
const customerTypeCtx = document.getElementById('customerTypeChart').getContext('2d');
const customerTypeChart = new Chart(customerTypeCtx, {
    type: 'pie',
    data: {
        labels: ['أفراد', 'شركات'],
        datasets: [{
            label: 'توزيع المبيعات',
            data: [
                <?php echo $individual_amount; ?>,
                <?php echo $company_amount; ?>
            ],
            backgroundColor: [
                'rgba(16, 185, 129, 0.7)',
                'rgba(59, 130, 246, 0.7)'
            ],
            borderColor: [
                'rgb(199, 164, 109)',
                'rgb(59, 130, 246)'
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
                            label += new Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR' }).format(context.parsed);
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
