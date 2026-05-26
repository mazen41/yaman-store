<?php
session_start();
require_once 'config/database.php';

// Show raw order_items data - last 5 orders
$stmt = $db->query("
    SELECT 
        oi.id,
        oi.order_id,
        oi.product_name,
        oi.shein_sku,
        oi.quantity,
        o.order_number
    FROM order_items oi
    INNER JOIN customer_orders o ON o.id = oi.order_id
    ORDER BY oi.order_id DESC, oi.id ASC
    LIMIT 50
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre style='font-size:14px;'>";
echo "=== RAW order_items (last 50 rows) ===\n\n";
echo str_pad("item_id", 10) . str_pad("order_id", 10) . str_pad("order_num", 15) . str_pad("shein_sku", 30) . "product_name\n";
echo str_repeat("-", 90) . "\n";
foreach ($rows as $r) {
    $sku = $r['shein_sku'] === null ? 'NULL' : ($r['shein_sku'] === '' ? "EMPTY_STRING" : $r['shein_sku']);
    echo str_pad($r['id'], 10) 
       . str_pad($r['order_id'], 10) 
       . str_pad($r['order_number'], 15) 
       . str_pad($sku, 30) 
       . $r['product_name'] . "\n";
}

// Now show what the SKU page query actually returns
echo "\n\n=== WHAT skus.php QUERY RETURNS ===\n\n";
$stmt2 = $db->query("
    SELECT
        oi.id AS item_id,
        oi.order_id,
        oi.product_name,
        oi.shein_sku,
        TRIM(COALESCE(oi.shein_sku, '')) AS normalized_sku,
        o.order_number
    FROM order_items oi
    INNER JOIN customer_orders o ON o.id = oi.order_id
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE TRIM(COALESCE(oi.shein_sku, '')) = ''
    ORDER BY o.id DESC, oi.id ASC
    LIMIT 50
");
$rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "Total missing-SKU rows returned: " . count($rows2) . "\n\n";
echo str_pad("item_id", 10) . str_pad("order_id", 10) . str_pad("order_num", 15) . "product_name\n";
echo str_repeat("-", 70) . "\n";
foreach ($rows2 as $r) {
    echo str_pad($r['item_id'], 10) 
       . str_pad($r['order_id'], 10) 
       . str_pad($r['order_number'], 15) 
       . $r['product_name'] . "\n";
}
echo "</pre>";
