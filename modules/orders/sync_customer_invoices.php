<?php
/**
 * Sync Invoices and Payments for a specific customer
 */
session_start();

// Only allow access to authenticated users
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once 'order_hooks.php';

// Get customer ID from query string
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$redirect_url = isset($_GET['redirect']) ? $_GET['redirect'] : '../customers/index.php';

if ($customer_id <= 0) {
    header("Location: $redirect_url?error=" . urlencode('معرف العميل غير صالح'));
    exit();
}

// Check if customer exists
try {
    $customer_stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND is_active = 1");
    $customer_stmt->execute([$customer_id]);
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        header("Location: $redirect_url?error=" . urlencode('العميل غير موجود'));
        exit();
    }
    
    $results = [
        'orders_processed' => 0,
        'invoices_created' => 0,
        'payments_created' => 0
    ];
    
    // Get all orders for this customer that don't have invoices
    $orders_stmt = $db->prepare("
        SELECT co.* 
        FROM customer_orders co 
        LEFT JOIN customer_invoices ci ON co.id = ci.order_id 
        WHERE co.customer_id = ? AND ci.id IS NULL
    ");
    $orders_stmt->execute([$customer_id]);
    $orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results['orders_processed'] = count($orders);
    
    // Create invoices for each order
    foreach ($orders as $order) {
        $invoice_id = create_invoice_for_order($order['id'], $order);
        if ($invoice_id) {
            $results['invoices_created']++;
            
            // If order is completed, create payment
            if ($order['status'] === 'completed') {
                $payment_id = create_payment_for_invoice($invoice_id, $order);
                if ($payment_id) {
                    $results['payments_created']++;
                }
            }
        }
    }
    
    // Check for completed orders with invoices but no payments
    $completed_orders_stmt = $db->prepare("
        SELECT co.*, ci.id as invoice_id
        FROM customer_orders co 
        JOIN customer_invoices ci ON co.id = ci.order_id 
        LEFT JOIN customer_payments cp ON ci.id = cp.invoice_id
        WHERE co.customer_id = ? AND co.status = 'completed' AND cp.id IS NULL
    ");
    $completed_orders_stmt->execute([$customer_id]);
    $completed_orders = $completed_orders_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create payments for completed orders
    foreach ($completed_orders as $order) {
        $payment_id = create_payment_for_invoice($order['invoice_id'], $order);
        if ($payment_id) {
            $results['payments_created']++;
        }
    }
    
    // Update invoice statuses based on order statuses
    $db->prepare("
        UPDATE customer_invoices ci
        JOIN customer_orders co ON ci.order_id = co.id
        SET ci.status = 
            CASE 
                WHEN co.status = 'completed' THEN 'paid'
                WHEN co.status = 'cancelled' THEN 'cancelled'
                ELSE 'pending'
            END,
        ci.updated_at = NOW()
        WHERE co.customer_id = ? AND ci.status != 
            CASE 
                WHEN co.status = 'completed' THEN 'paid'
                WHEN co.status = 'cancelled' THEN 'cancelled'
                ELSE 'pending'
            END
    ")->execute([$customer_id]);
    
    // Redirect back with success message
    $success_message = "تمت المزامنة بنجاح: {$results['invoices_created']} فاتورة جديدة و {$results['payments_created']} دفعة جديدة";
    header("Location: $redirect_url?success=" . urlencode($success_message));
    exit();
    
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء مزامنة الفواتير والمدفوعات: ' . $e->getMessage();
    header("Location: $redirect_url?error=" . urlencode($error_message));
    exit();
}
?>
