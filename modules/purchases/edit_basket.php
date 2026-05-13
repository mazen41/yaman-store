<?php
/**
 * Edit Basket - Version 2.0
 * تعديل السلة
 *
 * FIX: Removed LOCK TABLES/UNLOCK TABLES from helper functions and replaced with
 * SELECT FOR UPDATE. This resolves the "No active transactions" PDO error, as
 * LOCK TABLES implicitly commits the main transaction when used with InnoDB/PDO.
 * The use of SELECT ... FOR UPDATE maintains financial locking within the context
 * of the main transaction started by $db->beginTransaction().
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/phone_utils.php'; // Assuming this might be used for other parts, though not directly in this snippet
require_once '../../includes/accounting_functions.php'; // Assuming this might be used, though create_journal_entry is commented out for edits.

$basket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($basket_id === 0) {
    header('Location: index.php');
    exit();
}

$page_title = 'تعديل السلة';
$error_message = '';
$success_message = ''; // This variable is not used to display the message, only $_SESSION is used for the redirect success.

// ===================================================================
// HELPER FUNCTIONS FOR PAYMENT ADJUSTMENT - FIX APPLIED HERE
// ===================================================================

/**
 * Reverses a payment or refunds an amount to a given source.
 *
 * @param PDO $db The database connection.
 * @param string|null $source_type 'purchase_card' or 'bank_account'.
 * @param int|null $source_id The ID of the card or account.
 * @param float $amount The positive amount to refund.
 * @param int $basket_id The related basket ID for logging.
 * @param int $user_id The user performing the action.
 * @throws Exception If the source is invalid or the update fails.
 */
