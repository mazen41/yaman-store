<?php
/** POST /api/undo-sort.php {item_id:int} */
require_once __DIR__ . '/api_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام POST.', 405);
}

authenticateRequest($db);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$itemId = (int)($body['item_id'] ?? 0);
if ($itemId <= 0) fail('item_id غير صالح', 400);

try {
    $stmt = $db->prepare('SELECT id, order_id FROM order_items WHERE id = ? LIMIT 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) fail('العنصر غير موجود', 404);

    $db->prepare("UPDATE order_items SET status = 'pending', sorted_by = NULL, sorted_at = NULL, updated_at = NOW() WHERE id = ?")
       ->execute([$itemId]);

    ok([
        'message' => 'تم إلغاء الفرز وإرجاع المنتج إلى قيد الانتظار',
        'item_id' => $itemId,
        'order_id' => (int)$item['order_id'],
    ]);
} catch (PDOException $e) {
    fail('فشل إلغاء الفرز: ' . $e->getMessage(), 500);
}
