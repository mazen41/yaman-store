<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'shipping', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

// Get user's permissions
$can_add = hasPermission($_SESSION['user_id'], 'shipping', 'add');
$can_edit = hasPermission($_SESSION['user_id'], 'shipping', 'edit');
$can_delete = hasPermission($_SESSION['user_id'], 'shipping', 'delete'); // Permission for deleting

$page_title = 'إدارة الشحن';

// Handle potential success/error messages from other pages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}


// Fetch shipments with filtering
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sender_filter = $_GET['sender_id'] ?? ''; // Changed from company to sender
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// ===================================================================
// START: MAIN SHIPMENT QUERY
// ===================================================================
$sql = "
    SELECT 
        s.id,
        s.shipment_number,
        s.shipping_cost,
        s.status,
        s.created_at,
        sender.name as sender_name,
        GROUP_CONCAT(DISTINCT COALESCE(co.order_number, sho.order_number) ORDER BY COALESCE(co.created_at, sho.created_at) SEPARATOR ', ') as order_numbers,
        GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') as customer_names
    FROM shipments s
    LEFT JOIN shipment_orders so ON s.id = so.shipment_id
    LEFT JOIN customer_orders co ON so.order_id = co.id AND so.order_source = 'customer'
    LEFT JOIN shop_orders sho ON so.order_id = sho.id AND so.order_source = 'shop'
    LEFT JOIN customers c ON COALESCE(co.customer_id, sho.customer_id) = c.id
    LEFT JOIN senders sender ON s.sender_id = sender.id
    WHERE 1=1
";
$params = [];
$where_clauses = ""; // We'll build a common where clause

if ($search) {
    $where_clauses .= " AND (s.shipment_number LIKE ? OR s.tracking_number LIKE ? OR co.order_number LIKE ? OR sho.order_number LIKE ? OR c.name LIKE ? OR sender.name LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param);
}

if ($status_filter) {
    $where_clauses .= " AND s.status = ?";
    $params[] = $status_filter;
}

if ($sender_filter) {
    $where_clauses .= " AND s.sender_id = ?";
    $params[] = $sender_filter;
}

if ($date_from) {
    $where_clauses .= " AND DATE(s.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_clauses .= " AND DATE(s.created_at) <= ?";
    $params[] = $date_to;
}

// Append the common WHERE clauses to the main query
$sql .= $where_clauses;
$sql .= " GROUP BY s.id ORDER BY s.created_at DESC LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$shipments = $stmt->fetchAll();

// Count total
$count_sql = "
    SELECT COUNT(DISTINCT s.id) 
    FROM shipments s
    LEFT JOIN shipment_orders so ON s.id = so.shipment_id
    LEFT JOIN customer_orders co ON so.order_id = co.id AND so.order_source = 'customer'
    LEFT JOIN shop_orders sho ON so.order_id = sho.id AND so.order_source = 'shop'
    LEFT JOIN customers c ON COALESCE(co.customer_id, sho.customer_id) = c.id
    LEFT JOIN senders sender ON s.sender_id = sender.id
    WHERE 1=1
";
// Append the common WHERE clauses to the count query
$count_sql .= $where_clauses;


$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params); // Use the same params
$total_shipments = $count_stmt->fetchColumn();
$total_pages = ceil($total_shipments / $limit);
// ===================================================================
// END: MAIN SHIPMENT QUERY
// ===================================================================


