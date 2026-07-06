<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login.php');
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/check_permissions.php';
require_once '../../../includes/status_helpers.php';
require_once '../../../includes/sorting_status_helpers.php';

$page_title = 'عرض مجموعة الشراء';
$group_id = intval($_GET['id'] ?? 0);
$success_message = '';
$error_message = '';

if (!$group_id) {
    header('Location: index.php');
    exit();
}

function isAdmin($user_id, $db) {
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];
    try {
        $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$user_id] = ($result && $result['is_admin'] == 1);
        return $cache[$user_id];
    } catch (PDOException $e) { return false; }
}

$current_user_id = $_SESSION['user_id'] ?? 0;
if (!hasPermission($current_user_id, 'purchase_groups', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لعرض مجموعات الشراء.';
    header('Location: ../../../index.php');
    exit();
}
$can_edit_groups  = hasPermission($current_user_id, 'purchase_groups', 'edit');
$can_edit_orders  = hasPermission($current_user_id, 'orders', 'edit');
$can_add_orders   = hasPermission($current_user_id, 'orders', 'add');
$isAdmin          = isAdmin($current_user_id, $db);

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_edit_groups) {
        $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل مجموعات الشراء.';
        header('Location: view.php?id=' . $group_id);
        exit();
    }
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'add_basket') {
                $basket_ids = isset($_POST['basket_ids']) ? $_POST['basket_ids'] : (isset($_POST['basket_id']) ? [$_POST['basket_id']] : []);
                $added_count = 0;
                foreach ($basket_ids as $basket_id) {
                    $basket_id = intval($basket_id);
                    if ($basket_id) { $db->prepare("UPDATE purchase_baskets SET purchase_group_id = ? WHERE id = ?")->execute([$group_id, $basket_id]); $added_count++; }
                }
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') { header('Content-Type: application/json'); echo json_encode(['success' => true, 'count' => $added_count]); exit(); }
                $success_message = "تم إضافة $added_count سلة بنجاح";
                header("Location: view.php?id=$group_id&success=basket_added"); exit();

            } elseif ($_POST['action'] === 'add_order') {
                $order_ids = isset($_POST['order_ids']) ? $_POST['order_ids'] : (isset($_POST['order_id']) ? [$_POST['order_id']] : []);
                $added_count = 0;
                foreach ($order_ids as $order_id) {
                    $order_id = intval($order_id);
                    if ($order_id) { $db->prepare("UPDATE customer_orders SET purchase_group_id = ? WHERE id = ?")->execute([$group_id, $order_id]); $added_count++; }
                }
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') { header('Content-Type: application/json'); echo json_encode(['success' => true, 'count' => $added_count]); exit(); }
                $success_message = "تم إضافة $added_count طلب بنجاح";
                header("Location: view.php?id=$group_id&success=order_added"); exit();

            } elseif ($_POST['action'] === 'remove_basket') {
                $basket_id = intval($_POST['basket_id'] ?? 0);
                if ($basket_id) { $db->prepare("UPDATE purchase_baskets SET purchase_group_id = NULL WHERE id = ? AND purchase_group_id = ?")->execute([$basket_id, $group_id]); header("Location: view.php?id=$group_id&success=basket_removed"); exit(); }

            } elseif ($_POST['action'] === 'remove_order') {
                $order_id = intval($_POST['order_id'] ?? 0);
                if ($order_id) { $db->prepare("UPDATE customer_orders SET purchase_group_id = NULL WHERE id = ? AND purchase_group_id = ?")->execute([$order_id, $group_id]); header("Location: view.php?id=$group_id&success=order_removed"); exit(); }

            } elseif ($_POST['action'] === 'delete_basket') {
                $basket_id = intval($_POST['basket_id'] ?? 0);
                if ($basket_id) {
                    $db->prepare("UPDATE purchase_baskets SET purchase_group_id = NULL WHERE id = ? AND purchase_group_id = ?")->execute([$basket_id, $group_id]);
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') { header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => 'تم إزالة السلة من المجموعة بنجاح.']); exit(); }
                    header("Location: view.php?id=$group_id&success=basket_removed"); exit();
                }

            } elseif ($_POST['action'] === 'delete_order') {
                $order_id = intval($_POST['order_id'] ?? 0);
                if ($order_id) {
                    $db->prepare("UPDATE customer_orders SET purchase_group_id = NULL WHERE id = ? AND purchase_group_id = ?")->execute([$order_id, $group_id]);
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') { header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => 'تم إزالة الطلب من المجموعة بنجاح']); exit(); }
                    header("Location: view.php?id=$group_id&success=order_removed"); exit();
                }

            } elseif ($_POST['action'] === 'update_order_status') {
                // Inline status update from the orders table dropdown
                $order_id  = intval($_POST['order_id'] ?? 0);
                $new_status = trim($_POST['status'] ?? '');
                if ($order_id && $new_status) {
                    $db->prepare("UPDATE customer_orders SET status = ? WHERE id = ?")->execute([$new_status, $order_id]);
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit(); }
                }
                header("Location: view.php?id=$group_id"); exit();
            }
        } catch (PDOException $e) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit(); }
            $error_message = 'حدث خطأ: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'basket_added':   $success_message = 'تم إضافة السلة بنجاح'; break;
        case 'order_added':    $success_message = 'تم إضافة الطلب بنجاح'; break;
        case 'basket_removed': $success_message = 'تم إزالة السلة بنجاح'; break;
        case 'order_removed':  $success_message = 'تم إزالة الطلب بنجاح'; break;
    }
}

