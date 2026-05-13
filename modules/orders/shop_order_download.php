<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$order_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$order_id) {
    $_SESSION['error_message'] = 'معرف الطلب غير صالح.';
    header('Location: shop_orders_manage.php');
    exit();
}

try {
    // Fetch main order details
    $stmt = $db->prepare("
        SELECT o.*, c.name as customer_name, c.mobile_number, c.whatsapp_number, c.email as customer_email, c.city_name as customer_city
        FROM shop_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("الطلب غير موجود.");
    }

    // Fetch order items with cost and profit calculation
    $items_stmt = $db->prepare("
        SELECT 
            soi.*,
            p.purchase_amount,
            (soi.unit_price * soi.quantity) as item_total_sale,
            (p.purchase_amount * soi.quantity) as item_total_cost,
            ((soi.unit_price * soi.quantity) - (p.purchase_amount * soi.quantity)) as item_profit
        FROM shop_order_items soi
        LEFT JOIN products p ON soi.product_id = p.id
        WHERE soi.order_id = ?
    ");
    $items_stmt->execute([$order_id]);
    $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch company settings
    try {
        $settings_stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
        $company_settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        $company_settings = [
            'company_name'    => 'اسم الشركة',
            'company_address' => 'العنوان',
            'company_phone'   => 'رقم الهاتف',
            'company_email'   => 'البريد الإلكتروني'
        ];
    }

} catch (Exception $e) {
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: shop_orders_manage.php');
    exit();
}

$page_title = 'طباعة طلب #' . htmlspecialchars($order['order_number']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Cairo', sans-serif;
            background-color: #f3f4f6;
            color: #111827;
            direction: rtl;
        }

        /* ---- Shared card style from view page ---- */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-header h2 {
            font-size: 1.125rem;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
        .card-body { padding: 24px; }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e5e7eb;
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { font-weight: 600; color: #4b5563; }
        .detail-value { font-weight: 500; color: #111827; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
            font-family: 'Cairo', sans-serif;
            font-size: 14px;
        }
        .btn-primary  { background-color: #3b82f6; color: white; }
        .btn-primary:hover  { background-color: #2563eb; }
        .btn-secondary { background-color: #6b7280; color: white; }
        .btn-secondary:hover { background-color: #4b5563; }

        /* ---- Page layout ---- */
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-header h1 {
            font-size: 1.4rem;
            font-weight: 900;
            color: #111827;
        }
        .header-actions { display: flex; gap: 10px; }

        .grid { display: grid; gap: 24px; }
        .grid-3 { grid-template-columns: 1fr 1fr 1fr; }
        .col-span-2 { grid-column: span 2; }

        /* ---- Company header inside print ---- */
        .company-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 20px 24px;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 0;
        }
        .company-name { font-size: 1.25rem; font-weight: 900; color: #111827; margin-bottom: 4px; }
        .company-sub  { font-size: 0.8rem; color: #6b7280; line-height: 1.8; }
        .order-badge  { text-align: left; }
        .order-badge h2 { font-size: 1rem; font-weight: 700; color: #374151; margin-bottom: 6px; }
        .order-badge p  { font-size: 0.8rem; color: #4b5563; line-height: 1.8; }

        /* ---- Status badge ---- */
        .status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
        }
        .status-new      { background: #fef3c7; color: #b45309; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }

        /* ---- Table ---- */
        .items-table { width: 100%; border-collapse: collapse; }
        .items-table thead { background: #f9fafb; }
        .items-table th {
            padding: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #4b5563;
            text-align: right;
            border-bottom: 2px solid #e5e7eb;
        }
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.9rem;
            text-align: right;
            vertical-align: middle;
        }
        .items-table tbody tr:last-child td { border-bottom: none; }
        .items-table tbody tr:hover { background: #f9fafb; }

        /* ---- Totals section ---- */
        .totals-section {
            display: flex;
            justify-content: flex-end;
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
        }
        .totals-box { width: 280px; }
        .totals-box .detail-row { font-size: 0.9rem; }
        .totals-grand {
            font-size: 1.1rem;
            font-weight: 900;
            padding-top: 10px;
            margin-top: 6px;
            border-top: 2px solid #111827 !important;
        }

        /* ---- Info grid ---- */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
        .info-col { padding: 16px 24px; }
        .info-col:first-child { border-left: 1px solid #e5e7eb; }
        .info-col h3 {
            font-size: 0.9rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        .info-row { display: flex; gap: 8px; font-size: 0.85rem; margin-bottom: 6px; }
        .info-row strong { color: #4b5563; min-width: 80px; }
        .info-row span { color: #111827; }

        /* ---- Notes / rejection ---- */
        .notes-box {
            background: #f9fafb;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 0.85rem;
            color: #374151;
            line-height: 1.7;
        }
        .rejection-box {
            background: #fee2e2;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 0.85rem;
            color: #991b1b;
            line-height: 1.7;
        }

        /* ---- Footer ---- */
        .print-footer {
            text-align: center;
            padding: 16px;
            font-size: 0.78rem;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
        }

        /* ---- No-print toolbar ---- */
        .toolbar { display: flex; gap: 10px; }

        /* ============ PRINT STYLES ============ */
        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .container { padding: 0; max-width: 100%; }
            .card {
                box-shadow: none;
                border: 1px solid #e5e7eb;
                break-inside: avoid;
                margin-bottom: 16px;
            }
            .page-header { display: none; }
        }

        @media (max-width: 768px) {
            .grid-3 { grid-template-columns: 1fr; }
            .col-span-2 { grid-column: span 1; }
            .info-grid { grid-template-columns: 1fr; }
            .info-col:first-child { border-left: none; border-bottom: 1px solid #e5e7eb; }
        }
    </style>
</head>
<body>
<div class="container">

    <!-- Page Header (hidden on print) -->
    <div class="page-header no-print">
        <h1><i class="fas fa-print" style="color:#3b82f6;margin-left:8px;"></i> <?php echo $page_title; ?></h1>
        <div class="toolbar">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> طباعة
            </button>
            <a href="shop_order_view.php?id=<?php echo $order_id; ?>" class="btn btn-secondary">
                <i class="fas fa-eye"></i> عرض الطلب
            </a>
            <a href="shop_orders_manage.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> العودة
            </a>
        </div>
    </div>

    <!-- ===== PRINTABLE AREA ===== -->

    <!-- 1. Company Header + Order Info -->
    <div class="card">
        <div class="company-header">
            <div>
                <div class="company-name"><?php echo htmlspecialchars($company_settings['company_name'] ?? 'اسم الشركة'); ?></div>
                <div class="company-sub">
                    <?php echo htmlspecialchars($company_settings['company_address'] ?? ''); ?><br>
                    هاتف: <?php echo htmlspecialchars($company_settings['company_phone'] ?? ''); ?>
                    <?php if (!empty($company_settings['company_email'])): ?>
                     &nbsp;|&nbsp; <?php echo htmlspecialchars($company_settings['company_email']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="order-badge">
                <h2>طلب متجر</h2>
                <p>
                    <strong>رقم الطلب:</strong> #<?php echo htmlspecialchars($order['order_number']); ?><br>
                    <strong>التاريخ:</strong> <?php echo date('Y-m-d h:i A', strtotime($order['created_at'])); ?><br>
                    <strong>الحالة:</strong>
                    <?php
                        $status = $order['order_status'];
                        $badge_class = 'status-new';
                        if ($status === 'طلب معتمد') $badge_class = 'status-approved';
                        elseif ($status === 'مرفوض') $badge_class = 'status-rejected';
                    ?>
                    <span class="status-badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($status); ?></span>
                </p>
            </div>
        </div>

        <!-- Customer + Shipping Info -->
        <div class="info-grid">
            <div class="info-col">
                <h3><i class="fas fa-user" style="color:#3b82f6;margin-left:6px;"></i> معلومات العميل</h3>
                <div class="info-row"><strong>الاسم:</strong> <span><?php echo htmlspecialchars($order['customer_name'] ?? 'غير محدد'); ?></span></div>
                <div class="info-row"><strong>الجوال:</strong> <span><?php echo htmlspecialchars($order['mobile_number'] ?? 'لا يوجد'); ?></span></div>
                <div class="info-row"><strong>الواتساب:</strong> <span><?php echo htmlspecialchars($order['whatsapp_number'] ?? 'لا يوجد'); ?></span></div>
                <div class="info-row"><strong>البريد:</strong> <span><?php echo htmlspecialchars($order['customer_email'] ?? 'لا يوجد'); ?></span></div>
                <div class="info-row"><strong>المدينة:</strong> <span><?php echo htmlspecialchars($order['customer_city'] ?? 'لا يوجد'); ?></span></div>
            </div>
            <div class="info-col">
                <h3><i class="fas fa-truck" style="color:#8b5cf6;margin-left:6px;"></i> تفاصيل الشحن</h3>
                <div class="info-row"><strong>رسوم الشحن:</strong> <span><?php echo number_format($order['shipping_fee'], 2); ?> ريال</span></div>
                <div class="info-row"><strong>عنوان الشحن:</strong> <span><?php echo htmlspecialchars($order['shipping_address'] ?? 'لا يوجد'); ?></span></div>
            </div>
        </div>
    </div>

    <!-- 2. Order Items -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-cubes" style="color:#3b82f6;"></i>
            <h2>منتجات الطلب</h2>
        </div>
        <div style="overflow-x:auto;">
            <table class="items-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>سعر الوحدة</th>
                        <th>إجمالي البيع</th>
                        <th>التكلفة</th>
                        <th>الربح</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($order_items)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:30px;color:#9ca3af;">لا توجد منتجات لهذا الطلب.</td></tr>
                    <?php else: ?>
                        <?php foreach ($order_items as $i => $item): ?>
                        <tr>
                            <td style="color:#9ca3af;"><?php echo $i + 1; ?></td>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td style="font-weight:700;text-align:center;"><?php echo $item['quantity']; ?></td>
                            <td><?php echo number_format($item['unit_price'], 2); ?></td>
                            <td style="color:#059669;font-weight:700;"><?php echo number_format($item['item_total_sale'], 2); ?></td>
                            <td style="color:#ef4444;font-weight:700;"><?php echo number_format($item['item_total_cost'], 2); ?></td>
                            <td style="font-weight:900;color:<?php echo $item['item_profit'] >= 0 ? '#2563eb' : '#dc2626'; ?>;">
                                <?php echo number_format($item['item_profit'], 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="totals-section">
            <div class="totals-box">
                <div class="detail-row">
                    <span class="detail-label">مجموع المنتجات</span>
                    <span class="detail-value"><?php echo number_format($order['subtotal'], 2); ?> ريال</span>
                </div>
                <?php if ($order['shipping_fee'] > 0): ?>
                <div class="detail-row">
                    <span class="detail-label">رسوم الشحن</span>
                    <span class="detail-value"><?php echo number_format($order['shipping_fee'], 2); ?> ريال</span>
                </div>
                <?php endif; ?>
                <?php if ($order['discount_amount'] > 0): ?>
                <div class="detail-row" style="color:#d97706;">
                    <span class="detail-label">الخصم</span>
                    <span class="detail-value">-<?php echo number_format($order['discount_amount'], 2); ?> ريال</span>
                </div>
                <?php endif; ?>
                <div class="detail-row totals-grand">
                    <span class="detail-label">الإجمالي النهائي</span>
                    <span class="detail-value" style="color:#059669;font-size:1.15rem;"><?php echo number_format($order['total_amount'], 2); ?> ريال</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Notes & Rejection (if any) -->
    <?php if (!empty($order['notes']) || (!empty($order['rejection_reason']) && $order['order_status'] === 'مرفوض')): ?>
    <div class="card">
        <div class="card-header">
            <i class="fas fa-sticky-note" style="color:#f59e0b;"></i>
            <h2>ملاحظات</h2>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
            <?php if (!empty($order['notes'])): ?>
                <div>
                    <p style="font-size:0.85rem;font-weight:600;color:#4b5563;margin-bottom:6px;">ملاحظات الطلب:</p>
                    <div class="notes-box"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($order['rejection_reason']) && $order['order_status'] === 'مرفوض'): ?>
                <div>
                    <p style="font-size:0.85rem;font-weight:600;color:#991b1b;margin-bottom:6px;"><i class="fas fa-times-circle"></i> سبب الرفض:</p>
                    <div class="rejection-box"><?php echo nl2br(htmlspecialchars($order['rejection_reason'])); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 4. Footer -->
    <div class="card">
        <div class="print-footer">
            شكراً لتعاملكم معنا &mdash; <?php echo htmlspecialchars($company_settings['company_name'] ?? ''); ?>
        </div>
    </div>

</div>
</body>
</html>