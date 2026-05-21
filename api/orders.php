<?php
/**
 * api/orders.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: GET /api/orders.php
 * Fetch ALL orders (no status filtering) for scanner app caching.
 * Supports full sync (no param) and incremental sync (?updated_after=UNIX).
 * Authenticated via Authorization: Bearer <token>.
 *
 * FIXED:
 *  - Was querying wrong table logic / filtering too many statuses
 *  - Now fetches ALL orders regardless of status (scanner needs them all)
 *  - Removed the 1000-row cap on full sync (uses batched chunking instead)
 *  - Added total_orders count to response
 *  - Fixed customer join (customer_orders has customer_id → customers.id)
 *  - Fixed SKU join: order_items.shein_sku → shein_products.shein_sku
 *  - Added sorting_status to order response so app can show "تم الفرز"
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
    $items  = [];
    $totalOrders = 0;

    if (empty($updatedAfter)) {
        // ── Full Sync ─────────────────────────────────────────────────
        // Fetch ALL orders — NO status filtering, NO artificial row limit.
        // The scanner must be able to look up ANY order by SKU.
        $countStmt = $db->query("SELECT COUNT(*) FROM customer_orders");
        $totalOrders = (int)$countStmt->fetchColumn();

        $ordersStmt = $db->query("
            SELECT
                co.id                                          AS order_id,
                co.order_number,
                c.name                                         AS customer_name,
                COALESCE(c.mobile_number, c.phone, '')         AS customer_mobile,
                co.status,
                co.sorting_status,
                UNIX_TIMESTAMP(co.updated_at)                  AS updated_at
            FROM   customer_orders co
            LEFT JOIN customers c ON c.id = co.customer_id
            ORDER  BY co.id DESC
        ");
        $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // ── Incremental Sync ──────────────────────────────────────────
        if (is_numeric($updatedAfter)) {
            $updatedAfterTime = date('Y-m-d H:i:s', (int)$updatedAfter);
        } else {
            $updatedAfterTime = date('Y-m-d H:i:s', strtotime($updatedAfter));
        }

        // Fetch ALL orders modified since updated_after (any status — app decides what to keep)
        $ordersStmt = $db->prepare("
            SELECT
                co.id                                          AS order_id,
                co.order_number,
                c.name                                         AS customer_name,
                COALESCE(c.mobile_number, c.phone, '')         AS customer_mobile,
                co.status,
                co.sorting_status,
                UNIX_TIMESTAMP(co.updated_at)                  AS updated_at
            FROM   customer_orders co
            LEFT JOIN customers c ON c.id = co.customer_id
            WHERE  co.updated_at >= ?
            ORDER  BY co.id DESC
        ");
        $ordersStmt->execute([$updatedAfterTime]);
        $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

        // For incremental sync, total_orders reflects the delta count
        $totalOrders = count($orders);
    }

    if (!empty($orders)) {
        $orderIds = array_column($orders, 'order_id');

        // Split into chunks to avoid SQL "too many placeholders" on large datasets
        $chunkSize  = 500;
        $allItems   = [];
        $chunks     = array_chunk($orderIds, $chunkSize);

        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $itemsStmt = $db->prepare("
                SELECT
                    oi.id                                                   AS item_id,
                    oi.order_id,
                    oi.shein_sku                                            AS sku,
                    CASE WHEN oi.status = 'scanned' THEN 1 ELSE 0 END      AS is_sorted,
                    COALESCE(sp.name, oi.product_name, '')                  AS product_name,
                    COALESCE(sp.image, '')                                  AS product_image
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
            $row['order_id']     = (int) $row['order_id'];
            $row['updated_at']   = (int) $row['updated_at'];
            $row['sorting_status'] = $row['sorting_status'] ?? 'not_started';
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
