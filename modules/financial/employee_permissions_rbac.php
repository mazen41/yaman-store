<?php
/**
 * Employee Permissions Management - RBAC Integrated
 * Combines role assignment with granular permission checkboxes
 */

session_start();
require_once '../../config/database.php';

// Simple admin check
function isAdminUser() {
    global $db;
    $user_id = $_SESSION['user_id'] ?? 0;
    if (!$user_id) return false;
    
    try {
        $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user && $user['is_admin'] == 1;
    } catch (PDOException $e) {
        return false;
    }
}

if (!isAdminUser()) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

$page_title = 'إدارة صلاحيات الموظفين (RBAC)';
$success_message = '';
$error_message = '';

// Sidebar modules matching your existing system
$sidebar_modules = [
    ['name' => 'إدارة العملاء', 'route' => '/modules/customers/index.php', 'icon' => 'fas fa-users', 'key' => 'customers', 'module' => 'customers', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'فواتير العملاء', 'route' => '/modules/customers/show_invoices.php', 'icon' => 'fas fa-file-invoice-dollar', 'key' => 'customer_invoices', 'module' => 'customers', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'أنواع العملاء', 'route' => '/modules/customers/customer_types.php', 'icon' => 'fas fa-users-cog', 'key' => 'customer_types', 'module' => 'customers', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'المدن', 'route' => '/modules/customers/cities.php', 'icon' => 'fas fa-city', 'key' => 'cities', 'module' => 'customers', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'طلبات العملاء', 'route' => '/modules/orders/index.php', 'icon' => 'fas fa-shopping-bag', 'key' => 'orders', 'module' => 'orders', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'المراجعة المالية', 'route' => '/modules/orders/financial_review.php', 'icon' => 'fas fa-file-invoice-dollar', 'key' => 'financial_review', 'module' => 'financial', 'permissions' => ['view', 'edit']],
    ['name' => 'إدارة المشتريات', 'route' => '/modules/purchases/index.php', 'icon' => 'fas fa-shopping-cart', 'key' => 'purchases', 'module' => 'purchases', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'مجموعات الشراء', 'route' => '/modules/purchases/groups/index.php', 'icon' => 'fas fa-layer-group', 'key' => 'purchase_groups', 'module' => 'purchases', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'إدارة الموردين', 'route' => '/modules/purchases/suppliers.php', 'icon' => 'fas fa-truck', 'key' => 'suppliers', 'module' => 'purchases', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'سلات الشراء', 'route' => '/modules/purchases/show_baskets.php', 'icon' => 'fas fa-shopping-basket', 'key' => 'baskets', 'module' => 'purchases', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'إدارة بطاقات الشراء', 'route' => '/modules/purchase_cards/index.php', 'icon' => 'fas fa-credit-card', 'key' => 'purchase_cards', 'module' => 'purchases', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'بطاقات الهدية', 'route' => '/modules/loyalty-cards/index.php', 'icon' => 'fas fa-gift', 'key' => 'loyalty_cards', 'module' => 'loyalty', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'إدارة الشحن', 'route' => '/modules/shipping/index.php', 'icon' => 'fas fa-shipping-fast', 'key' => 'shipping', 'module' => 'shipping', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'رسائل الواتساب', 'route' => '/modules/whatsapp/send.php', 'icon' => 'fab fa-whatsapp', 'key' => 'whatsapp', 'module' => 'whatsapp', 'permissions' => ['view', 'add']],
    ['name' => 'إدارة المخزون', 'route' => '/modules/inventory/index.php', 'icon' => 'fas fa-boxes', 'key' => 'inventory', 'module' => 'inventory', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'الحسابات المالية', 'route' => '/modules/financial/index.php', 'icon' => 'fas fa-coins', 'key' => 'financial', 'module' => 'financial', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'إدارة الحسابات البنكية', 'route' => '/modules/payments/bank_accounts.php', 'icon' => 'fas fa-university', 'key' => 'bank_accounts', 'module' => 'financial', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'إدارة الموظفين', 'route' => '/modules/financial/employee-manage.php', 'icon' => 'fas fa-users-cog', 'key' => 'employees', 'module' => 'settings', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'صلاحيات الموظفين', 'route' => '/modules/financial/employee_permissions.php', 'icon' => 'fas fa-user-shield', 'key' => 'permissions', 'module' => 'settings', 'permissions' => ['view', 'edit']],
    ['name' => 'إدارة المصروفات', 'route' => '/modules/expenses/index.php', 'icon' => 'fas fa-money-bill-wave', 'key' => 'expenses', 'module' => 'expenses', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'إدارة الكوبونات', 'route' => '/modules/coupons/index.php', 'icon' => 'fas fa-ticket-alt', 'key' => 'coupons', 'module' => 'coupons', 'permissions' => ['view', 'edit', 'add']],
    ['name' => 'التقارير والطباعة', 'route' => '/modules/reports/index.php', 'icon' => 'fas fa-chart-bar', 'key' => 'reports', 'module' => 'reports', 'permissions' => ['view']],
    ['name' => 'إعدادات النظام', 'route' => '/modules/settings/index.php', 'icon' => 'fas fa-cog', 'key' => 'settings', 'module' => 'settings', 'permissions' => ['view', 'edit']],
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_permissions') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $role_id = intval($_POST['role_id'] ?? 0);
        $selected_permissions = $_POST['permissions'] ?? [];
        
        // Debug: Log what we received
        error_log("RBAC Debug - User ID: $user_id, Role ID: $role_id");
        error_log("RBAC Debug - Test field: " . ($_POST['test_field'] ?? 'NOT RECEIVED'));
        error_log("RBAC Debug - All POST data: " . print_r($_POST, true));
        error_log("RBAC Debug - Permissions received: " . print_r($selected_permissions, true));
        error_log("RBAC Debug - Permissions count: " . count($selected_permissions));
        
        try {
            $db->beginTransaction();
            
            // 1. Update user's role (or set to NULL if not selected)
            if ($role_id > 0) {
                $stmt = $db->prepare("UPDATE users SET role_id = ? WHERE id = ?");
                $stmt->execute([$role_id, $user_id]);
            } else {
                // If no role selected, set to NULL
                $stmt = $db->prepare("UPDATE users SET role_id = NULL WHERE id = ?");
                $stmt->execute([$user_id]);
            }
            
            // 2. If no role selected, we can't save permissions (need a role)
            if ($role_id == 0) {
                $db->commit();
                $_SESSION['error_message'] = "يجب اختيار دور أولاً من القائمة المنسدلة";
                header("Location: employee_permissions_rbac.php?user_id=$user_id");
                exit();
            }
            
            // 3. Sync permissions in permissions table (ensure they exist)
            $saved_count = 0;
            foreach ($sidebar_modules as $module) {
                $page_key = $module['key'];
                $module_key = $module['module'];
                
                // Check if permission exists (using permission_key, not page_key)
                $stmt = $db->prepare("SELECT id FROM permissions WHERE permission_key = ?");
                $stmt->execute([$page_key]);
                $perm = $stmt->fetch();
                
                if (!$perm) {
                    // Create permission with correct column names (use INSERT IGNORE to avoid duplicates)
                    try {
                        $stmt = $db->prepare("
                            INSERT IGNORE INTO permissions (permission_name, permission_key, module_name, module, permission_type)
                            VALUES (?, ?, ?, ?, 'view')
                        ");
                        $stmt->execute([
                            $module['name'],
                            $page_key,
                            $module['name'],
                            $module_key
                        ]);
                        $permission_id = $db->lastInsertId();
                        
                        // If INSERT IGNORE didn't insert (duplicate), fetch the existing ID
                        if (!$permission_id) {
                            $stmt = $db->prepare("SELECT id FROM permissions WHERE permission_key = ?");
                            $stmt->execute([$page_key]);
                            $perm = $stmt->fetch();
                            $permission_id = $perm['id'];
                        }
                    } catch (PDOException $e) {
                        // If still fails, try to get existing permission
                        $stmt = $db->prepare("SELECT id FROM permissions WHERE permission_key = ?");
                        $stmt->execute([$page_key]);
                        $perm = $stmt->fetch();
                        $permission_id = $perm ? $perm['id'] : null;
                    }
                } else {
                    $permission_id = $perm['id'];
                }
                
                // 3. Update role_permissions for this role
                if ($role_id > 0 && $permission_id) {
                    // Delete existing
                    $stmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?");
                    $stmt->execute([$role_id, $permission_id]);
                    
                    // Check what permissions are selected for this page
                    $can_view = 0;
                    $can_add = 0;
                    $can_edit = 0;
                    
                    foreach ($selected_permissions as $perm_key) {
                        if ($perm_key === $page_key . '_view') $can_view = 1;
                        if ($perm_key === $page_key . '_add') $can_add = 1;
                        if ($perm_key === $page_key . '_edit') $can_edit = 1;
                    }
                    
                    error_log("RBAC Debug - Module: $page_key, View: $can_view, Add: $can_add, Edit: $can_edit");
                    
                    // Insert if any permission is granted
                    if ($can_view || $can_add || $can_edit) {
                        $stmt = $db->prepare("
                            INSERT INTO role_permissions (role_id, permission_id, can_view, can_add, can_edit)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$role_id, $permission_id, $can_view, $can_add, $can_edit]);
                        $saved_count++;
                        error_log("RBAC Debug - Saved permission: $page_key (view:$can_view, add:$can_add, edit:$can_edit)");
                    }
                }
            }
            
            error_log("RBAC Debug - Total permissions saved: $saved_count");
            
            // 4. Clear permission cache
            try {
                $stmt = $db->prepare("DELETE FROM permission_cache WHERE user_id = ?");
                $stmt->execute([$user_id]);
            } catch (PDOException $e) {
                // Cache table might not exist
            }
            
            $db->commit();
            
            $_SESSION['success_message'] = "تم تحديث الصلاحيات بنجاح ($saved_count صلاحية محفوظة)";
            header("Location: employee_permissions_rbac.php?user_id=$user_id");
            exit();
            
        } catch (PDOException $e) {
            $db->rollBack();
            $error_message = 'حدث خطأ: ' . $e->getMessage();
        }
    }
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
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
}

