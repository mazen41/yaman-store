<?php
/**
 * API: Get Order Details
 * Returns detailed information about a specific order
 */

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../../../config/database.php';

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($order_id === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Order ID required']);
    exit();
}

try {
    // Check which columns exist
    $order_columns = $db->query("SHOW COLUMNS FROM customer_orders")->fetchAll(PDO::FETCH_COLUMN);
    $customer_columns = $db->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    
    // Build SELECT dynamically
    $select_parts = [
        'o.id',
        'o.order_number',
        'o.customer_id',
        'o.created_at',
        'o.basket_id',
        'c.name as customer_name'
    ];
    
    // Add amount columns
    if (in_array('final_amount', $order_columns)) {
        $select_parts[] = 'o.final_amount';
    } elseif (in_array('total_amount', $order_columns)) {
        $select_parts[] = 'o.total_amount as final_amount';
    } else {
        $select_parts[] = '0 as final_amount';
    }
    
    if (in_array('subtotal_amount', $order_columns)) {
        $select_parts[] = 'o.subtotal_amount';
    } elseif (in_array('total_amount', $order_columns)) {
        $select_parts[] = 'o.total_amount as subtotal_amount';
    } else {
        $select_parts[] = '0 as subtotal_amount';
    }
    
    if (in_array('discount_amount', $order_columns)) {
        $select_parts[] = 'o.discount_amount';
    } else {
        $select_parts[] = '0 as discount_amount';
    }
    
    if (in_array('status', $order_columns)) {
        $select_parts[] = 'o.status';
    } else {
        $select_parts[] = "'new' as status";
    }
    
    if (in_array('notes', $order_columns)) {
        $select_parts[] = 'o.notes';
    } else {
        $select_parts[] = "'' as notes";
    }
    
    // Add customer columns
    if (in_array('customer_code', $customer_columns)) {
        $select_parts[] = 'c.customer_code';
    } else {
        $select_parts[] = "'' as customer_code";
    }
    
    if (in_array('mobile_number', $customer_columns)) {
        $select_parts[] = 'c.mobile_number';
    } else {
        $select_parts[] = "'' as mobile_number";
    }
    
    if (in_array('whatsapp_number', $customer_columns)) {
        $select_parts[] = 'c.whatsapp_number';
    } else {
        $select_parts[] = "'' as whatsapp_number";
    }
    
    $select_sql = implode(', ', $select_parts);
    
    $sql = "
        SELECT $select_sql
        FROM customer_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit();
    }
    
    // Get order items if table exists
    try {
        $items_stmt = $db->prepare("
            SELECT 
                oi.*,
                p.name as product_name,
                p.product_code
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $items_stmt->execute([$order_id]);
        $order['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $order['items'] = [];
    }
    
    // Format and clean data
    $result = [
        'id' => (int)$order['id'],
        'order_number' => $order['order_number'],
        'customer_id' => (int)$order['customer_id'],
        'customer_name' => isset($order['customer_name']) ? $order['customer_name'] : '',
        'customer_code' => isset($order['customer_code']) ? $order['customer_code'] : '',
        'mobile_number' => isset($order['mobile_number']) ? $order['mobile_number'] : '',
        'whatsapp_number' => isset($order['whatsapp_number']) ? $order['whatsapp_number'] : '',
        'created_at' => date('Y-m-d', strtotime($order['created_at'])),
        'subtotal_amount' => (float)(isset($order['subtotal_amount']) ? $order['subtotal_amount'] : 0),
        'discount_amount' => (float)(isset($order['discount_amount']) ? $order['discount_amount'] : 0),
        'final_amount' => (float)(isset($order['final_amount']) ? $order['final_amount'] : 0),
        'status' => isset($order['status']) ? $order['status'] : 'new',
        'notes' => isset($order['notes']) ? $order['notes'] : '',
        'basket_id' => isset($order['basket_id']) ? $order['basket_id'] : null,
        'basket_code' => null,
        'basket_name' => null,
        'in_basket' => !empty($order['basket_id']),
        'items' => []
    ];
    
    // Add items if they exist
    if (isset($order['items']) && is_array($order['items'])) {
        $result['items'] = $order['items'];
    }
    
    echo json_encode($result);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'General error: ' . $e->getMessage()]);
}
?>
