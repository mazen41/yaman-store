<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
require_once '../../config/database.php';

try {
    // Count unread sorting notifications for the navbar badge.
    $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM sorting_notifications WHERE COALESCE(is_read, 0) = 0");
    $countStmt->execute();
    $count = (int)$countStmt->fetchColumn();

    // Recent sorting notifications list (latest 20). Loaded by the dashboard only on page refresh.
    $listStmt = $db->prepare(
        "SELECT sn.id, sn.order_id, sn.item_id, sn.type, sn.message, sn.is_read, sn.created_at,
                o.order_number,
                oi.shein_sku AS sku,
                u.full_name AS created_by_name
         FROM sorting_notifications sn
         LEFT JOIN customer_orders o ON sn.order_id = o.id
         LEFT JOIN order_items oi ON sn.item_id = oi.id
         LEFT JOIN users u ON sn.created_by = u.id
         ORDER BY sn.created_at DESC, sn.id DESC
         LIMIT 20"
    );
    $listStmt->execute();
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Map rows to UI-friendly payload.
    $items = array_map(function($r) {
        $type = (string)($r['type'] ?? '');
        $title = $type === 'order_complete' ? 'اكتمال فرز الطلب' : ($type === 'item_sorted' ? 'فرز منتج' : 'إشعار فرز');
        $orderId = (int)($r['order_id'] ?? 0);
        $link = '/yassin-admin-system/modules/orders/view.php?id=' . urlencode((string)$orderId);

        return [
            'id' => (int)$r['id'],
            'order_id' => $orderId,
            'item_id' => $r['item_id'] === null ? null : (int)$r['item_id'],
            'order_number' => $r['order_number'],
            'sku' => $r['sku'],
            'type' => $type,
            'title' => $title,
            'message' => $r['message'],
            'is_read' => (int)($r['is_read'] ?? 0),
            'created_at' => $r['created_at'],
            'created_by_name' => $r['created_by_name'],
            'view_url' => $link,
        ];
    }, $rows ?: []);

    echo json_encode([
        'ok' => true,
        'count' => $count,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
