<?php

/**
 * Customer Orders - Vertical Table Design
 * Version 4.7 (Manager Notes & No Group Filter)
 * - Changes the "Notes" column to "Manager Notes", fetching from the `manager_notes` field.
 * - Updates the permission for adding a manager note to use `can_add_orders`.
 * - Combines advanced filtering/sorting with dynamic custom statuses.
 * - Adds a filter and sort option for the remaining amount.
 * - Unifies the display of discount percentages from both coupons and automatic discounts.
 * - Adds a filter for orders that are not in a group.
 *
 * NEW CHANGES:
 * - Added a filter for 'Customer Type'.
 * - Made the customer name in the table a blue, clickable link to the customer's enhanced view page.
 * - Added indicators for orders created via Customer Approvals.
 * - ADDED: Filter and icon for "Manual" orders (defined as orders *not* from Customer Approvals).
 *
 * FIXES:
 * - Separates search filter to be always visible.
 * - Makes advanced filters collapsible via the toggle button.
 * - Implements robust pagination with Prev/Next and a limited page number display.
 *
 * NEW VARIABLES ADDED:
 * - Gross Total (from subtotal_amount)
 * - Discount Amount
 * - Discount Percentage
 * - Damaged Amount (مبلغ التوالف)
 * - Order Link
 * - Additional Link
 */

// DEBUG: Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/status_helpers.php';

// --- 1. PERMISSIONS ---
$user_id = $_SESSION['user_id'] ?? 0;
if (!canViewOrders($user_id, $db)) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لعرض الطلبات';
    header('Location: ../../index.php');
    exit();
}

// Get user's permissions
$can_add_orders = hasPermission($user_id, 'orders', 'add');
$can_edit_orders = hasPermission($user_id, 'orders', 'edit');
$can_create_status = hasPermission($user_id, 'orders', 'create_status');

// --- 2. INITIALIZATION & FILTERS ---
$page_title = 'قائمة عرض طلبات العملاء';
$error_message = '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']); // Clear the message after displaying it