function refundToSource(PDO $db, $source_type, $source_id, $amount, $basket_id, $user_id)
{
    if (empty($source_type) || empty($source_id) || $amount <= 0) {
        return; // Nothing to refund
    }

    // NOTE: LOCK TABLES/UNLOCK TABLES removed to prevent implicit commit of the main transaction.
    // Row locking is now managed by SELECT...FOR UPDATE within the main transaction.
    try {
        if ($source_type == 'purchase_card') {
            // Re-adding FOR UPDATE to lock the row within the main transaction
            $stmt = $db->prepare("SELECT balance, purchase_amount FROM purchase_cards WHERE id = ? FOR UPDATE"); 
            $stmt->execute([$source_id]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$card) throw new Exception('بطاقة الشراء الأصلية غير موجودة.');

            $balance_before = $card['balance'];
            $new_balance = $balance_before + $amount;
            $new_purchase_amount = ($card['purchase_amount'] ?? 0) - $amount; // Reduce purchase amount

            $update_stmt = $db->prepare("UPDATE purchase_cards SET balance = ?, purchase_amount = ? WHERE id = ?");
            $update_stmt->execute([$new_balance, $new_purchase_amount, $source_id]);

            $txn_stmt = $db->prepare("
                INSERT INTO purchase_card_transactions
                (purchase_card_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, description, created_by, created_at)
                VALUES (?, 'refund', ?, ?, ?, 'basket', ?, ?, ?, NOW())
            ");
            $description = 'إرجاع مبلغ بسبب تعديل السلة #' . $basket_id;
            $txn_stmt->execute([$source_id, $amount, $balance_before, $new_balance, $basket_id, $description, $user_id]);

        } elseif ($source_type == 'bank_account') {
            // Re-adding FOR UPDATE to lock the row within the main transaction
            $stmt = $db->prepare("SELECT current_balance FROM bank_accounts WHERE id = ? FOR UPDATE"); 
            $stmt->execute([$source_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account) throw new Exception('الحساب البنكي الأصلي غير موجود.');

            $new_balance = $account['current_balance'] + $amount;
            $update_stmt = $db->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
            $update_stmt->execute([$new_balance, $source_id]);
        }
    } catch (Exception $e) {
        // Re-throw the exception to be caught by the main try/catch block
        throw $e;
    }
}

/**
 * Deducts a payment from a given source.
 *
 * @param PDO $db The database connection.
 * @param string|null $source_type 'purchase_card' or 'bank_account'.
 * @param int|null $source_id The ID of the card or account.
 * @param float $amount The positive amount to deduct.
 * @param int $basket_id The related basket ID for logging.
 * @param int $user_id The user performing the action.
 * @param string $basket_name The name of the basket for logging.
 * @throws Exception If the source is invalid, balance is insufficient, or the update fails.
 */
function deductFromSource(PDO $db, $source_type, $source_id, $amount, $basket_id, $user_id, $basket_name)
{
    if (empty($source_type) || empty($source_id) || $amount <= 0) {
        return; // Nothing to deduct
    }

    // NOTE: LOCK TABLES/UNLOCK TABLES removed to prevent implicit commit of the main transaction.
    // Row locking is now managed by SELECT...FOR UPDATE within the main transaction.
    try {
        if ($source_type == 'purchase_card') {
            // Re-adding FOR UPDATE to lock the row within the main transaction
            $stmt = $db->prepare("SELECT balance, purchase_amount FROM purchase_cards WHERE id = ? FOR UPDATE"); 
            $stmt->execute([$source_id]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$card) throw new Exception('بطاقة الشراء المحددة غير صالحة.');
            if ($card['balance'] < $amount) throw new Exception('الرصيد في بطاقة الشراء غير كافٍ. الرصيد الحالي: ' . number_format($card['balance'], 2) . ' YER');

            $balance_before = $card['balance'];
            $new_balance = $balance_before - $amount;
            $new_purchase_amount = ($card['purchase_amount'] ?? 0) + $amount; // Increase purchase amount

            $update_stmt = $db->prepare("UPDATE purchase_cards SET balance = ?, purchase_amount = ? WHERE id = ?");
            $update_stmt->execute([$new_balance, $new_purchase_amount, $source_id]);

            $txn_stmt = $db->prepare("
                INSERT INTO purchase_card_transactions
                (purchase_card_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, description, created_by, created_at)
                VALUES (?, 'deduct', ?, ?, ?, 'basket', ?, ?, ?, NOW())
            ");
            $description = 'شراء سلة (تحديث): ' . $basket_name;
            $txn_stmt->execute([$source_id, $amount, $balance_before, $new_balance, $basket_id, $description, $user_id]);

        } elseif ($source_type == 'bank_account') {
            // Re-adding FOR UPDATE to lock the row within the main transaction
            $stmt = $db->prepare("SELECT current_balance FROM bank_accounts WHERE id = ? FOR UPDATE"); 
            $stmt->execute([$source_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account) throw new Exception('الحساب البنكي المحدد غير صالح.');
            if ($account['current_balance'] < $amount) throw new Exception('الرصيد في الحساب البنكي غير كافٍ. الرصيد الحالي: ' . number_format($account['current_balance'], 2) . ' YER');

            $new_balance = $account['current_balance'] - $amount;
            $update_stmt = $db->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
            $update_stmt->execute([$new_balance, $source_id]);
        }
    } catch (Exception $e) {
        // Re-throw the exception to be caught by the main try/catch block
        throw $e;
    }
}


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();

        if ($_POST['action'] == 'update_basket') {
            
            // --- 1. GET ORIGINAL BASKET STATE (LOCKED FOR UPDATE) ---
            $stmt = $db->prepare("SELECT * FROM purchase_baskets WHERE id = ? FOR UPDATE");
            $stmt->execute([$basket_id]);
            $original_basket = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$original_basket) {
                throw new Exception("Basket not found or has been deleted.");
            }

            // Determine the amount that was originally deducted
            // Use final_price_override if set, otherwise final_amount.
            // Ensure original_amount_deducted is treated as float for correct comparison.
            $original_amount_deducted = (float)($original_basket['final_price_override'] !== null && $original_basket['final_price_override'] > 0
                ? $original_basket['final_price_override']
                : $original_basket['final_amount']);
            
            // --- 2. GET NEW DATA & CALCULATE NEW TOTALS ---
            $basket_name = trim($_POST['basket_name']);
            if (empty($basket_name)) {
                throw new Exception('يرجى إدخال اسم السلة');
            }

            $subtotal = (float) ($_POST['subtotal_amount'] ?? 0);
            $shipping = (float) ($_POST['shipping_cost'] ?? 0);
            $tax_rate = (float) ($_POST['tax_rate'] ?? 0);
            $tax_included = isset($_POST['tax_included']) ? 1 : 0;
            $manual_discount = (float) ($_POST['discount_amount'] ?? 0);
            $points_discount = (float) ($_POST['points_discount'] ?? 0);
            $club_discount = (float) ($_POST['club_discount'] ?? 0);
            
            $total_discount = $manual_discount + $points_discount + $club_discount;
            $base_for_tax = $subtotal - $total_discount;
            
            $tax_amount = 0;
            $final_total = 0;

            if ($tax_included) {
                $tax_amount = ($tax_rate > 0) ? ($base_for_tax * $tax_rate) / (100 + $tax_rate) : 0;
                $final_total = $base_for_tax + $shipping;
            } else {
                $tax_amount = $base_for_tax * ($tax_rate / 100);
                $final_total = $base_for_tax + $tax_amount + $shipping;
            }

            $final_price_override = !empty($_POST['final_price_override']) ? (float)$_POST['final_price_override'] : null;

            // Determine the new amount to be deducted
            // Ensure new_amount_to_deduct is treated as float for correct comparison.
            $new_amount_to_deduct = (float)($final_price_override !== null && $final_price_override >= 0
                ? $final_price_override
                : $final_total);

            // Get new payment source
            $new_payment_type = !empty($_POST['payment_source_type']) ? $_POST['payment_source_type'] : null;
            $new_payment_id = null;
            if ($new_payment_type === 'bank_account') {
                $new_payment_id = !empty($_POST['payment_source_id_bank']) ? (int)$_POST['payment_source_id_bank'] : null;
            } elseif ($new_payment_type === 'purchase_card') {
                $new_payment_id = !empty($_POST['payment_source_id_purchase']) ? (int)$_POST['payment_source_id_purchase'] : null;
            }


            // --- 3. PERFORM PAYMENT ADJUSTMENT ---
            $original_payment_type = $original_basket['payment_source_type'];
            $original_payment_id = $original_basket['payment_source_id'];

            // Compare float values carefully
            $epsilon = 0.001; // Tolerance for float comparison
            $is_same_amount = abs($new_amount_to_deduct - $original_amount_deducted) < $epsilon;
            $is_same_source = ($original_payment_type == $new_payment_type && $original_payment_id == $new_payment_id);

            if ($is_same_source) {
                if (!$is_same_amount) { // Source is the same, but amount changed
                    $adjustment_amount = $new_amount_to_deduct - $original_amount_deducted;
                    if ($adjustment_amount > 0) { // Need to deduct more
                        deductFromSource($db, $new_payment_type, $new_payment_id, $adjustment_amount, $basket_id, $_SESSION['user_id'], $basket_name);
                    } elseif ($adjustment_amount < 0) { // Need to refund
                        refundToSource($db, $original_payment_type, $original_payment_id, abs($adjustment_amount), $basket_id, $_SESSION['user_id']);
                    }
                }
                // If same source and same amount, no financial adjustment needed for the payment source itself.
            } else {
                // Source has changed OR source is gone/new
                // a. Refund the full original amount from the old source if one existed and a positive amount was deducted
                if ($original_payment_type && $original_payment_id && $original_amount_deducted > 0) {
                    refundToSource($db, $original_payment_type, $original_payment_id, $original_amount_deducted, $basket_id, $_SESSION['user_id']);
                }
                
                // b. Deduct the full new amount from the new source if one is selected and a positive amount is due
                if ($new_payment_type && $new_payment_id && $new_amount_to_deduct > 0) {
                    deductFromSource($db, $new_payment_type, $new_payment_id, $new_amount_to_deduct, $basket_id, $_SESSION['user_id'], $basket_name);
                }
            }
            
            // --- 4. UPDATE THE BASKET RECORD ---
            $sql = "UPDATE purchase_baskets SET 
                basket_name = ?, basket_code = ?, purchase_date = ?, expected_delivery_date = ?, notes = ?, 
                shipping_cost = ?, tax_rate = ?, tax_included = ?, account_number = ?, total_items = ?, 
                coupon_code = ?, points_discount = ?, club_discount = ?, final_price_override = ?, 
                subtotal_amount = ?, discount_amount = ?, tax_amount = ?, final_amount = ?, 
                delivery_codes = ?, delivery_codes_status = ?, 
                payment_source_type = ?, payment_source_id = ?
                WHERE id = ?";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $basket_name, // Use the validated name
                trim($_POST['basket_code']) ?: null,
                $_POST['purchase_date'] ?: null,
                $_POST['expected_delivery_date'] ?: null,
                trim($_POST['notes']),
                $shipping, // Use calculated float
                $tax_rate, // Use calculated float
                $tax_included, // Use calculated int
                trim($_POST['account_number']) ?: null,
                (int) ($_POST['total_products'] ?? 0),
                trim($_POST['coupon_code']) ?: null,
                $points_discount, // Use calculated float
                $club_discount, // Use calculated float
                $final_price_override,
                $subtotal, // Use calculated float
                $manual_discount, // Use calculated float for discount_amount (manual)
                $tax_amount, // Recalculated tax amount
                $final_total, // Recalculated final amount
                trim($_POST['delivery_codes']) ?: null,
                trim($_POST['delivery_codes_status']) ?: null,
                $new_payment_type,
                $new_payment_id,
                $basket_id
            ]);

            $db->commit();
            $_SESSION['success_message'] = 'تم تحديث السلة بنجاح وتعديل الدفعات المالية.';
            header('Location: view_basket.php?id=' . $basket_id);
            exit();
        }

    } catch (Exception $e) {
        // This is where the original error was likely thrown if commit() failed.
        // The check for inTransaction() is important and correct here.
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'حدث خطأ: ' . $e->getMessage();
    }
}

