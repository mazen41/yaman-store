<?php
// Application Configuration

// Application Information
define('APP_NAME', 'Yaman Accounting Calculator');
define('APP_VERSION', '1.0.0');
define('APP_DESCRIPTION', 'نظام إدارة شامل للأعمال التجارية');

// Paths
define('ROOT_PATH', dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('MODULES_PATH', ROOT_PATH . '/modules');
define('CONFIG_PATH', ROOT_PATH . '/config');

// URL Configuration (adjust based on your setup)
define('BASE_URL', '');
define('ASSETS_URL', BASE_URL . 'assets/');

// Date and Time (set to Yemen time / Aden timezone)
date_default_timezone_set('Asia/Aden');
define('DEFAULT_DATE_FORMAT', 'd/m/Y');
define('DEFAULT_DATETIME_FORMAT', 'd/m/Y H:i:s');

// Pagination
define('DEFAULT_PAGE_SIZE', 10);
define('MAX_PAGE_SIZE', 100);

// File Upload
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx']);
define('UPLOAD_PATH', ROOT_PATH . '/uploads/');

// Security
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes in seconds

// Application Settings (can be overridden by database settings)
$default_settings = [
    'company_name' => 'Yaman Accounting Calculator',
    'company_address' => 'اليمن',
    'company_phone' => '967xxxxxxxxx',
    'company_email' => 'info@yassin-admin.com',
    'currency' => 'ريال يمني',
    'tax_rate' => '15',
    'timezone' => 'Asia/Aden',
    'date_format' => 'd/m/Y',
    'items_per_page' => '10',
    'system_language' => 'ar'
];

// Error Reporting (adjust for production)
if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', ROOT_PATH . '/logs/error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Helper Functions
function get_setting($key, $default = null) {
    global $db, $default_settings;
    
    static $settings_cache = null;
    
    if ($settings_cache === null) {
        $settings_cache = $default_settings;
        
        if (isset($db)) {
            try {
                $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings");
                $stmt->execute();
                while ($row = $stmt->fetch()) {
                    $settings_cache[$row['setting_key']] = $row['setting_value'];
                }
            } catch (PDOException $e) {
                // If database is not available, use default settings
            }
        }
    }
    
    return $settings_cache[$key] ?? $default;
}

function format_currency($amount, $currency = null) {
    if ($currency === null) {
        $currency = get_setting('currency', 'ريال يمني');
    }

    $amount = (float) $amount;
    $normalizedCurrency = strtoupper(trim($currency));
    $isSar = ($normalizedCurrency === 'SAR' || strpos($currency, 'سعود') !== false || strpos($currency, 'ر.س') !== false);
    $isYer = ($normalizedCurrency === 'YER' || strpos($currency, 'يمني') !== false || strpos($currency, 'ر.ي') !== false || (!$isSar && strpos($currency, 'ريال') !== false));

    if ($isSar) {
        return number_format($amount, 2, '.', '') . ' ' . $currency;
    }

    if ($isYer) {
        return number_format($amount, 0, '.', ',') . ' ' . $currency;
    }

    return number_format($amount, 2, '.', ',') . ' ' . $currency;
}

function format_date($date, $format = null) {
    if ($format === null) {
        $format = get_setting('date_format', DEFAULT_DATE_FORMAT);
    }
    return date($format, is_string($date) ? strtotime($date) : $date);
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function has_permission($permission) {
    if (!is_logged_in()) {
        return false;
    }
    
    // Admin has all permissions
    if ($_SESSION['role'] === 'admin') {
        return true;
    }
    
    // Add more specific permission checks here
    return true;
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitize_input($input) {
    if (is_array($input)) {
        return array_map('sanitize_input', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function log_activity($action, $details = null) {
    global $db;
    
    if (!is_logged_in() || !isset($db)) {
        return;
    }
    
    try {
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, details, ip_address, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (PDOException $e) {
        // Log error to file if database logging fails
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

function generate_code($prefix, $table, $field, $length = 6) {
    global $db;
    
    do {
        $code = $prefix . str_pad(mt_rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM $table WHERE $field = ?");
        $stmt->execute([$code]);
        $exists = $stmt->fetchColumn() > 0;
    } while ($exists);
    
    return $code;
}
?>
