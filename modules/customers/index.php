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

// Include and check permissions if the file exists
if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    $can_view = hasPermission($_SESSION['user_id'], 'customers', 'view');
    $can_add = hasPermission($_SESSION['user_id'], 'customers', 'add');
    $can_edit = hasPermission($_SESSION['user_id'], 'customers', 'edit');
}

// If the user cannot view customers, redirect or show an error
if (!$can_view) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لعرض الطلبات';
    header('Location: ../../index.php');
    exit();
}
$page_title = 'إدارة العملاء';

// --- Handle Actions (Delete/Toggle Status/Toggle Self Order/Toggle No Deposit Order/Toggle Show Shop) ---
// Note: These actions now update the `updated_at` field to reflect the change in sorting.
if (isset($_GET['action'])) {
    // Check edit permission for actions that modify data
    if (!$can_edit) {
        $error_message = 'ليس لديك صلاحية لتنفيذ هذا الإجراء';
    } else {
        if ($_GET['action'] == 'delete') {
            try {
                $stmt = $db->prepare("UPDATE customers SET is_active = 0, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $success_message = 'تم حذف العميل بنجاح (أصبح غير نشط).';
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء الحذف.';
            }
        } elseif ($_GET['action'] == 'toggle_active') {
            try {
                $current_status = $_GET['status'] ?? 0;
                $new_status = $current_status == 1 ? 0 : 1;
                $stmt = $db->prepare("UPDATE customers SET is_active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $_GET['id']]);
                $success_message = $new_status == 1 ? 'تم تفعيل العميل بنجاح.' : 'تم تعطيل العميل بنجاح.';
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء تحديث حالة العميل.';
            }
        } elseif ($_GET['action'] == 'toggle_self_order') {
            try {
                // Get the CURRENT status from the URL (which holds the current DB value: 'active' or 'inactive')
                $current_status_db = strtolower($_GET['status'] ?? 'inactive');
                
                // Determine the new ENUM value for the database (toggle the state)
                if ($current_status_db === 'active') {
                    $new_status_db_value = 'inactive';
                    $new_status_is_active = false;
                } else { // 'inactive' or any other unexpected value defaults to 'active'
                    $new_status_db_value = 'active';
                    $new_status_is_active = true;
                }

                $stmt = $db->prepare("UPDATE customers SET enable_create_self_order = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status_db_value, $_GET['id']]); // FIX: Use ENUM string value
                
                $success_message = $new_status_is_active ? 'تم تفعيل خاصية الطلب الذاتي للعميل بنجاح.' : 'تم تعطيل خاصية الطلب الذاتي للعميل بنجاح.';
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء تحديث حالة خاصية الطلب الذاتي.';
            }
        // --- Handle Toggle No Deposit Order ---
        } elseif ($_GET['action'] == 'toggle_no_deposit_order') {
            try {
                $current_status = $_GET['status'] ?? 0; // Expecting 0 or 1
                $new_status = $current_status == 1 ? 0 : 1; // Toggle the value
                $stmt = $db->prepare("UPDATE customers SET allow_no_deposit_orders = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $_GET['id']]);
                $success_message = $new_status == 1 ? 'تم تفعيل السماح بالطلب بدون دفعة أولى للعميل بنجاح.' : 'تم تعطيل السماح بالطلب بدون دفعة أولى للعميل بنجاح.';
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء تحديث حالة السماح بالطلب بدون دفعة أولى.';
            }
        } elseif ($_GET['action'] == 'bulk_all_customer_features') {
            try {
                $bulk_state = $_GET['state'] ?? '';
                if ($bulk_state === 'enable') {
                    $db->exec("UPDATE customers SET enable_create_self_order = 'active', allow_no_deposit_orders = 1, show_shop_for_customer = 1, updated_at = NOW()");
                    $success_message = 'تم تفعيل الطلب الذاتي وبدون دفعة أولى وعرض المتجر لجميع العملاء.';
                } elseif ($bulk_state === 'disable') {
                    $db->exec("UPDATE customers SET enable_create_self_order = 'inactive', allow_no_deposit_orders = 0, show_shop_for_customer = 0, updated_at = NOW()");
                    $success_message = 'تم تعطيل الطلب الذاتي وبدون دفعة أولى وعرض المتجر لجميع العملاء.';
                } else {
                    $error_message = 'طلب غير صالح للتحكم الجماعي.';
                }
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء تحديث إعدادات جميع العملاء.';
            }
        } elseif ($_GET['action'] == 'bulk_self_order') {
            try {
                $bulk_state = $_GET['state'] ?? '';
                if ($bulk_state === 'enable') {
                    $db->exec("UPDATE customers SET enable_create_self_order = 'active', updated_at = NOW()");
                    $success_message = 'تم تفعيل الطلب الذاتي لجميع العملاء.';
                } elseif ($bulk_state === 'disable') {
                    $db->exec("UPDATE customers SET enable_create_self_order = 'inactive', updated_at = NOW()");
                    $success_message = 'تم تعطيل الطلب الذاتي لجميع العملاء.';
                } else {
                    $error_message = 'طلب غير صالح لتحديث الطلب الذاتي.';
                }
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء تحديث الطلب الذاتي لجميع العملاء.';
            }
        } elseif ($_GET['action'] == 'bulk_feature_toggle') {
            try {
                $feature = $_GET['feature'] ?? '';
                $bulk_state = $_GET['state'] ?? '';
                $allowed_features = [
                    'no_deposit' => ['column' => 'allow_no_deposit_orders', 'label' => 'بدون دفعة أولى'],
                    'show_shop' => ['column' => 'show_shop_for_customer', 'label' => 'عرض المتجر'],
                ];
                if (!isset($allowed_features[$feature]) || !in_array($bulk_state, ['enable', 'disable'], true)) {
                    throw new Exception('طلب غير صالح لتحديث الميزة.');
                }
                $new_value = $bulk_state === 'enable' ? 1 : 0;
                $column = $allowed_features[$feature]['column'];
                $db->exec("UPDATE customers SET {$column} = {$new_value}, updated_at = NOW()");
                $success_message = ($bulk_state === 'enable' ? 'تم تفعيل ' : 'تم تعطيل ') . $allowed_features[$feature]['label'] . ' لجميع العملاء.';
            } catch (Exception $e) {
                $error_message = 'حدث خطأ أثناء تحديث الميزة لجميع العملاء: ' . $e->getMessage();
            }
        } elseif ($_GET['action'] == 'allow_self_order_all' || $_GET['action'] == 'bulk_self_service_all') {
            try {
                $db->exec("UPDATE customers SET enable_create_self_order = 'active', updated_at = NOW()");
                $success_message = 'تم تفعيل الطلب الذاتي لجميع العملاء.';
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء تفعيل الطلب الذاتي لجميع العملاء.';
            }
        } elseif ($_GET['action'] == 'enable_all_customers') {
            try {
                $db->exec("UPDATE customers SET is_active = 1, updated_at = NOW()");
                $success_message = 'تم تفعيل جميع العملاء بنجاح.';
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء تفعيل جميع العملاء.';
            }
        } elseif ($_GET['action'] == 'disable_all_customers') {
            try {
                $db->exec("UPDATE customers SET is_active = 0, updated_at = NOW()");
                $success_message = 'تم تعطيل جميع العملاء بنجاح.';
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء تعطيل جميع العملاء.';
            }
        } elseif ($_GET['action'] == 'toggle_show_shop') {
            try {
                $current_status = $_GET['status'] ?? 0; // Expecting 0 or 1
                $new_status = $current_status == 1 ? 0 : 1; // Toggle the value
                $stmt = $db->prepare("UPDATE customers SET show_shop_for_customer = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $_GET['id']]);
                $success_message = $new_status == 1 ? 'تم تفعيل عرض المتجر لهذا العميل بنجاح.' : 'تم تعطيل عرض المتجر لهذا العميل بنجاح.';
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء تحديث حالة عرض المتجر للعميل.';
            }
        }
    }
}

// --- Fetch data for filters ---
try {
    $customer_types = $db->query("SELECT id, name FROM customer_types WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $cities = $db->query("SELECT id, name FROM cities WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customer_types = [];
    $cities = [];
    $error_message = "فشل تحميل بيانات الفلاتر: " . $e->getMessage();
}


// --- Get Filter and Sort Parameters ---
$search = $_GET['search'] ?? '';
$filter_type = $_GET['filter_type'] ?? '';
$filter_city = $_GET['filter_city'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_status = $_GET['filter_status'] ?? 'active'; // active, inactive, all
$filter_remaining_from = $_GET['filter_remaining_from'] ?? ''; // **NEW**: Filter for remaining amount

// Whitelist for safe sorting
$sort_options = [
    'updated_at' => 'c.updated_at',
    'total_amount' => 'total_invoices_amount',
    'total_orders' => 'total_orders',
    'remaining_amount' => 'total_remaining_amount', // **NEW**: Sort by remaining amount
    'name_alpha' => 'c.name' // **NEW**: Sort by name
];
$sort_by = $_GET['sort_by'] ?? 'updated_at';
$sort_column = $sort_options[$sort_by] ?? 'c.updated_at'; // Default to updated_at

$sort_dir_options = ['DESC' => 'DESC', 'ASC' => 'ASC'];
$sort_dir = $_GET['sort_dir'] ?? 'DESC';
$sort_direction = $sort_dir_options[$sort_dir] ?? 'DESC'; // Default to DESC

// Determine if any ADVANCED filter is active (excluding search, page)
// Check if any filter is set to a non-default value
$advanced_filters_active = !empty($filter_type) ||
                           !empty($filter_city) ||
                           !empty($filter_date_from) ||
                           !empty($filter_date_to) ||
                           !empty($filter_remaining_from) ||
                           ($sort_by != 'updated_at') ||
                           ($sort_dir != 'DESC') ||
                           ($filter_status != 'active');


// --- Pagination ---
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15; // Kept at 15 as in original code
$offset = ($page - 1) * $limit;

// --- Build The Main SQL Query ---
$base_query_from = "
    FROM
        customers c
    LEFT JOIN
        customer_types ct ON c.customer_type_id = ct.id
    LEFT JOIN
        cities city ON c.city_id = city.id
    LEFT JOIN
        customer_orders co ON c.id = co.customer_id
    LEFT JOIN
        order_damaged_items odi ON co.id = odi.order_id
";

$where_clauses = ["1=1"];
$params = [];
$having_clauses = [];
$having_params = [];

// Apply status filter
if ($filter_status == 'active') {
    $where_clauses[] = "c.is_active = 1";
} elseif ($filter_status == 'inactive') {
    $where_clauses[] = "c.is_active = 0";
}

// Apply other WHERE filters
if ($search) {
    $where_clauses[] = "(c.name LIKE ? OR c.customer_code LIKE ? OR c.mobile_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}
if ($filter_type) {
    $where_clauses[] = "c.customer_type_id = ?";
    $params[] = $filter_type;
}
if ($filter_city) {
    $where_clauses[] = "c.city_id = ?";
    $params[] = $filter_city;
}
if ($filter_date_from) {
    $where_clauses[] = "DATE(c.created_at) >= ?";
    $params[] = $filter_date_from;
}
if ($filter_date_to) {
    $where_clauses[] = "DATE(c.created_at) <= ?";
    $params[] = $filter_date_to;
}

// **NEW**: Apply HAVING filter for remaining amount
if ($filter_remaining_from !== '' && is_numeric($filter_remaining_from)) {
    $having_clauses[] = "(IFNULL(SUM(DISTINCT co.final_amount), 0) - IFNULL(SUM(DISTINCT co.paid_amount), 0)) >= ?";
    $having_params[] = $filter_remaining_from;
}

$where_sql = implode(" AND ", $where_clauses);
$having_sql = empty($having_clauses) ? '' : 'HAVING ' . implode(" AND ", $having_clauses);
$all_query_params = array_merge($params, $having_params);

// --- Execute Queries ---
$customers = [];
$total_customers = 0;
$total_pages = 0;
$totals = [];

// Only fetch data if the user has view permission
if ($can_view) {
    try {
        // Main query for fetching customer data
        $sql = "SELECT
                    c.*,
                    ct.name as customer_type_name,
                    city.name as city_name,
                    COUNT(DISTINCT co.id) AS total_orders,
                    IFNULL(SUM(DISTINCT co.final_amount), 0) AS total_invoices_amount,
                    IFNULL(SUM(DISTINCT co.paid_amount), 0) AS total_paid_amount,
                    (IFNULL(SUM(DISTINCT co.final_amount), 0) - IFNULL(SUM(DISTINCT co.paid_amount), 0)) as total_remaining_amount,
                    IFNULL(SUM(DISTINCT co.discount_amount), 0) + IFNULL(SUM(DISTINCT co.additional_discount), 0) as total_discount_amount,
                    IFNULL(SUM(DISTINCT odi.price), 0) AS total_damaged_amount
                {$base_query_from}
                WHERE {$where_sql}
                GROUP BY c.id
                {$having_sql}
                ORDER BY {$sort_column} {$sort_direction}, c.created_at DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($all_query_params);
        $customers = $stmt->fetchAll();

        // Count query for pagination (handles HAVING clause correctly)
        $count_sql = "SELECT COUNT(*) FROM (
                        SELECT 1
                        {$base_query_from}
                        WHERE {$where_sql}
                        GROUP BY c.id
                        {$having_sql}
                    ) AS sub_count";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute($all_query_params);
        $total_customers = $count_stmt->fetchColumn();
        $total_pages = ceil($total_customers / $limit);

        // Adjust page if it exceeds total pages after filtering
        if ($page > $total_pages && $total_pages > 0) {
            $page = $total_pages;
            $offset = ($page - 1) * $limit;
            // Re-run the main query with the corrected offset if needed
            $sql = "SELECT
                        c.*,
                        ct.name as customer_type_name,
                        city.name as city_name,
                        COUNT(DISTINCT co.id) AS total_orders,
                        IFNULL(SUM(DISTINCT co.final_amount), 0) AS total_invoices_amount,
                        IFNULL(SUM(DISTINCT co.paid_amount), 0) AS total_paid_amount,
                        (IFNULL(SUM(DISTINCT co.final_amount), 0) - IFNULL(SUM(DISTINCT co.paid_amount), 0)) as total_remaining_amount,
                        IFNULL(SUM(DISTINCT co.discount_amount), 0) + IFNULL(SUM(DISTINCT co.additional_discount), 0) as total_discount_amount,
                        IFNULL(SUM(DISTINCT odi.price), 0) AS total_damaged_amount
                    {$base_query_from}
                    WHERE {$where_sql}
                    GROUP BY c.id
                    {$having_sql}
                    ORDER BY {$sort_column} {$sort_direction}, c.created_at DESC
                    LIMIT $limit OFFSET $offset";
            $stmt = $db->prepare($sql);
            $stmt->execute($all_query_params);
            $customers = $stmt->fetchAll();
        } else if ($total_pages === 0) {
            $page = 1;
            $offset = 0;
        }


        // Totals query (handles HAVING clause correctly)
        $totals_sql = "
        SELECT
            COUNT(customer_id) as filtered_count,
            SUM(total_orders) as total_orders_sum,
            SUM(total_invoices_amount) as total_invoices_sum,
            SUM(total_paid_amount) as total_paid_sum,
            SUM(total_remaining_amount) as total_remaining_sum,
            SUM(total_discount_amount) as total_discount_sum,
            SUM(total_damaged_amount) as total_damaged_sum
        FROM (
            SELECT
                c.id as customer_id,
                COUNT(DISTINCT co.id) AS total_orders,
                IFNULL(SUM(DISTINCT co.final_amount), 0) AS total_invoices_amount,
                IFNULL(SUM(DISTINCT co.paid_amount), 0) AS total_paid_amount,
                (IFNULL(SUM(DISTINCT co.final_amount), 0) - IFNULL(SUM(DISTINCT co.paid_amount), 0)) as total_remaining_amount,
                IFNULL(SUM(DISTINCT co.discount_amount), 0) + IFNULL(SUM(DISTINCT co.additional_discount), 0) as total_discount_amount,
                IFNULL(SUM(DISTINCT odi.price), 0) AS total_damaged_amount
            {$base_query_from}
            WHERE {$where_sql}
            GROUP BY c.id
            {$having_sql}
        ) AS customer_subtotals";

        $totals_stmt = $db->prepare($totals_sql);
        $totals_stmt->execute($all_query_params);
        $totals = $totals_stmt->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $error_message = "حدث خطأ أثناء جلب البيانات: " . $e->getMessage();
        // Ensure variables are still set to prevent errors in the view
        $customers = [];
        $total_customers = 0;
        $total_pages = 0;
        $totals = [];
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div
                class="px-6 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">إدارة العملاء</h1>
                    <p class="text-gray-600 mt-1">فلترة، ترتيب، وإدارة بيانات العملاء.</p>
                </div>
                <div class="mt-4 sm:mt-0 flex flex-col lg:flex-row lg:items-center gap-3 w-full lg:w-auto">
                    <div class="flex flex-wrap gap-2 justify-start lg:justify-end">
                        <!-- Toggle Filters Button -->
                        <button type="button" id="toggleAdvancedFiltersBtn"
                            class="inline-flex items-center px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition duration-200 text-sm">
                            <i class="fas fa-filter ml-2"></i> <span id="toggleText"><?php echo $advanced_filters_active ? 'إخفاء الفلاتر المتقدمة' : 'إظهار الفلاتر المتقدمة'; ?></span>
                        </button>
                        <?php if ($can_add): ?>
                            <a href="add.php"
                                class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200 text-sm font-semibold">
                                <i class="fas fa-plus ml-2"></i> عميل جديد
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($can_edit): ?>
                        <div class="w-full lg:w-auto bg-white border border-gray-200 rounded-xl p-3 shadow-sm">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                                <span class="text-xs font-bold text-gray-500 whitespace-nowrap">تحكم جماعي</span>
                                <div class="flex flex-wrap gap-2">
                                    <a href="index.php?action=bulk_all_customer_features&state=enable" onclick="return confirm('تفعيل الطلب الذاتي وبدون دفعة أولى وعرض المتجر لجميع العملاء؟');"
                                        class="inline-flex items-center px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200 text-xs sm:text-sm font-semibold">
                                        <i class="fas fa-check-circle ml-1"></i> Enable All Customers
                                    </a>
                                    <a href="index.php?action=bulk_all_customer_features&state=disable" onclick="return confirm('تعطيل الطلب الذاتي وبدون دفعة أولى وعرض المتجر لجميع العملاء؟');"
                                        class="inline-flex items-center px-3 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition duration-200 text-xs sm:text-sm font-semibold">
                                        <i class="fas fa-ban ml-1"></i> Disable All Customers
                                    </a>
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-semibold text-purple-700">الطلب الذاتي</span>
                                    <a href="index.php?action=bulk_self_order&state=enable" onclick="return confirm('تفعيل الطلب الذاتي لجميع العملاء؟');" class="px-2.5 py-1.5 rounded-lg bg-purple-100 text-purple-700 hover:bg-purple-200 text-xs font-bold">Enable All</a>
                                    <a href="index.php?action=bulk_self_order&state=disable" onclick="return confirm('تعطيل الطلب الذاتي لجميع العملاء؟');" class="px-2.5 py-1.5 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 text-xs font-bold">Disable All</a>
                                </div>
                                <form method="GET" class="flex flex-col sm:flex-row gap-2">
                                    <input type="hidden" name="action" value="bulk_feature_toggle">
                                    <select name="feature" class="px-3 py-2 border border-gray-300 rounded-lg text-xs sm:text-sm">
                                        <option value="no_deposit">بدون دفعة أولى</option>
                                        <option value="show_shop">عرض المتجر</option>
                                    </select>
                                    <select name="state" class="px-3 py-2 border border-gray-300 rounded-lg text-xs sm:text-sm">
                                        <option value="enable">Enable All</option>
                                        <option value="disable">Disable All</option>
                                    </select>
                                    <button type="submit" onclick="return confirm('تنفيذ الإجراء على جميع العملاء؟');" class="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-xs sm:text-sm font-semibold">تطبيق</button>
                                </form>
                            </div>
                        </div>
                        <a href="index.php?action=allow_self_order_all" onclick="return confirm('تفعيل الطلب الذاتي لجميع العملاء؟');"
                            class="inline-flex items-center px-3 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition duration-200 text-sm font-semibold">
                            <i class="fas fa-user-edit ml-1"></i> السماح بالطلب الذاتي للكل
                        </a>
                        <a href="index.php?action=enable_all_customers" onclick="return confirm('تفعيل جميع العملاء؟');"
                            class="inline-flex items-center px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200 text-sm font-semibold">
                            <i class="fas fa-check-circle ml-1"></i> تفعيل كل العملاء
                        </a>
                        <a href="index.php?action=disable_all_customers" onclick="return confirm('تعطيل جميع العملاء؟');"
                            class="inline-flex items-center px-3 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition duration-200 text-sm font-semibold">
                            <i class="fas fa-ban ml-1"></i> تعطيل كل العملاء
                        </a>
                    <?php endif; ?>
                    <?php if ($can_add): ?>
                        <a href="add.php"
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i> عميل جديد
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
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">بحث (اسم، كود، جوال)</label>
                            <input type="text" name="search" id="search" placeholder="ابحث بالاسم, الكود, الجوال..."
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
                        
                        <!-- Customer Type Filter -->
                        <div>
                            <label for="filter_type" class="block text-sm font-medium text-gray-700 mb-1">نوع العميل</label>
                            <select name="filter_type" id="filter_type"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">الكل</option>
                                <?php foreach ($customer_types as $type): ?>
                                    <option value="<?php echo $type['id']; ?>" <?php if ($filter_type == $type['id'])
                                           echo 'selected'; ?>><?php echo htmlspecialchars($type['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- City Filter -->
                        <div>
                            <label for="filter_city" class="block text-sm font-medium text-gray-700 mb-1">المحافظة</label>
                            <select name="filter_city" id="filter_city"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="">الكل</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo $city['id']; ?>" <?php if ($filter_city == $city['id'])
                                           echo 'selected'; ?>><?php echo htmlspecialchars($city['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Date From Filter -->
                        <div>
                            <label for="filter_date_from" class="block text-sm font-medium text-gray-700 mb-1">من
                                تاريخ</label>
                            <input type="date" name="filter_date_from" id="filter_date_from"
                                value="<?php echo htmlspecialchars($filter_date_from); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <!-- Date To Filter -->
                        <div>
                            <label for="filter_date_to" class="block text-sm font-medium text-gray-700 mb-1">إلى
                                تاريخ</label>
                            <input type="date" name="filter_date_to" id="filter_date_to"
                                value="<?php echo htmlspecialchars($filter_date_to); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <!-- **NEW** Remaining Amount Filter -->
                        <div>
                            <label for="filter_remaining_from" class="block text-sm font-medium text-gray-700 mb-1">المتبقي
                                يبدأ من</label>
                            <input type="number" name="filter_remaining_from" id="filter_remaining_from" placeholder="0"
                                value="<?php echo htmlspecialchars($filter_remaining_from); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        </div>
                        <!-- Sort By -->
                        <div>
                            <label for="sort_by" class="block text-sm font-medium text-gray-700 mb-1">ترتيب حسب</label>
                            <select name="sort_by" id="sort_by" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="updated_at" <?php if ($sort_by == 'updated_at')
                                    echo 'selected'; ?>>آخر تعديل
                                </option>
                                <option value="name_alpha" <?php if ($sort_by == 'name_alpha')
                                    echo 'selected'; ?>>الاسم
                                    بالأبجدية</option>
                                <option value="total_amount" <?php if ($sort_by == 'total_amount')
                                    echo 'selected'; ?>>إجمالي
                                    المبلغ</option>
                                <option value="remaining_amount" <?php if ($sort_by == 'remaining_amount')
                                    echo 'selected'; ?>>
                                    المبلغ المتبقي</option>
                                <option value="total_orders" <?php if ($sort_by == 'total_orders')
                                    echo 'selected'; ?>>عدد
                                    الطلبات</option>
                            </select>
                        </div>
                        <!-- Sort Direction -->
                        <div>
                            <label for="sort_dir" class="block text-sm font-medium text-gray-700 mb-1">الاتجاه</label>
                            <select name="sort_dir" id="sort_dir"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="DESC" <?php if ($sort_dir == 'DESC')
                                    echo 'selected'; ?>>تنازلي</option>
                                <option value="ASC" <?php if ($sort_dir == 'ASC')
                                    echo 'selected'; ?>>تصاعدي</option>
                            </select>
                        </div>
                        <!-- Status Filter -->
                        <div>
                            <label for="filter_status" class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                            <select name="filter_status" id="filter_status"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                <option value="active" <?php if ($filter_status == 'active')
                                    echo 'selected'; ?>>نشط</option>
                                <option value="inactive" <?php if ($filter_status == 'inactive')
                                    echo 'selected'; ?>>معطل
                                </option>
                                <option value="all" <?php if ($filter_status == 'all')
                                    echo 'selected'; ?>>الكل</option>
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

        <!-- Customers Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                العميل</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                الاتصال</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                المحافظة</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                الفئة</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                الطلب الذاتي</th>
                            <!-- NEW Header for Allow No Deposit Orders -->
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                بدون دفعة أولى</th>
                            <!-- END NEW -->
                            <!-- NEW Header for Show Shop For Customer -->
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                عرض المتجر</th>
                            <!-- END NEW -->
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                الطلبات</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                المجموع</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                المدفوع</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                المتبقي</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                إجمالي الخصم</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                مبلغ التوالف</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                ملاحظات</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                تاريخ الإضافة</th>
                            <th
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (!$can_view): // If user cannot view, show message and stop ?>
                            <tr>
                                <td colspan="15" class="px-6 py-12 text-center text-red-500">
                                    <i class="fas fa-times-circle text-4xl mb-4"></i>
                                    <p><?php echo $error_message; // Display the permission error ?></p>
                                </td>
                            </tr>
                        <?php elseif (empty($customers)): ?>
                            <tr>
                                <td colspan="15" class="px-6 py-12 text-center text-gray-500"><i
                                        class="fas fa-users text-4xl mb-4 text-gray-300"></i>
                                    <p>لا توجد نتائج مطابقة للبحث أو الفلترة.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $customer): ?>
                                <tr class="hover:bg-gray-50 <?php echo $customer['is_active'] == 0 ? 'bg-red-50' : ''; ?>">
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <div class="font-bold <?php echo $customer['is_active'] == 0 ? 'text-red-600' : ''; ?>">
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                            <?php if ($customer['is_active'] == 0): ?>
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 mr-2">
                                                    <i class="fas fa-ban ml-1"></i>معطل
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo htmlspecialchars($customer['customer_code']); ?></div>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <?php if ($customer['mobile_number'])
                                            echo '<div><i class="fas fa-mobile-alt text-gray-400 ml-1"></i>' . htmlspecialchars($customer['mobile_number']) . '</div>'; ?>
                                        <?php if ($customer['whatsapp_number'])
                                            echo '<div><a href="https://wa.me/' . preg_replace('/[^0-9]/', '', $customer['whatsapp_number']) . '" target="_blank" class="text-green-600"><i class="fab fa-whatsapp ml-1"></i>' . htmlspecialchars($customer['whatsapp_number']) . '</a></div>'; ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <span
                                            class="px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                                            <i
                                                class="fas fa-map-marker-alt ml-1"></i><?php echo htmlspecialchars($customer['city_name'] ?: '-'); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center"><span
                                            class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800"><?php echo htmlspecialchars($customer['customer_type_name'] ?: '-'); ?></span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <?php 
                                        $self_order_status_db = $customer['enable_create_self_order'] ?? 'inactive'; 
                                        $is_self_order_active = strtolower($self_order_status_db) === 'active';
                                        $self_order_status_for_url = htmlspecialchars($self_order_status_db);
                                        ?>
                                        <?php if ($can_edit): ?>
                                            <a href="?action=toggle_self_order&id=<?php echo $customer['id']; ?>&status=<?php echo $self_order_status_for_url . '&' . http_build_query(array_filter($_GET, fn($k) => $k != 'action' && $k != 'id' && $k != 'status', ARRAY_FILTER_USE_KEY)); ?>"
                                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium transition duration-200 
                                                <?php echo $is_self_order_active ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-red-100 text-red-800 hover:bg-red-200'; ?>"
                                                title="<?php echo $is_self_order_active ? 'تعطيل الطلب الذاتي' : 'تفعيل الطلب الذاتي'; ?>"
                                                onclick="return confirm('هل أنت متأكد من تغيير حالة خاصية الطلب الذاتي لهذا العميل؟')">
                                                <i class="fas <?php echo $is_self_order_active ? 'fa-check-circle' : 'fa-times-circle'; ?> ml-1"></i>
                                                <?php echo $is_self_order_active ? 'مفعل' : 'معطل'; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium 
                                                <?php echo $is_self_order_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>"
                                                title="<?php echo $is_self_order_active ? 'مفعل' : 'معطل'; ?>">
                                                <i class="fas <?php echo $is_self_order_active ? 'fa-check-circle' : 'fa-times-circle'; ?> ml-1"></i>
                                                <?php echo $is_self_order_active ? 'مفعل' : 'معطل'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- NEW Cell for Allow No Deposit Orders Toggle -->
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <?php 
                                        $no_deposit_status = $customer['allow_no_deposit_orders']; // This is TINYINT(1): 0 or 1
                                        $is_no_deposit_enabled = $no_deposit_status == 1;
                                        ?>
                                        <?php if ($can_edit): ?>
                                            <a href="?action=toggle_no_deposit_order&id=<?php echo $customer['id']; ?>&status=<?php echo $no_deposit_status . '&' . http_build_query(array_filter($_GET, fn($k) => $k != 'action' && $k != 'id' && $k != 'status', ARRAY_FILTER_USE_KEY)); ?>"
                                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium transition duration-200 
                                                <?php echo $is_no_deposit_enabled ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-red-100 text-red-800 hover:bg-red-200'; ?>"
                                                title="<?php echo $is_no_deposit_enabled ? 'تعطيل السماح بالطلب بدون دفعة أولى' : 'تفعيل السماح بالطلب بدون دفعة أولى'; ?>"
                                                onclick="return confirm('هل أنت متأكد من تغيير حالة السماح بالطلب بدون دفعة أولى لهذا العميل؟')">
                                                <i class="fas <?php echo $is_no_deposit_enabled ? 'fa-check-circle' : 'fa-times-circle'; ?> ml-1"></i>
                                                <?php echo $is_no_deposit_enabled ? 'مسموح' : 'غير مسموح'; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium 
                                                <?php echo $is_no_deposit_enabled ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>"
                                                title="<?php echo $is_no_deposit_enabled ? 'مسموح' : 'غير مسموح'; ?>">
                                                <i class="fas <?php echo $is_no_deposit_enabled ? 'fa-check-circle' : 'fa-times-circle'; ?> ml-1"></i>
                                                <?php echo $is_no_deposit_enabled ? 'مسموح' : 'غير مسموح'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- END NEW -->

                                    <!-- NEW Cell for Show Shop For Customer Toggle -->
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <?php 
                                        $show_shop_status = $customer['show_shop_for_customer']; // This is TINYINT(1): 0 or 1
                                        $is_shop_shown = $show_shop_status == 1;
                                        ?>
                                        <?php if ($can_edit): ?>
                                            <a href="?action=toggle_show_shop&id=<?php echo $customer['id']; ?>&status=<?php echo $show_shop_status . '&' . http_build_query(array_filter($_GET, fn($k) => $k != 'action' && $k != 'id' && $k != 'status', ARRAY_FILTER_USE_KEY)); ?>"
                                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium transition duration-200 
                                                <?php echo $is_shop_shown ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-red-100 text-red-800 hover:bg-red-200'; ?>"
                                                title="<?php echo $is_shop_shown ? 'تعطيل عرض المتجر' : 'تفعيل عرض المتجر'; ?>"
                                                onclick="return confirm('هل أنت متأكد من تغيير حالة عرض المتجر لهذا العميل؟')">
                                                <i class="fas <?php echo $is_shop_shown ? 'fa-store' : 'fa-store-slash'; ?> ml-1"></i>
                                                <?php echo $is_shop_shown ? 'معروض' : 'مخفي'; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium 
                                                <?php echo $is_shop_shown ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>"
                                                title="<?php echo $is_shop_shown ? 'معروض' : 'مخفي'; ?>">
                                                <i class="fas <?php echo $is_shop_shown ? 'fa-store' : 'fa-store-slash'; ?> ml-1"></i>
                                                <?php echo $is_shop_shown ? 'معروض' : 'مخفي'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- END NEW -->

                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center font-medium">
                                        <?php echo number_format($customer['total_orders'], 0, '', ','); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600 text-center font-bold">
                                        <?php echo number_format($customer['total_invoices_amount'], 0, '', ','); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-blue-600 text-center">
                                        <?php echo number_format($customer['total_paid_amount'], 0, '', ','); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-red-600 text-center font-bold">
                                        <?php echo number_format($customer['total_remaining_amount'], 0, '', ','); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-orange-600 text-center">
                                        <?php echo number_format($customer['total_discount_amount'], 0, '', ','); ?>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-red-600 text-center font-bold">
                                        <?php echo number_format($customer['total_damaged_amount'], 0, '', ','); ?>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-gray-500 text-center"
                                        style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                        title="<?php echo htmlspecialchars($customer['customer_notes'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($customer['customer_notes'] ?: '-'); ?>
                                    </td>

                                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                        <?php echo date('Y/m/d', strtotime($customer['created_at'])); ?></td>
                                    <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-center">
                                        <div class="flex justify-center items-center space-x-2 space-x-reverse">
                                            <a href="view_enhanced.php?id=<?php echo $customer['id']; ?>"
                                                class="text-blue-600 hover:text-blue-900" title="عرض"><i
                                                    class="fas fa-eye"></i></a>
                                            <?php if ($can_edit): ?>
                                                <a href="edit.php?id=<?php echo $customer['id']; ?>"
                                                    class="text-green-600 hover:text-green-900" title="تعديل"><i
                                                        class="fas fa-edit"></i></a>
                                                <a href="?action=toggle_active&id=<?php echo $customer['id']; ?>&status=<?php echo $customer['is_active'] . '&' . http_build_query(array_filter($_GET, fn($k) => $k != 'action' && $k != 'id' && $k != 'status', ARRAY_FILTER_USE_KEY)); ?>"
                                                    class="<?php echo $customer['is_active'] ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900'; ?>"
                                                    title="<?php echo $customer['is_active'] ? 'تعطيل' : 'تفعيل'; ?>"
                                                    onclick="return confirm('<?php echo $customer['is_active'] ? 'هل أنت متأكد من تعطيل هذا العميل؟' : 'هل أنت متأكد من تفعيل هذا العميل؟'; ?>')">
                                                    <i class="fas fa-power-off"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <!-- Totals Row -->

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
                                class="font-medium"><?php echo min($offset + $limit, $total_customers); ?></span>
                            من <span class="font-medium"><?php echo $total_customers; ?></span> نتيجة
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

<!-- SCRIPTS -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('toggleAdvancedFiltersBtn');
        const filtersDiv = document.getElementById('advancedFilters');
        const toggleText = document.getElementById('toggleText');
        
        // Use PHP variable for initial state
        const advancedFiltersActive = <?php echo $advanced_filters_active ? 'true' : 'false'; ?>;

        // Set initial state based on PHP logic (already handled by inline style in PHP, but for completeness):
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