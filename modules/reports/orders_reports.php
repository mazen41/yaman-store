<?php
/**
 * Customer Orders - Summary Report
 * Version 1.0
 *
 * This report provides an aggregate summary of customer orders
 * based on the same filters available on the main orders page.
 * It calculates totals for quantities, amounts, payments, and remaining balances.
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

// --- 1. PERMISSIONS ---
$user_id = $_SESSION['user_id'] ?? 0;
if (!canViewOrders($user_id, $db)) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لعرض التقارير';
    header('Location: ../../index.php');
    exit();
}

// --- 2. INITIALIZATION & FILTERS ---
$page_title = 'تقرير ملخص طلبات العملاء';
$error_message = '';

// Get filter parameters from the URL
$status_filter = $_GET['status'] ?? '';
$creator_filter = $_GET['creator_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$group_filter = $_GET['group_id'] ?? '';
$remaining_filter = $_GET['remaining'] ?? '';

// --- 3. FETCH DATA ---
try {
    // Fetch data for all filter dropdowns
    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM customer_order_statuses ORDER BY is_default DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $users = $db->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
    $groups = $db->query("SELECT id, group_name FROM purchase_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // The common FROM and JOIN clauses
    $from_joins = "FROM customer_orders o
                   LEFT JOIN customers c ON o.customer_id = c.id
                   LEFT JOIN purchase_baskets pb ON o.basket_id = pb.id";

    // The main SELECT part for aggregation
    $select_part = "SELECT
                        COUNT(o.id) as total_orders_count,
                        COALESCE(SUM(o.final_amount), 0) as total_final_amount,
                        COALESCE(SUM(o.paid_amount), 0) as total_paid_amount,
                        COALESCE(SUM(o.final_amount - o.paid_amount), 0) as total_remaining_amount,
                        COALESCE(SUM(o.subtotal_amount), 0) as total_subtotal_amount,
                        COALESCE(SUM(o.discount_amount), 0) as total_discount_amount,
                        COALESCE(SUM((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id)), 0) as total_quantity_sum,
                        COALESCE(SUM((SELECT SUM(price) FROM order_damaged_items odi WHERE odi.order_id = o.id)), 0) as total_damaged_amount
                    ";

    // Base query
    $query = $select_part . " " . $from_joins . " WHERE 1=1";
    $params = [];

    // Appending all filters
    if ($status_filter) {
        if ($status_filter === 'new') {
            $query .= " AND o.status IN (?, ?)";
            $params[] = 'new';
            $params[] = 'processing';
        } else {
            $query .= " AND o.status = ?";
            $params[] = $status_filter;
        }
    }
    if ($creator_filter) {
        $query .= " AND o.created_by = ?";
        $params[] = $creator_filter;
    }
    if ($date_from) {
        $query .= " AND DATE(o.created_at) >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $query .= " AND DATE(o.created_at) <= ?";
        $params[] = $date_to;
    }
    if ($group_filter) {
        if ($group_filter === 'not_in_group') {
            $query .= " AND COALESCE(o.purchase_group_id, pb.purchase_group_id) IS NULL";
        } else {
            $query .= " AND COALESCE(o.purchase_group_id, pb.purchase_group_id) = ?";
            $params[] = $group_filter;
        }
    }
    if ($search) {
        $search_param = "%$search%";
        $query .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.mobile_number LIKE ?)";
        $params = array_merge($params, [$search_param, $search_param, $search_param]);
    }
    if ($remaining_filter === 'has_remaining') {
        $query .= " AND (o.final_amount - o.paid_amount) > 0.01";
    } elseif ($remaining_filter === 'fully_paid') {
        $query .= " AND (o.final_amount - o.paid_amount) <= 0.01";
    }

    // Fetch the aggregated data
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء إنشاء التقرير: ' . $e->getMessage();
    $report_data = [];
}

include '../../includes/header.php';
?>

<!-- STYLES -->
<style>
    :root {
        --primary: #3b82f6;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
    }

    .report-wrapper {
        background: #f9fafb;
        padding: 20px;
    }

    .report-header {
        background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
        color: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .report-title {
        font-size: 24px;
        font-weight: 700;
    }

    .filter-section {
        background: white;
        padding: 20px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        margin-bottom: 20px;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
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

    .btn-primary { background: var(--primary); color: white; }
    .btn-secondary { background: #6b7280; color: white; }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }

    .summary-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        border-left: 5px solid;
        display: flex;
        align-items: center;
        padding: 20px;
        gap: 20px;
    }
    .summary-card .icon {
        font-size: 32px;
        width: 60px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }
    .summary-card .info .value {
        font-size: 28px;
        font-weight: 700;
        color: #1f2937;
    }
    .summary-card .info .label {
        font-size: 14px;
        color: #6b7280;
    }

    .card-blue { border-color: #3b82f6; }
    .card-blue .icon { background: #dbeafe; color: #3b82f6; }
    .card-green { border-color: #10b981; }
    .card-green .icon { background: #d1fae5; color: #10b981; }
    .card-red { border-color: #ef4444; }
    .card-red .icon { background: #fee2e2; color: #ef4444; }
    .card-yellow { border-color: #f59e0b; }
    .card-yellow .icon { background: #fef3c7; color: #f59e0b; }
    .card-purple { border-color: #8b5cf6; }
    .card-purple .icon { background: #e9d5ff; color: #8b5cf6; }
    .card-gray { border-color: #6b7280; }
    .card-gray .icon { background: #e5e7eb; color: #6b7280; }


    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }

</style>

<div dir="rtl">
    <div class="report-wrapper">
        <!-- Header -->
        <div class="report-header">
            <h2 class="report-title"><i class="fas fa-chart-pie"></i> <?php echo $page_title; ?></h2>
            <a href="../index.php" class="btn btn-secondary" style="background: rgba(255,255,255,0.2);"><i class="fas fa-arrow-left"></i> العودة للطلبات</a>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group"><label>البحث</label><input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="رقم طلب، اسم..." class="form-control"></div>
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
                            <option value="not_in_group" <?php echo $group_filter == 'not_in_group' ? 'selected' : ''; ?>>-</option>
                            <?php foreach ($groups as $group): ?><option value="<?php echo $group['id']; ?>" <?php echo $group_filter == $group['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($group['group_name']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="filter-group"><label>الموظف</label><select name="creator_id" class="form-control">
                            <option value="">الكل</option><?php foreach ($users as $user): ?><option value="<?php echo $user['id']; ?>" <?php echo $creator_filter == $user['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['username']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="filter-group"><label>من تاريخ</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control"></div>
                    <div class="filter-group"><label>إلى تاريخ</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control"></div>
                </div>
                <div style="display: flex; gap: 10px;"><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> تطبيق الفلتر</button><a href="summary.php" class="btn btn-secondary"><i class="fas fa-redo"></i> مسح الفلتر</a></div>
            </form>
        </div>

        <!-- Summary Grid -->
        <?php if ($report_data): ?>
        <div class="summary-grid">
            <!-- Total Orders -->
            <div class="summary-card card-purple">
                <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="info">
                    <div class="value"><?php echo number_format($report_data['total_orders_count']); ?></div>
                    <div class="label">إجمالي عدد الطلبات</div>
                </div>
            </div>

            <!-- Total Quantity -->
            <div class="summary-card card-gray">
                <div class="icon"><i class="fas fa-box-open"></i></div>
                <div class="info">
                    <div class="value"><?php echo number_format($report_data['total_quantity_sum']); ?></div>
                    <div class="label">إجمالي عدد القطع</div>
                </div>
            </div>

            <!-- Subtotal Amount -->
            <div class="summary-card card-blue">
                <div class="icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="info">
                    <div class="value"><?php echo number_format($report_data['total_subtotal_amount'], 2); ?></div>
                    <div class="label">إجمالي المبلغ الأصلي</div>
                </div>
            </div>

            <!-- Total Discount -->
            <div class="summary-card card-yellow">
                <div class="icon"><i class="fas fa-percent"></i></div>
                <div class="info">
                    <div class="value"><?php echo number_format($report_data['total_discount_amount'], 2); ?></div>
                    <div class="label">إجمالي الخصومات</div>
                </div>
            </div>
            
            <!-- Total Damaged -->
            <div class="summary-card card-red">
                <div class="icon"><i class="fas fa-heart-broken"></i></div>
                <div class="info">
                    <div class="value"><?php echo number_format($report_data['total_damaged_amount'], 2); ?></div>
                    <div class="label">إجمالي مبلغ التوالف</div>
                </div>
            </div>

            <!-- Grand Total -->
            <div class="summary-card card-green">
                <div class="icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="info">
                    <div class="value"><?php echo number_format($report_data['total_final_amount'], 2); ?></div>
                    <div class="label">الإجمالي النهائي (بعد الخصم)</div>
                </div>
            </div>

            <!-- Total Paid -->
            <div class="summary-card card-green">
                <div class="icon"><i class="fas fa-money-check-alt"></i></div>
                <div class="info">
                    <div class="value" style="color: var(--success);"><?php echo number_format($report_data['total_paid_amount'], 2); ?></div>
                    <div class="label">إجمالي المدفوع</div>
                </div>
            </div>

            <!-- Total Remaining -->
            <div class="summary-card card-red">
                <div class="icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="info">
                    <div class="value" style="color: var(--danger);"><?php echo number_format($report_data['total_remaining_amount'], 2); ?></div>
                    <div class="label">إجمالي المتبقي</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>