try {
    // 1. Group details
    $stmt = $db->prepare("SELECT pg.*, u.full_name as created_by_name FROM purchase_groups pg LEFT JOIN users u ON pg.created_by = u.id WHERE pg.id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) { header('Location: index.php'); exit(); }

    // 2. All statuses (for the inline dropdown — mirrors orders/index.php)
    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM customer_order_statuses ORDER BY is_default DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Purchase baskets
    $baskets_stmt = $db->prepare("
        SELECT pb.id, pb.basket_code, pb.basket_name, pb.final_amount, pb.grand_total_yer, pb.status, pbs.status_name_ar, pb.created_at,
               pb.purchase_date, pb.subtotal_amount, pb.account_number, pb.total_items as items_count,
               (SELECT SUM(bi.quantity) FROM basket_items bi WHERE bi.basket_id = pb.id) as total_quantity,
               (SELECT GROUP_CONCAT(tracking_number SEPARATOR ', ') FROM basket_tracking WHERE basket_id = pb.id) AS tracking_numbers
        FROM purchase_baskets pb
        LEFT JOIN purchase_basket_statuses pbs ON pb.status = pbs.status_key
        WHERE pb.purchase_group_id = ?
        ORDER BY pb.created_at DESC
    ");
    $baskets_stmt->execute([$group_id]);
    $baskets = $baskets_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Customer orders — enriched to match orders/index.php columns exactly
    $customer_orders_stmt = $db->prepare("
        SELECT
            co.id,
            co.order_number,
            co.order_date,
            co.currency,
            co.order_link,
            co.additional_link,
            co.created_at,
            co.customer_id,
            co.status,
            co.coupon_id,
            co.manager_notes,
            co.basket_id,
            COALESCE(co.subtotal_amount, 0)  AS subtotal_amount,
            COALESCE(co.discount_amount, 0)  AS discount_amount,
            COALESCE(co.final_amount, 0)     AS final_amount,
            COALESCE(co.paid_amount, 0)      AS paid_amount,
            c.name                           AS customer_name,
            c.mobile_number,
            c.whatsapp_number,
            cos.status_name_ar,
            coup.discount_type               AS coupon_discount_type,
            coup.discount_value              AS coupon_discount_value,
            COALESCE(co.automatic_discount_percentage, 0) AS automatic_discount_percentage,
            (SELECT SUM(oi.quantity)  FROM order_items oi WHERE oi.order_id = co.id) AS total_quantity,
            (SELECT COUNT(*)          FROM order_items oi WHERE oi.order_id = co.id) AS sorting_total_items,
            (SELECT COUNT(*)          FROM order_items oi WHERE oi.order_id = co.id AND oi.status = 'scanned') AS sorting_sorted_items,
            (SELECT COALESCE(SUM(price), 0) FROM order_damaged_items odi WHERE odi.order_id = co.id) AS damaged_amount,
            (SELECT oi.product_link   FROM order_items oi WHERE oi.order_id = co.id AND oi.product_link IS NOT NULL AND oi.product_link <> '' ORDER BY oi.id LIMIT 1) AS first_product_link,
            (SELECT GROUP_CONCAT(CONCAT(ci.id, ':', ci.invoice_number) SEPARATOR ';') FROM customer_invoices ci WHERE ci.order_id = co.id) AS invoice_data,
            (SELECT id FROM order_approvals WHERE final_order_id = co.id LIMIT 1) AS source_approval_id,
            CASE
                WHEN co.coupon_id IS NOT NULL AND coup.discount_type = 'percentage' THEN coup.discount_value
                WHEN co.coupon_id IS NOT NULL AND coup.discount_type = 'fixed' AND co.subtotal_amount > 0.01 THEN (co.discount_amount / co.subtotal_amount) * 100
                ELSE co.automatic_discount_percentage
            END AS display_discount_percentage,
            pb.basket_code,
            pg2.group_name,
            pg2.group_number AS purchase_group_number
        FROM customer_orders co
        LEFT JOIN customers c     ON co.customer_id = c.id
        LEFT JOIN customer_order_statuses cos ON co.status = cos.status_key
        LEFT JOIN coupons coup    ON co.coupon_id  = coup.id
        LEFT JOIN purchase_baskets pb ON co.basket_id = pb.id
        LEFT JOIN purchase_groups pg2 ON co.purchase_group_id = pg2.id
        WHERE co.purchase_group_id = ?
        ORDER BY co.created_at DESC
    ");
    $customer_orders_stmt->execute([$group_id]);
    $customer_orders = $customer_orders_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Financial summary
    $stats = [
        'total_baskets'                  => count($baskets),
        'total_baskets_amount'           => array_sum(array_map(function($b) {
            return floatval($b['grand_total_yer'] ?? $b['final_amount'] ?? 0);
        }, $baskets)),
        'total_customer_orders'          => count($customer_orders),
        'total_customer_orders_amount'   => array_sum(array_column($customer_orders, 'final_amount')),
    ];
    $revenue         = $stats['total_customer_orders_amount'];
    $cost            = $stats['total_baskets_amount'];
    $profit          = $revenue - $cost;
    $profit_margin   = ($revenue > 0) ? ($profit / $revenue) * 100 : 0;
    $markup_pct      = ($cost > 0)    ? ($profit / $cost)    * 100 : 0;
    $stats['profit']           = $profit;
    $stats['profit_margin']    = $profit_margin;
    $stats['markup_percentage']= $markup_pct;

    $profit_color = 'text-gray-800';
    $profit_icon  = 'fas fa-minus';
    if ($stats['profit'] > 0.01)  { $profit_color = 'text-emerald-600'; $profit_icon = 'fas fa-arrow-up'; }
    elseif ($stats['profit'] < -0.01) { $profit_color = 'text-red-600'; $profit_icon = 'fas fa-arrow-down'; }

    // 6. Orders page totals (footer row)
    $order_totals = ['qty' => 0, 'subtotal' => 0, 'discount' => 0, 'damaged' => 0, 'final' => 0, 'paid' => 0, 'remaining' => 0];
    foreach ($customer_orders as $co) {
        $order_totals['qty']       += intval($co['total_quantity'] ?? 0);
        $order_totals['subtotal']  += floatval($co['subtotal_amount']);
        $order_totals['discount']  += floatval($co['discount_amount']);
        $order_totals['damaged']   += floatval($co['damaged_amount'] ?? 0);
        $order_totals['final']     += floatval($co['final_amount']);
        $order_totals['paid']      += floatval($co['paid_amount']);
        $order_totals['remaining'] += floatval($co['final_amount']) - floatval($co['paid_amount']);
    }

} catch (PDOException $e) {
    $error_message  = "حدث خطأ في قاعدة البيانات: " . $e->getMessage();
    $group          = [];
    $baskets        = [];
    $customer_orders= [];
    $all_statuses   = [];
    $stats          = array_fill_keys(['total_baskets','total_baskets_amount','total_customer_orders','total_customer_orders_amount','profit','profit_margin','markup_percentage'], 0);
    $profit_color   = 'text-gray-800';
    $profit_icon    = 'fas fa-minus';
    $order_totals   = ['qty'=>0,'subtotal'=>0,'discount'=>0,'damaged'=>0,'final'=>0,'paid'=>0,'remaining'=>0];
}

include '../../../includes/header.php';
?>

<!-- ═══════════════════════════════════════════════
     STYLES — mirrors orders/index.php exactly
     (scoped with .orders-index-mirror prefix to avoid
      colliding with Tailwind utilities used elsewhere)
     ═══════════════════════════════════════════════ -->
<style>
/* ── Sorting badges (copied verbatim from orders/index.php) ── */
.sorting-badge {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 6px; padding: 5px 11px; border-radius: 999px;
    font-size: 12px; font-weight: 700; border: 1px solid transparent;
    white-space: nowrap;
}
.sorting-badge small { font-size: 11px; opacity: .8; margin-right: 2px; }
.sorting-badge-success { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
.sorting-badge-pending  { background:#fffbeb; color:#92400e; border-color:#fde68a; }

/* ── Orders table wrapper ── */
.oim-table-wrapper {
    background: white;
    border-radius: 0 0 12px 12px; /* top corners belong to the section header */
    overflow: hidden;
}

/* ── Green header row (thead) ── */
.oim-table {
    width: 100%;
    border-collapse: collapse;
}
.oim-table thead { background: #10b981; color: white; }
.oim-table th {
    padding: 12px 10px; text-align: right;
    font-weight: 600; font-size: 13px; white-space: nowrap;
}

/* ── Body rows ── */
.oim-table tbody tr { border-bottom: 1px solid #e5e7eb; }
.oim-table tbody tr:hover { background: #f9fafb; }
.oim-table td {
    padding: 12px 10px; text-align: right;
    font-size: 13px; vertical-align: middle;
}

/* ── Status dropdown ── */
.oim-status-dropdown {
    padding: 6px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    font-size: 13px; font-weight: 600;
    cursor: pointer; min-width: 140px;
    background: white;
}

/* ── Action icon circles ── */
.oim-action-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border-radius: 50%; cursor: pointer;
    text-decoration: none; border: none;
    transition: opacity .15s;
}
.oim-action-icon:hover { opacity: .8; }

/* ── Footer totals row ── */
.oim-table tfoot tr {
    background: #f3f4f6;
    border-top: 2px solid #d1d5db;
    font-weight: bold; font-size: 14px;
}
.oim-table tfoot td { padding: 12px 10px; text-align: right; }

/* ── Empty state ── */
.oim-empty {
    text-align: center; padding: 40px; color: #6b7280; font-size: 14px;
}

/* ── Currency pill ── */
.oim-currency-pill {
    background: #eff6ff; color: #1d4ed8;
    padding: 4px 10px; border-radius: 20px; font-size: 11px;
}
</style>

<?php if ($success_message): ?>
    <div class="fixed top-4 right-4 z-50 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-lg" role="alert">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-2xl ml-3"></i>
            <p class="font-bold"><?php echo htmlspecialchars($success_message); ?></p>
        </div>
    </div>
    <script>setTimeout(() => { const a = document.querySelector('[role="alert"]'); if(a) a.remove(); }, 3000);</script>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="fixed top-4 right-4 z-50 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-lg">
        <div class="flex items-center"><i class="fas fa-exclamation-circle text-2xl ml-3"></i><p class="font-bold"><?php echo htmlspecialchars($error_message); ?></p></div>
    </div>
<?php endif; ?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Page header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h1 class="text-3xl font-bold text-white flex items-center">
                            <i class="fas fa-layer-group ml-3 text-purple-200"></i>
                            <?php echo htmlspecialchars($group['group_name'] ?? 'مجموعة غير موجودة'); ?>
                        </h1>
                        <p class="text-purple-100 mt-2 text-lg">رقم المجموعة: <?php echo htmlspecialchars($group['group_number'] ?? 'غير محدد'); ?></p>
                    </div>
                    <div class="flex gap-3">
                        <?php if ($can_edit_groups): ?>
                        <a href="edit.php?id=<?php echo $group_id; ?>" class="inline-flex items-center px-6 py-3 bg-white text-purple-600 rounded-xl hover:bg-purple-50 transition-all duration-200 shadow-lg font-semibold">
                            <i class="fas fa-edit ml-2"></i> تعديل
                        </a>
                        <?php endif; ?>
                        <a href="index.php" class="inline-flex items-center px-6 py-3 bg-purple-800 text-white rounded-xl hover:bg-purple-900 transition-all duration-200 shadow-lg font-semibold">
                            <i class="fas fa-arrow-right ml-2"></i> العودة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <!-- Financial analysis -->
        <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 border-t-4 border-indigo-500">
            <h2 class="text-2xl font-bold text-gray-900 flex items-center mb-6">
                <i class="fas fa-chart-line ml-3 text-indigo-500"></i> التحليل المالي للمجموعة
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 text-center">
                <div class="bg-teal-50 p-5 rounded-xl border border-teal-200">
                    <p class="text-md font-semibold text-teal-800">إجمالي المبيعات</p>
                    <div class="flex items-center justify-center gap-2 mt-2">
                        <p class="text-3xl font-bold text-teal-600"><?php echo number_format($stats['total_customer_orders_amount']); ?></p>
                        <span class="text-md font-semibold text-teal-700">ر.ي</span>
                    </div>
                </div>
                <div class="bg-orange-50 p-5 rounded-xl border border-orange-200">
                    <p class="text-md font-semibold text-orange-800">إجمالي التكاليف</p>
                    <div class="flex items-center justify-center gap-2 mt-2">
                        <p class="text-3xl font-bold text-orange-600"><?php echo number_format($stats['total_baskets_amount']); ?></p>
                        <span class="text-md font-semibold text-orange-700">ر.ي</span>
                    </div>
                </div>
                <div class="bg-gray-50 p-5 rounded-xl border <?php echo ($stats['profit'] > 0.01) ? 'border-emerald-300' : (($stats['profit'] < -0.01) ? 'border-red-300' : 'border-gray-200'); ?>">
                    <p class="text-md font-semibold text-gray-800">صافي الربح / الخسارة</p>
                    <div class="flex items-center justify-center gap-2 mt-2">
                        <i class="<?php echo $profit_icon; ?> text-xl <?php echo $profit_color; ?>"></i>
                        <p class="text-3xl font-bold <?php echo $profit_color; ?>"><?php echo number_format(abs($stats['profit'])); ?></p>
                        <span class="text-md font-semibold <?php echo $profit_color; ?>">ر.ي</span>
                    </div>
                </div>
                <div class="bg-indigo-50 p-5 rounded-xl border border-indigo-200">
                    <p class="text-md font-semibold text-indigo-800">نسبة الربح (%)</p>
                    <div class="flex flex-col items-center justify-center mt-2">
                        <p class="text-3xl font-bold <?php echo $profit_color; ?>"><?php echo number_format($stats['profit_margin'], 2); ?>%</p>
                        <p class="text-xs text-indigo-500 mt-1">من إجمالي المبيعات</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════
             PURCHASE BASKETS TABLE  (unchanged design)
             ══════════════════════════════════════════════════ -->
        <div class="bg-white shadow-xl rounded-2xl overflow-hidden mb-8">
            <div class="px-6 py-4 bg-gradient-to-r from-gray-50 to-gray-100 border-b-2 border-gray-200 flex items-center justify-between">
                <h2 class="text-xl font-bold text-gray-900 flex items-center">
                    <i class="fas fa-shopping-basket ml-2 text-amber-600"></i> سلال الشراء المرتبطة
                </h2>
                <?php if ($can_edit_groups): ?>
                <button onclick="openAddBasketModal()" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-all shadow-lg">
                    <i class="fas fa-plus ml-2"></i> إضافة سلة
                </button>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto">
                <?php if (empty($baskets)): ?>
                    <p class="p-6 text-center text-gray-500">لا توجد سلال شراء مرتبطة.</p>
                <?php else: ?>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">اسم السلة</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">الكود</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">رقم الحساب</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">رقم التتبع</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">عدد المنتجات</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">رقم السلة</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">تاريخ الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">الحالة</th>
                            <th class="px-6 py-3 text-center text-xs font-bold text-gray-700">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        $sum_basket_items = 0;
                        foreach ($baskets as $basket):
                            $sum_basket_items += intval($basket['items_count'] ?? 0);
                            $st = ['active'=>'نشطة','ordered'=>'تم الطلب','ready_to_deliver'=>'جاهز للتسليم','delivered'=>'مسلمة','under_inspection'=>'قيد الفحص','completed'=>'مكتملة','finished'=>'منتهية','cancelled'=>'ملغية','shipped'=>'مشحونة','t'=>'ترانزيت'];
                            $raw_status     = strtolower(trim($basket['status'] ?? ''));
                            $display_status = $basket['status_name_ar'] ?? ($st[$raw_status] ?? $basket['status']);
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($basket['basket_name'] ?? '-'); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($basket['basket_code']); ?></td>
                            <td class="px-6 py-4 text-sm font-bold text-indigo-600"><?php echo htmlspecialchars($basket['account_number'] ?? '-'); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo !empty($basket['tracking_numbers']) ? '<span><i class="fas fa-truck ml-1"></i> '.htmlspecialchars((string)$basket['tracking_numbers']).'</span>' : '-'; ?></td>
                            <td class="px-6 py-4 text-center text-sm font-bold text-blue-600"><?php echo intval($basket['items_count'] ?? 0); ?></td>
                            <td class="px-6 py-4 text-sm font-bold text-blue-600"><?php echo htmlspecialchars($basket['id']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-700"><?php echo $basket['purchase_date'] ? date('Y/m/d', strtotime($basket['purchase_date'])) : date('Y/m/d', strtotime($basket['created_at'])); ?></td>
                            <td class="px-6 py-4"><span class="px-3 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-800"><?php echo htmlspecialchars($display_status); ?></span></td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="/modules/purchases/view_basket.php?id=<?php echo $basket['id']; ?>" class="w-8 h-8 flex items-center justify-center rounded-full bg-blue-100 text-blue-600 hover:bg-blue-600 hover:text-white transition-all"><i class="fas fa-eye text-sm"></i></a>
                                    <?php if ($can_edit_groups): ?><button onclick="deleteBasket(<?php echo $basket['id']; ?>, '<?php echo htmlspecialchars(addslashes($basket['basket_code'])); ?>')" class="w-8 h-8 flex items-center justify-center rounded-full bg-red-100 text-red-600 hover:bg-red-600 hover:text-white transition-all"><i class="fas fa-trash text-sm"></i></button><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-100 font-bold border-t-2 border-gray-300">
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-gray-900">إجمالي عدد المنتجات:</td>
                            <td class="px-6 py-4 text-center text-blue-700 font-bold"><?php echo $sum_basket_items; ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             CUSTOMER ORDERS TABLE
             Design: exact replica of modules/orders/index.php
             ══════════════════════════════════════════════════ -->
        <div class="oim-table-wrapper shadow-xl rounded-2xl overflow-hidden mb-8">

            <!-- Section header — styled to match the page but keeps the green thead below -->
            <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color:white; padding:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
                <h2 style="font-size:20px; font-weight:700; margin:0;">
                    <i class="fas fa-shopping-cart"></i>
                    طلبات العملاء المرتبطة
                    <span style="font-size:15px; font-weight:500; opacity:.85;">(<?php echo count($customer_orders); ?>)</span>
                </h2>
                <?php if ($can_edit_groups): ?>
                <button onclick="openAddOrderModal()" style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; background:#10b981; color:white; font-weight:600; font-size:14px; border:none; cursor:pointer;">
                    <i class="fas fa-plus"></i> إضافة طلب
                </button>
                <?php endif; ?>
            </div>

            <!-- Table -->
            <div style="overflow-x: auto;">
                <table class="oim-table" id="orders-table-body-wrapper">
                    <thead>
                        <tr>
                            <th>رقم الطلب</th>
                            <th>تاريخ الطلب</th>
                            <th>العميل</th>
                            <th>عدد القطع</th>
                            <th>رابط الطلب</th>
                            <th>رابط إضافي</th>
                            <th>الحالة</th>
                            <th>حالة الفرز</th>
                            <th>ملاحظات المدير</th>
                            <th>العملة</th>
                            <th>المبلغ الأصلي</th>
                            <th>الخصم</th>
                            <th>نسبة الخصم</th>
                            <th>مبلغ التوالف</th>
                            <th>المبلغ النهائي</th>
                            <th>المدفوع</th>
                            <th>المتبقي</th>
                            <th>رقم الفاتورة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="group-orders-table-body">
                    <?php if (empty($customer_orders)): ?>
                        <tr><td colspan="19" class="oim-empty">لا توجد طلبات عملاء مرتبطة بهذه المجموعة</td></tr>
                    <?php else: ?>
                        <?php foreach ($customer_orders as $co):
                            $remaining_amount      = floatval($co['final_amount']) - floatval($co['paid_amount']);
                            $is_manual_order       = empty($co['source_approval_id']);
                            $order_sorting_summary = getOrderSortingSummaryFromRow($co);
                            $display_order_link    = $co['order_link'] ?: $co['first_product_link'];
                        ?>
                        <tr>
                            <!-- Order number + type icon -->
                            <td>
                                <strong><?php echo htmlspecialchars(formatOrderNumber($co['order_number'])); ?></strong>
                                <?php if (!$is_manual_order): ?>
                                    <i class="fas fa-globe" style="color:#3b82f6; margin-right:5px; font-size:13px;" title="من بوابة العملاء (<?php echo $co['source_approval_id']; ?>)"></i>
                                <?php else: ?>
                                    <i class="fas fa-keyboard" style="color:#6c757d; margin-right:5px; font-size:13px;" title="طلب يدوي"></i>
                                <?php endif; ?>
                            </td>

                            <!-- Order date -->
                            <td><?php echo htmlspecialchars($co['order_date'] ?? ''); ?></td>

                            <!-- Customer name (blue link) + phone -->
                            <td>
                                <a href="../../customers/view_enhanced.php?id=<?php echo $co['customer_id']; ?>" style="color:#3b82f6; text-decoration:none; font-weight:bold;">
                                    <?php echo htmlspecialchars($co['customer_name'] ?? 'N/A'); ?>
                                </a>
                                <br><small style="color:#6b7280;"><?php echo htmlspecialchars($co['mobile_number'] ?? ''); ?></small>
                            </td>

                            <!-- Quantity -->
                            <td><strong><?php echo intval($co['total_quantity'] ?? 0); ?></strong></td>

                            <!-- Order link -->
                            <td>
                                <?php if (!empty($display_order_link)): ?>
                                    <a href="<?php echo htmlspecialchars($display_order_link); ?>" target="_blank" class="oim-action-icon" style="background:#dbeafe; color:#1e40af;" title="فتح رابط الطلب"><i class="fas fa-external-link-alt"></i></a>
                                <?php else: ?>-<?php endif; ?>
                            </td>

                            <!-- Additional link -->
                            <td>
                                <?php if (!empty($co['additional_link'])): ?>
                                    <a href="<?php echo htmlspecialchars($co['additional_link']); ?>" target="_blank" class="oim-action-icon" style="background:#fef3c7; color:#92400e;" title="فتح الرابط الإضافي"><i class="fas fa-link"></i></a>
                                <?php else: ?>-<?php endif; ?>
                            </td>

                            <!-- Status dropdown (editable if can_edit_orders) -->
                            <td>
                                <?php if ($can_edit_orders && !empty($all_statuses)): ?>
                                    <select class="oim-status-dropdown"
                                            data-order-id="<?php echo $co['id']; ?>"
                                            data-original-status="<?php echo htmlspecialchars($co['status']); ?>">
                                        <?php foreach ($all_statuses as $s): ?>
                                            <option value="<?php echo htmlspecialchars($s['status_key']); ?>" <?php echo ($co['status'] == $s['status_key']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($s['status_name_ar']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <span><?php echo htmlspecialchars($co['status_name_ar'] ?? $co['status']); ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Sorting badge -->
                            <td><?php echo renderOrderSortingBadge($order_sorting_summary); ?></td>

                            <!-- Manager notes -->
                            <td style="max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($co['manager_notes'] ?? ''); ?>">
                                <?php echo !empty($co['manager_notes']) ? htmlspecialchars($co['manager_notes']) : '<span style="color:#9ca3af;">-</span>'; ?>
                            </td>

                            <!-- Currency -->
                            <td><span class="oim-currency-pill"><?php echo htmlspecialchars($co['currency'] ?? 'SAR'); ?></span></td>

                            <!-- Subtotal (gross) -->
                            <td style="color:#3b82f6;"><strong><?php echo number_format($co['subtotal_amount'], 0); ?></strong></td>

                            <!-- Discount amount -->
                            <td style="color:#f59e0b; font-weight:600;"><?php echo number_format($co['discount_amount'], 0); ?></td>

                            <!-- Discount % -->
                            <td style="color:#d97706; font-weight:600; text-align:center;">
                                <?php
                                $dp = floatval($co['display_discount_percentage'] ?? 0);
                                if ($dp > 0.01) {
                                    echo number_format($dp, 0) . '%';
                                    if (!empty($co['coupon_id'])) echo ' <i class="fas fa-ticket-alt" title="خصم كوبون" style="color:#16a34a;"></i>';
                                } else { echo '-'; }
                                ?>
                            </td>

                            <!-- Damaged amount -->
                            <td style="color:#dc2626; font-weight:700;"><?php echo number_format($co['damaged_amount'] ?? 0, 0); ?></td>

                            <!-- Final amount -->
                            <td style="color:#059669; font-weight:700;"><?php echo number_format($co['final_amount'], 0); ?></td>

                            <!-- Paid -->
                            <td style="color:#10b981;"><?php echo number_format($co['paid_amount'], 0); ?></td>

                            <!-- Remaining -->
                            <td style="color:<?php echo ($remaining_amount > 0.01) ? '#ef4444' : '#6b7280'; ?>;"><?php echo number_format($remaining_amount, 0); ?></td>

                            <!-- Invoice numbers -->
                            <td>
                                <?php if (!empty($co['invoice_data'])):
                                    foreach (explode(';', $co['invoice_data']) as $invoice_str):
                                        [$inv_id, $inv_num] = explode(':', $invoice_str, 2); ?>
                                        <a href="../../invoices/view.php?id=<?php echo htmlspecialchars($inv_id); ?>" style="display:block; color:#3b82f6; text-decoration:none; white-space:nowrap;"><?php echo htmlspecialchars($inv_num); ?></a>
                                    <?php endforeach;
                                else: echo '<span style="color:#9ca3af;">-</span>';
                                endif; ?>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div style="display:flex; gap:5px; justify-content:center;">
                                    <?php if (!$is_manual_order): ?>
                                        <a href="../../orders/view_approval.php?id=<?php echo $co['source_approval_id']; ?>" class="oim-action-icon" style="background:#ecfdf5; color:#059669;" title="عرض طلب الموافقة"><i class="fas fa-file-signature"></i></a>
                                    <?php endif; ?>
                                    <a href="../../orders/view.php?id=<?php echo $co['id']; ?>" class="oim-action-icon" style="background:#dbeafe; color:#1e40af;" title="عرض"><i class="fas fa-eye"></i></a>
                                    <?php if ($can_edit_orders): ?>
                                        <a href="../../orders/edit.php?id=<?php echo $co['id']; ?>" class="oim-action-icon" style="background:#fef3c7; color:#92400e;" title="تعديل"><i class="fas fa-edit"></i></a>
                                    <?php endif; ?>
                                    <a href="../../orders/print.php?id=<?php echo $co['id']; ?>" target="_blank" class="oim-action-icon" style="background:#f3f4f6; color:#374151;" title="طباعة"><i class="fas fa-print"></i></a>
                                    <?php if ($can_edit_groups): ?>
                                        <button onclick="removeOrder(<?php echo $co['id']; ?>, '<?php echo htmlspecialchars(addslashes(formatOrderNumber($co['order_number']))); ?>')"
                                                class="oim-action-icon" style="background:#fee2e2; color:#b91c1c;" title="إزالة من المجموعة">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php
                                    $phone_number = !empty($co['whatsapp_number']) ? $co['whatsapp_number'] : $co['mobile_number'];
                                    if (!empty($phone_number) && function_exists('canView') && canView($current_user_id, 'whatsapp')):
                                        $wa_url = '/modules/whatsapp/send.php?' . http_build_query(['customer_id'=>$co['customer_id'],'phone'=>$phone_number,'order_id'=>$co['id']]);
                                    ?>
                                        <a href="<?php echo htmlspecialchars($wa_url); ?>" class="oim-action-icon" style="background:#25D366; color:white;" title="إرسال واتساب"><i class="fab fa-whatsapp"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>

                    <!-- Footer totals — mirrors orders/index.php tfoot exactly -->
                    <?php if (!empty($customer_orders)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3"><i class="fas fa-calculator"></i> إجمالي الصفحة (<?php echo count($customer_orders); ?>)</td>
                            <td><?php echo number_format($order_totals['qty'], 0); ?></td>
                            <td colspan="6"></td>
                            <td style="color:#3b82f6;"><?php echo number_format($order_totals['subtotal'], 0); ?></td>
                            <td style="color:#f59e0b;"><?php echo number_format($order_totals['discount'], 0); ?></td>
                            <td></td>
                            <td style="color:#ef4444;"><?php echo number_format($order_totals['damaged'], 0); ?></td>
                            <td style="color:#059669;"><?php echo number_format($order_totals['final'], 0); ?></td>
                            <td style="color:#10b981;"><?php echo number_format($order_totals['paid'], 0); ?></td>
                            <td style="color:#ef4444;"><?php echo number_format($order_totals['remaining'], 0); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div><!-- /overflow-x:auto -->
        </div><!-- /orders section -->

    </div><!-- /max-w container -->
</div><!-- /min-h-screen -->


<!-- ══════════════════════════════════════════════════
     MODALS
     ══════════════════════════════════════════════════ -->

<!-- Add basket modal -->
<div id="addBasketModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" onclick="if(event.target===this)closeAddBasketModal()">
    <div class="bg-white rounded-2xl p-8 max-w-3xl w-full mx-4 max-h-[85vh] overflow-hidden flex flex-col">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-bold text-gray-900">إضافة سلال شراء للمجموعة</h3>
            <button onclick="closeAddBasketModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-2xl"></i></button>
        </div>
        <div class="mb-4"><input type="text" id="basketSearch" placeholder="🔍 ابحث عن سلة..." class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-right" oninput="filterBaskets()"></div>
        <div id="basketsList" class="flex-1 overflow-y-auto border-2 border-gray-200 rounded-lg mb-4"></div>
        <div class="flex gap-3 justify-end pt-4 border-t-2">
            <button onclick="closeAddBasketModal()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold">إلغاء</button>
            <button onclick="addSelectedBasketsToGroup()" class="px-6 py-3 bg-amber-600 text-white rounded-lg font-semibold">إضافة المختارة</button>
        </div>
    </div>
</div>

<!-- Add order modal -->
<div id="addOrderModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" onclick="if(event.target===this)closeAddOrderModal()">
    <div class="bg-white rounded-2xl p-8 max-w-3xl w-full mx-4 max-h-[85vh] overflow-hidden flex flex-col">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-bold text-gray-900">إضافة طلبات عملاء للمجموعة</h3>
            <button onclick="closeAddOrderModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-2xl"></i></button>
        </div>
        <div class="mb-4"><input type="text" id="orderSearch" placeholder="🔍 ابحث عن طلب..." class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-right" oninput="filterOrders()"></div>
        <div id="ordersList" class="flex-1 overflow-y-auto border-2 border-gray-200 rounded-lg mb-4"></div>
        <div class="flex gap-3 justify-end pt-4 border-t-2">
            <button onclick="closeAddOrderModal()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold">إلغاء</button>
            <button onclick="addSelectedOrdersToGroup()" class="px-6 py-3 bg-teal-600 text-white rounded-lg font-semibold">إضافة المختارة</button>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════ -->
<script>
const groupId = <?php echo $group_id; ?>;
let allBaskets = [], allOrders = [];
let selectedBasketIds = new Set(), selectedOrderIds = new Set();

/* ─── Basket modal ─── */
async function loadAvailableBaskets() {
    try {
        const r = await fetch('/api_get_available_baskets.php');
        const d = await r.json();
        if (d.success) { allBaskets = d.baskets; renderBaskets(allBaskets); }
    } catch(e) { console.error(e); }
}
function renderBaskets(baskets) {
    document.getElementById('basketsList').innerHTML = baskets.map(b => `
        <label class="flex items-center p-4 border-b hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" onchange="toggleBasket(${b.id})" class="ml-3">
            <div class="flex-1 flex justify-between">
                <span class="font-bold">${b.basket_code}</span>
                <span class="text-green-600">${parseFloat(b.final_amount).toLocaleString()} ر.ي</span>
            </div>
        </label>`).join('');
}
function filterBaskets() {
    const q = document.getElementById('basketSearch').value.toLowerCase();
    renderBaskets(allBaskets.filter(b => b.basket_code.toLowerCase().includes(q)));
}
function toggleBasket(id) { selectedBasketIds.has(id) ? selectedBasketIds.delete(id) : selectedBasketIds.add(id); }
async function addSelectedBasketsToGroup() {
    const fd = new FormData();
    fd.append('action','add_basket');
    [...selectedBasketIds].forEach(id => fd.append('basket_ids[]', id));
    const r = await fetch(window.location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
    if ((await r.json()).success) window.location.reload();
}
function openAddBasketModal()  { document.getElementById('addBasketModal').classList.remove('hidden'); loadAvailableBaskets(); }
function closeAddBasketModal() { document.getElementById('addBasketModal').classList.add('hidden'); }

/* ─── Order modal ─── */
async function loadAvailableOrders() {
    try {
        const r = await fetch('/api_get_available_orders.php');
        const d = await r.json();
        if (d.success) { allOrders = d.orders; renderOrders(allOrders); }
    } catch(e) { console.error(e); }
}
function renderOrders(orders) {
    document.getElementById('ordersList').innerHTML = orders.map(o => `
        <label class="flex items-center p-4 border-b hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" onchange="toggleOrder(${o.id})" class="ml-3">
            <div class="flex-1 flex justify-between">
                <span class="font-bold">${o.order_number} (${o.customer_name})</span>
                <span class="text-teal-600">${parseFloat(o.final_amount).toLocaleString()} ر.ي</span>
            </div>
        </label>`).join('');
}
function filterOrders() {
    const q = document.getElementById('orderSearch').value.toLowerCase();
    renderOrders(allOrders.filter(o => o.order_number.toLowerCase().includes(q) || (o.customer_name||'').toLowerCase().includes(q)));
}
function toggleOrder(id) { selectedOrderIds.has(id) ? selectedOrderIds.delete(id) : selectedOrderIds.add(id); }
async function addSelectedOrdersToGroup() {
    const fd = new FormData();
    fd.append('action','add_order');
    [...selectedOrderIds].forEach(id => fd.append('order_ids[]', id));
    const r = await fetch(window.location.href, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
    if ((await r.json()).success) window.location.reload();
}
function openAddOrderModal()  { document.getElementById('addOrderModal').classList.remove('hidden'); loadAvailableOrders(); }
function closeAddOrderModal() { document.getElementById('addOrderModal').classList.add('hidden'); }

/* ─── Delete helpers ─── */
async function deleteBasket(id, code) {
    if (!confirm(`إزالة السلة ${code} من المجموعة؟`)) return;
    const fd = new FormData(); fd.append('action','delete_basket'); fd.append('basket_id',id);
    const r = await fetch(window.location.href, {method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    if ((await r.json()).success) window.location.reload();
}
async function removeOrder(id, num) {
    if (!confirm(`إزالة الطلب ${num} من المجموعة؟`)) return;
    const fd = new FormData(); fd.append('action','delete_order'); fd.append('order_id',id);
    const r = await fetch(window.location.href, {method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    if ((await r.json()).success) window.location.reload();
}

/* ─── Inline status change (mirrors orders/index.php JS) ─── */
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('group-orders-table-body').addEventListener('change', async function (event) {
        if (!event.target.classList.contains('oim-status-dropdown')) return;
        const dd = event.target;
        const orderId = dd.dataset.orderId;
        const newStatus = dd.value;
        const origStatus = dd.dataset.originalStatus;

        if (!confirm('هل أنت متأكد من تغيير حالة الطلب؟')) { dd.value = origStatus; return; }

        try {
            const response = await fetch('../../orders/api/update_order_status.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({order_id: orderId, status: newStatus})
            });
            const result = await response.json();
            if (result.success) {
                dd.dataset.originalStatus = newStatus;
            } else {
                alert('فشل تغيير الحالة: ' + (result.message || ''));
                dd.value = origStatus;
            }
        } catch (e) {
            alert('حدث خطأ أثناء الاتصال بالخادم.');
            dd.value = origStatus;
        }
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>
