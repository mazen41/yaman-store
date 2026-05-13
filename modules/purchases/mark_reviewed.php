<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $basket_id = $_POST['basket_id'] ?? null;
    $review_note = $_POST['review_note'] ?? '';
    
    if (!$basket_id) {
        echo json_encode(['success' => false, 'message' => 'معرف السلة مطلوب']);
        exit();
    }
    
    try {
        // First, check if review columns exist, if not add them
        $check_columns = $db->query("SHOW COLUMNS FROM purchase_baskets LIKE 'review_status'");
        if ($check_columns->rowCount() == 0) {
            $db->exec("
                ALTER TABLE purchase_baskets 
                ADD COLUMN review_status ENUM('pending', 'reviewed') DEFAULT 'pending' AFTER status,
                ADD COLUMN reviewed_by INT NULL AFTER review_status,
                ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
                ADD COLUMN review_note TEXT NULL AFTER reviewed_at
            ");
        }
        
        // Update the basket
        $stmt = $db->prepare("
            UPDATE purchase_baskets 
            SET review_status = 'reviewed',
                reviewed_by = ?,
                reviewed_at = NOW(),
                review_note = ?
            WHERE id = ?
        ");
        
        $stmt->execute([$_SESSION['user_id'], $review_note, $basket_id]);
        
        echo json_encode(['success' => true, 'message' => 'تمت المراجعة بنجاح']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطأ: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صحيحة']);
}
