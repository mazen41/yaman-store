<?php
/**
 * modules/sorting/api.php
 * ─────────────────────────────────────────────────────────────────
 * REST API for the Yaman Sorting module.
 * Designed for offline-capable mobile apps.
 * Authentication: disabled for scanner/sync flows.
 *
 * Endpoints:
 *   POST /api.php?action=scan          – scan / sort an item
 *   POST /api.php?action=unscan        – revert item to pending
 *   GET  /api.php?action=pending       – list pending items (for a given order or all)
 *   GET  /api.php?action=stats         – today's session stats
 *   GET  /api.php?action=ping          – health-check
 *   GET  /api.php?action=sync_orders   – download all pending orders + items for offline cache
 * ─────────────────────────────────────────────────────────────────
 */

// ── CORS ──────────────────────────────────────────────────────────
// Allow your Expo / React-Native app to call this endpoint.
// Tighten origin in production if you like.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// ── Bootstrap ─────────────────────────────────────────────────────
require_once '../../config/database.php';
require_once '../../includes/shein_helpers.php';
require_once '../../includes/sorting_status_helpers.php';
require_once '../../includes/serpapi_lookup.php';

// ── Auth disabled ─────────────────────────────────────────────────
$userId = 0;

// ── Route ─────────────────────────────────────────────────────────
$action = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    switch ($action) {
        case 'ping':
            ok(['status' => 'ok', 'server_time' => date('c')]);
            break;
        case 'scan':
            handleScan($db, $userId);
            break;
        case 'unscan':
            handleUnscan($db);
            break;
        case 'pending':
            handlePending($db);
            break;
        case 'stats':
            handleStats($db, $userId);
            break;
        case 'sync_orders':
            handleSyncOrders($db);
            break;
        default:
            fail('Unknown action: ' . htmlspecialchars($action), 400);
    }
} catch (Throwable $e) {
    fail($e->getMessage(), 422);
}
exit();

// ═══════════════════════════════════════════════════════════════════
// RESPONSE HELPERS
// ═══════════════════════════════════════════════════════════════════

function ok(array $data): void
{
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit();
}

function fail(string $message, int $code = 422): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit();
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: SCAN
// ═══════════════════════════════════════════════════════════════════

