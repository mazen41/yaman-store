<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/accounting_functions.php';

// Enforce permission: only users with payments_edit can delete
if (!hasPermission($_SESSION['user_id'], 'payments', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لحذف المدفوعات';
    header('Location: index.php');
    exit();
}

$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($payment_id <= 0) {
    header('Location: index.php');
    exit();
}

try {
    $db->beginTransaction();

    // Load payment row
    $stmt = $db->prepare("SELECT * FROM customer_payments WHERE id = ? FOR UPDATE");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $db->rollBack();
        $_SESSION['error_message'] = 'الدفعة غير موجودة';
        header('Location: index.php');
        exit();
    }

    $invoice_id      = $payment['invoice_id'] ?? null;
    $bank_account_id = $payment['payment_method'] === 'transfer' ? ($payment['bank_account_id'] ?? null) : null;
    $amount          = (float)($payment['amount'] ?? 0);

    // Check if the payment is linked to an invoice
    if (!empty($invoice_id)) {
        // ===================================================================
        // START: *** NEW LOGIC TO UPDATE ORDER'S PAID AMOUNT ***
        // ===================================================================
        // 1) Find the order associated with the invoice
        $order_stmt = $db->prepare("SELECT order_id FROM customer_invoices WHERE id = ?");
        $order_stmt->execute([$invoice_id]);
        $order_id = $order_stmt->fetchColumn();

        // 2) If an order is found and the payment amount is > 0, decrease the order's paid_amount
        if ($order_id && $amount > 0) {
            // Use GREATEST(0, ...) to prevent paid_amount from ever being negative
            $update_order_stmt = $db->prepare(
                "UPDATE customer_orders 
                 SET paid_amount = GREATEST(0, COALESCE(paid_amount, 0) - ?) 
                 WHERE id = ?"
            );
            $update_order_stmt->execute([$amount, $order_id]);
        }
        // ===================================================================
        // END: *** NEW LOGIC TO UPDATE ORDER'S PAID AMOUNT ***
        // ===================================================================

        // 3) Reverse effect on invoice status
        // Get invoice total
        $inv_stmt = $db->prepare('SELECT total_amount FROM customer_invoices WHERE id = ? FOR UPDATE');
        $inv_stmt->execute([$invoice_id]);
        $invoice_total = $inv_stmt->fetchColumn();

        if ($invoice_total !== false) {
            // Sum payments excluding this one
            $paid_stmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE invoice_id = ? AND id <> ?');
            $paid_stmt->execute([$invoice_id, $payment_id]);
            $total_paid = (float)$paid_stmt->fetchColumn();

            $new_status = 'pending';
            if ($total_paid <= 0) {
                $new_status = 'pending';
            } elseif ($total_paid + 0.001 >= $invoice_total) {
                $new_status = 'paid';
            } else {
                $new_status = 'partially_paid';
            }

            $update_inv = $db->prepare('UPDATE customer_invoices SET status = ?, updated_at = NOW() WHERE id = ?');
            $update_inv->execute([$new_status, $invoice_id]);
        }
    }

    // 4) Reverse bank account balance for transfer
    if (!empty($bank_account_id) && $amount > 0) {
        $bank_stmt = $db->prepare('UPDATE bank_accounts SET current_balance = COALESCE(current_balance,0) - ? WHERE id = ?');
        $bank_stmt->execute([$amount, $bank_account_id]);
    }
    
    // 5) Reverse accounting entries
    // Safely delete the associated journal entry.
    // This will not stop the payment deletion if the accounting part fails.
    try {
        delete_journal_entry_by_source($db, 'payments', $payment_id);
    } catch (Exception $acc_e) {
        error_log("Accounting cleanup failed for deleted Payment ID $payment_id: " . $acc_e->getMessage());
    }
    
    // 6) Delete the payment itself
    $del_stmt = $db->prepare('DELETE FROM customer_payments WHERE id = ?');
    $del_stmt->execute([$payment_id]);

    $db->commit();

    $_SESSION['success_message'] = 'تم حذف الدفعة وتحديث مبلغ الطلب المدفوع بنجاح';

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error_message'] = 'فشل في حذف الدفعة: ' . $e->getMessage();
}

$redirect = $_GET['redirect'] ?? 'index.php';
header('Location: ' . $redirect);
exit();