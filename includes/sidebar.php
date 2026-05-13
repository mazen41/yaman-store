<?php
/**
 * Dynamic Sidebar Component
 * Displays only permitted pages based on user's role
 */

require_once __DIR__ . '/rbac_helpers.php';

$user_id = $_SESSION['user_id'] ?? 0;
$current_page = $_SERVER['PHP_SELF'];

if ($user_id) {
    $sidebarItems = getUserSidebar($user_id);
} else {
    $sidebarItems = [];
}
?>

<style>
.sidebar {
    background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%);
    color: white;
    min-height: 100vh;
    padding: 20px 0;
    direction: rtl;
}

.sidebar-module {
    margin-bottom: 25px;
}

.module-title {
    font-size: 14px;
    font-weight: 600;
    color: #93c5fd;
    padding: 10px 20px;
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.module-pages {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-link {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    color: #e0e7ff;
    text-decoration: none;
    transition: all 0.3s ease;
    border-right: 3px solid transparent;
}

.sidebar-link:hover {
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border-right-color: #60a5fa;
}

.sidebar-link.active {
    background: rgba(96, 165, 250, 0.2);
    color: white;
    border-right-color: #60a5fa;
    font-weight: 600;
}

.sidebar-link i {
    margin-left: 12px;
    font-size: 18px;
    width: 24px;
    text-align: center;
}

.sidebar-link span {
    flex: 1;
}

.no-permissions {
    text-align: center;
    padding: 40px 20px;
    color: #93c5fd;
}

.no-permissions i {
    font-size: 48px;
    margin-bottom: 15px;
    opacity: 0.5;
}

.permission-badge {
    display: inline-block;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 3px;
    margin-right: 5px;
    background: rgba(255, 255, 255, 0.2);
}

.sidebar-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(0, 0, 0, 0.2);
    padding: 15px 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.user-info {
    display: flex;
    align-items: center;
    color: white;
    font-size: 14px;
}

.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: 10px;
    font-weight: bold;
}
</style>

<aside class="sidebar">
    <?php if (empty($sidebarItems)): ?>
        <div class="no-permissions">
            <i class="fas fa-lock"></i>
            <p>لا توجد صلاحيات متاحة</p>
            <small>يرجى الاتصال بالمسؤول</small>
        </div>
    <?php else: ?>
        <?php foreach ($sidebarItems as $module): ?>
            <div class="sidebar-module">
                <h3 class="module-title">
                    <?php echo htmlspecialchars($module['module_name']); ?>
                </h3>
                
                <ul class="module-pages">
                    <?php foreach ($module['pages'] as $page): ?>
                        <?php if ($page['can_view']): ?>
                            <?php 
                            $isActive = strpos($current_page, basename($page['route_path'])) !== false;
                            ?>
                            <li>
                                <a href="<?php echo htmlspecialchars($page['route_path']); ?>" 
                                   class="sidebar-link <?php echo $isActive ? 'active' : ''; ?>"
                                   title="<?php echo htmlspecialchars($page['page_name']); ?>">
                                    <?php if ($page['icon']): ?>
                                        <i class="<?php echo htmlspecialchars($page['icon']); ?>"></i>
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($page['page_name']); ?></span>
                                    
                                    <?php if ($page['can_add'] || $page['can_edit']): ?>
                                        <div style="font-size: 10px; opacity: 0.7;">
                                            <?php if ($page['can_add']): ?>
                                                <span class="permission-badge">إضافة</span>
                                            <?php endif; ?>
                                            <?php if ($page['can_edit']): ?>
                                                <span class="permission-badge">تعديل</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Super Admin Only: Portal Slider Settings -->
    <?php if (isset($_SESSION['user_id']) && isSuperAdmin($_SESSION['user_id'])): ?>
    <div class="sidebar-module">
        <h3 class="module-title">
            إعدادات النظام
        </h3>
        <ul class="module-pages">
            <li>
                <a href="/modules/settings/portal_slides.php" 
                   class="sidebar-link <?php echo strpos($current_page, 'portal_slides.php') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-images"></i>
                    <span>سلايدر البوابة</span>
                    <span class="permission-badge" style="background: #f59e0b; color: white;">Super Admin</span>
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- User Info Footer -->
    <?php if (isset($_SESSION['user_name'])): ?>
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
            </div>
            <div>
                <div style="font-weight: 600;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                <div style="font-size: 11px; opacity: 0.7;">
                    <?php echo isSuperAdmin($_SESSION['user_id']) ? 'مدير النظام' : 'موظف'; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</aside>

<script>
// Auto-collapse modules on mobile
if (window.innerWidth < 768) {
    document.querySelectorAll('.sidebar-module').forEach(module => {
        const hasActive = module.querySelector('.sidebar-link.active');
        if (!hasActive) {
            module.style.display = 'none';
        }
    });
}

// Highlight parent module of active page
const activeLink = document.querySelector('.sidebar-link.active');
if (activeLink) {
    const module = activeLink.closest('.sidebar-module');
    if (module) {
        module.querySelector('.module-title').style.color = '#60a5fa';
    }
}
</script>