// **NEW**: Fetch Customer Types for filter dropdown
try {
    $customer_types = $db->query("SELECT id, name FROM customer_types WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customer_types = [];
    $error_message = "فشل تحميل بيانات فئات العملاء: " . $e->getMessage();
}

// Get filter parameters from the URL
$status_filter = $_GET['status'] ?? '';
$creator_filter = $_GET['creator_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$group_filter = $_GET['group_id'] ?? '';
$remaining_filter = $_GET['remaining'] ?? '';
$filter_customer_type = $_GET['customer_type'] ?? '';
$manual_order_filter = $_GET['manual_order'] ?? ''; // NEW: Manual Order Filter

// Determine if any ADVANCED filter is active (excluding search, sort, page)
$advanced_filters_active = !empty($status_filter) || !empty($creator_filter) || !empty($date_from) || !empty($date_to) || !empty($group_filter) || !empty($remaining_filter) || !empty($filter_customer_type) || !empty($manual_order_filter);


// Get Sort Parameters
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_dir = $_GET['sort_dir'] ?? 'DESC';

// Whitelist for safe sorting
$sort_options = [
    'created_at' => 'o.created_at',
    'order_date' => 'o.order_date',
    'final_amount' => 'o.final_amount',
    'total_quantity' => 'total_quantity',
    'remaining_amount' => '(o.final_amount - o.paid_amount)'
];
$sort_column = $sort_options[$sort_by] ?? 'o.created_at';
$sort_direction = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';

// Pagination setup
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$records_per_page = 20; // Set to 20 per user request
$offset = ($page - 1) * $records_per_page;
$page = max(1, $page); // Ensure page is at least 1

// --- 3. FETCH DATA ---
try {
    // Fetch all available statuses for dropdowns
    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM customer_order_statuses ORDER BY is_default DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch data for all filter dropdowns
    $users = $db->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
    $groups = $db->query("SELECT id, group_name FROM purchase_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);

    $from_joins = "FROM customer_orders o
                   LEFT JOIN customers c ON o.customer_id = c.id
                   LEFT JOIN customer_types ct ON c.customer_type_id = ct.id
                   LEFT JOIN users u ON o.created_by = u.id
                   LEFT JOIN coupons coup ON o.coupon_id = coup.id
                   LEFT JOIN purchase_baskets pb ON o.basket_id = pb.id
                   LEFT JOIN purchase_groups pg ON pg.id = COALESCE(o.purchase_group_id, pb.purchase_group_id)
                   LEFT JOIN customer_order_statuses cos ON o.status = cos.status_key";

    // **NEW: Subquery to find corresponding source_approval_id used to infer "manual" status**
    $select_part = "SELECT
                        o.id, o.order_number, o.currency, o.order_date, o.customer_id, o.status,
                        o.order_link, o.additional_link, o.created_at, o.basket_id, o.purchase_group_id,
                        o.manager_notes,
                        o.coupon_id,
                        c.name as customer_name, c.mobile_number, c.whatsapp_number,
                        cos.status_name_ar,
                        COALESCE(o.subtotal_amount, 0) as subtotal_amount,
                        COALESCE(o.discount_amount, 0) as discount_amount,
                        COALESCE(o.final_amount, 0) as final_amount,
                        COALESCE(o.paid_amount, 0) as paid_amount,
                        COALESCE((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id), 0) as total_quantity,
                        (SELECT COALESCE(SUM(price), 0) FROM order_damaged_items odi WHERE odi.order_id = o.id) as damaged_amount,
                        (SELECT oi.product_link FROM order_items oi WHERE oi.order_id = o.id AND oi.product_link IS NOT NULL AND oi.product_link <> '' ORDER BY oi.id LIMIT 1) as first_product_link,
                        pg.group_name as purchase_group_name,
                        pg.group_number as purchase_group_number,
                        (SELECT GROUP_CONCAT(CONCAT(ci.id, ':', ci.invoice_number) SEPARATOR ';')
                         FROM customer_invoices ci WHERE ci.order_id = o.id) as invoice_data,
                        (SELECT id FROM order_approvals WHERE final_order_id = o.id LIMIT 1) as source_approval_id,
                        CASE
                            WHEN o.coupon_id IS NOT NULL AND coup.discount_type = 'percentage' THEN coup.discount_value
                            WHEN o.coupon_id IS NOT NULL AND coup.discount_type = 'fixed' AND o.subtotal_amount > 0.01 THEN (o.discount_amount / o.subtotal_amount) * 100
                            ELSE o.automatic_discount_percentage
                        END as display_discount_percentage
                    ";

    // Base queries
    $query = $select_part . " " . $from_joins . " WHERE 1=1";
    $count_query = "SELECT COUNT(o.id) " . $from_joins . " WHERE 1=1";
    $params = [];

    // Appending all filters
    if ($status_filter) {
        if ($status_filter === 'new') {
            $query .= " AND o.status IN (?, ?)";
            $count_query .= " AND o.status IN (?, ?)";
            $params[] = 'new';
            $params[] = 'processing';
        } else {
            $query .= " AND o.status = ?";
            $count_query .= " AND o.status = ?";
            $params[] = $status_filter;
        }
    }
    if ($creator_filter) {
        $query .= " AND o.created_by = ?";
        $count_query .= " AND o.created_by = ?";
        $params[] = $creator_filter;
    }
    if ($date_from) {
        $query .= " AND DATE(o.created_at) >= ?";
        $count_query .= " AND DATE(o.created_at) >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $query .= " AND DATE(o.created_at) <= ?";
        $count_query .= " AND DATE(o.created_at) <= ?";
        $params[] = $date_to;
    }
    if ($group_filter) {
        if ($group_filter === 'not_in_group') {
            $query .= " AND COALESCE(o.purchase_group_id, pb.purchase_group_id) IS NULL";
            $count_query .= " AND COALESCE(o.purchase_group_id, pb.purchase_group_id) IS NULL";
        } else {
            $query .= " AND COALESCE(o.purchase_group_id, pb.purchase_group_id) = ?";
            $count_query .= " AND COALESCE(o.purchase_group_id, pb.purchase_group_id) = ?";
            $params[] = $group_filter;
        }
    }
    if ($filter_customer_type) {
        $query .= " AND c.customer_type_id = ?";
        $count_query .= " AND c.customer_type_id = ?";
        $params[] = $filter_customer_type;
    }
    // NEW: Manual Order Filter condition (based on source_approval_id)
    if ($manual_order_filter !== '') {
        if ($manual_order_filter === '1') { // Filter for manual orders (no source approval ID)
            $query .= " AND (SELECT id FROM order_approvals WHERE final_order_id = o.id LIMIT 1) IS NULL";
            $count_query .= " AND (SELECT id FROM order_approvals WHERE final_order_id = o.id LIMIT 1) IS NULL";
        } elseif ($manual_order_filter === '0') { // Filter for non-manual orders (has source approval ID)
            $query .= " AND (SELECT id FROM order_approvals WHERE final_order_id = o.id LIMIT 1) IS NOT NULL";
            $count_query .= " AND (SELECT id FROM order_approvals WHERE final_order_id = o.id LIMIT 1) IS NOT NULL";
        }
    }
    if ($search) {
        $search_param = "%$search%";
        $query .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.mobile_number LIKE ?)";
        $count_query .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.mobile_number LIKE ?)";
        $params = array_merge($params, [$search_param, $search_param, $search_param]);
    }
    if ($remaining_filter === 'has_remaining') {
        $query .= " AND (o.final_amount - o.paid_amount) > 0.01";
        $count_query .= " AND (o.final_amount - o.paid_amount) > 0.01";
    } elseif ($remaining_filter === 'fully_paid') {
        $query .= " AND (o.final_amount - o.paid_amount) <= 0.01";
        $count_query .= " AND (o.final_amount - o.paid_amount) <= 0.01";
    }

    // Get total count for pagination
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = (int) $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $records_per_page);

    // Adjust page if it exceeds total pages after filtering
    if ($page > $total_pages && $total_pages > 0) {
        $page = $total_pages;
        $offset = ($page - 1) * $records_per_page;
    } else if ($total_pages === 0) {
        $page = 1;
        $offset = 0;
    }


    // Apply sorting and pagination
    $query .= " ORDER BY {$sort_column} {$sort_direction} LIMIT $records_per_page OFFSET $offset";

    // Fetch orders for the current page
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals for the CURRENT PAGE
    $page_totals = [
        'total_count' => count($orders),
        'total_quantity_sum' => 0,
        'total_subtotal_sum' => 0, // This is your gross_total
        'total_discount_sum' => 0,
        'total_damaged_sum' => 0, // New sum for damaged amount
        'total_amount_sum' => 0,
        'total_paid_sum' => 0,
        'total_remaining_sum' => 0,
    ];
    foreach ($orders as $order) {
        $page_totals['total_quantity_sum'] += $order['total_quantity'];
        $page_totals['total_subtotal_sum'] += $order['subtotal_amount'];
        $page_totals['total_discount_sum'] += $order['discount_amount'];
        $page_totals['total_damaged_sum'] += $order['damaged_amount']; // Add to sum
        $page_totals['total_amount_sum'] += $order['final_amount'];
        $page_totals['total_paid_sum'] += $order['paid_amount'];
        $page_totals['total_remaining_sum'] += ($order['final_amount'] - $order['paid_amount']);
    }
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع الطلبات: ' . $e->getMessage();
    $orders = [];
    $total_records = 0;
    $total_pages = 0;
}

