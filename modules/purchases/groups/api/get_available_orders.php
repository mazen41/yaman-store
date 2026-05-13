<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once 'config/database.php';

try {
    // Get customer orders that are not assigned to any group
    $query = "SELECT 
                co.id,
                co.order_number,
                co.final_amount,
                co.status,
                co.created_at,
                c.name as customer_name
              FROM customer_orders co
              LEFT JOIN customers c ON co.customer_id = c.id
              WHERE co.purchase_group_id IS NULL
              ORDER BY co.created_at DESC";
    
    $stmt = $db->query($query);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