// Get basket details for the form
try {
    $stmt = $db->prepare("SELECT * FROM purchase_baskets WHERE id = ?");
    $stmt->execute([$basket_id]);
    $basket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$basket) {
        header('Location: index.php');
        exit();
    }

    // Load accounts/cards for payment source selectors
    $purchase_cards = $db->query("SELECT id, card_number, card_name, balance FROM purchase_cards ORDER BY card_name")->fetchAll(PDO::FETCH_ASSOC);
    $bank_accounts = $db->query("SELECT id, bank_name, account_number, current_balance FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('خطأ في قاعدة البيانات: ' . $e->getMessage());
}

include '../../includes/header.php';
?>

<!-- START: Redesigned Styles -->
<style>
    :root {
        --primary-color: #4f46e5;
        /* Indigo 600 */
        --primary-hover-color: #4338ca;
        /* Indigo 700 */
        --success-color: #C7A46D;
        /* Emerald 500 */
        --success-hover-color: #059669;
        /* Emerald 600 */
        --secondary-color: #6b7280;
        /* Gray 500 */
        --secondary-hover-color: #4b5563;
        /* Gray 600 */
        --danger-color: #ef4444;
        /* Red 500 */
        --danger-hover-color: #dc2626;
        /* Red 600 */
        --background-color: #f9fafb;
        /* Gray 50 */
        --card-background-color: #ffffff;
        --border-color: #e5e7eb;
        /* Gray 200 */
        --text-color: #1f2937;
        /* Gray 800 */
        --text-muted-color: #6b7280;
        /* Gray 500 */
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

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-label {
        display: block;
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
        /* Gray-700 */
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .form-label .required {
        color: var(--danger-color);
    }

    .form-control,
    .form-select {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 1rem;
        color: var(--text-color);
        background-color: #ffffff;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-control:focus,
    .form-select:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
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
    }

    .total-row span:first-child {
        font-weight: 600;
        color: var(--text-muted-color);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .total-row span:last-child,
    .total-row div {
        font-weight: 700;
        color: var(--text-color);
    }

    #grandTotalDisplay {
        font-size: 1.75rem;
        color: var(--primary-color);
    }

    .totals-input {
        width: 140px;
        padding: 0.5rem 0.75rem;
        text-align: left;
        background-color: #fff;
        color: var(--text-color);
        border: 1px solid var(--border-color);
        border-radius: 6px;
    }

    .totals-input:focus {
        border-color: var(--primary-color);
        outline: none;
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

    .alert {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 500;
    }

    .alert-danger {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    .grid {
        display: grid;
    }

    .grid-cols-1 {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }

    .gap-6 {
        gap: 1.5rem;
    }

    .items-start {
        align-items: flex-start;
    }

    @media (min-width: 768px) {
        .md\:grid-cols-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .md\:col-span-2 {
            grid-column: span 2 / span 2;
        }
    }

    @media (min-width: 1024px) {
        .lg\:grid-cols-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .lg\:col-span-2 {
            grid-column: span 2 / span 2;
        }

        .lg\:col-span-1 {
            grid-column: span 1 / span 1;
        }
    }
</style>
<!-- END: Redesigned Styles -->

<!-- Page Header -->
<div class="page-header">
    <h1><i class="fas fa-edit"></i> <?php echo $page_title; ?></h1>
    <div class="breadcrumb">
        <a href="../../index.php"><i class="fas fa-home"></i> الرئيسية</a>
        <span>/</span>
        <a href="index.php">المشتريات</a>
        <span>/</span>
        <span>تعديل السلة</span>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="update_basket">

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

                    <!-- Main Info Column -->
                    <div class="lg:col-span-2">
                        <!-- Basket Info Card -->
                        <div class="card">
                            <div class="card-title"><i class="fas fa-info-circle"></i> معلومات السلة الأساسية</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="form-group">
                                    <label class="form-label" for="basket_name"><span class="required">*</span> اسم
                                        السلة</label>
                                    <input type="text" id="basket_name" name="basket_name" class="form-control"
                                        placeholder="مثال: سلة شراء يناير 2025"
                                        value="<?php echo htmlspecialchars($basket['basket_name'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="basket_code"><i class="fas fa-barcode"></i> كود السلة
                                        (اختياري)</label>
                                    <input type="text" id="basket_code" name="basket_code" class="form-control"
                                        placeholder="سيتم إنشاؤه تلقائياً إذا تُرك فارغاً"
                                        value="<?php echo htmlspecialchars($basket['basket_code'] ?? ''); ?>">
                                    <small
                                        style="color: #6b7280; font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                                        <i class="fas fa-info-circle"></i> اتركه فارغاً للإنشاء التلقائي (مثال:
                                        BASKET-20251115-123948)
                                    </small>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="account_number"><i class="fas fa-hashtag"></i> رقم الحساب</label>
                                    <input type="number" id="account_number" name="account_number" class="form-control"
                                        placeholder="أدخل رقم الحساب العددي هنا"
                                        value="<?php echo htmlspecialchars($basket['account_number'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="purchase_date"><i class="fas fa-calendar"></i> تاريخ
                                        الشراء</label>
                                    <input type="date" id="purchase_date" name="purchase_date" class="form-control"
                                        value="<?php echo htmlspecialchars($basket['purchase_date'] ?? date('Y-m-d')); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="expected_delivery_date"><i
                                            class="fas fa-calendar-check"></i> تاريخ التسليم المتوقع</label>
                                    <input type="date" id="expected_delivery_date" name="expected_delivery_date"
                                        class="form-control"
                                        value="<?php echo htmlspecialchars($basket['expected_delivery_date'] ?? ''); ?>">
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label" for="notes"><i class="fas fa-sticky-note"></i> ملاحظات /
                                        تفاصيل إضافية</label>
                                    <textarea name="notes" id="notes" class="form-control" rows="2"
                                        placeholder="ملاحظات اختيارية"><?php echo htmlspecialchars($basket['notes'] ?? ''); ?></textarea>
                                </div>
                                <!-- Attachment field for edit - removed for simplicity, as managing file updates requires more complex logic (delete old, upload new).
                                     If needed, it should be re-added with proper handling.
                                <div class="form-group">
                                    <label class="form-label" for="attachment"><i class="fas fa-paperclip"></i> رفع مرفق
                                        (اختياري)</label>
                                    <input type="file" name="attachment" id="attachment" class="form-control">
                                </div>
                                -->
                            </div>
                        </div>

                        <!-- Payment Source Card -->
                        <div class="card">
                            <div class="card-title"><i class="fas fa-wallet"></i> مصدر الدفع (اختياري)</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                                <div class="form-group">
                                    <label for="paymentSourceType" class="form-label">اختر نوع المصدر</label>
                                    <select name="payment_source_type" id="paymentSourceType" class="form-select">
                                        <option value="" <?php echo (empty($basket['payment_source_type'])) ? 'selected' : ''; ?>>-- بدون تحديد --</option>
                                        <option value="bank_account" <?php echo ($basket['payment_source_type'] ?? '') === 'bank_account' ? 'selected' : ''; ?>>حساب بنكي</option>
                                        <option value="purchase_card" <?php echo ($basket['payment_source_type'] ?? '') === 'purchase_card' ? 'selected' : ''; ?>>بطاقة شراء</option>
                                    </select>
                                </div>
                                <div id="paymentSourceDetails"
                                    style="<?php echo (empty($basket['payment_source_type'])) ? 'display: none;' : ''; ?>">
                                    <div class="form-group" id="bankAccountSelector"
                                        style="<?php echo ($basket['payment_source_type'] ?? '') === 'bank_account' ? 'display: block;' : 'display: none;'; ?>">
                                        <label for="bankAccountSearch" class="form-label">ابحث عن الحساب البنكي</label>
                                        <input type="text" id="bankAccountSearch" class="form-control"
                                            placeholder="اكتب اسم البنك أو رقم الحساب..." style="margin-bottom: 10px;">
                                        
                                        <label for="bankAccountSelect" class="form-label">اختر الحساب البنكي</label>
                                        <select name="payment_source_id_bank" id="bankAccountSelect"
                                            class="form-select"
                                            <?php echo ($basket['payment_source_type'] ?? '') === 'bank_account' ? '' : 'disabled'; ?>>
                                            <option value="">-- اختر الحساب --</option>
                                            <?php foreach ($bank_accounts as $account): ?>
                                                <option value="<?php echo $account['id']; ?>"
                                                    data-balance="<?php echo $account['current_balance']; ?>"
                                                    <?php echo (!empty($basket['payment_source_type']) && $basket['payment_source_type'] === 'bank_account' && (int) $basket['payment_source_id'] === (int) $account['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($account['bank_name'] . ' (' . $account['account_number'] . ') - Balance: ' . number_format($account['current_balance'], 2) . ' YER'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group" id="purchaseCardSelector"
                                        style="<?php echo ($basket['payment_source_type'] ?? '') === 'purchase_card' ? 'display: block;' : 'display: none;'; ?>">
                                        <label for="purchaseCardSearch" class="form-label">ابحث عن بطاقة الشراء</label>
                                        <input type="text" id="purchaseCardSearch" class="form-control"
                                            placeholder="اكتب اسم البطاقة أو رقمها..." style="margin-bottom: 10px;">
                                        
                                        <label for="purchaseCardSelect" class="form-label">اختر بطاقة الشراء</label>
                                        <select name="payment_source_id_purchase" id="purchaseCardSelect"
                                            class="form-select"
                                            <?php echo ($basket['payment_source_type'] ?? '') === 'purchase_card' ? '' : 'disabled'; ?>>
                                            <option value="">-- اختر البطاقة --</option>
                                            <?php foreach ($purchase_cards as $card): ?>
                                                <option value="<?php echo $card['id']; ?>"
                                                    data-balance="<?php echo $card['balance']; ?>"
                                                    <?php echo (!empty($basket['payment_source_type']) && $basket['payment_source_type'] === 'purchase_card' && (int) $basket['payment_source_id'] === (int) $card['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($card['card_name'] . ' (' . $card['card_number'] . ') - Balance: ' . number_format($card['balance'], 2) . ' YER'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div id="sourceBalanceContainer"
                                        style="display: none; margin-top: 1rem; padding: 0.75rem; background-color: #eef2ff; border-radius: 8px; border: 1px solid #c7d2fe;">
                                        <span class="font-bold text-lg" style="color: #4338ca;"
                                            id="sourceBalanceDisplay"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Delivery Codes Card -->
                        <div class="card">
                            <div class="card-title"><i class="fas fa-barcode"></i> أكواد التسليم (اختياري)</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="form-group">
                                    <label class="form-label" for="delivery_codes">أكواد التسليم</label>
                                    <textarea name="delivery_codes" id="delivery_codes" class="form-control" rows="2"
                                        placeholder="اكتب الأكواد هنا"><?php echo htmlspecialchars($basket['delivery_codes'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="delivery_codes_status">حالة أكواد التسليم</label>
                                    <input type="text" name="delivery_codes_status" id="delivery_codes_status"
                                        class="form-control"
                                        value="<?php echo htmlspecialchars($basket['delivery_codes_status'] ?? ''); ?>"
                                        placeholder="مثال: تم الاستخدام / قيد التفعيل">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Summary Column -->
                    <div class="lg:col-span-1" style="position: sticky; top: 20px;">
                        <div class="card">
                            <div class="card-title"><i class="fas fa-calculator" style="color:#C7A46D"></i> الملخص
                                المالي (إدخال يدوي)</div>
                            <div class="totals-box" style="padding: 0; background: none; border: none; margin: 0;">
                                <div class="total-row"><span><i class="fas fa-box-open"></i>إجمالي عدد
                                        المنتجات</span><input type="number" name="total_products"
                                        id="totalProductsInput" class="form-control totals-input"
                                        value="<?php echo htmlspecialchars($basket['total_items'] ?? 0); ?>"></div>
                                <div class="total-row"><span><i class="fas fa-file-invoice-dollar"></i>المجموع قبل
                                        الخصم</span><input type="number" name="subtotal_amount" id="subtotalInput"
                                        step="0.01" min="0"
                                        value="<?php echo htmlspecialchars($basket['subtotal_amount'] ?? 0); ?>" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-shipping-fast"></i>تكلفة
                                        الشحن</span><input type="number" name="shipping_cost" id="shippingCost"
                                        step="0.01" min="0"
                                        value="<?php echo htmlspecialchars($basket['shipping_cost'] ?? 0); ?>" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-percent"></i>الضريبة</span>
                                    <div style="display:flex; align-items:center; gap:10px;"><input type="number"
                                            name="tax_rate" id="taxRate" step="0.01" min="0" max="100"
                                            value="<?php echo htmlspecialchars($basket['tax_rate'] ?? 0); ?>"
                                            class="form-control totals-input" style="width:80px;"><span>%</span><label
                                            style="display:flex; align-items:center; gap:5px;"><input type="checkbox"
                                                name="tax_included" id="taxIncluded" <?php echo !empty($basket['tax_included']) ? 'checked' : ''; ?>><span>شامل</span></label></div>
                                </div>
                                <div class="total-row"><span><i class="fas fa-receipt"></i>مبلغ الضريبة</span><span
                                        id="taxAmountDisplay"><?php echo number_format($basket['tax_amount'] ?? 0, 2); ?> YER</span></div>
                                <hr style="border-color: var(--border-color); margin: 1rem 0;">

                                <!-- DISCOUNT SECTION -->
                                <h4 style="font-weight: 600; margin-top: 1.5rem; margin-bottom: 1rem;">الخصومات</h4>
                                <div class="total-row"><span><i class="fas fa-tag"></i>خصم يدوي</span><input
                                        type="number" name="discount_amount" id="manualDiscountInput" step="0.01"
                                        min="0"
                                        value="<?php echo htmlspecialchars($basket['discount_amount'] ?? 0); ?>" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-star"></i>خصم نقاط</span><input
                                        type="number" name="points_discount" id="points_discount" step="0.01" min="0"
                                        value="<?php echo htmlspecialchars($basket['points_discount'] ?? 0); ?>" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-users"></i>خصم نادي</span><input
                                        type="number" name="club_discount" id="club_discount" step="0.01" min="0"
                                        value="<?php echo htmlspecialchars($basket['club_discount'] ?? 0); ?>" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-ticket-alt"></i>كود الخصم</span><input
                                        type="text" name="coupon_code" class="form-control totals-input"
                                        value="<?php echo htmlspecialchars($basket['coupon_code'] ?? ''); ?>"></div>
                                <div class="total-row" style="background:#fffbe6; border-radius: 8px; padding: 1rem;">
                                    <span><i class="fas fa-tags"></i>إجمالي الخصومات</span><span
                                        id="totalDiscountDisplay">0.00 YER</span>
                                </div>

                                <hr style="border-color: var(--border-color); margin: 1rem 0;">
                                <div class="total-row" style="padding-top:1.5rem;">
                                    <span><i class="fas fa-money-bill-wave"></i> الصافي النهائي</span>
                                    <span id="grandTotalDisplay"><?php echo number_format($basket['final_amount'] ?? 0, 2); ?> YER</span>
                                </div>

                                <div class="total-row"
                                    style="background: #eef2ff; border-radius: 8px; padding: 1rem; margin-top: 1rem;">
                                    <span><i class="fas fa-check-double"></i> السعر النهائي للدفع</span>
                                    <input type="number" name="final_price_override" id="final_price_override"
                                        step="0.01" min="0" class="form-control totals-input"
                                        placeholder="السعر الذي سيخصم"
                                        value="<?php echo htmlspecialchars($basket['final_price_override'] ?? ''); ?>">
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="card" style="position: sticky; bottom: 0; z-index: 10;">
                    <div class="flex justify-between items-center gap-4"
                        style="display:flex; justify-content:space-between; align-items:center;">
                        <a href="view_basket.php?id=<?php echo $basket_id; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> إلغاء</a>
                        <div>
                            <button type="submit" name="action" value="update_basket" class="btn btn-primary"
                                id="saveChangesBtn"><i class="fas fa-save"></i> حفظ التغييرات</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    /**
     * Purchase Basket - Manual Entry JS (Adapted for Edit)
     * Version: 4.4
     * - Automatically fills the "Final Payment Price" input with the calculated "Grand Total".
     * - The "Final Payment Price" remains editable for manual overrides.
     * - Modified formatMoney function to display numbers in English (en-US locale).
     * - Handles all financial calculations based on direct user input.
     * - Manages dynamic visibility of payment source selectors.
     * - Adds live search/filter functionality for payment source dropdowns.
     */

    console.log('🚀 Basket Manual JS Loaded (v4.4 for Edit)');

    // ============================================
    // INITIALIZATION
    // ============================================

    document.addEventListener('DOMContentLoaded', function () {
        console.log('✅ DOM Content Loaded');

        // --- FINANCIAL CALCULATION SETUP ---
        const financialInputs = [
            'subtotalInput', 'shippingCost', 'taxRate', 'manualDiscountInput',
            'points_discount', 'club_discount', 'totalProductsInput' // Added totalProductsInput for completeness
        ];
        financialInputs.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('input', updateTotals);
            }
        });
        const taxIncludedCheckbox = document.getElementById('taxIncluded');
        if (taxIncludedCheckbox) {
            taxIncludedCheckbox.addEventListener('change', updateTotals);
        }
        updateTotals(); // Initial calculation on page load with existing data

        // --- PAYMENT SOURCE SELECTION LOGIC ---
        const paymentTypeSelect = document.getElementById('paymentSourceType');
        const paymentDetailsContainer = document.getElementById('paymentSourceDetails');
        const bankSelectorContainer = document.getElementById('bankAccountSelector');
        const cardSelectorContainer = document.getElementById('purchaseCardSelector');
        const bankSelect = document.getElementById('bankAccountSelect');
        const cardSelect = document.getElementById('purchaseCardSelect');
        const balanceDisplayContainer = document.getElementById('sourceBalanceContainer');
        const balanceDisplay = document.getElementById('sourceBalanceDisplay');

        function handlePaymentTypeChange() {
            const selectedType = paymentTypeSelect.value;

            // Reset related fields and hide containers before showing the relevant one
            paymentDetailsContainer.style.display = 'none';
            bankSelectorContainer.style.display = 'none';
            cardSelectorContainer.style.display = 'none';
            balanceDisplayContainer.style.display = 'none';
            balanceDisplay.textContent = '';
            
            // Re-enable/disable select elements
            bankSelect.disabled = true;
            cardSelect.disabled = true;


            if (selectedType === 'bank_account') {
                paymentDetailsContainer.style.display = 'block';
                bankSelectorContainer.style.display = 'block';
                bankSelect.disabled = false;
                updateSourceBalance(bankSelect); // Update balance for selected bank account
            } else if (selectedType === 'purchase_card') {
                paymentDetailsContainer.style.display = 'block';
                cardSelectorContainer.style.display = 'block';
                cardSelect.disabled = false;
                updateSourceBalance(cardSelect); // Update balance for selected purchase card
            }
        }

        function updateSourceBalance(selectElement) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const balance = selectedOption ? selectedOption.getAttribute('data-balance') : null;

            if (balance) {
                balanceDisplay.innerHTML = `<i class="fas fa-wallet"></i> الرصيد المتاح: ${formatMoney(balance)}`;
                balanceDisplayContainer.style.display = 'block';
            } else {
                balanceDisplayContainer.style.display = 'none';
                balanceDisplay.textContent = '';
            }
        }

        if (paymentTypeSelect) {
            paymentTypeSelect.addEventListener('change', handlePaymentTypeChange);
        }
        if (bankSelect) {
            bankSelect.addEventListener('change', () => updateSourceBalance(bankSelect));
        }
        if (cardSelect) {
            cardSelect.addEventListener('change', () => updateSourceBalance(cardSelect));
        }

        handlePaymentTypeChange(); // Run on page load to set initial state and display balance if a source is pre-selected

        // --- DROPDOWN SEARCH/FILTER LOGIC ---
        function setupSearchableDropdown(searchInputId, selectElementId) {
            const searchInput = document.getElementById(searchInputId);
            const selectElement = document.getElementById(selectElementId);

            if (!searchInput || !selectElement) return;

            // Store original options for filtering
            const originalOptions = Array.from(selectElement.options).map(option => ({
                value: option.value,
                text: option.textContent,
                balance: option.getAttribute('data-balance')
            }));

            searchInput.addEventListener('input', function () {
                const searchTerm = this.value.toLowerCase().trim();
                
                // Clear current options
                selectElement.innerHTML = '';

                // Add default option
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.textContent = `-- اختر ${selectElementId.includes('bank') ? 'الحساب' : 'البطاقة'} --`;
                selectElement.appendChild(defaultOption);

                // Filter and add matching options
                originalOptions.forEach(optionData => {
                    if (optionData.value === '') return; // Skip the original default option

                    if (optionData.text.toLowerCase().includes(searchTerm)) {
                        const newOption = document.createElement('option');
                        newOption.value = optionData.value;
                        newOption.textContent = optionData.text;
                        if (optionData.balance) {
                            newOption.setAttribute('data-balance', optionData.balance);
                        }
                        selectElement.appendChild(newOption);
                    }
                });

                // Re-select the previously selected value if it still exists
                const currentSelectedValue = "<?php
                    if (!empty($basket['payment_source_type']) && !empty($basket['payment_source_id'])) {
                        if ($basket['payment_source_type'] === 'bank_account') {
                            echo $basket['payment_source_id'];
                        } elseif ($basket['payment_source_type'] === 'purchase_card') {
                            echo $basket['payment_source_id'];
                        }
                    }
                ?>";
                if (currentSelectedValue && selectElement.querySelector(`option[value="${currentSelectedValue}"]`)) {
                    selectElement.value = currentSelectedValue;
                } else {
                    selectElement.value = ''; // Reset if selected option is no longer visible
                }
                updateSourceBalance(selectElement); // Update balance after filtering
            });
        }

        // Initialize the search for both dropdowns
        setupSearchableDropdown('bankAccountSearch', 'bankAccountSelect');
        setupSearchableDropdown('purchaseCardSearch', 'purchaseCardSelect');


        console.log('✅ Initialization Complete');
    });


    // ============================================
    // TOTALS CALCULATION ENGINE
    // ============================================

    function updateTotals() {
        const subtotal = parseFloat(document.getElementById('subtotalInput').value) || 0;
        const shippingCost = parseFloat(document.getElementById('shippingCost').value) || 0;
        const taxRate = parseFloat(document.getElementById('taxRate').value) || 0;
        const taxIncluded = document.getElementById('taxIncluded').checked;

        const manualDiscount = parseFloat(document.getElementById('manualDiscountInput').value) || 0;
        const pointsDiscount = parseFloat(document.getElementById('points_discount').value) || 0;
        const clubDiscount = parseFloat(document.getElementById('club_discount').value) || 0;

        const totalDiscount = manualDiscount + pointsDiscount + clubDiscount;
        const baseForTax = subtotal - totalDiscount;
        let taxAmount = 0;
        let grandTotal = 0;

        if (taxIncluded) {
            if ((taxRate + 100) > 0) { // Prevent division by zero
                taxAmount = (baseForTax * taxRate) / (100 + taxRate);
            }
            grandTotal = baseForTax + shippingCost;
        } else {
            taxAmount = baseForTax * (taxRate / 100);
            grandTotal = baseForTax + taxAmount + shippingCost;
        }

        // Ensure calculations are always at least 2 decimal places and handle negative results for display
        taxAmount = Math.max(0, taxAmount); // Tax cannot be negative
        grandTotal = Math.max(0, grandTotal); // Grand total cannot be negative

        // --- Update display elements ---
        document.getElementById('totalDiscountDisplay').textContent = formatMoney(totalDiscount);
        document.getElementById('taxAmountDisplay').textContent = formatMoney(taxAmount);
        document.getElementById('grandTotalDisplay').textContent = formatMoney(grandTotal);

        // --- NEW: Automatically fill the final price input with the grand total if it's currently empty or 0 ---
        const finalPriceOverrideInput = document.getElementById('final_price_override');
        // Only update if the user hasn't explicitly set an override, or if it's currently 0
        if (!finalPriceOverrideInput.value || parseFloat(finalPriceOverrideInput.value) === 0) {
            finalPriceOverrideInput.value = grandTotal.toFixed(2);
        }
    }


    // ============================================
    // UTILITY FUNCTION
    // ============================================

    /**
     * MODIFIED: This function now formats numbers using English numerals and adds " YER" as the currency.
     * It also ensures two decimal places for a consistent financial look.
     */
    function formatMoney(amount) {
        if (amount === null || isNaN(amount)) return '0.00 YER';

        // Options to ensure two decimal places are always shown
        const options = {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        };

        // 'en-US' locale uses standard Western Arabic numerals (0, 1, 2...)
        return new Intl.NumberFormat('en-US', options).format(parseFloat(amount)) + ' YER';
    }
</script>


<?php include '../../includes/footer.php'; ?>