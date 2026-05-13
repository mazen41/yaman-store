<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $basket_id = intval($_POST['basket_id'] ?? 0);
    $new_status = $_POST['order_status'] ?? '';
    
    $allowed_statuses = ['pending', 'in_basket', 'in_shipping', 'in_sorting', 'ready_for_delivery', 'completed'];
    
    if (!$basket_id || !in_array($new_status, $allowed_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit();
    }
    
    try {
        $stmt = $db->prepare("
            UPDATE purchase_baskets 
            SET order_status = ?, order_status_updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$new_status, $basket_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'تم تحديث حالة الطلب بنجاح',
            'basket_id' => $basket_id,
            'new_status' => $new_status
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
