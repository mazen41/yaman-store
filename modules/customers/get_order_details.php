<?php
// get_order_details.php
header('Content-Type: application/json');
require_once '../../config/database.php';

// !!! IMPORTANT - PLEASE CONFIRM THESE TABLE AND COLUMN NAMES !!!
// I am assuming your order items are in a table named 'customer_order_items'.
// If your table has a different name or different column names, you MUST change them here.
$ORDER_ITEMS_TABLE = 'customer_order_items'; // <-- CHANGE IF YOUR TABLE NAME IS DIFFERENT
$ORDER_ID_COLUMN = 'order_id';               // <-- The column that links to the customer_orders table
$QUANTITY_COLUMN = 'quantity';               // <-- The column that stores the number of products for an item

if (isset($_POST['order_ids']) && is_array($_POST['order_ids'])) {
    $order_ids = array_filter($_POST['order_ids'], 'is_numeric');

    if (empty($order_ids)) {
        echo json_encode(['total_products' => 0]);
        exit();
    }

    try {
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));

        // This query calculates the total quantity of items across all selected orders.
        $sql = "SELECT SUM(`$QUANTITY_COLUMN`) as total_products
                FROM `$ORDER_ITEMS_TABLE`
                WHERE `$ORDER_ID_COLUMN` IN ($placeholders)";

        $stmt = $db->prepare($sql);
        $stmt->execute($order_ids);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $total_products = $result['total_products'] ?? 0;

        echo json_encode(['total_products' => (int)$total_products]);

    } catch (PDOException $e) {
        // Return 0 if there's an error (e.g., table not found)
        echo json_encode(['total_products' => 0, 'error' => 'Database error. Please check table/column names in get_order_details.php']);
    }
} else {
    echo json_encode(['total_products' => 0]);
}
?>