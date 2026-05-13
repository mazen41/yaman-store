<?php
// modules/orders/api/check_card_balance.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../../config/database.php';

$cardNumber = trim($_GET['card_number'] ?? '');

if (empty($cardNumber)) {
    echo json_encode(['success' => false, 'message' => 'رقم البطاقة مطلوب.']);
    exit();
}

try {
    $stmt = $db->prepare("SELECT id, current_balance, customer_id FROM customer_cards WHERE card_number = ? AND status = 'active'");
    $stmt->execute([$cardNumber]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($card) {
        echo json_encode(['success' => true, 'card' => [
            'id' => $card['id'],
            'current_balance' => (float)$card['current_balance'],
            // 'customer_id' => $card['customer_id'] // You might return this for more client-side logic
        ]]);
    } else {
        echo json_encode(['success' => false, 'message' => 'البطاقة غير موجودة أو غير نشطة.']);
    }

} catch (PDOException $e) {
    error_log("Error checking card balance: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات.']);
}
?>