include '../../includes/header.php';
?>

<!-- STYLES -->
<style>
    :root {
        --primary: #3b82f6;
        --success: #10b981;
        --danger: #ef4444;
    }

    .table-wrapper {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        margin: 20px;
    }

    .table-page-header {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
        padding: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .table-page-title {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
    }

    /* .filter-section is now used for the advanced, collapsible part */
    .filter-section {
        background: #f9fafb;
        padding: 20px;
        border-bottom: 1px solid #e5e7eb;
        display: none; /* Controlled by JS and PHP inline style for initial state */
    }

    .filter-section.active {
        display: block;
    }


    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin-bottom: 0; /* Removed bottom margin from grid for better spacing */
    }

    .filter-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 5px;
    }

    .form-control {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        font-size: 14px;
    }

    .vertical-table {
        width: 100%;
        border-collapse: collapse;
    }

    .vertical-table thead {
        background: #10b981;
        color: white;
    }

    .vertical-table th {
        padding: 12px 10px;
        text-align: right;
        font-weight: 600;
        font-size: 13px;
        white-space: nowrap;
    }

    .vertical-table tbody tr {
        border-bottom: 1px solid #e5e7eb;
    }

    .vertical-table tbody tr:hover {
        background: #f9fafb;
    }

    .vertical-table td {
        padding: 12px 10px;
        text-align: right;
        font-size: 13px;
        vertical-align: middle;
    }

    .status-dropdown {
        padding: 6px 10px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        min-width: 150px;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 8px 15px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        border: none;
        cursor: pointer;
        text-decoration: none;
    }

    .btn-primary {
        background: #3b82f6;
        color: white;
    }

    .btn-success {
        background: #10b981;
        color: white;
    }

    .btn-secondary {
        background: #6b7280;
        color: white;
    }

    .action-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        cursor: pointer;
    }

    .pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 20px;
        background: #f9fafb;
        border-top: 1px solid #e5e7eb;
    }

    .pagination-links a, .pagination-links span {
        padding: 6px 12px;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
        background: white;
        color: #374151;
        text-decoration: none;
        font-size: 14px;
        margin: 0 2px;
        display: inline-block;
    }

    .pagination-links a.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    .pagination-links span.disabled {
        background: #f3f4f6;
        color: #9ca3af;
        cursor: not-allowed;
    }

    .alert {
        padding: 15px;
        border-radius: 8px;
        margin: 20px;
    }

    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #10b981;
    }

    .alert-danger {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #ef4444;
    }
</style>

