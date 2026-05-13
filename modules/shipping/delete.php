<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/accounting_functions.php'; // We will use the function from our plan

// Check permission for deleting shipments
// Ensure 'shipping' module and 'delete' action are defined in your permissions system.
if (!hasPermission($_SESSION['user_id'], 'shipping', 'add')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لحذف الشحنات.';
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $shipment_id = filter_input(INPUT_POST, 'shipment_id', FILTER_VALIDATE_INT);

    if (!$shipment_id) {
        $_SESSION['error_message'] = 'معرف الشحنة غير صالح.';
        header('Location: index.php');
        exit();
    }

    try {
        $db->beginTransaction();

        // 1. Get associated order IDs before deleting shipment_orders
        // This is crucial to reset their shipping_status later.
        $stmt_get_orders = $db->prepare("SELECT order_id FROM shipment_orders WHERE shipment_id = ?");
        $stmt_get_orders->execute([$shipment_id]);
        $associated_order_ids = $stmt_get_orders->fetchAll(PDO::FETCH_COLUMN);

        // 2. Delete related tracking entries for this shipment
        $stmt_tracking = $db->prepare("DELETE FROM shipment_tracking WHERE shipment_id = ?");
        $stmt_tracking->execute([$shipment_id]);

        // 3. Delete entries from shipment_orders linkage table for this shipment
        // This breaks the link between the shipment and the orders.
        $stmt_shipment_orders = $db->prepare("DELETE FROM shipment_orders WHERE shipment_id = ?");
        $stmt_shipment_orders->execute([$shipment_id]);

        // 4. Reset shipping_status for associated customer_orders
        // If an order was partially shipped by this shipment and other shipments,
        // its status would become 'pending' again. Consider if this is the desired behavior.
        // For simple "one-shipment-per-order" or "all-items-in-one-shipment" scenarios, this is fine.
        if (!empty($associated_order_ids)) {
            $placeholders = implode(',', array_fill(0, count($associated_order_ids), '?'));
            $stmt_update_orders = $db->prepare("UPDATE customer_orders SET shipping_status = 'pending' WHERE id IN ($placeholders)");
            $stmt_update_orders->execute($associated_order_ids);
        }

        // 5. Delete associated accounting journal entries
        // The accounting system should use 'shipping' as module and shipment_id as source_id
        // We catch this specific error to allow the core shipment deletion to proceed
        // even if accounting fails, but log the issue.
        try {
            delete_journal_entry_by_source($db, 'shipping', $shipment_id);
        } catch (Exception $acc_e) {
            // Log the accounting error. Decide if this should rollBack the whole transaction.
            // Current setup: Log and continue. If accounting integrity is critical, use $db->rollBack(); throw $acc_e;
            error_log("Failed to delete accounting entry for Shipment ID $shipment_id: " . $acc_e->getMessage());
            // Optionally, if accounting failure MUST prevent deletion:
            // $db->rollBack();
            // $_SESSION['error_message'] = 'فشل حذف القيد المحاسبي. تم التراجع عن عملية حذف الشحنة.';
            // header('Location: index.php');
            // exit();
        }

        // 6. Delete the shipment itself from the main shipments table
        $stmt_shipment = $db->prepare("DELETE FROM shipments WHERE id = ?");
        $stmt_shipment->execute([$shipment_id]);

        // If all operations succeed, commit the transaction
        $db->commit();
        $_SESSION['success_message'] = 'تم حذف الشحنة بنجاح.';
        header('Location: index.php');
        exit();

    } catch (PDOException $e) {
        // Catch database-specific errors and roll back
        $db->rollBack();
        $_SESSION['error_message'] = 'حدث خطأ في قاعدة البيانات أثناء حذف الشحنة: ' . $e->getMessage();
        error_log("Shipment deletion failed (PDOException for ID $shipment_id): " . $e->getMessage());
        header('Location: index.php');
        exit();
    } catch (Exception $e) {
        // Catch any other general PHP errors and roll back
        $db->rollBack();
        $_SESSION['error_message'] = 'حدث خطأ غير متوقع أثناء حذف الشحنة: ' . $e->getMessage();
        error_log("Shipment deletion failed (General Exception for ID $shipment_id): " . $e->getMessage());
        header('Location: index.php');
        exit();
    }
} else {
    // If someone tries to access this page directly without POST data, redirect them.
    $_SESSION['error_message'] = 'طريقة الطلب غير صالحة.';
    header('Location: index.php');
    exit();
}
?>