<?php
require_once __DIR__ . '/../../config/database.php';

function modifyColumn($db, $table, $column, $definition) {
    try {
        // Check if column exists first
        $stmt = $db->query("SHOW COLUMNS FROM $table LIKE '$column'");
        if ($stmt->rowCount() > 0) {
            $db->exec("ALTER TABLE $table MODIFY COLUMN $column $definition");
            echo "Modified column $column in table $table to $definition.<br>";
        } else {
            echo "Column $column does not exist in table $table, skipping.<br>";
        }
    } catch (PDOException $e) {
        echo "Error modifying column $column in table $table: " . $e->getMessage() . "<br>";
    }
}

echo "Starting currency update migration (USD -> SAR)...<br>";

$definition = "ENUM('YER', 'SAR') NOT NULL DEFAULT 'YER'";

// 1. Customers
modifyColumn($db, 'customers', 'currency', $definition);

// 2. Customer Orders
modifyColumn($db, 'customer_orders', 'currency', $definition);

// 3. Customer Invoices
modifyColumn($db, 'customer_invoices', 'currency', $definition);

// 4. Expenses
modifyColumn($db, 'expenses', 'currency', $definition);

// 5. Purchase Baskets
modifyColumn($db, 'purchase_baskets', 'currency', $definition);

// 6. Bank Accounts (if applicable, usually added manually or check logic)
// Based on previous conversation, bank_accounts table structure wasn't fully confirmed but assumed. 
// We'll try to update it if it has the column.
modifyColumn($db, 'bank_accounts', 'currency', $definition);

echo "Migration complete. Please check data integrity manually if USD values existed.";
?>
