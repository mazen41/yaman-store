<?php
/**
 * Database Migration Script
 * Adds review_status columns to purchase_baskets and expenses tables
 */

require_once '../../config/database.php';

echo "<h2>Adding Review Columns to Database Tables</h2>";
echo "<pre>";

try {
    // Check and add columns to purchase_baskets table
    echo "\n=== Checking purchase_baskets table ===\n";
    $check_baskets = $db->query("SHOW COLUMNS FROM purchase_baskets LIKE 'review_status'");
    
    if ($check_baskets->rowCount() == 0) {
        echo "Adding review columns to purchase_baskets table...\n";
        $db->exec("
            ALTER TABLE purchase_baskets 
            ADD COLUMN review_status ENUM('pending', 'reviewed') DEFAULT 'pending' AFTER status,
            ADD COLUMN reviewed_by INT NULL AFTER review_status,
            ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
            ADD COLUMN review_note TEXT NULL AFTER reviewed_at,
            ADD INDEX idx_review_status (review_status),
            ADD FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ");
        echo "✅ Successfully added review columns to purchase_baskets\n";
    } else {
        echo "✅ Review columns already exist in purchase_baskets\n";
    }
    
    // Check and add columns to expenses table
    echo "\n=== Checking expenses table ===\n";
    $check_expenses = $db->query("SHOW COLUMNS FROM expenses LIKE 'review_status'");
    
    if ($check_expenses->rowCount() == 0) {
        echo "Adding review columns to expenses table...\n";
        $db->exec("
            ALTER TABLE expenses 
            ADD COLUMN review_status ENUM('pending', 'reviewed') DEFAULT 'pending' AFTER payment_status,
            ADD COLUMN reviewed_by INT NULL AFTER review_status,
            ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
            ADD COLUMN review_note TEXT NULL AFTER reviewed_at,
            ADD INDEX idx_review_status (review_status),
            ADD FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ");
        echo "✅ Successfully added review columns to expenses\n";
    } else {
        echo "✅ Review columns already exist in expenses\n";
    }
    
    // Verify the columns were added
    echo "\n=== Verification ===\n";
    
    echo "\nPurchase Baskets Columns:\n";
    $baskets_columns = $db->query("SHOW COLUMNS FROM purchase_baskets WHERE Field LIKE '%review%'")->fetchAll();
    foreach ($baskets_columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
    echo "\nExpenses Columns:\n";
    $expenses_columns = $db->query("SHOW COLUMNS FROM expenses WHERE Field LIKE '%review%'")->fetchAll();
    foreach ($expenses_columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\n<a href='financial_review.php?filter=basket' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #C7A46D; color: white; text-decoration: none; border-radius: 8px;'>Go to Financial Review</a>";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString();
}

echo "</pre>";
?>
