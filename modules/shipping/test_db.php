<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Starting diagnostic...<br>";

$path1 = '../../config/database.php';
$path2 = '../../database_config.php';

echo "Checking path 1: $path1 - " . (file_exists($path1) ? "Exists" : "Not Found") . "<br>";
echo "Checking path 2: $path2 - " . (file_exists($path2) ? "Exists" : "Not Found") . "<br>";

try {
    if (file_exists($path1)) {
        require_once $path1;
        echo "Included $path1<br>";
    } elseif (file_exists($path2)) {
        require_once $path2;
        echo "Included $path2<br>";
    } else {
        die("No configuration file found!");
    }

    if (isset($db)) {
        echo "DB Variable exists.<br>";
        $stmt = $db->query("SELECT 1");
        echo "Database connection successful.<br>";
    } else {
        echo "DB Variable NOT set.<br>";
    }
    
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "<br>";
    echo "Trace: " . $e->getTraceAsString();
}
?>
