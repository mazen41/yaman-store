<?php
/**
 * View Basket Details - Matches basket_complete.php structure
 * EDITED: Replaced 'Supplier' with 'Payment Source' in the main info card.
 * EDITED: Added 'Account Number' to the main info card.
 * NEW: Updated attachment display logic to handle multiple files stored as JSON.
 */

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$basket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($basket_id === 0) {
    header('Location: index.php');
    exit();
}

// Get basket details
try {
    // Select ALL columns from purchase_baskets
    $stmt = $db->prepare("SELECT * FROM purchase_baskets WHERE id = ?");
    $stmt->execute([$basket_id]);
    $basket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$basket) {
        header('Location: index.php');
        exit();
    }

    // Get basket items
    $items_stmt = $db->prepare("
        SELECT 
            bi.*,
            o.order_number,
            c.name as customer_name,
            c.customer_code
        FROM basket_items bi
        LEFT JOIN customer_orders o ON bi.order_id = o.id
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE bi.basket_id = ?
        ORDER BY bi.added_at
    ");
    $items_stmt->execute([$basket_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get group name
    $group_name = '';
    if (isset($basket['purchase_group_id']) && !empty($basket['purchase_group_id'])) {
        $grp_stmt = $db->prepare("SELECT group_name FROM purchase_groups WHERE id = ?");
        $grp_stmt->execute([$basket['purchase_group_id']]);
        $group = $grp_stmt->fetch(PDO::FETCH_ASSOC);
        $group_name = $group ? $group['group_name'] : '';
    }

    // Get creator name
    $creator_stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
    $creator_stmt->execute([$basket['created_by']]);
    $creator = $creator_stmt->fetch(PDO::FETCH_ASSOC);
    $creator_name = $creator ? $creator['username'] : '';

    // Get payment source details
    $payment_source_display = '';
    if (!empty($basket['payment_source_type']) && !empty($basket['payment_source_id'])) {
        if ($basket['payment_source_type'] == 'bank_account') {
            $ps_stmt = $db->prepare("SELECT bank_name, account_number FROM bank_accounts WHERE id = ?");
            $ps_stmt->execute([$basket['payment_source_id']]);
            $ps = $ps_stmt->fetch(PDO::FETCH_ASSOC);
            if ($ps) {
                $payment_source_display = 'حساب بنكي: ' . $ps['bank_name'] . ' - ' . $ps['account_number'];
            }
        } elseif ($basket['payment_source_type'] == 'purchase_card') {
            $ps_stmt = $db->prepare("SELECT card_name, card_number FROM purchase_cards WHERE id = ?");
            $ps_stmt->execute([$basket['payment_source_id']]);
            $ps = $ps_stmt->fetch(PDO::FETCH_ASSOC);
            if ($ps) {
                $card_display = !empty($ps['card_name']) ? $ps['card_name'] : $ps['card_number'];
                $payment_source_display = 'بطاقة شراء: ' . $card_display;
            }
        }
    }

} catch (PDOException $e) {
    die('خطأ في قاعدة البيانات: ' . $e->getMessage());
}

$page_title = 'تفاصيل السلة';
include '../../includes/header.php';
?>

<style>
    /* ... Your existing CSS styles ... */
    :root {
        --primary-color: #4f46e5;
        --primary-hover-color: #4338ca;
        --success-color: #C7A46D;
        --success-hover-color: #059669;
        --secondary-color: #6b7280;
        --secondary-hover-color: #4b5563;
        --danger-color: #ef4444;
        --danger-hover-color: #dc2626;
        --background-color: #f9fafb;
        --card-background-color: #ffffff;
        --border-color: #e5e7eb;
        --text-color: #1f2937;
        --text-muted-color: #6b7280;
        --font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;
    }

    body {
        font-family: var(--font-family);
        background-color: var(--background-color);
        color: var(--text-color);
    }

    .card {
        background: var(--card-background-color);
        border-radius: 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        padding: 2rem;
        margin-bottom: 1.5rem;
        border: 1px solid var(--border-color);
    }

    .card-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--text-color);
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--border-color);
    }

    .card-title i {
        color: var(--primary-color);
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
    }

    .info-item {
        padding: 1rem;
        background: #f9fafb;
        border-radius: 8px;
        border: 1px solid var(--border-color);
    }

    .info-label {
        font-size: 0.875rem;
        font-weight: 600;
        color: #6b7280;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .info-value {
        font-size: 1rem;
        font-weight: 600;
        color: #1f2937;
    }

    .status-badge {
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
        display: inline-block;
    }

    .status-active {
        background: #dbeafe;
        color: #1e40af;
    }

    .status-ordered {
        background: #d1fae5;
        color: #065f46;
    }

    .status-cancelled {
        background: #fee2e2;
        color: #991b1b;
    }

    .totals-box {
        background: #f9fafb;
        border-radius: 12px;
        padding: 1.5rem;
        margin-top: 1.5rem;
        border: 1px solid var(--border-color);
    }

    .total-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 0;
        border-bottom: 1px solid var(--border-color);
        font-size: 1rem;
    }

    .total-row:last-child {
        border-bottom: none;
        padding-top: 1.5rem;
        margin-top: 0.5rem;
        border-top: 2px solid var(--border-color);
    }

    .total-row span:first-child {
        font-weight: 600;
        color: var(--text-muted-color);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .total-row span:last-child {
        font-weight: 700;
        color: var(--text-color);
    }

    .grand-total {
        font-size: 1.75rem !important;
        color: var(--primary-color) !important;
    }

    .btn {
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-primary {
        background: var(--primary-color);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-hover-color);
    }

    .btn-success {
        background: var(--success-color);
        color: white;
    }

    .btn-success:hover {
        background: var(--success-hover-color);
    }

    .btn-secondary {
        background: var(--secondary-color);
        color: white;
    }

    .btn-secondary:hover {
        background: var(--secondary-hover-color);
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }

    .table th {
        background: #f9fafb;
        padding: 12px;
        text-align: right;
        font-weight: 600;
        border-bottom: 2px solid #e5e7eb;
        font-size: 0.875rem;
    }

    .table td {
        padding: 12px;
        border-bottom: 1px solid #e5e7eb;
    }

    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #6b7280;
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.3;
    }
