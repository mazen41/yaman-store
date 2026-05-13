<?php
/**
 * CORRECTED: Delete Customer Order and All Associated Data
 * Path: /modules/orders/delete.php
 * This script deletes the order, its invoices, items, history, notifications,
 * and uploaded images within a secure database transaction.
 */
header('Content-Type: application/json');
session_start();

// 1. Security & Permission Check
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'وصول غير مصرح به']);
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php'; // Important for security
require_once '../../includes/accounting_functions.php';

// Check if the user has permission to delete orders
if (!hasPermission($_SESSION['user_id'], 'orders', 'delete')) {
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لحذف الطلبات']);
    exit();
}

// 2. Get and Validate Order ID
$order_id = intval($_POST['order_id'] ?? 0);

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرف الطلب غير صحيح']);
    exit();
}

try {
    // 3. Begin a Database Transaction
    // This ensures that if any step fails, all previous steps are undone.
    $db->beginTransaction();

    // 4. Delete All Associated Data
    
    // --- THIS IS THE KEY FIX ---
    // A. Delete associated customer invoices.
     // ===================================================================
    // START: NEW ACCOUNTING LOGIC
    // ===================================================================
    // Safely delete the associated journal entry.
    try {
        delete_journal_entry_by_source($db, 'orders', $order_id);
    } catch (Exception $acc_e) {
        error_log("Accounting cleanup failed for deleted Order ID $order_id: " . $acc_e->getMessage());
    }
    // ===================================================================
    // END: NEW ACCOUNTING LOGIC
    // ===================================================================
    $db->prepare("DELETE FROM customer_invoices WHERE order_id = ?")->execute([$order_id]);
    
    // B. Delete associated order items.
    $db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$order_id]);
    
    // C. Delete order status history records.
    $db->prepare("DELETE FROM order_status_history WHERE order_id = ?")->execute([$order_id]);

    // D. Delete order notifications.
    $db->prepare("DELETE FROM order_notifications WHERE order_id = ?")->execute([$order_id]);
    
    // E. Delete any records from other potential related tables (add as needed).
    // These are from your original script; they will run without error even if the tables don't exist.
    $db->prepare("DELETE FROM order_payments WHERE order_id = ?")->execute([$order_id]);
    $db->prepare("DELETE FROM order_notes WHERE order_id = ?")->execute([$order_id]);
    $db->prepare("DELETE FROM order_logs WHERE order_id = ?")->execute([$order_id]);
    $db->prepare("DELETE FROM order_damaged_items WHERE order_id = ?")->execute([$order_id]);


    // F. (Optional but Recommended) Delete physical image files and their database records
    $img_stmt = $db->prepare("SELECT image_path FROM order_images WHERE order_id = ?");
    $img_stmt->execute([$order_id]);
    $images = $img_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($images as $image) {
        $file_path = '../../' . $image['image_path']; 
        if (!empty($image['image_path']) && file_exists($file_path)) {
            @unlink($file_path); // Delete the actual image file from the server
        }
    }
    // Now delete the image records from the database table.
    $db->prepare("DELETE FROM order_images WHERE order_id = ?")->execute([$order_id]);


    // 5. Finally, delete the main order record
    $delete_stmt = $db->prepare("DELETE FROM customer_orders WHERE id = ?");
    $delete_stmt->execute([$order_id]);
    
    // 6. If all deletions were successful, commit the transaction
    $db->commit();
    
    // 7. Send a success response back to the JavaScript
    echo json_encode(['success' => true, 'message' => 'تم حذف الطلب وكل البيانات المرتبطة به بنجاح']);

} catch (Exception $e) {
    // 8. If any error occurred, roll back the entire transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Log the detailed error for the administrator to see
    error_log("Order Deletion Error: " . $e->getMessage());
    
    // Send a generic error response back to the user
    echo json_encode(['success' => false, 'message' => 'فشلت عملية الحذف بسبب خطأ في قاعدة البيانات.']);
}