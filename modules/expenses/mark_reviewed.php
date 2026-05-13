<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expense_id = $_POST['expense_id'] ?? null;
    $review_note = $_POST['review_note'] ?? '';
    
    if (!$expense_id) {
        echo json_encode(['success' => false, 'message' => 'معرف المصروف مطلوب']);
        exit();
    }
    
    try {
        // First, check if review columns exist, if not add them
        $check_columns = $db->query("SHOW COLUMNS FROM expenses LIKE 'review_status'");
        if ($check_columns->rowCount() == 0) {
            $db->exec("
                ALTER TABLE expenses 
                ADD COLUMN review_status ENUM('pending', 'reviewed') DEFAULT 'pending' AFTER payment_status,
                ADD COLUMN reviewed_by INT NULL AFTER review_status,
                ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
                ADD COLUMN review_note TEXT NULL AFTER reviewed_at
            ");
        }
        
        // Update the expense
        $stmt = $db->prepare("
            UPDATE expenses 
            SET review_status = 'reviewed',
                reviewed_by = ?,
                reviewed_at = NOW(),
                review_note = ?
            WHERE id = ?
        ");
        
        $stmt->execute([$_SESSION['user_id'], $review_note, $expense_id]);
        
        echo json_encode(['success' => true, 'message' => 'تمت المراجعة بنجاح']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'خطأ: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صحيحة']);
}
