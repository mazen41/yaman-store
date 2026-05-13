<?php
/**
 * API: Get Customer Orders
 * Senior Engineer Solution - Robust and Simple
 */

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    require_once '../../../config/database.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

if ($customer_id === 0) {
    echo json_encode([]);
    exit();
}

try {
    // First, check what columns exist
    $columns = $db->query("SHOW COLUMNS FROM customer_orders")->fetchAll(PDO::FETCH_COLUMN);
    
    // Build SELECT based on available columns
    $select_parts = [
        'o.id',
        'o.order_number',
        'o.customer_id',
        'o.created_at',
        'o.basket_id',
        'c.name as customer_name'
    ];
    
    // Handle amount columns intelligently
    if (in_array('final_amount', $columns)) {
        $select_parts[] = 'o.final_amount';
    } elseif (in_array('total_amount', $columns)) {
        $select_parts[] = 'o.total_amount as final_amount';
    } else {
        $select_parts[] = '0 as final_amount';
    }
    
    if (in_array('subtotal_amount', $columns)) {
        $select_parts[] = 'o.subtotal_amount';
    } elseif (in_array('total_amount', $columns)) {
        $select_parts[] = 'o.total_amount as subtotal_amount';
    } else {
        $select_parts[] = '0 as subtotal_amount';
    }
    
    if (in_array('discount_amount', $columns)) {
        $select_parts[] = 'o.discount_amount';
    } else {
        $select_parts[] = '0 as discount_amount';
    }
    
    if (in_array('status', $columns)) {
        $select_parts[] = 'o.status';
    } else {
        $select_parts[] = "'new' as status";
    }
    
    $select_sql = implode(', ', $select_parts);
    
    $sql = "
        SELECT $select_sql
        FROM customer_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.customer_id = ?
        ORDER BY o.created_at DESC
        LIMIT 50
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$customer_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format and clean data
    $result = [];
    foreach ($orders as $order) {
        $result[] = [
            'id' => (int)$order['id'],
            'order_number' => $order['order_number'],
            'customer_id' => (int)$order['customer_id'],
            'customer_name' => isset($order['customer_name']) ? $order['customer_name'] : '',
            'created_at' => date('Y-m-d', strtotime($order['created_at'])),
            'subtotal_amount' => (float)(isset($order['subtotal_amount']) ? $order['subtotal_amount'] : 0),
            'discount_amount' => (float)(isset($order['discount_amount']) ? $order['discount_amount'] : 0),
            'final_amount' => (float)(isset($order['final_amount']) ? $order['final_amount'] : 0),
            'status' => isset($order['status']) ? $order['status'] : 'new',
            'basket_id' => isset($order['basket_id']) ? $order['basket_id'] : null,
            'in_basket' => !empty($order['basket_id']),
            'basket_code' => null,
            'basket_name' => null
        ];
    }
    
    echo json_encode($result);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'General error',
        'message' => $e->getMessage()
    ]);
}
?>
