<?php
/**
 * ajax/fetch_shein_product.php
 * Called manually when user clicks "جلب بيانات المنتج" button.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول'], JSON_UNESCAPED_UNICODE);
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/shein_helpers.php';

define('SERPAPI_SERVICE_URL', 'http://127.0.0.1:3579');

// ── Ensure schema + add updated_at if missing ─────────────────────────────────
function ensureSchemaFixed(PDO $db): void
{
    sheinEnsureSchema($db);
    // Add updated_at if the table existed before this column was introduced
    try {
        $db->exec("ALTER TABLE shein_products ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    } catch (PDOException $e) { /* already exists — ignore */ }
    // Add created_at too just in case
    try {
        $db->exec("ALTER TABLE shein_products ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    } catch (PDOException $e) { /* already exists — ignore */ }
}

// ── DB cache lookup (safe — only uses columns guaranteed to exist) ─────────────
function getCachedProduct(PDO $db, string $sku): ?array
{
    // Check which columns exist so we don't crash on older tables
    $cols = [];
    $res  = $db->query("SHOW COLUMNS FROM shein_products");
    foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $cols[] = $col['Field'];
    }

    $hasUpdatedAt = in_array('updated_at', $cols);

    $stmt = $db->prepare("SELECT shein_sku, name, image, link, price" . ($hasUpdatedAt ? ", updated_at" : "") . " FROM shein_products WHERE shein_sku = ? LIMIT 1");
    $stmt->execute([$sku]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    // Stale check — only if updated_at exists
    if ($hasUpdatedAt) {
        $age = (time() - strtotime($row['updated_at'])) / 86400;
        if ($age > 7) return null; // re-fetch after 7 days
    }

    return [
        'shein_sku' => $row['shein_sku'],
        'sku'       => $row['shein_sku'],
        'name'      => $row['name']  ?? '',
        'image'     => $row['image'] ?? '',
        'link'      => $row['link']  ?? '',
        'price'     => $row['price'] ?? '',
    ];
}

// ── Call local SerpAPI Node service ───────────────────────────────────────────
function callSerpApiService(string $sku): array
{
    $ch = curl_init(SERPAPI_SERVICE_URL . '/scrape');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['sku' => $sku]),
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $body    = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if (!$body) {
        throw new RuntimeException('خدمة البحث غير مشغّلة. شغّل start_serpapi.bat أولاً.' . ($curlErr ? " ($curlErr)" : ''));
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('استجابة غير صالحة من خدمة البحث');
    }
    if (empty($data['success'])) {
        throw new RuntimeException($data['error'] ?? 'تعذّر العثور على المنتج');
    }

    return [
        'shein_sku' => $data['sku']     ?? $sku,
        'sku'       => $data['sku']     ?? $sku,
        'name'      => $data['title']   ?? ('SHEIN SKU ' . $sku),
        'image'     => $data['image']   ?? '',
        'link'      => $data['url']     ?? '',
        'price'     => $data['price']   ?? '',
        'snippet'   => $data['snippet'] ?? '',
    ];
}

// ── Main ──────────────────────────────────────────────────────────────────────
try {
    $rawSku = trim($_POST['sku'] ?? $_GET['sku'] ?? '');
    if ($rawSku === '') throw new InvalidArgumentException('يرجى إدخال SKU منتج SHEIN');

    $sku = sheinNormalizeSku($rawSku);
    if ($sku === '') throw new InvalidArgumentException('SKU غير صالح: ' . htmlspecialchars($rawSku, ENT_QUOTES, 'UTF-8'));

    ensureSchemaFixed($db);

    // 1. Cache hit?
    $product = getCachedProduct($db, $sku);
    if ($product) {
        echo json_encode(['success' => true, 'product' => $product, 'source' => 'cache'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    // 2. Fetch from SerpAPI service
    $product = callSerpApiService($sku);

    // 3. Save to DB
    sheinFindOrCreateProduct($db, $product);

    echo json_encode(['success' => true, 'product' => $product, 'source' => 'serpapi'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
