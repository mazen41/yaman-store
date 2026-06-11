<?php
/**
 * api/sku-lookup.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: GET /api/sku-lookup.php?sku={sku}[&purchase_group_id={id}]
 * Performs an online SKU lookup in order items and shein products as a fallback.
 * Authenticated via Authorization: Bearer <token>.
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/api_helper.php';
require_once __DIR__ . '/../includes/shein_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام GET.', 405);
}

// Authenticate request
$user = authenticateRequest($db);

$rawSku = trim($_GET['sku'] ?? '');
$purchaseGroupId = (int)($_GET['purchase_group_id'] ?? 0);

if ($rawSku === '') {
    fail('الرجاء إرسال رمز SKU للبحث عنه.', 400);
}

// Normalize SKU
function localNormalizeSku(string $sku): string
{
    $sku = strtoupper(trim($sku));
    return preg_replace('/[\s\-\x{00A0}\x{200B}\x{200C}\x{200D}]+/u', '', $sku) ?? '';
}

$sku = localNormalizeSku($rawSku);

if (empty($sku)) {
    fail('رمز SKU غير صالح.', 400);
}

try {
    // NOTE: sheinEnsureSchema() intentionally removed from this hot path.
    $productStmt = $db->prepare("
        SELECT * FROM shein_products 
        WHERE shein_sku COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci 
        LIMIT 1
    ");
    $productStmt->execute([$sku]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if ((!$product || empty($product['name'])) && function_exists('serpapi_find_product')) {
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

    // ── Find matching order items ─────────────────────────────────────────
    // Resolves purchase group via:
    //   1. co.purchase_group_id  (order assigned directly to a group)
    //   2. pb.purchase_group_id  (order's basket belongs to a group — fallback)
    $stmt = $db->prepare("
        SELECT
            oi.id                                                                     AS item_id,
            oi.order_id,
            oi.status                                                                 AS item_status,
            CASE WHEN oi.status = 'scanned' OR oi.sorted_at IS NOT NULL THEN 1 ELSE 0 END AS is_sorted,
            co.order_number,
            c.name                                                                    AS customer_name,
            COALESCE(c.mobile_number, c.phone, '')                                    AS customer_mobile,
            (SELECT COUNT(*) FROM order_items oi2 WHERE oi2.order_id = co.id)         AS total_skus,
            COALESCE(co.purchase_group_id, pb.purchase_group_id)                      AS purchase_group_id,
            COALESCE(pg.group_number, '')                                              AS purchase_group_number,
            COALESCE(pg.group_name,   '')                                              AS purchase_group_name
        FROM order_items oi
        JOIN  customer_orders   co  ON co.id          = oi.order_id
        LEFT JOIN purchase_baskets  pb  ON pb.id          = co.basket_id
        LEFT JOIN purchase_groups   pg  ON pg.id          = COALESCE(co.purchase_group_id, pb.purchase_group_id)
        LEFT JOIN customers         c   ON c.id           = co.customer_id
        WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(oi.shein_sku), '-', ''), ' ', ''), CHAR(9), '')) = ?
          AND (? <= 0 OR COALESCE(co.purchase_group_id, pb.purchase_group_id) = ?)
        ORDER BY CASE WHEN oi.status = 'pending' THEN 0 ELSE 1 END, oi.id ASC
    ");
    $stmt->execute([$sku, $purchaseGroupId, $purchaseGroupId]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast types for reliable Flutter JSON parsing
    $matches = array_map(static function (array $row): array {
        $row['item_id']              = (int)  $row['item_id'];
        $row['order_id']             = (int)  $row['order_id'];
        $row['total_skus']           = (int) ($row['total_skus']         ?? 0);
        $row['is_sorted']            = (int) ($row['is_sorted']          ?? 0);
        $row['purchase_group_id']    = (int) ($row['purchase_group_id']  ?? 0);
        $row['purchase_group_number'] = (string)($row['purchase_group_number'] ?? '');
        $row['purchase_group_name']   = (string)($row['purchase_group_name']   ?? '');
        $row['status']               = $row['item_status'] ?? '';
        return $row;
    }, $matches);

    ok([
        'sku'                => $sku,
        'requires_selection' => count($matches) > 1,
        'matches'            => $matches,
        'success'            => true,
        'message'            => empty($matches) ? 'SKU not found' : 'SKU matched',
        'product'            => $product ?: [
            'shein_sku' => $sku,
            'name'      => '',
            'image'     => '',
            'link'      => '',
            'price'     => '',
        ],
    ]);

} catch (PDOException $e) {
    fail('حدث خطأ في قاعدة البيانات أثناء البحث عن الرمز: ' . $e->getMessage(), 500);
}
