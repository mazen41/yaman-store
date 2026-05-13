<?php
/**
 * API: Search Customers
 * Returns customer data for autocomplete/dropdown
 */

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    require_once '../../../config/database.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Simple phone formatter
function formatPhone($phone) {
    if (empty($phone)) return '';
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 3) === '967') {
        $phone = substr($phone, 3);
    }
    $phone = ltrim($phone, '0');
    if (strlen($phone) === 9) {
        return '+967 ' . substr($phone, 0, 3) . ' ' . substr($phone, 3, 3) . ' ' . substr($phone, 6, 3);
    }
    return '+967 ' . $phone;
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

if (strlen($query) < 1) {
    echo json_encode([]);
    exit();
}

try {
    // Ensure limit is integer
    $limit = max(1, min(100, $limit)); // Between 1 and 100
    
    // Check which columns exist in customers table
    $columns = $db->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    
    // Build dynamic SELECT
    $select_fields = ['id', 'customer_code', 'name'];
    
    // Add optional columns if they exist
    if (in_array('mobile_number', $columns)) $select_fields[] = 'mobile_number';
    if (in_array('whatsapp_number', $columns)) $select_fields[] = 'whatsapp_number';
    if (in_array('email', $columns)) $select_fields[] = 'email';
    if (in_array('city', $columns)) $select_fields[] = 'city';
    if (in_array('customer_type', $columns)) $select_fields[] = 'customer_type';
    
    $select_sql = implode(', ', $select_fields);
    
    // Build WHERE conditions dynamically
    $where_conditions = ['name LIKE ?', 'customer_code LIKE ?'];
    $params = ["%$query%", "%$query%"];
    
    if (in_array('mobile_number', $columns)) {
        $where_conditions[] = 'mobile_number LIKE ?';
        $params[] = "%$query%";
    }
    if (in_array('whatsapp_number', $columns)) {
        $where_conditions[] = 'whatsapp_number LIKE ?';
        $params[] = "%$query%";
    }
    if (in_array('email', $columns)) {
        $where_conditions[] = 'email LIKE ?';
        $params[] = "%$query%";
    }
    
    $where_sql = implode(' OR ', $where_conditions);
    
    $sql = "
        SELECT $select_sql
        FROM customers
        WHERE is_active = 1
        AND ($where_sql)
        ORDER BY name
        LIMIT " . $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format phone numbers
    foreach ($customers as &$customer) {
        $customer['mobile_formatted'] = isset($customer['mobile_number']) ? formatPhone($customer['mobile_number']) : '';
        $customer['whatsapp_formatted'] = isset($customer['whatsapp_number']) ? formatPhone($customer['whatsapp_number']) : '';
        $customer['city'] = isset($customer['city']) ? $customer['city'] : '';
    }
    
    echo json_encode($customers);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
?>
