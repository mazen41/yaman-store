<?php
/**
 * SHEIN product helpers for local SKU-based order linking and sorting.
 *
 * The integration intentionally does not use a live SHEIN API. It fetches the
 * product page once, extracts visible/product metadata, stores it locally, and
 * uses the SHEIN SKU as the matching key during sorting.
 */

function sheinEnsureSchema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $db->exec("CREATE TABLE IF NOT EXISTS shein_products (
        id INT(11) NOT NULL AUTO_INCREMENT,
        shein_sku VARCHAR(120) NOT NULL,
        name VARCHAR(500) DEFAULT NULL,
        image TEXT DEFAULT NULL,
        link TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_shein_sku (shein_sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $columns = $db->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('shein_product_id', $columns, true)) {
        $db->exec("ALTER TABLE order_items ADD COLUMN shein_product_id INT(11) DEFAULT NULL AFTER product_id");
    }
    if (!in_array('shein_sku', $columns, true)) {
        $db->exec("ALTER TABLE order_items ADD COLUMN shein_sku VARCHAR(120) DEFAULT NULL AFTER shein_product_id");
    }
    if (!in_array('status', $columns, true)) {
        $db->exec("ALTER TABLE order_items ADD COLUMN status ENUM('pending','scanned') NOT NULL DEFAULT 'pending' AFTER shein_sku");
    }

    $indexes = $db->query("SHOW INDEX FROM order_items")->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array_column($indexes, 'Key_name');
    if (!in_array('idx_order_items_shein_sku', $indexNames, true)) {
        $db->exec("ALTER TABLE order_items ADD INDEX idx_order_items_shein_sku (shein_sku)");
    }
    if (!in_array('idx_order_items_shein_product', $indexNames, true)) {
        $db->exec("ALTER TABLE order_items ADD INDEX idx_order_items_shein_product (shein_product_id)");
    }
    if (!in_array('idx_order_items_status', $indexNames, true)) {
        $db->exec("ALTER TABLE order_items ADD INDEX idx_order_items_status (status)");
    }

    $done = true;
}

function sheinNormalizeSku(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = trim($value);
    $value = preg_replace('/^(SKU|SHEIN\s*SKU|رقم\s*المنتج|كود\s*المنتج)\s*[:：#-]?\s*/iu', '', $value);
    $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);
    return strtoupper($value ?? '');
}

function sheinLooksLikeUrl(string $value): bool
{
    return (bool) filter_var(trim($value), FILTER_VALIDATE_URL);
}

function sheinFetchUrl(string $url): string
{
    if (!sheinLooksLikeUrl($url)) {
        throw new InvalidArgumentException('رابط المنتج غير صالح');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: ar,en-US;q=0.9,en;q=0.8',
            ],
        ]);
        $html = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || $html === '' || $status >= 400) {
            throw new RuntimeException('تعذر جلب صفحة SHEIN' . ($error ? ': ' . $error : ''));
        }

        return $html;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 18,
            'follow_location' => 1,
            'max_redirects' => 5,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36\r\n" .
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                "Accept-Language: ar,en-US;q=0.9,en;q=0.8\r\n",
        ],
    ]);
    $html = @file_get_contents($url, false, $context);
    if ($html === false || $html === '') {
        throw new RuntimeException('تعذر جلب صفحة SHEIN');
    }

    return $html;
}

function sheinExtractMeta(string $html, string $property): ?string
{
    $propertyPattern = preg_quote($property, '/');
    $patterns = [
        '/<meta[^>]+property=["\']' . $propertyPattern . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/iu',
        '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']' . $propertyPattern . '["\'][^>]*>/iu',
        '/<meta[^>]+name=["\']' . $propertyPattern . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/iu',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }
    return null;
}

function sheinExtractSkuFromHtml(string $html): string
{
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $compact = preg_replace('/\s+/', ' ', $decoded);

    $patterns = [
        '/(?:SKU|SHEIN\s*SKU|sku_code|goods_sn|productSku|product_sku|spu_code|mall_code)["\'\s:=：#-]{1,12}([A-Za-z0-9_-]{5,80})/iu',
        '/(?:رقم\s*المنتج|كود\s*المنتج)["\'\s:=：#-]{1,12}([A-Za-z0-9_-]{5,80})/iu',
        '/"(?:sku|skuCode|goodsSn|goods_sn|productSku|product_sku)"\s*:\s*"([A-Za-z0-9_-]{5,80})"/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $compact, $matches)) {
            $sku = sheinNormalizeSku($matches[1]);
            if ($sku !== '') {
                return $sku;
            }
        }
    }

    return '';
}

