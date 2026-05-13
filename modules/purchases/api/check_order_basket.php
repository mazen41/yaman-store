<?php
/**
 * API: Check if Order is in Basket
 * التحقق من وجود الطلب في سلة أخرى
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
    // Check if order is in any basket
    $stmt = $db->prepare("
        SELECT 
            o.basket_id,
            pb.basket_code,
            pb.basket_name,
            pb.status as basket_status
        FROM customer_orders o
        LEFT JOIN purchase_baskets pb ON o.basket_id = pb.id
        WHERE o.id = ? AND o.basket_id IS NOT NULL
    ");
    
    $stmt->execute([$order_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'in_basket' => true,
            'basket_id' => $result['basket_id'],
            'basket_code' => $result['basket_code'],
            'basket_name' => $result['basket_name'],
            'basket_status' => $result['basket_status']
        ]);
    } else {
        echo json_encode([
            'in_basket' => false
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
