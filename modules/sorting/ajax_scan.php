<?php
/**
 * sorting/ajax_scan.php
 *
 * Pure-PHP AJAX endpoint for the sorting page.
 * Replaces the Node.js / .bat scraper entirely.
 *
 * POST params:
 *   action     = "scan" | "unscan" | "next_pending"
 *   scan_input = raw SKU / URL string  (for action=scan)
 *   item_id    = order_items.id        (for action=unscan)
 *   order_id   = customer_orders.id    (for action=next_pending)
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/shein_helpers.php';
require_once '../../includes/sorting_status_helpers.php';
require_once '../../includes/serpapi_lookup.php';

header('Content-Type: application/json; charset=utf-8');

$user_id = $_SESSION['user_id'] ?? 0;
if (!hasPermission($user_id, 'orders', 'view') && !hasPermission($user_id, 'orders', 'edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية']);
    exit();
}

sheinEnsureSchema($db);

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    switch ($action) {
        case 'scan':
            handle_scan($db);
            break;
        case 'unscan':
            handle_unscan($db);
            break;
        case 'next_pending':
            handle_next_pending($db);
            break;
        default:
            throw new InvalidArgumentException("إجراء غير معروف: $action");
    }
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
exit();

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: SCAN
// ═══════════════════════════════════════════════════════════════════════════
function handle_scan(PDO $db): void
{
    $rawInput = trim($_POST['scan_input'] ?? '');
    if ($rawInput === '') throw new InvalidArgumentException('يرجى إدخال SKU أو رابط صالح');

    // ── Resolve SKU ─────────────────────────────────────────────────────────
    $sku = '';
    if (strpos($rawInput, 'shein.com') !== false || filter_var($rawInput, FILTER_VALIDATE_URL)) {
        $parsed = parse_url($rawInput);
        parse_str($parsed['query'] ?? '', $qs);
        if (!empty($qs['shein_sku'])) {
            $sku = sheinNormalizeSku($qs['shein_sku']);
        }
        if ($sku === '' && preg_match('/[?&]shein_sku=([A-Za-z0-9_-]+)/i', $rawInput, $m)) {
            $sku = sheinNormalizeSku($m[1]);
        }
        if ($sku === '') {
            $sku = sheinNormalizeSku($rawInput);
        }
    } else {
        $sku = sheinNormalizeSku($rawInput);
    }

    if ($sku === '') throw new InvalidArgumentException('تعذر استخراج SKU من: ' . htmlspecialchars($rawInput));

    // ── Lookup or create product in DB ───────────────────────────────────────
    sheinEnsureSchema($db);
    $productStmt = $db->prepare("SELECT * FROM shein_products WHERE shein_sku = ? LIMIT 1");
    $productStmt->execute([$sku]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product || empty($product['name'])) {
        // Hit SerpAPI (pure PHP — no Node.js)
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

    // ── Find order item ──────────────────────────────────────────────────────
    $item = find_order_item($db, $sku);

    if (!$item) {
        $msg = $product
            ? 'المنتج موجود لكن لا يوجد طلب مرتبط بهذا SKU'
            : 'لم يتم العثور على طلب بـ SKU: ' . $sku;
        throw new RuntimeException($msg);
    }

    // ── Update item status ───────────────────────────────────────────────────
    $alreadyScanned = isProductSorted($item);
    if (!$alreadyScanned) {
        $db->prepare("UPDATE order_items SET status = 'scanned', updated_at = NOW() WHERE id = ?")
           ->execute([$item['id']]);
        $item['status'] = 'scanned';
    }

    // ── Auto-complete order if all items scanned ─────────────────────────────
    $counts     = get_order_counts($db, $item['order_id']);
    $allDone    = ($counts['total_items'] > 0 && $counts['scanned_items'] >= $counts['total_items']);
    $groupInfo  = get_group_info($db, $item['order_id']);
    $allItems   = get_all_items($db, $item['order_id']);
    $orderImages = get_order_images($db, $item['order_id']);

    // Enrich product name from shein_products if item name is generic
    if ($product && !empty($product['name']) && (
        empty($item['product_name']) || stripos($item['product_name'], 'SHEIN SKU') === 0
    )) {
        $item['product_name'] = $product['name'];
    }

    // ── Next pending item ────────────────────────────────────────────────────
    $nextItem = get_next_pending($db, $item['order_id'], $item['id']);

    echo json_encode([
        'success'         => true,
        'already_scanned' => $alreadyScanned,
        'all_done'        => $allDone,
        'message'         => $alreadyScanned
            ? 'تنبيه: هذا المنتج مفروز مسبقاً'
            : 'تم العثور على المنتج وتحديث حالته إلى مفروز ✅',
        'product'         => $product ?: ['shein_sku' => $sku, 'name' => '', 'image' => '', 'link' => ''],
        'item'            => $item,
        'all_items'       => $allItems,
        'order_images'    => $orderImages,
        'counts'          => $counts,
        'group_info'      => $groupInfo,
        'next_item'       => $nextItem,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: UNSCAN (revert scanned → pending)
// ═══════════════════════════════════════════════════════════════════════════
function handle_unscan(PDO $db): void
{
    $itemId = (int) ($_POST['item_id'] ?? 0);
    if ($itemId <= 0) throw new InvalidArgumentException('item_id غير صالح');

    $stmt = $db->prepare("SELECT id, order_id FROM order_items WHERE id = ? LIMIT 1");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('العنصر غير موجود');

    $db->prepare("UPDATE order_items SET status = 'pending', updated_at = NOW() WHERE id = ?")->execute([$itemId]);

    $counts = get_order_counts($db, $row['order_id']);
    echo json_encode(['success' => true, 'message' => 'تم إرجاع الحالة إلى قيد الانتظار', 'counts' => $counts], JSON_UNESCAPED_UNICODE);
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTION: NEXT PENDING — get next unsorted item in same order
// ═══════════════════════════════════════════════════════════════════════════
function handle_next_pending(PDO $db): void
{
    $orderId = (int) ($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($orderId <= 0) throw new InvalidArgumentException('order_id غير صالح');

    $next = get_next_pending($db, $orderId, 0);
    echo json_encode(['success' => true, 'next_item' => $next], JSON_UNESCAPED_UNICODE);
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════

function find_order_item(PDO $db, string $sku): ?array
{
    $stmt = $db->prepare("
        SELECT
            oi.*,
            co.order_number, co.id AS order_id,
            co.customer_id, co.subtotal_amount, co.discount_amount,
            co.total_amount, co.final_amount, co.shipping_cost,
            co.status AS order_status, co.notes AS order_notes,
            co.created_at AS order_date, co.currency, co.order_link,
            co.purchase_group_id,
            c.name AS customer_name,
            c.mobile_number AS customer_mobile,
            c.whatsapp_number AS customer_whatsapp
        FROM order_items oi
        JOIN customer_orders co ON co.id = oi.order_id
        LEFT JOIN customers c ON c.id = co.customer_id
        WHERE oi.shein_sku = ?
        ORDER BY
            CASE WHEN oi.status = 'pending' THEN 0 ELSE 1 END,
            oi.id ASC
        LIMIT 1
    ");
    $stmt->execute([$sku]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function get_order_counts(PDO $db, int $orderId): array
{
    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS total_items,
            SUM(CASE WHEN status = 'scanned' THEN 1 ELSE 0 END) AS scanned_items
        FROM order_items
        WHERE order_id = ?
    ");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'total_items'   => (int) ($row['total_items']   ?? 0),
        'scanned_items' => (int) ($row['scanned_items'] ?? 0),
    ];
}

function get_group_info(PDO $db, int $orderId): ?array
{
    try {
        $stmt = $db->prepare("
            SELECT pg.id, pg.group_name, pg.group_number
            FROM customer_orders co
            LEFT JOIN purchase_baskets pb ON pb.id = co.basket_id
            LEFT JOIN purchase_groups pg ON pg.id = COALESCE(co.purchase_group_id, pb.purchase_group_id)
            WHERE co.id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['id'])) return $row;
    } catch (PDOException $e) { /* table may not exist */ }
    return null;
}

