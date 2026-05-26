<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'config/database.php';

// 1. find orders that likely lost items
$orders = $db->query("
    SELECT o.id, COUNT(i.id) as item_count
    FROM customer_orders o
    JOIN order_items i ON i.order_id = o.id
    GROUP BY o.id
    HAVING item_count = 1
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($orders as $order) {

    $orderId = $order['id'];

    // get the single existing item
    $item = $db->query("
        SELECT * FROM order_items
        WHERE order_id = $orderId
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$item) continue;

    // we cannot know original count, so we restore SAFE minimum structure:
    // → assume at least quantity rows should exist
    $qty = (int)$item['quantity'];

    // if quantity is 1, we skip (nothing to fix)
    if ($qty <= 1) continue;

    // create missing rows
    for ($i = 1; $i < $qty; $i++) {

        $stmt = $db->prepare("
            INSERT INTO order_items
            (
                order_id,
                product_name,
                shein_sku,
                status,
                product_id,
                shein_product_id,
                quantity,
                unit_price,
                total_price,
                created_at
            )
            VALUES (?, ?, NULL, 'pending', ?, ?, 1, ?, ?, NOW())
        ");

        $stmt->execute([
            $orderId,
            $item['product_name'] ?? 'منتج',
            $item['product_id'],
            $item['shein_product_id'],
            $item['unit_price'],
            $item['unit_price']
        ]);
    }
}

echo "DONE FIXED";