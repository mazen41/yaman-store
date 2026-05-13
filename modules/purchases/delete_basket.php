<?php
/**
 * Delete Purchase Basket
 * ملف حذف سلة الشراء مع تحديث الطلبات المرتبطة
 */

// 1. INITIALIZATION & SECURITY
session_start();

// Redirect if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Include database configuration
require_once '../../config/database.php';

// 2. VALIDATE THE INCOMING REQUEST
// Check if an ID is provided in the URL and if it's a valid number
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    // If no valid ID, redirect back to the list with an error message
    $_SESSION['error_message'] = "معرف السلة غير صالح أو مفقود.";
    header('Location: index.php');
    exit();
}

$basket_id = intval($_GET['id']);

// 3. DATABASE OPERATIONS
try {
    // Start a transaction to ensure all operations succeed or none do
    $db->beginTransaction();

    // --- Step A: "Release" the customer orders linked to this basket ---
    // This is the most important step. We find all orders that were part of this basket
    // and reset their status and basket_id. This makes them available again.
    $update_orders_stmt = $db->prepare(
        "UPDATE customer_orders SET status = 'new', basket_id = NULL WHERE basket_id = ?"
    );
    $update_orders_stmt->execute([$basket_id]);

    // --- Step B: Delete all items from the `basket_items` table ---
    // This cleans up the child records before deleting the parent record.
    $delete_items_stmt = $db->prepare("DELETE FROM basket_items WHERE basket_id = ?");
    $delete_items_stmt->execute([$basket_id]);

    // --- Step C: (Optional) Delete any activity logs for this basket ---
    try {
        $delete_log_stmt = $db->prepare("DELETE FROM basket_activity_log WHERE basket_id = ?");
        $delete_log_stmt->execute([$basket_id]);
    } catch (PDOException $e) {
        // If the log table doesn't exist, we can safely ignore this error and continue.
    }

    // --- Step D: Finally, delete the basket itself from `purchase_baskets` ---
    $delete_basket_stmt = $db->prepare("DELETE FROM purchase_baskets WHERE id = ?");
    $delete_basket_stmt->execute([$basket_id]);

    // Check if the deletion was successful
    if ($delete_basket_stmt->rowCount() > 0) {
        // If one or more rows were deleted, the operation was a success.
        $_SESSION['success_message'] = "تم حذف سلة الشراء بنجاح، وتم تحرير الطلبات المرتبطة بها.";
    } else {
        // If zero rows were deleted, it means the basket ID didn't exist.
        throw new Exception("لم يتم العثور على سلة الشراء بالمعرف المحدد.");
    }

    // If all operations were successful, commit the changes to the database
    $db->commit();

} catch (Exception $e) {
    // If any operation fails, roll back all changes
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Store a detailed error message to show to the user
    $_SESSION['error_message'] = "فشل حذف سلة الشراء: " . $e->getMessage();
}

// 4. REDIRECT BACK TO THE LIST
// Redirect the user back to the main basket list page. The list page should be
// configured to display the success or error message from the session.
header('Location: index.php');
exit();
?>