<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'تقرير مجموعات الشراء التفصيلي'; // Updated title for clarity

// --- DATA FETCHING AND FILTERING ---
$groups = [];
$all_statuses = [];
$error_message = '';

// Filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_search = $_GET['search'] ?? '';

try {
    // Fetch all available statuses for the filter dropdown
    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM purchase_group_statuses ORDER BY is_default DESC, status_name_ar ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Build WHERE clauses for filters
    $where_clauses = [];
    $params = [];

    if (!empty($filter_status)) {
        $where_clauses[] = "pg.status = ?";
        $params[] = $filter_status;
    }
    if (!empty($filter_search)) {
        $where_clauses[] = "(pg.group_number LIKE ? OR pg.group_name LIKE ?)";
        $search_term = '%' . $filter_search . '%';
        $params[] = $search_term;
        $params[] = $search_term;
    }
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Main Query to fetch groups for the report with detailed financial metrics
    $query = "
        SELECT
            pg.id,
            pg.group_number,
            pg.group_name,
            pg.description,
            pgs.status_name_ar,
            pg.created_at,
            (SELECT COUNT(DISTINCT pb.id) FROM purchase_baskets pb WHERE pb.purchase_group_id = pg.id) as baskets_count,
            (SELECT COUNT(DISTINCT co.id) FROM customer_orders co WHERE co.purchase_group_id = pg.id) as orders_count,
            -- Calculate Total Sales (Revenue) for the group
            (SELECT SUM(COALESCE(co.final_amount, 0))
             FROM customer_orders co
             WHERE co.purchase_group_id = pg.id AND co.status NOT IN ('cancelled', 'refunded')
            ) as total_sales,
            -- Calculate Total Costs (Purchases) for the group
            (SELECT SUM(COALESCE(pb.final_amount, 0))
             FROM purchase_baskets pb
             WHERE pb.purchase_group_id = pg.id
            ) as total_purchases_cost
        FROM purchase_groups pg
        LEFT JOIN purchase_group_statuses pgs ON pg.status = pgs.status_key
        $where_sql
        ORDER BY pg.created_at DESC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals for the summary cards and net profit/loss for each group
    $total_groups = count($groups);
    $total_baskets_overall = 0;
    $total_orders_overall = 0;
    $total_sales_overall = 0;
    $total_purchases_cost_overall = 0;
    $total_net_profit_loss_overall = 0;

    foreach ($groups as &$group) { // Use & to modify the original array elements
        $group['net_profit_loss'] = $group['total_sales'] - $group['total_purchases_cost'];

        // Calculate profit margin and markup percentage for each group
        $group_revenue = $group['total_sales'];
        $group_cost = $group['total_purchases_cost'];
        $group_profit = $group['net_profit_loss'];

        $group['profit_margin'] = ($group_revenue > 0) ? ($group_profit / $group_revenue) * 100 : 0;
        $group['markup_percentage'] = ($group_cost > 0) ? ($group_profit / $group_cost) * 100 : 0;


        // Accumulate overall totals
        $total_baskets_overall += $group['baskets_count'];
        $total_orders_overall += $group['orders_count'];
        $total_sales_overall += $group['total_sales'];
        $total_purchases_cost_overall += $group['total_purchases_cost'];
        $total_net_profit_loss_overall += $group['net_profit_loss'];
    }
    unset($group); // Break the reference with the last element

    // Calculate overall profit margin and markup percentage
    $overall_profit_margin = ($total_sales_overall > 0) ? ($total_net_profit_loss_overall / $total_sales_overall) * 100 : 0;
    $overall_markup_percentage = ($total_purchases_cost_overall > 0) ? ($total_net_profit_loss_overall / $total_purchases_cost_overall) * 100 : 0;

} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء جلب البيانات: ' . $e->getMessage();
    // Initialize overall totals to prevent errors on the page
    $total_groups = 0;
    $total_baskets_overall = 0;
    $total_orders_overall = 0;
    $total_sales_overall = 0;
    $total_purchases_cost_overall = 0;
    $total_net_profit_loss_overall = 0;
    $overall_profit_margin = 0;
    $overall_markup_percentage = 0;
}

