<?php
// modules/orders/api/reject_order.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id']) || !isset($_POST['approval_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or missing data.']);
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/check_permissions.php';

if (!hasPermission($_SESSION['user_id'], 'orders', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لرفض الطلبات.']);
    exit();
}

$approval_id = (int)$_POST['approval_id'];
$rejection_reason = trim($_POST['rejection_reason'] ?? 'No reason provided by admin.');
$admin_notes = trim($_POST['admin_notes'] ?? ''); // استقبال ملاحظات الإدارة
$user_id = $_SESSION['user_id'];

try {
    $db->beginTransaction();

    // 1. Update approval status to 'rejected' and save admin notes
    $update_stmt = $db->prepare("UPDATE order_approvals SET status = 'rejected', rejection_reason = ?, admin_notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
    $stmt_result = $update_stmt->execute([$rejection_reason, $admin_notes, $user_id, $approval_id]);
    
    if ($update_stmt->rowCount() == 0) {
        throw new Exception("Approval record not found or already processed.");
    }

    // 2. Update Admin Notification
    $notif_update_stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE related_id = ? AND related_table = 'order_approvals'");
    $notif_update_stmt->execute([$approval_id]);

    $db->commit();
    $_SESSION['success_message'] = 'تم رفض الطلب بنجاح.';
    header('Location: ../approvals.php');
    exit();

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    $_SESSION['error_message'] = 'فشل الرفض: ' . $e->getMessage();
    header('Location: ../view_approval.php?id=' . $approval_id);
    exit();
}