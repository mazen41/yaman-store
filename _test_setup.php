<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');

// Clean up any previous test order first
$db->prepare("DELETE FROM order_damaged_items WHERE order_id IN (SELECT id FROM customer_orders WHERE order_number = 'TEST_CLAUDE_DEBUG')")->execute();
$db->prepare("DELETE FROM order_items WHERE order_id IN (SELECT id FROM customer_orders WHERE order_number = 'TEST_CLAUDE_DEBUG')")->execute();
$db->prepare("DELETE FROM order_status_history WHERE order_id IN (SELECT id FROM customer_orders WHERE order_number = 'TEST_CLAUDE_DEBUG')")->execute();
$db->prepare("DELETE FROM customer_invoices WHERE order_id IN (SELECT id FROM customer_orders WHERE order_number = 'TEST_CLAUDE_DEBUG')")->execute();
$db->prepare("DELETE FROM customer_orders WHERE order_number = 'TEST_CLAUDE_DEBUG'")->execute();

$stmt = $db->prepare("INSERT INTO customer_orders
    (order_number, customer_id, subtotal_amount, total_amount, final_amount, discount_amount, shipping_cost, additional_discount, paid_amount, status, currency, created_by, created_at)
    VALUES ('TEST_CLAUDE_DEBUG', 7, 1000.00, 800.00, 800.00, 200.00, 0.00, 0.00, 0, 'new', 'YER', 1, NOW())");
$stmt->execute();
$order_id = $db->lastInsertId();

$db->prepare("INSERT INTO order_items (order_id, product_name, quantity, unit_price, total_price, product_status) VALUES (?, 'Test Product A', 2, 500, 1000, 'available')")->execute([$order_id]);

$db->prepare("INSERT INTO order_damaged_items (order_id, product_name, product_link, price, reason, notes) VALUES (?, 'Test Expired Item', '', 200.00, 'expired', 'test note')")->execute([$order_id]);

echo "Created test order_id=$order_id\n";

$item_id = $db->query("SELECT id FROM order_items WHERE order_id = $order_id")->fetchColumn();
$dmg_id  = $db->query("SELECT id FROM order_damaged_items WHERE order_id = $order_id")->fetchColumn();
echo "item_id=$item_id dmg_id=$dmg_id\n";