// Get selected user's permissions
$selected_user = null;
$user_permission_keys = [];
if ($selected_user_id > 0) {
    foreach ($users as $user) {
        if ($user['id'] == $selected_user_id) {
            $selected_user = $user;
            break;
        }
    }
    
    // Get permissions from role_permissions
    if ($selected_user && $selected_user['role_id']) {
        try {
            $stmt = $db->prepare("
                SELECT p.permission_key, rp.can_view, rp.can_add, rp.can_edit
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = ?
            ");
            $stmt->execute([$selected_user['role_id']]);
            $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($perms as $perm) {
                if ($perm['can_view']) $user_permission_keys[] = $perm['permission_key'] . '_view';
                if ($perm['can_add']) $user_permission_keys[] = $perm['permission_key'] . '_add';
                if ($perm['can_edit']) $user_permission_keys[] = $perm['permission_key'] . '_edit';
            }
        } catch (PDOException $e) {
            // Tables might not exist yet
        }
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-600 to-amber-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white flex items-center">
                            <i class="fas fa-user-shield ml-3 text-amber-200"></i>
                            تعيين الأدوار وإدارة صلاحيات الوصول
                        </h1>
                        <p class="text-amber-100 mt-2">منح وإدارة صلاحيات الوصول للصفحات</p>
                    </div>
                    <a href="../../index.php" class="inline-flex items-center px-6 py-3 bg-white text-amber-600 rounded-xl hover:bg-amber-50 transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-1 font-semibold">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة
                    </a>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success_message): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-6 py-4 rounded-lg mb-6 flex items-center shadow-md">
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
            
            <!-- Employees List -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-2xl shadow-xl p-6 sticky top-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center border-b pb-3">
                        <i class="fas fa-users ml-2 text-amber-600"></i>
                        قائمة الموظفين
                    </h2>
                    
                    <div class="space-y-2 max-h-[600px] overflow-y-auto">
                        <?php foreach ($users as $user): ?>
                            <a href="?user_id=<?php echo $user['id']; ?>" 
                               class="block p-4 rounded-lg transition-all duration-200 <?php echo $selected_user_id == $user['id'] ? 'bg-amber-100 border-2 border-amber-500 shadow-md' : 'bg-gray-50 hover:bg-gray-100 border-2 border-transparent hover:border-gray-300'; ?>">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-gradient-to-br from-amber-500 to-amber-600 rounded-full flex items-center justify-center text-white font-bold shadow-md">
                                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                    </div>
                                    <div class="mr-3 flex-1">
                                        <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($user['name']); ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo $user['role_display_name'] ? htmlspecialchars($user['role_display_name']) : 'لا يوجد دور'; ?>
                                        </p>
                                    </div>
                                    <?php if ($user['is_active']): ?>
                                        <span class="w-3 h-3 bg-amber-500 rounded-full animate-pulse"></span>
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

            <!-- Permissions Form -->
            <div class="lg:col-span-3">
                <?php if ($selected_user): ?>
                    
                    <div class="bg-white rounded-2xl shadow-xl p-8">
                        <div class="flex items-center justify-between mb-6 pb-4 border-b-2">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                                    <i class="fas fa-user-check text-amber-600 ml-2"></i>
                                    صلاحيات: <?php echo htmlspecialchars($selected_user['name']); ?>
                                </h2>
                                <p class="text-gray-600 mt-1 flex items-center">
                                    <i class="fas fa-envelope text-gray-400 ml-2"></i>
                                    <?php echo htmlspecialchars($selected_user['email']); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="px-4 py-2 bg-amber-100 text-amber-800 rounded-lg font-bold text-lg shadow-md block mb-2">
                                    <i class="fas fa-shield-alt ml-1"></i>
                                    <?php echo count($user_permission_keys); ?> صلاحية
                                </span>
                                <span class="px-4 py-2 bg-blue-100 text-blue-800 rounded-lg font-semibold text-sm block">
                                    الدور الحالي: <?php echo $selected_user['role_display_name'] ?: 'لا يوجد'; ?> (ID: <?php echo $selected_user['role_id'] ?: 'N/A'; ?>)
                                </span>
                            </div>
                        </div>

                        <form method="POST" class="space-y-4" id="permissionsForm" onsubmit="
                            event.preventDefault();
                            
                            console.log('=== FORM SUBMISSION DEBUG ===');
                            console.log('1. Form element:', this);
                            
                            // Check all checkboxes in the form
                            const allCheckboxes = this.querySelectorAll('input[type=checkbox]');
                            console.log('2. Total checkboxes in form:', allCheckboxes.length);
                            
                            const checkedBoxes = this.querySelectorAll('input[type=checkbox]:checked');
                            console.log('3. Checked checkboxes:', checkedBoxes.length);
                            
                            // Log each checkbox
                            checkedBoxes.forEach((cb, index) => {
                                console.log(`   Checkbox ${index + 1}:`, {
                                    name: cb.name,
                                    value: cb.value,
                                    checked: cb.checked,
                                    disabled: cb.disabled
                                });
                            });
                            
                            // Check FormData
                            const formData = new FormData(this);
                            console.log('4. FormData entries:');
                            let permCount = 0;
                            for (let [key, value] of formData.entries()) {
                                console.log(`   ${key}: ${value}`);
                                if (key === 'permissions[]') permCount++;
                            }
                            console.log('5. Permissions[] count in FormData:', permCount);
                            
                            // Check if permissions[] exists
                            const perms = formData.getAll('permissions[]');
                            console.log('6. All permissions[] values:', perms);
                            
                            console.log('=== END DEBUG ===');
                            console.log('⏳ Submitting in 3 seconds... Check the console output above!');
                            
                            // Submit after 3 seconds so you can see the console
                            setTimeout(() => {
                                console.log('✅ Now submitting form...');
                                this.submit();
                            }, 3000);
                            
                            return false;
                        ">
                            <input type="hidden" name="action" value="update_permissions">
                            <input type="hidden" name="user_id" value="<?php echo $selected_user_id; ?>">
                            <input type="hidden" name="test_field" value="form_is_working">

                            <!-- Role Selection -->
                            <div class="bg-gradient-to-r from-red-50 to-orange-50 p-6 rounded-xl border-4 border-red-400 mb-6 shadow-lg">
                                <label class="text-red-900 font-bold text-xl mb-3 block flex items-center">
                                    <i class="fas fa-user-tag ml-2 text-red-600 text-2xl"></i>
                                    ⚠️ تعيين الدور (إلزامي):
                                </label>
                                <p class="text-red-700 text-sm mb-3 font-semibold">يجب اختيار دور أولاً قبل تحديد الصلاحيات</p>
                                <select name="role_id" required class="w-full px-4 py-3 border-4 border-red-400 rounded-lg focus:ring-4 focus:ring-red-500 focus:border-red-500 font-bold text-xl bg-white">
                                    <option value="">-- اختر دور --</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" 
                                                <?php echo ($selected_user['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($role['display_name']); ?>
                                            <?php if ($role['description']): ?>
                                                - <?php echo htmlspecialchars($role['description']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- TEST CHECKBOX -->
                            <div class="bg-blue-100 border-2 border-blue-500 p-4 rounded-lg mb-6">
                                <label class="flex items-center">
                                    <input type="checkbox" name="permissions[]" value="test_checkbox" class="w-5 h-5 mr-2">
                                    <span class="font-bold text-blue-900">✅ اختبار - ضع علامة هنا للتأكد من عمل الصناديق</span>
                                </label>
                            </div>

                            <div class="bg-amber-50 border-r-4 border-amber-500 p-4 rounded-lg mb-6">
                                <p class="text-amber-800 font-semibold flex items-center">
                                    <i class="fas fa-info-circle ml-2"></i>
                                    مهم جداً: اختر الصلاحيات بدقة (عرض - تعديل - إضافة) لكل صفحة
                                </p>
                            </div>

                            <!-- Modules Grid with Granular Permissions -->
                            <div class="space-y-4">
                                <?php 
                                // Debug: Show module count
                                echo "<!-- Total modules: " . count($sidebar_modules) . " -->";
                                foreach ($sidebar_modules as $module): 
                                ?>
                                    <div class="bg-white border-2 border-gray-200 rounded-xl p-5 hover:border-amber-300 transition-all shadow-sm hover:shadow-md">
                                        <div class="flex items-center mb-3 pb-3 border-b">
                                            <i class="<?php echo htmlspecialchars($module['icon']); ?> text-amber-600 ml-3 text-xl"></i>
                                            <span class="text-gray-900 font-bold text-lg"><?php echo htmlspecialchars($module['name']); ?></span>
                                        </div>
                                        
                                        <div class="grid grid-cols-3 gap-3">
                                            <?php foreach ($module['permissions'] as $perm_type): ?>
                                                <?php 
                                                $permission_key = $module['key'] . '_' . $perm_type;
                                                $is_checked = in_array($permission_key, $user_permission_keys);
                                                
                                                // Define styles for each permission type
                                                if ($perm_type === 'view') {
                                                    $bg_class = 'bg-blue-50';
                                                    $hover_class = 'hover:bg-blue-100';
                                                    $border_class = $is_checked ? 'border-blue-500 shadow-md' : 'border-transparent';
                                                    $text_class = 'text-blue-600';
                                                    $label_class = 'text-blue-900';
                                                    $checkbox_class = 'text-blue-600 focus:ring-blue-500';
                                                    $icon = 'fa-eye';
                                                    $label = 'عرض';
                                                } elseif ($perm_type === 'edit') {
                                                    $bg_class = 'bg-amber-50';
                                                    $hover_class = 'hover:bg-amber-100';
                                                    $border_class = $is_checked ? 'border-amber-500 shadow-md' : 'border-transparent';
                                                    $text_class = 'text-amber-600';
                                                    $label_class = 'text-amber-900';
                                                    $checkbox_class = 'text-amber-600 focus:ring-amber-500';
                                                    $icon = 'fa-edit';
                                                    $label = 'تعديل';
                                                } else { // add
                                                    $bg_class = 'bg-green-50';
                                                    $hover_class = 'hover:bg-green-100';
                                                    $border_class = $is_checked ? 'border-green-500 shadow-md' : 'border-transparent';
                                                    $text_class = 'text-green-600';
                                                    $label_class = 'text-green-900';
                                                    $checkbox_class = 'text-green-600 focus:ring-green-500';
                                                    $icon = 'fa-plus-circle';
                                                    $label = 'إضافة';
                                                }
                                                ?>
                                                <label class="flex items-center p-3 <?php echo $bg_class; ?> rounded-lg cursor-pointer <?php echo $hover_class; ?> transition-all border-2 <?php echo $border_class; ?>">
                                                    <input type="checkbox" 
                                                           name="permissions[]" 
                                                           value="<?php echo htmlspecialchars($permission_key); ?>"
                                                           <?php echo $is_checked ? 'checked' : ''; ?>
                                                           class="w-4 h-4 <?php echo $checkbox_class; ?> rounded ml-2">
                                                    <i class="fas <?php echo $icon; ?> <?php echo $text_class; ?> ml-2"></i>
                                                    <span class="<?php echo $label_class; ?> font-semibold text-sm"><?php echo $label; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Submit Button -->
                            <div class="flex justify-end gap-3 pt-6 border-t-2 sticky bottom-0 bg-white mt-6">
                                <a href="?" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold transition-all flex items-center">
                                    <i class="fas fa-times ml-2"></i>
                                    إلغاء
                                </a>
                                <button type="submit" class="px-6 py-3 bg-gradient-to-r from-amber-600 to-amber-700 text-white rounded-lg hover:from-amber-700 hover:to-amber-800 font-semibold shadow-lg hover:shadow-xl transition-all transform hover:-translate-y-1 flex items-center">
                                    <i class="fas fa-save ml-2"></i>
                                    حفظ الصلاحيات
                                </button>
                            </div>
                        </form>
                    </div>
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

<?php include '../../includes/footer.php'; ?>
