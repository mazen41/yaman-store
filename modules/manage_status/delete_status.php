<?php
/**
 * Delete Status Logic
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo "Access denied.";
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    exit();
}

$user_id = $_SESSION['user_id'];
$status_id = $_POST['id'] ?? null;
$table_name = $_POST['table'] ?? '';

// Permission Check
if (!hasPermission($user_id, 'statuses', 'delete')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لحذف الحالات.';
    header('Location: index.php');
    exit();
}

// Whitelist of allowed tables
$allowed_tables = [
    'customer_order_statuses',
    'purchase_basket_statuses',
    'purchase_group_statuses'
];

if (!$status_id || !$table_name || !in_array($table_name, $allowed_tables)) {
    $_SESSION['error_message'] = 'بيانات غير صالحة.';
    header('Location: index.php');
    exit();
}

try {
    // Crucial: Check if the status is default before deleting
    $check_stmt = $db->prepare("SELECT is_default FROM `{$table_name}` WHERE id = ?");
    $check_stmt->execute([$status_id]);
    $status = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if ($status && $status['is_default']) {
        $_SESSION['error_message'] = 'لا يمكن حذف الحالة الافتراضية.';
    } else {
        $stmt = $db->prepare("DELETE FROM `{$table_name}` WHERE id = ?");
        $stmt->execute([$status_id]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success_message'] = 'تم حذف الحالة بنجاح.';
        } else {
            $_SESSION['error_message'] = 'الحالة لم يتم العثور عليها أو تم حذفها بالفعل.';
        }
    }
} catch (PDOException $e) {
    // You might want to check for foreign key constraint errors here
    // For example, if a status is already in use by an order.
    if ($e->getCode() == '23000') {
         $_SESSION['error_message'] = 'لا يمكن حذف هذه الحالة لأنها مستخدمة حاليًا في بعض السجلات.';
    } else {
        $_SESSION['error_message'] = 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage();
    }
}

header('Location: manage_status.php');
exit();