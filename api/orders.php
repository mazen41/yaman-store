<?php
/**
 * api/orders.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: GET /api/orders.php
 * Fetch ALL orders (no status filtering) for scanner app caching.
 * Supports full sync (no param) and incremental sync (?updated_after=UNIX).
 * Authenticated via Authorization: Bearer <token>.
 *
 * purchase_group_id / purchase_group_number resolved via:
 *   1. co.purchase_group_id  (order assigned directly to a group)
 *   2. pb.purchase_group_id  (order's basket belongs to a group — fallback)
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/api_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام GET.', 405);
}

$user = authenticateRequest($db);

$updatedAfter = trim($_GET['updated_after'] ?? '');

// Shared SELECT fragment used by both full-sync and incremental-sync queries.
// Resolves purchase group via direct assignment OR via basket membership.
$orderSelectSql = "
    SELECT
        co.id                                                                    AS order_id,
        co.order_number,
        c.name                                                                   AS customer_name,
        COALESCE(c.mobile_number, c.phone, '')                                   AS customer_mobile,
        co.status,
        co.sorting_status,
        COALESCE(co.purchase_group_id, pb.purchase_group_id)                     AS purchase_group_id,
        COALESCE(pg.group_number, '')                                             AS purchase_group_number,
        (SELECT COUNT(*)
           FROM order_items oi_count
          WHERE oi_count.order_id = co.id
            AND oi_count.shein_sku IS NOT NULL
            AND oi_count.shein_sku <> '')                                         AS total_skus,
        UNIX_TIMESTAMP(co.updated_at)                                            AS updated_at
    FROM   customer_orders co
    LEFT JOIN customers        c   ON c.id  = co.customer_id
    LEFT JOIN purchase_baskets pb  ON pb.id = co.basket_id
    LEFT JOIN purchase_groups  pg  ON pg.id = COALESCE(co.purchase_group_id, pb.purchase_group_id)
";

try {
    $orders      = [];
    $items       = [];
    $totalOrders = 0;

    if (empty($updatedAfter)) {
        // ── Full Sync ────────────────────────────────────────────────────────
        $countStmt = $db->query("SELECT COUNT(*) FROM customer_orders");
        $totalOrders = (int)$countStmt->fetchColumn();

        $ordersStmt = $db->query($orderSelectSql . " ORDER BY co.id DESC");
        $orders     = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // ── Incremental Sync ─────────────────────────────────────────────────
        if (is_numeric($updatedAfter)) {
            $updatedAfterTime = date('Y-m-d H:i:s', (int)$updatedAfter);
        } else {
            $updatedAfterTime = date('Y-m-d H:i:s', strtotime($updatedAfter));
        }

        $ordersStmt = $db->prepare($orderSelectSql . " WHERE co.updated_at >= ? ORDER BY co.id DESC");
        $ordersStmt->execute([$updatedAfterTime]);
        $orders      = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalOrders = count($orders);
    }

    if (!empty($orders)) {
        $orderIds = array_column($orders, 'order_id');

        // Chunk to avoid SQL "too many placeholders" on large datasets
        $chunkSize = 500;
        $allItems  = [];

        foreach (array_chunk($orderIds, $chunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $itemsStmt = $db->prepare("
                SELECT
                    oi.id                                                                   AS item_id,
                    oi.order_id,
                    oi.shein_sku                                                            AS sku,
                    oi.status                                                               AS item_status,
                    CASE WHEN oi.status = 'scanned' OR oi.sorted_at IS NOT NULL THEN 1 ELSE 0 END AS is_sorted,
                    COALESCE(sp.name, oi.product_name, '')                                  AS product_name,
                    COALESCE(sp.image, '')                                                  AS product_image
                FROM   order_items oi
                LEFT JOIN shein_products sp
                       ON sp.shein_sku COLLATE utf8mb4_unicode_ci
                        = oi.shein_sku  COLLATE utf8mb4_unicode_ci
                WHERE  oi.order_id IN ($placeholders)
                  AND  oi.shein_sku IS NOT NULL
                  AND  oi.shein_sku <> ''
                ORDER  BY oi.order_id ASC, oi.id ASC
            ");
            $itemsStmt->execute($chunk);
            $allItems = array_merge($allItems, $itemsStmt->fetchAll(PDO::FETCH_ASSOC));
        }
        $items = $allItems;

        // Cast types for reliable Flutter JSON parsing
        $orders = array_map(static function (array $row): array {
            $row['order_id']              = (int)   $row['order_id'];
            $row['updated_at']            = (int)   $row['updated_at'];
            $row['total_skus']            = (int)  ($row['total_skus']            ?? 0);
            $row['purchase_group_id']     = (int)  ($row['purchase_group_id']     ?? 0);
            $row['purchase_group_number'] = (string)($row['purchase_group_number'] ?? '');
            $row['sorting_status']        = $row['sorting_status'] ?? 'not_started';
            return $row;
        }, $orders);

        $items = array_map(static function (array $row): array {
            $row['item_id']  = (int) $row['item_id'];
            $row['order_id'] = (int) $row['order_id'];
            $row['is_sorted'] = (int) $row['is_sorted'];
            return $row;
        }, $items);
    }

    ok([
        'total_orders'   => $totalOrders,
        'orders'         => $orders,
        'items'          => $items,
        'sync_timestamp' => time(),
    ]);

} catch (PDOException $e) {
    fail('حدث خطأ في قاعدة البيانات أثناء مزامنة الطلبات: ' . $e->getMessage(), 500);
}