include '../../includes/header.php';
?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <div class="bg-gradient-to-br from-blue-600 to-indigo-700 shadow-lg rounded-xl mb-8 p-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-white flex items-center gap-3">
                    <i class="fas fa-layer-group"></i> <?php echo $page_title; ?>
                </h1>
                <p class="text-blue-100 mt-2">عرض وتحليل مجموعات الشراء مع التفاصيل المالية لكل مجموعة</p>
            </div>
            <a href="index.php" class="px-6 py-3 bg-white text-blue-600 rounded-xl hover:bg-blue-50 font-semibold transition">
                <i class="fas fa-arrow-right ml-2"></i> العودة للرئيسية
            </a>
        </div>
    </div>

    <!-- Export Buttons -->
    <div class="flex flex-wrap gap-4 mb-6">
        <button class="px-5 py-2.5 bg-red-500 hover:bg-red-600 text-white rounded-lg font-semibold flex items-center gap-2" onclick="exportReport('pdf')">
            <i class="fas fa-file-pdf"></i> تصدير PDF
        </button>
        <button class="px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-semibold flex items-center gap-2" onclick="exportReport('excel')">
            <i class="fas fa-file-excel"></i> تصدير Excel
        </button>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
        <form method="GET" id="filterForm">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">بحث</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="رقم أو اسم المجموعة..." class="w-full rounded-lg border-gray-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                    <select name="status" class="w-full rounded-lg border-gray-300">
                        <option value="">الكل</option>
                        <?php foreach ($all_statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo $filter_status === $status['status_key'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status['status_name_ar']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg">
                        <i class="fas fa-filter"></i> تصفية
                    </button>
                     <a href="purchase_groups_report.php" class="w-full bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium py-2 px-4 rounded-lg text-center">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>

     <!-- Totals (Updated to include new financial metrics) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-8 gap-4 mb-6">
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-blue-500"><p class="text-sm text-gray-600">إجمالي المجموعات</p><p class="text-2xl font-bold"><?php echo number_format($total_groups); ?></p></div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-purple-500"><p class="text-sm text-gray-600">إجمالي السلال</p><p class="text-2xl font-bold"><?php echo number_format($total_baskets_overall); ?></p></div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-yellow-500"><p class="text-sm text-gray-600">إجمالي الطلبات</p><p class="text-2xl font-bold"><?php echo number_format($total_orders_overall); ?></p></div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-green-500"><p class="text-sm text-gray-600">إجمالي الإيرادات</p><p class="text-2xl font-bold"><?php echo number_format($total_sales_overall, 2); ?> ر.ي</p></div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-red-500"><p class="text-sm text-gray-600">إجمالي التكاليف</p><p class="text-2xl font-bold"><?php echo number_format($total_purchases_cost_overall, 2); ?> ر.ي</p></div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-teal-500"><p class="text-sm text-gray-600">صافي الربح/الخسارة</p><p class="text-2xl font-bold <?php echo $total_net_profit_loss_overall >= 0 ? 'text-green-700' : 'text-red-700'; ?>"><?php echo number_format($total_net_profit_loss_overall, 2); ?> ر.ي</p></div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-orange-500"><p class="text-sm text-gray-600">هامش الربح الكلي</p><p class="text-2xl font-bold <?php echo $overall_profit_margin >= 0 ? 'text-orange-700' : 'text-red-700'; ?>"><?php echo number_format($overall_profit_margin, 2); ?>%</p></div>
        <div class="bg-white p-4 rounded-lg shadow border-l-4 border-indigo-500"><p class="text-sm text-gray-600">الربح على التكلفة الكلي</p><p class="text-2xl font-bold <?php echo $overall_markup_percentage >= 0 ? 'text-indigo-700' : 'text-red-700'; ?>"><?php echo number_format($overall_markup_percentage, 2); ?>%</p></div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">رقم المجموعة</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">اسم المجموعة</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحالة</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">السلال</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">الطلبات</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">إجمالي الإيرادات</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">إجمالي التكاليف</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">صافي الربح/الخسارة</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">هامش الربح (%)</th> <!-- New Header -->
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الربح على التكلفة (%)</th> <!-- New Header -->
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">تاريخ الإنشاء</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($groups)): ?>
                        <tr><td colspan="12" class="text-center py-10 text-gray-500">لا توجد بيانات تطابق الفلاتر المحددة.</td></tr>
                    <?php else: ?>
                        <?php foreach ($groups as $group): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-800"><?php echo htmlspecialchars($group['group_number']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($group['group_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?php echo htmlspecialchars($group['status_name_ar'] ?? 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-800"><?php echo htmlspecialchars($group['baskets_count']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-800"><?php echo htmlspecialchars($group['orders_count']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-700"><?php echo number_format($group['total_sales'], 2); ?> ر.ي</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600"><?php echo number_format($group['total_purchases_cost'], 2); ?> ر.ي</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?php echo $group['net_profit_loss'] >= 0 ? 'text-green-700' : 'text-red-700'; ?>"><?php echo number_format($group['net_profit_loss'], 2); ?> ر.ي</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?php echo $group['profit_margin'] >= 0 ? 'text-orange-700' : 'text-red-700'; ?>"><?php echo number_format($group['profit_margin'], 2); ?>%</td> <!-- Display Profit Margin -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?php echo $group['markup_percentage'] >= 0 ? 'text-indigo-700' : 'text-red-700'; ?>"><?php echo number_format($group['markup_percentage'], 2); ?>%</td> <!-- Display Markup Percentage -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($group['created_at'])); ?></td>
                             <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                <a href="view.php?id=<?php echo $group['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3" title="عرض التفاصيل"><i class="fas fa-eye"></i></a>
                                <!-- Add other actions here if needed, e.g., edit -->
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function exportReport(format) {
    const form = document.getElementById('filterForm');
    const params = new URLSearchParams(new FormData(form));
    params.set('type', 'purchase_groups'); // Hardcode the report type
    params.set('format', format);
    window.location.href = `export.php?${params.toString()}`;
}
</script>

<?php include '../../includes/footer.php'; ?>