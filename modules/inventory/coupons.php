<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'كوبونات المخزون';

// Fetch inventory-related coupons and statistics
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$sql = "SELECT * FROM coupons WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (coupon_code LIKE ? OR coupon_name LIKE ? OR description LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param, $search_param];
}

if ($status_filter) {
    if ($status_filter == 'active') {
        $sql .= " AND is_active = 1 AND start_date <= CURDATE() AND end_date >= CURDATE()";
    } elseif ($status_filter == 'expired') {
        $sql .= " AND end_date < CURDATE()";
    } elseif ($status_filter == 'inactive') {
        $sql .= " AND is_active = 0";
    }
}

$sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$coupons = $stmt->fetchAll();

// Count total coupons for pagination
$count_sql = "SELECT COUNT(*) FROM coupons WHERE 1=1";
$count_params = [];

if ($search) {
    $count_sql .= " AND (coupon_code LIKE ? OR coupon_name LIKE ? OR description LIKE ?)";
    $count_params = [$search_param, $search_param, $search_param];
}

if ($status_filter) {
    if ($status_filter == 'active') {
        $count_sql .= " AND is_active = 1 AND start_date <= CURDATE() AND end_date >= CURDATE()";
    } elseif ($status_filter == 'expired') {
        $count_sql .= " AND end_date < CURDATE()";
    } elseif ($status_filter == 'inactive') {
        $count_sql .= " AND is_active = 0";
    }
}

$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($count_params);
$total_coupons = $count_stmt->fetchColumn();
$total_pages = ceil($total_coupons / $limit);

// Get coupon statistics
$stats_stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_coupons,
        SUM(CASE WHEN is_active = 1 AND start_date <= CURDATE() AND end_date >= CURDATE() THEN 1 ELSE 0 END) as active_coupons,
        SUM(CASE WHEN end_date < CURDATE() THEN 1 ELSE 0 END) as expired_coupons,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_coupons,
        SUM(used_count) as total_usage
    FROM coupons
