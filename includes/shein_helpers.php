<?php
/**
 * shein_helpers.php  (v2 — PHP-only, no Node.js)
 *
 * SHEIN product helpers: DB schema, SKU normalization, upsert.
 * Scraping is now handled entirely in PHP via serpapi_lookup.php.
 * Node.js / .bat scraper has been removed.
 */

// =============================================================================
// SKU NORMALISER
// =============================================================================

function sheinNormalizeSku(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = trim($value);
    // Strip common human-readable prefixes (Arabic and Latin)
    $value = preg_replace(
        '/^(SKU|SHEIN\s*SKU|رقم\s*المنتج|كود\s*المنتج)\s*[::#\-]?\s*/iu',
        '',
        $value
    );
    // Keep only URL-safe characters
    $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);
    return strtoupper($value ?? '');
}

// =============================================================================
// DATABASE SCHEMA HELPER
// =============================================================================

function sheinEnsureSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS shein_products (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shein_sku  VARCHAR(100) NOT NULL UNIQUE,
            name       VARCHAR(500) NOT NULL DEFAULT '',
            image      TEXT,
            link       TEXT,
            price      VARCHAR(50)  DEFAULT NULL,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add price column if upgrading from older schema
    try {
        $db->exec("ALTER TABLE shein_products ADD COLUMN price VARCHAR(50) DEFAULT NULL");
    } catch (PDOException $e) { /* already exists */ }

    // Add updated_at column if upgrading from older schema
    try {
        $db->exec("ALTER TABLE shein_products ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    } catch (PDOException $e) { /* already exists */ }

    // Ensure order_items has the persisted fields used by the sorting workflow.
    try {
        $db->exec("ALTER TABLE order_items ADD COLUMN shein_sku VARCHAR(100) DEFAULT NULL AFTER product_name");
    } catch (PDOException $e) { /* already exists */ }
    try {
        $db->exec("ALTER TABLE order_items ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER shein_sku");
    } catch (PDOException $e) { /* already exists */ }
    try {
        $db->exec("ALTER TABLE order_items ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    } catch (PDOException $e) { /* already exists */ }
    try {
        $db->exec("CREATE INDEX idx_order_items_shein_sku ON order_items (shein_sku)");
    } catch (PDOException $e) { /* already exists */ }
    try {
        $db->exec("CREATE INDEX idx_order_items_status ON order_items (status)");
    } catch (PDOException $e) { /* already exists */ }
}

// =============================================================================
// FIND OR CREATE PRODUCT IN DB (upsert)
// =============================================================================

function sheinFindOrCreateProduct(PDO $db, array $product): int
{
    $sku   = sheinNormalizeSku($product['shein_sku'] ?? $product['sku'] ?? '');
    $name  = trim($product['name']  ?? '');
    $image = trim($product['image'] ?? '');
    $link  = trim($product['link']  ?? $product['url'] ?? '');
    $price = trim($product['price'] ?? '');

    if ($sku === '') return 0;

    $stmt = $db->prepare("SELECT id FROM shein_products WHERE shein_sku = ? LIMIT 1");
    $stmt->execute([$sku]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $sets = []; $params = [];
        if ($name  !== '') { $sets[] = 'name = ?';  $params[] = $name;  }
        if ($image !== '') { $sets[] = 'image = ?'; $params[] = $image; }
        if ($link  !== '') { $sets[] = 'link = ?';  $params[] = $link;  }
        if ($price !== '') { $sets[] = 'price = ?'; $params[] = $price; }
        if (!empty($sets)) {
            $params[] = $existing;
            $db->prepare("UPDATE shein_products SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }
        return (int) $existing;
    }

    $db->prepare("INSERT INTO shein_products (shein_sku, name, image, link, price) VALUES (?, ?, ?, ?, ?)")
       ->execute([$sku, $name, $image, $link, $price]);
    return (int) $db->lastInsertId();
}

// =============================================================================
// LEGACY STUB — kept so old call-sites don't break
// Real lookup now happens in ajax_scan.php via serpapi_lookup.php
// =============================================================================

function sheinExtractProductDataBySku(string $sku): array
{
    $sku = sheinNormalizeSku($sku);
    return [
        'shein_sku' => $sku,
        'name'      => '',
        'image'     => '',
        'link'      => '',
        'price'     => '',
    ];
}
