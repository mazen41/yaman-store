<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

// Initialize permissions
$can_view = false;
$can_add = false;
$can_edit = false;

if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    // Assuming 'products' is the permission key for this module
    $can_view = hasPermission($_SESSION['user_id'], 'products', 'view');
    $can_add = hasPermission($_SESSION['user_id'], 'products', 'add');
    $can_edit = hasPermission($_SESSION['user_id'], 'products', 'edit');
}

if (!$can_view) {
    $error_message = 'ليس لديك صلاحية لعرض قائمة المنتجات.';
}

$page_title = 'إدارة المنتجات';

// --- Handle Actions (Toggle Status / Delete) ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    if (!$can_edit) {
        $error_message = 'ليس لديك صلاحية لتنفيذ هذا الإجراء';
    } else {
        $product_id = $_GET['id'];
        try {
            if ($_GET['action'] == 'toggle_active') {
                $current_status = $_GET['status'] ?? 0;
                $new_status = $current_status == 1 ? 0 : 1;
                $stmt = $db->prepare("UPDATE products SET is_active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $product_id]);
                $success_message = $new_status == 1 ? 'تم تفعيل المنتج بنجاح.' : 'تم تعطيل المنتج بنجاح.';
            } elseif ($_GET['action'] == 'delete') {
                // Soft delete: set is_active to 0
                $stmt = $db->prepare("UPDATE products SET is_active = 0, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$product_id]);
                $success_message = 'تم حذف المنتج بنجاح (أصبح غير نشط).';
            }
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء تنفيذ الإجراء: ' . $e->getMessage();
        }
    }
}

// --- Fetch data for filters ---
$categories = [];
try {
    $categories_stmt = $db->query("SELECT id, name, parent_id FROM categories WHERE is_active = 1 ORDER BY parent_id ASC, name ASC");
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error
}

// Function to build hierarchical category options
function buildCategoryOptions($categories, $selected_id = null, $parent_id = null, $indent = '') {
    $html = '';
    foreach ($categories as $category) {
        if (($parent_id === null && $category['parent_id'] === null) || ($parent_id !== null && $category['parent_id'] == $parent_id)) {
            $html .= '<option value="' . htmlspecialchars($category['id']) . '"';
            if ($selected_id == $category['id']) {
                $html .= ' selected';
            }
            $html .= '>' . $indent . htmlspecialchars($category['name']) . '</option>';
            // Recursively add sub-categories
            $html .= buildCategoryOptions($categories, $selected_id, $category['id'], $indent . '--- ');
        }
    }
    return $html;
}

// --- Get Filter and Sort Parameters ---
$search = $_GET['search'] ?? '';
$filter_category = $_GET['filter_category'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'active'; // active, inactive, all
$sort_by = $_GET['sort_by'] ?? 'updated_at';
$sort_dir = $_GET['sort_dir'] ?? 'DESC';

$sort_options = [
    'updated_at' => 'p.updated_at',
    'created_at' => 'p.created_at',
    'name_alpha' => 'p.name',
    'price' => 'p.price',
    'total_quantity' => 'p.total_quantity',
    'display_order' => 'p.display_order',
];
$sort_column = $sort_options[$sort_by] ?? 'p.updated_at';
$sort_direction = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';

// Determine if any ADVANCED filter is active
$advanced_filters_active = !empty($filter_category) ||
                           ($sort_by != 'updated_at') ||
                           ($sort_dir != 'DESC') ||
                           ($filter_status != 'active');

// --- Pagination ---
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// --- Build The Main SQL Query ---
$where_clauses = ["1=1"];
$params = [];

// Apply status filter
if ($filter_status == 'active') {
    $where_clauses[] = "p.is_active = 1";
} elseif ($filter_status == 'inactive') {
    $where_clauses[] = "p.is_active = 0";
}

// Apply category filter
if ($filter_category) {
    // Also include products from subcategories of the selected category
    $where_clauses[] = "(p.category_id = ? OR p.subcategory_id = ?)";
    $params[] = $filter_category;
    $params[] = $filter_category;
}

// Apply search filter
if ($search) {
    $where_clauses[] = "(p.name LIKE ? OR p.sku LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(" AND ", $where_clauses);

$products = [];
$total_products = 0;
$total_pages = 0;

if ($can_view) {
    try {
        // Main query for fetching product data
        $sql = "SELECT
                    p.*,
                    c_main.name as main_category_name,
                    c_sub.name as sub_category_name,
                    (SELECT image_url FROM product_images WHERE product_id = p.id AND is_main = 1 ORDER BY display_order LIMIT 1) as main_image_url
                FROM
                    products p
                LEFT JOIN
                    categories c_main ON p.category_id = c_main.id
                LEFT JOIN
                    categories c_sub ON p.subcategory_id = c_sub.id
                WHERE {$where_sql}
                ORDER BY {$sort_column} {$sort_direction}
                LIMIT $limit OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Count query for pagination
        $count_sql = "SELECT COUNT(*) FROM products p WHERE {$where_sql}";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute($params);
        $total_products = $count_stmt->fetchColumn();
        $total_pages = ceil($total_products / $limit);

        // Adjust page if it exceeds total pages after filtering
        if ($page > $total_pages && $total_pages > 0) {
            $page = $total_pages;
            $offset = ($page - 1) * $limit;
            $stmt = $db->prepare($sql); // Re-run query with corrected offset
            $stmt->execute($params);
            $products = $stmt->fetchAll();
        } else if ($total_pages === 0) {
            $page = 1;
            $offset = 0;
        }

    } catch (PDOException $e) {
        $error_message = "حدث خطأ أثناء جلب البيانات: " . $e->getMessage();
        $products = [];
        $total_products = 0;
        $total_pages = 0;
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">إدارة المنتجات</h1>
                    <p class="text-gray-600 mt-1">عرض، إضافة، وتعديل منتجات المتجر.</p>
                </div>
                <div class="mt-4 sm:mt-0 flex flex-wrap gap-3 items-center justify-end">
                    <!-- Shop-related buttons -->
                    <a href="../categories/index.php" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition duration-200 text-sm">
                        <i class="fas fa-tags ml-2"></i> تصنيفات المنتجات
                    </a>
                    <a href="../attributes/index.php" class="inline-flex items-center px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition duration-200 text-sm">
                        <i class="fas fa-sliders-h ml-2"></i> سمات المنتجات
                    </a>
                    <a href="../settings/product_slides.php" class="inline-flex items-center px-4 py-2 bg-yellow-500 text-gray-800 rounded-lg hover:bg-yellow-600 transition duration-200 text-sm">
                        <i class="fas fa-images ml-2"></i> سلايدر المنتجات
                    </a>
                    <a href="../colors/index.php" class="inline-flex items-center px-4 py-2 bg-pink-500 text-white rounded-lg hover:bg-pink-600 transition duration-200 text-sm">
                        <i class="fas fa-palette ml-2"></i> الألوان
                    </a>
                    <a href="../orders/shop_orders_manage.php" class="inline-flex items-center px-4 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600 transition duration-200 text-sm">
                        <i class="fas fa-shopping-basket ml-2"></i> إدارة طلبات المتجر
                    </a>
                    <!-- End Shop-related buttons -->

                    <!-- Toggle Filters Button -->
                    <button type="button" id="toggleAdvancedFiltersBtn"
                        class="inline-flex items-center px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition duration-200 text-sm">
                        <i class="fas fa-filter ml-2"></i> <span id="toggleText"><?php echo $advanced_filters_active ? 'إخفاء الفلاتر المتقدمة' : 'إظهار الفلاتر المتقدمة'; ?></span>
                    </button>
                    
                    <?php if ($can_add): ?>
                        <a href="add.php"
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i> منتج جديد
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filters and Sorting Form -->
            <form method="GET">
                <!-- Always Visible Search and Action Row -->
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex flex-wrap gap-4 items-end">
                        <div style="flex-grow: 1;">
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">بحث (اسم المنتج، SKU)</label>
                            <input type="text" name="search" id="search" placeholder="ابحث باسم المنتج أو الـ SKU..."
                                value="<?php echo htmlspecialchars($search); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <div class="flex items-end gap-2" style="padding-bottom: 2px;">
                            <button type="submit"
                                class="w-full px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700"><i
                                    class="fas fa-search ml-1"></i>بحث</button>
                            <a href="index.php"
                                class="w-full text-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400"><i
                                    class="fas fa-times ml-1"></i>إلغاء</a>
                        </div>
                    </div>
                </div>

                <!-- Collapsible Advanced Filters -->
                <div id="advancedFilters" class="px-6 py-4" style="display: <?php echo $advanced_filters_active ? 'block' : 'none'; ?>;">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 items-end">
                        
                        <!-- Category Filter -->
                        <div>
                            <label for="filter_category" class="block text-sm font-medium text-gray-700 mb-1">الفئة</label>
                            <select name="filter_category" id="filter_category"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">الكل</option>
                                <?php echo buildCategoryOptions($categories, $filter_category); ?>
                            </select>
                        </div>
                        <!-- Sort By -->
                        <div>
                            <label for="sort_by" class="block text-sm font-medium text-gray-700 mb-1">ترتيب حسب</label>
                            <select name="sort_by" id="sort_by" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="updated_at" <?php if ($sort_by == 'updated_at') echo 'selected'; ?>>آخر تعديل</option>
                                <option value="created_at" <?php if ($sort_by == 'created_at') echo 'selected'; ?>>تاريخ الإضافة</option>
                                <option value="name_alpha" <?php if ($sort_by == 'name_alpha') echo 'selected'; ?>>الاسم بالأبجدية</option>
                                <option value="price" <?php if ($sort_by == 'price') echo 'selected'; ?>>السعر</option>
                                <option value="total_quantity" <?php if ($sort_by == 'total_quantity') echo 'selected'; ?>>الكمية الإجمالية</option>
                                <option value="display_order" <?php if ($sort_by == 'display_order') echo 'selected'; ?>>ترتيب العرض</option>
                            </select>
                        </div>
                        <!-- Sort Direction -->
                        <div>
                            <label for="sort_dir" class="block text-sm font-medium text-gray-700 mb-1">الاتجاه</label>
                            <select name="sort_dir" id="sort_dir"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="DESC" <?php if ($sort_dir == 'DESC') echo 'selected'; ?>>تنازلي</option>
                                <option value="ASC" <?php if ($sort_dir == 'ASC') echo 'selected'; ?>>تصاعدي</option>
                            </select>
                        </div>
                        <!-- Status Filter -->
                        <div>
                            <label for="filter_status" class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                            <select name="filter_status" id="filter_status"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="active" <?php if ($filter_status == 'active') echo 'selected'; ?>>نشط</option>
                                <option value="inactive" <?php if ($filter_status == 'inactive') echo 'selected'; ?>>معطل</option>
                                <option value="all" <?php if ($filter_status == 'all') echo 'selected'; ?>>الكل</option>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><i
                    class="fas fa-check-circle ml-2"></i><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><i
                    class="fas fa-exclamation-circle ml-2"></i><?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Products Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">المنتج</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الفئة</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">السعر</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الكمية الإجمالية</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الإضافة</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (!$can_view): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-red-500">
                                    <i class="fas fa-times-circle text-4xl mb-4"></i>
                                    <p><?php echo $error_message; ?></p>
                                </td>
                            </tr>
                        <?php elseif (empty($products)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500"><i
                                        class="fas fa-box-open text-4xl mb-4 text-gray-300"></i>
                                    <p>لا توجد منتجات مطابقة للبحث أو الفلترة.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                                <tr class="hover:bg-gray-50 <?php echo $product['is_active'] == 0 ? 'bg-red-50' : ''; ?>">
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <div class="flex items-center justify-center">
                                            <?php if ($product['main_image_url']): ?>
                                                <img src="<?php echo htmlspecialchars($product['main_image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="h-10 w-10 object-cover rounded-md ml-2">
                                            <?php else: ?>
                                                <i class="fas fa-image text-gray-300 text-2xl ml-2"></i>
                                            <?php endif; ?>
                                            <div>
                                                <div class="font-bold <?php echo $product['is_active'] == 0 ? 'text-red-600' : ''; ?>">
                                                    <?php echo htmlspecialchars($product['name']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">SKU: <?php echo htmlspecialchars($product['sku'] ?: '-'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                            <?php echo htmlspecialchars($product['main_category_name'] ?: '-'); ?>
                                        </span>
                                        <?php if ($product['sub_category_name']): ?>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-800 mt-1 block sm:inline-block">
                                                <?php echo htmlspecialchars($product['sub_category_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <?php if ($product['discount_price'] && $product['discount_price'] < $product['price']): ?>
                                            <span class="text-red-600 font-bold ml-1"><?php echo number_format($product['discount_price'], 2); ?></span>
                                            <span class="text-gray-500 line-through text-xs"><?php echo number_format($product['price'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="text-green-600 font-bold"><?php echo number_format($product['price'], 2); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center font-medium">
                                        <?php echo number_format($product['total_quantity'], 0, '', ','); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <?php if ($product['is_active']): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle ml-1"></i>نشط
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-ban ml-1"></i>معطل
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                        <?php echo date('Y/m/d', strtotime($product['created_at'])); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-center">
                                        <div class="flex flex-wrap justify-center items-center gap-2 gap-y-2">
                                            <a href="view.php?id=<?php echo $product['id']; ?>"
                                                class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-blue-600 hover:text-blue-900 hover:bg-blue-50 border border-blue-100" title="عرض"><i
                                                    class="fas fa-eye"></i><span class="text-xs">عرض</span></a>
                                            <?php if ($can_edit): ?>
                                                <a href="edit.php?id=<?php echo $product['id']; ?>"
                                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-green-600 hover:text-green-900 hover:bg-green-50 border border-green-100" title="تعديل"><i
                                                        class="fas fa-edit"></i><span class="text-xs">تعديل</span></a>
                                                <a href="?action=delete&id=<?php echo $product['id'] . '&' . http_build_query(array_filter($_GET, function ($k) { return $k != 'action' && $k != 'id'; }, ARRAY_FILTER_USE_KEY)); ?>"
                                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-red-600 hover:text-red-900 hover:bg-red-50 border border-red-100" title="حذف"
                                                    onclick="return confirm('هل أنت متأكد من حذف هذا المنتج؟')">
                                                    <i class="fas fa-trash"></i><span class="text-xs">حذف</span>
                                                </a>
                                                <a href="?action=toggle_active&id=<?php echo $product['id']; ?>&status=<?php echo $product['is_active'] . '&' . http_build_query(array_filter($_GET, function ($k) { return $k != 'action' && $k != 'id' && $k != 'status'; }, ARRAY_FILTER_USE_KEY)); ?>"
                                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-md <?php echo $product['is_active'] ? 'text-red-600 hover:bg-red-50 border border-red-100' : 'text-green-600 hover:bg-green-50 border border-green-100'; ?>"
                                                    title="<?php echo $product['is_active'] ? 'تعطيل' : 'تفعيل'; ?>"
                                                    onclick="return confirm('<?php echo $product['is_active'] ? 'هل أنت متأكد من تعطيل هذا المنتج؟' : 'هل أنت متأكد من تفعيل هذا المنتج؟'; ?>')">
                                                    <i class="fas fa-power-off"></i><span class="text-xs"><?php echo $product['is_active'] ? 'تعطيل' : 'تفعيل'; ?></span>
                                                </a>
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
            <?php if ($can_view && $total_pages > 1): ?>
                <div
                    class="bg-white px-4 py-3 flex flex-col sm:flex-row items-center justify-between border-t border-gray-200 sm:px-6">
                    <div class="w-full sm:w-auto text-center sm:text-right mb-4 sm:mb-0">
                        <p class="text-sm text-gray-700">
                            عرض <span class="font-medium"><?php echo $offset + 1; ?></span>
                            إلى <span
                                class="font-medium"><?php echo min($offset + $limit, $total_products); ?></span>
                            من <span class="font-medium"><?php echo $total_products; ?></span> نتيجة
                        </p>
                    </div>
                    <div class="w-full sm:w-auto">
                        <nav class="relative z-0 inline-flex justify-center rounded-md shadow-sm -space-x-px w-full"
                            aria-label="Pagination">
                            <?php
                            $query_params = $_GET;
                            unset($query_params['page']);
                            
                            $base_url = '?' . http_build_query($query_params);

                            // Previous Page Link
                            $prev_page = $page - 1;
                            $prev_disabled = $page <= 1 ? 'pointer-events-none opacity-50' : '';
                            echo "<a href='{$base_url}&page={$prev_page}' class='relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 {$prev_disabled}'><span class='sr-only'>السابق</span><i class='fas fa-chevron-right'></i></a>";

                            // Page Number Links (Fixed Logic)
                            $max_links = 5;
                            $start_page = max(1, $page - floor($max_links / 2));
                            $end_page = min($total_pages, $page + floor($max_links / 2));

                            if ($end_page - $start_page + 1 < $max_links) {
                                if ($start_page == 1) {
                                    $end_page = min($total_pages, $start_page + $max_links - 1);
                                } elseif ($end_page == $total_pages) {
                                    $start_page = max(1, $total_pages - $max_links + 1);
                                }
                            }
                            
                            // First page and ellipsis
                            if ($start_page > 1) {
                                echo "<a href='{$base_url}&page=1' class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50'>1</a>";
                                if ($start_page > 2) {
                                    echo "<span class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700'>...</span>";
                                }
                                $start_page = max(2, $start_page); // Ensure start is at least 2 if 1 is printed
                            }


                            for ($i = $start_page; $i <= $end_page; $i++) {
                                $active_class = ($i == $page) ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50';
                                echo "<a href='{$base_url}&page={$i}' class='relative inline-flex items-center px-4 py-2 border text-sm font-medium {$active_class}'>{$i}</a>";
                            }

                            // Last page and ellipsis
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo "<span class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700'>...</span>";
                                }
                                echo "<a href='{$base_url}&page={$total_pages}' class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50'>{$total_pages}</a>";
                            }

                            // Next Page Link
                            $next_page = $page + 1;
                            $next_disabled = $page >= $total_pages ? 'pointer-events-none opacity-50' : '';
                            echo "<a href='{$base_url}&page={$next_page}' class='relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 {$next_disabled}'><span class='sr-only'>التالي</span><i class='fas fa-chevron-left'></i></a>";
                            ?>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('toggleAdvancedFiltersBtn');
        const filtersDiv = document.getElementById('advancedFilters');
        const toggleText = document.getElementById('toggleText');
        
        // Use PHP variable for initial state
        const advancedFiltersActive = <?php echo $advanced_filters_active ? 'true' : 'false'; ?>;

        if (advancedFiltersActive) {
             filtersDiv.style.display = 'block';
             toggleText.textContent = 'إخفاء الفلاتر المتقدمة';
        } else {
             filtersDiv.style.display = 'none';
             toggleText.textContent = 'إظهار الفلاتر المتقدمة';
        }

        toggleBtn.addEventListener('click', function() {
            const isHidden = filtersDiv.style.display === 'none';
            if (isHidden) {
                filtersDiv.style.display = 'block';
                toggleText.textContent = 'إخفاء الفلاتر المتقدمة';
            } else {
                filtersDiv.style.display = 'none';
                toggleText.textContent = 'إظهار الفلاتر المتقدمة';
            }
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>