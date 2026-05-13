<?php

/**
 * Shop Orders Management - Vertical Table Design
 * Based on the exact design provided, integrated with new shop_orders table.
 * Features:
 * - Cost and Profit Calculation (from products.purchase_amount)
 * - Payment Evidence Image Modal
 * - Integrated Permissions (View, Edit, Delete)
 * - Embedded AJAX for Status Update & Deletion
 * - Download Order Details as PDF
 */

// DEBUG: Enable error reporting (Remove or comment out in production)
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
// require_once '../../includes/status_helpers.php'; // Uncomment if needed in your system

$user_id = $_SESSION['user_id'] ?? 0;

// --- 1. PERMISSIONS ---
// التأكد من الصلاحيات بناءً على نظامك
// افترضنا أن اسم الموديول 'orders' لتطابق نظامك السابق، يمكنك تغييره لـ 'shop_orders' إذا لزم الأمر
$can_view_orders = hasPermission($user_id, 'shop_orders', 'view') || canViewOrders($user_id, $db);
if (!$can_view_orders) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لعرض الطلبات';
    header('Location: ../../index.php');
    exit();
}

$can_edit_orders = hasPermission($user_id, 'shop_orders', 'edit');
$can_delete_orders = hasPermission($user_id, 'shop_orders', 'delete');
// Assume a permission for download if you want to restrict it, e.g.:
// $can_download_orders = hasPermission($user_id, 'shop_orders', 'download');


