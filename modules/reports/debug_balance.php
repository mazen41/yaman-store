<?php
// --- DEBUG FILE: debug_balance.php ---

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../config/database.php';

echo '<pre style="direction: ltr; text-align: left; background: #f1f1f1; padding: 20px; border: 1px solid #ccc;">';
echo "<h1>DATABASE DEBUG MODE</h1><hr>";

try {
    // Test 1: Bank Accounts
    echo "<h2>[TEST 1] Querying Bank Accounts...</h2>";
    $cash_query = "SELECT SUM(current_balance) FROM bank_accounts WHERE is_active = 1";
    echo "<strong>Query:</strong> " . $cash_query . "\n";
    $cash_in_banks = $db->query($cash_query)->fetchColumn();
    echo "<strong>Result:</strong> ";
    var_dump($cash_in_banks);
    echo "<hr>";

    // Test 2: Customers
    echo "<h2>[TEST 2] Querying Customer Balances...</h2>";
    $customer_query = "SELECT SUM(current_balance) FROM customers WHERE current_balance > 0";
    echo "<strong>Query:</strong> " . $customer_query . "\n";
    $accounts_receivable = $db->query($customer_query)->fetchColumn();
    echo "<strong>Result:</strong> ";
    var_dump($accounts_receivable);
    echo "<hr>";

    // Test 3: Purchase Cards (This is the one we expect to have a value)
    echo "<h2>[TEST 3] Querying Purchase Cards...</h2>";
    $cards_query = "SELECT SUM(balance) FROM purchase_cards WHERE balance > 0";
    echo "<strong>Query:</strong> " . $cards_query . "\n";
    $prepaid_cards_balance = $db->query($cards_query)->fetchColumn();
    echo "<strong>Result:</strong> ";
    var_dump($prepaid_cards_balance);
    echo "<hr>";

    echo "<h2>✅ All tests completed.</h2>";

} catch (PDOException $e) {
    echo "<h2>❌ A FATAL DATABASE ERROR OCCURRED!</h2>";
    echo "<strong>Error Message:</strong>\n";
    print_r($e->getMessage());
}

echo '</pre>';