<?php
/**
 * api/login.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: POST /api/login.php
 * Authenticates user credentials and issues access & refresh tokens.
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/api_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام POST.', 405);
}

// ── Read JSON body ───────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$usernameInput = trim($body['username'] ?? $body['email'] ?? '');
$passwordInput = $body['password'] ?? '';

if (empty($usernameInput) || empty($passwordInput)) {
    fail('يرجى إدخال اسم المستخدم/البريد الإلكتروني وكلمة المرور.', 400);
}

try {
    // Check credentials (similar to login.php)
    $stmt = $db->prepare("
        SELECT id, username, password, full_name, role, is_active
        FROM users
        WHERE username = ? OR email = ?
        LIMIT 1
    ");
    $stmt->execute([$usernameInput, $usernameInput]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($passwordInput, $user['password'])) {
        fail('اسم المستخدم أو كلمة المرور غير صحيحة.', 401);
    }

    if (isset($user['is_active']) && $user['is_active'] == 0) {
        fail('الحساب غير نشط. يرجى الاتصال بمسؤول النظام.', 403);
    }

    // ── Generate Access Token (7 days = 604800 seconds) ──────────────
    $accessTokenRaw = bin2hex(random_bytes(32));
    $accessTokenHash = hash('sha256', $accessTokenRaw);
    $accessTokenExpiry = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);

    // Save Access Token in auth_tokens
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $tokenStmt = $db->prepare("
        INSERT INTO auth_tokens (user_id, token, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    $tokenStmt->execute([
        $user['id'],
        $accessTokenHash,
        $accessTokenExpiry,
        $clientIp,
        $userAgent
    ]);

    // ── Generate Refresh Token (60 days) ─────────────────────────────
    $refreshTokenRaw = bin2hex(random_bytes(32));
    $refreshTokenHash = hash('sha256', $refreshTokenRaw);
    $refreshTokenExpiry = date('Y-m-d H:i:s', time() + 60 * 24 * 60 * 60);

    // Save Refresh Token in refresh_tokens
    $refreshStmt = $db->prepare("
        INSERT INTO refresh_tokens (user_id, token, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    $refreshStmt->execute([
        $user['id'],
        $refreshTokenHash,
        $refreshTokenExpiry,
        $clientIp,
        $userAgent
    ]);

    // Update last_login timestamp
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
       ->execute([$user['id']]);

    // Return tokens and user metadata
    ok([
        'access_token' => $accessTokenRaw,
        'refresh_token' => $refreshTokenRaw,
        'expires_in' => 7 * 24 * 60 * 60, // 7 days in seconds
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['full_name'],
            'role' => $user['role']
        ]
    ]);

} catch (PDOException $e) {
    fail('حدث خطأ أثناء محاولة تسجيل الدخول: ' . $e->getMessage(), 500);
}
