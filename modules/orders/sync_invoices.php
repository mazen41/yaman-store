<?php
/**
 * Sync Invoices and Payments
 * This script ensures all orders have corresponding invoices and payments
 */
session_start();

// Only allow access to authenticated users
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'غير مصرح بالوصول']);
        exit;
    }
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once 'order_hooks.php';

// Set content type to JSON if this is an AJAX request
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
if ($is_ajax) {
    header('Content-Type: application/json');
}

$results = [
    'success' => true,
    'orders_processed' => 0,
    'invoices_created' => 0,
    'payments_created' => 0,
    'errors' => []
];

try {
    // Get all orders that don't have invoices
    $orders_stmt = $db->query("
        SELECT co.* 
        FROM customer_orders co 
        LEFT JOIN customer_invoices ci ON co.id = ci.order_id 
        WHERE ci.id IS NULL
    ");
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
    $completed_orders_stmt = $db->query("
        SELECT co.*, ci.id as invoice_id
        FROM customer_orders co 
        JOIN customer_invoices ci ON co.id = ci.order_id 
        LEFT JOIN customer_payments cp ON ci.id = cp.invoice_id
        WHERE co.status = 'completed' AND cp.id IS NULL
    ");
    $completed_orders = $completed_orders_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create payments for completed orders
    foreach ($completed_orders as $order) {
        $payment_id = create_payment_for_invoice($order['invoice_id'], $order);
        if ($payment_id) {
            $results['payments_created']++;
        }
    }
    
    // Update invoice statuses based on order statuses
    $db->query("
        UPDATE customer_invoices ci
        JOIN customer_orders co ON ci.order_id = co.id
        SET ci.status = 
            CASE 
                WHEN co.status = 'completed' THEN 'paid'
                WHEN co.status = 'cancelled' THEN 'cancelled'
                ELSE 'pending'
            END,
        ci.updated_at = NOW()
        WHERE ci.status != 
            CASE 
                WHEN co.status = 'completed' THEN 'paid'
                WHEN co.status = 'cancelled' THEN 'cancelled'
                ELSE 'pending'
            END
    ");
    
    $results['message'] = "تمت المزامنة بنجاح: {$results['invoices_created']} فاتورة جديدة و {$results['payments_created']} دفعة جديدة";
    
} catch (PDOException $e) {
    $results['success'] = false;
    $results['message'] = 'حدث خطأ أثناء مزامنة الفواتير والمدفوعات: ' . $e->getMessage();
    $results['errors'][] = $e->getMessage();
}

// Output results
if ($is_ajax) {
    echo json_encode($results);
} else {
    // HTML output
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>مزامنة الفواتير والمدفوعات</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            body {
                font-family: 'Cairo', sans-serif;
            }
        </style>
    </head>
    <body class="bg-gray-50">
        <div class="min-h-screen flex items-center justify-center">
            <div class="max-w-md w-full bg-white rounded-lg shadow-lg p-8">
                <div class="text-center">
                    <?php if ($results['success']): ?>
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-amber-100 text-amber-600 mb-6">
                            <i class="fas fa-check text-2xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 mb-2">تمت المزامنة بنجاح</h2>
                        <p class="text-gray-600 mb-6">تم إنشاء <?php echo $results['invoices_created']; ?> فاتورة جديدة و <?php echo $results['payments_created']; ?> دفعة جديدة</p>
                    <?php else: ?>
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-red-100 text-red-600 mb-6">
                            <i class="fas fa-times text-2xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 mb-2">حدث خطأ</h2>
                        <p class="text-gray-600 mb-6"><?php echo htmlspecialchars($results['message']); ?></p>
                    <?php endif; ?>
                    
                    <div class="bg-gray-50 p-4 rounded-lg mb-6">
                        <h3 class="text-sm font-medium text-gray-500 mb-2">تفاصيل المزامنة</h3>
                        <ul class="space-y-2 text-sm">
                            <li class="flex justify-between">
                                <span>الطلبات التي تمت معالجتها:</span>
                                <span class="font-semibold"><?php echo $results['orders_processed']; ?></span>
                            </li>
                            <li class="flex justify-between">
                                <span>الفواتير التي تم إنشاؤها:</span>
                                <span class="font-semibold"><?php echo $results['invoices_created']; ?></span>
                            </li>
                            <li class="flex justify-between">
                                <span>المدفوعات التي تم إنشاؤها:</span>
                                <span class="font-semibold"><?php echo $results['payments_created']; ?></span>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="flex space-x-4 space-x-reverse">
                        <a href="../customers/index.php" class="flex-1 bg-gray-600 text-white py-2 px-4 rounded-lg hover:bg-gray-700 transition-colors">
                            العملاء
                        </a>
                        <a href="../orders/index.php" class="flex-1 bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition-colors">
                            الطلبات
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>
