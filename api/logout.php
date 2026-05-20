<?php
/**
 * api/logout.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: POST /api/logout.php
 * Logs out the user by invalidating their tokens in the database.
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/api_helper.php';

// Authenticate via Bearer token
$user = authenticateRequest($db);

// Extract raw token from header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches);
$rawToken = trim($matches[1]);
$tokenHash = hash('sha256', $rawToken);

try {
    $db->beginTransaction();

    // 1. Invalidate current access token
    $db->prepare("UPDATE auth_tokens SET is_valid = 0 WHERE token = ?")
       ->execute([$tokenHash]);

    // 2. Invalidate all active refresh tokens for this user
    $db->prepare("UPDATE refresh_tokens SET is_valid = 0 WHERE user_id = ? AND is_valid = 1")
       ->execute([$user['id']]);

    $db->commit();

    ok([
        'message' => 'تم تسجيل الخروج وإلغاء صلاحية الرموز بنجاح.'
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fail('حدث خطأ أثناء محاولة تسجيل الخروج: ' . $e->getMessage(), 500);
}
