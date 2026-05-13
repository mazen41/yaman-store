<?php
/**
 * Granular Permission Helper Functions
 * Use these functions to check specific permissions (view, edit, add) for each module
 */

/**
 * Check if user has a specific permission
 * @param int $user_id User ID
 * @param string $module_key Module key (e.g., 'customers', 'orders')
 * @param string $permission_type Permission type ('view', 'edit', 'add', 'delete')
 * @return bool
 */
function hasPermission($user_id, $module_key, $permission_type = 'view') {
    global $db;
    
    // Admins have all permissions
    if (isAdmin($user_id)) {
        return true;
    }
    
    try {
        $permission_key = $module_key . '_' . $permission_type;
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.permission_key = ?
        ");
        $stmt->execute([$user_id, $permission_key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    } catch (PDOException $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if user can view a module
 */
function canView($module_key, $user_id = null) {
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    return hasPermission($user_id, $module_key, 'view');
}

/**
 * Check if user can edit in a module
 */
function canEdit($module_key, $user_id = null) {
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    return hasPermission($user_id, $module_key, 'edit');
}

/**
 * Check if user can add in a module
 */
function canAdd($module_key, $user_id = null) {
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    return hasPermission($user_id, $module_key, 'add');
}

/**
 * Check if user can delete in a module
 */
function canDelete($module_key, $user_id = null) {
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    return hasPermission($user_id, $module_key, 'delete');
}

/**
 * Check if user is admin
 */
function isAdmin($user_id = null) {
    global $db;
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    
    try {
        $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user && $user['is_admin'] == 1;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Require specific permission or redirect
 * @param string $module_key Module key
 * @param string $permission_type Permission type
 * @param string $redirect_url Where to redirect if no permission
 */
function requirePermission($module_key, $permission_type = 'view', $redirect_url = '../../index.php') {
    $user_id = $_SESSION['user_id'] ?? 0;
    
    if (!hasPermission($user_id, $module_key, $permission_type)) {
        $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
        header("Location: $redirect_url");
        exit();
    }
}

/**
 * Get all permissions for a user
 * @param int $user_id
 * @return array Array of permission keys
 */
function getUserPermissions($user_id) {
    global $db;
    
    if (isAdmin($user_id)) {
        return ['*']; // Admin has all permissions
    }
    
    try {
        $stmt = $db->prepare("
            SELECT p.permission_key, p.permission_type, p.module_name
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ?
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get user permissions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if user has any permission for a module (view, edit, or add)
 * @param string $module_key
 * @param int|null $user_id
 * @return bool
 */
function hasModuleAccess($module_key, $user_id = null) {
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    
    return canView($module_key, $user_id) || 
           canEdit($module_key, $user_id) || 
           canAdd($module_key, $user_id);
}

/**
 * Generate permission-aware button HTML
 * @param string $module_key
 * @param string $permission_type
 * @param string $button_html The button HTML to show if user has permission
 * @return string
 */
function permissionButton($module_key, $permission_type, $button_html) {
    if (hasPermission($_SESSION['user_id'] ?? 0, $module_key, $permission_type)) {
        return $button_html;
    }
    return '';
}

/**
 * Show/hide elements based on permission
 * Usage in HTML: <?php if (showIf('customers', 'edit')): ?> ... <?php endif; ?>
 */
function showIf($module_key, $permission_type) {
    return hasPermission($_SESSION['user_id'] ?? 0, $module_key, $permission_type);
}
