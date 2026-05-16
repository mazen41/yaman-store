<?php
// Force all PHP date/time functions to use Yemen local time
date_default_timezone_set('Asia/Aden');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'yama');
define('DB_USER', 'root');
define('DB_PASS', '');

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $conn = null;

    public function getConnection() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                )
            );
        } catch(PDOException $e) {
            echo "Connection Error: " . $e->getMessage();
            die();
        }
        return $this->conn;
    }
}

// Global database connection
$database = new Database();
$db = $database->getConnection();
?>
