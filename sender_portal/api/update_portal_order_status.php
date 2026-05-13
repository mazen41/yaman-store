<?php
// !!! TEMPORARY FOR DEBUGGING - REMOVE AFTER FIXING !!!
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../../config/database.php'; // Adjust path if necessary
header('Content-Type: application/json');

// --- Configuration ---
// Define allowed statuses if you want to be very strict here
// For simplicity, we'll rely on the dropdown options provided by the frontend.
// const ALLOWED_STATUSES = ['preparing', 'picked_up', 'shipped', 'in_transit', 'out_for_delivery', 'delivered', 'cancelled', 'returned'];

// --- CORS Headers (Optional but recommended for API development) ---
// Allow requests from your frontend domain
header("Access-Control-Allow-Origin: *"); // Replace * with your frontend domain for production
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// --- Handle OPTIONS request (for CORS preflight) ---
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit();
}

// --- Check Request Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Only POST is accepted.']);
    exit();
}

// --- Get Request Body ---
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

// --- Input Validation ---
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid JSON received.']);
    exit();
}

$order_id = $data['order_id'] ?? null;
$new_status = $data['new_status'] ?? null;
$token = $data['token'] ?? null; // Token passed from frontend

// Basic validation
if (empty($order_id) || !is_numeric($order_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order ID provided.']);
    exit();
}
if (empty($new_status)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New status is required.']);
    exit();
}
if (empty($token)) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'Authentication token is missing.']);
    exit();
}

// --- Authorization Check (Crucial!) ---
try {
    // 1. Find the sender using the token
    $sender_stmt = $db->prepare("SELECT id, name FROM senders WHERE portal_token = ?");
    $sender_stmt->execute([$token]);
    $sender = $sender_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sender) {
        http_response_code(401); // Unauthorized
        echo json_encode(['success' => false, 'message' => 'Invalid or expired authentication token.']);
        exit();
    }

    // 2. Find the order and ensure it belongs to a shipment associated with this sender
    // This is a more robust check: ensure the order is linked to a shipment, and that shipment belongs to this sender.
    $order_check_stmt = $db->prepare("
        SELECT co.id, s.sender_id
        FROM customer_orders co
        JOIN shipment_orders so ON co.id = so.order_id
        JOIN shipments s ON so.shipment_id = s.id
        WHERE co.id = ? AND s.sender_id = ?
    ");
    $order_check_stmt->execute([$order_id, $sender['id']]);
    $order_association = $order_check_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order_association) {
        http_response_code(403); // Forbidden
        echo json_encode(['success' => false, 'message' => 'You do not have permission to update this order.']);
        exit();
    }

    // Optional: Check if the $new_status is one of the allowed statuses from customer_order_statuses table
    // This adds an extra layer of validation against invalid status keys.
    $status_stmt = $db->prepare("SELECT COUNT(*) FROM customer_order_statuses WHERE status_key = ?");
    $status_stmt->execute([$new_status]);
    if ($status_stmt->fetchColumn() === '0') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status key provided.']);
        exit();
    }

} catch (PDOException $e) {
    error_log("Database error during authorization check: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'An internal error occurred during authorization.']);
    exit();
}


// --- Update the Order Status ---
try {
    $update_stmt = $db->prepare("UPDATE customer_orders SET status = ?, updated_at = NOW() WHERE id = ?");
    $success = $update_stmt->execute([$new_status, $order_id]);

    if ($success && $update_stmt->rowCount() > 0) {
        // Successfully updated
        echo json_encode(['success' => true, 'message' => 'Order status updated successfully.']);
    } else {
        // Update failed or affected 0 rows (order might not exist, or status was already the same)
        // For now, we assume failure means something went wrong.
        http_response_code(500); // Internal Server Error (or consider 409 Conflict if status was same)
        echo json_encode(['success' => false, 'message' => 'Failed to update order status. The order might not exist or no changes were made.']);
    }

} catch (PDOException $e) {
    error_log("Database error during order status update: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'An internal error occurred while updating the status.']);
}

?>