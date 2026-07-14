<?php
require_once __DIR__ . '/api_helper.php';
require_once __DIR__ . '/../includes/sorting_notifications_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام POST.', 405);
}
$user = authenticateRequest($db);
$userId = (int)$user['id'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$purchaseGroupId = (int)($body['purchase_group_id'] ?? 0);
if ($purchaseGroupId <= 0) fail('purchase_group_id غير صالح', 400);
try {
    $pendingStmt = $db->prepare("
        SELECT oi.id, oi.order_id, oi.shein_sku, oi.product_name, co.order_number
        FROM order_items oi
        JOIN customer_orders co ON co.id = oi.order_id
        WHERE oi.status != 'scanned'
          AND co.purchase_group_id = ?
        ORDER BY oi.order_id ASC, oi.id ASC
    ");
    $pendingStmt->execute([$purchaseGroupId]);
    $pendingItems = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
    $before = count($pendingItems);

    $updateStmt = $db->prepare("
        UPDATE order_items oi
        JOIN customer_orders co ON co.id = oi.order_id
        SET oi.status = 'scanned', oi.sorted_by = ?, oi.sorted_at = NOW(), oi.updated_at = NOW()
        WHERE oi.status != 'scanned'
          AND co.purchase_group_id = ?
    ");
    $updateStmt->execute([$userId, $purchaseGroupId]);

    $notifiedOrders = [];
    foreach ($pendingItems as $pendingItem) {
        createSortingItemNotification($db, $pendingItem, $userId);
        $notifiedOrders[(int)$pendingItem['order_id']] = $pendingItem;
    }

    foreach ($notifiedOrders as $orderId => $pendingItem) {
        $countsStmt = $db->prepare("
            SELECT COUNT(*) AS total_items,
                   SUM(CASE WHEN status = 'scanned' THEN 1 ELSE 0 END) AS scanned_items
            FROM order_items
            WHERE order_id = ?
        ");
        $countsStmt->execute([$orderId]);
        $counts = $countsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_items' => 0, 'scanned_items' => 0];
        if ((int)$counts['total_items'] > 0 && (int)$counts['scanned_items'] >= (int)$counts['total_items']) {
            createSortingOrderCompleteNotification($db, $pendingItem, $userId);
        }
    }

    ok([
        'success' => true,
        'sorted_items' => (int)$updateStmt->rowCount(),
        'pending_before' => $before,
        'message' => 'تم فرز جميع منتجات مجموعة الشراء المحددة بنجاح',
    ]);
} catch (Throwable $e) {
    fail('فشل فرز المجموعة: ' . $e->getMessage(), 500);
}
