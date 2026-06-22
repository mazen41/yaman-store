<?php
/**
 * GET /api/sorting-notifications.php?after_id=0
 * Returns sorting notifications newer than after_id for the mobile app.
 */

require_once __DIR__ . '/api_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام GET.', 405);
}

authenticateRequest($db);
$afterId = max(0, (int)($_GET['after_id'] ?? 0));
$limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

try {
    $unreadStmt = $db->prepare("SELECT COUNT(*) FROM sorting_notifications WHERE COALESCE(is_read, 0) = 0");
    $unreadStmt->execute();
    $unreadCount = (int)$unreadStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT sn.id,
               sn.order_id,
               sn.item_id,
               sn.type,
               sn.message,
               sn.is_read,
               sn.created_at,
               co.order_number,
               oi.shein_sku AS sku,
               u.full_name AS created_by_name,
               c.name AS customer_name
        FROM sorting_notifications sn
        LEFT JOIN customer_orders co ON co.id = sn.order_id
        LEFT JOIN order_items oi ON oi.id = sn.item_id
        LEFT JOIN users u ON u.id = sn.created_by
        LEFT JOIN customers c ON c.id = co.customer_id
        WHERE sn.id > ?
        ORDER BY sn.id ASC
        LIMIT {$limit}
    ");
    $stmt->execute([$afterId]);
    $notifications = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'order_id' => (int)$row['order_id'],
            'item_id' => $row['item_id'] === null ? null : (int)$row['item_id'],
            'type' => (string)$row['type'],
            'message' => (string)$row['message'],
            'is_read' => (int)$row['is_read'],
            'created_at' => (string)$row['created_at'],
            'order_number' => (string)($row['order_number'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'created_by_name' => (string)($row['created_by_name'] ?? ''),
            'customer_name' => (string)($row['customer_name'] ?? ''),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    ok([
        'notifications' => $notifications,
        'last_id' => empty($notifications) ? $afterId : (int)end($notifications)['id'],
        'unread_count' => $unreadCount,
    ]);
} catch (PDOException $e) {
    fail('حدث خطأ أثناء جلب إشعارات الفرز: ' . $e->getMessage(), 500);
}
