<?php
/**
 * Permission Manager Class - FIXED for existing database structure
 * Path: /includes/PermissionManager.php
 */

class PermissionManager {
    private $db;
    private $user_id;
    private $is_admin = false;
    private $user_permissions = [];
    
    public function __construct($database, $user_id) {
        $this->db = $database;
        $this->user_id = $user_id;
        $this->loadUserPermissions();
    }
    
    /**
     * Load user permissions from database
     */
    private function loadUserPermissions() {
        try {
            // Check if user is admin
            $stmt = $this->db->prepare("SELECT is_admin FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->is_admin = ($user && $user['is_admin'] == 1);
            
            if ($this->is_admin) {
                return; // Admin has all permissions
            }
            
            // Load user's specific permissions from existing structure
            $stmt = $this->db->prepare("
                SELECT p.permission_key, p.module, p.permission_name
                FROM user_permissions up
                JOIN permissions p ON up.permission_id = p.id
                WHERE up.user_id = ?
            ");
            $stmt->execute([$this->user_id]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $module = $row['module'];
                $key = $row['permission_key'];
                
                // Parse permission key to determine action
                // Examples: "sales.manage", "inventory.view", "financial.accounts.view"
                if (!isset($this->user_permissions[$module])) {
                    $this->user_permissions[$module] = [
                        'view' => false,
                        'create' => false,
                        'edit' => false,
                        'delete' => false
                    ];
                }
                
                // Determine action from permission key (supports both dot and underscore notation)
                if (strpos($key, '_view') !== false || strpos($key, '.view') !== false) {
                    $this->user_permissions[$module]['view'] = true;
                } elseif (strpos($key, '_manage') !== false || strpos($key, '.manage') !== false) {
                    // manage implies all permissions
                    $this->user_permissions[$module]['view'] = true;
                    $this->user_permissions[$module]['create'] = true;
                    $this->user_permissions[$module]['edit'] = true;
                    $this->user_permissions[$module]['delete'] = true;
                } elseif (strpos($key, '_edit') !== false || strpos($key, '.edit') !== false) {
                    $this->user_permissions[$module]['view'] = true;
                    $this->user_permissions[$module]['edit'] = true;
                } elseif (strpos($key, '_add') !== false || strpos($key, '_create') !== false || strpos($key, '.create') !== false || strpos($key, '.add') !== false) {
                    $this->user_permissions[$module]['view'] = true;
                    $this->user_permissions[$module]['create'] = true;
                } elseif (strpos($key, '_delete') !== false || strpos($key, '.delete') !== false) {
                    $this->user_permissions[$module]['delete'] = true;
                }
            }
        } catch (PDOException $e) {
            error_log("Permission loading error: " . $e->getMessage());
        }
    }
    
    /**
     * Check if user has permission
     */
    public function hasPermission($module_name, $action = 'view') {
        // Admin has all permissions
        if ($this->is_admin) {
            return true;
        }
        
        // Map module names to database module names
        $module_map = [
            'orders' => 'sales',
            'baskets' => 'inventory',
            'purchases' => 'inventory',
            'customers' => 'customers',
            'customer_invoices' => 'customer_invoices',
            'inventory' => 'inventory',
            'financial' => 'financial',
            'reports' => 'financial',
            'settings' => 'settings',
            'employees' => 'financial',
            'dashboard' => 'dashboard'
        ];
        
        $db_module = $module_map[$module_name] ?? $module_name;
        
        // Check specific permission
        if (isset($this->user_permissions[$db_module][$action])) {
            return $this->user_permissions[$db_module][$action];
        }
        
        return false;
    }
    
    /**
     * Get all modules user can view
     */
    public function getAllowedModules() {
        try {
            if ($this->is_admin) {
                // Admin sees all modules - return hardcoded list
                return [
                    ['module_name' => 'dashboard', 'module_name_ar' => 'لوحة التحكم', 'module_route' => '/index.php', 'icon' => 'fas fa-home', 'display_order' => 1],
                    ['module_name' => 'orders', 'module_name_ar' => 'إدارة الطلبات', 'module_route' => '/modules/orders/index.php', 'icon' => 'fas fa-shopping-cart', 'display_order' => 2],
                    ['module_name' => 'baskets', 'module_name_ar' => 'سلال الشراء', 'module_route' => '/modules/purchases/show_baskets.php', 'icon' => 'fas fa-shopping-basket', 'display_order' => 3],
                    ['module_name' => 'purchases', 'module_name_ar' => 'مجموعات الشراء', 'module_route' => '/modules/purchases/groups/index.php', 'icon' => 'fas fa-layer-group', 'display_order' => 4],
                    ['module_name' => 'customers', 'module_name_ar' => 'إدارة العملاء', 'module_route' => '/modules/customers/index.php', 'icon' => 'fas fa-users', 'display_order' => 5],
                    ['module_name' => 'inventory', 'module_name_ar' => 'المخزون', 'module_route' => '/modules/inventory/index.php', 'icon' => 'fas fa-boxes', 'display_order' => 6],
                    ['module_name' => 'financial', 'module_name_ar' => 'الحسابات المالية', 'module_route' => '/modules/financial/index.php', 'icon' => 'fas fa-dollar-sign', 'display_order' => 7],
                    ['module_name' => 'reports', 'module_name_ar' => 'التقارير', 'module_route' => '/modules/reports/index.php', 'icon' => 'fas fa-chart-bar', 'display_order' => 8],
                    ['module_name' => 'employees', 'module_name_ar' => 'إدارة الموظفين', 'module_route' => '/modules/financial/employee_permissions.php', 'icon' => 'fas fa-user-tie', 'display_order' => 9],
                ];
            } else {
                // Return modules user has view permission for
                $allowed = [];
                $all_modules = [
                    ['module_name' => 'dashboard', 'module_name_ar' => 'لوحة التحكم', 'module_route' => '/index.php', 'icon' => 'fas fa-home', 'display_order' => 1],
                    ['module_name' => 'orders', 'module_name_ar' => 'إدارة الطلبات', 'module_route' => '/modules/orders/index.php', 'icon' => 'fas fa-shopping-cart', 'display_order' => 2],
                    ['module_name' => 'baskets', 'module_name_ar' => 'سلال الشراء', 'module_route' => '/modules/purchases/show_baskets.php', 'icon' => 'fas fa-shopping-basket', 'display_order' => 3],
                    ['module_name' => 'purchases', 'module_name_ar' => 'مجموعات الشراء', 'module_route' => '/modules/purchases/groups/index.php', 'icon' => 'fas fa-layer-group', 'display_order' => 4],
                    ['module_name' => 'customers', 'module_name_ar' => 'إدارة العملاء', 'module_route' => '/modules/customers/index.php', 'icon' => 'fas fa-users', 'display_order' => 5],
                    ['module_name' => 'inventory', 'module_name_ar' => 'المخزون', 'module_route' => '/modules/inventory/index.php', 'icon' => 'fas fa-boxes', 'display_order' => 6],
                    ['module_name' => 'financial', 'module_name_ar' => 'الحسابات المالية', 'module_route' => '/modules/financial/index.php', 'icon' => 'fas fa-dollar-sign', 'display_order' => 7],
                    ['module_name' => 'reports', 'module_name_ar' => 'التقارير', 'module_route' => '/modules/reports/index.php', 'icon' => 'fas fa-chart-bar', 'display_order' => 8],
                ];
                
                foreach ($all_modules as $module) {
                    if ($this->hasPermission($module['module_name'], 'view')) {
                        $allowed[] = $module;
                    }
                }
                
                return $allowed;
            }
        } catch (PDOException $e) {
            error_log("Get allowed modules error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get first allowed module route (for redirect)
     */
    public function getFirstAllowedRoute() {
        $modules = $this->getAllowedModules();
        return !empty($modules) ? $modules[0]['module_route'] : '/modules/orders/index.php';
    }
    
    /**
     * Require permission or redirect
     */
    public function requirePermission($module_name, $action = 'view', $redirect_url = '/index.php') {
        if (!$this->hasPermission($module_name, $action)) {
            $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
            header("Location: $redirect_url");
            exit();
        }
    }
    
    /**
     * Check if user is admin
     */
    public function isAdmin() {
        return $this->is_admin;
    }
    
    /**
     * Get user permissions for a specific module
     */
    public function getModulePermissions($module_name) {
        if ($this->is_admin) {
            return [
                'view' => true,
                'create' => true,
                'edit' => true,
                'delete' => true
            ];
        }
        
        $module_map = [
            'orders' => 'sales',
            'baskets' => 'inventory',
            'purchases' => 'inventory',
            'customers' => 'customers',
            'customer_invoices' => 'customer_invoices',
            'inventory' => 'inventory',
            'financial' => 'financial',
            'reports' => 'financial',
            'settings' => 'settings',
            'employees' => 'financial',
            'dashboard' => 'dashboard'
        ];
        
        $db_module = $module_map[$module_name] ?? $module_name;
        
        return $this->user_permissions[$db_module] ?? [
            'view' => false,
            'create' => false,
            'edit' => false,
            'delete' => false
        ];
    }
}
