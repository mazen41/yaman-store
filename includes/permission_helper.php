<?php
/**
 * Permission Helper Functions
 * Path: /includes/permission_helper.php
 */

/**
 * Get user's module permissions
 */
function getUserModulePermissions($user_id, $db) {
    try {
        $stmt = $db->prepare("
            SELECT module_name, permission_type 
            FROM user_module_permissions 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($permissions as $perm) {
            if (!isset($result[$perm['module_name']])) {
                $result[$perm['module_name']] = [];
            }
            $result[$perm['module_name']][] = $perm['permission_type'];
        }
        
        return $result;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Check if user has permission for a module
 */
function hasModulePermission($user_id, $module, $permission_type, $db) {
    // Admins have all permissions
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        return true;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM user_module_permissions 
            WHERE user_id = ? 
            AND module_name = ? 
            AND permission_type = ?
        ");
        $stmt->execute([$user_id, $module, $permission_type]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get first permitted module URL for redirect after login
 */
function getFirstPermittedModuleUrl($user_id, $db) {
    $module_urls = [
        'orders' => '/modules/orders/index.php',
        'baskets' => '/modules/purchases/show_baskets.php',
        'groups' => '/modules/purchases/groups/index.php'
    ];
    
    try {
        $stmt = $db->prepare("
            SELECT module_name 
            FROM user_module_permissions 
            WHERE user_id = ? 
            ORDER BY granted_at ASC 
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($module_urls[$result['module_name']])) {
            return $module_urls[$result['module_name']];
        }
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // Default fallback
    return '/index.php';
}

/**
 * Get sidebar menu items based on user permissions
 */
function getSidebarMenuItems($user_id, $db) {
    // Admins see everything
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        return [
            [
                'name' => 'الطلبات',
                'icon' => 'fa-shopping-cart',
                'url' => '/modules/orders/index.php',
                'module' => 'orders'
            ],
            [
                'name' => 'السلات',
                'icon' => 'fa-shopping-basket',
                'url' => '/modules/purchases/show_baskets.php',
                'module' => 'baskets'
            ],
            [
                'name' => 'المجموعات',
                'icon' => 'fa-layer-group',
                'url' => '/modules/purchases/groups/index.php',
                'module' => 'groups'
            ]
        ];
    }
    
    $all_menu_items = [
        'orders' => [
            'name' => 'الطلبات',
            'icon' => 'fa-shopping-cart',
            'url' => '/modules/orders/index.php',
            'module' => 'orders'
        ],
        'baskets' => [
            'name' => 'السلات',
            'icon' => 'fa-shopping-basket',
            'url' => '/modules/purchases/show_baskets.php',
            'module' => 'baskets'
        ],
        'groups' => [
            'name' => 'المجموعات',
            'icon' => 'fa-layer-group',
            'url' => '/modules/purchases/groups/index.php',
            'module' => 'groups'
        ]
    ];
    
    $user_permissions = getUserModulePermissions($user_id, $db);
    $menu_items = [];
    
    foreach ($all_menu_items as $module => $item) {
        if (isset($user_permissions[$module]) && !empty($user_permissions[$module])) {
            $menu_items[] = $item;
        }
    }
    
    return $menu_items;
}

/**
 * Check if user can access a specific module
 */
function canAccessModule($user_id, $module, $db) {
    // Admins can access everything
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        return true;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM user_module_permissions 
            WHERE user_id = ? 
            AND module_name = ?
        ");
        $stmt->execute([$user_id, $module]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    } catch (PDOException $e) {
        return false;
    }
}
?>
