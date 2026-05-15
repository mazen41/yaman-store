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

if (!canRejectOrderApprovals($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لرفض الطلبات.']);
    exit();
}

$approval_id = (int)$_POST['approval_id'];
$rejection_reason = trim($_POST['rejection_reason'] ?? 'No reason provided by admin.');
$admin_notes = trim($_POST['admin_notes'] ?? ''); // استقبال ملاحظات الإدارة
$whatsapp_contact_legacy = trim((string) ($_POST['whatsapp_contact'] ?? ''));
$user_id = $_SESSION['user_id'];

try {
    $db->beginTransaction();

    // 1. Fetch customer details for WhatsApp notification
    $customer_stmt = $db->prepare("SELECT c.mobile_number, c.whatsapp_number, c.name FROM order_approvals oa LEFT JOIN customers c ON oa.customer_id = c.id WHERE oa.id = ?");
    $customer_stmt->execute([$approval_id]);
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);

    $selected_numbers = [];
    $uses_contact_picker = !empty($_POST['whatsapp_contacts_form']);
    $posted_contacts = (isset($_POST['whatsapp_contacts']) && is_array($_POST['whatsapp_contacts'])) ? $_POST['whatsapp_contacts'] : [];

    if ($uses_contact_picker) {
        foreach ($posted_contacts as $c) {
            $c = trim((string) $c);
            if ($c === 'mobile' && !empty($customer['mobile_number'])) {
                $selected_numbers[] = trim($customer['mobile_number']);
            }
            if ($c === 'whatsapp' && !empty($customer['whatsapp_number'])) {
                $selected_numbers[] = trim($customer['whatsapp_number']);
            }
        }
    } elseif ($whatsapp_contact_legacy === '2' && !empty($customer['whatsapp_number'])) {
        $selected_numbers[] = trim($customer['whatsapp_number']);
    } elseif ($whatsapp_contact_legacy === '1' && !empty($customer['mobile_number'])) {
        $selected_numbers[] = trim($customer['mobile_number']);
    } elseif (!$uses_contact_picker && !empty($customer['mobile_number'])) {
        $selected_numbers[] = trim($customer['mobile_number']);
    }
    $selected_numbers = array_values(array_unique(array_filter($selected_numbers)));

    // 2. Update approval status to 'rejected' and save admin notes
    $update_stmt = $db->prepare("UPDATE order_approvals SET status = 'rejected', rejection_reason = ?, admin_notes = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
    $stmt_result = $update_stmt->execute([$rejection_reason, $admin_notes, $user_id, $approval_id]);

    if ($update_stmt->rowCount() == 0) {
        throw new Exception("Approval record not found or already processed.");
    }

    // 3. Update Admin Notification
    $notif_update_stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE related_id = ? AND related_table = 'order_approvals'");
    $notif_update_stmt->execute([$approval_id]);

    // 4. Create WhatsApp notification records for each selected number
    if (!empty($selected_numbers)) {
        $notif_stmt = $db->prepare("INSERT INTO notifications (related_id, related_table, type, status, sent_to, title, message, created_at) VALUES (?, ?, 'whatsapp', 'pending', ?, ?, ?, NOW())");
        $notif_title = 'تم رفض طلبك #' . $approval_id;
        $notif_message = 'عزيزي ' . ($customer['name'] ?? 'العميل') . '، نود إبلاغك بأنه تم رفض طلبك #' . $approval_id . '. السبب: ' . $rejection_reason;
        foreach ($selected_numbers as $num) {
            $notif_stmt->execute([$approval_id, 'order_approvals', $num, $notif_title, $notif_message]);
        }
    }

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