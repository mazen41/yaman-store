<?php
/**
 * AJAX Endpoint: Generate Customer Code
 * Returns a new unique customer code in JSON format
 */

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/auto_generate_helpers.php';

header('Content-Type: application/json');

try {
    // Generate new customer code
    $customerCode = generateCustomerCode($db);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'code' => $customerCode,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate customer code',
        'message' => $e->getMessage()
    ]);
}
?>