function handleScan(PDO $db, int $userId): void
{
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $rawInput       = trim($body['scan_input']      ?? $_POST['scan_input']      ?? '');
    $selectedItemId = (int) ($body['selected_item_id'] ?? $_POST['selected_item_id'] ?? 0);

    if ($rawInput === '') fail('scan_input is required', 400);

    // ── Normalise SKU ────────────────────────────────────────────────
    if (strpos($rawInput, 'shein.com') !== false || filter_var($rawInput, FILTER_VALIDATE_URL)) {
        parse_str(parse_url($rawInput, PHP_URL_QUERY) ?? '', $qs);
        $sku = sheinNormalizeSku($qs['shein_sku'] ?? $rawInput);
    } else {
        $sku = sheinNormalizeSku($rawInput);
    }
    if ($sku === '') fail('Could not extract a valid SKU from: ' . htmlspecialchars($rawInput));

    // ── Ensure product row exists ────────────────────────────────────
    sheinEnsureSchema($db);
    $productStmt = $db->prepare(
        "SELECT * FROM shein_products WHERE shein_sku COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci LIMIT 1"
    );
    $productStmt->execute([$sku]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product || empty($product['name'])) {
        $apiData = serpapi_find_product($sku);
        if ($apiData) {
            sheinFindOrCreateProduct($db, [
                'shein_sku' => $apiData['sku']   ?? $sku,
                'name'      => $apiData['title'] ?? '',
                'image'     => $apiData['image'] ?? '',
                'link'      => $apiData['url']   ?? '',
                'price'     => $apiData['price'] ?? '',
            ]);
            $productStmt->execute([$sku]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    // ── Find order item(s) ───────────────────────────────────────────
    $matches = findOrderItemsBySku($db, $sku);

    if (count($matches) > 1 && $selectedItemId <= 0) {
        ok([
            'requires_selection' => true,
            'sku'                => $sku,
            'message'            => 'SKU found in multiple orders – please select one',
            'matches'            => array_map(static fn($it) => [
                'item_id'         => (int) $it['id'],
                'order_id'        => (int) $it['order_id'],
                'order_number'    => $it['order_number'] ?? ('#' . $it['order_id']),
                'customer_name'   => $it['customer_name']   ?? '',
                'customer_mobile' => $it['customer_mobile'] ?? '',
                'status'          => $it['status']          ?? '',
            ], $matches),
        ]);
    }

    $item = findOrderItem($db, $sku, $selectedItemId);
    if (!$item) {
        fail($product ? 'Product found but no order contains this SKU' : 'No order found for SKU: ' . $sku);
    }

    $alreadyScanned = isProductSorted($item);
    if (!$alreadyScanned) {
        $db->prepare("UPDATE order_items SET status = 'scanned', updated_at = NOW() WHERE id = ?")
           ->execute([$item['id']]);
        $item['status'] = 'scanned';

        // Log the scan for stats
        try {
            $db->prepare(
                "INSERT INTO sorting_scans (user_id, item_id, order_id, scanned_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE scanned_at = NOW()"
            )->execute([$userId, $item['id'], $item['order_id']]);
        } catch (PDOException $e) { /* table may not exist */ }
    }

    $counts    = getOrderCounts($db, $item['order_id']);
    $allDone   = ($counts['total_items'] > 0 && $counts['scanned_items'] >= $counts['total_items']);
    $allItems  = getAllItems($db, $item['order_id']);
    $nextItem  = getNextPending($db, $item['order_id'], $item['id']);
    $groupInfo = getGroupInfo($db, $item['order_id']);

    // Prefer richer product name
    if ($product && !empty($product['name']) && (
        empty($item['product_name']) || stripos($item['product_name'], 'SHEIN SKU') === 0
    )) {
        $item['product_name'] = $product['name'];
    }

    ok([
        'already_scanned' => $alreadyScanned,
        'all_done'        => $allDone,
        'message'         => $alreadyScanned
            ? 'Warning: item was already sorted'
            : 'Item sorted successfully ✅',
        'product'         => $product ?: ['shein_sku' => $sku, 'name' => '', 'image' => '', 'link' => ''],
        'item'            => $item,
        'all_items'       => $allItems,
        'counts'          => $counts,
        'group_info'      => $groupInfo,
        'next_item'       => $nextItem,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: UNSCAN
// ═══════════════════════════════════════════════════════════════════

function handleUnscan(PDO $db): void
{
    $body   = json_decode(file_get_contents('php://input'), true) ?: [];
    $itemId = (int) ($body['item_id'] ?? $_POST['item_id'] ?? 0);
    if ($itemId <= 0) fail('item_id is required', 400);

    $stmt = $db->prepare("SELECT id, order_id FROM order_items WHERE id = ? LIMIT 1");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Item not found');

    $db->prepare("UPDATE order_items SET status = 'pending', updated_at = NOW() WHERE id = ?")->execute([$itemId]);
    $counts = getOrderCounts($db, $row['order_id']);

    ok(['message' => 'Item reverted to pending', 'counts' => $counts]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: PENDING LIST
// ═══════════════════════════════════════════════════════════════════

function handlePending(PDO $db): void
{
    $orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
    $limit   = min(100, max(1, (int) ($_GET['limit'] ?? 50)));

    if ($orderId > 0) {
        $stmt = $db->prepare("
            SELECT oi.id, oi.shein_sku, oi.product_name, oi.quantity, oi.status,
                   sp.name AS sp_name, sp.image AS sp_image
            FROM order_items oi
            LEFT JOIN shein_products sp ON sp.shein_sku COLLATE utf8mb4_unicode_ci = oi.shein_sku COLLATE utf8mb4_unicode_ci
            WHERE oi.order_id = ? AND oi.status != 'scanned'
            ORDER BY oi.id ASC
            LIMIT ?
        ");
        $stmt->execute([$orderId, $limit]);
    } else {
        $stmt = $db->prepare("
            SELECT oi.id, oi.shein_sku, oi.product_name, oi.quantity, oi.status,
                   oi.order_id, co.order_number,
                   c.name AS customer_name,
                   sp.name AS sp_name, sp.image AS sp_image
            FROM order_items oi
            JOIN customer_orders co ON co.id = oi.order_id
            LEFT JOIN customers c ON c.id = co.customer_id
            LEFT JOIN shein_products sp ON sp.shein_sku COLLATE utf8mb4_unicode_ci = oi.shein_sku COLLATE utf8mb4_unicode_ci
            WHERE oi.status != 'scanned'
              AND oi.shein_sku IS NOT NULL AND oi.shein_sku <> ''
            ORDER BY oi.order_id ASC, oi.id ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
    }

    ok(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: SYNC ORDERS
// ═══════════════════════════════════════════════════════════════════


// ACTION: SYNC ORDERS (offline cache for mobile app)
// ═══════════════════════════════════════════════════════════════════
function handleSyncOrders(PDO $db): void
{
    // Fetch all active orders (exclude fully-delivered / cancelled)
    $ordersStmt = $db->query("
        SELECT co.id          AS order_id,
               co.order_number,
               c.name         AS customer_name,
               c.mobile_number AS customer_mobile,
               co.status
        FROM   customer_orders co
        LEFT JOIN customers c ON c.id = co.customer_id
        WHERE  co.status NOT IN ('cancelled', 'delivered')
        ORDER  BY co.id DESC
        LIMIT  1000
    ");
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    if (!empty($orders)) {
        $orderIds     = array_column($orders, 'order_id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $itemsStmt    = $db->prepare("
            SELECT id                                                   AS item_id,
                   order_id,
                   shein_sku                                            AS sku,
                   CASE WHEN status = 'scanned' THEN 1 ELSE 0 END      AS is_sorted
            FROM   order_items
            WHERE  order_id IN ($placeholders)
              AND  shein_sku IS NOT NULL
              AND  shein_sku <> ''
            ORDER  BY order_id ASC, id ASC
        ");
        $itemsStmt->execute($orderIds);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast types so Flutter JSON parsing is happy
        $items = array_map(static function (array $row): array {
            $row['item_id']   = (int) $row['item_id'];
            $row['order_id']  = (int) $row['order_id'];
            $row['is_sorted'] = (int) $row['is_sorted'];
            return $row;
        }, $items);

        $orders = array_map(static function (array $row): array {
            $row['order_id'] = (int) $row['order_id'];
            return $row;
        }, $orders);
    }

    ok([
        'orders' => $orders,
        'items'  => $items,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: STATS
// ═══════════════════════════════════════════════════════════════════

function handleStats(PDO $db, int $userId): void
{
    // Global today stats
    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_items,
            SUM(CASE WHEN oi.status = 'scanned' THEN 1 ELSE 0 END) AS scanned_items
        FROM order_items oi
        JOIN customer_orders co ON co.id = oi.order_id
        WHERE DATE(co.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->execute();
    $global = $stmt->fetch(PDO::FETCH_ASSOC);

    // User's scans today (from sorting_scans if available)
    $userToday = 0;
    try {
        $stmt2 = $db->prepare(
            "SELECT COUNT(*) AS cnt FROM sorting_scans WHERE user_id = ? AND DATE(scanned_at) = CURDATE()"
        );
        $stmt2->execute([$userId]);
        $userToday = (int) ($stmt2->fetchColumn() ?: 0);
    } catch (PDOException $e) {}

    ok([
        'global'      => $global,
        'user_today'  => $userToday,
        'server_time' => date('c'),
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// DB HELPERS (mirrors ajax_scan.php)
// ═══════════════════════════════════════════════════════════════════

function findOrderItem(PDO $db, string $sku, int $selectedItemId = 0): ?array
{
    $normalizedSku = normalizeSku($sku);
    $base = "
        SELECT oi.*,
               co.order_number, co.id AS order_id,
               co.subtotal_amount, co.discount_amount, co.total_amount,
               co.final_amount, co.shipping_cost,
               co.status AS order_status, co.notes AS order_notes,
               co.created_at AS order_date, co.currency,
               c.name AS customer_name,
               c.mobile_number AS customer_mobile
        FROM order_items oi
        JOIN customer_orders co ON co.id = oi.order_id
        LEFT JOIN customers c ON c.id = co.customer_id
    ";

    if ($selectedItemId > 0) {
        $stmt = $db->prepare($base . "
            WHERE oi.id = ?
              AND UPPER(REPLACE(REPLACE(REPLACE(TRIM(oi.shein_sku), '-', ''), ' ', ''), CHAR(9), '')) = ?
            LIMIT 1
        ");
        $stmt->execute([$selectedItemId, $normalizedSku]);
    } else {
        $stmt = $db->prepare($base . "
            WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(oi.shein_sku), '-', ''), ' ', ''), CHAR(9), '')) = ?
            ORDER BY CASE WHEN oi.status = 'pending' THEN 0 ELSE 1 END, oi.id ASC
            LIMIT 1
        ");
        $stmt->execute([$normalizedSku]);
    }
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function findOrderItemsBySku(PDO $db, string $sku): array
{
    $normalizedSku = normalizeSku($sku);
    $stmt = $db->prepare("
        SELECT oi.id, oi.order_id, oi.status, co.order_number,
               c.name AS customer_name, c.mobile_number AS customer_mobile
        FROM order_items oi
        JOIN customer_orders co ON co.id = oi.order_id
        LEFT JOIN customers c ON c.id = co.customer_id
        WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(oi.shein_sku), '-', ''), ' ', ''), CHAR(9), '')) = ?
        ORDER BY CASE WHEN oi.status = 'pending' THEN 0 ELSE 1 END, oi.id ASC
    ");
    $stmt->execute([$normalizedSku]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function normalizeSku(string $sku): string
{
    $sku = strtoupper(trim($sku));
    return preg_replace('/[\s\-\x{00A0}\x{200B}\x{200C}\x{200D}]+/u', '', $sku) ?? '';
}

function getOrderCounts(PDO $db, int $orderId): array
{
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total_items,
               SUM(CASE WHEN status = 'scanned' THEN 1 ELSE 0 END) AS scanned_items
        FROM order_items WHERE order_id = ?
    ");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'total_items'   => (int) ($row['total_items']   ?? 0),
        'scanned_items' => (int) ($row['scanned_items'] ?? 0),
    ];
}

function getAllItems(PDO $db, int $orderId): array
{
    $stmt = $db->prepare("
        SELECT oi.id, oi.product_name, oi.shein_sku, oi.quantity,
               oi.unit_price, oi.total_price, oi.status,
               sp.name AS sp_name, sp.image AS sp_image
        FROM order_items oi
        LEFT JOIN shein_products sp ON sp.shein_sku COLLATE utf8mb4_unicode_ci = oi.shein_sku COLLATE utf8mb4_unicode_ci
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getNextPending(PDO $db, int $orderId, int $skipItemId): ?array
{
    $stmt = $db->prepare("
        SELECT oi.id, oi.shein_sku, oi.product_name, oi.status,
               sp.name AS sp_name, sp.image AS sp_image
        FROM order_items oi
        LEFT JOIN shein_products sp ON sp.shein_sku COLLATE utf8mb4_unicode_ci = oi.shein_sku COLLATE utf8mb4_unicode_ci
        WHERE oi.order_id = ?
          AND oi.status != 'scanned'
          AND oi.shein_sku IS NOT NULL AND oi.shein_sku <> ''
          AND oi.id != ?
        ORDER BY oi.id ASC
        LIMIT 1
    ");
    $stmt->execute([$orderId, $skipItemId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getGroupInfo(PDO $db, int $orderId): ?array
{
    try {
        $stmt = $db->prepare("
            SELECT pg.id, pg.group_name, pg.group_number
            FROM customer_orders co
            LEFT JOIN purchase_baskets pb ON pb.id = co.basket_id
            LEFT JOIN purchase_groups pg ON pg.id = COALESCE(co.purchase_group_id, pb.purchase_group_id)
            WHERE co.id = ? LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['id'])) return $row;
    } catch (PDOException $e) {}
    return null;
}
