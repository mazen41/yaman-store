<?php
/**
 * Standalone Purchase Basket - Manual Entry System
 * Version: 4.4 (Corrected)
 * - Numbers are now displayed in English format (e.g., 1,234.50 SAR).
 * - Automatic calculation is handled by the updated JS file.
 * - The "Save as Draft" button has been removed.
 * - All financial fields are manually editable.
 * - Server-side logic updated to process manually entered data.
 * - Added frontend JS for dynamic payment source selection.
 * - Added search/filter functionality for bank accounts and purchase cards dropdowns.
 * - FIXED: Corrected the logic for saving discount amounts to the database.
 * - NEW: Added support for multiple file uploads for attachments.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/phone_utils.php';
require_once '../../includes/accounting_functions.php';
$page_title = 'إنشاء سلة شراء يدوية';
$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();

        $action = $_POST['action'];
        if ($action !== 'lock_basket') {
            throw new Exception('Invalid action specified.');
        }

        $basket_name = trim($_POST['basket_name'] ?? '');
        if (empty($basket_name))
            throw new Exception('يرجى إدخال اسم السلة');

        // --- Collect all manually entered form data ---
        $basket_code = trim($_POST['basket_code'] ?? '');
        if (empty($basket_code)) {
            $basket_code = 'BASKET-' . date('Ymd-His');
        }
        $purchase_group_id = !empty($_POST['purchase_group_id']) ? intval($_POST['purchase_group_id']) : null;
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
        $expected_delivery_date = !empty($_POST['expected_delivery_date']) ? $_POST['expected_delivery_date'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
        $tax_rate = floatval($_POST['tax_rate'] ?? 0);
        $tax_included = isset($_POST['tax_included']) ? 1 : 0;
        $account_number = trim($_POST['account_number'] ?? null);
        $total_items = intval($_POST['total_products'] ?? 0);
        $coupon_code = trim($_POST['coupon_code'] ?? null);

        // Financial values from manual inputs
        $subtotal_amount = floatval($_POST['subtotal_amount'] ?? 0);
        
        // ** FIX START: Capture each discount individually **
        $manual_discount_amount = floatval($_POST['manual_discount_amount'] ?? 0);
        $points_discount = floatval($_POST['points_discount'] ?? 0);
        $club_discount = floatval($_POST['club_discount'] ?? 0);
        // This is the total discount amount which will be used for calculations
        $total_discount_for_calculation = $manual_discount_amount + $points_discount + $club_discount; 
        // ** FIX END **

        $final_price_override = !empty($_POST['final_price_override']) ? floatval($_POST['final_price_override']) : null;

        $delivery_codes = trim($_POST['delivery_codes'] ?? null);
        $delivery_codes_status = trim($_POST['delivery_codes_status'] ?? null);
        $payment_source_type = !empty($_POST['payment_source_type']) ? $_POST['payment_source_type'] : null;
        $payment_source_id = null;
        if ($payment_source_type === 'bank_account') {
            $payment_source_id = !empty($_POST['payment_source_id_bank']) ? intval($_POST['payment_source_id_bank']) : null;
        } elseif ($payment_source_type === 'purchase_card') {
            $payment_source_id = !empty($_POST['payment_source_id_purchase']) ? intval($_POST['payment_source_id_purchase']) : null;
        }

        // --- Handle multiple file uploads ---
        $attachment_paths = [];
        if (isset($_FILES['attachment']) && count($_FILES['attachment']['name']) > 0) {
            $upload_dir = '../../uploads/basket_attachments/';
            if (!is_dir($upload_dir))
                mkdir($upload_dir, 0755, true);

            foreach ($_FILES['attachment']['name'] as $key => $name) {
                if ($_FILES['attachment']['error'][$key] == UPLOAD_ERR_OK) {
                    $file_name = time() . '_' . basename($name);
                    $target_file = $upload_dir . $file_name;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'][$key], $target_file)) {
                        $attachment_paths[] = $target_file;
                    } else {
                        throw new Exception('فشل رفع الملف المرفق: ' . $name);
                    }
                }
            }
        }
        $attachment_path_json = !empty($attachment_paths) ? json_encode($attachment_paths) : null;


        // --- Server-side financial calculation ---
        $base_for_tax = $subtotal_amount - $total_discount_for_calculation; // Use the calculated total discount
        $tax_amount = 0;
        $final_total = 0;

        if ($tax_included) {
            $tax_amount = ($tax_rate > 0) ? ($base_for_tax * $tax_rate) / (100 + $tax_rate) : 0;
            $final_total = $base_for_tax + $shipping_cost;
        } else {
            $tax_amount = $base_for_tax * ($tax_rate / 100);
            $final_total = $base_for_tax + $tax_amount + $shipping_cost;
        }

        // Insert basket record - Status is now always 'ordered'
        $status = 'ordered';
        
        // ** FIX START: Corrected SQL statement to match variables to the right columns **
        // Note: `discount_amount` now stores the manual discount, while others go to their specific columns.
        // Also updated to store attachment_path as JSON
        $sql = "INSERT INTO purchase_baskets (
            basket_name, basket_code, status, created_by, purchase_group_id,
            purchase_date, expected_delivery_date, notes, shipping_cost, tax_rate, tax_included,
            account_number, total_items, coupon_code, 
            discount_amount, points_discount, club_discount, final_price_override,
            payment_source_type, payment_source_id,
            delivery_codes, delivery_codes_status, attachment_path,
            subtotal_amount, tax_amount, final_amount
        ) VALUES ( ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $basket_name,
            $basket_code,
            $status,
            $_SESSION['user_id'],
            $purchase_group_id,
            $purchase_date,
            $expected_delivery_date,
            $notes,
            $shipping_cost,
            $tax_rate,
            $tax_included,
            $account_number,
            $total_items,
            $coupon_code,
            $manual_discount_amount, // <-- Corresponds to `discount_amount`
            $points_discount,        // <-- Corresponds to `points_discount`
            $club_discount,          // <-- Corresponds to `club_discount`
            $final_price_override,
            $payment_source_type,
            $payment_source_id,
            $delivery_codes,
            $delivery_codes_status,
            $attachment_path_json, // Storing JSON array of paths
            $subtotal_amount,
            $tax_amount,
            $final_total
        ]);
        // ** FIX END **
        $basket_id = $db->lastInsertId();

        // ===================================================================
        // START: ACCOUNTING LOGIC (RUNS FOR LOCKED BASKETS)
        // ===================================================================
        try {
            // Get the account IDs from settings
            $purchases_account_id    = get_accounting_setting($db, 'default_purchases_account_id');
            $payable_account_id      = get_accounting_setting($db, 'default_accounts_payable_id');
            $shipping_expense_id     = get_accounting_setting($db, 'default_purchase_shipping_expense_id');
            $purchase_card_asset_id  = get_accounting_setting($db, 'default_purchase_card_asset_id');

            // The total value we are liable for or have paid
            $total_liability = $subtotal_amount - $total_discount_for_calculation + $shipping_cost; // Use the correct total discount

            // Determine the Credit account (how we are paying)
            $credit_account_id = $payable_account_id; // Default to Accounts Payable

            if ($payment_source_type === 'bank_account' && $payment_source_id) {
                $bank_stmt = $db->prepare("SELECT account_id FROM bank_accounts WHERE id = ?");
                $bank_stmt->execute([$payment_source_id]);
                $credit_account_id = $bank_stmt->fetchColumn();
            } elseif ($payment_source_type === 'purchase_card') {
                $credit_account_id = $purchase_card_asset_id;
            }

            $description = "إثبات مشتريات للسلة رقم " . ($basket_code ?: "#$basket_id");
            
            $entry_items = [
                ['account_id' => $purchases_account_id, 'type' => 'debit', 'amount' => $subtotal_amount],
                ['account_id' => $shipping_expense_id, 'type' => 'debit', 'amount' => $shipping_cost],
                ['account_id' => $credit_account_id, 'type' => 'credit', 'amount' => $total_liability],
            ];

            create_journal_entry(
                $db,
                $purchase_date,
                $description,
                $entry_items,
                'baskets',
                $basket_id,
                $_SESSION['user_id']
            );

        } catch (Exception $acc_e) {
            error_log("Accounting entry failed for Basket ID $basket_id: " . $acc_e->getMessage());
        }
        // ===================================================================
        // END: ACCOUNTING LOGIC
        // ===================================================================

        // --- Handle payment deduction ---
        if ($payment_source_type && $payment_source_id) {
            $amount_to_deduct = ($final_price_override !== null && $final_price_override > 0) ? $final_price_override : $final_total;
            if ($amount_to_deduct > 0) {
                if ($payment_source_type == 'purchase_card') {
                    $card_stmt = $db->prepare("SELECT balance, purchase_amount FROM purchase_cards WHERE id = ? FOR UPDATE");
                    $card_stmt->execute([$payment_source_id]);
                    $card = $card_stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$card)
                        throw new Exception('بطاقة الشراء المحددة غير صالحة.');
                    if ($card['balance'] < $amount_to_deduct)
                        throw new Exception('الرصيد في بطاقة الشراء غير كافٍ. الرصيد الحالي: ' . $card['balance']);

                    $balance_before = $card['balance'];
                    $new_balance = $balance_before - $amount_to_deduct;
                    $new_purchase_amount = ($card['purchase_amount'] ?? 0) + $amount_to_deduct;

                    $update_card = $db->prepare("UPDATE purchase_cards SET balance = ?, purchase_amount = ? WHERE id = ?");
                    $update_card->execute([$new_balance, $new_purchase_amount, $payment_source_id]);

                    $txn_stmt = $db->prepare("
                        INSERT INTO purchase_card_transactions 
                        (purchase_card_id, transaction_type, amount, balance_before, balance_after, 
                         reference_type, reference_id, description, created_by)
                        VALUES (?, 'deduct', ?, ?, ?, 'basket', ?, ?, ?)
                    ");
                    $description = 'شراء سلة: ' . ($basket_name ?: $basket_code);
                    $txn_stmt->execute([
                        $payment_source_id,
                        $amount_to_deduct,
                        $balance_before,
                        $new_balance,
                        $basket_id,
                        $description,
                        $_SESSION['user_id']
                    ]);
                } elseif ($payment_source_type == 'bank_account') {
                    $bank_stmt = $db->prepare("SELECT current_balance FROM bank_accounts WHERE id = ? FOR UPDATE");
                    $bank_stmt->execute([$payment_source_id]);
                    $bank_account = $bank_stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$bank_account)
                        throw new Exception('الحساب البنكي المحدد غير صالح.');
                    if ($bank_account['current_balance'] < $amount_to_deduct)
                        throw new Exception('الرصيد في الحساب البنكي غير كافٍ. الرصيد الحالي: ' . $bank_account['current_balance']);
                    $new_balance = $bank_account['current_balance'] - $amount_to_deduct;
                    $update_bank = $db->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
                    $update_bank->execute([$new_balance, $payment_source_id]);
                }
            }
        }

        $db->commit();
        $_SESSION['success_message'] = "تم إنشاء السلة اليدوية بنجاح: $basket_code";
        header("Location: view_basket.php?id=$basket_id");
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction())
            $db->rollBack();
        $error_message = $e->getMessage();
    }
}

// Get data for dropdowns
try {
    $purchase_cards = $db->query("SELECT id, card_number, card_name, balance FROM purchase_cards ORDER BY card_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $purchase_cards = [];
}
try {
    $bank_accounts = $db->query("SELECT id, bank_name, account_number, current_balance FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bank_accounts = [];
}
try {
    $purchase_groups = $db->query("SELECT id, group_name, group_number FROM purchase_groups WHERE status = 'active' ORDER BY group_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $purchase_groups = [];
}

include '../../includes/header.php';
?>

<!-- Your HTML and CSS remain unchanged... -->
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
    /* Styling for the image preview grid */
