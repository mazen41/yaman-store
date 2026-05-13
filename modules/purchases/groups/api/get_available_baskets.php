<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once 'config/database.php';

try {
    // Get baskets that are not assigned to any group
    $query = "SELECT 
                pb.id,
                pb.basket_code,
                pb.basket_name,
                pb.final_amount,
                pb.status,
                pb.created_at
              FROM purchase_baskets pb
              WHERE pb.purchase_group_id IS NULL
              ORDER BY pb.created_at DESC";
    
    $stmt = $db->query($query);
    $baskets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'baskets' => $baskets
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
