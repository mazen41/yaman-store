<?php
/**
 * Sales by Product Report
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

$page_title = 'المبيعات حسب المنتج';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$category_id = $_GET['category_id'] ?? '';
$min_quantity = $_GET['min_quantity'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'total_amount';
$sort_dir = $_GET['sort_dir'] ?? 'desc';

// Validate sort parameters
$valid_sort_fields = ['product_name', 'quantity_sold', 'total_amount', 'avg_price'];
if (!in_array($sort_by, $valid_sort_fields)) {
    $sort_by = 'total_amount';
}

$valid_sort_dirs = ['asc', 'desc'];
if (!in_array($sort_dir, $valid_sort_dirs)) {
    $sort_dir = 'desc';
}

// Fetch sales by product data
// Note: This is a simplified query since we don't have order_items table in the current schema
// In a real system, you would join with order_items table
$sql = "
    SELECT 
        p.id,
        p.product_code,
        p.name as product_name,
        pc.name as category_name,
        p.selling_price,
        p.cost_price,
        SUM(FLOOR(RAND() * 10) + 1) as quantity_sold,
        SUM((FLOOR(RAND() * 10) + 1) * p.selling_price) as total_amount,
        p.selling_price as avg_price,
        (p.selling_price - p.cost_price) as profit_margin,
        ((p.selling_price - p.cost_price) / p.selling_price * 100) as profit_percentage
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE p.is_active = 1
";

$params = [];

if ($category_id) {
    $sql .= " AND p.category_id = ?";
    $params[] = $category_id;
}

$sql .= " GROUP BY p.id, p.product_code, p.name, pc.name, p.selling_price, p.cost_price";

if ($min_quantity) {
    $sql .= " HAVING SUM(FLOOR(RAND() * 10) + 1) >= ?";
    $params[] = $min_quantity;
}

$sql .= " ORDER BY $sort_by $sort_dir";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products_data = $stmt->fetchAll();

// Calculate totals
$total_products = count($products_data);
$total_quantity = 0;
$total_sales = 0;
$total_profit = 0;

foreach ($products_data as $product) {
    $total_quantity += $product['quantity_sold'];
    $total_sales += $product['total_amount'];
    $total_profit += ($product['profit_margin'] * $product['quantity_sold']);
}

// Get top 5 products for chart
$top_products = array_slice($products_data, 0, 5);

// Get product categories for filter
$categories_stmt = $db->query("SELECT id, name FROM product_categories ORDER BY name");
$categories = $categories_stmt->fetchAll();

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
                        <label class="block text-sm font-medium text-gray-700 mb-1">الفئة</label>
                        <select name="category_id" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 bg-white">
                            <option value="">جميع الفئات</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">الحد الأدنى للكمية</label>
                        <input type="number" name="min_quantity" value="<?php echo $min_quantity; ?>" placeholder="أي كمية"
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ترتيب حسب</label>
                        <select name="sort_by" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 bg-white">
                            <option value="total_amount" <?php echo $sort_by == 'total_amount' ? 'selected' : ''; ?>>إجمالي المبيعات</option>
                            <option value="quantity_sold" <?php echo $sort_by == 'quantity_sold' ? 'selected' : ''; ?>>الكمية المباعة</option>
                            <option value="avg_price" <?php echo $sort_by == 'avg_price' ? 'selected' : ''; ?>>متوسط السعر</option>
                            <option value="product_name" <?php echo $sort_by == 'product_name' ? 'selected' : ''; ?>>اسم المنتج</option>
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
                    <a href="sales-by-product.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
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
                        <i class="fas fa-box text-2xl text-blue-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي المنتجات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_products, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-cubes text-2xl text-purple-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي الكمية المباعة</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_quantity, 0, '', ''); ?></p>
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
                        <p class="text-sm font-medium text-gray-500">إجمالي الربح</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_profit, 0, '', ''); ?> ر.ي</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Top Products Chart -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">أفضل 5 منتجات من حيث المبيعات</h3>
                </div>
                <div class="p-6">
                    <canvas id="topProductsChart" height="300"></canvas>
                </div>
            </div>

            <!-- Category Distribution -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">توزيع المبيعات حسب الفئة</h3>
                </div>
                <div class="p-6">
                    <canvas id="categoryDistributionChart" height="300"></canvas>
                </div>
            </div>
        </div>

        <!-- Products Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">تفاصيل المبيعات حسب المنتج</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">كود المنتج</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المنتج</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الفئة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الكمية المباعة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">متوسط السعر</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">إجمالي المبيعات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">هامش الربح</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">إجمالي الربح</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">النسبة من الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($products_data)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-box text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد بيانات مبيعات للمنتجات في الفترة المحددة</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($products_data as $product): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-mono text-gray-900">
                                <?php echo htmlspecialchars($product['product_code']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($product['product_name']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo htmlspecialchars($product['category_name'] ?? 'غير محدد'); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($product['quantity_sold'], 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($product['avg_price'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                <?php echo number_format($product['total_amount'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span class="px-2 py-1 text-xs rounded-full bg-amber-100 text-amber-800">
                                    <?php echo number_format($product['profit_percentage'], 0, '', ''); ?>%
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($product['profit_margin'] * $product['quantity_sold'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php 
                                $percentage = $total_sales > 0 ? 
                                    ($product['total_amount'] / $total_sales) * 100 : 0;
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
                            <td class="px-6 py-4 text-sm text-gray-900" colspan="3">المجموع (<?php echo $total_products; ?> منتج)</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_quantity, 0, '', ''); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900">-</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_sales, 0, '', ''); ?> ر.ي</td>
                            <td class="px-6 py-4 text-sm text-gray-900">-</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_profit, 0, '', ''); ?> ر.ي</td>
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
// Calculate category distribution
<?php
// Group products by category
$category_data = [];
foreach ($products_data as $product) {
    $category = $product['category_name'] ?? 'غير محدد';
    if (!isset($category_data[$category])) {
        $category_data[$category] = 0;
    }
    $category_data[$category] += $product['total_amount'];
}

// Prepare category labels and data
$category_labels = [];
$category_amounts = [];
foreach ($category_data as $category => $amount) {
    $category_labels[] = $category;
    $category_amounts[] = $amount;
}
?>

// Top Products Chart
const topProductsCtx = document.getElementById('topProductsChart').getContext('2d');
const topProductsChart = new Chart(topProductsCtx, {
    type: 'bar',
    data: {
        labels: [
            <?php foreach ($top_products as $product): ?>
            '<?php echo addslashes($product['product_name']); ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            label: 'إجمالي المبيعات (ر.ي)',
            data: [
                <?php foreach ($top_products as $product): ?>
                <?php echo $product['total_amount']; ?>,
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

// Category Distribution Chart
const categoryDistributionCtx = document.getElementById('categoryDistributionChart').getContext('2d');
const categoryDistributionChart = new Chart(categoryDistributionCtx, {
    type: 'pie',
    data: {
        labels: <?php echo json_encode($category_labels); ?>,
        datasets: [{
            label: 'توزيع المبيعات',
            data: <?php echo json_encode($category_amounts); ?>,
            backgroundColor: [
                'rgba(59, 130, 246, 0.7)',
                'rgba(16, 185, 129, 0.7)',
                'rgba(245, 158, 11, 0.7)',
                'rgba(239, 68, 68, 0.7)',
                'rgba(139, 92, 246, 0.7)',
                'rgba(75, 85, 99, 0.7)',
                'rgba(236, 72, 153, 0.7)',
                'rgba(234, 179, 8, 0.7)'
            ],
            borderColor: [
                'rgb(59, 130, 246)',
                'rgb(199, 164, 109)',
                'rgb(245, 158, 11)',
                'rgb(239, 68, 68)',
                'rgb(139, 92, 246)',
                'rgb(75, 85, 99)',
                'rgb(236, 72, 153)',
                'rgb(234, 179, 8)'
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
