<?php
require_once __DIR__ . '/api_helper.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام POST.', 405);
}
authenticateRequest($db);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$purchaseGroupId = (int)($body['purchase_group_id'] ?? 0);
if ($purchaseGroupId <= 0) fail('purchase_group_id غير صالح', 400);
try {
    $countStmt = $db->prepare("SELECT COUNT(*) AS total_pending FROM order_items oi JOIN customer_orders co ON co.id = oi.order_id LEFT JOIN purchase_baskets pb ON pb.id = co.basket_id WHERE oi.status != 'scanned' AND COALESCE(co.purchase_group_id, pb.purchase_group_id) = ?");
    $countStmt->execute([$purchaseGroupId]);
    $before = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total_pending'] ?? 0);

    $updateStmt = $db->prepare("UPDATE order_items oi JOIN customer_orders co ON co.id = oi.order_id LEFT JOIN purchase_baskets pb ON pb.id = co.basket_id SET oi.status = 'scanned', oi.updated_at = NOW() WHERE oi.status != 'scanned' AND COALESCE(co.purchase_group_id, pb.purchase_group_id) = ?");
    $updateStmt->execute([$purchaseGroupId]);

    ok([
        'success' => true,
        'sorted_items' => (int)$updateStmt->rowCount(),
        'pending_before' => $before,
        'message' => 'تم فرز جميع منتجات مجموعة الشراء المحددة بنجاح',
    ]);
} catch (Throwable $e) {
    fail('فشل فرز المجموعة: ' . $e->getMessage(), 500);
}
