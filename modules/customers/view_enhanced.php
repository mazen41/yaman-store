<?php
/**
 * Customer View Page - Enhanced (All Fields Shown)
 */

session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

$is_admin = isUserAdmin($_SESSION['user_id'], $db);



require_once '../../includes/status_helpers.php';

$user_id = $_SESSION['user_id'] ?? 0;
$page_title = 'عرض بيانات العميل';
$error_message = '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$customer_id = intval($_GET['id']);
$active_tab = $_GET['tab'] ?? 'details';

$can_view_customers = hasPermission($user_id, 'customers', 'view');
$can_edit_customers = hasPermission($user_id, 'customers', 'edit');
$can_delete_customers = hasPermission($user_id, 'customers', 'delete');
$can_view_orders = hasPermission($user_id, 'orders', 'view');
$can_add_orders = hasPermission($user_id, 'orders', 'add');
$can_edit_orders = hasPermission($user_id, 'orders', 'edit');
$can_delete_orders = hasPermission($user_id, 'orders', 'delete') || $can_edit_orders;
$can_view_invoices = hasPermission($user_id, 'customer_invoices', 'view');
$can_view_payments = hasPermission($user_id, 'payments', 'view');
$can_edit_payments = hasPermission($user_id, 'payments', 'edit');
$can_delete_payments = hasPermission($user_id, 'payments', 'delete') || $can_edit_payments;

$payment_method_translations = [
    'cash'        => 'نقدي',
    'transfer'    => 'تحويل بنكي',
    'check'       => 'شيك',
    'credit_card' => 'بطاقة ائتمانية',
    'other'       => 'أخرى'
];

