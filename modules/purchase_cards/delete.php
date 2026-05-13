<?php
session_start();

// 1. Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // If not logged in, redirect to the login page
    header('Location: ../../login.php');
    exit();
}

// 2. Include necessary files
require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// 3. Verify user has permission to delete purchase cards
if (!hasPermission($_SESSION['user_id'], 'purchase_cards', 'delete')) {
    // If they don't have permission, set an error message and redirect
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتنفيذ هذا الإجراء.';
    header('Location: index.php'); // Redirect back to the purchase cards list
    exit();
}

// 4. Check if the 'id' parameter is set in the URL and is not empty
if (!isset($_GET['id']) || empty($_GET['id'])) {
    // If no ID is provided, set an error and redirect
    $_SESSION['error_message'] = 'معرف البطاقة المطلوب حذفه غير محدد.';
    header('Location: index.php');
    exit();
}

// 5. Sanitize the ID to ensure it's an integer
$id_to_delete = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

// Use a try-catch block to handle potential database errors
try {
    // Prepare the SQL DELETE statement to prevent SQL injection
    $stmt = $db->prepare("DELETE FROM purchase_cards WHERE id = :id");
    
    // Bind the sanitized ID to the placeholder in the query
    $stmt->bindParam(':id', $id_to_delete, PDO::PARAM_INT);
    
    // Execute the deletion query
    $stmt->execute();

    // 6. Check if a row was actually deleted
    if ($stmt->rowCount() > 0) {
        // If deletion was successful, set a success message
        $_SESSION['success_message'] = 'تم حذف بطاقة الشراء بنجاح.';
    } else {
        // If no rows were affected (e.g., card with that ID didn't exist), set an info message
        $_SESSION['error_message'] = 'لم يتم العثور على البطاقة المحددة أو قد تم حذفها بالفعل.';
    }

} catch (PDOException $e) {
    // 7. If a database error occurs (like a foreign key constraint), catch it
    // This prevents the application from crashing and provides a user-friendly error
    $_SESSION['error_message'] = 'فشل حذف البطاقة. قد تكون مرتبطة بعمليات مسجلة. Error: ' . $e->getMessage();
}

// 8. Redirect the user back to the main purchase cards list page
header('Location: index.php');
exit();