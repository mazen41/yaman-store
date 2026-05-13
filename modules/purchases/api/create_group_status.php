<?php
/**
 * API Endpoint: Creates a new purchase group status and updates a group.
 * Path: /modules/purchases/purchase_groups/api/create_group_status.php
 */
session_start();
header('Content-Type: application/json');

require_once '../../../../config/database.php';
require_once '../../../../includes/check_permissions.php';

// --- 1. SECURITY & PERMISSIONS ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

$user_id = $_SESSION['user_id'];
if (!hasPermission($user_id, 'purchase_groups', 'create_status')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لإنشاء حالات جديدة.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

// --- 2. INPUT PROCESSING & VALIDATION ---
$db->beginTransaction();
try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $group_id = intval($input['group_id'] ?? 0);
    $status_key = trim($input['status_key'] ?? '');
    $status_name_ar = trim($input['status_name_ar'] ?? '');

    if (empty($group_id) || empty($status_key) || empty($status_name_ar)) {
        throw new Exception('البيانات المدخلة غير كاملة.');
    }
    if (!preg_match('/^[a-z0-9_]+$/', $status_key)) {
        throw new Exception('المعرّف يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطة سفلية (_).');
    }

    // --- 3. DATABASE LOGIC ---
    $stmt = $db->prepare("SELECT id FROM purchase_group_statuses WHERE status_key = ?");
    $stmt->execute([$status_key]);
    if ($stmt->fetch()) {
        throw new Exception('هذا المعرّف مستخدم بالفعل لحالة أخرى.');
    }

    $insert_stmt = $db->prepare("INSERT INTO purchase_group_statuses (status_key, status_name_ar) VALUES (?, ?)");
    if (!$insert_stmt->execute([$status_key, $status_name_ar])) {
        throw new Exception('فشل في إضافة الحالة الجديدة إلى قاعدة البيانات.');
    }
    
    $update_stmt = $db->prepare("UPDATE purchase_groups SET status = ? WHERE id = ?");
    if (!$update_stmt->execute([$status_key, $group_id])) {
        throw new Exception('فشل تحديث حالة المجموعة.');
    }
    
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'تم إنشاء الحالة الجديدة وتعيينها للمجموعة بنجاح!',
        'new_status' => [
            'status_key' => $status_key,
            'status_name_ar' => $status_name_ar
        ]
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>