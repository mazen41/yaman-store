<?php
/**
 * Enhanced Login Handler with Smart Redirect
 * Redirects user to first permitted page after login
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/rbac_helpers.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    $errors = [];
    
    // Validation
    if (empty($email)) {
        $errors[] = 'البريد الإلكتروني مطلوب';
    }
    
    if (empty($password)) {
        $errors[] = 'كلمة المرور مطلوبة';
    }
    
    if (empty($errors)) {
        try {
            // Fetch user
            $stmt = $db->prepare("
                SELECT u.*, r.name as role_name, r.display_name as role_display_name
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE u.email = ?
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                // Check if user is active
                if (!$user['is_active']) {
                    $errors[] = 'حسابك غير نشط. يرجى الاتصال بالإدارة.';
                } else {
                    // Set session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['role_id'] = $user['role_id'];
                    $_SESSION['role_name'] = $user['role_display_name'] ?? 'موظف';
                    $_SESSION['is_admin'] = $user['is_admin'];
                    $_SESSION['login_time'] = time();
                    
                    // Remember me
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('remember_token', $token, time() + (86400 * 30), '/');
                        
                        // Store token in database
                        $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                        $stmt->execute([$token, $user['id']]);
                    }
                    
                    // Update last login
                    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    
                    // Log login
                    logActivity($user['id'], 'login', 'تسجيل دخول ناجح');
                    
                    // Smart redirect based on permissions
                    $redirectUrl = determineRedirectUrl($user['id'], $user['is_admin']);
                    
                    header("Location: $redirectUrl");
                    exit();
                }
            } else {
                $errors[] = 'البريد الإلكتروني أو كلمة المرور غير صحيحة';
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $errors[] = 'حدث خطأ أثناء تسجيل الدخول. يرجى المحاولة مرة أخرى.';
        }
    }
    
    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['login_errors'] = $errors;
        $_SESSION['login_email'] = $email;
        header("Location: /login.php");
        exit();
    }
}

/**
 * Determine where to redirect user after login
 */
function determineRedirectUrl($user_id, $is_admin) {
    // Super admin goes to dashboard
    if ($is_admin) {
        return '/index.php';
    }
    
    // Get first permitted page
    $firstPage = getFirstPermittedPage($user_id);
    
    if ($firstPage) {
        return $firstPage;
    }
    
    // No permissions - redirect to no-permissions page
    return '/no-permissions.php';
}

/**
 * Log user activity
 */
function logActivity($user_id, $action, $description) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $action,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (PDOException $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

/**
 * Check remember me token
 */
function checkRememberToken() {
    global $db;
    
    if (isset($_COOKIE['remember_token']) && !isset($_SESSION['user_id'])) {
        $token = $_COOKIE['remember_token'];
        
        try {
            $stmt = $db->prepare("
                SELECT id, name, email, role_id, is_admin
                FROM users
                WHERE remember_token = ? AND is_active = 1
            ");
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Auto-login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['is_admin'] = $user['is_admin'];
                $_SESSION['login_time'] = time();
                
                return true;
            }
        } catch (PDOException $e) {
            error_log("Remember token check error: " . $e->getMessage());
        }
    }
    
    return false;
}
