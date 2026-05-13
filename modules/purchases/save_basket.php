<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db->beginTransaction();
        
        $basket_id = intval($_POST['basket_id'] ?? 0);
        
        // Get values from POST
        $subtotal = floatval($_POST['subtotal_amount'] ?? 0);
        $discount = floatval($_POST['discount_amount'] ?? 0);
        $tax = floatval($_POST['tax_amount'] ?? 0);
        $shipping = floatval($_POST['shipping_cost'] ?? 0);
        
        // Calculate final amount (allow manual override)
        if (isset($_POST['final_amount']) && $_POST['final_amount'] !== '') {
            $final_amount = floatval($_POST['final_amount']);
        } else {
            $final_amount = $subtotal - $discount + $tax + $shipping;
        }
        
        if ($basket_id > 0) {
            // Update existing basket
            $stmt = $db->prepare("
                UPDATE purchase_baskets 
                SET 
                    basket_name = ?,
                    supplier_id = ?,
                    subtotal_amount = ?,
                    discount_amount = ?,
                    tax_amount = ?,
                    shipping_cost = ?,
                    final_amount = ?,
                    notes = ?,
                    payment_source_type = NULL,
                    payment_source_id = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $_POST['basket_name'],
                $_POST['supplier_id'],
                $subtotal,
                $discount,
                $tax,
                $shipping,
                $final_amount,
                $_POST['notes'] ?? '',
                $basket_id
            ]);
            
            $message = 'تم تحديث السلة بنجاح';
            
        } else {
            // Create new basket
            $basket_code = 'BASKET-' . date('Y') . '-' . rand(1000, 9999);
            
            $stmt = $db->prepare("
                INSERT INTO purchase_baskets 
                (basket_code, basket_name, supplier_id, subtotal_amount, 
                 discount_amount, tax_amount, shipping_cost, final_amount, 
                 notes, created_by, payment_source_type, payment_source_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NOW())
            ");
            
            $stmt->execute([
                $basket_code,
                $_POST['basket_name'],
                $_POST['supplier_id'],
                $subtotal,
                $discount,
                $tax,
                $shipping,
                $final_amount,
                $_POST['notes'] ?? '',
                $_SESSION['user_id']
            ]);
            
            $basket_id = $db->lastInsertId();
            $message = 'تم إنشاء السلة بنجاح';
        }
        
        $db->commit();
        
        $_SESSION['success_message'] = $message;
        header('Location: show_baskets.php');
        exit();
        
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error_message'] = 'خطأ: ' . $e->getMessage();
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit();
    }
} else {
    header('Location: show_baskets.php');
    exit();
}