try {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        header('Location: index.php');
        exit();
    }

    // Fetch customer type name
    $ct_stmt = $db->prepare("SELECT name, discount_percentage FROM customer_types WHERE id = ?");
    $ct_stmt->execute([$customer['customer_type_id']]);
    $customer_type_info = $ct_stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch city name
    $city_stmt = $db->prepare("SELECT name FROM cities WHERE id = ?");
    $city_stmt->execute([$customer['city_id']]);
    $city_info = $city_stmt->fetch(PDO::FETCH_ASSOC);

    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM customer_order_statuses ORDER BY is_default DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $orders_query = "
        SELECT
            o.id, o.order_number, o.currency, o.order_date, o.customer_id, o.status,
            o.order_link, o.additional_link, o.created_at, o.basket_id, o.purchase_group_id,
            o.manager_notes,
            o.coupon_id,
            cos.status_name_ar,
            COALESCE(o.subtotal_amount, 0) as subtotal_amount,
            COALESCE(o.discount_amount, 0) as discount_amount,
            COALESCE(o.final_amount, 0) as final_amount,
            COALESCE(o.paid_amount, 0) as paid_amount,
            (SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id) as total_quantity,
            (SELECT COALESCE(SUM(price), 0) FROM order_damaged_items odi WHERE odi.order_id = o.id) as damaged_amount,
            (SELECT oi.product_link FROM order_items oi WHERE oi.order_id = o.id AND oi.product_link IS NOT NULL AND oi.product_link <> '' ORDER BY oi.id LIMIT 1) as first_product_link,
            pg.group_name as purchase_group_name,
            pg.group_number as purchase_group_number,
            (SELECT GROUP_CONCAT(CONCAT(ci.id, ':', ci.invoice_number) SEPARATOR ';')
                FROM customer_invoices ci WHERE ci.order_id = o.id) as invoice_data,
            u.username as creator_name,
            CASE
                WHEN o.coupon_id IS NOT NULL AND coup.discount_type = 'percentage' THEN coup.discount_value
                WHEN o.coupon_id IS NOT NULL AND coup.discount_type = 'fixed' AND o.subtotal_amount > 0.01 THEN (o.discount_amount / o.subtotal_amount) * 100
                ELSE o.automatic_discount_percentage
            END as display_discount_percentage
        FROM customer_orders o
        LEFT JOIN users u ON o.created_by = u.id
        LEFT JOIN coupons coup ON o.coupon_id = coup.id
        LEFT JOIN purchase_baskets pb ON o.basket_id = pb.id
        LEFT JOIN purchase_groups pg ON pg.id = COALESCE(o.purchase_group_id, pb.purchase_group_id)
        LEFT JOIN customer_order_statuses cos ON o.status = cos.status_key
        WHERE o.customer_id = ?
        ORDER BY o.created_at DESC
    ";

    $orders_stmt = $db->prepare($orders_query);
    $orders_stmt->execute([$customer_id]);
    $orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_orders = count($orders);

    $orders_totals = ['quantity' => 0, 'subtotal' => 0, 'discount' => 0, 'damaged' => 0, 'final' => 0, 'paid' => 0, 'remaining' => 0];
    foreach ($orders as $o) {
        $orders_totals['quantity']  += $o['total_quantity'];
        $orders_totals['subtotal']  += $o['subtotal_amount'];
        $orders_totals['discount']  += $o['discount_amount'];
        $orders_totals['damaged']   += $o['damaged_amount'];
        $orders_totals['final']     += $o['final_amount'];
        $orders_totals['paid']      += $o['paid_amount'];
        $orders_totals['remaining'] += ($o['final_amount'] - $o['paid_amount']);
    }

    $spent_stmt = $db->prepare("SELECT SUM(final_amount) FROM customer_orders WHERE customer_id = ? AND status != 'cancelled'");
    $spent_stmt->execute([$customer_id]);
    $total_spent = $spent_stmt->fetchColumn() ?? 0;

    $invoices_stmt = $db->prepare("
        SELECT ci.*, co.order_number,
               (SELECT SUM(cp.amount) FROM customer_payments cp WHERE cp.invoice_id = ci.id) as total_paid
        FROM customer_invoices ci
        LEFT JOIN customer_orders co ON ci.order_id = co.id
        WHERE ci.customer_id = ?
        ORDER BY ci.created_at DESC
    ");
    $invoices_stmt->execute([$customer_id]);
    $invoices = $invoices_stmt->fetchAll(PDO::FETCH_ASSOC);
    $visible_invoices_count = count($invoices);

    $payments_stmt = $db->prepare("
        SELECT cp.*, ci.invoice_number
        FROM customer_payments cp
        LEFT JOIN customer_invoices ci ON cp.invoice_id = ci.id
        WHERE cp.customer_id = ?
        ORDER BY cp.created_at DESC
    ");
    $payments_stmt->execute([$customer_id]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_payments = count($payments);

} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات العميل: ' . $e->getMessage();
}

include '../../includes/header.php';
?>

<style>
    :root { --primary: #3b82f6; --success: #10b981; --danger: #ef4444; }

    /* ── Detail Card ── */
    .detail-section {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
        margin-bottom: 20px;
    }
    .detail-section-header {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
        padding: 12px 18px;
        font-weight: 700;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .detail-section-header.green  { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
    .detail-section-header.purple { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
    .detail-section-header.amber  { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .detail-section-header.gray   { background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0;
    }
    @media (max-width: 640px) { .detail-grid { grid-template-columns: 1fr; } }

    .detail-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 12px 18px;
        border-bottom: 1px solid #f3f4f6;
        border-left: 1px solid #f3f4f6;
        gap: 10px;
    }
    .detail-item:last-child  { border-bottom: none; }
    .detail-item .lbl {
        font-size: 12px;
        color: #6b7280;
        font-weight: 600;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .detail-item .val {
        font-size: 13px;
        color: #111827;
        font-weight: 600;
        text-align: left;
        word-break: break-all;
    }
    .detail-item .val.muted { color: #9ca3af; font-weight: 400; }
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
    }
    .badge-blue   { background: #dbeafe; color: #1e40af; }
    .badge-green  { background: #d1fae5; color: #065f46; }
    .badge-red    { background: #fee2e2; color: #991b1b; }
    .badge-amber  { background: #fef3c7; color: #92400e; }
    .badge-purple { background: #ede9fe; color: #5b21b6; }

    .notes-box {
        background: #fffbeb;
        border-right: 4px solid #f59e0b;
        padding: 14px 18px;
        font-size: 13px;
        color: #374151;
        white-space: pre-wrap;
        line-height: 1.7;
    }
    .full-width-item {
        grid-column: 1 / -1;
    }
    .token-box {
        font-family: monospace;
        font-size: 11px;
        background: #f3f4f6;
        padding: 4px 8px;
        border-radius: 6px;
        word-break: break-all;
        color: #374151;
    }

    /* ── Orders Table ── */
    .orders-tab-container { overflow-x: auto; padding-bottom: 20px; }
    .vertical-table { width: 100%; border-collapse: collapse; min-width: 1200px; }
    .vertical-table thead { background: #10b981; color: white; }
    .vertical-table th { padding: 12px 10px; text-align: right; font-weight: 600; font-size: 13px; white-space: nowrap; }
    .vertical-table tbody tr { border-bottom: 1px solid #e5e7eb; }
    .vertical-table tbody tr:hover { background: #f9fafb; }
    .vertical-table td { padding: 12px 10px; text-align: right; font-size: 13px; vertical-align: middle; }
    .status-dropdown { padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; min-width: 130px; }
    .action-icon { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; text-decoration: none; transition: opacity 0.2s; }
    .action-icon:hover { opacity: 0.8; }

    /* ── Modals ── */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-content { background: white; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; text-align: right; }
    .btn-custom { display: inline-flex; align-items: center; gap: 5px; padding: 8px 15px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; }
    .btn-primary   { background: #3b82f6; color: white; }
    .btn-secondary { background: #6b7280; color: white; }
    .form-control-custom { width: 100%; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">بيانات العميل</h1>
                        <p class="text-gray-600 mt-1"><?php echo htmlspecialchars($customer['name']); ?> &mdash; <span class="text-blue-600 font-bold"><?php echo htmlspecialchars($customer['customer_code']); ?></span></p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($can_edit_customers): ?><a href="edit.php?id=<?php echo $customer_id; ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm"><i class="fas fa-edit ml-2"></i>تعديل</a><?php endif; ?>
                        <a href="../orders/sync_customer_invoices.php?customer_id=<?php echo $customer_id; ?>&redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 text-sm"><i class="fas fa-sync ml-2"></i>مزامنة الفواتير</a>
                        <button onclick="copyPortalLink('<?php echo $customer['portal_token']; ?>')" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm"><i class="fas fa-copy ml-2"></i>نسخ رابط البوابة</button>
                        <a href="../../customer_portal/portal.php?token=<?php echo $customer['portal_token']; ?>" target="_blank" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm"><i class="fas fa-external-link-alt ml-2"></i>فتح البوابة</a>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm"><i class="fas fa-arrow-right ml-2"></i>عودة</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-5 shadow rounded-lg flex items-center gap-3"><i class="fas fa-shopping-cart text-2xl text-amber-500"></i><div><p class="text-xs text-gray-500">إجمالي الطلبات</p><p class="text-lg font-bold text-gray-900"><?php echo $total_orders; ?></p></div></div>
            <div class="bg-white p-5 shadow rounded-lg flex items-center gap-3"><i class="fas fa-coins text-2xl text-blue-500"></i><div><p class="text-xs text-gray-500">إجمالي المبلغ</p><p class="text-lg font-bold text-gray-900"><?php echo number_format($total_spent, 0, '.', ','); ?></p></div></div>
            <div class="bg-white p-5 shadow rounded-lg flex items-center gap-3"><i class="fas fa-file-invoice text-2xl text-orange-500"></i><div><p class="text-xs text-gray-500">الفواتير</p><p class="text-lg font-bold text-gray-900"><?php echo $visible_invoices_count; ?></p></div></div>
            <div class="bg-white p-5 shadow rounded-lg flex items-center gap-3"><i class="fas fa-credit-card text-2xl text-purple-500"></i><div><p class="text-xs text-gray-500">المدفوعات</p><p class="text-lg font-bold text-gray-900"><?php echo $total_payments; ?></p></div></div>
        </div>

        <!-- Tabs -->
        <div class="bg-white shadow rounded-lg">
            <div class="border-b border-gray-200 overflow-x-auto">
                <nav class="flex space-x-8 space-x-reverse px-6 min-w-max">
                    <a href="?id=<?php echo $customer_id; ?>&tab=details"  class="<?php echo $active_tab=='details'  ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">تفاصيل العميل</a>
                    <a href="?id=<?php echo $customer_id; ?>&tab=orders"   class="<?php echo $active_tab=='orders'   ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">طلبات العميل (<?php echo $total_orders; ?>)</a>
                    <a href="?id=<?php echo $customer_id; ?>&tab=invoices" class="<?php echo $active_tab=='invoices' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">فواتير العميل (<?php echo $visible_invoices_count; ?>)</a>
                    <a href="?id=<?php echo $customer_id; ?>&tab=payments" class="<?php echo $active_tab=='payments' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">مدفوعات العميل (<?php echo $total_payments; ?>)</a>
                </nav>
            </div>

            <div class="p-4 md:p-6">

                <?php if ($active_tab == 'details'): ?>
                <!-- ═══════════════════════════════════════════════════
                     DETAILS TAB — ALL FIELDS
                ═══════════════════════════════════════════════════ -->

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

                    <!-- ① Basic Info -->
                    <div class="detail-section">
                        <div class="detail-section-header"><i class="fas fa-user-circle"></i> المعلومات الأساسية</div>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-hashtag"></i> رقم العميل</span>
                                <span class="val"><span class="badge badge-blue"><?php echo htmlspecialchars($customer['customer_code']); ?></span></span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-list"></i> الفئة</span>
                                <span class="val">
                                    <?php if ($customer_type_info): ?>
                                        <span class="badge badge-purple"><?php echo htmlspecialchars($customer_type_info['name']); ?></span>
                                        <?php if ($customer_type_info['discount_percentage'] > 0): ?>
                                            <small class="text-green-600 mr-1">(خصم <?php echo number_format($customer_type_info['discount_percentage'], 0); ?>%)</small>
                                        <?php endif; ?>
                                    <?php else: echo '<span class="muted">—</span>'; endif; ?>
                                </span>
                            </div>
                            <div class="detail-item full-width-item" style="grid-column:1/-1;">
                                <span class="lbl"><i class="fas fa-user"></i> الاسم الكامل</span>
                                <span class="val" style="font-size:15px;"><?php echo htmlspecialchars($customer['name']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-toggle-on"></i> الحالة</span>
                                <span class="val">
                                    <?php if ($customer['is_active']): ?>
                                        <span class="badge badge-green"><i class="fas fa-check-circle ml-1"></i> نشط</span>
                                    <?php else: ?>
                                        <span class="badge badge-red"><i class="fas fa-times-circle ml-1"></i> غير نشط</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-coins"></i> العملة</span>
                                <span class="val"><span class="badge badge-amber"><?php echo htmlspecialchars($customer['currency'] ?? 'YER'); ?></span></span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-money-bill-wave"></i> الحد الائتماني</span>
                                <span class="val"><?php echo number_format($customer['credit_limit'] ?? 0, 0, '.', ','); ?> ريال</span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-wallet"></i> الرصيد الحالي</span>
                                <span class="val" style="color:<?php echo ($customer['current_balance'] ?? 0) >= 0 ? '#d97706' : '#dc2626'; ?>;">
                                    <?php echo number_format($customer['current_balance'] ?? 0, 0, '.', ','); ?> ريال
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- ② Contact Info -->
                    <div class="detail-section">
                        <div class="detail-section-header green"><i class="fas fa-phone-alt"></i> معلومات الاتصال</div>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-phone"></i> هاتف ثابت</span>
                                <span class="val <?php echo empty($customer['phone']) ? 'muted' : ''; ?>">
                                    <?php echo !empty($customer['phone']) ? htmlspecialchars($customer['phone']) : '—'; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-mobile-alt"></i> رقم الجوال</span>
                                <span class="val <?php echo empty($customer['mobile_number']) ? 'muted' : ''; ?>">
                                    <?php echo !empty($customer['mobile_number']) ? htmlspecialchars($customer['mobile_number']) : '—'; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fab fa-whatsapp" style="color:#25d366;"></i> واتساب</span>
                                <span class="val <?php echo empty($customer['whatsapp_number']) ? 'muted' : ''; ?>">
                                    <?php if (!empty($customer['whatsapp_number'])): ?>
                                        <a href="https://wa.me/<?php echo preg_replace('/\D/', '', $customer['whatsapp_number']); ?>" target="_blank" style="color:#25d366; text-decoration:none;">
                                            <?php echo htmlspecialchars($customer['whatsapp_number']); ?>
                                            <i class="fas fa-external-link-alt" style="font-size:10px; margin-right:4px;"></i>
                                        </a>
                                    <?php else: echo '—'; endif; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-phone-volume"></i> رقم بديل</span>
                                <span class="val <?php echo empty($customer['alternative_number']) ? 'muted' : ''; ?>">
                                    <?php echo !empty($customer['alternative_number']) ? htmlspecialchars($customer['alternative_number']) : '—'; ?>
                                </span>
                            </div>
                            <div class="detail-item full-width-item" style="grid-column:1/-1;">
                                <span class="lbl"><i class="fas fa-envelope"></i> البريد الإلكتروني</span>
                                <span class="val <?php echo empty($customer['email']) ? 'muted' : ''; ?>">
                                    <?php echo !empty($customer['email']) ? htmlspecialchars($customer['email']) : '—'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- ③ Location Info -->
                    <div class="detail-section">
                        <div class="detail-section-header amber"><i class="fas fa-map-marked-alt"></i> معلومات الموقع والعنوان</div>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-city"></i> المدينة</span>
                                <span class="val <?php echo empty($city_info) ? 'muted' : ''; ?>">
                                    <?php echo !empty($city_info) ? htmlspecialchars($city_info['name']) : (htmlspecialchars($customer['city_name'] ?? '') ?: '—'); ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-map-marker-alt"></i> المنطقة / الحي</span>
                                <span class="val <?php echo empty($customer['location_area']) ? 'muted' : ''; ?>">
                                    <?php echo !empty($customer['location_area']) ? htmlspecialchars($customer['location_area']) : '—'; ?>
                                </span>
                            </div>
                            <div class="detail-item full-width-item" style="grid-column:1/-1;">
                                <span class="lbl"><i class="fas fa-map-pin"></i> العنوان التفصيلي</span>
                                <span class="val <?php echo empty($customer['address']) ? 'muted' : ''; ?>" style="white-space:pre-wrap;">
                                    <?php echo !empty($customer['address']) ? htmlspecialchars($customer['address']) : '—'; ?>
                                </span>
                            </div>
                            <div class="detail-item full-width-item" style="grid-column:1/-1;">
                                <span class="lbl"><i class="fas fa-link"></i> رابط الخريطة</span>
                                <span class="val">
                                    <?php if (!empty($customer['location_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($customer['location_url']); ?>" target="_blank" style="color:#3b82f6; text-decoration:none;">
                                            <i class="fas fa-map-marker-alt"></i> فتح في خرائط Google
                                            <i class="fas fa-external-link-alt" style="font-size:10px;"></i>
                                        </a>
                                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- ④ Portal & System Info -->
                    <div class="detail-section">
                        <div class="detail-section-header purple"><i class="fas fa-cog"></i> بيانات النظام والبوابة</div>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-calendar-plus"></i> تاريخ الإضافة</span>
                                <span class="val">
                                    <?php echo !empty($customer['created_at']) ? date('d/m/Y H:i', strtotime($customer['created_at'])) : '—'; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="lbl"><i class="fas fa-calendar-check"></i> آخر تحديث</span>
                                <span class="val">
                                    <?php echo !empty($customer['updated_at']) ? date('d/m/Y H:i', strtotime($customer['updated_at'])) : '—'; ?>
                                </span>
                            </div>
                            <div class="detail-item full-width-item" style="grid-column:1/-1;">
                                <span class="lbl"><i class="fas fa-key"></i> رمز البوابة (Token)</span>
                                <span class="val">
                                    <span class="token-box"><?php echo htmlspecialchars($customer['portal_token']); ?></span>
                                    <button onclick="copyPortalLink('<?php echo $customer['portal_token']; ?>')" style="background:#ede9fe;border:none;color:#5b21b6;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px;margin-right:6px;"><i class="fas fa-copy"></i> نسخ الرابط</button>
                                </span>
                            </div>
                        </div>
                    </div>

                </div><!-- /grid -->

                <!-- ⑤ Customer Notes — full width -->
                <?php if (!empty($customer['customer_notes'])): ?>
                <div class="detail-section" style="margin-top:0;">
                    <div class="detail-section-header amber"><i class="fas fa-sticky-note"></i> ملاحظات العميل</div>
                    <div class="notes-box"><?php echo htmlspecialchars($customer['customer_notes']); ?></div>
                </div>
                <?php endif; ?>


                <?php elseif ($active_tab == 'orders'): ?>
                <!-- ═══════════════════════════════════════════════════
                     ORDERS TAB
                ═══════════════════════════════════════════════════ -->
                <div class="orders-tab-container">
                    <?php if (empty($orders)): ?>
                        <div class="text-center py-12"><i class="fas fa-shopping-cart text-4xl text-gray-300 mb-4"></i><p class="text-gray-500">لا توجد طلبات لهذا العميل</p></div>
                    <?php else: ?>
                        <table class="vertical-table">
                            <thead>
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>تاريخ الطلب</th>
                                    <th>عدد القطع</th>
                                    <th>رابط الطلب</th>
                                    <th>رابط إضافي</th>
                                    <th>الحالة</th>
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
                                    <th>المجموعة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody id="orders-table-body">
                                <?php foreach ($orders as $order):
                                    $remaining_amount = $order['final_amount'] - $order['paid_amount'];
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars(formatOrderNumber($order['order_number'])); ?></strong></td>
                                    <td><?php echo htmlspecialchars($order['order_date']); ?></td>
                                    <td><strong><?php echo $order['total_quantity']; ?></strong></td>
                                    <td>
                                        <?php $display_order_link = $order['order_link'] ?: $order['first_product_link']; ?>
                                        <?php if (!empty($display_order_link)): ?><a href="<?php echo htmlspecialchars($display_order_link); ?>" target="_blank" class="action-icon" style="background:#dbeafe;color:#1e40af;" title="فتح رابط الطلب"><i class="fas fa-external-link-alt"></i></a><?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($order['additional_link'])): ?><a href="<?php echo htmlspecialchars($order['additional_link']); ?>" target="_blank" class="action-icon" style="background:#fef3c7;color:#92400e;" title="رابط إضافي"><i class="fas fa-link"></i></a><?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($can_edit_orders): ?>
                                            <select class="status-dropdown" data-order-id="<?php echo $order['id']; ?>" data-original-status="<?php echo htmlspecialchars($order['status']); ?>">
                                                <?php foreach ($all_statuses as $status): ?>
                                                <option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo ($order['status'] == $status['status_key']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($status['status_name_ar']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <span><?php echo htmlspecialchars($order['status_name_ar'] ?? $order['status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($order['manager_notes']); ?>">
                                        <?php echo !empty($order['manager_notes']) ? htmlspecialchars($order['manager_notes']) : '<span style="color:#9ca3af;">—</span>'; ?>
                                    </td>
                                    <td><span style="background:#eff6ff;color:#1d4ed8;padding:4px 10px;border-radius:20px;font-size:11px;"><?php echo htmlspecialchars($order['currency']); ?></span></td>
                                    <td style="color:#3b82f6;"><strong><?php echo number_format($order['subtotal_amount'], 0); ?></strong></td>
                                    <td style="color:#f59e0b;font-weight:600;"><?php echo number_format($order['discount_amount'], 0); ?></td>
                                    <td style="color:#d97706;font-weight:600;text-align:center;">
                                        <?php
                                        $dp = floatval($order['display_discount_percentage'] ?? 0);
                                        if ($dp > 0.01) {
                                            echo number_format($dp, 0) . '%';
                                            if (!empty($order['coupon_id'])) echo ' <i class="fas fa-ticket-alt" title="خصم كوبون" style="color:#16a34a;"></i>';
                                        } else { echo '—'; }
                                        ?>
                                    </td>
                                    <td style="color:#dc2626;font-weight:700;"><?php echo number_format($order['damaged_amount'], 0); ?></td>
                                    <td style="color:#059669;font-weight:700;"><?php echo number_format($order['final_amount'], 0); ?></td>
                                    <td style="color:#10b981;"><?php echo number_format($order['paid_amount'], 0); ?></td>
                                    <td style="color:<?php echo ($remaining_amount > 0.01) ? '#ef4444' : '#6b7280'; ?>;"><?php echo number_format($remaining_amount, 0); ?></td>
                                    <td>
                                        <?php if (!empty($order['invoice_data'])):
                                            foreach (explode(';', $order['invoice_data']) as $inv_str):
                                                list($inv_id, $inv_number) = explode(':', $inv_str, 2); ?>
                                                <a href="../invoices/view.php?id=<?php echo htmlspecialchars($inv_id); ?>" style="display:block;color:#3b82f6;text-decoration:none;white-space:nowrap;"><?php echo htmlspecialchars($inv_number); ?></a>
                                        <?php endforeach; else: echo '<span style="color:#9ca3af;">—</span>'; endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $gp = [];
                                        if (!empty($order['purchase_group_name']))   $gp[] = $order['purchase_group_name'];
                                        if (!empty($order['purchase_group_number'])) $gp[] = $order['purchase_group_number'];
                                        echo !empty($gp) ? '<small>' . implode(' - ', array_map('htmlspecialchars', $gp)) . '</small>' : '—';
                                        ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:5px;justify-content:center;">
                                            <?php if ($can_view_orders): ?><a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="action-icon" style="background:#dbeafe;color:#1e40af;" title="عرض"><i class="fas fa-eye"></i></a><?php endif; ?>
                                            <?php if ($can_add_orders): ?>
                                                <button onclick="openManagerNotesModal(<?php echo $order['id']; ?>)" class="action-icon" style="background:#e0e7ff;color:#4338ca;border:none;" title="ملاحظات المدير"><i class="fas fa-user-shield"></i></button>
                                            <?php endif; ?>
                                            <?php if ($can_edit_orders): ?>
                                                <a href="../orders/edit.php?id=<?php echo $order['id']; ?>" class="action-icon" style="background:#fef3c7;color:#92400e;" title="تعديل"><i class="fas fa-edit"></i></a>
                                            <?php endif; ?>
                                            <a href="../orders/print.php?id=<?php echo $order['id']; ?>" target="_blank" class="action-icon" style="background:#f3f4f6;color:#374151;" title="طباعة"><i class="fas fa-print"></i></a>
                                            <?php if ($can_delete_orders): ?>
                                                <button onclick="deleteOrder(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['order_number']); ?>')" class="action-icon" style="background:#fee2e2;color:#b91c1c;border:none;" title="حذف"><i class="fas fa-trash-alt"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if (!empty($orders)): ?>
                            <tfoot style="background:#f3f4f6;border-top:2px solid #d1d5db;">
                                <tr style="font-weight:bold;font-size:14px;">
                                    <td colspan="2" style="text-align:right;padding:12px;"><i class="fas fa-calculator"></i> الإجمالي (<?php echo $total_orders; ?>)</td>
                                    <td><?php echo number_format($orders_totals['quantity'], 0); ?></td>
                                    <td colspan="5"></td>
                                    <td style="color:#3b82f6;"><?php echo number_format($orders_totals['subtotal'], 0); ?></td>
                                    <td style="color:#f59e0b;"><?php echo number_format($orders_totals['discount'], 0); ?></td>
                                    <td></td>
                                    <td style="color:#ef4444;"><?php echo number_format($orders_totals['damaged'], 0); ?></td>
                                    <td style="color:#059669;"><?php echo number_format($orders_totals['final'], 0); ?></td>
                                    <td style="color:#10b981;"><?php echo number_format($orders_totals['paid'], 0); ?></td>
                                    <td style="color:#ef4444;"><?php echo number_format($orders_totals['remaining'], 0); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    <?php endif; ?>
                </div>


                <?php elseif ($active_tab == 'invoices'): ?>
                <!-- ═══════════════════════════════════════════════════
                     INVOICES TAB
                ═══════════════════════════════════════════════════ -->
                <?php if (empty($invoices)): ?>
                    <div class="text-center py-12"><i class="fas fa-file-invoice text-4xl text-gray-300 mb-4"></i><p class="text-gray-500">لا توجد فواتير لهذا العميل</p></div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 border border-gray-200 rounded-lg overflow-hidden">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">رقم الفاتورة</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">مبلغ الفاتورة</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">المدفوع</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">المتبقي</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">الحالة</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">تاريخ الإصدار</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">العمليات</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($invoices as $invoice):
                                    $inv_amount  = $invoice['total_amount'];
                                    $inv_paid    = $invoice['total_paid'] ?? 0;
                                    $inv_remain  = $inv_amount - $inv_paid;
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                    <td class="px-6 py-4 text-sm"><?php echo number_format($inv_amount, 0, '.', ','); ?> ريال</td>
                                    <td class="px-6 py-4 text-sm text-amber-600"><?php echo number_format($inv_paid, 0, '.', ','); ?> ريال</td>
                                    <td class="px-6 py-4 text-sm font-bold <?php echo $inv_remain > 0 ? 'text-red-600' : 'text-gray-500'; ?>"><?php echo number_format($inv_remain, 0, '.', ','); ?> ريال</td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                            <?php if ($inv_remain <= 0 && $inv_amount > 0) echo 'bg-green-100 text-green-800';
                                                  elseif ($inv_paid > 0) echo 'bg-yellow-100 text-yellow-800';
                                                  else echo 'bg-blue-100 text-blue-800'; ?>">
                                            <?php if ($inv_remain <= 0 && $inv_amount > 0) echo 'مدفوعة بالكامل';
                                                  elseif ($inv_paid > 0) echo 'مدفوعة جزئياً';
                                                  else echo 'قيد الانتظار'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500"><?php echo date('d/m/Y', strtotime($invoice['created_at'])); ?></td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="flex gap-2">
                                            <?php if ($can_view_invoices): ?><a href="../invoices/view.php?id=<?php echo $invoice['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض"><i class="fas fa-eye"></i></a><?php endif; ?>
                                            <?php if ($inv_remain > 0): ?>
                                            <a href="../payments/add.php?invoice_id=<?php echo $invoice['id']; ?>" class="text-purple-600 hover:text-purple-900" title="تسجيل دفعة"><i class="fas fa-money-bill-wave"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>


                <?php elseif ($active_tab == 'payments'): ?>
                <!-- ═══════════════════════════════════════════════════
                     PAYMENTS TAB
                ═══════════════════════════════════════════════════ -->
                <?php if (empty($payments)): ?>
                    <div class="text-center py-12"><i class="fas fa-credit-card text-4xl text-gray-300 mb-4"></i><p class="text-gray-500">لا توجد مدفوعات لهذا العميل</p></div>
                <?php else: ?>
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">مدفوعات العميل</h3>
                        <a href="../payments/add.php?customer_id=<?php echo $customer_id; ?>" class="text-sm text-blue-600 hover:text-blue-800"><i class="fas fa-plus-circle ml-1"></i>إضافة دفعة جديدة</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 border border-gray-200 rounded-lg overflow-hidden">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">رقم الدفعة</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">رقم الفاتورة</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">المبلغ</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">طريقة الدفع</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">تاريخ الدفع</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500">العمليات</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($payments as $payment): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['payment_number']); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($payment['invoice_number'] ?? '—'); ?></td>
                                    <td class="px-6 py-4 text-sm"><?php echo number_format($payment['amount'], 0, '.', ','); ?> ريال</td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800">
                                            <?php echo htmlspecialchars($payment_method_translations[$payment['payment_method']] ?? $payment['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500"><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="flex gap-2">
                                            <?php if ($can_view_payments): ?><a href="../payments/view.php?id=<?php echo $payment['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض"><i class="fas fa-eye"></i></a><?php endif; ?>
                                            <?php if ($can_edit_payments): ?><a href="../payments/edit.php?id=<?php echo $payment['id']; ?>" class="text-green-600 hover:text-green-900" title="تعديل"><i class="fas fa-edit"></i></a><?php endif; ?>
                                            <?php if ($can_delete_payments): ?><a href="../payments/delete.php?id=<?php echo $payment['id']; ?>" class="text-red-600 hover:text-red-900" title="حذف" onclick="return confirm('هل أنت متأكد من حذف هذه الدفعة؟');"><i class="fas fa-trash"></i></a><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php endif; ?>
            </div><!-- /tab body -->
        </div><!-- /bg-white card -->
    </div>
</div>

<!-- ── MANAGER NOTES MODAL ── -->
<div id="managerNotesModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="font-size:20px;margin-bottom:20px;">إضافة ملاحظة المدير</h3>
        <form id="managerNotesForm">
            <input type="hidden" id="managerNotesOrderId" name="order_id">
            <div style="margin-bottom:15px;">
                <label for="managerNoteText" style="display:block;margin-bottom:5px;font-weight:600;">الملاحظة</label>
                <textarea id="managerNoteText" name="manager_note" rows="5" class="form-control-custom" placeholder="أضف ملاحظتك السرية هنا..."></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closeManagerNotesModal()" class="btn-custom btn-secondary">إلغاء</button>
                <button type="submit" class="btn-custom btn-primary">حفظ الملاحظة</button>
            </div>
        </form>
    </div>
</div>

<!-- ── DELETE MODAL ── -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-content" style="text-align:center;max-width:400px;">
        <div style="width:60px;height:60px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;"><i class="fas fa-exclamation-triangle" style="font-size:30px;color:#ef4444;"></i></div>
        <h3 style="font-size:20px;margin-bottom:10px;">تأكيد الحذف</h3>
        <p style="color:#6b7280;margin-bottom:5px;">هل أنت متأكد من حذف الطلب <strong id="orderNumberToDelete"></strong>؟</p>
        <p style="color:#ef4444;font-size:14px;margin-bottom:20px;">لا يمكن التراجع عن هذا الإجراء!</p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button id="confirmDeleteBtn" class="btn-custom" style="background:#ef4444;color:white;">حذف</button>
            <button onclick="closeDeleteModal()" class="btn-custom btn-secondary">إلغاء</button>
        </div>
    </div>
</div>

<script>
function copyPortalLink(token) {
    const baseUrl = window.location.origin;
    const portalUrl = `${baseUrl}/customer_portal/portal.php?token=${token}`;
    const textarea = document.createElement('textarea');
    textarea.value = portalUrl;
    textarea.style.position = 'fixed';
    textarea.style.opacity  = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try { document.execCommand('copy'); alert('✅ تم نسخ رابط البوابة!'); }
    catch (err) { alert('❌ فشل نسخ الرابط.'); }
    document.body.removeChild(textarea);
}

document.addEventListener('DOMContentLoaded', function () {

    // Status dropdown change
    const tableBody = document.getElementById('orders-table-body');
    if (tableBody) {
        tableBody.addEventListener('change', async function (event) {
            if (!event.target.classList.contains('status-dropdown')) return;
            const dropdown = event.target;
            const orderId  = dropdown.dataset.orderId;
            const newKey   = dropdown.value;
            const origKey  = dropdown.dataset.originalStatus;
            if (!confirm('هل أنت متأكد من تغيير حالة الطلب؟')) { dropdown.value = origKey; return; }
            try {
                const res  = await fetch('../orders/api/update_order_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order_id: orderId, status: newKey })
                });
                const data = await res.json();
                if (data.success) { dropdown.dataset.originalStatus = newKey; }
                else { alert('فشل تحديث الحالة: ' + data.message); dropdown.value = origKey; }
            } catch { alert('حدث خطأ أثناء تحديث الحالة.'); dropdown.value = origKey; }
        });
    }

    // Manager notes form
    const noteForm = document.getElementById('managerNotesForm');
    if (noteForm) {
        noteForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const orderId = this.order_id.value;
            const note    = this.manager_note.value;
            const btn     = this.querySelector('button[type="submit"]');
            btn.disabled     = true;
            btn.textContent  = 'جاري الحفظ...';
            try {
                const res  = await fetch('../orders/api/add_manager_note.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order_id: orderId, manager_note: note })
                });
                const data = await res.json();
                if (data.success) { alert('تم حفظ الملاحظة بنجاح.'); closeManagerNotesModal(); location.reload(); }
                else { alert('فشل الحفظ: ' + (data.message || 'خطأ غير معروف')); }
            } catch { alert('حدث خطأ أثناء الاتصال بالخادم.'); }
            finally { btn.disabled = false; btn.textContent = 'حفظ الملاحظة'; }
        });
    }

    // Confirm delete
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function () {
            if (!orderIdToDelete) return;
            this.disabled = true;
            fetch('../orders/delete.php', {
                method: 'POST',
                body: new URLSearchParams('order_id=' + orderIdToDelete)
            }).then(r => r.json()).then(data => {
                if (data.success) { location.reload(); }
                else { alert('خطأ: ' + data.message); this.disabled = false; }
            }).catch(() => { alert('حدث خطأ'); this.disabled = false; });
        });
    }
});

function openManagerNotesModal(orderId) {
    document.getElementById('managerNotesOrderId').value = orderId;
    document.getElementById('managerNoteText').value     = '';
    document.getElementById('managerNotesModal').style.display = 'flex';
    document.getElementById('managerNoteText').focus();
}
function closeManagerNotesModal() { document.getElementById('managerNotesModal').style.display = 'none'; }

let orderIdToDelete = null;
function deleteOrder(orderId, orderNumber) {
    orderIdToDelete = orderId;
    document.getElementById('orderNumberToDelete').textContent = orderNumber;
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; orderIdToDelete = null; }
</script>

<?php include '../../includes/footer.php'; ?>