<div dir="rtl">
    <?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div><?php endif; ?>

    <div class="table-wrapper">
        <!-- Header -->
        <div class="table-page-header">
            <h2 class="table-page-title"><i class="fas fa-shopping-cart"></i> <?php echo $page_title; ?> (<?php echo $total_records; ?>)</h2>
            <div style="display: flex; gap: 10px;">
                <!-- Filter Toggle Button -->
                <button id="toggleFiltersBtn" class="btn btn-secondary">
                    <i class="fas fa-filter"></i> <span><?php echo $advanced_filters_active ? 'إخفاء الفلاتر المتقدمة' : 'إظهار الفلاتر المتقدمة'; ?></span>
                </button>
                <?php if ($can_add_orders): ?><a href="create.php" class="btn btn-success"><i class="fas fa-plus"></i> طلب جديد</a><?php endif; ?>
            </div>
        </div>

        <!-- Filters Section -->
        <form method="GET" action="">
            <!-- Always Visible Search and Action Row -->
            <div style="background: #f9fafb; padding: 20px; border-bottom: 1px solid #e5e7eb;">
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
                    <div class="filter-group" style="flex-grow: 1;"><label>البحث (رقم طلب، اسم، هاتف)</label><input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="رقم طلب، اسم، هاتف..." class="form-control"></div>
                    <div style="display: flex; gap: 10px; margin-bottom: 5px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
                        <a href="index.php" class="btn btn-secondary"><i class="fas fa-redo"></i> إلغاء</a>
                    </div>
                </div>
            </div>

            <!-- Collapsible Advanced Filters -->
            <div id="filterSection" class="filter-section" style="display: <?php echo $advanced_filters_active ? 'block' : 'none'; ?>;">
                <div class="filter-grid">
                    <div class="filter-group"><label>الحالة</label><select name="status" class="form-control">
                            <option value="">الكل</option><?php foreach ($all_statuses as $status): ?><option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo $status_filter == $status['status_key'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($status['status_name_ar']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="filter-group"><label>المبلغ المتبقي</label><select name="remaining" class="form-control">
                            <option value="">الكل</option>
                            <option value="has_remaining" <?php echo $remaining_filter == 'has_remaining' ? 'selected' : ''; ?>>له متبقي</option>
                            <option value="fully_paid" <?php echo $remaining_filter == 'fully_paid' ? 'selected' : ''; ?>>مدفوع بالكامل</option>
                        </select></div>
                    <div class="filter-group"><label>المجموعة</label><select name="group_id" class="form-control">
                            <option value="">الكل</option>
                            <option value="not_in_group" <?php echo $group_filter == 'not_in_group' ? 'selected' : ''; ?>>غير مصنف (-) </option>
                            <?php foreach ($groups as $group): ?><option value="<?php echo $group['id']; ?>" <?php echo $group_filter == $group['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($group['group_name']); ?></option><?php endforeach; ?>
                        </select></div>

                    <div class="filter-group"><label>نوع العميل</label><select name="customer_type" class="form-control">
                        <option value="">الكل</option>
                        <?php foreach ($customer_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" <?php echo $filter_customer_type == $type['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select></div>

                    <!-- NEW: Manual Order Filter Dropdown (based on presence of source_approval_id) -->
                    <div class="filter-group"><label>إنشاء الطلب</label><select name="manual_order" class="form-control">
                        <option value="">الكل</option>
                        <option value="1" <?php echo $manual_order_filter === '1' ? 'selected' : ''; ?>>يدوي</option>
                        <option value="0" <?php echo $manual_order_filter === '0' ? 'selected' : ''; ?>>من بوابة العملاء</option>
                    </select></div>

                    <div class="filter-group"><label>الموظف</label><select name="creator_id" class="form-control">
                            <option value="">الكل</option><?php foreach ($users as $user): ?><option value="<?php echo $user['id']; ?>" <?php echo $creator_filter == $user['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['username']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="filter-group"><label>من تاريخ</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control"></div>
                    <div class="filter-group"><label>إلى تاريخ</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control"></div>
                    <div class="filter-group"><label>ترتيب حسب</label><select name="sort_by" class="form-control">
                            <option value="created_at" <?php echo $sort_by == 'created_at' ? 'selected' : ''; ?>>تاريخ الإنشاء</option>
                            <option value="order_date" <?php echo $sort_by == 'order_date' ? 'selected' : ''; ?>>تاريخ الطلب</option>
                            <option value="final_amount" <?php echo $sort_by == 'final_amount' ? 'selected' : ''; ?>>المبلغ</option>
                            <option value="remaining_amount" <?php echo $sort_by == 'remaining_amount' ? 'selected' : ''; ?>>المتبقي</option>
                        </select></div>
                    <div class="filter-group"><label>الاتجاه</label><select name="sort_dir" class="form-control">
                            <option value="DESC" <?php echo $sort_dir == 'DESC' ? 'selected' : ''; ?>>تنازلي</option>
                            <option value="ASC" <?php echo $sort_dir == 'ASC' ? 'selected' : ''; ?>>تصاعدي</option>
                        </select></div>
                </div>
            </div>
        </form>

        <!-- Vertical Table -->
        <div style="overflow-x: auto;">
            <table class="vertical-table">
                <thead>
                    <tr>
                        <th>رقم الطلب</th>
                        <th>تاريخ الطلب</th>
                        <th>العميل</th>
                        <th>عدد القطع</th>
                        <th>رابط الطلب</th>
                        <th>رابط إضافي</th>
                        <th>الحالة</th>
                        <th>ملاحظات المدير</th>
                        <th>العملة</th>
                        <th>المبلغ الأصلي</th> <!-- This is your Gross Total -->
                        <th>الخصم</th>
                        <th>نسبة الخصم</th>
                        <th>مبلغ التوالف</th> <!-- New column -->
                        <th>المبلغ النهائي</th>
                        <th>المدفوع</th>
                        <th>المتبقي</th>
                        <th>رقم الفاتورة</th>
                        <th>المجموعة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody id="orders-table-body">
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="19" style="text-align: center; padding: 40px; color: #6b7280;">لا توجد طلبات تطابق معايير البحث</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order):
                            $remaining_amount = $order['final_amount'] - $order['paid_amount'];
                            // Determine if manual: if source_approval_id is empty, it's manual
                            $is_manual_order = empty($order['source_approval_id']);
                        ?>
                            <tr>
                                <!-- Icon logic updated to use $is_manual_order -->
                                <td>
                                    <strong><?php echo htmlspecialchars(formatOrderNumber($order['order_number'])); ?></strong>
                                    <?php if (!$is_manual_order): // If NOT manual, it's from portal ?>
                                        <i class="fas fa-globe" style="color: #3b82f6; margin-right: 5px; font-size: 13px;" title="تم إنشاؤه من بوابة العملاء (رقم الموافقة: <?php echo $order['source_approval_id']; ?>)"></i>
                                    <?php else: // If manual ?>
                                        <i class="fas fa-keyboard" style="color: #6c757d; margin-right: 5px; font-size: 13px;" title="طلب يدوي"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($order['order_date']); ?></td>
                                <td>
                                    <a href="../customers/view_enhanced.php?id=<?php echo $order['customer_id']; ?>" style="color: #3b82f6; text-decoration: none; font-weight: bold;">
                                        <div><?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?></div>
                                    </a>
                                    <small style="color: #6b7280;"><?php echo htmlspecialchars($order['mobile_number'] ?? ''); ?></small>
                                </td>
                                <td><strong><?php echo $order['total_quantity']; ?></strong></td>
                                <td>
                                    <?php $display_order_link = $order['order_link'] ?: $order['first_product_link']; ?>
                                    <?php if (!empty($display_order_link)): ?><a href="<?php echo htmlspecialchars($display_order_link); ?>" target="_blank" class="action-icon" style="background: #dbeafe; color: #1e40af;" title="فتح رابط الطلب"><i class="fas fa-external-link-alt"></i></a><?php else: ?>-<?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($order['additional_link'])): ?><a href="<?php echo htmlspecialchars($order['additional_link']); ?>" target="_blank" class="action-icon" style="background: #fef3c7; color: #92400e;" title="فتح الرابط الإضافي"><i class="fas fa-link"></i></a><?php else: ?>-<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($can_edit_orders): ?>
                                        <select class="status-dropdown" data-order-id="<?php echo $order['id']; ?>" data-original-status="<?php echo htmlspecialchars($order['status']); ?>">
                                            <?php foreach ($all_statuses as $status): ?><option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo ($order['status'] == $status['status_key']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($status['status_name_ar']); ?></option><?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <span><?php echo htmlspecialchars($order['status_name_ar'] ?? $order['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($order['manager_notes']); ?>">
                                    <?php echo !empty($order['manager_notes']) ? htmlspecialchars($order['manager_notes']) : '<span style="color: #9ca3af;">-</span>'; ?>
                                </td>
                                <td><span style="background: #eff6ff; color: #1d4ed8; padding: 4px 10px; border-radius: 20px; font-size: 11px;"><?php echo htmlspecialchars($order['currency']); ?></span></td>
                                <td style="color: #3b82f6;"><strong><?php echo number_format($order['subtotal_amount'], 0); ?></strong></td>
                                <td style="color: #f59e0b; font-weight: 600;"><?php echo number_format($order['discount_amount'], 0); ?></td>
                                <td style="color: #d97706; font-weight: 600; text-align: center;">
                                    <?php
                                    $discount_percentage = floatval($order['display_discount_percentage'] ?? 0);
                                    if ($discount_percentage > 0.01) {
                                        echo number_format($discount_percentage, 0) . '%';
                                        if (!empty($order['coupon_id'])) {
                                            echo ' <i class="fas fa-ticket-alt" title="خصم كوبون" style="color: #16a34a;"></i>';
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td style="color: #dc2626; font-weight: 700;"><?php echo number_format($order['damaged_amount'], 0); ?></td> <!-- New column display -->
                                <td style="color: #059669; font-weight: 700;"><?php echo number_format($order['final_amount'], 0); ?></td>
                                <td style="color: #10b981;"><?php echo number_format($order['paid_amount'], 0); ?></td>
                                <td style="color: <?php echo ($remaining_amount > 0.01) ? '#ef4444' : '#6b7280'; ?>;"><?php echo number_format($remaining_amount, 0); ?></td>
                                <td>
                                    <?php if (!empty($order['invoice_data'])): $invoices = explode(';', $order['invoice_data']);
                                        foreach ($invoices as $invoice_str): list($invoice_id, $invoice_number) = explode(':', $invoice_str, 2); ?>
                                            <a href="../invoices/view.php?id=<?php echo htmlspecialchars($invoice_id); ?>" style="display: block; color: #3b82f6; text-decoration: none; white-space: nowrap;"><?php echo htmlspecialchars($invoice_number); ?></a>
                                    <?php endforeach;
                                    else: echo '<span style="color: #9ca3af;">-</span>';
                                    endif; ?>
                                </td>
                                <td><?php $groupLabelParts = [];
                                    if (!empty($order['purchase_group_name'])) {
                                        $groupLabelParts[] = $order['purchase_group_name'];
                                    }
                                    if (!empty($order['purchase_group_number'])) {
                                        $groupLabelParts[] = $order['purchase_group_number'];
                                    }
                                    echo !empty($groupLabelParts) ? '<small>' . implode(' - ', array_map('htmlspecialchars', $groupLabelParts)) . '</small>' : '-'; ?></td>
                                <td>
                                    <div style="display: flex; gap: 5px; justify-content: center;">
                                        <!-- **NEW: Added button linking to the source approval record** -->
                                        <?php if (!$is_manual_order): // Only show approval link if it's NOT a manual order ?>
                                            <a href="view_approval.php?id=<?php echo $order['source_approval_id']; ?>" class="action-icon" style="background: #ecfdf5; color: #059669;" title="عرض طلب الموافقة الأصلي للعميل"><i class="fas fa-file-signature"></i></a>
                                        <?php endif; ?>
                                        <a href="view.php?id=<?php echo $order['id']; ?>" class="action-icon" style="background: #dbeafe; color: #1e40af;" title="عرض"><i class="fas fa-eye"></i></a>
                                        <?php if ($can_add_orders): ?>
                                            <button onclick="openManagerNotesModal(<?php echo $order['id']; ?>)" class="action-icon" style="background: #e0e7ff; color: #4338ca; border: none;" title="ملاحظات المدير"><i class="fas fa-user-shield"></i></button>
                                        <?php endif; ?>
                                        <?php if ($can_edit_orders): ?><a href="edit.php?id=<?php echo $order['id']; ?>" class="action-icon" style="background: #fef3c7; color: #92400e;" title="تعديل"><i class="fas fa-edit"></i></a><?php endif; ?>
                                        <a href="print.php?id=<?php echo $order['id']; ?>" target="_blank" class="action-icon" style="background: #f3f4f6; color: #374151;" title="طباعة"><i class="fas fa-print"></i></a>
                                        <?php if ($can_edit_orders): ?><button onclick="deleteOrder(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['order_number']); ?>')" class="action-icon" style="background: #fee2e2; color: #b91c1c; border: none;" title="حذف"><i class="fas fa-trash-alt"></i></button><?php endif; ?>
                                        <?php $phone_number = !empty($order['whatsapp_number']) ? $order['whatsapp_number'] : $order['mobile_number'];
                                        if (!empty($phone_number) && canView($user_id, 'whatsapp')): $whatsapp_url = '/modules/whatsapp/send.php?' . http_build_query(['customer_id' => $order['customer_id'], 'phone' => $phone_number, 'order_id' => $order['id']]); ?>
                                            <a href="<?php echo htmlspecialchars($whatsapp_url); ?>" class="action-icon" style="background: #25D366; color: white;" title="إرسال واتساب"><i class="fab fa-whatsapp"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($orders) && !empty($page_totals)): ?>
                    <tfoot style="background: #f3f4f6; border-top: 2px solid #d1d5db;">
                        <tr style="font-weight: bold; font-size: 14px;">
                            <td colspan="3" style="text-align: right; padding: 12px;"><i class="fas fa-calculator"></i> إجمالي الصفحة (<?php echo $page_totals['total_count']; ?>)</td>
                            <td><?php echo number_format($page_totals['total_quantity_sum'], 0); ?></td>
                            <td colspan="5"></td>
                            <td style="color: #3b82f6;"><?php echo number_format($page_totals['total_subtotal_sum'], 0); ?></td>
                            <td style="color: #f59e0b;"><?php echo number_format($page_totals['total_discount_sum'], 0); ?></td>
                            <td></td>
                            <td style="color: #ef4444;"><?php echo number_format($page_totals['total_damaged_sum'], 0); ?></td>
                            <td style="color: #059669;"><?php echo number_format($page_totals['total_amount_sum'], 0); ?></td>
                            <td style="color: #10b981;"><?php echo number_format($page_totals['total_paid_sum'], 0); ?></td>
                            <td style="color: #ef4444;"><?php echo number_format($page_totals['total_remaining_sum'], 0); ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <div style="font-size: 14px; color: #6b7280;">عرض <?php echo $offset + 1; ?> - <?php echo min($offset + $records_per_page, $total_records); ?> من <?php echo $total_records; ?></div>
                <div class="pagination-links">
                    <?php
                    // Display Previous link
                    if ($page > 1) {
                        $page_params = $_GET;
                        $page_params['page'] = $page - 1;
                        echo '<a href="?' . htmlspecialchars(http_build_query($page_params)) . '">السابق</a>';
                    } else {
                        echo '<span class="disabled">السابق</span>';
                    }

                    // Calculate a limited range of page numbers to display
                    $max_links = 5;
                    $start_page = max(1, $page - floor($max_links / 2));
                    $end_page = min($total_pages, $page + floor($max_links / 2));

                    // Adjust range if it hits the start or end
                    if ($start_page > 1) {
                        $end_page = min($total_pages, $end_page + ($start_page - 1));
                        $start_page = 1;
                        $page_params = $_GET;
                        $page_params['page'] = 1;
                        echo '<a href="?' . htmlspecialchars(http_build_query($page_params)) . '" class="' . (1 == $page ? 'active' : '') . '">1</a>';
                        if ($start_page < max(2, $page - floor($max_links / 2))) {
                            echo '<span style="padding: 6px 1px; font-size: 14px; color: #6b7280; border: none; background: transparent;">...</span>';
                        }
                        $start_page = max(2, $page - floor($max_links / 2));
                    }

                    if ($end_page < $total_pages) {
                        $start_page = max(1, $start_page - ($total_pages - $end_page));
                        $end_page = $total_pages;
                        if ($end_page > $page + floor($max_links / 2)) {
                            $end_page = $page + floor($max_links / 2);
                        }
                    }

                    for ($i = $start_page; $i <= $end_page; $i++):
                        $page_params = $_GET;
                        $page_params['page'] = $i;
                    ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query($page_params)); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor;

                    // Display ellipsis and last page if necessary
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) {
                            echo '<span style="padding: 6px 1px; font-size: 14px; color: #6b7280; border: none; background: transparent;">...</span>';
                        }
                        $page_params = $_GET;
                        $page_params['page'] = $total_pages;
                        echo '<a href="?' . htmlspecialchars(http_build_query($page_params)) . '" class="' . ($total_pages == $page ? 'active' : '') . '">' . $total_pages . '</a>';
                    }

                    // Display Next link
                    if ($page < $total_pages) {
                        $page_params = $_GET;
                        $page_params['page'] = $page + 1;
                        echo '<a href="?' . htmlspecialchars(http_build_query($page_params)) . '">التالي</a>';
                    } else {
                        echo '<span class="disabled">التالي</span>';
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Manager Notes Modal -->
<div id="managerNotesModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div style="background: white; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; text-align: right;">
        <h3 style="font-size: 20px; margin-bottom: 20px;">إضافة ملاحظة المدير</h3>
        <form id="managerNotesForm">
            <input type="hidden" id="managerNotesOrderId" name="order_id">
            <div style="margin-bottom: 15px;">
                <label for="managerNoteText" style="display: block; margin-bottom: 5px; font-weight: 600;">الملاحظة</label>
                <textarea id="managerNoteText" name="manager_note" rows="5" class="form-control" style="width: 100%;" placeholder="أضف ملاحظتك السرية هنا..."></textarea>
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeManagerNotesModal()" class="btn btn-secondary">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ الملاحظة</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 30px; max-width: 400px; text-align: center;">
        <div style="width: 60px; height: 60px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;"><i class="fas fa-exclamation-triangle" style="font-size: 30px; color: #ef4444;"></i></div>
        <h3 style="font-size: 20px; margin-bottom: 10px;">تأكيد الحذف</h3>
        <p style="color: #6b7280; margin-bottom: 5px;">هل أنت متأكد من حذف الطلب <strong id="orderNumberToDelete"></strong>؟</p>
        <p style="color: #ef4444; font-size: 14px; margin-bottom: 20px;">لا يمكن التراجع عن هذا الإجراء!</p>
        <div style="display: flex; gap: 10px; justify-content: center;"><button id="confirmDeleteBtn" class="btn" style="background: #ef4444; color: white;">حذف</button><button onclick="closeDeleteModal()" class="btn btn-secondary">إلغاء</button></div>
    </div>
</div>

<!-- SCRIPTS -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tableBody = document.getElementById('orders-table-body');
        const filterSection = document.getElementById('filterSection');
        const toggleFiltersBtn = document.getElementById('toggleFiltersBtn');
        const toggleFiltersBtnText = toggleFiltersBtn.querySelector('span');
        const advancedFiltersActive = <?php echo $advanced_filters_active ? 'true' : 'false'; ?>;

        // Set initial state for filter section based on PHP variable
        if (advancedFiltersActive) {
            filterSection.style.display = 'block';
            toggleFiltersBtnText.textContent = 'إخفاء الفلاتر المتقدمة';
        } else {
            filterSection.style.display = 'none';
            toggleFiltersBtnText.textContent = 'إظهار الفلاتر المتقدمة';
        }

        toggleFiltersBtn.addEventListener('click', function() {
            const isHidden = filterSection.style.display === 'none';
            if (isHidden) {
                filterSection.style.display = 'block';
                toggleFiltersBtnText.textContent = 'إخفاء الفلاتر المتقدمة';
            } else {
                filterSection.style.display = 'none';
                toggleFiltersBtnText.textContent = 'إظهار الفلاتر المتقدمة';
            }
        });


        tableBody.addEventListener('change', async function(event) {
            if (event.target.classList.contains('status-dropdown')) {
                const dropdown = event.target;
                const orderId = dropdown.dataset.orderId;
                const newStatusKey = dropdown.value;
                const originalStatusKey = dropdown.dataset.originalStatus;
                if (newStatusKey === 'custom_status') {
                    alert('إضافة حالة جديدة يجب أن تتم من صفحة الإعدادات.');
                    dropdown.value = originalStatusKey;
                } else {
                    if (confirm('هل أنت متأكد من تغيير حالة الطلب؟')) {
                        try {
                            const response = await fetch('api/update_order_status.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    order_id: orderId,
                                    status: newStatusKey
                                })
                            });
                            const result = await response.json();
                            if (result.success) {
                                dropdown.dataset.originalStatus = newStatusKey;
                            } else {
                                alert('Failed to update status: ' + result.message);
                                dropdown.value = originalStatusKey;
                            }
                        } catch (error) {
                            alert('An error occurred while updating the status.');
                            dropdown.value = originalStatusKey;
                        }
                    } else {
                        dropdown.value = originalStatusKey;
                    }
                }
            }
        });

        document.getElementById('managerNotesForm').addEventListener('submit', async function(event) {
            event.preventDefault();
            const form = event.target;
            const orderId = form.order_id.value;
            const note = form.manager_note.value;
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.textContent = 'جاري الحفظ...';

            try {
                const response = await fetch('api/add_manager_note.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        order_id: orderId,
                        manager_note: note
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert('تم حفظ الملاحظة بنجاح.');
                    closeManagerNotesModal();
                    // Reload the current page to display the new note, preserving filters/pagination
                    window.location.href = window.location.href.split('?')[0] + '?' + new URLSearchParams(window.location.search).toString();
                } else {
                    alert('فشل حفظ الملاحظة: ' + (result.message || 'خطأ غير معروف'));
                }
            } catch (error) {
                console.error('Error saving manager note:', error);
                alert('حدث خطأ أثناء الاتصال بالخادم.');
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = 'حفظ الملاحظة';
            }
        });
    });

    function openManagerNotesModal(orderId) {
        document.getElementById('managerNotesOrderId').value = orderId;
        document.getElementById('managerNoteText').value = '';
        document.getElementById('managerNotesModal').style.display = 'flex';
        document.getElementById('managerNoteText').focus();
    }

    function closeManagerNotesModal() {
        document.getElementById('managerNotesModal').style.display = 'none';
    }

    let orderIdToDelete = null;

    function deleteOrder(orderId, orderNumber) {
        orderIdToDelete = orderId;
        document.getElementById('orderNumberToDelete').textContent = orderNumber;
        document.getElementById('deleteModal').style.display = 'flex';
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
        orderIdToDelete = null;
    }
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (!orderIdToDelete) return;
        this.disabled = true;
        fetch('delete.php', {
            method: 'POST',
            body: new URLSearchParams('order_id=' + orderIdToDelete)
        }).then(res => res.json()).then(data => {
            if (data.success) {
                window.location.href = 'index.php?success=deleted';
            } else {
                alert('خطأ: ' + data.message);
                this.disabled = false;
            }
        }).catch(() => {
            alert('حدث خطأ');
            this.disabled = false;
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>