function sheinBuildAbsoluteUrl(string $href, string $baseUrl = 'https://us.shein.com'): string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '') {
        return '';
    }

    if (substr($href, 0, 2) === '//') {
        return 'https:' . $href;
    }

    if (preg_match('/^https?:\/\//i', $href)) {
        return $href;
    }

    if ($href[0] === '/') {
        return rtrim($baseUrl, '/') . $href;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
}

function sheinExtractFirstProductLink(string $html): string
{
    $patterns = [
        '/<a\b[^>]*\bhref=["\']([^"\']*\/p\/[^"\']*)["\'][^>]*>/iu',
        '/\bhref=["\']([^"\']*\/p\/[^"\']*)["\']/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $matches)) {
            $link = sheinBuildAbsoluteUrl($matches[1]);
            if ($link !== '') {
                return $link;
            }
        }
    }

    throw new RuntimeException('تعذر العثور على رابط منتج SHEIN لهذا SKU');
}

function sheinExtractProductDataBySku(string $sku): array
{
    $sku = sheinNormalizeSku($sku);
    if ($sku === '') {
        throw new InvalidArgumentException('يرجى إدخال SKU صالح لمنتج SHEIN');
    }

    $searchSku = strtolower($sku);
    $searchUrl = 'https://us.shein.com/pdsearch/' . rawurlencode($searchSku) . '/';
    $searchHtml = sheinFetchUrl($searchUrl);
    $productLink = sheinExtractFirstProductLink($searchHtml);

    $productHtml = sheinFetchUrl($productLink);
    $name = sheinExtractMeta($productHtml, 'og:title') ?: sheinExtractMeta($productHtml, 'twitter:title') ?: 'SHEIN Product';
    $image = sheinExtractMeta($productHtml, 'og:image') ?: sheinExtractMeta($productHtml, 'twitter:image') ?: '';

    return [
        'sku' => $sku,
        'shein_sku' => $sku,
        'name' => trim($name),
        'image' => trim($image),
        'link' => trim($productLink),
    ];
}

function sheinFindOrCreateProduct(PDO $db, array $data): int
{
    sheinEnsureSchema($db);
    $sku = sheinNormalizeSku($data['shein_sku'] ?? '');
    if ($sku === '') {
        throw new InvalidArgumentException('SKU مطلوب لحفظ منتج SHEIN');
    }

    $stmt = $db->prepare("SELECT id FROM shein_products WHERE shein_sku = ? LIMIT 1");
    $stmt->execute([$sku]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $update = $db->prepare("UPDATE shein_products SET
            name = COALESCE(NULLIF(?, ''), name),
            image = COALESCE(NULLIF(?, ''), image),
            link = COALESCE(NULLIF(?, ''), link)
            WHERE id = ?");
        $update->execute([
            trim($data['name'] ?? ''),
            trim($data['image'] ?? ''),
            trim($data['link'] ?? ''),
            $existing['id'],
        ]);
        return (int) $existing['id'];
    }

    $insert = $db->prepare("INSERT INTO shein_products (shein_sku, name, image, link, created_at) VALUES (?, ?, ?, ?, NOW())");
    $insert->execute([
        $sku,
        trim($data['name'] ?? ''),
        trim($data['image'] ?? ''),
        trim($data['link'] ?? ''),
    ]);

    return (int) $db->lastInsertId();
}

function sheinResolveInputToSku(string $input): array
{
    $sku = sheinNormalizeSku($input);
    if ($sku === '') {
        throw new InvalidArgumentException('يرجى إدخال SKU صالح');
    }

    return sheinExtractProductDataBySku($sku);
    // ✅ Build the search URL from the SKU and fetch it
    $searchUrl = 'https://us.shein.com/pdsearch/' . urlencode(strtolower($sku)) . '/';
    return sheinExtractProductDataFromSearch($searchUrl, $sku);
}
