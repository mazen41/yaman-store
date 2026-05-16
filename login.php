<?php
/**
 * Secured Login Page with Complete Security Implementation
 * AES-256-CBC, CSRF, XSS, SQL Injection Protection, Rate Limiting, Session Management
 */

session_start();

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

require_once 'config/database.php';
require_once 'security_config.php';
require_once 'includes/PermissionManager.php'; // Included here for use in redirect logic

// Initialize security manager
$security = new SecurityManager($db);

// Generate CSRF token
$csrf_token = $security->generateCSRFToken();

$error_message = '';
$success_message = '';

// Check for timeout message
if (isset($_GET['timeout'])) {
    $error_message = 'تم تسجيل الخروج تلقائياً بسبب عدم النشاط (30 دقيقة)';
}

// Rate limiting helper functions
function checkRateLimit($db, $username, $ip_address) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as attempts 
        FROM failed_login_attempts 
        WHERE (username = ? OR ip_address = ?) 
        AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$username, $ip_address]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['attempts'] < 5;
}

function logFailedAttempt($db, $username, $ip_address, $user_agent) {
    $stmt = $db->prepare("
        INSERT INTO failed_login_attempts (username, ip_address, user_agent)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$username, $ip_address, $user_agent]);
}

function getClientIP() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // CSRF Protection
        $csrf_token_post = $_POST['csrf_token'] ?? '';
        if (!$security->validateCSRFToken($csrf_token_post)) {
            throw new Exception('رمز الأمان غير صحيح. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        }
        
        // Sanitize inputs (XSS Protection)
        $username = $security->sanitizeInput($_POST['username'] ?? '', 'string');
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);
        
        if (empty($username) || empty($password)) {
            throw new Exception('يرجى إدخال اسم المستخدم وكلمة المرور');
        }
        
        // Rate limiting check
        $ip_address = getClientIP();
        if (!checkRateLimit($db, $username, $ip_address)) {
            throw new Exception('تم تجاوز عدد محاولات تسجيل الدخول. يرجى المحاولة بعد 15 دقيقة.');
        }
        
        // SQL Injection Protection - Using prepared statements
        $stmt = $db->prepare("
            SELECT id, username, password, full_name, email, phone, role, is_admin, is_active 
            FROM users 
            WHERE (username = ? OR email = ?)
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($password, $user['password'])) {
            // Log failed attempt
            logFailedAttempt($db, $username, $ip_address, $_SERVER['HTTP_USER_AGENT'] ?? '');
            
            // Log security event
            $security->logSecurityEvent('login_failed', [
                'username' => $username,
                'ip' => $ip_address
            ]);
            
            throw new Exception('اسم المستخدم أو كلمة المرور غير صحيحة');
        }
        
        // Clear failed attempts on successful login
        $db->prepare("DELETE FROM failed_login_attempts WHERE username = ? OR ip_address = ?")
           ->execute([$username, $ip_address]);
        
        // Regenerate session ID (Session Hijacking Protection)
        session_regenerate_id(true);
        
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['email'] = $user['email'] ?? '';
        $_SESSION['phone'] = $user['phone'] ?? '';
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['ip_address'] = $ip_address;
        
        // Create authentication token (120-minute expiration)
        if ($remember_me) {
            $token = $security->createAuthToken($user['id']);
            if ($token) {
                setcookie('auth_token', $token, time() + (120 * 60), '/', '', true, true);
            }
        }
        
        // Log successful login
        $security->logSession($user['id'], 'login');
        
        // Update last login time
        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
           ->execute([$user['id']]);
        
        // --- START EDITED REDIRECT LOGIC ---
        
        // Permission-based redirect
        $permissionManager = new PermissionManager($db, $user['id']);
        
        $target_route = '';
        $orders_module_route = '/modules/orders/index.php';
        
        // 1. Check if the user has permission for the desired default page: /modules/orders/index.php
        if ($permissionManager->hasPermission('orders', 'view')) {
            // If yes, redirect to the preferred route
            $target_route = $orders_module_route;
        } else {
            // 2. If no permission for orders, redirect to the first allowed route (which handles admin/other modules)
            $target_route = $permissionManager->getFirstAllowedRoute();
        }
        
        header("Location: $target_route");
        exit();
        
        // --- END EDITED REDIRECT LOGIC ---
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

$page_title = 'تسجيل الدخول';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - نظام إدارة يمان</title>
    
    <!-- CSRF Token Meta Tag -->
    <meta name="csrf-token" content="<?php echo $csrf_token; ?>">
    
    <!-- Security Headers -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
    <!-- TailwindCSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts for Arabic -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            font-family: 'Cairo', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .security-badge {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    
    <div class="max-w-md w-full space-y-8">
        <!-- Header -->
        <div>
            <div class="mx-auto h-20 w-20 bg-white rounded-full flex items-center justify-center shadow-lg">
                <i class="fas fa-shield-alt text-3xl text-amber-600"></i>
            </div>
            <h2 class="mt-6 text-center text-3xl font-extrabold text-white">
                نظام إدارة يمان
            </h2>
            <p class="mt-2 text-center text-sm text-gray-200">
                تسجيل الدخول إلى حسابك
            </p>
            
            <!-- Security Badge -->
            <div class="mt-4 flex justify-center">
                <div class="security-badge inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                    <i class="fas fa-lock ml-1"></i>
                    محمي بتشفير AES-256-CBC
                </div>
            </div>
        </div>
        
        <!-- Login Form -->
        <div class="bg-white rounded-lg shadow-2xl p-8">
            
            <!-- Error Message -->
            <?php if ($error_message): ?>
            <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle ml-2"></i>
                    <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Success Message -->
            <?php if ($success_message): ?>
            <div class="mb-4 bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded relative" role="alert">
                <div class="flex items-center">
                    <i class="fas fa-check-circle ml-2"></i>
                    <span class="block sm:inline"><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="space-y-6">
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- Username -->
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-user ml-1 text-gray-500"></i>
                        اسم المستخدم أو البريد الإلكتروني
                    </label>
                    <input id="username" name="username" type="text" required 
                           class="appearance-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                           placeholder="أدخل اسم المستخدم"
                           autocomplete="username">
                </div>
                
                <!-- Password -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-lock ml-1 text-gray-500"></i>
                        كلمة المرور
                    </label>
                    <div class="relative">
                        <input id="password" name="password" type="password" required 
                               class="appearance-none relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                               placeholder="أدخل كلمة المرور"
                               autocomplete="current-password">
                        <button type="button" onclick="togglePassword()" 
                                class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700">
                            <i id="passwordIcon" class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Remember Me -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input id="remember_me" name="remember_me" type="checkbox" 
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="remember_me" class="mr-2 block text-sm text-gray-900">
                            تذكرني لمدة 120 دقيقة
                        </label>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <div>
                    <button type="submit" 
                            class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 shadow-lg hover:shadow-xl">
                        <span class="absolute right-0 inset-y-0 flex items-center pr-3">
                            <i class="fas fa-sign-in-alt text-white"></i>
                        </span>
                        تسجيل الدخول
                    </button>
                </div>
            </form>
            
            <!-- Security Features -->
            <div class="mt-6 pt-6 border-t border-gray-200">
                <p class="text-xs text-gray-600 text-center mb-3 font-semibold">ميزات الأمان المفعلة:</p>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div class="flex items-center text-amber-600">
                        <i class="fas fa-check-circle ml-1"></i>
                        <span>تشفير AES-256</span>
                    </div>
                    <div class="flex items-center text-amber-600">
                        <i class="fas fa-check-circle ml-1"></i>
                        <span>حماية CSRF</span>
                    </div>
                    <div class="flex items-center text-amber-600">
                        <i class="fas fa-check-circle ml-1"></i>
                        <span>حماية XSS</span>
                    </div>
                    <div class="flex items-center text-amber-600">
                        <i class="fas fa-check-circle ml-1"></i>
                        <span>حماية SQL Injection</span>
                    </div>
                    <div class="flex items-center text-amber-600">
                        <i class="fas fa-check-circle ml-1"></i>
                        <span>جلسات آمنة (120 دقيقة)</span>
                    </div>
                    <div class="flex items-center text-amber-600">
                        <i class="fas fa-check-circle ml-1"></i>
                        <span>خروج تلقائي (30 دقيقة)</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="text-center">
            <p class="text-sm text-gray-200">
                <i class="fas fa-shield-alt text-amber-300 ml-1"></i>
                محمي بأعلى معايير الأمان السيبراني
            </p>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const passwordIcon = document.getElementById('passwordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.classList.remove('fa-eye');
                passwordIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordIcon.classList.remove('fa-eye-slash');
                passwordIcon.classList.add('fa-eye');
            }
        }
        
        // Auto-focus username field
        document.getElementById('username').focus();
        
        // Prevent multiple form submissions
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin ml-2"></i> جاري تسجيل الدخول...';
        });
    </script>
</body>
</html>