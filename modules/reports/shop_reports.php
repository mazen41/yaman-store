<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'تقارير المبيعات والأرباح للمتجر';

// --- 1. FILTERING & DATE SETUP ---
$today = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Default: start of the current month
$date_to = $_GET['date_to'] ?? $today; // Default: today

// --- 2. DATA FETCHING AND CALCULATION ---
$summary = [
    'total_revenue' => 0,
    'total_cost' => 0,
    'gross_profit' => 0,
    'profit_margin' => 0,
    'order_count' => 0,
    'products_sold' => 0,
];
$top_selling_products = [];
$top_profitable_orders = [];

try {
    // --- A. SUMMARY METRICS ---
    $summary_query = "
        SELECT 
            -- الإيرادات: إجمالي المبلغ مطروحاً منه رسوم الشحن
            COALESCE(SUM(o.total_amount - o.shipping_fee), 0) AS total_revenue,
            
            -- التكلفة: مجموع (سعر شراء المنتج * الكمية) لكل منتج في الطلب
            COALESCE(SUM((
                SELECT SUM(p.purchase_amount * soi.quantity)
                FROM shop_order_items soi
                LEFT JOIN products p ON soi.product_id = p.id
                WHERE soi.order_id = o.id
            )), 0) AS total_cost,
            
            -- عدد الطلبات المعتمدة
            COUNT(DISTINCT o.id) AS order_count,
            
            -- إجمالي عدد المنتجات المباعة
            COALESCE(SUM((SELECT SUM(quantity) FROM shop_order_items WHERE order_id = o.id)), 0) AS products_sold
            
        FROM shop_orders o
        WHERE o.order_status = 'طلب معتمد'
          AND DATE(o.created_at) BETWEEN :date_from AND :date_to
    ";
    
    $stmt_summary = $db->prepare($summary_query);
    $stmt_summary->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $summary_data = $stmt_summary->fetch(PDO::FETCH_ASSOC);

    if ($summary_data) {
        $summary['total_revenue'] = (float)$summary_data['total_revenue'];
        $summary['total_cost'] = (float)$summary_data['total_cost'];
        $summary['order_count'] = (int)$summary_data['order_count'];
        $summary['products_sold'] = (int)$summary_data['products_sold'];
        $summary['gross_profit'] = $summary['total_revenue'] - $summary['total_cost'];
        if ($summary['total_revenue'] > 0) {
            $summary['profit_margin'] = ($summary['gross_profit'] / $summary['total_revenue']) * 100;
        }
    }

    // --- B. TOP SELLING PRODUCTS ---
    $top_products_query = "
        SELECT 
            p.id,
            p.name,
            SUM(soi.quantity) AS total_quantity_sold,
            SUM(soi.total_price) AS total_revenue,
            SUM(p.purchase_amount * soi.quantity) AS total_cost,
            (SUM(soi.total_price) - SUM(p.purchase_amount * soi.quantity)) AS total_profit
        FROM shop_order_items soi
        JOIN products p ON soi.product_id = p.id
        JOIN shop_orders o ON soi.order_id = o.id
        WHERE o.order_status = 'طلب معتمد'
          AND DATE(o.created_at) BETWEEN :date_from AND :date_to
        GROUP BY p.id, p.name
        ORDER BY total_quantity_sold DESC
        LIMIT 10
    ";
    $stmt_top_products = $db->prepare($top_products_query);
    $stmt_top_products->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $top_selling_products = $stmt_top_products->fetchAll(PDO::FETCH_ASSOC);
    
    // --- C. TOP PROFITABLE ORDERS ---
    $top_orders_query = "
        SELECT
            o.id,
            o.order_number,
            c.name AS customer_name,
            (o.total_amount - o.shipping_fee) AS net_revenue,
            COALESCE((
                SELECT SUM(p.purchase_amount * soi.quantity)
                FROM shop_order_items soi
                LEFT JOIN products p ON soi.product_id = p.id
                WHERE soi.order_id = o.id
            ), 0) AS total_cost,
            ((o.total_amount - o.shipping_fee) - COALESCE((
                SELECT SUM(p.purchase_amount * soi.quantity)
                FROM shop_order_items soi
                LEFT JOIN products p ON soi.product_id = p.id
                WHERE soi.order_id = o.id
            ), 0)) AS profit
        FROM shop_orders o
        JOIN customers c ON o.customer_id = c.id
        WHERE o.order_status = 'طلب معتمد'
          AND DATE(o.created_at) BETWEEN :date_from AND :date_to
        ORDER BY profit DESC
        LIMIT 10
    ";
    $stmt_top_orders = $db->prepare($top_orders_query);
    $stmt_top_orders->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $top_profitable_orders = $stmt_top_orders->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "حدث خطأ في استعلام قاعدة البيانات: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<style>
    .report-card { background: white; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden; }
    .report-card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #e5e7eb; }
    .report-card-title { font-size: 1.125rem; font-weight: 700; color: #1f2937; }
    .report-card-body { padding: 1.5rem; }

    .stat-card { display: flex; align-items: center; gap: 1rem; padding: 1.25rem; background-color: #f9fafb; border-radius: 12px; border: 1px solid #e5e7eb; }
    .stat-icon { flex-shrink: 0; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; }
    .stat-value { font-size: 1.75rem; font-weight: 800; color: #111827; line-height: 1; }
    .stat-label { font-size: 0.875rem; font-weight: 600; color: #6b7280; }
</style>

<div class="min-h-screen bg-gray-100 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-700 shadow-xl rounded-2xl mb-8 p-6">
            <div class="flex flex-col sm:flex-row justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-chart-pie ml-3"></i>
                        <?php echo $page_title; ?>
                    </h1>
                    <p class="text-indigo-100 mt-2">نظرة شاملة على أداء المبيعات والأرباح من المتجر الإلكتروني.</p>
                </div>
                <a href="index.php" class="mt-4 sm:mt-0 px-6 py-3 bg-white text-blue-600 rounded-xl hover:bg-blue-50 font-semibold transition">
                    <i class="fas fa-arrow-right ml-2"></i> العودة للرئيسية
                </a>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                <p class="font-bold">خطأ!</p><p><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="report-card mb-8">
            <form method="GET" class="flex flex-wrap items-end gap-4 p-4">
                <div class="flex-grow">
                    <label for="date_from" class="block text-sm font-semibold text-gray-700 mb-1">من تاريخ</label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full border-gray-300 rounded-lg shadow-sm">
                </div>
                <div class="flex-grow">
                    <label for="date_to" class="block text-sm font-semibold text-gray-700 mb-1">إلى تاريخ</label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full border-gray-300 rounded-lg shadow-sm">
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition"><i class="fas fa-filter mr-2"></i> عرض التقرير</button>
            </form>
        </div>

        <!-- Summary Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Revenue -->
            <div class="stat-card">
                <div class="stat-icon bg-green-500"><i class="fas fa-dollar-sign text-2xl"></i></div>
                <div>
                    <div class="stat-value"><?php echo number_format($summary['total_revenue'], 2); ?></div>
                    <div class="stat-label">إجمالي المبيعات</div>
                </div>
            </div>
            <!-- Total Cost -->
            <div class="stat-card">
                <div class="stat-icon bg-red-500"><i class="fas fa-shopping-cart text-2xl"></i></div>
                <div>
                    <div class="stat-value"><?php echo number_format($summary['total_cost'], 2); ?></div>
                    <div class="stat-label">تكلفة البضاعة المباعة</div>
                </div>
            </div>
            <!-- Gross Profit -->
            <div class="stat-card">
                <div class="stat-icon bg-blue-500"><i class="fas fa-chart-line text-2xl"></i></div>
                <div>
                    <div class="stat-value"><?php echo number_format($summary['gross_profit'], 2); ?></div>
                    <div class="stat-label">الربح الإجمالي (<?php echo round($summary['profit_margin'], 1); ?>%)</div>
                </div>
            </div>
            <!-- Orders Count -->
            <div class="stat-card">
                <div class="stat-icon bg-purple-500"><i class="fas fa-receipt text-2xl"></i></div>
                <div>
                    <div class="stat-value"><?php echo $summary['order_count']; ?></div>
                    <div class="stat-label">عدد الطلبات المعتمدة</div>
                </div>
            </div>
        </div>

        <!-- Detailed Tables -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Top Selling Products -->
            <div class="report-card">
                <div class="report-card-header"><h2 class="report-card-title"><i class="fas fa-star text-yellow-500 mr-2"></i> المنتجات الأكثر مبيعاً</h2></div>
                <div class="report-card-body p-0">
                    <table class="w-full text-right">
                        <thead><tr class="bg-gray-50">
                            <th class="p-3 text-sm font-semibold text-gray-600">المنتج</th>
                            <th class="p-3 text-sm font-semibold text-gray-600 text-center">الكمية المباعة</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">الربح الصافي</th>
                        </tr></thead>
                        <tbody>
                            <?php if (empty($top_selling_products)): ?>
                                <tr><td colspan="3" class="p-4 text-center text-gray-500">لا توجد بيانات لهذه الفترة.</td></tr>
                            <?php else: foreach($top_selling_products as $product): ?>
                                <tr class="border-b last:border-b-0">
                                    <td class="p-3 font-semibold text-gray-800"><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td class="p-3 text-center font-bold text-gray-700"><?php echo $product['total_quantity_sold']; ?></td>
                                    <td class="p-3 font-bold text-blue-600"><?php echo number_format($product['total_profit'], 2); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Profitable Orders -->
            <div class="report-card">
                <div class="report-card-header"><h2 class="report-card-title"><i class="fas fa-trophy text-green-500 mr-2"></i> الطلبات الأعلى ربحاً</h2></div>
                <div class="report-card-body p-0">
                    <table class="w-full text-right">
                         <thead><tr class="bg-gray-50">
                            <th class="p-3 text-sm font-semibold text-gray-600">رقم الطلب</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">العميل</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">الربح الصافي</th>
                        </tr></thead>
                        <tbody>
                            <?php if (empty($top_profitable_orders)): ?>
                                <tr><td colspan="3" class="p-4 text-center text-gray-500">لا توجد بيانات لهذه الفترة.</td></tr>
                            <?php else: foreach($top_profitable_orders as $order): ?>
                                <tr class="border-b last:border-b-0">
                                    <td class="p-3 font-semibold text-gray-800">
                                        <a href="shop_order_view.php?id=<?php echo $order['id']; ?>" class="text-indigo-600 hover:underline">
                                            <?php echo htmlspecialchars($order['order_number']); ?>
                                        </a>
                                    </td>
                                    <td class="p-3 font-semibold text-gray-700"><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                    <td class="p-3 font-bold text-blue-600"><?php echo number_format($order['profit'], 2); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>