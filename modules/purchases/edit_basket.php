<?php
/**
 * Edit Basket - Version 4.4 (Based on basket_complete.php)
 * تعديل السلة
 * - Uses exactly the same inputs, fields, behavior, validations, calculations, and UI structure as create form.
 * - Added payment source adjustment logic for edits.
 * - Numbers are displayed in English format (e.g., 1,234.50 SAR).
 * - All financial fields are manually editable.
 * - Added support for multiple file uploads for attachments.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/phone_utils.php';
require_once '../../includes/accounting_functions.php';

$basket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($basket_id === 0) {
    header('Location: index.php');
    exit();
}

$page_title = 'تعديل السلة';
$error_message = '';
$success_message = '';

// ===================================================================
// HELPER FUNCTIONS FOR PAYMENT ADJUSTMENT
// ===================================================================

/**
 * Reverses a payment or refunds an amount to a given source.
 */
function refundToSource(PDO $db, $source_type, $source_id, $amount, $basket_id, $user_id)
{
    if (empty($source_type) || empty($source_id) || $amount <= 0) {
        return;
    }

    try {
        if ($source_type == 'purchase_card') {
            $stmt = $db->prepare("SELECT balance, purchase_amount FROM purchase_cards WHERE id = ? FOR UPDATE"); 
            $stmt->execute([$source_id]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$card) throw new Exception('بطاقة الشراء الأصلية غير موجودة.');

            $balance_before = $card['balance'];
            $new_balance = $balance_before + $amount;
            $new_purchase_amount = ($card['purchase_amount'] ?? 0) - $amount;

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
            $stmt = $db->prepare("SELECT current_balance FROM bank_accounts WHERE id = ? FOR UPDATE"); 
            $stmt->execute([$source_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$account) throw new Exception('الحساب البنكي الأصلي غير موجود.');

            $new_balance = $account['current_balance'] + $amount;
            $update_stmt = $db->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
            $update_stmt->execute([$new_balance, $source_id]);
        }
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Deducts a payment from a given source.
 */
function deductFromSource(PDO $db, $source_type, $source_id, $amount, $basket_id, $user_id, $basket_name)
{
    if (empty($source_type) || empty($source_id) || $amount <= 0) {
        return;
    }

    try {
        if ($source_type == 'purchase_card') {
            $stmt = $db->prepare("SELECT balance, purchase_amount FROM purchase_cards WHERE id = ? FOR UPDATE"); 
            $stmt->execute([$source_id]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$card) throw new Exception('بطاقة الشراء المحددة غير صالحة.');
            if ($card['balance'] < $amount) throw new Exception('الرصيد في بطاقة الشراء غير كافٍ. الرصيد الحالي: ' . number_format($card['balance'], 2) . ' YER');

            $balance_before = $card['balance'];
            $new_balance = $balance_before - $amount;
            $new_purchase_amount = ($card['purchase_amount'] ?? 0) + $amount;

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
        throw $e;
    }
}

// Ensure YER/SAR editable columns exist BEFORE we read the basket,
// so the edit form always has these columns available on first load
// (previously this only ran inside the POST handler, which caused all
// SAR/YER fields to show 0/empty the first time an older basket was opened).
try {
    $yerColumnsToEnsureOnLoad = [
        'sar_amount' => "DECIMAL(15,2) NULL",
        'yer_exchange_rate' => "DECIMAL(10,4) NULL",
        'subtotal_amount_yer' => "DECIMAL(15,2) NULL",
        'shipping_cost_yer' => "DECIMAL(15,2) NULL",
        'tax_amount_yer' => "DECIMAL(15,2) NULL",
        'manual_discount_yer' => "DECIMAL(15,2) NULL",
        'points_discount_yer' => "DECIMAL(15,2) NULL",
        'club_discount_yer' => "DECIMAL(15,2) NULL",
        'total_discount_yer' => "DECIMAL(15,2) NULL",
        'grand_total_yer' => "DECIMAL(15,2) NULL"
    ];
    foreach ($yerColumnsToEnsureOnLoad as $columnName => $columnType) {
        $checkColStmt = $db->prepare("SHOW COLUMNS FROM purchase_baskets LIKE ?");
        $checkColStmt->execute([$columnName]);
        if (!$checkColStmt->fetch()) {
            $db->exec("ALTER TABLE purchase_baskets ADD COLUMN `{$columnName}` {$columnType}");
        }
    }
} catch (Exception $e) {
    // Non-fatal: form will still fall back to base columns below.
}

// Load existing basket data
$basket = null;
try {
    $stmt = $db->prepare("SELECT * FROM purchase_baskets WHERE id = ?");
    $stmt->execute([$basket_id]);
    $basket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$basket) {
        header('Location: index.php');
        exit();
    }

    // Backfill YER fields from the legacy non-YER columns when they were
    // never populated (e.g. basket created before the YER columns existed,
    // or created without going through the dual-currency form).
    $basket_rate = (!empty($basket['yer_exchange_rate']) && $basket['yer_exchange_rate'] > 0) ? (float)$basket['yer_exchange_rate'] : 140.0;

    if (empty($basket['subtotal_amount_yer'])) {
        $basket['subtotal_amount_yer'] = $basket['subtotal_amount'] ?? 0;
    }
    if (empty($basket['shipping_cost_yer'])) {
        $basket['shipping_cost_yer'] = $basket['shipping_cost'] ?? 0;
    }
    if (empty($basket['manual_discount_yer'])) {
        $basket['manual_discount_yer'] = $basket['discount_amount'] ?? 0;
    }
    if (empty($basket['points_discount_yer'])) {
        $basket['points_discount_yer'] = $basket['points_discount'] ?? 0;
    }
    if (empty($basket['club_discount_yer'])) {
        $basket['club_discount_yer'] = $basket['club_discount'] ?? 0;
    }
    if (empty($basket['total_discount_yer'])) {
        $basket['total_discount_yer'] = (float)$basket['manual_discount_yer'] + (float)$basket['points_discount_yer'] + (float)$basket['club_discount_yer'];
    }
    if (empty($basket['grand_total_yer'])) {
        $basket['grand_total_yer'] = $basket['final_amount'] ?? 0;
    }
    // sar_amount has no legacy equivalent column, so derive it from the YER subtotal + rate
    if (empty($basket['sar_amount']) && !empty($basket['subtotal_amount_yer'])) {
        $basket['sar_amount'] = round(((float)$basket['subtotal_amount_yer']) / $basket_rate, 2);
    }
} catch (Exception $e) {
    $error_message = "فشل تحميل بيانات السلة: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        $db->beginTransaction();

        $action = $_POST['action'];
        if ($action !== 'update_basket') {
            throw new Exception('Invalid action specified.');
        }

        $basket_name = trim($_POST['basket_name'] ?? '');
        if (empty($basket_name))
            throw new Exception('يرجى إدخال اسم السلة');

        // --- Collect all manually entered form data ---
        $basket_code = trim($_POST['basket_code'] ?? '');
        if (empty($basket_code)) {
            $basket_code = $basket['basket_code']; // Keep existing code if empty
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

        $sanitize_decimal = function ($value, $default = 0.0) {
            if ($value === null || $value === '') return (float)$default;
            $value = str_replace([',', ' '], '', (string)$value);
            return is_numeric($value) ? (float)$value : (float)$default;
        };

        // Financial values from manual inputs
        $subtotal_amount = $sanitize_decimal($_POST['subtotal_amount'] ?? 0);
        
        // ** FIX START: Capture each discount individually **
        $manual_discount_amount = $sanitize_decimal($_POST['manual_discount_amount'] ?? 0);
        $points_discount = $sanitize_decimal($_POST['points_discount'] ?? 0);
        $club_discount = $sanitize_decimal($_POST['club_discount'] ?? 0);
        // This is the total discount amount which will be used for calculations
        $total_discount_for_calculation = $manual_discount_amount + $points_discount + $club_discount; 
        // ** FIX END **

        $final_price_override = isset($_POST['final_price_override']) && $_POST['final_price_override'] !== '' ? $sanitize_decimal($_POST['final_price_override']) : null;

        $delivery_codes = trim($_POST['delivery_codes'] ?? null);
        $delivery_codes_status = trim($_POST['delivery_codes_status'] ?? null);
        $payment_source_type = !empty($_POST['payment_source_type']) ? $_POST['payment_source_type'] : null;
        $payment_source_id = null;
        if ($payment_source_type === 'bank_account') {
            $payment_source_id = !empty($_POST['payment_source_id_bank']) ? intval($_POST['payment_source_id_bank']) : null;
        } elseif ($payment_source_type === 'purchase_card') {
            $payment_source_id = !empty($_POST['payment_source_id_purchase']) ? intval($_POST['payment_source_id_purchase']) : null;
        }

        $yer_exchange_rate = $sanitize_decimal($_POST['yer_exchange_rate'] ?? 140, 140);
        if ($yer_exchange_rate <= 0) {
            $yer_exchange_rate = 140;
        }
        $sar_amount = $sanitize_decimal($_POST['sar_amount'] ?? 0);
        $subtotal_amount_yer = $sanitize_decimal($_POST['subtotal_amount_yer'] ?? $subtotal_amount);
        $shipping_cost_yer = $sanitize_decimal($_POST['shipping_cost_yer'] ?? $shipping_cost);
        $tax_amount_yer = $sanitize_decimal($_POST['tax_amount_yer'] ?? 0);
        $manual_discount_yer = $sanitize_decimal($_POST['manual_discount_yer'] ?? $manual_discount_amount);
        $points_discount_yer = $sanitize_decimal($_POST['points_discount_yer'] ?? $points_discount);
        $club_discount_yer = $sanitize_decimal($_POST['club_discount_yer'] ?? $club_discount);
        $total_discount_yer = $sanitize_decimal($_POST['total_discount_yer'] ?? $total_discount_for_calculation);
        $grand_total_yer = $sanitize_decimal($_POST['grand_total_yer'] ?? 0);

        // Keep YER fields in sync when SAR is entered manually (while preserving manual edit capability).
        if ($sar_amount > 0) {
            $subtotal_from_sar = $sar_amount * $yer_exchange_rate;
            if ($subtotal_amount <= 0) {
                $subtotal_amount = $subtotal_from_sar;
            }
            if ($subtotal_amount_yer <= 0) {
                $subtotal_amount_yer = $subtotal_from_sar;
            }
            if ($manual_discount_yer <= 0) {
                $manual_discount_yer = $manual_discount_amount;
            }
            if ($points_discount_yer <= 0) {
                $points_discount_yer = $points_discount;
            }
            if ($club_discount_yer <= 0) {
                $club_discount_yer = $club_discount;
            }
            if ($total_discount_yer <= 0) {
                $total_discount_yer = $manual_discount_yer + $points_discount_yer + $club_discount_yer;
            }

            $base_for_tax_yer = $subtotal_amount_yer - $total_discount_yer;
            if ($tax_included) {
                $tax_amount_yer = ($tax_rate > 0) ? ($base_for_tax_yer * $tax_rate) / (100 + $tax_rate) : 0;
                $calculated_grand_total_yer = $base_for_tax_yer + $shipping_cost_yer;
            } else {
                $tax_amount_yer = $base_for_tax_yer * ($tax_rate / 100);
                $calculated_grand_total_yer = $base_for_tax_yer + $tax_amount_yer + $shipping_cost_yer;
            }
            if ($grand_total_yer <= 0) {
                $grand_total_yer = $calculated_grand_total_yer;
            }
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

        // Ensure YER editable columns exist (backward compatible safe extension)
        $yerColumnsToEnsure = [
            'sar_amount' => "DECIMAL(15,2) NULL",
            'yer_exchange_rate' => "DECIMAL(10,4) NULL",
            'subtotal_amount_yer' => "DECIMAL(15,2) NULL",
            'shipping_cost_yer' => "DECIMAL(15,2) NULL",
            'tax_amount_yer' => "DECIMAL(15,2) NULL",
            'manual_discount_yer' => "DECIMAL(15,2) NULL",
            'points_discount_yer' => "DECIMAL(15,2) NULL",
            'club_discount_yer' => "DECIMAL(15,2) NULL",
            'total_discount_yer' => "DECIMAL(15,2) NULL",
            'grand_total_yer' => "DECIMAL(15,2) NULL"
        ];
        foreach ($yerColumnsToEnsure as $columnName => $columnType) {
            $checkColStmt = $db->prepare("SHOW COLUMNS FROM purchase_baskets LIKE ?");
            $checkColStmt->execute([$columnName]);
            if (!$checkColStmt->fetch()) {
                $db->exec("ALTER TABLE purchase_baskets ADD COLUMN `{$columnName}` {$columnType}");
            }
        }

        // Update basket record
        // Merge new attachment paths with existing ones
        $existing_attachments = !empty($basket['attachment_path']) ? json_decode($basket['attachment_path'], true) : [];
        if (!empty($attachment_paths)) {
            $attachment_path_json = json_encode(array_merge($existing_attachments, $attachment_paths));
        } else {
            $attachment_path_json = !empty($existing_attachments) ? json_encode($existing_attachments) : null;
        }

        // ** FIX START: Corrected SQL statement to match variables to the right columns **
        $sql = "UPDATE purchase_baskets SET
            basket_name = ?,
            basket_code = ?,
            purchase_group_id = ?,
            purchase_date = ?,
            expected_delivery_date = ?,
            notes = ?,
            shipping_cost = ?,
            tax_rate = ?,
            tax_included = ?,
            account_number = ?,
            total_items = ?,
            coupon_code = ?,
            discount_amount = ?,
            points_discount = ?,
            club_discount = ?,
            final_price_override = ?,
            payment_source_type = ?,
            payment_source_id = ?,
            delivery_codes = ?,
            delivery_codes_status = ?,
            attachment_path = ?,
            subtotal_amount = ?,
            tax_amount = ?,
            final_amount = ?,
            sar_amount = ?,
            yer_exchange_rate = ?,
            subtotal_amount_yer = ?,
            shipping_cost_yer = ?,
            tax_amount_yer = ?,
            manual_discount_yer = ?,
            points_discount_yer = ?,
            club_discount_yer = ?,
            total_discount_yer = ?,
            grand_total_yer = ?
        WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $basket_name,
            $basket_code,
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
            $final_total,
            $sar_amount,
            $yer_exchange_rate,
            $subtotal_amount_yer,
            $shipping_cost_yer,
            $tax_amount_yer,
            $manual_discount_yer,
            $points_discount_yer,
            $club_discount_yer,
            $total_discount_yer,
            $grand_total_yer,
            $basket_id
        ]);
        // ** FIX END **

        // ===================================================================
        // PAYMENT ADJUSTMENT LOGIC
        // ===================================================================
        
        // Get original basket state for payment adjustment
        $stmt = $db->prepare("SELECT * FROM purchase_baskets WHERE id = ? FOR UPDATE");
        $stmt->execute([$basket_id]);
        $original_basket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$original_basket) {
            throw new Exception("Basket not found or has been deleted.");
        }

        // Determine the amount that was originally deducted
        $original_amount_deducted = (float)($original_basket['final_price_override'] !== null && $original_basket['final_price_override'] > 0
            ? $original_basket['final_price_override']
            : $original_basket['final_amount']);

        // Determine the new amount to be deducted
        $new_amount_to_deduct = (float)($final_price_override !== null && $final_price_override >= 0
            ? $final_price_override
            : $final_total);

        // Get new payment source
        $new_payment_type = $payment_source_type;
        $new_payment_id = $payment_source_id;

        // Perform payment adjustment
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
        // ===================================================================
        // END: PAYMENT ADJUSTMENT LOGIC
        // ===================================================================

        $db->commit();
        $_SESSION['success_message'] = "تم تعديل السلة بنجاح: $basket_code";
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

<!-- START: Redesigned Styles -->
<style>
    :root {
        --primary:        #2563eb;
        --primary-dark:   #1d4ed8;
        --primary-light:  #eff6ff;
        --primary-border: #bfdbfe;
        --gold:           #C7A46D;
        --gold-dark:      #b8956a;
        --gold-light:     #fef9f0;
        --gold-border:    #f0d9b5;
        --danger:         #dc2626;
        --danger-light:   #fef2f2;
        --danger-border:  #fecaca;
        --success:        #16a34a;
        --success-light:  #f0fdf4;
        --success-border: #bbf7d0;
        --gray-50:  #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-300: #d1d5db;
        --gray-500: #6b7280;
        --gray-700: #374151;
        --gray-800: #1f2937;
        --white: #ffffff;
        --radius-sm: 6px;
        --radius:    10px;
        --radius-lg: 14px;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.07), 0 1px 2px rgba(0,0,0,0.04);
        --shadow:    0 4px 12px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
    }

    body { font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif; background: var(--gray-50); color: var(--gray-800); }

    /* ── Cards ── */
    .card {
        background: var(--white);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow);
        padding: 1.75rem;
        margin-bottom: 1.5rem;
        border: 1px solid var(--gray-200);
    }
    .card-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--gray-800);
        margin-bottom: 1.5rem;
        padding-bottom: 0.875rem;
        border-bottom: 2px solid var(--gray-100);
        display: flex;
        align-items: center;
        gap: 0.625rem;
    }
    .card-title i { color: var(--primary); font-size: 1rem; }

    /* ── Form Controls ── */
    .form-group { margin-bottom: 1.25rem; }
    .form-label {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--gray-700);
        margin-bottom: 0.45rem;
    }
    .form-label .required { color: var(--danger); font-size: 1rem; }
    .form-label i { color: var(--primary); font-size: 0.8rem; }

    .form-control,
    .form-select {
        width: 100%;
        padding: 0.65rem 0.9rem;
        border: 1.5px solid var(--gray-300);
        border-radius: var(--radius);
        font-size: 0.95rem;
        font-family: 'Cairo', sans-serif;
        color: var(--gray-800);
        background: var(--white);
        transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
    }
    .form-control:hover, .form-select:hover  { border-color: #93c5fd; }
    .form-control:focus, .form-select:focus  {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        background: #fafcff;
    }
    textarea.form-control { resize: vertical; min-height: 70px; }

    /* ── Alerts ── */
    .alert {
        padding: 0.875rem 1rem;
        border-radius: var(--radius);
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 600;
        font-size: 0.9rem;
    }
    .alert-danger { background: var(--danger-light); color: #991b1b; border: 1px solid var(--danger-border); }

    /* ── Buttons ── */
    .btn {
        padding: 0.65rem 1.4rem;
        border-radius: var(--radius);
        font-weight: 700;
        font-size: 0.95rem;
        font-family: 'Cairo', sans-serif;
        cursor: pointer;
        transition: all 0.18s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        letter-spacing: 0.01em;
    }
    .btn-primary   { background: var(--primary);   color: #fff; box-shadow: 0 2px 6px rgba(37,99,235,0.25); }
    .btn-primary:hover   { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 10px rgba(37,99,235,0.3); }
    .btn-success   { background: var(--gold);       color: #fff; box-shadow: 0 2px 6px rgba(199,164,109,0.3); }
    .btn-success:hover   { background: var(--gold-dark); transform: translateY(-1px); }
    .btn-secondary { background: var(--gray-500);   color: #fff; }
    .btn-secondary:hover { background: #4b5563; transform: translateY(-1px); }

    /* ── Grid helpers ── */
    .grid       { display: grid; }
    .grid-cols-1 { grid-template-columns: repeat(1, minmax(0,1fr)); }
    .gap-6       { gap: 1.5rem; }
    .items-start { align-items: flex-start; }
    @media (min-width:768px)  { .md\:grid-cols-2 { grid-template-columns:repeat(2,minmax(0,1fr)); } .md\:col-span-2 { grid-column:span 2/span 2; } }
    @media (min-width:1024px) { .lg\:grid-cols-3 { grid-template-columns:repeat(3,minmax(0,1fr)); } .lg\:col-span-2 { grid-column:span 2/span 2; } .lg\:col-span-1 { grid-column:span 1/span 1; } }

    /* ── Image Previews ── */
    .image-preview-grid { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
    .preview-item {
        position:relative; width:90px; height:90px;
        border:2px solid var(--gray-200); border-radius:var(--radius);
        overflow:hidden; background:var(--gray-100);
    }
    .preview-item img { width:100%; height:100%; object-fit:cover; }
    .preview-item .remove-btn {
        position:absolute; top:3px; right:3px;
        background:rgba(220,38,38,0.85); color:#fff; border:none;
        border-radius:50%; width:20px; height:20px; font-size:11px;
        cursor:pointer; display:flex; align-items:center; justify-content:center;
    }

    /* ════════════════════════════════════════
       FINANCIAL SUMMARY CARD  —  clean & cohesive
    ════════════════════════════════════════ */

    .fin-card {
        background: var(--white);
        border: 1px solid var(--gray-200);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }

    /* Header band — project blue */
    .fin-header {
        background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
        padding: 1rem 1.25rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .fin-header-title {
        color: #fff;
        font-weight: 700;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .fin-rate-pill {
        display: flex;
        align-items: center;
        gap: 5px;
        background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.25);
        padding: 4px 10px;
        border-radius: 20px;
    }
    .fin-rate-pill label { color: #bfdbfe; font-size: 11px; font-weight: 600; white-space: nowrap; margin: 0; }
    .fin-rate-pill input[type=number] {
        width: 52px;
        background: transparent;
        border: none;
        border-bottom: 1px solid rgba(255,255,255,0.5);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        text-align: center;
        outline: none;
        padding: 0 2px;
        font-family: 'Cairo', sans-serif;
    }
    .fin-rate-pill span { color: #bfdbfe; font-size: 11px; font-weight: 600; }

    /* Col headers */
    .fin-col-heads {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        background: var(--gray-50);
        border-bottom: 2px solid var(--gray-200);
        padding: 7px 14px;
        gap: 6px;
    }
    .fin-col-head { font-size: 11px; font-weight: 700; color: var(--gray-500); text-align: center; }
    .fin-col-head:first-child { text-align: right; }
    .fin-col-head.sar-head {
        background: #dcfce7; color: #166534;
        border-radius: 6px; padding: 3px 6px;
        display: flex; align-items: center; justify-content: center; gap: 4px;
    }
    .fin-col-head.yer-head {
        background: #fef9c3; color: #854d0e;
        border-radius: 6px; padding: 3px 6px;
        display: flex; align-items: center; justify-content: center; gap: 4px;
    }

    /* Body wrapper */
    .fin-body { padding: 0 14px 14px; }

    /* Section divider labels */
    .fin-section-title {
        font-size: 11px;
        font-weight: 700;
        color: var(--primary);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding: 10px 0 4px;
        border-bottom: 1px dashed var(--primary-border);
        margin-bottom: 2px;
    }
    .fin-section-title.disc { color: var(--danger); border-bottom-color: var(--danger-border); }

    /* Single-value rows */
    .fin-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 8px 0;
        border-bottom: 1px solid var(--gray-100);
    }
    .fin-row:last-child { border-bottom: none; }
    .fin-label {
        font-size: 13px;
        font-weight: 600;
        color: var(--gray-700);
        display: flex;
        align-items: center;
        gap: 6px;
        flex: 1;
        min-width: 0;
    }
    .fin-label i { font-size: 12px; width: 14px; text-align: center; }

    /* Dual-currency rows */
    .fin-row-dual {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        padding: 8px 0;
        border-bottom: 1px solid var(--gray-100);
        flex-wrap: wrap;
    }
    .fin-row-dual .fin-label { padding-top: 6px; flex: 0 0 auto; width: 100%; }
    .fin-dual-inputs {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        width: 100%;
    }

    /* Input wrappers with currency badge */
    .fin-input-wrap {
        position: relative;
        display: flex;
        flex-direction: column;
    }
    .fin-input-wrap .fin-input {
        padding-left: 2.6rem !important;
        padding-right: 0.55rem !important;
        text-align: left;
        direction: ltr;
    }
    .fin-currency-badge {
        position: absolute;
        left: 8px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 10px;
        font-weight: 700;
        pointer-events: none;
        border-radius: 4px;
        padding: 1px 4px;
        line-height: 1.4;
    }
    .fin-input-wrap.sar .fin-currency-badge { background: #dcfce7; color: #166534; }
    .fin-input-wrap.yer .fin-currency-badge { background: #fef9c3; color: #854d0e; }

    /* Generic fin-input used outside dual rows too */
    .fin-input {
        width: 100%;
        padding: 0.5rem 0.65rem;
        border: 1.5px solid var(--gray-300);
        border-radius: var(--radius-sm);
        font-size: 13px;
        font-family: 'Cairo', sans-serif;
        color: var(--gray-800);
        background: var(--white);
        transition: border-color 0.15s, box-shadow 0.15s;
        direction: ltr;
        text-align: left;
    }
    .fin-input:hover  { border-color: #93c5fd; }
    .fin-input:focus  { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }

    /* Tax display badges */
    .tax-badge {
        font-size: 11px; font-weight: 700;
        padding: 3px 8px; border-radius: 6px; white-space: nowrap;
    }
    .tax-badge.sar { background: #dcfce7; color: #166534; }
    .tax-badge.yer { background: #fef9c3; color: #854d0e; }

    /* Totals discount summary strip */
    .fin-disc-total {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #fff7ed;
        border: 1px solid #fed7aa;
        border-radius: var(--radius);
        padding: 8px 12px;
        margin: 8px 0;
    }
    .fin-disc-total-label { font-size: 13px; font-weight: 700; color: #9a3412; display: flex; align-items: center; gap: 6px; }
    .fin-disc-total-values { display: flex; gap: 6px; align-items: center; }

    /* Grand total block — project blue */
    .fin-grand-total {
        background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
        border-radius: var(--radius);
        padding: 14px;
        margin-top: 12px;
    }
    .fin-grand-total-label {
        color: #bfdbfe;
        font-size: 11px;
        font-weight: 700;
        text-align: center;
        letter-spacing: 0.08em;
        margin-bottom: 10px;
        text-transform: uppercase;
    }
    .fin-grand-total-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .fin-grand-total-cell { text-align: center; }
    .fin-grand-total-cell .cell-label { font-size: 10px; font-weight: 700; margin-bottom: 4px; }
    .fin-grand-total-cell.sar-cell .cell-label { color: #86efac; }
    .fin-grand-total-cell.yer-cell .cell-label { color: #fde68a; }
    .fin-grand-total-cell .cell-value {
        font-size: 1.3rem;
        font-weight: 900;
        display: block;
    }
    .fin-grand-total-cell.sar-cell .cell-value { color: #86efac; }
    #grandTotalDisplay {
        width: 100%;
        background: transparent;
        border: none;
        border-bottom: 2px solid rgba(253,230,138,0.45);
        color: #fde68a;
        font-size: 1.3rem;
        font-weight: 900;
        text-align: center;
        outline: none;
        padding: 0;
        font-family: 'Cairo', sans-serif;
        direction: ltr;
    }

    /* Final payment override box — gold accent */
    .fin-final-box {
        background: var(--gold-light);
        border: 2px solid var(--gold-border);
        border-radius: var(--radius);
        padding: 12px;
        margin-top: 10px;
    }
    .fin-final-box-title {
        font-size: 12px;
        font-weight: 700;
        color: var(--gold-dark);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .fin-final-box-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .fin-final-box-cell .cell-label { font-size: 10px; font-weight: 700; margin-bottom: 3px; }
    .fin-final-box-cell.sar-cell .cell-label { color: #166534; }
    .fin-final-box-cell.yer-cell .cell-label { color: #854d0e; }
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

            <form method="POST" id="basketForm" enctype="multipart/form-data" data-mode="edit" data-basket-id="<?php echo (int) $basket_id; ?>">
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
                                        placeholder="مثال: سلة شراء يناير 2025" required
                                        value="<?php echo htmlspecialchars($basket['basket_name'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="basket_code"><i class="fas fa-barcode"></i> كود السلة
                                        (اختياري)</label>
                                    <input type="text" id="basket_code" name="basket_code" class="form-control"
                                        placeholder="سيتم إنشاؤه تلقائياً إذا تُرك فارغاً"
                                        value="<?php echo htmlspecialchars($basket['basket_code'] ?? ''); ?>">
                                    <small style="color: #6b7280; font-size: 0.75rem; margin-top: 0.25rem; display: block;">
                                        <i class="fas fa-info-circle"></i> اتركه فارغاً للإنشاء التلقائي (مثال: BASKET-20251115-123948)
                                    </small>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="account_number"><i class="fas fa-hashtag"></i> رقم الحساب</label>
                                    <input type="text" id="account_number" name="account_number" class="form-control" placeholder="أدخل رقم الحساب العددي هنا"
                                        value="<?php echo htmlspecialchars($basket['account_number'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="purchase_date"><i class="fas fa-calendar"></i> تاريخ الشراء</label>
                                    <input type="date" id="purchase_date" name="purchase_date" class="form-control"
                                        value="<?php echo htmlspecialchars($basket['purchase_date'] ?? date('Y-m-d')); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="expected_delivery_date"><i class="fas fa-calendar-check"></i> تاريخ التسليم المتوقع</label>
                                    <input type="date" id="expected_delivery_date" name="expected_delivery_date" class="form-control"
                                        value="<?php echo htmlspecialchars($basket['expected_delivery_date'] ?? ''); ?>">
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label" for="notes"><i class="fas fa-sticky-note"></i> ملاحظات / تفاصيل إضافية</label>
                                    <textarea name="notes" id="notes" class="form-control" rows="2"
                                        placeholder="ملاحظات اختيارية"><?php echo htmlspecialchars($basket['notes'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="attachment"><i class="fas fa-paperclip"></i> رفع مرفق (اختياري)</label>
                                    <input type="file" name="attachment[]" id="attachment" class="form-control" multiple accept="image/*">
                                    <div id="imagePreviewContainer" class="image-preview-grid"></div>
                                    <small style="color: #6b7280; font-size: 0.75rem; margin-top: 0.25rem; display: block;">
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
                                        <option value="" <?php echo (empty($basket['payment_source_type'])) ? 'selected' : ''; ?>>-- بدون تحديد --</option>
                                        <option value="bank_account" <?php echo ($basket['payment_source_type'] ?? '') === 'bank_account' ? 'selected' : ''; ?>>حساب بنكي</option>
                                        <option value="purchase_card" <?php echo ($basket['payment_source_type'] ?? '') === 'purchase_card' ? 'selected' : ''; ?>>بطاقة شراء</option>
                                    </select>
                                </div>
                                <div id="paymentSourceDetails" style="<?php echo (empty($basket['payment_source_type'])) ? 'display: none;' : ''; ?>">
                                    <div class="form-group" id="bankAccountSelector" style="<?php echo ($basket['payment_source_type'] ?? '') === 'bank_account' ? 'display: block;' : 'display: none;'; ?>">
                                        <label for="bankAccountSearch" class="form-label">ابحث عن الحساب البنكي</label>
                                        <input type="text" id="bankAccountSearch" class="form-control"
                                            placeholder="اكتب اسم البنك أو رقم الحساب..." style="margin-bottom: 10px;">
                                        <label for="bankAccountSelect" class="form-label">اختر الحساب البنكي</label>
                                        <select name="payment_source_id_bank" id="bankAccountSelect" class="form-select"
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

                                    <div class="form-group" id="purchaseCardSelector" style="<?php echo ($basket['payment_source_type'] ?? '') === 'purchase_card' ? 'display: block;' : 'display: none;'; ?>">
                                        <label for="purchaseCardSearch" class="form-label">ابحث عن بطاقة الشراء</label>
                                        <input type="text" id="purchaseCardSearch" class="form-control"
                                            placeholder="اكتب اسم البطاقة أو رقمها..." style="margin-bottom: 10px;">
                                        <label for="purchaseCardSelect" class="form-label">اختر بطاقة الشراء</label>
                                        <select name="payment_source_id_purchase" id="purchaseCardSelect" class="form-select"
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
                                        style="display: none; margin-top: 1rem; padding: 0.75rem; background: var(--primary-light); border-radius: var(--radius); border: 1px solid var(--primary-border);">
                                        <span style="font-weight:700; font-size:1rem; color:var(--primary-dark);"
                                            id="sourceBalanceDisplay"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Summary Column -->
                    <div class="lg:col-span-1" style="position: sticky; top: 20px;">
                        <div class="fin-card">

                            <!-- Header -->
                            <div class="fin-header">
                                <div class="fin-header-title">
                                    <i class="fas fa-calculator"></i> الملخص المالي
                                </div>
                                <div class="fin-rate-pill">
                                    <label>1 SAR =</label>
                                    <input type="number" name="yer_exchange_rate" id="yerExchangeRateInput"
                                        step="0.0001" min="0.0001" value="<?php echo number_format($basket['yer_exchange_rate'] ?? 140, 4, '.', ''); ?>">
                                    <span>YER</span>
                                </div>
                            </div>

                            <!-- Column Headers -->
                            <div class="fin-col-heads">
                                <div class="fin-col-head">البند</div>
                                <div class="fin-col-head sar-head">🇸🇦 SAR</div>
                                <div class="fin-col-head yer-head">🇾🇪 YER</div>
                            </div>

                            <div class="fin-body">

                                <!-- عدد المنتجات -->
                                <div class="fin-row">
                                    <span class="fin-label"><i class="fas fa-box-open" style="color:var(--primary);"></i> عدد المنتجات</span>
                                    <input type="number" name="total_products" id="totalProductsInput"
                                        class="fin-input" value="<?php echo $basket['total_items'] ?? 0; ?>" style="width:80px; direction:ltr;">
                                </div>

                                <!-- ── Main Amounts ── -->
                                <div class="fin-section-title">المبالغ الأساسية</div>

                                <!-- المجموع قبل الخصم -->
                                <div class="fin-row-dual">
                                    <span class="fin-label"><i class="fas fa-file-invoice-dollar" style="color:var(--primary);"></i> المجموع قبل الخصم</span>
                                    <div class="fin-dual-inputs">
                                        <div class="fin-input-wrap sar">
                                            <input type="number" name="sar_amount" id="sarInput" step="0.01" min="0" value="<?php echo number_format($basket['sar_amount'] ?? 0, 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">SAR</span>
                                        </div>
                                        <div class="fin-input-wrap yer">
                                            <input type="number" name="subtotal_amount" id="subtotalInput" step="0.01" min="0" value="<?php echo number_format($basket['subtotal_amount_yer'] ?? $basket['subtotal_amount'] ?? 0, 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">YER</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- تكلفة الشحن -->
                                <div class="fin-row-dual">
                                    <span class="fin-label"><i class="fas fa-shipping-fast" style="color:#0ea5e9;"></i> تكلفة الشحن</span>
                                    <div class="fin-dual-inputs">
                                        <div class="fin-input-wrap sar">
                                            <input type="number" name="shipping_cost_sar" id="shippingCostSar" step="0.01" min="0" value="<?php echo number_format(($basket['shipping_cost_yer'] ?? 0) / ($basket['yer_exchange_rate'] ?? 140), 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">SAR</span>
                                        </div>
                                        <div class="fin-input-wrap yer">
                                            <input type="number" name="shipping_cost" id="shippingCost" step="0.01" min="0" value="<?php echo number_format($basket['shipping_cost_yer'] ?? $basket['shipping_cost'] ?? 0, 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">YER</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- الضريبة -->
                                <div class="fin-row" style="flex-wrap:wrap; gap:6px;">
                                    <span class="fin-label"><i class="fas fa-percent" style="color:#f59e0b;"></i> نسبة الضريبة</span>
                                    <div style="display:flex; align-items:center; gap:7px;">
                                        <input type="number" name="tax_rate" id="taxRate" step="0.01" min="0" max="100" value="<?php echo number_format($basket['tax_rate'] ?? 0, 2, '.', ''); ?>"
                                            class="fin-input" style="width:60px; direction:ltr;">
                                        <span style="font-size:12px; color:var(--gray-500); font-weight:700;">%</span>
                                        <label style="display:flex; align-items:center; gap:4px; font-size:12px; color:var(--gray-700); cursor:pointer; font-weight:600;">
                                            <input type="checkbox" name="tax_included" id="taxIncluded"
                                                style="accent-color:var(--primary); width:14px; height:14px;" <?php echo ($basket['tax_included'] ?? 0) ? 'checked' : ''; ?>> شامل
                                        </label>
                                    </div>
                                </div>

                                <!-- مبلغ الضريبة (display only) -->
                                <div class="fin-row" style="padding-top:4px;">
                                    <span class="fin-label" style="color:var(--gray-500); font-weight:500; font-size:12px;">
                                        <i class="fas fa-receipt"></i> مبلغ الضريبة
                                    </span>
                                    <div style="display:flex; gap:5px; align-items:center;">
                                        <span id="taxAmountSarDisplay" class="tax-badge sar">0.00 SAR</span>
                                        <span id="taxAmountDisplay" class="tax-badge yer">0.00 YER</span>
                                        <input type="hidden" name="tax_amount_yer" id="taxCurrencyDisplay" value="0">
                                    </div>
                                </div>

                                <!-- ── Discounts ── -->
                                <div class="fin-section-title disc"><i class="fas fa-tags"></i> الخصومات</div>

                                <!-- خصم يدوي -->
                                <div class="fin-row-dual">
                                    <span class="fin-label"><i class="fas fa-tag" style="color:var(--danger);"></i> خصم يدوي</span>
                                    <div class="fin-dual-inputs">
                                        <div class="fin-input-wrap sar">
                                            <input type="number" name="manual_discount_amount" id="manualDiscountInput" step="0.01" min="0" value="<?php echo number_format($basket['discount_amount'] ?? 0, 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">SAR</span>
                                        </div>
                                        <div class="fin-input-wrap yer">
                                            <input type="number" name="manual_discount_yer" id="manualDiscountCurrencyDisplay" step="0.01" min="0" value="<?php echo number_format($basket['manual_discount_yer'] ?? $basket['discount_amount'] ?? 0, 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">YER</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- خصم نقاط -->
                                <div class="fin-row-dual">
                                    <span class="fin-label"><i class="fas fa-star" style="color:#f59e0b;"></i> خصم نقاط</span>
                                    <div class="fin-dual-inputs">
                                        <div class="fin-input-wrap sar">
                                            <input type="number" name="points_discount" id="points_discount" step="0.01" min="0" value="<?php echo number_format($basket['points_discount'] ?? 0, 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">SAR</span>
                                        </div>
                                        <div class="fin-input-wrap yer">
                                            <input type="number" name="points_discount_yer" id="pointsDiscountCurrencyDisplay" step="0.01" min="0" value="<?php echo number_format($basket['points_discount_yer'] ?? $basket['points_discount'] ?? 0, 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">YER</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- خصم نادي -->
                                <div class="fin-row-dual">
                                    <span class="fin-label"><i class="fas fa-users" style="color:#7c3aed;"></i> خصم نادي</span>
                                    <div class="fin-dual-inputs">
                                        <div class="fin-input-wrap sar">
                                            <input type="number" name="club_discount" id="club_discount" step="0.01" min="0" value="<?php echo number_format($basket['club_discount'] ?? 0, 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">SAR</span>
                                        </div>
                                        <div class="fin-input-wrap yer">
                                            <input type="number" name="club_discount_yer" id="clubDiscountCurrencyDisplay" step="0.01" min="0" value="<?php echo number_format($basket['club_discount_yer'] ?? $basket['club_discount'] ?? 0, 2, '.', ''); ?>" class="fin-input" placeholder="0.00">
                                            <span class="fin-currency-badge">YER</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- كود الخصم -->
                                <div class="fin-row">
                                    <span class="fin-label"><i class="fas fa-ticket-alt" style="color:#0891b2;"></i> كود الخصم</span>
                                    <input type="text" name="coupon_code" class="fin-input" placeholder="أدخل الكود"
                                        style="width:130px; direction:ltr; text-align:center;" value="<?php echo htmlspecialchars($basket['coupon_code'] ?? ''); ?>">
                                </div>

                                <!-- إجمالي الخصومات -->
                                <div class="fin-disc-total">
                                    <span class="fin-disc-total-label"><i class="fas fa-tags"></i> إجمالي الخصومات</span>
                                    <div class="fin-disc-total-values">
                                        <span id="totalDiscountSarDisplay" class="tax-badge sar">0.00 SAR</span>
                                        <span id="totalDiscountDisplay" class="tax-badge yer">0.00 YER</span>
                                        <input type="hidden" name="total_discount_yer" id="totalDiscountCurrencyDisplay" value="<?php echo number_format($basket['total_discount_yer'] ?? 0, 2, '.', ''); ?>">
                                    </div>
                                </div>

                                <!-- ── Grand Total ── -->
                                <div class="fin-grand-total">
                                    <div class="fin-grand-total-label">الصافي النهائي</div>
                                    <div class="fin-grand-total-grid">
                                        <div class="fin-grand-total-cell sar-cell">
                                            <div class="cell-label">🇸🇦 SAR</div>
                                            <span id="grandTotalSarDisplay" class="cell-value">0.00</span>
                                        </div>
                                        <div class="fin-grand-total-cell yer-cell">
                                            <div class="cell-label">🇾🇪 YER</div>
                                            <input type="number" name="grand_total_yer" id="grandTotalDisplay"
                                                step="0.01" min="0" value="<?php echo number_format($basket['grand_total_yer'] ?? $basket['final_amount'] ?? 0, 2, '.', ''); ?>">
                                        </div>
                                    </div>
                                </div>

                                <!-- السعر النهائي للدفع -->
                                <div class="fin-final-box">
                                    <div class="fin-final-box-title">
                                        <i class="fas fa-check-double" style="color:var(--gold);"></i>
                                        السعر النهائي للدفع (المبلغ الذي سيُخصم)
                                    </div>
                                    <div class="fin-final-box-grid">
                                        <div class="fin-final-box-cell sar-cell">
                                            <div class="cell-label">🇸🇦 SAR</div>
                                            <input type="number" name="final_price_override_sar" id="finalPriceOverrideSar"
                                                step="0.01" min="0" class="fin-input" placeholder="0.00"
                                                style="direction:ltr; text-align:left;" value="<?php echo number_format(($basket['final_price_override'] ?? 0) / ($basket['yer_exchange_rate'] ?? 140), 2, '.', ''); ?>">
                                        </div>
                                        <div class="fin-final-box-cell yer-cell">
                                            <div class="cell-label">🇾🇪 YER</div>
                                            <input type="number" name="final_price_override" id="final_price_override"
                                                step="0.01" min="0" class="fin-input" placeholder="0.00"
                                                style="direction:ltr; text-align:left;" value="<?php echo number_format($basket['final_price_override'] ?? 0, 2, '.', ''); ?>">
                                        </div>
                                    </div>
                                </div>

                            </div><!-- /fin-body -->
                        </div><!-- /fin-card -->
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="card" style="position: sticky; bottom: 0; z-index: 10;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <a href="show_baskets.php" class="btn btn-secondary"><i class="fas fa-times"></i> إلغاء</a>
                        <button type="submit" name="action" value="update_basket" class="btn btn-success"
                            id="lockBasketBtn"><i class="fas fa-lock"></i> إقفال وطلب</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="basket_manual.js?v=4.8"></script>

<?php include '../../includes/footer.php'; ?>
