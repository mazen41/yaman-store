<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once 'order_hooks.php';

// This file processes order actions (add, update, delete)
$action = $_POST['action'] ?? '';
$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
$redirect_url = $_POST['redirect_url'] ?? 'index.php';

$success_message = '';
$error_message = '';

try {
    if ($action === 'add') {
        // Process new order
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $order_number = 'ORD-' . date('Ymd') . '-' . sprintf('%04d', rand(1000, 9999));
        $status = $_POST['status'] ?? 'new';
        $total_amount = isset($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;
        $shipping_cost = isset($_POST['shipping_cost']) ? floatval($_POST['shipping_cost']) : 0;
        $final_amount = $total_amount + $shipping_cost;
        $payment_method = $_POST['payment_method'] ?? 'cash';
        $shipping_method = $_POST['shipping_method'] ?? 'delivery';
        $expected_delivery_date = $_POST['expected_delivery_date'] ?? date('Y-m-d', strtotime('+7 days'));
        
        // Insert order
        $stmt = $db->prepare("INSERT INTO customer_orders 
                             (customer_id, order_number, status, total_amount, shipping_cost, final_amount, 
                              payment_method, shipping_method, expected_delivery_date, created_at, updated_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        
        $stmt->execute([
            $customer_id, $order_number, $status, $total_amount, $shipping_cost, $final_amount,
            $payment_method, $shipping_method, $expected_delivery_date
        ]);
        
        $order_id = $db->lastInsertId();
        
        // Get the order data for invoice creation
        $order_stmt = $db->prepare("SELECT * FROM customer_orders WHERE id = ?");
        $order_stmt->execute([$order_id]);
        $order_data = $order_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Create invoice for the order
        $invoice_id = create_invoice_for_order($order_id, $order_data);
        
        $success_message = 'تم إضافة الطلب بنجاح';
        
    } elseif ($action === 'update' && $order_id > 0) {
        // Process order update
        $status = $_POST['status'] ?? '';
        $total_amount = isset($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;
        $shipping_cost = isset($_POST['shipping_cost']) ? floatval($_POST['shipping_cost']) : 0;
        $final_amount = $total_amount + $shipping_cost;
        $payment_method = $_POST['payment_method'] ?? '';
        $shipping_method = $_POST['shipping_method'] ?? '';
        $expected_delivery_date = $_POST['expected_delivery_date'] ?? '';
        
        // Update order
        $stmt = $db->prepare("UPDATE customer_orders SET 
                             status = ?, total_amount = ?, shipping_cost = ?, final_amount = ?,
                             payment_method = ?, shipping_method = ?, expected_delivery_date = ?,
                             updated_at = NOW() 
                             WHERE id = ?");
        
        $stmt->execute([
            $status, $total_amount, $shipping_cost, $final_amount,
            $payment_method, $shipping_method, $expected_delivery_date,
            $order_id
        ]);
        
        // Update invoice status based on order status
        update_invoice_for_order_status($order_id, $status);
        
        $success_message = 'تم تحديث الطلب بنجاح';
        
    } elseif ($action === 'delete' && $order_id > 0) {
        // Process order deletion (soft delete)
        $stmt = $db->prepare("UPDATE customer_orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$order_id]);
        
        // Update invoice status
        update_invoice_for_order_status($order_id, 'cancelled');
        
        $success_message = 'تم إلغاء الطلب بنجاح';
    }
    
    // Redirect with success message
    header("Location: $redirect_url?success=" . urlencode($success_message));
    exit();
    
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء معالجة الطلب: ' . $e->getMessage();
    header("Location: $redirect_url?error=" . urlencode($error_message));
    exit();
}
?>
