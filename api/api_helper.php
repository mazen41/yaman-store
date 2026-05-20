<?php
/**
 * api/api_helper.php
 * ─────────────────────────────────────────────────────────────────
 * Shared helper for the Yaman mobile scanner API.
 * Handles CORS, error responses, self-healing DB schemas, and auth.
 * ─────────────────────────────────────────────────────────────────
 */

// ── CORS Headers ──────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// ── Load Configuration ────────────────────────────────────────────
require_once __DIR__ . '/../config/database.php';

// ── Ensure self-healing database tables exist ─────────────────────
ensureApiSchema($db);

/**
 * Auto-creates token and device registration tables if not present.
 */
function ensureApiSchema(PDO $db): void
{
    try {
        // Create refresh_tokens table
        $db->exec("
            CREATE TABLE IF NOT EXISTS `refresh_tokens` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `token` VARCHAR(64) NOT NULL UNIQUE,
                `expires_at` DATETIME NOT NULL,
                `is_valid` TINYINT(1) DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `ip_address` VARCHAR(45) NOT NULL,
                `user_agent` TEXT DEFAULT NULL,
                INDEX `idx_user_refresh` (`user_id`),
                INDEX `idx_token_hash` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Create registered_devices table
        $db->exec("
            CREATE TABLE IF NOT EXISTS `registered_devices` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `device_id` VARCHAR(255) NOT NULL UNIQUE,
                `device_name` VARCHAR(255) DEFAULT NULL,
                `platform` VARCHAR(50) DEFAULT 'android',
                `app_version` VARCHAR(50) DEFAULT NULL,
                `fcm_token` TEXT DEFAULT NULL,
                `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `last_active_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_user_devices` (`user_id`),
                INDEX `idx_device_lookup` (`device_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (PDOException $e) {
        // Log errors but let request continue in case tables already exist
        error_log("API Database Schema ensure failure: " . $e->getMessage());
    }
}

/**
 * Return successful JSON response.
 */
function ok(array $data): void
{
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Return failure JSON response and terminate.
 */
function fail(string $message, int $code = 422): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Authenticate incoming request using Authorization: Bearer token header.
 * Checks against the `auth_tokens` table.
 * Returns array containing user details if successful, otherwise terminates with 401.
 */
function authenticateRequest(PDO $db): array
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (empty($authHeader)) {
        // Fallback for some Apache environments
        if (function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            $authHeader = $apacheHeaders['Authorization'] ?? $apacheHeaders['authorization'] ?? '';
        }
    }
    
    if (empty($authHeader) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        fail('يبدو أنك غير مسجل، الرجاء إرسال رمز تفويض صالح.', 401);
    }

    $rawToken = trim($matches[1]);
    $tokenHash = hash('sha256', $rawToken);

    try {
        $stmt = $db->prepare("
            SELECT t.user_id, t.expires_at, t.is_valid,
                   u.username, u.full_name, u.role, u.is_admin
            FROM auth_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.token = ? AND t.is_valid = 1
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            fail('رمز الدخول غير صالح أو تم إلغاؤه.', 401);
        }

        if (strtotime($session['expires_at']) < time()) {
            // Update token validity in DB
            $db->prepare("UPDATE auth_tokens SET is_valid = 0 WHERE token = ?")
               ->execute([$tokenHash]);
            fail('انتهت صلاحية رمز الدخول، يرجى تجديده.', 401);
        }

        // Update token's last used timestamp
        $db->prepare("UPDATE auth_tokens SET last_used_at = NOW() WHERE token = ?")
           ->execute([$tokenHash]);

        return [
            'id' => (int)$session['user_id'],
            'username' => $session['username'],
            'name' => $session['full_name'],
            'role' => $session['role'],
            'is_admin' => (int)$session['is_admin']
        ];
    } catch (PDOException $e) {
        fail('حدث خطأ في قاعدة البيانات أثناء التحقق من الهوية.', 500);
    }
    return [];
}
