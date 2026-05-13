<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// On server, this path is correct as verified by test_db.php
if (file_exists('../../config/database.php')) {
    require_once '../../config/database.php';
} else {
    // Fallback for local if needed, but priority to server path
    require_once '../../database_config.php';
}

echo "Connected. Migration starting...<br>";

try {
    // 1. Create shipment_orders table
    // Removing foreign keys initially to avoid blocking errors, adding indexes
    $sql = "CREATE TABLE IF NOT EXISTS shipment_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        order_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_shipment (shipment_id),
        INDEX idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->exec($sql);
    echo "Table 'shipment_orders' created or exists.<br>";

    // 2. Migrate existing data
    $count = $db->query("SELECT COUNT(*) FROM shipment_orders")->fetchColumn();
    if ($count == 0) {
        // Check if we have data to migrate
        $stmt = $db->query("SELECT COUNT(*) FROM shipments WHERE order_id IS NOT NULL AND order_id > 0");
        $rows = $stmt->fetchColumn();
        
        if ($rows > 0) {
            $db->exec("INSERT INTO shipment_orders (shipment_id, order_id) 
                       SELECT id, order_id FROM shipments WHERE order_id IS NOT NULL AND order_id > 0");
            echo "Migrated $rows records.<br>";
        } else {
            echo "No data to migrate.<br>";
        }
    } else {
        echo "Data already migrated (rows: $count).<br>";
    }

    // 3. Make order_id nullable in shipments
    try {
        $db->exec("ALTER TABLE shipments MODIFY COLUMN order_id INT NULL");
        echo "shipments.order_id is now nullable.<br>";
    } catch (Exception $e) {
        echo "Warning modifying column (might already be nullable or constrained): " . $e->getMessage() . "<br>";
    }

    echo "Done.";

} catch (PDOException $e) {
    echo "Fatal Error: " . $e->getMessage();
}
?>