function get_all_items(PDO $db, int $orderId): array
{
    $stmt = $db->prepare("
        SELECT oi.id, oi.product_name, oi.shein_sku, oi.quantity,
               oi.unit_price, oi.total_price, oi.status, oi.product_link,
               sp.name AS sp_name, sp.image AS sp_image
        FROM order_items oi
        LEFT JOIN shein_products sp ON sp.shein_sku = oi.shein_sku
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_order_images(PDO $db, int $orderId): array
{
    try {
        $stmt = $db->prepare("SELECT image_path, image_name FROM order_images WHERE order_id = ? ORDER BY display_order ASC");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return []; }
}

function get_next_pending(PDO $db, int $orderId, int $skipItemId): ?array
{
    $stmt = $db->prepare("
        SELECT oi.id, oi.shein_sku, oi.product_name, oi.status,
               sp.name AS sp_name, sp.image AS sp_image
        FROM order_items oi
        LEFT JOIN shein_products sp ON sp.shein_sku = oi.shein_sku
        WHERE oi.order_id = ?
          AND oi.status != 'scanned'
          AND oi.shein_sku IS NOT NULL
          AND oi.shein_sku <> ''
          AND oi.id != ?
        ORDER BY oi.id ASC
        LIMIT 1
    ");
    $stmt->execute([$orderId, $skipItemId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
