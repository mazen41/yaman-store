<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();

// CORRECT PATH: Go up 3 levels from api/ to htdocs/
$config_path = __DIR__ . '/../../../config/database.php';

if (!file_exists($config_path)) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Config not found at: ' . $config_path]);
    exit;
}

require_once $config_path;

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'list_couriers') {
        
        $stmt = $db->query("
            SELECT 
                u.id,
                u.username,
                u.full_name,
                u.phone,
                u.email
            FROM users u
            INNER JOIN user_roles ur ON u.id = ur.user_id
            INNER JOIN roles r ON ur.role_id = r.id
            WHERE r.name = 'courier'
            AND u.is_active = 1
        ");
        
        $couriers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($couriers as &$c) {
            $c['name'] = $c['full_name'] ?: $c['username'];
            $c['total_orders'] = 0;
            $c['completed_orders'] = 0;
            $c['total_cod'] = 0;
            $c['created_at'] = date('Y-m-d');
        }
        
        echo json_encode([
            'success' => true,
            'couriers' => $couriers,
            'stats' => [
                'active_couriers' => count($couriers),
                'total_deliveries' => 0,
                'total_cod' => 0
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'add_courier') {
        
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($username) || empty($password) || empty($full_name)) {
            echo json_encode(['success' => false, 'error' => 'Missing fields']);
            exit;
        }
        
        $db->beginTransaction();
        
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, full_name, phone, email, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$username, $hashed, $full_name, $phone, $email]);
        $user_id = $db->lastInsertId();
        
        $role = $db->query("SELECT id FROM roles WHERE name = 'courier'")->fetch();
        if (!$role) throw new Exception('Courier role not found');
        
        $stmt = $db->prepare("INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?, ?, NOW())");
        $stmt->execute([$user_id, $role['id']]);
        
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Added successfully', 'courier_id' => $user_id]);
        
    } elseif ($action === 'delete_courier') {
        
        $courier_id = intval($_POST['courier_id'] ?? 0);
        if (!$courier_id) {
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }
        
        $stmt = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->execute([$courier_id]);
        
        echo json_encode(['success' => true, 'message' => 'Deleted successfully']);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
