<?php
/**
 * Delete Customer Invoice - Action File
 * ملف حذف فاتورة عميل
 */

// --- 1. INITIALIZATION & SECURITY ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
// Redirect to login if the user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// --- 2. DATABASE CONNECTION ---
require_once '../../config/database.php';

// --- 3. VALIDATE THE INVOICE ID ---
// Get the invoice ID from the URL and ensure it's an integer
$invoice_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;

if (!$invoice_id) {
    // If the ID is invalid or missing, set an error message and redirect
    $_SESSION['error_message'] = "رقم الفاتورة غير صحيح أو مفقود.";
    header('Location: show_invoices.php');
    exit();
}

// --- 4. PERFORM THE DELETION ---
try {
    // Prepare the DELETE statement to prevent SQL injection
    $sql = "DELETE FROM customer_invoices WHERE id = :id";
    $stmt = $db->prepare($sql);

    // Bind the integer ID to the placeholder
    $stmt->bindParam(':id', $invoice_id, PDO::PARAM_INT);

    // Execute the query
    $stmt->execute();

    // Check if any row was actually deleted
    if ($stmt->rowCount() > 0) {
        // A row was affected, so the deletion was successful
        $_SESSION['success_message'] = "تم حذف الفاتورة بنجاح!";
    } else {
        // No rows were affected, meaning the invoice ID did not exist
        $_SESSION['error_message'] = "الفاتورة غير موجودة أو قد تم حذفها بالفعل.";
    }

} catch (PDOException $e) {
    // If the database query fails, set a generic error message
    // In a real-world application, you should log the detailed error: $e->getMessage()
    $_SESSION['error_message'] = "حدث خطأ أثناء محاولة حذف الفاتورة.";
}

// --- 5. REDIRECT BACK TO THE INVOICE LIST ---
// This will display the success or error message set above
header('Location: show_invoices.php');
exit();

?>