<?php
require_once '../../config/database.php';

echo "<h1>Debug Dashboard Data</h1>";

$tables = ['customer_orders', 'purchase_baskets', 'expenses'];
$valid_order_statuses = "'completed', 'shipped', 'delivered', 'in_delivery', 'received', 'sorted', 'under_sorting', 'in_preparation', 'approved', 'new'";

foreach ($tables as $table) {
    echo "<h2>Table: $table</h2>";
    
    // 1. Check Columns
    try {
        $columns = $db->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
        $has_currency = in_array('currency', $columns);
        echo "Has 'currency' column: " . ($has_currency ? "<span style='color:green'>YES</span>" : "<span style='color:red'>NO</span>") . "<br>";
    } catch (Exception $e) {
        echo "Error checking columns: " . $e->getMessage() . "<br>";
    }

    // 2. Check Data Counts (All time)
    try {
        $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "Total Records: $count<br>";
    } catch (Exception $e) {
        echo "Error counting records: " . $e->getMessage() . "<br>";
    }

    // 3. Check Currency Distribution
    if ($has_currency) {
        try {
            $dist = $db->query("SELECT currency, COUNT(*) as c FROM $table GROUP BY currency")->fetchAll(PDO::FETCH_ASSOC);
            echo "<h3>Currency Distribution:</h3><ul>";
            if (empty($dist)) echo "<li>No data or all NULL</li>";
            foreach ($dist as $row) {
                $curr = $row['currency'] === null ? 'NULL' : ($row['currency'] === '' ? 'EMPTY' : $row['currency']);
                echo "<li>$curr: {$row['c']}</li>";
            }
            echo "</ul>";
        } catch (Exception $e) {
             echo "Error checking distribution: " . $e->getMessage() . "<br>";
        }
    }

    // 4. Check Date Matches (Current Month)
    try {
        $date_col = ($table === 'purchase_baskets') ? 'purchase_date' : 'created_at';
        $status_filter = "";
        if ($table === 'customer_orders') {
            $status_filter = " AND status IN ($valid_order_statuses)";
        } elseif ($table === 'purchase_baskets') {
            $status_filter = " AND status = 'ordered'";
        }

        $sql = "SELECT COUNT(*) FROM $table WHERE MONTH($date_col) = MONTH(CURDATE()) AND YEAR($date_col) = YEAR(CURDATE()) $status_filter";
        $month_count = $db->query($sql)->fetchColumn();
        echo "Records this month (ignoring currency): $month_count<br>";
        
        // Debug query
        // echo "Query: $sql<br>";

    } catch (Exception $e) {
        echo "Error checking dates: " . $e->getMessage() . "<br>";
    }
    echo "<hr>";
}
?>
