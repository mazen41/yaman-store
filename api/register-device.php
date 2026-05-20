<?php
/**
 * api/register-device.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: POST /api/register-device.php
 * Registers or updates a user's mobile device metadata & FCM token.
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/api_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام POST.', 405);
}

// Authenticate via Bearer token
$user = authenticateRequest($db);

// ── Read JSON body ───────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$deviceId = trim($body['device_id'] ?? '');
$deviceName = trim($body['device_name'] ?? '');
$platform = trim($body['platform'] ?? 'android');
$appVersion = trim($body['app_version'] ?? '');
$fcmToken = trim($body['fcm_token'] ?? '');

if (empty($deviceId)) {
    fail('المعرف الفريد للجهاز (device_id) مطلوب.', 400);
}

try {
    // Upsert the device registration details
    $stmt = $db->prepare("
        INSERT INTO registered_devices (user_id, device_id, device_name, platform, app_version, fcm_token)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            device_name = VALUES(device_name),
            platform = VALUES(platform),
            app_version = VALUES(app_version),
            fcm_token = VALUES(fcm_token),
            last_active_at = NOW()
    ");
    $stmt->execute([
        $user['id'],
        $deviceId,
        $deviceName,
        $platform,
        $appVersion,
        $fcmToken
    ]);

    ok([
        'message' => 'تم تسجيل الجهاز وتحديث البيانات بنجاح.'
    ]);

} catch (PDOException $e) {
    fail('حدث خطأ في قاعدة البيانات أثناء تسجيل الجهاز: ' . $e->getMessage(), 500);
}