</style>

<!-- Page Header -->
<div class="page-header">
    <h1><i class="fas fa-shopping-basket"></i> <?php echo $page_title; ?></h1>
    <div class="breadcrumb">
        <a href="../../index.php"><i class="fas fa-home"></i> الرئيسية</a>
        <span>/</span>
        <a href="index.php">المشتريات</a>
        <span>/</span>
        <span>تفاصيل السلة</span>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">

            <!-- Basket Info Card -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h2 class="card-title" style="margin: 0; border: none; padding: 0;">
                        <i class="fas fa-info-circle"></i>
                        معلومات السلة الأساسية
                    </h2>
                    <div>
                        <?php
                        $status_class = 'status-active';
                        $status_text = 'مفعلة';
                        if ($basket['status'] == 'ordered') {
                            $status_class = 'status-ordered';
                            $status_text = 'تم الطلب';
                        } elseif ($basket['status'] == 'cancelled') {
                            $status_class = 'status-cancelled';
                            $status_text = 'ملغاة';
                        }
                        ?>
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </span>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-barcode"></i> كود السلة</div>
                        <div class="info-value"><?php echo htmlspecialchars($basket['basket_code'] ?? 'N/A'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-tag"></i> اسم السلة</div>
                        <div class="info-value"><?php echo htmlspecialchars($basket['basket_name'] ?? 'N/A'); ?></div>
                    </div>

                    <!-- EDITED: Added Account Number -->
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-hashtag"></i> رقم الحساب</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($basket['account_number'] ?? '<span style="color: #9ca3af;">غير محدد</span>'); ?>
                        </div>
                    </div>

                    <!-- EDITED: Replaced Supplier with Payment Source -->
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-credit-card"></i> مصدر الدفع</div>
                        <div class="info-value">
                            <?php echo !empty($payment_source_display) ? htmlspecialchars($payment_source_display) : '<span style="color: #9ca3af;">غير محدد</span>'; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar"></i> تاريخ الشراء</div>
                        <div class="info-value">
                            <?php echo $basket['purchase_date'] ? date('Y-m-d', strtotime($basket['purchase_date'])) : 'N/A'; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-check"></i> تاريخ التسليم المتوقع</div>
                        <div class="info-value">
                            <?php
                            echo !empty($basket['expected_delivery_date'])
                                ? date('Y-m-d', strtotime($basket['expected_delivery_date']))
                                : '<span style="color: #9ca3af;">غير محدد</span>';
                            ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-user"></i> تم الإنشاء بواسطة</div>
                        <div class="info-value"><?php echo htmlspecialchars($creator_name); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-clock"></i> تاريخ الإنشاء</div>
                        <div class="info-value"><?php echo date('Y-m-d H:i', strtotime($basket['created_at'])); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-shopping-cart"></i> عدد الطلبات</div>
                        <div class="info-value"><?php echo $basket['total_items'] ?? 0; ?> طلب</div>
                    </div>
                </div>

                <?php if (!empty($basket['notes'])): ?>
                    <div
                        style="padding: 1rem; background: #fef3c7; border-radius: 8px; margin-top: 1.5rem; border: 1px solid #fde68a;">
                        <strong><i class="fas fa-sticky-note"></i> ملاحظات اختيارية:</strong>
                        <p style="margin-top: 0.5rem; margin-bottom: 0; color: #78350f;">
                            <?php echo nl2br(htmlspecialchars($basket['notes'])); ?></p>
                    </div>
                <?php endif; ?>

                <?php
                // Decode the JSON string of attachment paths
                $attachment_paths = json_decode($basket['attachment_path'], true); // true for associative array
                if (!empty($attachment_paths) && is_array($attachment_paths)):
                ?>
                    <div
                        style="padding: 1rem; background: #dbeafe; border-radius: 8px; margin-top: 1rem; border: 1px solid #bfdbfe;">
                        <strong><i class="fas fa-paperclip"></i> المرفقات:</strong>
                        <ul style="list-style-type: none; padding-left: 0; margin-top: 0.5rem; margin-bottom: 0;">
                            <?php foreach ($attachment_paths as $path):
                                $filename = basename($path);
                            ?>
                                <li style="margin-bottom: 0.25rem;">
                                    <a href="<?php echo htmlspecialchars($path); ?>" target="_blank"
                                        style="color: #1e40af; text-decoration: underline;">
                                        <i class="fas fa-download"></i> <?php echo htmlspecialchars($filename); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Basket Items -->
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-list"></i>
                    الطلبات في السلة
                </h2>

                <?php if (empty($items)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>لا توجد طلبات في هذه السلة</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>رقم الطلب</th>
                                <th>العميل</th>
                                <th>كود العميل</th>
                                <th>المبلغ</th>
                                <th>ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $num = 1;
                            foreach ($items as $item):
                                ?>
                                <tr>
                                    <td><?php echo $num++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($item['order_number'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['customer_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($item['customer_code'] ?? 'N/A'); ?></td>
                                    <td style="font-family: monospace; text-align: left;">
                                        <?php echo number_format($item['total_price'] ?? 0); ?> ر.ي
                                    </td>

                                    <td><?php echo htmlspecialchars($item['notes'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- ============================================ -->
            <!-- EDITED: Financial Summary (Matches basket_complete.php) -->
            <!-- ============================================ -->
            <div class="card">
                <h2 class="card-title">
                    <i class="fas fa-calculator"></i>
                    الملخص المالي (إجمالي يدوي)
                </h2>

                <div class="totals-box">
                    <div class="total-row">
                        <span><i class="fas fa-calculator"></i> إجمالي عدد المنتجات</span>
                        <span><?php echo $basket['total_items'] ?? 0; ?></span>
                    </div>

                    <div class="total-row">
                        <span><i class="fas fa-money-bill-wave"></i> المجموع قبل الخصم</span>
                        <span><?php echo number_format($basket['subtotal_amount'] ?? 0); ?> ر.ي</span>
                    </div>

                    <!-- EDITED: Corrected discount labels to match database columns -->
                    <div class="total-row">
                        <span><i class="fas fa-tag"></i> خصم يدوي</span>
                        <span><?php echo number_format($basket['discount_amount'] ?? 0); ?> ر.ي</span>
                    </div>

                    <div class="total-row">
                        <span><i class="fas fa-star"></i> خصم نقاط</span>
                        <span><?php echo number_format($basket['points_discount'] ?? 0); ?> ر.ي</span>
                    </div>

                    <div class="total-row">
                        <span><i class="fas fa-users"></i> خصم نادي</span>
                        <span><?php echo number_format($basket['club_discount'] ?? 0); ?> ر.ي</span>
                    </div>

                    <?php if (!empty($basket['coupon_code'])): ?>
                        <div class="total-row">
                            <span><i class="fas fa-ticket-alt"></i> كود الخصم</span>
                            <span><?php echo htmlspecialchars($basket['coupon_code']); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="total-row">
                        <span><i class="fas fa-tags"></i> إجمالي الخصومات</span>
                        <span><?php
                        // This calculation is correct as it sums all three discount columns
                        $total_discounts = ($basket['discount_amount'] ?? 0) + ($basket['points_discount'] ?? 0) + ($basket['club_discount'] ?? 0);
                        echo number_format($total_discounts);
                        ?> ر.ي</span>
                    </div>

                    <?php if (isset($basket['shipping_cost']) && $basket['shipping_cost'] > 0): ?>
                        <div class="total-row">
                            <span><i class="fas fa-shipping-fast"></i> تكلفة الشحن</span>
                            <span><?php echo number_format($basket['shipping_cost']); ?> ر.ي</span>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($basket['tax_amount']) && $basket['tax_amount'] > 0): ?>
                        <div class="total-row">
                            <span><i class="fas fa-percent"></i> الضريبة (<?php echo $basket['tax_rate'] ?? 0; ?>%)</span>
                            <span><?php echo number_format($basket['tax_amount']); ?> ر.ي</span>
                        </div>
                    <?php endif; ?>

                    <div class="total-row">
                        <span><i class="fas fa-check-circle"></i> الصافي النهائي</span>
                        <span class="grand-total"><?php echo number_format($basket['final_amount'] ?? 0); ?> ر.ي</span>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
                <a href="index.php" class="btn btn-secondary"> <!-- Changed from show_baskets.php to index.php assuming it's the main list -->
                    <i class="fas fa-arrow-right"></i>
                    العودة
                </a>

                <a href="edit_basket.php?id=<?php echo $basket_id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i>
                    تعديل السلة
                </a>

                <a href="print_basket.php?id=<?php echo $basket_id; ?>" target="_blank" class="btn btn-success">
                    <i class="fas fa-print"></i>
                    طباعة الفاتورة
                </a>
            </div>

        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>