<?php
/**
 * API endpoint to add/update a manager's note for a specific order.
 */

// Always return JSON
header('Content-Type: application/json');

session_start();

// ---------------------------------------------------------------------
// 1. AUTHENTICATION
// ---------------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'User not authenticated.'
    ]);
    exit;
}

require_once '../../../config/database.php';
require_once '../../../includes/check_permissions.php';

// ---------------------------------------------------------------------
// 2. AUTHORIZATION
// ---------------------------------------------------------------------
if (!hasPermission($_SESSION['user_id'], 'orders', 'edit')) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to perform this action.'
    ]);
    exit;
}

// ---------------------------------------------------------------------
// 3. INPUT VALIDATION
// ---------------------------------------------------------------------
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || !isset($data['order_id'], $data['manager_note'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid input provided.'
    ]);
    exit;
}

$order_id = filter_var($data['order_id'], FILTER_VALIDATE_INT);
$manager_note = trim($data['manager_note']);

if ($order_id === false) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid Order ID.'
    ]);
    exit;
}

// ---------------------------------------------------------------------
// 4. DATABASE UPDATE
// ---------------------------------------------------------------------
try {
    $db->beginTransaction();

    $stmt = $db->prepare("
        UPDATE customer_orders
        SET manager_notes = ?
        WHERE id = ?
    ");

    $stmt->execute([$manager_note, $order_id]);

    // Catch silent failures (no row updated)
    if ($stmt->rowCount() === 0) {
        throw new Exception('No rows updated. Order may not exist or note unchanged.');
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Manager note saved successfully.'
    ]);

} catch (Throwable $e) {

    if ($db->inTransaction()) {
        $db->rollBack();
    }

    $response = [
        'success' => false,
        'message' => 'A database error occurred.'
    ];

    // Show detailed error only in debug mode
    $response['error'] = $e->getMessage();

    // Always log the real error
    error_log('Manager Note Update Error: ' . $e->getMessage());

    echo json_encode($response);
}

