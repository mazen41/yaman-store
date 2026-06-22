<?php
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Search for damaged-item deletion logs ===\n";
$stmt = $db->prepare("SELECT id, order_id, created_at, notes FROM order_status_history WHERE notes LIKE ? ORDER BY id DESC LIMIT 30");
$stmt->execute(['%حُذف التالف%']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Matches for 'حُذف التالف': " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "---- order_id={$r['order_id']} id={$r['id']} created={$r['created_at']} ----\n";
    echo $r['notes'] . "\n\n";
}

echo "\n=== Search for any damaged item log mentions ===\n";
$stmt2 = $db->prepare("SELECT COUNT(*) c FROM order_status_history WHERE notes LIKE ?");
$stmt2->execute(['%تالف%']);
echo "Count containing 'تالف': " . $stmt2->fetch(PDO::FETCH_ASSOC)['c'] . "\n";

$stmt3 = $db->prepare("SELECT COUNT(*) c FROM order_status_history WHERE notes LIKE ?");
$stmt3->execute(['%تغييرات المنتجات%']);
echo "Count containing 'تغييرات المنتجات' (item changes header): " . $stmt3->fetch(PDO::FETCH_ASSOC)['c'] . "\n";

echo "\n=== Orders edited more than once (status_history count per order) ===\n";
$stmt4 = $db->query("SELECT order_id, COUNT(*) c FROM order_status_history GROUP BY order_id HAVING c >= 3 ORDER BY c DESC LIMIT 10");
foreach ($stmt4->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "order_id={$r['order_id']} history_count={$r['c']}\n";
}