// ==========================================
// 2. AJAX HANDLERS (مدمج لتغيير الحالة والحذف)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    try {
        if ($_POST['ajax_action'] === 'update_status' && $can_edit_orders) {
            $order_id = (int)$_POST['order_id'];
            $new_status = $_POST['status'];
            
            $stmt = $db->prepare("UPDATE shop_orders SET order_status = ? WHERE id = ?");
            if ($stmt->execute([$new_status, $order_id])) {
                $response['success'] = true;
                $response['message'] = 'تم تحديث حالة الطلب بنجاح';
            }
        } elseif ($_POST['ajax_action'] === 'delete_order' && $can_delete_orders) {
            $order_id = (int)$_POST['order_id'];
            
            // جلب مسار الصورة لحذفها من السيرفر إن وجدت
            $img_stmt = $db->prepare("SELECT payment_evidence_url FROM shop_orders WHERE id = ?");
            $img_stmt->execute([$order_id]);
            $img_url = $img_stmt->fetchColumn();
            
            $stmt = $db->prepare("DELETE FROM shop_orders WHERE id = ?");
            if ($stmt->execute([$order_id])) {
                if ($img_url && file_exists(__DIR__ . '/../../' . $img_url)) {
                    @unlink(__DIR__ . '/../../' . $img_url);
                }
                $response['success'] = true;
                $response['message'] = 'تم حذف الطلب بنجاح';
            }
        } else {
            $response['message'] = 'ليس لديك صلاحية للقيام بهذا الإجراء.';
        }
    } catch (PDOException $e) {
        $response['message'] = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// --- 3. INITIALIZATION & FILTERS ---
$page_title = 'إدارة طلبات المتجر (Shop Orders)';
$error_message = '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

$advanced_filters_active = !empty($status_filter) || !empty($date_from) || !empty($date_to);

$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_dir = $_GET['sort_dir'] ?? 'DESC';

$sort_options = [
    'created_at' => 'o.created_at',
    'total_amount' => 'o.total_amount',
    'profit' => 'profit_amount'
];
$sort_column = $sort_options[$sort_by] ?? 'o.created_at';
$sort_direction = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$records_per_page = 20;
$offset = ($page - 1) * $records_per_page;
$page = max(1, $page);

// --- 4. FETCH DATA ---
try {
    // قائمة الحالات الثابتة للطلبات الجديدة
    $all_statuses = ['طلب جديد', 'طلب معتمد', 'مرفوض'];

    $from_joins = "FROM shop_orders o
                   LEFT JOIN customers c ON o.customer_id = c.id";

    // الاستعلام الأساسي مع الحسابات (التكلفة والربح)
    $select_part = "SELECT 
                        o.id, 
                        o.order_number, 
                        o.created_at, 
                        o.total_amount, 
                        o.shipping_fee, 
                        o.discount_amount,
                        o.payment_evidence_url, 
                        o.order_status,
                        c.name AS customer_name,
                        c.mobile_number,
                        
                        -- جلب المنتجات بشكل نصي
                        (
                            SELECT GROUP_CONCAT(CONCAT(soi.quantity, 'x ', soi.product_name) SEPARATOR '<br>')
                            FROM shop_order_items soi
                            WHERE soi.order_id = o.id
                        ) AS products_list,
                        
                        -- حساب التكلفة
                        COALESCE((
                            SELECT SUM(p.purchase_amount * soi.quantity)
                            FROM shop_order_items soi
                            LEFT JOIN products p ON soi.product_id = p.id
                            WHERE soi.order_id = o.id
                        ), 0) AS total_cost,
                        
                        -- حساب إيراد المنتجات الصافي والربح
                        (o.total_amount - o.shipping_fee) AS net_revenue,
                        ((o.total_amount - o.shipping_fee) - COALESCE((
                            SELECT SUM(p.purchase_amount * soi.quantity)
                            FROM shop_order_items soi
                            LEFT JOIN products p ON soi.product_id = p.id
                            WHERE soi.order_id = o.id
                        ), 0)) AS profit_amount
                    ";

    $query = $select_part . " " . $from_joins . " WHERE 1=1";
    $count_query = "SELECT COUNT(o.id) " . $from_joins . " WHERE 1=1";
    $params = [];

    // Filters
    if ($status_filter) {
        $query .= " AND o.order_status = ?";
        $count_query .= " AND o.order_status = ?";
        $params[] = $status_filter;
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
    if ($search) {
        $search_param = "%$search%";
        $query .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.mobile_number LIKE ?)";
        $count_query .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.mobile_number LIKE ?)";
        $params = array_merge($params, [$search_param, $search_param, $search_param]);
    }

    // Pagination setup
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = (int) $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $records_per_page);

    if ($page > $total_pages && $total_pages > 0) {
        $page = $total_pages;
        $offset = ($page - 1) * $records_per_page;
    } else if ($total_pages === 0) {
        $page = 1;
        $offset = 0;
    }

    $query .= " ORDER BY {$sort_column} {$sort_direction} LIMIT $records_per_page OFFSET $offset";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totals calculation
    $page_totals = [
        'total_count' => count($orders),
        'total_revenue' => 0,
        'total_cost' => 0,
        'total_profit' => 0
    ];
    foreach ($orders as $order) {
        $page_totals['total_revenue'] += $order['net_revenue'];
        $page_totals['total_cost'] += $order['total_cost'];
        $page_totals['total_profit'] += $order['profit_amount'];
    }

} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع الطلبات: ' . $e->getMessage();
    $orders = [];
    $total_records = 0;
    $total_pages = 0;
}

include '../../includes/header.php';
?>

