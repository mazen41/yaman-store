<?php
/**
 * api/orders.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: GET /api/orders.php
 * Fetch active orders and items for caching (full or incremental sync).
 * Authenticated via Authorization: Bearer <token>.
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/api_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام GET.', 405);
}

// Authenticate request
$user = authenticateRequest($db);

$updatedAfter = trim($_GET['updated_after'] ?? '');

try {
    $orders = [];
    $items = [];
    
    if (empty($updatedAfter)) {
        // ── Full Sync ─────────────────────────────────────────────────
        // Fetch all active orders (exclude cancelled, delivered, etc.)
        $ordersStmt = $db->query("
            SELECT co.id          AS order_id,
                   co.order_number,
                   c.name         AS customer_name,
                   COALESCE(c.mobile_number, c.mobile, c.phone, '') AS customer_mobile,
                   co.status,
                   UNIX_TIMESTAMP(co.updated_at) AS updated_at
            FROM   customer_orders co
            LEFT JOIN customers c ON c.id = co.customer_id
            WHERE  co.status NOT IN ('cancelled', 'delivered', 'returned', 'refunded')
            ORDER  BY co.id DESC
            LIMIT  1000
        ");
        $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // ── Incremental Sync ──────────────────────────────────────────
        // Parse updated_after (can be UNIX timestamp or ISO/Datetime string)
        if (is_numeric($updatedAfter)) {
            $updatedAfterTime = date('Y-m-d H:i:s', (int)$updatedAfter);
        } else {
            $updatedAfterTime = date('Y-m-d H:i:s', strtotime($updatedAfter));
        }

        // Fetch all orders modified since updated_after (including cancelled/delivered so mobile can delete them)
        $ordersStmt = $db->prepare("
            SELECT co.id          AS order_id,
                   co.order_number,
                   c.name         AS customer_name,
                   COALESCE(c.mobile_number, c.mobile, c.phone, '') AS customer_mobile,
                   co.status,
                   UNIX_TIMESTAMP(co.updated_at) AS updated_at
            FROM   customer_orders co
            LEFT JOIN customers c ON c.id = co.customer_id
            WHERE  co.updated_at >= ?
            ORDER  BY co.id DESC
            LIMIT  1000
        ");
        $ordersStmt->execute([$updatedAfterTime]);
        $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty($orders)) {
        $orderIds = array_column($orders, 'order_id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        $itemsStmt = $db->prepare("
            SELECT oi.id                                                   AS item_id,
                   oi.order_id,
                   oi.shein_sku                                            AS sku,
                   CASE WHEN oi.status = 'scanned' THEN 1 ELSE 0 END      AS is_sorted,
                   COALESCE(sp.name, oi.product_name, '')                  AS product_name,
                   COALESCE(sp.image, '')                                  AS product_image
            FROM   order_items oi
            LEFT JOIN shein_products sp ON sp.shein_sku COLLATE utf8mb4_unicode_ci = oi.shein_sku COLLATE utf8mb4_unicode_ci
            WHERE  oi.order_id IN ($placeholders)
              AND  oi.shein_sku IS NOT NULL
              AND  oi.shein_sku <> ''
            ORDER  BY oi.order_id ASC, oi.id ASC
        ");
        $itemsStmt->execute($orderIds);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast types to ensure Flutter JSON parsing matches expected types
        $orders = array_map(static function (array $row): array {
            $row['order_id'] = (int) $row['order_id'];
            $row['updated_at'] = (int) $row['updated_at'];
            return $row;
        }, $orders);

        $items = array_map(static function (array $row): array {
            $row['item_id'] = (int) $row['item_id'];
            $row['order_id'] = (int) $row['order_id'];
            $row['is_sorted'] = (int) $row['is_sorted'];
            return $row;
        }, $items);
    }

    ok([
        'orders' => $orders,
        'items' => $items,
        'sync_timestamp' => time()
    ]);

} catch (PDOException $e) {
    fail('حدث خطأ في قاعدة البيانات أثناء مزامنة الطلبات: ' . $e->getMessage(), 500);
}
