<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة المشتريات';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    try {
        $stmt = $db->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $success_message = 'تم إلغاء طلب الشراء بنجاح';
    } catch (PDOException $e) {
        $error_message = 'حدث خطأ أثناء الإلغاء';
    }
}

// Fetch purchase orders with advanced filtering
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$supplier_filter = $_GET['supplier_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Check which columns exist
$columns_check = $db->query("DESCRIBE purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
$has_purchase_date = in_array('purchase_date', $columns_check);
$has_purchase_basket_number = in_array('purchase_basket_number', $columns_check);
$has_account_number = in_array('account_number', $columns_check);
$has_purchase_group_id = in_array('purchase_group_id', $columns_check);

// Build dynamic SELECT
$select_fields = "po.*, s.name as supplier_name, s.phone as supplier_phone, u.full_name as created_by_name";
if ($has_purchase_group_id) {
    $select_fields .= ", pg.group_name";
}

$sql = "SELECT $select_fields
        FROM purchase_orders po 
        LEFT JOIN suppliers s ON po.supplier_id = s.id 
        LEFT JOIN users u ON po.created_by = u.id";

if ($has_purchase_group_id) {
    $sql .= " LEFT JOIN purchase_groups pg ON po.purchase_group_id = pg.id";
}

$sql .= " WHERE 1=1";
$params = [];

if ($search) {
    $search_conditions = ["po.order_number LIKE ?", "s.name LIKE ?"];
    if ($has_purchase_basket_number) {
        $search_conditions[] = "po.purchase_basket_number LIKE ?";
    }
    if ($has_account_number) {
        $search_conditions[] = "po.account_number LIKE ?";
    }
    
    $sql .= " AND (" . implode(" OR ", $search_conditions) . ")";
    $search_param = "%$search%";
    foreach ($search_conditions as $condition) {
        $params[] = $search_param;
    }
}

if ($status_filter) {
    $sql .= " AND po.status = ?";
    $params[] = $status_filter;
}

if ($supplier_filter) {
    $sql .= " AND po.supplier_id = ?";
    $params[] = $supplier_filter;
}

if ($date_from) {
    $date_column = $has_purchase_date ? 'po.purchase_date' : 'po.created_at';
    $sql .= " AND $date_column >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $date_column = $has_purchase_date ? 'po.purchase_date' : 'po.created_at';
    $sql .= " AND $date_column <= ?";
    $params[] = $date_to;
}

// Validate sort column
$allowed_sort = ['order_number', 'created_at', 'total_amount', 'status', 'supplier_name'];
if ($has_purchase_date) {
    $allowed_sort[] = 'purchase_date';
}
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'created_at';
}

$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
$sql .= " ORDER BY $sort_by $sort_order LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$purchase_orders = $stmt->fetchAll();

// Count total orders for pagination
$count_sql = "SELECT COUNT(*) FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id WHERE 1=1";
$count_params = [];

if ($search) {
    $search_conditions = ["po.order_number LIKE ?", "s.name LIKE ?"];
    if ($has_purchase_basket_number) {
        $search_conditions[] = "po.purchase_basket_number LIKE ?";
    }
    if ($has_account_number) {
        $search_conditions[] = "po.account_number LIKE ?";
    }
    
    $count_sql .= " AND (" . implode(" OR ", $search_conditions) . ")";
    foreach ($search_conditions as $condition) {
        $count_params[] = $search_param;
    }
}

if ($status_filter) {
    $count_sql .= " AND po.status = ?";
    $count_params[] = $status_filter;
}

if ($supplier_filter) {
    $count_sql .= " AND po.supplier_id = ?";
    $count_params[] = $supplier_filter;
}

if ($date_from) {
    $date_column = $has_purchase_date ? 'po.purchase_date' : 'po.created_at';
    $count_sql .= " AND $date_column >= ?";
    $count_params[] = $date_from;
}

if ($date_to) {
    $date_column = $has_purchase_date ? 'po.purchase_date' : 'po.created_at';
    $count_sql .= " AND $date_column <= ?";
    $count_params[] = $date_to;
}

$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($count_params);
$total_orders = $count_stmt->fetchColumn();
$total_pages = ceil($total_orders / $limit);

// Fetch suppliers for filter
$suppliers_stmt = $db->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">إدارة المشتريات</h1>
                        <p class="text-gray-600 mt-1">إدارة طلبات الشراء والموردين</p>
                    </div>
                    <div class="mt-4 sm:mt-0 flex flex-wrap gap-3">
                        <a href="add.php" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            طلب شراء جديد
                        </a>
                        <a href="suppliers.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-truck ml-2"></i>
                            إدارة الموردين
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Additional Action Buttons -->
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                <div class="flex flex-wrap gap-3 justify-center">
                    <a href="basket.php" class="inline-flex items-center px-6 py-3 bg-amber-500 text-white rounded-lg hover:bg-amber-600 transition-all duration-200 shadow-md hover:shadow-lg transform hover:scale-105">
                        <i class="fas fa-shopping-basket ml-2"></i>
                        <span class="font-medium">سلة المشتريات</span>
                    </a>
                    <a href="analytics.php" class="inline-flex items-center px-6 py-3 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition-all duration-200 shadow-md hover:shadow-lg transform hover:scale-105">
                        <i class="fas fa-chart-bar ml-2"></i>
                        <span class="font-medium">التحليلات</span>
                    </a>
                    <a href="tracking.php" class="inline-flex items-center px-6 py-3 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-all duration-200 shadow-md hover:shadow-lg transform hover:scale-105">
                        <i class="fas fa-truck ml-2"></i>
                        <span class="font-medium">التتبع</span>
                    </a>
                    <a href="approvals.php" class="inline-flex items-center px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-all duration-200 shadow-md hover:shadow-lg transform hover:scale-105">
                        <i class="fas fa-check-circle ml-2"></i>
                        <span class="font-medium">الموافقات</span>
                    </a>
                </div>
            </div>
            
            <!-- Advanced Search and Filters -->
            <div class="px-6 py-4 bg-gray-50">
                <form method="GET" id="filterForm">
                    <!-- Main Search Row -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">البحث</label>
                            <input 
                                type="text" 
                                name="search" 
                                placeholder="رقم الطلب، المورد، رقم السلة، رقم الحساب..." 
                                value="<?php echo htmlspecialchars($search); ?>"
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500 transition-all"
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                            <select name="status" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500">
                                <option value="">جميع الحالات</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                                <option value="ordered" <?php echo $status_filter == 'ordered' ? 'selected' : ''; ?>>تم الطلب</option>
                                <option value="received" <?php echo $status_filter == 'received' ? 'selected' : ''; ?>>تم الاستلام</option>
                                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>ملغي</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">المورد</label>
                            <select name="supplier_id" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500">
                                <option value="">جميع الموردين</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Date Range and Sort Row -->
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">من تاريخ</label>
                            <input 
                                type="date" 
                                name="date_from" 
                                value="<?php echo htmlspecialchars($date_from); ?>"
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500"
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">إلى تاريخ</label>
                            <input 
                                type="date" 
                                name="date_to" 
                                value="<?php echo htmlspecialchars($date_to); ?>"
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500"
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ترتيب حسب</label>
                            <select name="sort_by" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500">
                                <option value="created_at" <?php echo $sort_by == 'created_at' ? 'selected' : ''; ?>>تاريخ الإنشاء</option>
                                <?php if ($has_purchase_date): ?>
                                <option value="purchase_date" <?php echo $sort_by == 'purchase_date' ? 'selected' : ''; ?>>تاريخ الشراء</option>
                                <?php endif; ?>
                                <option value="order_number" <?php echo $sort_by == 'order_number' ? 'selected' : ''; ?>>رقم الطلب</option>
                                <option value="total_amount" <?php echo $sort_by == 'total_amount' ? 'selected' : ''; ?>>المبلغ</option>
                                <option value="status" <?php echo $sort_by == 'status' ? 'selected' : ''; ?>>الحالة</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">الترتيب</label>
                            <select name="sort_order" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500">
                                <option value="DESC" <?php echo $sort_order == 'DESC' ? 'selected' : ''; ?>>تنازلي</option>
                                <option value="ASC" <?php echo $sort_order == 'ASC' ? 'selected' : ''; ?>>تصاعدي</option>
                            </select>
                        </div>
                        
                        <div class="flex items-end gap-2">
                            <button type="submit" class="flex-1 px-6 py-2 bg-gradient-to-r from-amber-600 to-amber-700 text-white rounded-lg hover:from-amber-700 hover:to-amber-800 transition-all shadow-md font-semibold">
                                <i class="fas fa-search ml-2"></i>بحث
                            </button>
                            <?php if ($search || $status_filter || $supplier_filter || $date_from || $date_to): ?>
                            <a href="index.php" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-all shadow-md" title="إعادة تعيين">
                                <i class="fas fa-redo"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($success_message)): ?>
        <div class="bg-amber-100 border-2 border-amber-400 text-amber-700 px-6 py-4 rounded-xl mb-6 shadow-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle ml-3 text-2xl"></i>
                <span class="font-semibold"><?php echo $success_message; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border-2 border-red-400 text-red-700 px-6 py-4 rounded-xl mb-6 shadow-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle ml-3 text-2xl"></i>
                <span class="font-semibold"><?php echo $error_message; ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Active Filters Summary -->
        <?php if ($search || $status_filter || $supplier_filter || $date_from || $date_to): ?>
        <div class="bg-blue-50 border-2 border-blue-200 rounded-xl p-4 mb-6 shadow-md">
            <div class="flex items-center justify-between">
                <div class="flex items-center flex-wrap gap-2">
                    <span class="font-semibold text-blue-900">
                        <i class="fas fa-filter ml-2"></i>
                        الفلاتر النشطة:
                    </span>
                    <?php if ($search): ?>
                    <span class="px-3 py-1 bg-blue-200 text-blue-800 rounded-full text-sm font-medium">
                        <i class="fas fa-search ml-1"></i> <?php echo htmlspecialchars($search); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($status_filter): ?>
                    <span class="px-3 py-1 bg-purple-200 text-purple-800 rounded-full text-sm font-medium">
                        <i class="fas fa-flag ml-1"></i> <?php echo $status_labels[$status_filter] ?? $status_filter; ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($supplier_filter): ?>
                    <span class="px-3 py-1 bg-amber-200 text-amber-800 rounded-full text-sm font-medium">
                        <i class="fas fa-truck ml-1"></i> مورد محدد
                    </span>
                    <?php endif; ?>
                    <?php if ($date_from || $date_to): ?>
                    <span class="px-3 py-1 bg-orange-200 text-orange-800 rounded-full text-sm font-medium">
                        <i class="fas fa-calendar ml-1"></i>
                        <?php echo $date_from ? date('Y/m/d', strtotime($date_from)) : '...'; ?> 
                        - 
                        <?php echo $date_to ? date('Y/m/d', strtotime($date_to)) : '...'; ?>
                    </span>
                    <?php endif; ?>
                    <span class="text-sm text-gray-600">
                        (<?php echo $total_orders; ?> نتيجة)
                    </span>
                </div>
                <a href="index.php" class="text-blue-600 hover:text-blue-800 font-semibold text-sm">
                    <i class="fas fa-times-circle ml-1"></i>
                    إزالة الفلاتر
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Purchase Orders Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">رقم الطلب</th>
                            <?php if ($has_purchase_basket_number): ?>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">رقم السلة</th>
                            <?php endif; ?>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">المورد</th>
                            <?php if ($has_purchase_date): ?>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">تاريخ الشراء</th>
                            <?php endif; ?>
                            <?php if ($has_account_number): ?>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">رقم الحساب</th>
                            <?php endif; ?>
                            <?php if ($has_purchase_group_id): ?>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">المجموعة</th>
                            <?php endif; ?>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">المبلغ الإجمالي</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">الحالة</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">أنشئ بواسطة</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase tracking-wider">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($purchase_orders)): ?>
                        <?php 
                        // Calculate colspan dynamically
                        $colspan = 5; // base columns: order_number, supplier, total, status, created_by, actions
                        if ($has_purchase_basket_number) $colspan++;
                        if ($has_purchase_date) $colspan++;
                        if ($has_account_number) $colspan++;
                        if ($has_purchase_group_id) $colspan++;
                        ?>
                        <tr>
                            <td colspan="<?php echo $colspan; ?>" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-shopping-cart text-6xl mb-4 text-gray-300"></i>
                                    <p class="text-lg font-semibold text-gray-600 mb-2">لا توجد طلبات شراء</p>
                                    <?php if ($search || $status_filter || $supplier_filter || $date_from || $date_to): ?>
                                    <p class="text-sm text-gray-500 mb-4">لم يتم العثور على نتائج مطابقة للفلاتر المحددة</p>
                                    <a href="index.php" class="text-blue-600 hover:text-blue-800 font-semibold">
                                        <i class="fas fa-redo ml-1"></i>
                                        إعادة تعيين الفلاتر
                                    </a>
                                    <?php else: ?>
                                    <a href="add.php" class="inline-flex items-center px-6 py-3 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-all shadow-md mt-4">
                                        <i class="fas fa-plus ml-2"></i>
                                        إضافة طلب شراء جديد
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($purchase_orders as $order): ?>
                        <tr class="hover:bg-blue-50 transition-colors">
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-blue-600">
                                    <?php echo htmlspecialchars($order['order_number']); ?>
                                </div>
                            </td>
                            
                            <?php if ($has_purchase_basket_number): ?>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?php echo htmlspecialchars($order['purchase_basket_number'] ?? '-'); ?>
                            </td>
                            <?php endif; ?>
                            
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">
                                    <?php echo htmlspecialchars($order['supplier_name'] ?? 'غير محدد'); ?>
                                </div>
                                <?php if (!empty($order['supplier_phone'])): ?>
                                <div class="text-xs text-gray-500">
                                    <i class="fas fa-phone text-amber-600"></i> <?php echo htmlspecialchars($order['supplier_phone']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            
                            <?php if ($has_purchase_date): ?>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?php echo $order['purchase_date'] ? date('Y/m/d', strtotime($order['purchase_date'])) : '-'; ?>
                            </td>
                            <?php endif; ?>
                            
                            <?php if ($has_account_number): ?>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?php echo htmlspecialchars($order['account_number'] ?? '-'); ?>
                            </td>
                            <?php endif; ?>
                            
                            <?php if ($has_purchase_group_id): ?>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?php echo htmlspecialchars($order['group_name'] ?? '-'); ?>
                            </td>
                            <?php endif; ?>
                            
                            <td class="px-4 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-amber-700">
                                    <?php echo number_format($order['total_amount'], 0, '', ''); ?> ر.ي
                                </div>
                                <div class="text-xs text-gray-500">
                                    قبل الضريبة: <?php echo number_format($order['subtotal'], 0, '', ''); ?>
                                </div>
                            </td>
                            
                            <td class="px-4 py-4 whitespace-nowrap text-sm">
                                <?php
                                $status_colors = [
                                    'pending' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                                    'ordered' => 'bg-blue-100 text-blue-800 border-blue-300',
                                    'received' => 'bg-amber-100 text-amber-800 border-amber-300',
                                    'cancelled' => 'bg-red-100 text-red-800 border-red-300'
                                ];
                                $status_labels = [
                                    'pending' => 'قيد الانتظار',
                                    'ordered' => 'تم الطلب',
                                    'received' => 'تم الاستلام',
                                    'cancelled' => 'ملغي'
                                ];
                                $status_icons = [
                                    'pending' => 'fa-clock',
                                    'ordered' => 'fa-shopping-cart',
                                    'received' => 'fa-check-circle',
                                    'cancelled' => 'fa-times-circle'
                                ];
                                ?>
                                <span class="px-3 py-1 inline-flex items-center text-xs leading-5 font-bold rounded-full border-2 <?php echo $status_colors[$order['status']]; ?>">
                                    <i class="fas <?php echo $status_icons[$order['status']]; ?> ml-1"></i>
                                    <?php echo $status_labels[$order['status']]; ?>
                                </span>
                            </td>
                            
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">
                                <div class="flex items-center">
                                    <i class="fas fa-user-circle text-gray-400 ml-2"></i>
                                    <?php echo htmlspecialchars($order['created_by_name']); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex items-center gap-2 justify-center">
                                    <!-- View Button -->
                                    <a href="view.php?id=<?php echo $order['id']; ?>" 
                                       class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-600 hover:bg-blue-600 hover:text-white transition-all duration-200" 
                                       title="عرض">
                                        <i class="fas fa-eye text-sm"></i>
                                    </a>
                                    
                                    <!-- Edit Button (only for pending) -->
                                    <?php if ($order['status'] == 'pending'): ?>
                                    <a href="edit.php?id=<?php echo $order['id']; ?>" 
                                       class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-amber-100 text-amber-600 hover:bg-amber-600 hover:text-white transition-all duration-200" 
                                       title="تعديل">
                                        <i class="fas fa-edit text-sm"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <!-- Delete/Cancel Button (only for pending) -->
                                    <?php if ($order['status'] == 'pending'): ?>
                                    <a href="?action=delete&id=<?php echo $order['id']; ?>" 
                                       class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-red-100 text-red-600 hover:bg-red-600 hover:text-white transition-all duration-200" 
                                       title="إلغاء"
                                       onclick="return confirm('هل أنت متأكد من إلغاء طلب الشراء؟')">
                                        <i class="fas fa-times text-sm"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <!-- Print Button -->
                                    <a href="print.php?id=<?php echo $order['id']; ?>" 
                                       class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-100 text-purple-600 hover:bg-purple-600 hover:text-white transition-all duration-200" 
                                       title="طباعة" 
                                       target="_blank">
                                        <i class="fas fa-print text-sm"></i>
                                    </a>
                                    
                                    <!-- Download Button -->
                                    <a href="download.php?id=<?php echo $order['id']; ?>" 
                                       class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all duration-200" 
                                       title="تحميل">
                                        <i class="fas fa-download text-sm"></i>
                                    </a>
                                    
                                    <!-- Share/Link Button -->
                                    <button onclick="copyOrderLink(<?php echo $order['id']; ?>)" 
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-teal-100 text-teal-600 hover:bg-teal-600 hover:text-white transition-all duration-200" 
                                            title="نسخ الرابط">
                                        <i class="fas fa-link text-sm"></i>
                                    </button>
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
                <div class="flex-1 flex justify-between sm:hidden">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        السابق
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" class="mr-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        التالي
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-gray-700">
                            عرض
                            <span class="font-medium"><?php echo $offset + 1; ?></span>
                            إلى
                            <span class="font-medium"><?php echo min($offset + $limit, $total_orders); ?></span>
                            من
                            <span class="font-medium"><?php echo $total_orders; ?></span>
                            طلب
                        </p>
                    </div>
                    <div>
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?php echo $i == $page ? 'z-10 bg-amber-50 border-amber-500 text-amber-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                        </nav>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Summary Cards -->
        <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-6">
            <?php
            // Get statistics
            $stats_stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                    SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as completed_orders,
                    SUM(total_amount) as total_amount
                FROM purchase_orders
            ");
            $stats_stmt->execute();
            $stats = $stats_stmt->fetch();
            ?>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-shopping-cart text-2xl text-blue-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">إجمالي الطلبات</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_orders']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-clock text-2xl text-yellow-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">قيد الانتظار</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['pending_orders']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-2xl text-amber-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">مكتملة</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['completed_orders']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-money-bill-wave text-2xl text-purple-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">إجمالي المبلغ</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo number_format($stats['total_amount'], 0, '', ''); ?> ريال</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyOrderLink(orderId) {
    const url = window.location.origin + window.location.pathname.replace('index.php', 'view.php') + '?id=' + orderId;
    
    // Create temporary input element
    const tempInput = document.createElement('input');
    tempInput.value = url;
    document.body.appendChild(tempInput);
    tempInput.select();
    
    try {
        document.execCommand('copy');
        // Show success message
        alert('تم نسخ رابط الطلب بنجاح!');
    } catch (err) {
        alert('فشل نسخ الرابط');
    }
    
    document.body.removeChild(tempInput);
}
</script>

<?php include '../../includes/footer.php'; ?>