<!-- STYLES (Identical to your design) -->
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

    .table-page-title { font-size: 20px; font-weight: 700; margin: 0; }

    .filter-section {
        background: #f9fafb; padding: 20px; border-bottom: 1px solid #e5e7eb; display: none;
    }
    .filter-section.active { display: block; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 0; }
    .filter-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 5px; }

    .form-control { width: 100%; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; }

    .vertical-table { width: 100%; border-collapse: collapse; }
    .vertical-table thead { background: #10b981; color: white; }
    .vertical-table th { padding: 12px 10px; text-align: right; font-weight: 600; font-size: 13px; white-space: nowrap; }
    .vertical-table tbody tr { border-bottom: 1px solid #e5e7eb; transition: background 0.3s; }
    .vertical-table tbody tr:hover { background: #f9fafb; }
    .vertical-table td { padding: 12px 10px; text-align: right; font-size: 13px; vertical-align: middle; }

    .status-dropdown {
        padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; min-width: 130px;
    }

    .btn { display: inline-flex; align-items: center; gap: 5px; padding: 8px 15px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; }
    .btn-primary { background: #3b82f6; color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-secondary { background: #6b7280; color: white; }
    .btn-warning { background: #f59e0b; color: white; }

    .action-icon { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; border: none; }

    .pagination { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; }
    .pagination-links a, .pagination-links span { padding: 6px 12px; border-radius: 6px; border: 1px solid #e5e7eb; background: white; color: #374151; text-decoration: none; font-size: 14px; margin: 0 2px; display: inline-block; }
    .pagination-links a.active { background: var(--primary); color: white; border-color: var(--primary); }
    .pagination-links span.disabled { background: #f3f4f6; color: #9ca3af; cursor: not-allowed; }

    .alert { padding: 15px; border-radius: 8px; margin: 20px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }

    /* Image Modal specific */
    .image-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
    .image-modal-content { position: relative; max-width: 90%; max-height: 90vh; }
    .image-modal-content img { max-width: 100%; max-height: 90vh; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); background: white; }
    .image-modal-close { position: absolute; top: -40px; right: 0; color: white; font-size: 30px; cursor: pointer; background: transparent; border: none; }
</style>

<div dir="rtl">
    <?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div><?php endif; ?>

    <div class="table-wrapper">
        <!-- Header -->
        <div class="table-page-header">
            <h2 class="table-page-title"><i class="fas fa-shopping-bag"></i> <?php echo $page_title; ?> (<?php echo $total_records; ?>)</h2>
            <div style="display: flex; gap: 10px;">
                <button id="toggleFiltersBtn" class="btn btn-secondary">
                    <i class="fas fa-filter"></i> <span><?php echo $advanced_filters_active ? 'إخفاء الفلاتر المتقدمة' : 'إظهار الفلاتر المتقدمة'; ?></span>
                </button>
            </div>
        </div>

        <!-- Filters Section -->
        <form method="GET" action="">
            <div style="background: #f9fafb; padding: 20px; border-bottom: 1px solid #e5e7eb;">
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
                    <div class="filter-group" style="flex-grow: 1;">
                        <label>البحث (رقم طلب، اسم العميل، رقم الهاتف)</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ابحث هنا..." class="form-control">
                    </div>
                    <div style="display: flex; gap: 10px; margin-bottom: 5px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
                        <a href="shop_orders_manage.php" class="btn btn-secondary"><i class="fas fa-redo"></i> إلغاء</a>
                    </div>
                </div>
            </div>

            <!-- Advanced Filters -->
            <div id="filterSection" class="filter-section" style="display: <?php echo $advanced_filters_active ? 'block' : 'none'; ?>;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>الحالة</label>
                        <select name="status" class="form-control">
                            <option value="">الكل</option>
                            <?php foreach ($all_statuses as $status): ?>
                                <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status_filter == $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group"><label>من تاريخ</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control"></div>
                    <div class="filter-group"><label>إلى تاريخ</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control"></div>
                    <div class="filter-group">
                        <label>ترتيب حسب</label>
                        <select name="sort_by" class="form-control">
                            <option value="created_at" <?php echo $sort_by == 'created_at' ? 'selected' : ''; ?>>تاريخ الإنشاء</option>
                            <option value="total_amount" <?php echo $sort_by == 'total_amount' ? 'selected' : ''; ?>>الإجمالي</option>
                            <option value="profit" <?php echo $sort_by == 'profit' ? 'selected' : ''; ?>>الربح</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>الاتجاه</label>
                        <select name="sort_dir" class="form-control">
                            <option value="DESC" <?php echo $sort_dir == 'DESC' ? 'selected' : ''; ?>>تنازلي</option>
                            <option value="ASC" <?php echo $sort_dir == 'ASC' ? 'selected' : ''; ?>>تصاعدي</option>
                        </select>
                    </div>
                </div>
            </div>
        </form>

        <!-- Vertical Table -->
        <div style="overflow-x: auto;">
            <table class="vertical-table">
                <thead>
                    <tr>
                        <th>رقم الطلب</th>
                        <th>التاريخ</th>
                        <th>العميل</th>
                        <th>المنتجات (الكمية × الاسم)</th>
                        <th>إجمالي المنتجات (بيع)</th>
                        <th>التكلفة (شراء)</th>
                        <th>الربح الصافي</th>
                        <th>إيصال الدفع</th>
                        <th>حالة الطلب</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody id="orders-table-body">
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px; color: #6b7280;">لا توجد طلبات تطابق معايير البحث</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr id="row-<?php echo $order['id']; ?>">
                                <td><strong><?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                                <td style="color: #6b7280; font-size: 12px;"><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></td>
                                <td>
                                    <div style="color: #3b82f6; font-weight: bold;"><?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?></div>
                                    <small style="color: #6b7280;"><?php echo htmlspecialchars($order['mobile_number'] ?? ''); ?></small>
                                </td>
                                <td style="line-height: 1.6; font-size: 12px;"><?php echo $order['products_list']; ?></td>
                                
                                <!-- Calculations -->
                                <td style="color: #059669; font-weight: 700;"><?php echo number_format($order['net_revenue'], 2); ?></td>
                                <td style="color: #ef4444; font-weight: 700;"><?php echo number_format($order['total_cost'], 2); ?></td>
                                <td style="font-weight: 900; color: <?php echo $order['profit_amount'] > 0 ? '#2563eb' : ($order['profit_amount'] < 0 ? '#dc2626' : '#6b7280'); ?>;">
                                    <?php echo number_format($order['profit_amount'], 2); ?>
                                </td>
                                
                                <!-- Evidence Button -->
                                <td>
                                    <?php if (!empty($order['payment_evidence_url'])): ?>
                                        <button onclick="openImageModal('../../<?php echo htmlspecialchars($order['payment_evidence_url']); ?>')" class="btn btn-warning" style="font-size: 11px; padding: 5px 10px;">
                                            <i class="fas fa-image"></i> عرض
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #9ca3af; font-size: 12px;">لا يوجد</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Status Dropdown -->
                                <td>
                                    <?php if ($can_edit_orders): ?>
                                        <select class="status-dropdown" data-order-id="<?php echo $order['id']; ?>" data-original-status="<?php echo htmlspecialchars($order['order_status']); ?>" style="<?php echo $order['order_status'] == 'مرفوض' ? 'color:#dc2626;' : ($order['order_status'] == 'طلب معتمد' ? 'color:#059669;' : 'color:#d97706;'); ?>">
                                            <?php foreach ($all_statuses as $status): ?>
                                                <option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($order['order_status'] == $status) ? 'selected' : ''; ?>><?php echo htmlspecialchars($status); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <span style="font-weight: bold; <?php echo $order['order_status'] == 'مرفوض' ? 'color:#dc2626;' : ($order['order_status'] == 'طلب معتمد' ? 'color:#059669;' : 'color:#d97706;'); ?>"><?php echo htmlspecialchars($order['order_status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Actions -->
                                <td>
                                    <div style="display: flex; gap: 5px; justify-content: center;">
                                        <!-- يمكنك توجيه هذه الأزرار لصفحاتك الخاصة لاحقاً -->
                                  <a href="shop_order_view.php?id=<?php echo $order['id']; ?>" class="action-icon" style="background: #dbeafe; color: #1e40af;" title="عرض التفاصيل"><i class="fas fa-eye"></i></a>
                                        
                                        <!-- DOWNLOAD BUTTON MODIFIED HERE -->
                                        <a href="shop_order_download.php?id=<?php echo $order['id']; ?>" target="_blank" class="action-icon" style="background: #f3f4f6; color: #374151;" title="طباعة / تحميل"><i class="fas fa-download"></i></a>
                                        <?php if ($can_delete_orders): ?>
                                            <button onclick="confirmDelete(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['order_number']); ?>')" class="action-icon" style="background: #fee2e2; color: #b91c1c;" title="حذف"><i class="fas fa-trash-alt"></i></button>
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
                            <td colspan="4" style="text-align: right; padding: 12px;"><i class="fas fa-calculator"></i> إجمالي الصفحة (<?php echo $page_totals['total_count']; ?>)</td>
                            <td style="color: #059669;"><?php echo number_format($page_totals['total_revenue'], 2); ?></td>
                            <td style="color: #ef4444;"><?php echo number_format($page_totals['total_cost'], 2); ?></td>
                            <td style="color: #2563eb;"><?php echo number_format($page_totals['total_profit'], 2); ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <!-- Pagination (Identical) -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <div style="font-size: 14px; color: #6b7280;">عرض <?php echo $offset + 1; ?> - <?php echo min($offset + $records_per_page, $total_records); ?> من <?php echo $total_records; ?></div>
                <div class="pagination-links">
                    <?php
                    if ($page > 1) {
                        $page_params = $_GET; $page_params['page'] = $page - 1;
                        echo '<a href="?' . htmlspecialchars(http_build_query($page_params)) . '">السابق</a>';
                    } else { echo '<span class="disabled">السابق</span>'; }

                    $max_links = 5;
                    $start_page = max(1, $page - floor($max_links / 2));
                    $end_page = min($total_pages, $page + floor($max_links / 2));

                    if ($start_page > 1) {
                        $end_page = min($total_pages, $end_page + ($start_page - 1));
                        $start_page = 1; $page_params = $_GET; $page_params['page'] = 1;
                        echo '<a href="?' . htmlspecialchars(http_build_query($page_params)) . '" class="' . (1 == $page ? 'active' : '') . '">1</a>';
                        if ($start_page < max(2, $page - floor($max_links / 2))) { echo '<span style="padding: 6px 1px; color: #6b7280; border: none; background: transparent;">...</span>'; }
                        $start_page = max(2, $page - floor($max_links / 2));
                    }

                    if ($end_page < $total_pages) {
                        $start_page = max(1, $start_page - ($total_pages - $end_page));
                        $end_page = $total_pages;
                        if ($end_page > $page + floor($max_links / 2)) { $end_page = $page + floor($max_links / 2); }
                    }

                    for ($i = $start_page; $i <= $end_page; $i++):
                        $page_params = $_GET; $page_params['page'] = $i;
                        echo '<a href="?'.htmlspecialchars(http_build_query($page_params)).'" class="'.($i == $page ? 'active' : '').'">'.$i.'</a>';
                    endfor;

                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) { echo '<span style="padding: 6px 1px; color: #6b7280; border: none; background: transparent;">...</span>'; }
                        $page_params = $_GET; $page_params['page'] = $total_pages;
                        echo '<a href="?' . htmlspecialchars(http_build_query($page_params)) . '" class="' . ($total_pages == $page ? 'active' : '') . '">' . $total_pages . '</a>';
                    }

                    if ($page < $total_pages) {
                        $page_params = $_GET; $page_params['page'] = $page + 1;
                        echo '<a href="?' . htmlspecialchars(http_build_query($page_params)) . '">التالي</a>';
                    } else { echo '<span class="disabled">التالي</span>'; }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Image Modal (NEW) -->
<div id="imageEvidenceModal" class="image-modal-overlay" onclick="closeImageModal()">
    <div class="image-modal-content" onclick="event.stopPropagation()">
        <button class="image-modal-close" onclick="closeImageModal()">&times;</button>
        <img id="evidenceImageSrc" src="" alt="إيصال الدفع">
    </div>
</div>

<!-- Delete Modal (Identical to your design) -->
<div id="deleteModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 30px; max-width: 400px; text-align: center;">
        <div style="width: 60px; height: 60px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;"><i class="fas fa-exclamation-triangle" style="font-size: 30px; color: #ef4444;"></i></div>
        <h3 style="font-size: 20px; margin-bottom: 10px;">تأكيد الحذف</h3>
        <p style="color: #6b7280; margin-bottom: 5px;">هل أنت متأكد من حذف الطلب <strong id="orderNumberToDelete"></strong>؟</p>
        <p style="color: #ef4444; font-size: 14px; margin-bottom: 20px;">لا يمكن التراجع عن هذا الإجراء وسيتم حذف إيصال التحويل!</p>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button id="confirmDeleteBtn" class="btn" style="background: #ef4444; color: white;">حذف نهائي</button>
            <button onclick="closeDeleteModal()" class="btn btn-secondary">إلغاء</button>
        </div>
    </div>
</div>

<!-- SCRIPTS -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const filterSection = document.getElementById('filterSection');
        const toggleFiltersBtn = document.getElementById('toggleFiltersBtn');
        const toggleFiltersBtnText = toggleFiltersBtn.querySelector('span');
        const advancedFiltersActive = <?php echo $advanced_filters_active ? 'true' : 'false'; ?>;

        if (advancedFiltersActive) {
            filterSection.style.display = 'block';
            toggleFiltersBtnText.textContent = 'إخفاء الفلاتر المتقدمة';
        }

        toggleFiltersBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const isHidden = filterSection.style.display === 'none';
            if (isHidden) {
                filterSection.style.display = 'block';
                toggleFiltersBtnText.textContent = 'إخفاء الفلاتر المتقدمة';
            } else {
                filterSection.style.display = 'none';
                toggleFiltersBtnText.textContent = 'إظهار الفلاتر المتقدمة';
            }
        });

        // AJAX Status Update
        const tableBody = document.getElementById('orders-table-body');
        tableBody.addEventListener('change', async function(event) {
            if (event.target.classList.contains('status-dropdown')) {
                const dropdown = event.target;
                const orderId = dropdown.dataset.orderId;
                const newStatus = dropdown.value;
                const originalStatus = dropdown.dataset.originalStatus;

                if (confirm('هل أنت متأكد من تغيير حالة الطلب؟')) {
                    const formData = new FormData();
                    formData.append('ajax_action', 'update_status');
                    formData.append('order_id', orderId);
                    formData.append('status', newStatus);

                    try {
                        const response = await fetch('', { method: 'POST', body: formData });
                        const result = await response.json();
                        if (result.success) {
                            dropdown.dataset.originalStatus = newStatus;
                            // تحديث اللون بناءً على الحالة
                            dropdown.style.color = (newStatus === 'مرفوض') ? '#dc2626' : (newStatus === 'طلب معتمد' ? '#059669' : '#d97706');
                        } else {
                            alert('فشل التحديث: ' + result.message);
                            dropdown.value = originalStatus;
                        }
                    } catch (error) {
                        alert('حدث خطأ أثناء الاتصال بالخادم.');
                        dropdown.value = originalStatus;
                    }
                } else {
                    dropdown.value = originalStatus;
                }
            }
        });
    });

    // Delete Modal Logic
    let orderIdToDelete = null;

    function confirmDelete(orderId, orderNumber) {
        orderIdToDelete = orderId;
        document.getElementById('orderNumberToDelete').textContent = orderNumber;
        document.getElementById('deleteModal').style.display = 'flex';
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
        orderIdToDelete = null;
    }

    document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
        if (!orderIdToDelete) return;
        this.disabled = true;
        this.textContent = 'جاري الحذف...';

        const formData = new FormData();
        formData.append('ajax_action', 'delete_order');
        formData.append('order_id', orderIdToDelete);

        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                const row = document.getElementById('row-' + orderIdToDelete);
                if(row) {
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                }
                closeDeleteModal();
            } else {
                alert('خطأ: ' + data.message);
            }
        } catch(err) {
            alert('حدث خطأ بالاتصال بالسيرفر');
        } finally {
            this.disabled = false;
            this.textContent = 'حذف نهائي';
        }
    });

    // Image Modal Logic
    function openImageModal(imgUrl) {
        document.getElementById('evidenceImageSrc').src = imgUrl;
        document.getElementById('imageEvidenceModal').style.display = 'flex';
    }

    function closeImageModal() {
        document.getElementById('imageEvidenceModal').style.display = 'none';
        document.getElementById('evidenceImageSrc').src = '';
    }
</script>

<?php include '../../includes/footer.php'; ?>