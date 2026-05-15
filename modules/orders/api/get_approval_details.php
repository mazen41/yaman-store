<?php
// modules/orders/api/get_approval_details.php
ini_set('display_errors', 0); // Hide errors in production API
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/check_permissions.php';

if (!canOpenOrderApprovalDetail($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$approval_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$approval_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid approval ID.']);
    exit();
}

try {
    // Fetch Approval Details
    $app_stmt = $db->prepare("
        SELECT oa.*
        FROM order_approvals oa
        WHERE oa.id = ? AND oa.status = 'pending'
    ");
    $app_stmt->execute([$approval_id]);
    $approval = $app_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$approval) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Approval record not found or already processed.']);
        exit();
    }

    // Fetch Items for this Approval
    $items_stmt = $db->prepare("SELECT * FROM order_approval_items WHERE approval_id = ?");
    $items_stmt->execute([$approval_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    $approval['items'] = $items; // Attach items to the approval details

    echo json_encode(['success' => true, 'data' => $approval]);

} catch (PDOException $e) {
    error_log("Error fetching approval details for ID $approval_id: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error fetching approval details for ID $approval_id: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>