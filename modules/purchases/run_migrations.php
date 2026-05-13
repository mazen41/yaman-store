<?php
/**
 * Database Migration Script
 * Run this once to fix the database schema
 */

require_once '../../config/database.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Database Migration</title></head><body>";
echo "<h1>Database Migration - Status Updates</h1>";
echo "<pre>";

try {
    // Fix purchase_baskets status column
    echo "\n=== Fixing purchase_baskets status column ===\n";
    
    // Check current column type
    $stmt = $db->query("SHOW COLUMNS FROM purchase_baskets LIKE 'status'");
    $column_info = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Current status column: " . print_r($column_info, true) . "\n";
    
    // Modify column to VARCHAR for flexibility
    echo "Modifying status column to VARCHAR(50)...\n";
    $db->exec("ALTER TABLE purchase_baskets MODIFY COLUMN status VARCHAR(50) DEFAULT 'under_review'");
    echo "✓ Status column modified successfully\n";
    
    // Update old values to new ones
    echo "\nUpdating old status values...\n";
    $updates = [
        ['old' => 'active', 'new' => 'under_review'],
        ['old' => 'ordered', 'new' => 'purchased'],
        ['old' => 'cancelled', 'new' => 'ready']
    ];
    
    foreach ($updates as $update) {
        $stmt = $db->prepare("UPDATE purchase_baskets SET status = ? WHERE status = ?");
        $stmt->execute([$update['new'], $update['old']]);
        $count = $stmt->rowCount();
        echo "✓ Updated {$count} rows from '{$update['old']}' to '{$update['new']}'\n";
    }
    
    // Fix purchase_groups table
    echo "\n=== Fixing purchase_groups table ===\n";
    
    // Check if updated_at column exists
    $stmt = $db->query("SHOW COLUMNS FROM purchase_groups LIKE 'updated_at'");
    if (!$stmt->fetch()) {
        echo "Adding updated_at column...\n";
        $db->exec("ALTER TABLE purchase_groups ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
        echo "✓ updated_at column added\n";
    } else {
        echo "✓ updated_at column already exists\n";
    }
    
    // Check if status column exists
    $stmt = $db->query("SHOW COLUMNS FROM purchase_groups LIKE 'status'");
    $status_exists = $stmt->fetch();
    
    if (!$status_exists) {
        echo "Adding status column...\n";
        $db->exec("ALTER TABLE purchase_groups ADD COLUMN status VARCHAR(50) DEFAULT 'active'");
        echo "✓ status column added\n";
    } else {
        echo "Modifying status column to VARCHAR(50)...\n";
        $db->exec("ALTER TABLE purchase_groups MODIFY COLUMN status VARCHAR(50) DEFAULT 'active'");
        echo "✓ status column modified\n";
    }
    
    // Set default values for existing rows
    echo "\nSetting default values for existing rows...\n";
    $stmt = $db->exec("UPDATE purchase_groups SET updated_at = created_at WHERE updated_at IS NULL");
    echo "✓ Set updated_at for existing rows\n";
    
    $stmt = $db->exec("UPDATE purchase_groups SET status = 'active' WHERE status IS NULL OR status = ''");
    echo "✓ Set status for existing rows\n";
    
    echo "\n=== Migration completed successfully! ===\n";
    echo "\nYou can now delete this file (run_migrations.php) for security.\n";
    
} catch (PDOException $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<p><a href='javascript:history.back()'>Go Back</a></p>";
echo "</body></html>";