.image-preview-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 15px;
}

.preview-item {
    position: relative;
    width: 100px;
    height: 100px;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
    background-color: #f3f4f6;
}

.preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover; /* This makes sure the image fills the square */
}

.preview-item .remove-btn {
    position: absolute;
    top: 2px;
    right: 2px;
    background: rgba(239, 68, 68, 0.8);
    color: white;
    border: none;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
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
        <span>إنشاء سلة يدوية</span>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <?php if ($error_message): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="basketForm" enctype="multipart/form-data">
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
                                        placeholder="مثال: سلة شراء يناير 2025" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="basket_code"><i class="fas fa-barcode"></i> كود السلة
                                        (اختياري)</label>
                                    <input type="text" id="basket_code" name="basket_code" class="form-control"
                                        placeholder="سيتم إنشاؤه تلقائياً إذا تُرك فارغاً">
                                    <small
                                        style="color: #6b7280; font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                                        <i class="fas fa-info-circle"></i> اتركه فارغاً للإنشاء التلقائي (مثال:
                                        BASKET-20251115-123948)
                                    </small>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="account_number"><i class="fas fa-hashtag"></i> رقم الحساب</label>
                                    <input type="text" id="account_number" name="account_number" class="form-control" placeholder="أدخل رقم الحساب العددي هنا">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="purchase_date"><i class="fas fa-calendar"></i> تاريخ
                                        الشراء</label>
                                    <input type="date" id="purchase_date" name="purchase_date" class="form-control"
                                        value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="expected_delivery_date"><i
                                            class="fas fa-calendar-check"></i> تاريخ التسليم المتوقع</label>
                                    <input type="date" id="expected_delivery_date" name="expected_delivery_date"
                                        class="form-control">
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label" for="notes"><i class="fas fa-sticky-note"></i> ملاحظات /
                                        تفاصيل إضافية</label>
                                    <textarea name="notes" id="notes" class="form-control" rows="2"
                                        placeholder="ملاحظات اختيارية"></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="attachment"><i class="fas fa-paperclip"></i> رفع مرفق
                                        (اختياري)</label>
