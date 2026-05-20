<?php
/**
 * api/sku-lookup.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: GET /api/sku-lookup.php?sku={sku}
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

if ($rawSku === '') {
    fail('الرجاء إرسال رمز SKU للبحث عنه.', 400);
}

// Normalize SKU (using same function as in sorting/api.php)
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
    // It runs ALTER TABLE statements on every request which causes timeouts.
    // Schema is expected to be created during initial setup / migrations.

    $productStmt = $db->prepare("
        SELECT * FROM shein_products 
        WHERE shein_sku COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci 
        LIMIT 1
    ");
    $productStmt->execute([$sku]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    // If product details aren't stored, try finding online via serpapi if available
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

    // ── Find matching order items ──────────────────────────────────────────────────────
    $stmt = $db->prepare("
        SELECT oi.id AS item_id, 
               oi.order_id, 
               oi.status, 
               co.order_number,
               c.name AS customer_name, 
               COALESCE(c.mobile_number, c.mobile, c.phone, '') AS customer_mobile
        FROM order_items oi
        JOIN customer_orders co ON co.id = oi.order_id
        LEFT JOIN customers c ON c.id = co.customer_id
        WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(oi.shein_sku), '-', ''), ' ', ''), CHAR(9), '')) = ?
        ORDER BY CASE WHEN oi.status = 'pending' THEN 0 ELSE 1 END, oi.id ASC
    ");
    $stmt->execute([$sku]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast IDs to integers
    $matches = array_map(static function (array $row): array {
        $row['item_id'] = (int) $row['item_id'];
        $row['order_id'] = (int) $row['order_id'];
        return $row;
    }, $matches);

    ok([
        'sku' => $sku,
        'matches' => $matches,
        'product' => $product ?: [
            'shein_sku' => $sku,
            'name' => '',
            'image' => '',
            'link' => '',
            'price' => ''
        ]
    ]);

} catch (PDOException $e) {
    fail('حدث خطأ في قاعدة البيانات أثناء البحث عن الرمز: ' . $e->getMessage(), 500);
}
