<?php
/**
 * Employee Permissions - RBAC Role Assignment
 * Standalone version with error handling
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Database connection
try {
    require_once '../../config/database.php';
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Check if user is admin
function isAdmin() {
    global $db;
    try {
        $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user && $user['is_admin'] == 1;
    } catch (PDOException $e) {
        return false;
    }
}

if (!isAdmin()) {
    die("Access denied. Admin only.");
}

$page_title = 'إدارة صلاحيات الموظفين';
$success_message = '';
$error_message = '';

// Handle role assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_role') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $role_id = intval($_POST['role_id'] ?? 0);
    
    if ($user_id > 0) {
        try {
            if ($role_id > 0) {
                $stmt = $db->prepare("UPDATE users SET role_id = ? WHERE id = ?");
                $stmt->execute([$role_id, $user_id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET role_id = NULL WHERE id = ?");
                $stmt->execute([$user_id]);
            }
            
            // Clear cache if table exists
            try {
                $db->exec("DELETE FROM permission_cache WHERE user_id = $user_id");
            } catch (PDOException $e) {
                // Cache table might not exist yet
            }
            
            $success_message = "تم تعيين الدور بنجاح";
        } catch (PDOException $e) {
            $error_message = "خطأ: " . $e->getMessage();
        }
    }
}

// Get selected user
$selected_user_id = intval($_GET['user_id'] ?? 0);

// Fetch all users
try {
    $users_stmt = $db->prepare("
        SELECT u.id, u.full_name as name, u.email, u.role_id, u.is_active,
               r.name as role_name, r.display_name as role_display_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE u.is_admin = 0
        ORDER BY u.full_name ASC
    ");
    $users_stmt->execute();
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
    $error_message = "Error loading users: " . $e->getMessage();
}

// Fetch all roles
try {
    $roles_stmt = $db->query("SELECT * FROM roles ORDER BY id ASC");
    $roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $roles = [];
    $error_message .= " Error loading roles: " . $e->getMessage();
}

// Get selected user details
$selected_user = null;
$user_permissions = [];
if ($selected_user_id > 0) {
    foreach ($users as $user) {
        if ($user['id'] == $selected_user_id) {
            $selected_user = $user;
            break;
        }
    }
    
    // Get permissions if user has role
    if ($selected_user && $selected_user['role_id']) {
        try {
            $perms_stmt = $db->prepare("
                SELECT p.page_key, p.page_name, p.module_name,
                       rp.can_view, rp.can_add, rp.can_edit
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = ?
                  AND (rp.can_view = 1 OR rp.can_add = 1 OR rp.can_edit = 1)
                ORDER BY p.sort_order
            ");
            $perms_stmt->execute([$selected_user['role_id']]);
            $user_permissions = $perms_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // role_permissions table might not exist yet
            $user_permissions = [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">

<div class="min-h-screen py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white flex items-center">
                            <i class="fas fa-user-shield ml-3 text-blue-200"></i>
                            إدارة صلاحيات الموظفين (RBAC)
                        </h1>
                        <p class="text-blue-100 mt-2">تعيين الأدوار وإدارة صلاحيات الوصول</p>
                    </div>
                    <a href="../../index.php" class="inline-flex items-center px-6 py-3 bg-white text-blue-600 rounded-xl hover:bg-blue-50 transition-all duration-200 shadow-lg font-semibold">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة
                    </a>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded-lg mb-6 flex items-center shadow-md">
            <i class="fas fa-check-circle text-2xl ml-3"></i>
            <span class="font-semibold"><?php echo htmlspecialchars($success_message); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6 flex items-center shadow-md">
            <i class="fas fa-exclamation-circle text-2xl ml-3"></i>
            <span class="font-semibold"><?php echo htmlspecialchars($error_message); ?></span>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            
            <!-- Users List -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-2xl shadow-xl p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center border-b pb-3">
                        <i class="fas fa-users ml-2 text-blue-600"></i>
                        قائمة الموظفين
                    </h2>
                    
                    <div class="space-y-2 max-h-[600px] overflow-y-auto">
                        <?php foreach ($users as $user): ?>
                            <a href="?user_id=<?php echo $user['id']; ?>" 
                               class="block p-4 rounded-lg transition-all duration-200 <?php echo $selected_user_id == $user['id'] ? 'bg-blue-100 border-2 border-blue-500 shadow-md' : 'bg-gray-50 hover:bg-gray-100 border-2 border-transparent'; ?>">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold shadow-md">
                                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                    </div>
                                    <div class="mr-3 flex-1">
                                        <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($user['name']); ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo $user['role_display_name'] ? htmlspecialchars($user['role_display_name']) : 'لا يوجد دور'; ?>
                                        </p>
                                    </div>
                                    <?php if ($user['is_active']): ?>
                                        <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                                    <?php else: ?>
                                        <span class="w-3 h-3 bg-red-500 rounded-full"></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        
                        <?php if (empty($users)): ?>
                            <div class="text-center text-gray-500 py-8">
                                <i class="fas fa-users text-4xl mb-3 text-gray-300"></i>
                                <p>لا يوجد موظفين</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- User Details -->
            <div class="lg:col-span-3">
                <?php if ($selected_user): ?>
                    
                    <div class="bg-white rounded-2xl shadow-xl p-8 mb-6">
                        <div class="flex items-center justify-between mb-6 pb-4 border-b-2">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                                    <i class="fas fa-user-check text-blue-600 ml-2"></i>
                                    <?php echo htmlspecialchars($selected_user['name']); ?>
                                </h2>
                                <p class="text-gray-600 mt-1">
                                    <i class="fas fa-envelope text-gray-400 ml-2"></i>
                                    <?php echo htmlspecialchars($selected_user['email']); ?>
                                </p>
                            </div>
                            <span class="px-4 py-2 bg-blue-100 text-blue-800 rounded-lg font-bold">
                                <i class="fas fa-shield-alt ml-1"></i>
                                <?php echo count($user_permissions); ?> صلاحية
                            </span>
                        </div>

                        <!-- Role Assignment Form -->
                        <form method="POST" class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 rounded-xl border-2 border-blue-200">
                            <input type="hidden" name="action" value="assign_role">
                            <input type="hidden" name="user_id" value="<?php echo $selected_user_id; ?>">
                            
                            <div class="flex items-center gap-4">
                                <label class="text-gray-700 font-bold flex items-center">
                                    <i class="fas fa-user-tag ml-2 text-blue-600"></i>
                                    تعيين الدور:
                                </label>
                                
                                <select name="role_id" class="flex-1 px-4 py-3 border-2 border-blue-300 rounded-lg focus:ring-2 focus:ring-blue-500 font-semibold">
                                    <option value="">-- اختر دور --</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" 
                                                <?php echo ($selected_user['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($role['display_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <button type="submit" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 font-semibold shadow-lg transition-all flex items-center whitespace-nowrap">
                                    <i class="fas fa-save ml-2"></i>
                                    حفظ الدور
                                </button>
                            </div>
                            
                            <div class="mt-4 text-sm text-gray-600 bg-white p-3 rounded-lg">
                                <strong>الدور الحالي:</strong> 
                                <?php if ($selected_user['role_display_name']): ?>
                                    <span class="text-blue-700 font-bold"><?php echo htmlspecialchars($selected_user['role_display_name']); ?></span>
                                <?php else: ?>
                                    <span class="text-red-600 font-bold">لم يتم تعيين دور</span>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Permissions Display -->
                    <?php if (!empty($user_permissions)): ?>
                        <div class="bg-white rounded-2xl shadow-xl p-8">
                            <h3 class="text-xl font-bold text-gray-900 mb-4 flex items-center border-b pb-3">
                                <i class="fas fa-list-check ml-2 text-blue-600"></i>
                                الصلاحيات الحالية
                            </h3>
                            
                            <div class="space-y-3">
                                <?php 
                                $grouped = [];
                                foreach ($user_permissions as $perm) {
                                    $grouped[$perm['module_name']][] = $perm;
                                }
                                foreach ($grouped as $module_name => $perms): ?>
                                    <div class="bg-gray-50 border-2 border-gray-200 rounded-xl p-5">
                                        <h4 class="font-bold text-gray-900 mb-3">
                                            <i class="fas fa-folder ml-2 text-blue-600"></i>
                                            <?php echo htmlspecialchars($module_name); ?>
                                        </h4>
                                        <div class="space-y-2">
                                            <?php foreach ($perms as $perm): ?>
                                                <div class="flex items-center justify-between bg-white p-3 rounded-lg border">
                                                    <span class="text-gray-700 font-medium">
                                                        <?php echo htmlspecialchars($perm['page_name']); ?>
                                                    </span>
                                                    <div class="flex gap-2">
                                                        <?php if ($perm['can_view']): ?>
                                                            <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-semibold">
                                                                <i class="fas fa-eye"></i> عرض
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($perm['can_add']): ?>
                                                            <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                                                                <i class="fas fa-plus"></i> إضافة
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($perm['can_edit']): ?>
                                                            <span class="px-3 py-1 bg-amber-100 text-amber-700 rounded-full text-xs font-semibold">
                                                                <i class="fas fa-edit"></i> تعديل
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-xl p-12 text-center">
                        <i class="fas fa-user-check text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-2xl font-bold text-gray-700 mb-2">اختر موظفاً</h3>
                        <p class="text-gray-500">اختر موظفاً من القائمة لإدارة صلاحياته</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>
