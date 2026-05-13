<?php
/**
 * Permission Check Functions - Works with user_permissions + permissions tables
 * Path: /includes/check_permissions.php
 * 
 * Usage in any page:
 *   require_once '../../includes/check_permissions.php';
 *   
 *   // Check if user can view
 *   if (!hasPermission($_SESSION['user_id'], 'customers', 'view')) {
 *       header('Location: /no-permissions.php');
 *       exit();
 *   }
 *   
 *   // Check if user can add (for showing/hiding add button)
 *   $can_add = hasPermission($_SESSION['user_id'], 'customers', 'add');
 *   
 *   // Check if user can edit (for showing/hiding edit button)
 *   $can_edit = hasPermission($_SESSION['user_id'], 'customers', 'edit');
 */

/**
 * Check if user is admin
 */
function isUserAdmin($user_id, $db) {
    static $cache = [];
    
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }
    
    try {
        $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$user_id] = ($result && $result['is_admin'] == 1);
        return $cache[$user_id];
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Check if user has a specific permission
 * 
 * @param int $user_id User ID
 * @param string $module Module key (e.g., 'customers', 'orders', 'baskets')
 * @param string $permission_type Permission type: 'view', 'edit', 'add'
 * @return bool
 */
function hasPermission($user_id, $module, $permission_type = 'view') {
    global $db;
    
    // Admins have all permissions
    if (isUserAdmin($user_id, $db)) {
        return true;
    }
    
    // Build permission key (e.g., "customers_view")
    $permission_key = $module . '_' . $permission_type;
    
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? 
            AND p.permission_key = ?
        ");
        $stmt->execute([$user_id, $permission_key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    } catch (PDOException $e) {
        // Log error but don't expose it
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all permissions for a user (cached)
 */
function getUserPermissions($user_id) {
    global $db;
    static $cache = [];
    
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }
    
    // Admins have all permissions
    if (isUserAdmin($user_id, $db)) {
        $cache[$user_id] = ['is_admin' => true];
        return $cache[$user_id];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT p.permission_key, p.module_name, p.permission_type
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ?
        ");
        $stmt->execute([$user_id]);
        
        $permissions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $permissions[$row['permission_key']] = true;
            
            // Also store by module for easier access
            if (!isset($permissions['modules'][$row['module_name']])) {
                $permissions['modules'][$row['module_name']] = [];
            }
            $permissions['modules'][$row['module_name']][] = $row['permission_type'];
        }
        
        $cache[$user_id] = $permissions;
        return $permissions;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Check permission and redirect if not allowed
 */
function requirePermission($user_id, $module, $permission_type = 'view', $redirect_url = null) {
    if (!hasPermission($user_id, $module, $permission_type)) {
        if ($redirect_url === null) {
            $redirect_url = '/no-permissions.php';
        }
        
        $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
        header('Location: ' . $redirect_url);
        exit();
    }
}

/**
 * Check if user can access module (has any permission for it)
 */
function canAccessModule($user_id, $module) {
    return hasPermission($user_id, $module, 'view');
}

/**
 * Check if user can add in module
 */
function canAdd($user_id, $module) {
    return hasPermission($user_id, $module, 'add');
}

/**
 * Check if user can edit in module
 */
function canEdit($user_id, $module) {
    return hasPermission($user_id, $module, 'edit');
}

/**
 * Check if user can view in module
 */
function canView($user_id, $module) {
    return hasPermission($user_id, $module, 'view');
}

/**
 * Get first permitted page for user (for redirect after login)
 */
function getFirstPermittedPage($user_id) {
    global $db;
    
    // Admin goes to dashboard
    if (isUserAdmin($user_id, $db)) {
        return '/index.php';
    }
    
    // Module routes mapping
    $module_routes = [
        'dashboard' => '/index.php',
        'customers' => '/modules/customers/index.php',
        'orders' => '/modules/orders/index.php',
        'baskets' => '/modules/purchases/show_baskets.php',
        'purchase_groups' => '/modules/purchases/groups/index.php',
        'purchase_cards' => '/modules/purchase_cards/index.php',
        'financial' => '/modules/financial/index.php',
        'reports' => '/modules/reports/index.php',
    ];
    
    // Check each module for view permission
    foreach ($module_routes as $module => $route) {
        if (hasPermission($user_id, $module, 'view')) {
            return $route;
        }
    }
    
    // Default fallback
    return '/index.php';
}

/**
 * Legacy function aliases for backward compatibility
 * These accept optional parameters to maintain compatibility with old code
 */
function canViewOrders($user_id = null, $db = null) {
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    return hasPermission($uid, 'orders', 'view');
}

function canEditOrders($user_id = null, $db = null) {
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    return hasPermission($uid, 'orders', 'edit');
}

function canAddOrders($user_id = null, $db = null) {
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    return hasPermission($uid, 'orders', 'add');
}

function canDeleteOrders($user_id = null, $db = null) {
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    return hasPermission($uid, 'orders', 'edit'); // delete = edit permission
}

function canViewCustomers($user_id = null, $db = null) {
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    return hasPermission($uid, 'customers', 'view');
}

function canEditCustomers($user_id = null, $db = null) {
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    return hasPermission($uid, 'customers', 'edit');
}

function canAddCustomers($user_id = null, $db = null) {
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    return hasPermission($uid, 'customers', 'add');
}

function canDeleteCustomers($user_id = null, $db = null) {
    $uid = $user_id ?? ($_SESSION['user_id'] ?? 0);
    return hasPermission($uid, 'customers', 'edit'); // delete = edit permission
}
