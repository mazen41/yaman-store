<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تحليلات المشتريات';

// Get date range from filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$supplier_filter = $_GET['supplier_id'] ?? '';

// Purchase Orders Analytics
$orders_stats = $db->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(total_amount) as total_amount,
        AVG(total_amount) as avg_order_value,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_orders,
        COUNT(CASE WHEN status = 'received' THEN 1 END) as completed_orders,
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders,
        COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent_orders
    FROM purchase_orders 
    WHERE order_date BETWEEN ? AND ?
    " . ($supplier_filter ? "AND supplier_id = ?" : "")
);

$params = [$start_date, $end_date];
if ($supplier_filter) $params[] = $supplier_filter;
$orders_stats->execute($params);
$orders_data = $orders_stats->fetch();

// Monthly trend data
$monthly_trend = $db->prepare("
    SELECT 
        DATE_FORMAT(order_date, '%Y-%m') as month,
        COUNT(*) as order_count,
        SUM(total_amount) as total_amount
    FROM purchase_orders 
    WHERE order_date >= DATE_SUB(?, INTERVAL 11 MONTH)
    GROUP BY DATE_FORMAT(order_date, '%Y-%m')
    ORDER BY month
");
$monthly_trend->execute([$end_date]);
$trend_data = $monthly_trend->fetchAll();

// Top suppliers
$top_suppliers = $db->prepare("
    SELECT 
        s.name as supplier_name,
        COUNT(po.id) as order_count,
        SUM(po.total_amount) as total_amount,
        AVG(po.total_amount) as avg_order_value
    FROM suppliers s
    JOIN purchase_orders po ON s.id = po.supplier_id
    WHERE po.order_date BETWEEN ? AND ?
    GROUP BY s.id, s.name
    ORDER BY total_amount DESC
    LIMIT 10
");
$top_suppliers->execute([$start_date, $end_date]);
$suppliers_data = $top_suppliers->fetchAll();

// Status distribution
$status_distribution = $db->prepare("
    SELECT 
        status,
        COUNT(*) as count,
        SUM(total_amount) as amount
    FROM purchase_orders 
    WHERE order_date BETWEEN ? AND ?
    GROUP BY status
");
$status_distribution->execute([$start_date, $end_date]);
$status_data = $status_distribution->fetchAll();

// Priority analysis
$priority_analysis = $db->prepare("
    SELECT 
        priority,
        COUNT(*) as count,
        AVG(DATEDIFF(COALESCE(updated_at, created_at), created_at)) as avg_processing_days
    FROM purchase_orders 
    WHERE order_date BETWEEN ? AND ?
    GROUP BY priority
");
$priority_analysis->execute([$start_date, $end_date]);
$priority_data = $priority_analysis->fetchAll();

// Get suppliers for filter
$suppliers = $db->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">تحليلات المشتريات</h1>
                        <p class="text-gray-600 mt-1">تقارير وإحصائيات شاملة لنظام المشتريات</p>
                    </div>
                    <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة للمشتريات
                    </a>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">من تاريخ</label>
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>" 
                               class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">إلى تاريخ</label>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>" 
                               class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">المورد</label>
                        <select name="supplier_id" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white">
                            <option value="">جميع الموردين</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-filter ml-2"></i>تطبيق الفلتر
                    </button>
                    <a href="analytics.php" class="px-4 py-2 bg-gray-400 text-white rounded-lg hover:bg-gray-500 transition-colors duration-200">
                        <i class="fas fa-refresh ml-2"></i>إعادة تعيين
                    </a>
                </form>
            </div>
        </div>

        <!-- Key Metrics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-shopping-cart text-2xl text-white"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-blue-100 truncate">إجمالي الطلبات</dt>
                                <dd class="text-2xl font-bold text-white"><?php echo number_format($orders_data['total_orders'], 0, '', ''); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-r from-amber-500 to-amber-600 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-money-bill-wave text-2xl text-white"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-amber-100 truncate">إجمالي المبلغ</dt>
                                <dd class="text-2xl font-bold text-white"><?php echo number_format($orders_data['total_amount'], 0); ?> ر.س</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-r from-purple-500 to-purple-600 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-chart-line text-2xl text-white"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-purple-100 truncate">متوسط قيمة الطلب</dt>
                                <dd class="text-2xl font-bold text-white"><?php echo number_format($orders_data['avg_order_value'], 0); ?> ر.س</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-gradient-to-r from-orange-500 to-orange-600 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-2xl text-white"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-orange-100 truncate">طلبات عاجلة</dt>
                                <dd class="text-2xl font-bold text-white"><?php echo number_format($orders_data['urgent_orders'], 0, '', ''); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            
            <!-- Monthly Trend Chart -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">الاتجاه الشهري للمشتريات</h3>
                </div>
                <div class="p-6">
                    <canvas id="monthlyTrendChart" width="400" height="200"></canvas>
                </div>
            </div>

            <!-- Status Distribution -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">توزيع حالات الطلبات</h3>
                </div>
                <div class="p-6">
                    <canvas id="statusChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            
            <!-- Top Suppliers -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">أفضل الموردين</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المورد</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الطلبات</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المبلغ</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($suppliers_data as $index => $supplier): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8">
                                            <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                <span class="text-sm font-medium text-blue-600"><?php echo $index + 1; ?></span>
                                            </div>
                                        </div>
                                        <div class="mr-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($supplier['supplier_name']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $supplier['order_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo number_format($supplier['total_amount'], 0); ?> ر.س
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Priority Analysis -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">تحليل الأولويات</h3>
                </div>
                <div class="p-6">
                    <?php foreach ($priority_data as $priority): ?>
                    <div class="mb-4">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-medium text-gray-700">
                                <?php 
                                $priority_labels = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'urgent' => 'عاجلة'];
                                echo $priority_labels[$priority['priority']] ?? $priority['priority']; 
                                ?>
                            </span>
                            <span class="text-sm text-gray-600"><?php echo $priority['count']; ?> طلب</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <?php 
                            $percentage = ($priority['count'] / $orders_data['total_orders']) * 100;
                            $color_classes = [
                                'low' => 'bg-gray-400',
                                'medium' => 'bg-blue-400', 
                                'high' => 'bg-orange-400',
                                'urgent' => 'bg-red-400'
                            ];
                            ?>
                            <div class="<?php echo $color_classes[$priority['priority']] ?? 'bg-gray-400'; ?> h-2 rounded-full" 
                                 style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            متوسط أيام المعالجة: <?php echo round($priority['avg_processing_days'], 1); ?> يوم
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Detailed Statistics -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">إحصائيات تفصيلية</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-yellow-600"><?php echo $orders_data['pending_orders']; ?></div>
                        <div class="text-sm text-gray-600">طلبات معلقة</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-blue-600"><?php echo $orders_data['approved_orders']; ?></div>
                        <div class="text-sm text-gray-600">طلبات معتمدة</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-amber-600"><?php echo $orders_data['completed_orders']; ?></div>
                        <div class="text-sm text-gray-600">طلبات مكتملة</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-red-600"><?php echo $orders_data['cancelled_orders']; ?></div>
                        <div class="text-sm text-gray-600">طلبات ملغية</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Monthly Trend Chart
const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
const monthlyChart = new Chart(monthlyCtx, {
    type: 'line',
    data: {
        labels: [<?php echo "'" . implode("','", array_column($trend_data, 'month')) . "'"; ?>],
        datasets: [{
            label: 'عدد الطلبات',
            data: [<?php echo implode(',', array_column($trend_data, 'order_count')); ?>],
            borderColor: 'rgb(59, 130, 246)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.4,
            yAxisID: 'y'
        }, {
            label: 'إجمالي المبلغ (ألف ريال)',
            data: [<?php echo implode(',', array_map(function($item) { return round($item['total_amount'] / 1000, 1); }, $trend_data)); ?>],
            borderColor: 'rgb(199, 164, 109)',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'top',
            }
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: 'عدد الطلبات'
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: 'المبلغ (ألف ريال)'
                },
                grid: {
                    drawOnChartArea: false,
                }
            }
        }
    }
});

// Status Distribution Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
const statusChart = new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: [
            <?php 
            $status_labels = [
                'draft' => 'مسودة',
                'pending' => 'معلق', 
                'approved' => 'معتمد',
                'ordered' => 'تم الطلب',
                'received' => 'تم الاستلام',
                'cancelled' => 'ملغي'
            ];
            echo "'" . implode("','", array_map(function($item) use ($status_labels) {
                return $status_labels[$item['status']] ?? $item['status'];
            }, $status_data)) . "'";
            ?>
        ],
        datasets: [{
            data: [<?php echo implode(',', array_column($status_data, 'count')); ?>],
            backgroundColor: [
                '#6B7280', // draft - gray
                '#F59E0B', // pending - yellow
                '#3B82F6', // approved - blue
                '#8B5CF6', // ordered - purple
                '#10B981', // received - green
                '#EF4444'  // cancelled - red
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom',
            }
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