");
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">كوبونات المخزون</h1>
                        <p class="text-gray-600 mt-1">إدارة كوبونات الخصم المتعلقة بالمنتجات والمخزون</p>
                    </div>
                    <div class="mt-4 sm:mt-0 flex flex-wrap gap-3">
                        <a href="../coupons/add.php" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة كوبون جديد
                        </a>
                        <a href="../coupons/index.php" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors duration-200">
                            <i class="fas fa-tags ml-2"></i>
                            جميع الكوبونات
                        </a>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للمخزون
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Search and filters -->
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-col sm:flex-row gap-4">
                    <div class="flex-1 relative">
                        <span class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                            <i class="fas fa-search"></i>
                        </span>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="البحث في الكوبونات..." 
                            value="<?php echo htmlspecialchars($search); ?>"
                            class="w-full px-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent shadow-sm transition-all duration-200"
                        >
                    </div>
                    <div>
                        <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent shadow-sm transition-all duration-200 bg-white">
                            <option value="">جميع الحالات</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>نشط</option>
                            <option value="expired" <?php echo $status_filter == 'expired' ? 'selected' : ''; ?>>منتهي الصلاحية</option>
                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>غير نشط</option>
                        </select>
                    </div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors duration-200">
                        <i class="fas fa-filter ml-2"></i>بحث
                    </button>
                    <?php if ($search || $status_filter): ?>
                    <a href="coupons.php" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200">
                        <i class="fas fa-times ml-2"></i>إلغاء
                    </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white overflow-hidden shadow-lg rounded-lg border-t-4 border-purple-500">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center">
                            <i class="fas fa-tags text-xl text-purple-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">إجمالي الكوبونات</dt>
                                <dd class="text-xl font-bold text-gray-900"><?php echo $stats['total_coupons']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow-lg rounded-lg border-t-4 border-amber-500">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center">
                            <i class="fas fa-check-circle text-xl text-amber-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">كوبونات نشطة</dt>
                                <dd class="text-xl font-bold text-gray-900"><?php echo $stats['active_coupons']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow-lg rounded-lg border-t-4 border-red-500">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                            <i class="fas fa-clock text-xl text-red-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">منتهية الصلاحية</dt>
                                <dd class="text-xl font-bold text-gray-900"><?php echo $stats['expired_coupons']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow-lg rounded-lg border-t-4 border-blue-500">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-chart-line text-xl text-blue-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">إجمالي الاستخدام</dt>
                                <dd class="text-xl font-bold text-gray-900"><?php echo $stats['total_usage']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Coupons Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">كود الكوبون</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">اسم الكوبون</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نوع الخصم</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">قيمة الخصم</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحد الأدنى للطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الاستخدام</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الانتهاء</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($coupons)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-tags text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد كوبونات</p>
                                <a href="../coupons/add.php" class="text-purple-600 hover:text-purple-800 mt-2 inline-block">
                                    إضافة كوبون جديد
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($coupons as $coupon): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <div class="flex items-center">
                                    <span class="bg-purple-100 text-purple-800 px-2 py-1 rounded-full text-xs font-medium">
                                        <?php echo htmlspecialchars($coupon['coupon_code']); ?>
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div>
                                    <div class="font-medium"><?php echo htmlspecialchars($coupon['coupon_name']); ?></div>
                                    <div class="text-gray-500 text-xs"><?php echo htmlspecialchars($coupon['description']); ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo $coupon['discount_type'] == 'percentage' ? 'نسبة مئوية' : 'مبلغ ثابت'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php 
                                if ($coupon['discount_type'] == 'percentage') {
                                    echo $coupon['discount_value'] . '%';
                                } else {
                                    echo number_format($coupon['discount_value'], 2) . ' ر.س';
                                }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo number_format($coupon['min_order_amount'], 2); ?> ر.س
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div class="text-center">
                                    <div class="font-medium"><?php echo $coupon['used_count']; ?></div>
                                    <?php if ($coupon['usage_limit']): ?>
                                    <div class="text-xs text-gray-500">من <?php echo $coupon['usage_limit']; ?></div>
                                    <?php else: ?>
                                    <div class="text-xs text-gray-500">غير محدود</div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('Y-m-d', strtotime($coupon['end_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php
                                $status_class = '';
                                $status_text = '';
                                
                                if (!$coupon['is_active']) {
                                    $status_class = 'bg-gray-100 text-gray-800';
                                    $status_text = 'غير نشط';
                                } elseif (strtotime($coupon['end_date']) < time()) {
                                    $status_class = 'bg-red-100 text-red-800';
                                    $status_text = 'منتهي الصلاحية';
                                } elseif (strtotime($coupon['start_date']) > time()) {
                                    $status_class = 'bg-yellow-100 text-yellow-800';
                                    $status_text = 'لم يبدأ بعد';
                                } else {
                                    $status_class = 'bg-amber-100 text-amber-800';
                                    $status_text = 'نشط';
                                }
                                ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2 space-x-reverse">
                                    <a href="../coupons/view.php?id=<?php echo $coupon['id']; ?>" 
                                       class="w-8 h-8 flex items-center justify-center bg-blue-100 text-blue-600 rounded-full hover:bg-blue-200 transition-all duration-200 transform hover:scale-110" 
                                       title="عرض">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../coupons/edit.php?id=<?php echo $coupon['id']; ?>" 
                                       class="w-8 h-8 flex items-center justify-center bg-amber-100 text-amber-600 rounded-full hover:bg-amber-200 transition-all duration-200 transform hover:scale-110" 
                                       title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </a>
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
            <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-gray-700">
                            عرض
                            <span class="font-medium"><?php echo $offset + 1; ?></span>
                            إلى
                            <span class="font-medium"><?php echo min($offset + $limit, $total_coupons); ?></span>
                            من
                            <span class="font-medium"><?php echo $total_coupons; ?></span>
                            كوبون
                        </p>
                    </div>
                    <div>
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?php echo $i == $page ? 'z-10 bg-purple-50 border-purple-500 text-purple-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                        </nav>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