<input type="file" name="attachment[]" id="attachment" class="form-control" multiple accept="image/*">
<!-- Add this container below -->
<div id="imagePreviewContainer" class="image-preview-grid"></div>

<small style="color: #6b7280; font-size: 0.75rem; margin-top: 0.25rem; display: block;">
    <i class="fas fa-info-circle"></i> يمكنك تحديد عدة ملفات.
</small>                                    <small
                                        style="color: #6b7280; font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                                        <i class="fas fa-info-circle"></i> يمكنك تحديد عدة ملفات.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Source Card -->
                        <div class="card">
                            <div class="card-title"><i class="fas fa-wallet"></i> مصدر الدفع (اختياري)</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                                <div class="form-group">
                                    <label for="paymentSourceType" class="form-label">اختر نوع المصدر</label>
                                    <select name="payment_source_type" id="paymentSourceType" class="form-select">
                                        <option value="" selected>-- بدون تحديد --</option>
                                        <option value="bank_account">حساب بنكي</option>
                                        <option value="purchase_card">بطاقة شراء</option>
                                    </select>
                                </div>
                                <div id="paymentSourceDetails" style="display: none;">
                                    <div class="form-group" id="bankAccountSelector" style="display: none;">
                                        <label for="bankAccountSearch" class="form-label">ابحث عن الحساب البنكي</label>
                                        <input type="text" id="bankAccountSearch" class="form-control"
                                            placeholder="اكتب اسم البنك أو رقم الحساب..." style="margin-bottom: 10px;">
                                        
                                        <label for="bankAccountSelect" class="form-label">اختر الحساب البنكي</label>
                                        <select name="payment_source_id_bank" id="bankAccountSelect"
                                            class="form-select">
                                            <option value="">-- اختر الحساب --</option>
                                            <?php foreach ($bank_accounts as $account): ?>
                                                <option value="<?php echo $account['id']; ?>"
                                                    data-balance="<?php echo $account['current_balance']; ?>">
                                                    <?php echo htmlspecialchars($account['bank_name'] . ' (' . $account['account_number'] . ') - Balance: ' . number_format($account['current_balance'], 2) . ' YER'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group" id="purchaseCardSelector" style="display: none;">
                                        <label for="purchaseCardSearch" class="form-label">ابحث عن بطاقة الشراء</label>
                                        <input type="text" id="purchaseCardSearch" class="form-control"
                                            placeholder="اكتب اسم البطاقة أو رقمها..." style="margin-bottom: 10px;">
                                        
                                        <label for="purchaseCardSelect" class="form-label">اختر بطاقة الشراء</label>
                                        <select name="payment_source_id_purchase" id="purchaseCardSelect"
                                            class="form-select">
                                            <option value="">-- اختر البطاقة --</option>
                                            <?php foreach ($purchase_cards as $card): ?>
                                                <option value="<?php echo $card['id']; ?>"
                                                    data-balance="<?php echo $card['balance']; ?>">
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
                    </div>

                    <!-- Financial Summary Column -->
                    <div class="lg:col-span-1" style="position: sticky; top: 20px;">
                        <div class="card">
                            <div class="card-title"><i class="fas fa-calculator" style="color:#C7A46D"></i> الملخص
                                المالي (إدخال يدوي)</div>
                            <div class="totals-box" style="padding: 0; background: none; border: none; margin: 0;">
                                <div class="total-row"><span><i class="fas fa-box-open"></i>إجمالي عدد
                                        المنتجات</span><input type="number" name="total_products"
                                        id="totalProductsInput" class="form-control totals-input" value="0"></div>
                                <div class="total-row"><span><i class="fas fa-riyal-sign"></i>المبلغ بالريال السعودي</span><input type="number" name="sar_amount" id="sarInput"
                                        step="0.01" min="0" value="0" class="form-control totals-input" placeholder="SAR"></div>
                                <div class="total-row"><span><i class="fas fa-file-invoice-dollar"></i>المجموع قبل
                                        الخصم (ر.ي)</span><input type="number" name="subtotal_amount" id="subtotalInput"
                                        step="0.01" min="0" value="0" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-shipping-fast"></i>تكلفة
                                        الشحن</span><input type="number" name="shipping_cost" id="shippingCost"
                                        step="0.01" min="0" value="0" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-percent"></i>الضريبة</span>
                                    <div style="display:flex; align-items:center; gap:10px;"><input type="number"
                                            name="tax_rate" id="taxRate" step="0.01" min="0" max="100" value="0"
                                            class="form-control totals-input" style="width:80px;"><span>%</span><label
                                            style="display:flex; align-items:center; gap:5px;"><input type="checkbox"
                                                name="tax_included" id="taxIncluded"><span>شامل</span></label></div>
                                </div>
                                <div class="total-row"><span><i class="fas fa-receipt"></i>مبلغ الضريبة</span><span
                                        id="taxAmountDisplay">0.00 YER</span></div>
                                <hr style="border-color: var(--border-color); margin: 1rem 0;">

                                <!-- DISCOUNT SECTION -->
                                <h4 style="font-weight: 600; margin-top: 1.5rem; margin-bottom: 1rem;">الخصومات</h4>
                                <div class="total-row"><span><i class="fas fa-tag"></i>خصم يدوي</span><input
                                        type="number" name="manual_discount_amount" id="manualDiscountInput" step="0.01"
                                        min="0" value="0" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-star"></i>خصم نقاط</span><input
                                        type="number" name="points_discount" id="points_discount" step="0.01" min="0"
                                        value="0" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-users"></i>خصم نادي</span><input
                                        type="number" name="club_discount" id="club_discount" step="0.01" min="0"
                                        value="0" class="form-control totals-input"></div>
                                <div class="total-row"><span><i class="fas fa-ticket-alt"></i>كود الخصم</span><input
                                        type="text" name="coupon_code" class="form-control totals-input"></div>
                                <div class="total-row" style="background:#fffbe6; border-radius: 8px; padding: 1rem;">
                                    <span><i class="fas fa-tags"></i>إجمالي الخصومات</span><span
                                        id="totalDiscountDisplay">0.00 YER</span>
                                </div>

                                <hr style="border-color: var(--border-color); margin: 1rem 0;">
                                <div class="total-row" style="padding-top:1.5rem;">
                                    <span><i class="fas fa-money-bill-wave"></i> الصافي النهائي</span>
                                    <span id="grandTotalDisplay">0.00 YER</span>
                                </div>

                                <div class="total-row"
                                    style="background: #eef2ff; border-radius: 8px; padding: 1rem; margin-top: 1rem;">
                                    <span><i class="fas fa-check-double"></i> السعر النهائي للدفع</span>
                                    <input type="number" name="final_price_override" id="final_price_override"
                                        step="0.01" min="0" class="form-control totals-input"
                                        placeholder="السعر الذي سيخصم">
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="card" style="position: sticky; bottom: 0; z-index: 10;">
                    <div class="flex justify-between items-center gap-4"
                        style="display:flex; justify-content:space-between; align-items:center;">
                        <a href="show_baskets.php" class="btn btn-secondary"><i class="fas fa-times"></i> إلغاء</a>
                        <div>
                            <!-- "Save Draft" button has been removed -->
                            <button type="submit" name="action" value="lock_basket" class="btn btn-success"
                                id="lockBasketBtn"><i class="fas fa-lock"></i> إقفال وطلب</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="basket_manual.js?v=4.5"></script>

<?php include '../../includes/footer.php'; ?>