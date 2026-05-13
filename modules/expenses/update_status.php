<?php
session_start();
header('Content-Type: application/json');

// Basic security checks
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

require_once '../../config/database.php';

$expense_id = $_POST['expense_id'] ?? null;
$new_status = $_POST['new_status'] ?? null;

// Validate input
$allowed_statuses = ['pending', 'approved', 'rejected'];
if (empty($expense_id) || empty($new_status) || !in_array($new_status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input provided.']);
    exit();
}

try {
    $stmt = $db->prepare("UPDATE expenses SET status = ? WHERE id = ?");
    $success = $stmt->execute([$new_status, $expense_id]);

    if ($success) {
        // Here you could add more logic, e.g., if status changes to 'approved',
        // you might trigger the financial transaction logic from the 'add' page.
        // For now, we just update the status.
        echo json_encode(['success' => true, 'message' => 'Status updated successfully.']);
    } else {
        throw new Exception('Database update failed.');
    }
} catch (Exception $e) {
    // Log the actual error for debugging
    error_log("Failed to update expense status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred.']);
}