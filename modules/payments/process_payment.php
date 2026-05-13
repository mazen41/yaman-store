<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $bank_account_id = !empty($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : null;
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = $_POST['notes'] ?? '';
    
    // Handle file upload
    $receipt_image = null;
    if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../../uploads/payment_receipts/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024;
        
        $file_type = $_FILES['receipt_image']['type'];
        $file_size = $_FILES['receipt_image']['size'];
        
        if (in_array($file_type, $allowed_types) && $file_size <= $max_size) {
            $extension = pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION);
            $filename = 'receipt_' . time() . '_' . uniqid() . '.' . $extension;
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['receipt_image']['tmp_name'], $upload_path)) {
                $receipt_image = $filename;
            }
        }
    }
    
    try {
        $db->beginTransaction();
        
        // Generate payment number
        $stmt = $db->query("SELECT COUNT(*) FROM payments");
        $count = $stmt->fetchColumn();
        $payment_number = 'PAY-' . date('Ymd') . '-' . str_pad($count + 1, 8, '0', STR_PAD_LEFT);
        
        // Insert payment
        $stmt = $db->prepare("INSERT INTO payments (payment_number, invoice_id, amount, payment_method, bank_account_id, receipt_image, payment_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$payment_number, $invoice_id, $amount, $payment_method, $bank_account_id, $receipt_image, $payment_date, $notes, $_SESSION['user_id']]);
        
        // Update invoice - RECALCULATE paid_amount from SUM
        $stmt = $db->prepare("
            UPDATE customer_invoices 
            SET paid_amount = (
                SELECT COALESCE(SUM(amount), 0) 
                FROM payments 
                WHERE invoice_id = ?
            ),
            updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$invoice_id, $invoice_id]);
        
        // Update remaining_amount
        $stmt = $db->prepare("
            UPDATE customer_invoices 
            SET remaining_amount = GREATEST(0, total_amount - paid_amount)
            WHERE id = ?
        ");
        $stmt->execute([$invoice_id]);
        
        // Update status
        $stmt = $db->prepare("
            UPDATE customer_invoices 
            SET status = CASE 
                WHEN paid_amount >= total_amount THEN 'paid'
                WHEN paid_amount > 0 THEN 'partially_paid'
                ELSE 'unpaid'
            END
            WHERE id = ?
        ");
        $stmt->execute([$invoice_id]);
        
        $db->commit();
        
        $_SESSION['success_message'] = 'تم إضافة الدفعة بنجاح';
        header('Location: add.php?invoice_id=' . $invoice_id);
        exit();
        
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error_message'] = 'حدث خطأ: ' . $e->getMessage();
        header('Location: add.php?invoice_id=' . $invoice_id);
        exit();
    }
} else {
    header('Location: ../customers/show_invoices.php');
    exit();
}
