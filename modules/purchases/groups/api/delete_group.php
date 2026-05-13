<?php
/**
 * API endpoint to delete a purchase group.
 *
 * - Verifies user permissions.
 * - Ensures the group is empty before deletion.
 * - Uses a transaction for safe database operations.
 */

// Set header to return JSON
header('Content-Type: application/json');

// Start session to access user data
session_start();

// Include database and permission check essentials
// Adjust the path '..' as necessary based on your file structure
require_once '../../../../config/database.php';
require_once '../../../../includes/check_permissions.php';

// --- 1. Security and Permission Checks ---

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Only POST is accepted.']);
    exit();
}

// Check if user is logged in
$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id === 0) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالقيام بهذا الإجراء.']);
    exit();
}

// Check if the user has the specific permission to delete purchase groups
if (!hasPermission($user_id, 'purchase_groups', 'delete')) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لحذف مجموعات الشراء.']);
    exit();
}


// --- 2. Input Validation ---

// Get the POST data sent as JSON
$data = json_decode(file_get_contents('php://input'));

// Check if group_id is provided and is a valid integer
$group_id = filter_var($data->group_id ?? null, FILTER_VALIDATE_INT);

if (!$group_id) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'معرف المجموعة غير صالح أو مفقود.']);
    exit();
}


// --- 3. Database Deletion Logic with Transaction ---

try {
    // Begin a transaction
    $db->beginTransaction();

    // SAFETY CHECK 1: Check for associated customer orders
    $stmt_orders = $db->prepare("SELECT COUNT(*) FROM customer_orders WHERE purchase_group_id = ?");
    $stmt_orders->execute([$group_id]);
    if ($stmt_orders->fetchColumn() > 0) {
        // If orders exist, throw an exception to prevent deletion
        throw new Exception('لا يمكن حذف المجموعة لوجود طلبات مرتبطة بها. يرجى نقل الطلبات أولاً.');
    }

    // SAFETY CHECK 2: Check for associated purchase baskets
    $stmt_baskets = $db->prepare("SELECT COUNT(*) FROM purchase_baskets WHERE purchase_group_id = ?");
    $stmt_baskets->execute([$group_id]);
    if ($stmt_baskets->fetchColumn() > 0) {
        // If baskets exist, throw an exception
        throw new Exception('لا يمكن حذف المجموعة لوجود سلال شراء مرتبطة بها. يرجى إفراغ المجموعة أولاً.');
    }

    // If both checks pass, proceed with deletion
    $stmt_delete = $db->prepare("DELETE FROM purchase_groups WHERE id = ?");
    $stmt_delete->execute([$group_id]);

    // Verify if a row was actually deleted
    if ($stmt_delete->rowCount() === 0) {
        throw new Exception('المجموعة غير موجودة أو قد تم حذفها بالفعل.');
    }

    // If everything is successful, commit the transaction
    $db->commit();

    // Send a success response
    echo json_encode(['success' => true, 'message' => 'تم حذف المجموعة بنجاح.']);

} catch (PDOException $e) {
    // If a database error occurs, roll back the transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'فشل الحذف بسبب خطأ في قاعدة البيانات: ' . $e->getMessage()]);

} catch (Exception $e) {
    // If our safety checks fail (or other exceptions), roll back
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(409); // Conflict (because deletion is blocked by existing data)
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

exit();