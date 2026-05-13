<?php
/**
 * Dynamic Sidebar with Permissions
 * Path: /includes/sidebar.php (replace existing)
 */

// Initialize permission manager
require_once __DIR__ . '/PermissionManager.php';
$permissionManager = new PermissionManager($db, $_SESSION['user_id']);

// Get allowed modules for this user
$allowed_modules = $permissionManager->getAllowedModules();

// Get current page to highlight active menu
$current_page = basename($_SERVER['PHP_SELF']);
?>

<aside class="sidebar bg-gradient-to-b from-gray-900 to-gray-800 text-white w-64 min-h-screen p-4" dir="rtl">
    <!-- User Info -->
    <div class="user-info mb-6 p-4 bg-gray-700 rounded-lg">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-amber-500 rounded-full flex items-center justify-center text-xl font-bold">
                <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)); ?>
            </div>
            <div class="mr-3">
                <p class="font-semibold"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'مستخدم'); ?></p>
                <p class="text-xs text-gray-400">
                    <?php echo $permissionManager->isAdmin() ? 'مدير النظام' : 'موظف'; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="space-y-2">
        <?php foreach ($allowed_modules as $module): ?>
            <?php
            $is_active = strpos($_SERVER['REQUEST_URI'], $module['module_route']) !== false;
            $active_class = $is_active ? 'bg-amber-600 shadow-lg' : 'hover:bg-gray-700';
            $permissions = $permissionManager->getModulePermissions($module['module_name']);
            ?>
            
            <a href="<?php echo htmlspecialchars($module['module_route']); ?>" 
               class="flex items-center p-3 rounded-lg transition-all <?php echo $active_class; ?>"
               title="<?php echo htmlspecialchars($module['module_name_ar']); ?>">
                <i class="<?php echo htmlspecialchars($module['icon']); ?> ml-3 text-lg"></i>
                <span class="flex-1"><?php echo htmlspecialchars($module['module_name_ar']); ?></span>
                
                <!-- Permission badges -->
                <div class="flex gap-1">
                    <?php if ($permissions['view']): ?>
                        <span class="text-xs bg-blue-500 px-1 rounded" title="مشاهدة">👁</span>
                    <?php endif; ?>
                    <?php if ($permissions['edit']): ?>
                        <span class="text-xs bg-yellow-500 px-1 rounded" title="تعديل">✏</span>
                    <?php endif; ?>
                    <?php if ($permissions['create']): ?>
                        <span class="text-xs bg-amber-500 px-1 rounded" title="إضافة">+</span>
                    <?php endif; ?>
                    <?php if ($permissions['delete']): ?>
                        <span class="text-xs bg-red-500 px-1 rounded" title="حذف">🗑</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
        
        <?php if (empty($allowed_modules)): ?>
            <div class="text-center text-gray-400 py-8">
                <i class="fas fa-lock text-4xl mb-3"></i>
                <p>لا توجد صلاحيات</p>
                <p class="text-xs mt-2">يرجى التواصل مع المدير</p>
            </div>
        <?php endif; ?>
    </nav>

    <!-- Logout Button -->
    <div class="mt-8 pt-4 border-t border-gray-700">
        <a href="/logout.php" class="flex items-center p-3 rounded-lg hover:bg-red-600 transition-all">
            <i class="fas fa-sign-out-alt ml-3"></i>
            <span>تسجيل الخروج</span>
        </a>
    </div>
</aside>

<style>
.sidebar {
    position: fixed;
    right: 0;
    top: 0;
    height: 100vh;
    overflow-y: auto;
    z-index: 1000;
}

.sidebar::-webkit-scrollbar {
    width: 6px;
}

.sidebar::-webkit-scrollbar-track {
    background: rgba(0,0,0,0.1);
}

.sidebar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.2);
    border-radius: 3px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.3);
}
</style>
