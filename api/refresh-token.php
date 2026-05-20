<?php
/**
 * api/refresh-token.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: POST /api/refresh-token.php
 * Verifies a refresh token, invalidates it, and issues a new pair.
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/api_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام POST.', 405);
}

// ── Read JSON body ───────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$refreshTokenRaw = trim($body['refresh_token'] ?? '');

if (empty($refreshTokenRaw)) {
    fail('يرجى إرسال رمز التحديث (refresh_token) لتجديد الجلسة.', 400);
}

$refreshTokenHash = hash('sha256', $refreshTokenRaw);

try {
    // Validate refresh token
    $stmt = $db->prepare("
        SELECT id, user_id, expires_at, is_valid
        FROM refresh_tokens
        WHERE token = ? AND is_valid = 1
        LIMIT 1
    ");
    $stmt->execute([$refreshTokenHash]);
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow) {
        fail('رمز التحديث غير صالح أو تم إلغاؤه بالفعل.', 401);
    }

    if (strtotime($tokenRow['expires_at']) < time()) {
        $db->prepare("UPDATE refresh_tokens SET is_valid = 0 WHERE id = ?")
           ->execute([$tokenRow['id']]);
        fail('انتهت صلاحية رمز التحديث، الرجاء تسجيل الدخول مجدداً.', 401);
    }

    // ── Token Rotation (Invalidate old refresh token) ──────────────
    $db->prepare("UPDATE refresh_tokens SET is_valid = 0 WHERE id = ?")
       ->execute([$tokenRow['id']]);

    // ── Issue New Access Token (7 days = 604800 seconds) ────────────
    $newAccessTokenRaw = bin2hex(random_bytes(32));
    $newAccessTokenHash = hash('sha256', $newAccessTokenRaw);
    $newAccessTokenExpiry = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $tokenStmt = $db->prepare("
        INSERT INTO auth_tokens (user_id, token, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    $tokenStmt->execute([
        $tokenRow['user_id'],
        $newAccessTokenHash,
        $newAccessTokenExpiry,
        $clientIp,
        $userAgent
    ]);

    // ── Issue New Refresh Token (60 days) ───────────────────────────
    $newRefreshTokenRaw = bin2hex(random_bytes(32));
    $newRefreshTokenHash = hash('sha256', $newRefreshTokenRaw);
    $newRefreshTokenExpiry = date('Y-m-d H:i:s', time() + 60 * 24 * 60 * 60);

    $refreshStmt = $db->prepare("
        INSERT INTO refresh_tokens (user_id, token, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    $refreshStmt->execute([
        $tokenRow['user_id'],
        $newRefreshTokenHash,
        $newRefreshTokenExpiry,
        $clientIp,
        $userAgent
    ]);

    ok([
        'access_token' => $newAccessTokenRaw,
        'refresh_token' => $newRefreshTokenRaw,
        'expires_in' => 7 * 24 * 60 * 60 // 7 days in seconds
    ]);

} catch (PDOException $e) {
    fail('حدث خطأ أثناء تجديد الرمز: ' . $e->getMessage(), 500);
}
