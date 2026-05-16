<?php
/**
 * Security Middleware
 * Apply to all protected pages
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/security_config.php';

// Initialize security manager
if (!isset($security)) {
    $security = new SecurityManager($db);
}

// Check authentication
function requireAuth() {
    global $security;
    
    // Check session
    if (!isset($_SESSION['user_id'])) {
        // Try token authentication
        if (isset($_COOKIE['auth_token'])) {
            $user_id = $security->validateAuthToken($_COOKIE['auth_token']);
            if ($user_id) {
                // Restore session from token
                $_SESSION['user_id'] = $user_id;
                $_SESSION['last_activity'] = time();
                return true;
            } else {
                // Invalid token, clear cookie
                setcookie('auth_token', '', time() - 3600, '/', '', true, true);
            }
        }
        
        // Not authenticated
        header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit();
    }
    
    return true;
}

// Check if user is admin
function requireAdmin() {
    requireAuth();
    
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
        http_response_code(403);
        die('Access Denied: Admin privileges required');
    }
}

// CSRF Protection for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    if (!$security->validateCSRFToken($csrf_token)) {
        http_response_code(403);
        die('CSRF token validation failed');
    }
}

// XSS Protection Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// Content Security Policy
$csp = "default-src 'self'; " .
       "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
       "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
       "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
       "img-src 'self' data: https:; " .
       "connect-src 'self';";
header("Content-Security-Policy: $csp");

// Sanitize all GET parameters
if (!empty($_GET)) {
    foreach ($_GET as $key => $value) {
        $_GET[$key] = $security->sanitizeInput($value);
    }
}

// Auto-logout on inactivity (handled in SecurityManager)
// Session validation (handled in SecurityManager)

// Clean expired data periodically (1% chance per request)
if (rand(1, 100) === 1) {
    $security->cleanExpiredData();
}
