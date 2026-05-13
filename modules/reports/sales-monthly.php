<?php
/**
 * Monthly Sales Report
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

$page_title = 'تقرير المبيعات الشهرية';

// Get filter parameters
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$customer_filter = $_GET['customer_id'] ?? '';

// Get available years for filter
$years_stmt = $db->query("
    SELECT DISTINCT YEAR(order_date) as year 
    FROM customer_orders 
    ORDER BY year DESC
");
$available_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// If no years in database, use current year
if (empty($available_years)) {
    $available_years = [date('Y')];
}

// If selected year is not in available years, use first available
if (!in_array($year, $available_years)) {
    $year = reset($available_years);
}

// Fetch monthly sales data
$sql = "
    SELECT 
        MONTH(order_date) as month,
        COUNT(*) as order_count,
        SUM(subtotal) as total_subtotal,
        SUM(tax_amount) as total_tax,
        SUM(discount_amount) as total_discount,
        SUM(total_amount) as total_amount
    FROM customer_orders
    WHERE YEAR(order_date) = ?
";
$params = [$year];

if ($customer_filter) {
    $sql .= " AND customer_id = ?";
    $params[] = $customer_filter;
}

$sql .= " GROUP BY MONTH(order_date) ORDER BY month";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$monthly_data = $stmt->fetchAll();

// Fill in missing months with zero values
$complete_monthly_data = [];
for ($i = 1; $i <= 12; $i++) {
    $found = false;
    foreach ($monthly_data as $data) {
        if ($data['month'] == $i) {
            $complete_monthly_data[$i] = $data;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $complete_monthly_data[$i] = [
            'month' => $i,
            'order_count' => 0,
            'total_subtotal' => 0,
            'total_tax' => 0,
            'total_discount' => 0,
            'total_amount' => 0
        ];
    }
}

// Calculate yearly totals
$yearly_totals = [
    'order_count' => 0,
    'total_subtotal' => 0,
    'total_tax' => 0,
    'total_discount' => 0,
    'total_amount' => 0
];

foreach ($complete_monthly_data as $data) {
    $yearly_totals['order_count'] += $data['order_count'];
    $yearly_totals['total_subtotal'] += $data['total_subtotal'];
    $yearly_totals['total_tax'] += $data['total_tax'];
    $yearly_totals['total_discount'] += $data['total_discount'];
    $yearly_totals['total_amount'] += $data['total_amount'];
}

// Get customers for filter
$customers_stmt = $db->query("SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name");
$customers = $customers_stmt->fetchAll();

// Get quarterly data for chart
$quarterly_data = [
    'Q1' => ['orders' => 0, 'amount' => 0],
    'Q2' => ['orders' => 0, 'amount' => 0],
    'Q3' => ['orders' => 0, 'amount' => 0],
    'Q4' => ['orders' => 0, 'amount' => 0]
];

foreach ($complete_monthly_data as $month => $data) {
    if ($month >= 1 && $month <= 3) {
        $quarterly_data['Q1']['orders'] += $data['order_count'];
        $quarterly_data['Q1']['amount'] += $data['total_amount'];
    } elseif ($month >= 4 && $month <= 6) {
        $quarterly_data['Q2']['orders'] += $data['order_count'];
        $quarterly_data['Q2']['amount'] += $data['total_amount'];
    } elseif ($month >= 7 && $month <= 9) {
        $quarterly_data['Q3']['orders'] += $data['order_count'];
        $quarterly_data['Q3']['amount'] += $data['total_amount'];
    } elseif ($month >= 10 && $month <= 12) {
        $quarterly_data['Q4']['orders'] += $data['order_count'];
        $quarterly_data['Q4']['amount'] += $data['total_amount'];
    }
}

// Arabic month names
$arabic_months = [
    1 => 'يناير',
    2 => 'فبراير',
    3 => 'مارس',
    4 => 'أبريل',
    5 => 'مايو',
    6 => 'يونيو',
    7 => 'يوليو',
    8 => 'أغسطس',
    9 => 'سبتمبر',
    10 => 'أكتوبر',
    11 => 'نوفمبر',
    12 => 'ديسمبر'
];

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
                            تقرير المبيعات الشهرية لعام <?php echo $year; ?>
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">السنة</label>
                        <select name="year" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 bg-white">
                            <?php foreach ($available_years as $y): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
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
                    <a href="sales-monthly.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
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
                        <i class="fas fa-shopping-cart text-2xl text-blue-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي الطلبات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($yearly_totals['order_count'], 0, '', ''); ?></p>
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
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($yearly_totals['total_amount'], 0, '', ''); ?> ر.ي</p>
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
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($yearly_totals['total_tax'], 0, '', ''); ?> ر.ي</p>
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
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($yearly_totals['total_discount'], 0, '', ''); ?> ر.ي</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Monthly Sales Chart -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">المبيعات الشهرية</h3>
                </div>
                <div class="p-6">
                    <canvas id="monthlySalesChart" height="300"></canvas>
                </div>
            </div>

            <!-- Quarterly Sales Chart -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">المبيعات الربع سنوية</h3>
                </div>
                <div class="p-6">
                    <canvas id="quarterlySalesChart" height="300"></canvas>
                </div>
            </div>
        </div>

        <!-- Monthly Sales Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">تفاصيل المبيعات الشهرية لعام <?php echo $year; ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الشهر</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">عدد الطلبات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المجموع الفرعي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الضريبة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الخصم</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الإجمالي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">النسبة من الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($complete_monthly_data as $month => $data): ?>
                        <tr class="hover:bg-gray-50 <?php echo $data['order_count'] == 0 ? 'text-gray-400' : ''; ?>">
                            <td class="px-6 py-4 text-sm font-medium <?php echo $data['order_count'] == 0 ? 'text-gray-400' : 'text-gray-900'; ?>">
                                <?php echo $arabic_months[$month]; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($data['order_count'], 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($data['total_subtotal'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($data['total_tax'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm text-red-600">
                                -<?php echo number_format($data['total_discount'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                <?php echo number_format($data['total_amount'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php 
                                $percentage = $yearly_totals['total_amount'] > 0 ? 
                                    ($data['total_amount'] / $yearly_totals['total_amount']) * 100 : 0;
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
                            <td class="px-6 py-4 text-sm text-gray-900">المجموع</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($yearly_totals['order_count'], 0, '', ''); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($yearly_totals['total_subtotal'], 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($yearly_totals['total_tax'], 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-red-600">-<?php echo number_format($yearly_totals['total_discount'], 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($yearly_totals['total_amount'], 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-gray-900">100%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Monthly Sales Chart
const monthlySalesCtx = document.getElementById('monthlySalesChart').getContext('2d');
const monthlySalesChart = new Chart(monthlySalesCtx, {
    type: 'bar',
    data: {
        labels: [
            <?php foreach ($arabic_months as $month): ?>
            '<?php echo $month; ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            label: 'المبيعات الشهرية (ر.ي)',
            data: [
                <?php foreach ($complete_monthly_data as $data): ?>
                <?php echo $data['total_amount']; ?>,
                <?php endforeach; ?>
            ],
            backgroundColor: 'rgba(59, 130, 246, 0.7)',
            borderColor: 'rgb(59, 130, 246)',
            borderWidth: 1
        }, {
            label: 'عدد الطلبات',
            data: [
                <?php foreach ($complete_monthly_data as $data): ?>
                <?php echo $data['order_count']; ?>,
                <?php endforeach; ?>
            ],
            type: 'line',
            borderColor: 'rgb(199, 164, 109)',
            backgroundColor: 'rgba(16, 185, 129, 0.2)',
            borderWidth: 2,
            fill: true,
            yAxisID: 'y1'
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
            },
            y1: {
                beginAtZero: true,
                position: 'right',
                title: {
                    display: true,
                    text: 'عدد الطلبات'
                },
                grid: {
                    drawOnChartArea: false
                }
            }
        }
    }
});

// Quarterly Sales Chart
const quarterlySalesCtx = document.getElementById('quarterlySalesChart').getContext('2d');
const quarterlySalesChart = new Chart(quarterlySalesCtx, {
    type: 'pie',
    data: {
        labels: ['الربع الأول', 'الربع الثاني', 'الربع الثالث', 'الربع الرابع'],
        datasets: [{
            label: 'المبيعات الربع سنوية',
            data: [
                <?php echo $quarterly_data['Q1']['amount']; ?>,
                <?php echo $quarterly_data['Q2']['amount']; ?>,
                <?php echo $quarterly_data['Q3']['amount']; ?>,
                <?php echo $quarterly_data['Q4']['amount']; ?>
            ],
            backgroundColor: [
                'rgba(59, 130, 246, 0.7)',
                'rgba(16, 185, 129, 0.7)',
                'rgba(245, 158, 11, 0.7)',
                'rgba(239, 68, 68, 0.7)'
            ],
            borderColor: [
                'rgb(59, 130, 246)',
                'rgb(199, 164, 109)',
                'rgb(245, 158, 11)',
                'rgb(239, 68, 68)'
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
