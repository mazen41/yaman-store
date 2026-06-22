<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');

$order_id = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : 200;

$stmt = $db->prepare("SELECT id, status, created_at, notes FROM order_status_history WHERE order_id = ? ORDER BY id ASC");
$stmt->execute([$order_id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "================ history_id={$r['id']}  status={$r['status']}  created={$r['created_at']} ================\n";
    echo $r['notes'] . "\n\n";
}

echo "\n\n=== CURRENT order_damaged_items for order $order_id ===\n";
$stmt2 = $db->prepare("SELECT * FROM order_damaged_items WHERE order_id = ? ORDER BY id");
$stmt2->execute([$order_id]);
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    print_r($r);
}

echo "\n=== CURRENT order_items for order $order_id ===\n";
$stmt3 = $db->prepare("SELECT id, product_name, quantity, unit_price, total_price, product_status, shein_sku FROM order_items WHERE order_id = ? ORDER BY id");
$stmt3->execute([$order_id]);
foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    print_r($r);
}
