<?php
session_start();

// Ensure the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Load DB config
require_once '../../config/database.php';

// Connect via PDO
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// File name
$filename = DB_NAME . '_backup_' . date('Y-m-d_H-i-s') . '.sql';

// Function to escape values
function escapeValue($val) {
    return str_replace("'", "''", $val);
}

// Start output buffer
ob_start();

// Get all tables
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    // Table structure
    $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\n\n" . $row['Create Table'] . ";\n\n";

    // Table data
    $data = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($data as $row_data) {
        $columns = array_map(function($col){ return "`$col`"; }, array_keys($row_data));
        $values = array_map(function($val){ return isset($val) ? "'" . addslashes($val) . "'" : "NULL"; }, array_values($row_data));
        echo "INSERT INTO `$table` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ");\n";
    }
}

// Capture output
$sql_dump = ob_get_clean();

// Send download headers
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . strlen($sql_dump));

// Output file
echo $sql_dump;
exit;
