<?php
require_once __DIR__ . '/../../database_config.php';

function addColumnIfNotExists($db, $table, $column, $definition) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM $table LIKE '$column'");
        if ($stmt->rowCount() == 0) {
            $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            echo "Added column $column to table $table.<br>";
        } else {
            echo "Column $column already exists in table $table.<br>";
        }
    } catch (PDOException $e) {
        echo "Error adding column $column to table $table: " . $e->getMessage() . "<br>";
    }
}

echo "Starting migration...<br>";

// 1. Add currency to customers
addColumnIfNotExists($db, 'customers', 'currency', "ENUM('YER', 'SAR') NOT NULL DEFAULT 'YER'");

// 2. Add currency to customer_orders
addColumnIfNotExists($db, 'customer_orders', 'currency', "ENUM('YER', 'SAR') NOT NULL DEFAULT 'YER'");

// 3. Add currency to customer_invoices
addColumnIfNotExists($db, 'customer_invoices', 'currency', "ENUM('YER', 'SAR') NOT NULL DEFAULT 'YER'");

// 4. Add currency to expenses
addColumnIfNotExists($db, 'expenses', 'currency', "ENUM('YER', 'SAR') NOT NULL DEFAULT 'YER'");

// 5. Add currency to purchase_baskets
addColumnIfNotExists($db, 'purchase_baskets', 'currency', "ENUM('YER', 'SAR') NOT NULL DEFAULT 'YER'");

echo "Migration complete.";
?>
