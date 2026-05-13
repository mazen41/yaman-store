<?php
/**
 * API Endpoint: Update Purchase Group Status
 * Path: /modules/purchases/purchase_groups/api/update_group_status.php
 */

// 1. بدء التخزين المؤقت للمخرجات فوراً لمنع أي ملف آخر من طباعة مسافات أو HTML تخرب الـ JSON
ob_start();
session_start();

// 2. إجبار استجابة بصيغة JSON
header('Content-Type: application/json; charset=utf-8');

try {
    require_once '../../../../config/database.php';
    require_once '../../../../includes/check_permissions.php';

    // مسح أي نصوص/أخطاء طبعتها الملفات المضمنة أعلاه لتجنب كسر الـ JSON
    $buffer = ob_get_clean(); 
    
    // إعادة إرسال الـ Header تحسباً لأي مسح
    header('Content-Type: application/json; charset=utf-8');

    // --- 1. SECURITY & PERMISSIONS ---
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('غير مصرح - الرجاء تسجيل الدخول أولاً', 401);
    }

    $user_id = $_SESSION['user_id'];
    if (!hasPermission($user_id, 'purchase_groups', 'edit')) {
        throw new Exception('ليس لديك صلاحية لتعديل المجموعات.', 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير صالحة.', 405);
    }

    // --- 2. INPUT & VALIDATION ---
    $input = json_decode(file_get_contents('php://input'), true);

    $group_id = intval($input['group_id'] ?? 0);
    $status_key = trim($input['status'] ?? '');

    if (empty($group_id) || empty($status_key)) {
        throw new Exception('معرف المجموعة أو الحالة مفقود.', 400);
    }

    // تم تغيير البحث ليكون بدلالة status_key تجنباً لمشكلة غياب عمود id
    $stmt = $db->prepare("SELECT status_key FROM purchase_group_statuses WHERE status_key = ?");
    $stmt->execute([$status_key]);
    if (!$stmt->fetch()) {
        throw new Exception('الحالة المحددة غير صالحة.', 400);
    }

    // --- 3. DATABASE UPDATE ---
    $update_stmt = $db->prepare("UPDATE purchase_groups SET status = ?, updated_at = NOW() WHERE id = ?");
    $success = $update_stmt->execute([$status_key, $group_id]);

    if (!$success) {
        throw new Exception('فشل تحديث قاعدة البيانات.', 500);
    }

    echo json_encode(['success' => true, 'message' => 'تم تحديث حالة المجموعة بنجاح.']);

} catch (Throwable $e) {
    // التقاط جميع الأخطاء (حتى أخطاء قواعد البيانات Fatal Errors) وطباعتها كـ JSON حقيقي
    
    // تنظيف البفر في حال حدث الخطأ أثناء التحميل
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $code = $e->getCode();
    if (!is_numeric($code) || $code < 100 || $code > 599) {
        $code = 400; // الافتراضي
    }
    
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>