<?php
session_start();
// Ensure UTF-8 encoding
header('Content-Type: text/html; charset=utf-8');

// 1. Security: Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// 2. Include Database Config
require_once '../../config/database.php';

// 3. Validation: Check if request is valid
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['expense_id'])) {
    $_SESSION['error_message'] = 'طلب غير صالح.';
    header('Location: index.php');
    exit();
}

$expense_id = intval($_POST['expense_id']);

try {
    // Start Transaction
    $db->beginTransaction();

    // --- Step 1: Fetch the full expense details BEFORE deleting ---
    // We need this to know how much money to return and to which account
    $stmt = $db->prepare("
        SELECT * FROM expenses 
        WHERE id = ?
    ");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        throw new Exception('لم يتم العثور على المصروف المحدد/تم حذفه مسبقاً.');
    }

    // Only reverse financial transactions if the expense was actually 'approved'
    // (If it was 'pending' or 'rejected', money likely wasn't taken out yet)
    if ($expense['status'] === 'approved') {

        $amount_to_refund = floatval($expense['amount']);
        $payment_method = $expense['payment_method'];
        $bank_account_id = $expense['bank_account_id'];
        $customer_id = $expense['customer_id'];

        // --- Step 2: Return Money to the Source (Bank or Cash) ---
        
        if ($payment_method === 'bank_transfer' && !empty($bank_account_id)) {
            // OPTION A: Bank Transfer -> Return money to the specific Bank Account
            // Logic: current_balance + amount
            $update_bank = $db->prepare("
                UPDATE bank_accounts 
                SET current_balance = current_balance + ? 
                WHERE id = ?
            ");
            $update_bank->execute([$amount_to_refund, $bank_account_id]);

        } elseif ($payment_method === 'cash') {
            // OPTION B: Cash -> Return money to the 'الصندوق' (Cash Box)
            // Logic: current_balance + amount
            // Note: This matches the logic used in your 'add' page
            $update_cash = $db->prepare("
                UPDATE bank_accounts 
                SET current_balance = current_balance + ? 
                WHERE bank_name = 'الصندوق'
            ");
            $update_cash->execute([$amount_to_refund]);
        }

        // --- Step 3: Reverse Customer Refund (If applicable) ---
        // If this expense was a "Refund to Customer", we reduced their Paid Amount previously.
        // Now that we are deleting the refund, we must ADD that amount back to their Paid Amount
        // (meaning: we claim they still paid it).
        if (!empty($customer_id)) {
            // We verify if this expense was actually linked to a customer
            // We try to update the most recent order or a specific order if we had the ID.
            // Since expenses table usually doesn't store 'order_id' directly in some versions,
            // we target the customer's orders broadly or the latest one.
            
            // Logic: paid_amount + amount (Restoring the payment value)
            $update_order = $db->prepare("
                UPDATE customer_orders 
                SET paid_amount = paid_amount + ? 
                WHERE customer_id = ? 
                AND status NOT IN ('cancelled', 'returned')
                ORDER BY id DESC 
                LIMIT 1
            ");
            $update_order->execute([$amount_to_refund, $customer_id]);
        }

        // --- Step 4: Delete the Accounting Journal Entry ---
        // This removes the debit/credit records from the ledger
        $delete_journal = $db->prepare("
            DELETE FROM journal_entries 
            WHERE source_module = 'expenses' AND source_id = ?
        ");
        $delete_journal->execute([$expense_id]);
    }

    // --- Step 5: Finally, Delete the Expense Record ---
    $delete_stmt = $db->prepare("DELETE FROM expenses WHERE id = ?");
    $delete_stmt->execute([$expense_id]);

    // Commit changes
    $db->commit();

    $_SESSION['success_message'] = 'تم حذف المصروف وإرجاع المبلغ للحساب بنجاح.';

} catch (Exception $e) {
    // Rollback if error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Expense Delete Error: " . $e->getMessage());
    $_SESSION['error_message'] = 'حدث خطأ أثناء الحذف: ' . $e->getMessage();
}

// Redirect back
header('Location: index.php');
exit();
?>