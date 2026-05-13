<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $order_id = intval($_POST['order_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    
    if (!$order_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit();
    }
    
    try {
        $stmt = $db->prepare("
            UPDATE customer_orders
            SET review_status = 'reviewed',
                reviewed_at = NOW(),
                reviewed_by = ?,
                review_note = ?
            WHERE id = ?
        ");
        
        $stmt->execute([$_SESSION['user_id'], $note, $order_id]);
        
        // الحصول على العدادات المحدثة
        $stats = $db->query("
            SELECT 
                COUNT(DISTINCT CASE WHEN review_status = 'reviewed' THEN id END) as reviewed,
                COUNT(DISTINCT CASE WHEN review_status = 'pending' THEN id END) as pending
            FROM customer_orders
        ")->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'تمت المراجعة بنجاح',
            'reviewed_count' => intval($stats['reviewed']),
            'pending_count' => intval($stats['pending'])
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
