<?php
/**
 * Employee Permissions Management - FIXED & UNIFIED
 * Works with both user_permissions AND role_permissions
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$root = dirname(dirname(dirname(__FILE__)));
require_once $root . '/config/database.php';

// Include permission check functions
require_once $root . '/includes/check_permissions.php';

// Initial check for overall access to this page
// User must have 'manage_permissions' on the 'employees' module to access this page.
if (!hasPermission($_SESSION['user_id'], 'employees', 'manage_permissions')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لإدارة صلاحيات الموظفين.';
    header('Location: ../dashboard.php'); // Redirect to dashboard
    exit();
}

$page_title = 'إدارة صلاحيات الموظفين';
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Sidebar modules definition
$sidebar_modules = [
    ['name' => 'الصفحة الرئيسية', 'icon' => 'fas fa-home', 'key' => 'dashboard'],
    ['name' => 'إدارة العملاء', 'icon' => 'fas fa-users', 'key' => 'customers'],
    ['name' => 'فواتير العملاء', 'icon' => 'fas fa-file-invoice', 'key' => 'customer_invoices'],
    ['name' => 'أنواع العملاء', 'icon' => 'fas fa-users-cog', 'key' => 'customer_types'],
    ['name' => 'المدن', 'icon' => 'fas fa-city', 'key' => 'cities'],
    ['name' => 'طلبات العملاء', 'icon' => 'fas fa-shopping-bag', 'key' => 'orders'],
    ['name' => 'order approval', 'icon' => 'fas fa-clipboard-check', 'key' => 'order_approval'],
    ['name' => 'المنتجات', 'icon' => 'fas fa-boxes', 'key' => 'products'],
    ['name' => 'الفئات', 'icon' => 'fas fa-tags', 'key' => 'categories'],
    ['name' => 'السمات', 'icon' => 'fas fa-th-list', 'key' => 'attributes'],
    ['name' => 'طلبات المتجر', 'icon' => 'fas fa-shopping-basket', 'key' => 'shop_orders'],
    ['name' => 'سلايدر المنتجات', 'icon' => 'fas fa-images', 'key' => 'product_slides'],
    ['name' => 'المراجعة المالية', 'icon' => 'fas fa-file-invoice-dollar', 'key' => 'financial_review'],
    ['name' => 'نسخ الرسائل', 'icon' => 'fas fa-calculator', 'key' => 'calculations'], // Calculations module
    ['name' => 'مجموعات الشراء', 'icon' => 'fas fa-layer-group', 'key' => 'purchase_groups'],
    ['name' => 'سلات الشراء', 'icon' => 'fas fa-shopping-basket', 'key' => 'baskets'],
    ['name' => 'بطاقات الشراء', 'icon' => 'fas fa-credit-card', 'key' => 'purchase_cards'],
    ['name' => 'بطاقات الهدية', 'icon' => 'fas fa-gift', 'key' => 'loyalty_cards'],
    ['name' => 'إدارة الشحن', 'icon' => 'fas fa-shipping-fast', 'key' => 'shipping'],
    ['name' => 'رسائل الواتساب', 'icon' => 'fab fa-whatsapp', 'key' => 'whatsapp'],
    ['name' => 'قالب واتساب (إدارة الطلب)', 'icon' => 'fab fa-whatsapp', 'key' => 'whatsapp_templates_admin_notification'], // NEW: WhatsApp Admin Template module
    ['name' => 'الحسابات المالية', 'icon' => 'fas fa-coins', 'key' => 'financial'],
    ['name' => 'الحسابات البنكية', 'icon' => 'fas fa-university', 'key' => 'bank_accounts'],
    ['name' => 'إدارة الموظفين', 'icon' => 'fas fa-user-tie', 'key' => 'employees'],
    ['name' => 'إدارة المصروفات', 'icon' => 'fas fa-money-bill-wave', 'key' => 'expenses'],
    ['name' => 'إدارة الكوبونات', 'icon' => 'fas fa-ticket-alt', 'key' => 'coupons'],
    ['name' => 'كوبونات المتجر', 'icon' => 'fas fa-tags', 'key' => 'shop_coupons'], // Added shop_coupons
    ['name' => 'التقارير', 'icon' => 'fas fa-chart-bar', 'key' => 'reports'],
    ['name' => 'إدارة سلايدر البوابة', 'icon' => 'fas fa-images', 'key' => 'portal_slides'],
    ['name' => 'الإعدادات', 'icon' => 'fas fa-cog', 'key' => 'settings'],
    ['name' => 'إداره الحالات', 'icon' => 'fas fa-tasks', 'key' => 'status'],
    ['name' => 'إداره الدفعات', 'icon' => 'fas fa-tasks', 'key' => 'payments'],
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $user_id = intval($_POST['user_id'] ?? 0);
    
    if ($action === 'update_permissions' && $user_id > 0) {
        // Permission check for saving permissions
        if (!hasPermission($_SESSION['user_id'], 'employees', 'manage_permissions')) {
            $_SESSION['error_message'] = 'ليس لديك صلاحية لحفظ صلاحيات الموظفين.';
            header('Location: employee-permissions.php?user_id=' . $user_id);
            exit();
        }

        $selected_permissions = $_POST['permissions'] ?? [];
        
        try {
            $db->beginTransaction();
            
            // Step 1: Delete ALL existing permissions for this user
            $db->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$user_id]);
            
            // Step 2: Add new permissions
            $added = 0;
            // Updated to include 'approve', 'reject', and 'manage_permissions'
            $permission_labels = [
                'view'    => 'عرض',
                'edit'    => 'تعديل',
                'add'     => 'إضافة',
                'manage'  => 'إدارة',
                'approve' => 'موافقة',
                'reject'  => 'رفض',
                'manage_permissions' => 'إدارة صلاحيات' // Added for employees module
            ];
            
            foreach ($selected_permissions as $perm_key_full) { // Changed variable name to avoid conflict
                // Parse: customers_view -> module=customers, type=view
                $parts = explode('_', $perm_key_full);
                $perm_type = array_pop($parts);
                $module_key = implode('_', $parts);
                
                // Ensure permission exists in permissions table
                $check = $db->prepare("SELECT id FROM permissions WHERE permission_key = ?");
                $check->execute([$perm_key_full]); // Use the full key here
                $perm_id = $check->fetchColumn();
                
                if (!$perm_id) {
                    // Build human-readable permission name & description
                    // Map module keys to their Arabic names for descriptions
                    $module_name_map = array_column($sidebar_modules, 'name', 'key');
                    $perm_label = $permission_labels[$perm_type] ?? $perm_type;
                    $perm_name  = $perm_label . ' ' . ($module_name_map[$module_key] ?? $module_key); // Use translated module name if available
                    $desc       = 'صلاحية ' . $perm_name;

                    // Insert permission; use INSERT IGNORE to avoid duplicate-key errors
                    $ins = $db->prepare("INSERT IGNORE INTO permissions (permission_name, permission_key, permission_type, module, description) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$perm_name, $perm_key_full, $perm_type, $module_key, $desc]);

                    // Re-fetch id
                    $check->execute([$perm_key_full]);
                    $perm_id = $check->fetchColumn();
                }
                
                // Add user permission
                if ($perm_id) {
                    $db->prepare("INSERT INTO user_permissions (user_id, permission_id, granted_by) VALUES (?, ?, ?)")
                       ->execute([$user_id, $perm_id, $_SESSION['user_id']]);
                    $added++;
                }
            }
            
            $db->commit();
            $_SESSION['success_message'] = "تم حفظ الصلاحيات بنجاح ($added صلاحية)";
            
        } catch (PDOException $e) {
            $db->rollBack();
            $_SESSION['error_message'] = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
        }
        
        header("Location: employee-permissions.php?user_id=$user_id");
        exit();
    }
}

// Get selected user
$selected_user_id = intval($_GET['user_id'] ?? 0);

// Fetch all non-admin users
$users = $db->query("
    SELECT id, username, full_name, email, is_active 
    FROM users 
    WHERE (is_admin = 0 OR is_admin IS NULL)
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get user's current permissions - MUST be global for function access
$GLOBALS['user_permissions'] = [];
if ($selected_user_id > 0) {
    $stmt = $db->prepare("
        SELECT p.permission_key 
        FROM user_permissions up 
        JOIN permissions p ON up.permission_id = p.id 
        WHERE up.user_id = ?
    ");
    $stmt->execute([$selected_user_id]);
    while ($row = $stmt->fetch()) {
        $GLOBALS['user_permissions'][$row['permission_key']] = true;
    }
}

// Function to check if permission is granted
function hasUserPerm($module_key, $type) { // Changed signature
    $full_key = $module_key . '_' . $type;
    return isset($GLOBALS['user_permissions'][$full_key]);
}

// Get selected user info
$selected_user = null;
foreach ($users as $u) {
    if ($u['id'] == $selected_user_id) {
        $selected_user = $u;
        break;
    }
}

include $root . '/includes/header.php';
?>

<style>
.perm-card { transition: all 0.2s; }
.perm-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.perm-checkbox { width: 20px; height: 20px; cursor: pointer; }
.perm-label { cursor: pointer; user-select: none; }
.perm-view { background: #dbeafe; border-color: #3b82f6; }
.perm-edit { background: #fef3c7; border-color: #f59e0b; }
.perm-add { background: #d1fae5; border-color: #10b981; }
.perm-checked { border-width: 2px; }
.user-card { transition: all 0.2s; }
.user-card:hover { background: #f3f4f6; }
.user-card.active { background: #fef3c7; border-color: #f59e0b; }
</style>

<div class="min-h-screen bg-gray-100 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-500 to-amber-600 rounded-xl shadow-lg mb-6 px-4 sm:px-6 py-4 sm:py-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-user-shield ml-3"></i>
                        إدارة صلاحيات الموظفين
                    </h1>
                    <p class="text-amber-100 mt-1">تحديد صلاحيات العرض والتعديل والإضافة لكل موظف</p>
                </div>
                <a href="../../index.php" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 sm:py-2.5 bg-white text-amber-600 rounded-lg font-semibold hover:bg-amber-50 text-sm sm:text-base">
                    <i class="fas fa-arrow-right ml-2"></i>العودة
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success_message): ?>
        <div class="bg-green-100 border-r-4 border-green-500 text-green-700 p-4 rounded-lg mb-6 flex items-center">
            <i class="fas fa-check-circle text-xl ml-3"></i>
            <span class="font-semibold"><?= htmlspecialchars($success_message) ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 flex items-center">
            <i class="fas fa-exclamation-circle text-xl ml-3"></i>
            <span class="font-semibold"><?= htmlspecialchars($error_message) ?></span>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            
            <!-- Users List -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="bg-blue-600 px-4 py-3">
                        <h2 class="text-lg font-bold text-white flex items-center">
                            <i class="fas fa-users ml-2"></i>
                            قائمة الموظفين
                        </h2>
                    </div>
                    <div class="p-3 max-h-[600px] overflow-y-auto">
                        <?php foreach ($users as $user): 
                            $perm_count = $db->query("SELECT COUNT(*) FROM user_permissions WHERE user_id = {$user['id']}")->fetchColumn();
                            $is_active = $selected_user_id == $user['id'];
                        ?>
                        <a href="?user_id=<?= $user['id'] ?>" 
                           class="user-card block p-3 mb-2 rounded-lg border-2 <?= $is_active ? 'active border-amber-500' : 'border-gray-200' ?>">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-<?= $is_active ? 'amber' : 'blue' ?>-100 flex items-center justify-center ml-3">
                                        <i class="fas fa-user text-<?= $is_active ? 'amber' : 'blue' ?>-600"></i>
                                    </div>
                                    <div>
                                        <div class="font-bold text-gray-800"><?= htmlspecialchars($user['full_name']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($user['email'] ?? $user['username']) ?></div>
                                    </div>
                                </div>
                                <span class="px-2 py-1 text-xs font-bold rounded-full <?= $perm_count > 0 ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                                    <?= $perm_count ?>
                                </span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Permissions Form -->
            <div class="lg:col-span-3">
                <?php if ($selected_user): ?>
                <div class="bg-white rounded-xl shadow-lg">
                    <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-4 sm:px-6 py-4 rounded-t-xl">
                        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                            <div class="flex items-center">
                                <div class="w-12 h-12 rounded-full bg-amber-400 flex items-center justify-center ml-4">
                                    <i class="fas fa-user-cog text-white text-xl"></i>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold text-white"><?= htmlspecialchars($selected_user['full_name']) ?></h2>
                                    <p class="text-gray-300 text-sm"><?= htmlspecialchars($selected_user['email'] ?? '') ?></p>
                                </div>
                            </div>
                            <div class="text-left">
                                <span class="inline-block px-3 sm:px-4 py-1.5 sm:py-2 bg-amber-500 text-white rounded-lg font-bold text-sm sm:text-base">
                                    <?= count($GLOBALS['user_permissions']) ?> صلاحية
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" id="permForm" class="p-4 sm:p-6">
                        <input type="hidden" name="action" value="update_permissions">
                        <input type="hidden" name="user_id" value="<?= $selected_user_id ?>">
                        
                        <!-- Permission Legend -->
                        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between mb-6 pb-4 border-b">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="flex items-center px-3 py-1 rounded-lg perm-view">
                                    <i class="fas fa-eye ml-2 text-blue-600"></i>
                                    <span class="text-blue-800 font-semibold">عرض</span>
                                </span>
                                <span class="flex items-center px-3 py-1 rounded-lg perm-edit">
                                    <i class="fas fa-edit ml-2 text-amber-600"></i>
                                    <span class="text-amber-800 font-semibold">تعديل</span>
                                </span>
                                <span class="flex items-center px-3 py-1 rounded-lg perm-add">
                                    <i class="fas fa-plus-circle ml-2 text-green-600"></i>
                                    <span class="text-green-800 font-semibold">إضافة</span>
                                </span>
                                <span class="flex items-center px-3 py-1 rounded-lg bg-green-100 border-green-500">
                                    <i class="fas fa-check ml-2 text-green-600"></i>
                                    <span class="text-green-800 font-semibold">موافقة</span>
                                </span>
                                <span class="flex items-center px-3 py-1 rounded-lg bg-red-100 border-red-500">
                                    <i class="fas fa-times ml-2 text-red-600"></i>
                                    <span class="text-red-800 font-semibold">رفض</span>
                                </span>
                                <span class="flex items-center px-3 py-1 rounded-lg bg-purple-100 border-purple-500">
                                    <i class="fas fa-user-shield ml-2 text-purple-600"></i>
                                    <span class="text-purple-800 font-semibold">إدارة صلاحيات</span>
                                </span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" onclick="selectAll()" class="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-xs sm:text-sm font-semibold hover:bg-blue-200">
                                    <i class="fas fa-check-double ml-1"></i>تحديد الكل
                                </button>
                                <button type="button" onclick="clearAll()" class="px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-xs sm:text-sm font-semibold hover:bg-red-200">
                                    <i class="fas fa-times ml-1"></i>إزالة الكل
                                </button>
                            </div>
                        </div>
                        
                        <!-- Modules Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($sidebar_modules as $module): ?>
                            <div class="perm-card bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <div class="flex items-center mb-3 pb-2 border-b border-gray-200">
                                    <i class="<?= $module['icon'] ?> text-amber-600 ml-2 text-lg"></i>
                                    <span class="font-bold text-gray-800"><?= $module['name'] ?></span>
                                </div>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
    <?php 
    $perms = [
        'view'    =>['icon' => 'fa-eye', 'label' => 'عرض', 'class' => 'perm-view', 'color' => 'blue'],
        'add'     =>['icon' => 'fa-plus-circle', 'label' => 'إضافة', 'class' => 'perm-add', 'color' => 'green'],
        'edit'    =>['icon' => 'fa-edit', 'label' => 'تعديل', 'class' => 'perm-edit', 'color' => 'amber'],
        'approve' =>['icon' => 'fa-check', 'label' => 'موافقة', 'class' => 'bg-green-100 border-green-500', 'color' => 'green'],
        'reject'  =>['icon' => 'fa-times', 'label' => 'رفض', 'class' => 'bg-red-100 border-red-500', 'color' => 'red'],
        'manage_permissions' =>['icon' => 'fa-user-shield', 'label' => 'إدارة صلاحيات', 'class' => 'bg-purple-100 border-purple-500', 'color' => 'purple'], // For employees module
    ];

    $allowedTypes = ['view', 'add', 'edit']; // Default permissions for most modules

    if (in_array($module['key'], ['order_approval', 'shop_orders'])) {
        // Specific permissions for order_approval and shop_orders
        $allowedTypes =['view', 'approve', 'reject'];
    } else if ($module['key'] === 'orders') {
        // Orders (customer orders) typically have view, add, edit
        $allowedTypes = ['view', 'add', 'edit'];
    } else if ($module['key'] === 'employees') { // Special case for employees module
        $allowedTypes = ['view', 'edit', 'add', 'manage_permissions']; // Employees can have their own permissions managed
    } else if (in_array($module['key'],['dashboard', 'cities', 'whatsapp', 'financial', 'bank_accounts', 'expenses', 'coupons', 'reports', 'portal_slides', 'settings', 'status', 'payments'])) {
        // Modules that are more about management/viewing records (Removed 'shipping' from here so it gets the default 'add' permission)
        $allowedTypes =['view', 'edit']; 
    } else if ($module['key'] === 'calculations') {
        $allowedTypes = ['view', 'edit'];
    } else if ($module['key'] === 'whatsapp_templates_admin_notification') { // NEW: WhatsApp Admin Template permissions
        $allowedTypes = ['view', 'edit'];
    } else if ($module['key'] === 'shop_coupons') { // Added for shop_coupons
        $allowedTypes = ['view', 'add', 'edit'];
    }
    // For other modules not explicitly listed (including 'shipping' now)
    // The default ['view', 'add', 'edit'] will apply as intended.

    foreach ($perms as $type => $p):
        if (!in_array($type, $allowedTypes)) continue; // Skip permission types not allowed for this module
        
        $full_permission_key = $module['key'] . '_' . $type;
        $checked = hasUserPerm($module['key'], $type); // Corrected function call
    ?>
    <label class="perm-label flex items-center p-2 rounded-lg <?= $p['class'] ?> <?= $checked ? 'perm-checked border-2' : 'border' ?> hover:opacity-80">
        <input type="checkbox" 
               name="permissions[]" 
               value="<?= $full_permission_key ?>"
               <?= $checked ? 'checked' : '' ?>
               class="perm-checkbox ml-2 text-<?= $p['color'] ?>-600 rounded"
               onchange="updateStyle(this)">
        <i class="fas <?= $p['icon'] ?> text-<?= $p['color'] ?>-600 ml-1"></i>
        <span class="text-<?= $p['color'] ?>-800 text-sm font-medium"><?= $p['label'] ?></span>
    </label>
    <?php endforeach; ?>
</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Submit -->
                        <div class="flex flex-col sm:flex-row sm:justify-end sm:items-center gap-3 mt-6 pt-4 border-t">
                            <button type="submit" class="w-full sm:w-auto px-6 sm:px-8 py-2.5 sm:py-3 bg-gradient-to-r from-amber-500 to-amber-600 text-white rounded-xl font-bold shadow-lg hover:from-amber-600 hover:to-amber-700 transition-all text-sm sm:text-base">
                                <i class="fas fa-save ml-2"></i>
                                حفظ الصلاحيات
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Permission Verification Panel -->
                <div class="bg-white rounded-xl shadow-lg mt-6 p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-shield-alt text-green-600 ml-2"></i>
                        التحقق من الصلاحيات المحفوظة
                    </h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <?php 
                        $saved_perms = array_keys($GLOBALS['user_permissions']); // Corrected to use GLOBALS
                        if (empty($saved_perms)): ?>
                            <p class="col-span-4 text-center text-gray-500 py-4">
                                <i class="fas fa-info-circle ml-2"></i>
                                لا توجد صلاحيات محفوظة لهذا المستخدم
                            </p>
                        <?php else:
                            // Create maps for translation
                            $module_name_map = array_column($sidebar_modules, 'name', 'key');
                            $permission_type_map = [
                                'view'   => 'عرض',
                                'edit'   => 'تعديل',
                                'add'    => 'إضافة',
                                'manage' => 'إدارة',
                                'approve' => 'موافقة',
                                'reject'  => 'رفض',
                                'manage_permissions' => 'إدارة صلاحيات', // Added for employees module
                            ];
                            
                            foreach ($saved_perms as $perm): 
                                $parts = explode('_', $perm);
                                $type = array_pop($parts);
                                $module_key = implode('_', $parts);

                                // Look up the Arabic names, with a fallback to the original key
                                $translated_module = $module_name_map[$module_key] ?? ucfirst(str_replace('_', ' ', $module_key));
                                $translated_type = $permission_type_map[$type] ?? ucfirst($type);

                                // Combine them for display
                                $display_text = $translated_type . ' - ' . $translated_module;
                                
                                $colors = [
                                    'view' => 'blue',
                                    'edit' => 'amber',
                                    'add' => 'green',
                                    'manage' => 'red',
                                    'approve' => 'green',
                                    'reject' => 'red',
                                    'manage_permissions' => 'purple' // Added color for new type
                                ];
                                $c = $colors[$type] ?? 'gray';
                        ?>
                            <span class="px-3 py-2 bg-<?= $c ?>-100 text-<?= $c ?>-800 rounded-lg text-sm font-medium text-center">
                                <i class="fas fa-check-circle ml-1"></i>
                                <?= htmlspecialchars($display_text) // Use the translated text ?>
                            </span>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                    <i class="fas fa-hand-pointer text-6xl text-gray-300 mb-4"></i>
                    <h3 class="text-2xl font-bold text-gray-600 mb-2">اختر موظفاً</h3>
                    <p class="text-gray-500">اختر موظفاً من القائمة لإدارة صلاحياته</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function selectAll() {
    document.querySelectorAll('input[name="permissions[]"]').forEach(cb => {
        cb.checked = true;
        updateStyle(cb);
    });
}

function clearAll() {
    document.querySelectorAll('input[name="permissions[]"]').forEach(cb => {
        cb.checked = false;
        updateStyle(cb);
    });
}

function updateStyle(checkbox) {
    const label = checkbox.closest('label');
    if (checkbox.checked) {
        label.classList.add('perm-checked', 'border-2');
    } else {
        label.classList.remove('perm-checked', 'border-2');
    }
}
</script>

<?php include $root . '/includes/footer.php'; ?>