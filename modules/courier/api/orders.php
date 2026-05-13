<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../../../config/database.php';

$role_check = $db->prepare("SELECT r.name FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = ? AND r.name = 'courier'");
$role_check->execute([$_SESSION['user_id']]);

if (!$role_check->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

$status_filter = $_GET['status'] ?? 'all';
$courier_id = $_SESSION['user_id'];

try {
    $query = "
        SELECT DISTINCT
            o.id,
            o.order_number,
            o.status,
            o.cod_amount,
            o.cod_collected,
            c.name as customer_name,
            c.phone as customer_phone,
            c.address as customer_address
        FROM customer_orders o
        INNER JOIN order_assignments oa ON o.id = oa.order_id
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE oa.courier_id = ?
    ";
    
    $params = [$courier_id];
    
    if ($status_filter !== 'all') {
        if ($status_filter === 'ready') {
            // جاهز للتسليم
            $query .= " AND o.status IN ('ready_for_delivery', 'جاهز للتسليم', 'processing')";
        } elseif ($status_filter === 'completed') {
            // مكتمل
            $query .= " AND o.status IN ('completed', 'مكتمل')";
        } elseif ($status_filter === 'failed') {
            // معلق
            $query .= " AND o.status = 'معلق'";
        }
    }
    
    $query .= " ORDER BY o.id DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = [
        'ready' => 0,
        'completed' => 0,
        'failed' => 0,
        'total_cod' => 0,
        'collected_cod' => 0
    ];
    
    foreach ($orders as $order) {
        $summary['total_cod'] += floatval($order['cod_amount'] ?? 0);
        $summary['collected_cod'] += floatval($order['cod_collected'] ?? 0);
        
        $status = $order['status'];
        if (in_array($status, ['ready_for_delivery', 'جاهز للتسليم', 'processing'])) {
            $summary['ready']++;
        } elseif (in_array($status, ['completed', 'مكتمل'])) {
            $summary['completed']++;
        } elseif ($status === 'معلق') {
            $summary['failed']++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'summary' => $summary
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
