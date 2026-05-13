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
    // Count notifications that need attention (pending or failed)
    $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM order_notifications WHERE status IN ('pending','failed')");
    $countStmt->execute();
    $count = (int)$countStmt->fetchColumn();

    // Recent notifications list (latest 10)
    $listStmt = $db->prepare(
        "SELECT n.id, n.order_id, n.notification_type, n.status, n.sent_to, n.created_at,
                o.order_number, o.status AS order_status,
                c.name AS customer_name
         FROM order_notifications n
         LEFT JOIN customer_orders o ON n.order_id = o.id
         LEFT JOIN customers c ON o.customer_id = c.id
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 10"
    );
    $listStmt->execute();
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Map rows to UI-friendly payload
    $items = array_map(function($r) {
        $title = '';
        if ($r['notification_type'] === 'email') {
            $title = 'بريد إلكتروني';
        } elseif ($r['notification_type'] === 'whatsapp') {
            $title = 'واتساب';
        } else {
            $title = 'إشعار';
        }

        $statusText = $r['status'] === 'sent' ? 'تم الإرسال' : ($r['status'] === 'failed' ? 'فشل الإرسال' : 'قيد الانتظار');
        $link = '/yassin-admin-system/modules/orders/view.php?id=' . urlencode($r['order_id']);
        $sendLink = '/yassin-admin-system/modules/orders/send.php?id=' . urlencode($r['order_id']) . '&method=' . urlencode($r['notification_type'] ?: 'email');

        return [
            'id' => (int)$r['id'],
            'order_id' => (int)$r['order_id'],
            'order_number' => $r['order_number'],
            'customer_name' => $r['customer_name'],
            'type' => $r['notification_type'],
            'title' => $title,
            'status' => $r['status'],
            'status_text' => $statusText,
            'sent_to' => $r['sent_to'],
            'created_at' => $r['created_at'],
            'view_url' => $link,
            'send_url' => $sendLink,
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
