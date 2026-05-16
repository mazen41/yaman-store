<?php
/**
 * Security Configuration
 * AES-256-CBC Encryption, CSRF Protection, Session Management
 */

// Security Constants
define('ENCRYPTION_METHOD', 'AES-256-CBC');
define('SESSION_LIFETIME', 120 * 60); // 120 minutes
define('INACTIVITY_TIMEOUT', 30 * 60); // 30 minutes
define('ENCRYPTION_KEY', getenv('APP_ENCRYPTION_KEY') ?: 'your-32-character-secret-key-here-change-this');
define('CSRF_TOKEN_LENGTH', 32);

class SecurityManager {
    private $db;
    private $encryption_key;
    private $encryption_iv;
    
    public function __construct($db) {
        $this->db = $db;
        $this->encryption_key = hash('sha256', ENCRYPTION_KEY);
        $this->initializeSession();
    }
    
    /**
     * Initialize secure session
     */
    private function initializeSession() {
        if (session_status() === PHP_SESSION_NONE) {
            // Secure session configuration
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', 1); // HTTPS only
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
            
            session_name('SECURE_SESSION_ID');
            session_start();
            
            // Regenerate session ID periodically
            if (!isset($_SESSION['created'])) {
                $_SESSION['created'] = time();
            } else if (time() - $_SESSION['created'] > 1800) {
                session_regenerate_id(true);
                $_SESSION['created'] = time();
            }
            
            // Check for session hijacking
            $this->validateSession();
            
            // Check inactivity timeout
            $this->checkInactivity();
        }
    }
    
    /**
     * Validate session against hijacking
     */
    private function validateSession() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip_address = $this->getClientIP();
        
        if (!isset($_SESSION['user_agent'])) {
            $_SESSION['user_agent'] = $user_agent;
            $_SESSION['ip_address'] = $ip_address;
        } else {
            // Validate user agent and IP
            if ($_SESSION['user_agent'] !== $user_agent) {
                $this->destroySession();
                throw new Exception('Session hijacking detected - User Agent mismatch');
            }
            
            // Allow IP change but log it (for mobile users)
            if ($_SESSION['ip_address'] !== $ip_address) {
                $this->logSecurityEvent('ip_change', [
                    'old_ip' => $_SESSION['ip_address'],
                    'new_ip' => $ip_address
                ]);
                $_SESSION['ip_address'] = $ip_address;
            }
        }
    }
    
    /**
     * Check session inactivity timeout
     */
    private function checkInactivity() {
        if (isset($_SESSION['user_id'])) {
            $last_activity = $_SESSION['last_activity'] ?? time();
            
            if (time() - $last_activity > INACTIVITY_TIMEOUT) {
                $this->logSecurityEvent('auto_logout', [
                    'reason' => 'inactivity_timeout',
                    'inactive_duration' => time() - $last_activity
                ]);
                $this->destroySession();
                header('Location: /login.php?timeout=1');
                exit();
            }
            
            $_SESSION['last_activity'] = time();
        }
    }
    
    /**
     * AES-256-CBC Encryption
     */
    public function encrypt($data) {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
        $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, $this->encryption_key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
    
    /**
     * AES-256-CBC Decryption
     */
    public function decrypt($data) {
        try {
            list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
            return openssl_decrypt($encrypted_data, ENCRYPTION_METHOD, $this->encryption_key, 0, $iv);
        } catch (Exception $e) {
            $this->logSecurityEvent('decryption_failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Generate CSRF Token
     */
    public function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) || 
            (time() - $_SESSION['csrf_token_time']) > 3600) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Validate CSRF Token
     */
    public function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            $this->logSecurityEvent('csrf_validation_failed', [
                'expected' => $_SESSION['csrf_token'] ?? 'none',
                'received' => $token
            ]);
            return false;
        }
        return true;
    }
    
    /**
     * XSS Protection - Sanitize output
     */
    public function sanitizeOutput($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeOutput'], $data);
        }
        return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * SQL Injection Protection - Validate input
     */
    public function sanitizeInput($data, $type = 'string') {
        switch ($type) {
            case 'int':
                return filter_var($data, FILTER_VALIDATE_INT);
            case 'float':
                return filter_var($data, FILTER_VALIDATE_FLOAT);
            case 'email':
                return filter_var($data, FILTER_VALIDATE_EMAIL);
            case 'url':
                return filter_var($data, FILTER_VALIDATE_URL);
            case 'string':
            default:
                return trim(strip_tags($data));
        }
    }
    
    /**
     * Create authentication token
     */
    public function createAuthToken($user_id) {
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO auth_tokens (user_id, token, expires_at, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                hash('sha256', $token),
                $expires_at,
                $this->getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            return $token;
        } catch (PDOException $e) {
            $this->logSecurityEvent('token_creation_failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Validate authentication token
     */
    public function validateAuthToken($token) {
        try {
            $stmt = $this->db->prepare("
                SELECT user_id, expires_at 
                FROM auth_tokens 
                WHERE token = ? AND is_valid = 1
            ");
            $stmt->execute([hash('sha256', $token)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return false;
            }
            
            // Check expiration
            if (strtotime($result['expires_at']) < time()) {
                $this->invalidateToken($token);
                return false;
            }
            
            // Update last used
            $this->db->prepare("UPDATE auth_tokens SET last_used_at = NOW() WHERE token = ?")
                     ->execute([hash('sha256', $token)]);
            
            return $result['user_id'];
        } catch (PDOException $e) {
            $this->logSecurityEvent('token_validation_failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Invalidate token
     */
    public function invalidateToken($token) {
        try {
            $this->db->prepare("UPDATE auth_tokens SET is_valid = 0 WHERE token = ?")
                     ->execute([hash('sha256', $token)]);
        } catch (PDOException $e) {
            $this->logSecurityEvent('token_invalidation_failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Log session activity
     */
    public function logSession($user_id, $action) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO session_logs (user_id, session_id, action, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user_id,
                session_id(),
                $action,
                $this->getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (PDOException $e) {
            error_log("Session logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * Log security events
     */
    private function logSecurityEvent($event_type, $details = []) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO security_logs (event_type, user_id, ip_address, user_agent, details, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $event_type,
                $_SESSION['user_id'] ?? null,
                $this->getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                json_encode($details)
            ]);
        } catch (PDOException $e) {
            error_log("Security logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get client IP address
     */
    private function getClientIP() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                    'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Destroy session securely
     */
    public function destroySession() {
        if (isset($_SESSION['user_id'])) {
            $this->logSession($_SESSION['user_id'], 'logout');
        }
        
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    /**
     * Clean expired sessions and tokens
     */
    public function cleanExpiredData() {
        try {
            // Clean expired tokens
            $this->db->exec("DELETE FROM auth_tokens WHERE expires_at < NOW()");
            
            // Clean old session logs (keep 90 days)
            $this->db->exec("DELETE FROM session_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            
            // Clean old security logs (keep 180 days)
            $this->db->exec("DELETE FROM security_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
        } catch (PDOException $e) {
            error_log("Cleanup failed: " . $e->getMessage());
        }
    }
}

/**
 * Helper function to get CSRF token field
 */
function csrf_field() {
    global $security;
    $token = $security->generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Helper function to get CSRF token meta tag
 */
function csrf_meta() {
    global $security;
    $token = $security->generateCSRFToken();
    return '<meta name="csrf-token" content="' . $token . '">';
}
