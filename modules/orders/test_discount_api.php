<?php
/**
 * Test Discount API
 */

require_once '../../config/database.php';

echo "<h1>Test Discount API</h1>";

// Test 1: Check customer types
echo "<h2>Customer Types:</h2>";
$types_stmt = $db->query("SELECT id, name FROM customer_types ORDER BY id");
$types = $types_stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Tiers</th></tr>";
foreach ($types as $type) {
    $tiers_stmt = $db->prepare("SELECT COUNT(*) FROM customer_type_discount_tiers WHERE customer_type_id = ?");
    $tiers_stmt->execute([$type['id']]);
    $tier_count = $tiers_stmt->fetchColumn();
    echo "<tr><td>{$type['id']}</td><td>{$type['name']}</td><td>{$tier_count} tiers</td></tr>";
}
echo "</table>";

// Test 2: Check tiers for customer type ID 9 (test cus)
echo "<h2>Tiers for Customer Type ID 9 (test cus):</h2>";
$tiers_stmt = $db->prepare("SELECT * FROM customer_type_discount_tiers WHERE customer_type_id = 9 ORDER BY tier_number");
$tiers_stmt->execute();
$tiers = $tiers_stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($tiers, true) . "</pre>";

// Test 3: Test API call
echo "<h2>API Test (customer_type_id=9, amount=2000):</h2>";
$url = "http://localhost/modules/orders/get_customer_discount.php?customer_type_id=9&amount=2000";
$response = file_get_contents($url);
echo "<pre>" . $response . "</pre>";