// ===================================================================
// START: EDITED AND FIXED STATISTICS QUERY
// ===================================================================
// Get statistics based on the same filters
$stats_sql = "
    SELECT 
        COUNT(DISTINCT s.id) as total_shipments,
        SUM(CASE WHEN s.status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN s.status = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
        SUM(CASE WHEN s.status = 'preparing' THEN 1 ELSE 0 END) as preparing,
        SUM(s.shipping_cost) as total_cost
    FROM shipments s
    LEFT JOIN shipment_orders so ON s.id = so.shipment_id
    LEFT JOIN customer_orders co ON so.order_id = co.id AND so.order_source = 'customer'
    LEFT JOIN shop_orders sho ON so.order_id = sho.id AND so.order_source = 'shop'
    LEFT JOIN customers c ON COALESCE(co.customer_id, sho.customer_id) = c.id
    LEFT JOIN senders sender ON s.sender_id = sender.id
    WHERE 1=1
";
// Append the common WHERE clauses to the statistics query
$stats_sql .= $where_clauses;

$stats_stmt = $db->prepare($stats_sql);
$stats_stmt->execute($params); // Use the same params
$stats = $stats_stmt->fetch();

// Ensure stats are not null if no results are found
$stats['total_shipments'] = $stats['total_shipments'] ?? 0;
$stats['delivered'] = $stats['delivered'] ?? 0;
$stats['in_transit'] = $stats['in_transit'] ?? 0;
$stats['preparing'] = $stats['preparing'] ?? 0;
$stats['total_cost'] = $stats['total_cost'] ?? 0.0;
// ===================================================================
// END: EDITED AND FIXED STATISTICS QUERY
// ===================================================================

// Fetch senders for the filter dropdown
$senders = $db->query("SELECT id, name FROM senders ORDER BY name ASC")->fetchAll();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-2">
                            <i class="fas fa-shipping-fast mr-3"></i>
                            إدارة الشحن
                        </h1>
                        <p class="text-blue-100">إدارة وتتبع جميع الشحنات</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="senders.php"
                            class="bg-purple-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-purple-700 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                            <i class="fas fa-user-tie ml-2"></i>
                            إدارة المرسلين
                        </a>
                        <?php if ($can_add): ?>
                            <a href="add.php"
                                class="bg-white text-blue-600 px-6 py-3 rounded-lg font-bold hover:bg-blue-50 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                                <i class="fas fa-plus-circle ml-2"></i>
                                إضافة شحنة جديدة
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border-r-4 border-green-500 text-green-700 p-4 rounded-lg mb-6 shadow-md">
                <p class="font-medium"><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
             <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md">
                <p class="font-medium"><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
             <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">إجمالي الشحنات</p>
                        <p class="text-3xl font-bold text-gray-800">
                            <?php echo number_format($stats['total_shipments']); ?></p>
                    </div>
                    <div class="bg-blue-100 p-4 rounded-full">
                        <i class="fas fa-box text-3xl text-blue-600"></i>
                    </div>
                </div>
            </div>
             <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">تم التسليم</p>
                        <p class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['delivered']); ?>
                        </p>
                    </div>
                    <div class="bg-green-100 p-4 rounded-full">
                        <i class="fas fa-check-circle text-3xl text-green-600"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-yellow-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">قيد النقل</p>
                        <p class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['in_transit']); ?>
                        </p>
                    </div>
                    <div class="bg-yellow-100 p-4 rounded-full">
                        <i class="fas fa-truck text-3xl text-yellow-600"></i>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-red-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">إجمالي التكلفة</p>
                        <p class="text-3xl font-bold text-gray-800">
                            <?php echo number_format($stats['total_cost'], 2); ?></p>
                        <p class="text-xs text-gray-500 mt-1">ريال</p>
                    </div>
                    <div class="bg-red-100 p-4 rounded-full">
                        <i class="fas fa-money-bill-wave text-3xl text-red-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <form method="GET" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-search ml-1 text-blue-600"></i>
                            البحث
                        </label>
                        <input type="text" name="search" placeholder="رقم الشحنة، رقم الطلب، اسم العميل..."
                            value="<?php echo htmlspecialchars($search); ?>"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">الحالة</label>
                        <select name="status"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">جميع الحالات</option>
                            <option value="preparing" <?php echo $status_filter == 'preparing' ? 'selected' : ''; ?>>قيد التجهيز</option>
                            <option value="picked_up" <?php echo $status_filter == 'picked_up' ? 'selected' : ''; ?>>تم الاستلام</option>
                            <option value="in_transit" <?php echo $status_filter == 'in_transit' ? 'selected' : ''; ?>>في الطريق</option>
                            <option value="out_for_delivery" <?php echo $status_filter == 'out_for_delivery' ? 'selected' : ''; ?>>خرج للتوصيل</option>
                            <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>تم التسليم</option>
                            <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>ملغي</option>
                            <option value="returned" <?php echo $status_filter == 'returned' ? 'selected' : ''; ?>>مرتجع</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">المرسل</label>
                        <select name="sender_id"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">جميع المرسلين</option>
                            <?php foreach ($senders as $sender): ?>
                                <option value="<?php echo $sender['id']; ?>" <?php echo $sender_filter == $sender['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sender['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">من تاريخ</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-all duration-300 shadow-md hover:shadow-lg">
                        <i class="fas fa-search ml-2"></i>
                        بحث
                    </button>
                    <a href="index.php" class="text-gray-600 hover:text-gray-800">
                        <i class="fas fa-redo ml-1"></i>
                        إعادة تعيين
                    </a>
                </div>
            </form>
        </div>

        <!-- Shipments Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gradient-to-r from-blue-50 to-indigo-50">
                        <tr>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">رقم الشحنة</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">ارقام الطلبات</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">أسماء العملاء</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">اسم الموصل</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">تكلفة الشحن</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">الحالة</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($shipments)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        <i class="fas fa-shipping-fast text-6xl text-gray-300 mb-4"></i>
                                        <p class="text-gray-500 text-lg font-medium">لا توجد شحنات تطابق بحثك</p>
                                        <p class="text-gray-400 text-sm mt-2">حاول تعديل الفلاتر أو إضافة شحنة جديدة</p>
                                        <?php if ($can_add): ?>
                                            <a href="add.php"
                                                class="mt-4 bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-all">
                                                <i class="fas fa-plus ml-2"></i>
                                                إضافة شحنة
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($shipments as $shipment): ?>
                                <tr class="hover:bg-gray-50 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <a href="view.php?id=<?php echo $shipment['id']; ?>" class="font-bold text-blue-600 hover:text-blue-800">
                                            <?php echo htmlspecialchars($shipment['shipment_number']); ?>
                                        </a>
                                        <div class="text-xs text-gray-500"><?php echo date('Y-m-d', strtotime($shipment['created_at'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars($shipment['order_numbers'] ?: '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars($shipment['customer_names'] ?: '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800">
                                        <?php echo htmlspecialchars($shipment['sender_name'] ?: '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-lg font-bold text-green-600"><?php echo number_format($shipment['shipping_cost'], 2); ?></span>
                                        <span class="text-xs text-gray-500">ريال</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status_badges = [
                                            'preparing' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">قيد التجهيز</span>',
                                            'picked_up' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-800">تم الاستلام</span>',
                                            'in_transit' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">في الطريق</span>',
                                            'out_for_delivery' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800">خرج للتوصيل</span>',
                                            'delivered' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">تم التسليم</span>',
                                            'cancelled' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">ملغي</span>',
                                            'returned' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">مرتجع</span>'
                                        ];
                                        echo $status_badges[$shipment['status']] ?? htmlspecialchars($shipment['status']);
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex items-center gap-4">
                                            <a href="view.php?id=<?php echo $shipment['id']; ?>" class="text-blue-600 hover:text-blue-800 transition-colors" title="عرض التفاصيل"><i class="fas fa-eye text-lg"></i></a>
                                            <?php if ($can_edit): ?>
                                                <a href="edit.php?id=<?php echo $shipment['id']; ?>" class="text-yellow-600 hover:text-yellow-800 transition-colors" title="تعديل"><i class="fas fa-edit text-lg"></i></a>
                                            <?php endif; ?>
                                            <a href="print.php?id=<?php echo $shipment['id']; ?>" target="_blank" class="text-purple-600 hover:text-purple-800 transition-colors" title="طباعة"><i class="fas fa-print text-lg"></i></a>
                                            <?php if ($can_edit): ?>
                                                <form method="POST" action="delete.php" onsubmit="return confirm('هل أنت متأكد من حذف هذه الشحنة؟ لا يمكن التراجع عن هذا الإجراء.');">
                                                    <input type="hidden" name="shipment_id" value="<?php echo $shipment['id']; ?>">
                                                    <button type="submit" class="text-red-600 hover:text-red-800 transition-colors" title="حذف">
                                                        <i class="fas fa-trash-alt text-lg"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            عرض <span class="font-medium"><?php echo $offset + 1; ?></span> إلى
                            <span class="font-medium"><?php echo min($offset + $limit, $total_shipments); ?></span> من
                            <span class="font-medium"><?php echo $total_shipments; ?></span> شحنة
                        </div>
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <?php 
                                $url_params = "&search=" . urlencode($search) . "&status=" . $status_filter . "&sender_id=" . $sender_filter . "&date_from=" . $date_from . "&date_to=" . $date_to;
                            ?>
                             <a href="?page=<?php echo max(1, $page - 1) . $url_params; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                السابق
                            </a>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i . $url_params; ?>" class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?php echo $i == $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            <a href="?page=<?php echo min($total_pages, $page + 1) . $url_params; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                التالي
                            </a>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>