<?php
require_once '../../config/database.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (isset($_GET['card_number'])) {
    $cardNumber = trim($_GET['card_number']);

    try {
        $stmt = $db->prepare("SELECT id, card_number, current_balance, customer_id, status FROM customer_cards WHERE card_number = ? AND status = 'active'");
        $stmt->execute([$cardNumber]);
        $card_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($card_data) {
            $response['success'] = true;
            $response['card'] = [
                'id' => $card_data['id'],
                'card_number' => $card_data['card_number'],
                'current_balance' => (float)$card_data['current_balance'],
                'customer_id' => $card_data['customer_id']
            ];
        } else {
            $response['message'] = 'البطاقة غير موجودة أو غير نشطة.';
        }
    } catch (PDOException $e) {
        $response['message'] = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
        error_log("Error in check_card_balance.php: " . $e->getMessage());
    }
} else {
    $response['message'] = 'لم يتم تقديم رقم البطاقة.';
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>