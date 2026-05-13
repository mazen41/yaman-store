<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'نظرة عامة على أداء الطلبات';

// --- 1. FILTERING & DATE SETUP ---
$today = date('Y-m-d');
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Default: start of the current month
$date_to = $_GET['date_to'] ?? $today; // Default: today
$status_filter = $_GET['status'] ?? ''; // Filter by order status

// --- 2. DATA FETCHING AND CALCULATION ---
$stats = [
    'total_orders' => 0,
    'approved_orders' => 0,
    'rejected_orders' => 0,
    'new_orders' => 0,
    'approval_rate' => 0,
    'total_value' => 0,
    'approved_value' => 0,
];
$orders_list = [];

try {
    // --- A. SUMMARY STATS ---
    // This query calculates all counts in one go for efficiency
    $stats_query = "
        SELECT 
            COUNT(id) AS total_orders,
            COALESCE(SUM(CASE WHEN order_status = 'طلب معتمد' THEN 1 ELSE 0 END), 0) AS approved_orders,
            COALESCE(SUM(CASE WHEN order_status = 'مرفوض' THEN 1 ELSE 0 END), 0) AS rejected_orders,
            COALESCE(SUM(CASE WHEN order_status = 'طلب جديد' THEN 1 ELSE 0 END), 0) AS new_orders,
            COALESCE(SUM(total_amount), 0) AS total_value,
            COALESCE(SUM(CASE WHEN order_status = 'طلب معتمد' THEN total_amount ELSE 0 END), 0) AS approved_value
        FROM shop_orders
        WHERE DATE(created_at) BETWEEN :date_from AND :date_to
    ";
    
    $stmt_stats = $db->prepare($stats_query);
    $stmt_stats->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $stats_data = $stmt_stats->fetch(PDO::FETCH_ASSOC);

    if ($stats_data) {
        $stats = array_merge($stats, $stats_data);
        if ($stats['total_orders'] > 0) {
            // Approval rate = (approved / (approved + rejected)) * 100
            $decided_orders = $stats['approved_orders'] + $stats['rejected_orders'];
            if ($decided_orders > 0) {
                 $stats['approval_rate'] = ($stats['approved_orders'] / $decided_orders) * 100;
            }
        }
    }

    // --- B. DETAILED ORDERS LIST ---
    $orders_list_query = "
        SELECT 
            o.id,
            o.order_number,
            o.created_at,
            o.total_amount,
            o.order_status,
            c.name AS customer_name
        FROM shop_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE DATE(o.created_at) BETWEEN :date_from AND :date_to
    ";

    $params = [':date_from' => $date_from, ':date_to' => $date_to];

    if (!empty($status_filter)) {
        $orders_list_query .= " AND o.order_status = :status";
        $params[':status'] = $status_filter;
    }

    $orders_list_query .= " ORDER BY o.created_at DESC";

    $stmt_orders = $db->prepare($orders_list_query);
    $stmt_orders->execute($params);
    $orders_list = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "حدث خطأ في استعلام قاعدة البيانات: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<style>
    .report-card { background: white; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden; }
    .stat-card { display: flex; flex-direction: column; justify-content: space-between; padding: 1.5rem; background-color: white; border-radius: 12px; border: 1px solid #e5e7eb; transition: all 0.2s ease-in-out; }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 16px rgba(0,0,0,0.08); }
    .stat-header { display: flex; justify-content: space-between; align-items: center; }
    .stat-label { font-size: 0.9rem; font-weight: 600; color: #6b7280; }
    .stat-icon { font-size: 1.5rem; }
    .stat-value { font-size: 2.25rem; font-weight: 800; color: #111827; margin-top: 0.5rem; }
    .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
    .status-new { background-color: #fef3c7; color: #92400e; }
    .status-approved { background-color: #dcfce7; color: #166534; }
    .status-rejected { background-color: #fee2e2; color: #991b1b; }
</style>

<div class="min-h-screen bg-gray-100 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-gray-800 to-gray-900 shadow-xl rounded-2xl mb-8 p-6">
            <h1 class="text-3xl font-bold text-white flex items-center">
                <i class="fas fa-tasks ml-3 text-blue-400"></i>
                <?php echo $page_title; ?>
            </h1>
            <p class="text-gray-300 mt-2">تحليل أداء وحالات الطلبات من المتجر الإلكتروني.</p>
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
                <div class="flex-grow">
                    <label for="status" class="block text-sm font-semibold text-gray-700 mb-1">حالة الطلب</label>
                    <select name="status" id="status" class="w-full border-gray-300 rounded-lg shadow-sm">
                        <option value="">كل الحالات</option>
                        <option value="طلب جديد" <?php echo $status_filter == 'طلب جديد' ? 'selected' : ''; ?>>طلب جديد</option>
                        <option value="طلب معتمد" <?php echo $status_filter == 'طلب معتمد' ? 'selected' : ''; ?>>طلب معتمد</option>
                        <option value="مرفوض" <?php echo $status_filter == 'مرفوض' ? 'selected' : ''; ?>>مرفوض</option>
                    </select>
                </div>
                <button type="submit" class="bg-gray-800 hover:bg-gray-700 text-white font-bold py-2 px-6 rounded-lg transition"><i class="fas fa-filter mr-2"></i> عرض التقرير</button>
            </form>
        </div>

        <!-- Summary Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Orders -->
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">إجمالي الطلبات</span>
                    <i class="stat-icon fas fa-receipt text-gray-400"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_orders']); ?></div>
            </div>
            <!-- Approved Orders -->
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">الطلبات المعتمدة</span>
                    <i class="stat-icon fas fa-check-circle text-green-500"></i>
                </div>
                <div class="stat-value text-green-600"><?php echo number_format($stats['approved_orders']); ?></div>
            </div>
            <!-- Rejected Orders -->
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">الطلبات المرفوضة</span>
                    <i class="stat-icon fas fa-times-circle text-red-500"></i>
                </div>
                <div class="stat-value text-red-600"><?php echo number_format($stats['rejected_orders']); ?></div>
            </div>
            <!-- New Orders -->
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">الطلبات الجديدة</span>
                    <i class="stat-icon fas fa-hourglass-half text-yellow-500"></i>
                </div>
                <div class="stat-value text-yellow-600"><?php echo number_format($stats['new_orders']); ?></div>
            </div>
        </div>

        <!-- Financial Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Total Value -->
            <div class="stat-card">
                <div class="stat-header"><span class="stat-label">إجمالي قيمة الطلبات</span><i class="stat-icon fas fa-wallet text-gray-400"></i></div>
                <div class="stat-value"><?php echo number_format($stats['total_value'], 2); ?></div>
            </div>
            <!-- Approved Value -->
            <div class="stat-card">
                <div class="stat-header"><span class="stat-label">قيمة الطلبات المعتمدة</span><i class="stat-icon fas fa-money-check-alt text-green-500"></i></div>
                <div class="stat-value text-green-600"><?php echo number_format($stats['approved_value'], 2); ?></div>
            </div>
            <!-- Approval Rate -->
            <div class="stat-card">
                <div class="stat-header"><span class="stat-label">معدل الموافقة</span><i class="stat-icon fas fa-chart-line text-blue-500"></i></div>
                <div class="stat-value text-blue-600"><?php echo round($stats['approval_rate'], 1); ?>%</div>
            </div>
        </div>


        <!-- Detailed Orders List -->
        <div class="report-card">
            <div class="p-4 border-b">
                <h2 class="text-lg font-bold text-gray-800">قائمة الطلبات التفصيلية (<?php echo count($orders_list); ?>)</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-right">
                    <thead><tr class="bg-gray-50">
                        <th class="p-3 text-sm font-semibold text-gray-600">رقم الطلب</th>
                        <th class="p-3 text-sm font-semibold text-gray-600">العميل</th>
                        <th class="p-3 text-sm font-semibold text-gray-600">تاريخ الطلب</th>
                        <th class="p-3 text-sm font-semibold text-gray-600">المبلغ الإجمالي</th>
                        <th class="p-3 text-sm font-semibold text-gray-600 text-center">الحالة</th>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($orders_list)): ?>
                            <tr><td colspan="5" class="p-6 text-center text-gray-500">لا توجد طلبات تطابق الفلترة المحددة.</td></tr>
                        <?php else: foreach($orders_list as $order): 
                            $status_class = '';
                            if ($order['order_status'] === 'طلب جديد') $status_class = 'status-new';
                            if ($order['order_status'] === 'طلب معتمد') $status_class = 'status-approved';
                            if ($order['order_status'] === 'مرفوض') $status_class = 'status-rejected';
                        ?>
                            <tr class="border-b last:border-b-0 hover:bg-gray-50">
                                <td class="p-3 font-semibold text-gray-800">
                                    <a href="shop_order_view.php?id=<?php echo $order['id']; ?>" class="text-blue-600 hover:underline">
                                        <?php echo htmlspecialchars($order['order_number']); ?>
                                    </a>
                                </td>
                                <td class="p-3 text-gray-700"><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                <td class="p-3 text-sm text-gray-500"><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></td>
                                <td class="p-3 font-bold text-green-700"><?php echo number_format($order['total_amount'], 2); ?></td>
                                <td class="p-3 text-center">
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($order['order_status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>