<?php
/**
 * Role-Based Access Control (RBAC) Helper Functions
 * Enhanced permission system with caching and role support
 */

/**
 * Check if user has a specific permission (role-based)
 * @param int $user_id User ID
 * @param string $page_key Page key (e.g., 'customers.index')
 * @param string $action Action type ('view', 'add', 'edit')
 * @return bool
 */
function hasPermission($user_id, $page_key, $action = 'view') {
    global $db;
    
    // Super admin bypass
    if (isSuperAdmin($user_id)) {
        return true;
    }
    
    // Check cache first
    $cacheKey = "permission_{$user_id}_{$page_key}_{$action}";
    $cached = getPermissionCache($user_id, $cacheKey);
    
    if ($cached !== null) {
        return (bool) $cached;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT rp.can_{$action}
            FROM users u
            JOIN role_permissions rp ON u.role_id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE u.id = ? 
              AND p.permission_key = ? 
              AND rp.can_{$action} = 1
            LIMIT 1
        ");
        $stmt->execute([$user_id, $page_key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $hasAccess = !empty($result);
        
        // Cache the result
        setPermissionCache($user_id, $cacheKey, $hasAccess);
        
        return $hasAccess;
    } catch (PDOException $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if user is super admin
 */
function isSuperAdmin($user_id = null) {
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
 * Get user's sidebar items (only permitted pages)
 * @param int $user_id
 * @return array Grouped by module
 */
function getUserSidebar($user_id) {
    global $db;
    
    if (isSuperAdmin($user_id)) {
        return getAllSidebarItems();
    }
    
    // Check cache
    $cacheKey = "sidebar_{$user_id}";
    $cached = getPermissionCache($user_id, $cacheKey);
    
    if ($cached !== null) {
        return json_decode($cached, true);
    }
    
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT
                p.module_key,
                p.module_name,
                p.page_key,
                p.page_name,
                p.route_path,
                p.sidebar_icon,
                p.sort_order,
                rp.can_view,
                rp.can_add,
                rp.can_edit
            FROM users u
            JOIN role_permissions rp ON u.role_id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE u.id = ?
              AND p.is_active = 1
              AND (rp.can_view = 1 OR rp.can_add = 1 OR rp.can_edit = 1)
              AND p.sidebar_icon IS NOT NULL
            ORDER BY p.sort_order ASC
        ");
        $stmt->execute([$user_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $grouped = groupSidebarByModule($items);
        
        // Cache for 24 hours
        setPermissionCache($user_id, $cacheKey, json_encode($grouped), 86400);
        
        return $grouped;
    } catch (PDOException $e) {
        error_log("Sidebar fetch error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all sidebar items (for super admin)
 */
function getAllSidebarItems() {
    global $db;
    
    try {
        $stmt = $db->query("
            SELECT DISTINCT
                module_key,
                module_name,
                page_key,
                page_name,
                route_path,
                sidebar_icon,
                sort_order,
                1 as can_view,
                1 as can_add,
                1 as can_edit
            FROM permissions
            WHERE is_active = 1
              AND sidebar_icon IS NOT NULL
            ORDER BY sort_order ASC
        ");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return groupSidebarByModule($items);
    } catch (PDOException $e) {
        error_log("All sidebar fetch error: " . $e->getMessage());
        return [];
    }
}

/**
 * Group sidebar items by module
 */
function groupSidebarByModule($items) {
    $grouped = [];
    
    foreach ($items as $item) {
        $moduleKey = $item['module_key'];
        
        if (!isset($grouped[$moduleKey])) {
            $grouped[$moduleKey] = [
                'module_name' => $item['module_name'],
                'module_key' => $moduleKey,
                'pages' => []
            ];
        }
        
        $grouped[$moduleKey]['pages'][] = [
            'page_key' => $item['page_key'],
            'page_name' => $item['page_name'],
            'route_path' => $item['route_path'],
            'icon' => $item['sidebar_icon'],
            'can_view' => (bool) $item['can_view'],
            'can_add' => (bool) $item['can_add'],
            'can_edit' => (bool) $item['can_edit'],
        ];
    }
    
    return array_values($grouped);
}

/**
 * Get first permitted page for redirect after login
 * @param int $user_id
 * @return string|null Route path
 */
function getFirstPermittedPage($user_id) {
    $sidebar = getUserSidebar($user_id);
    
    if (empty($sidebar)) {
        return null;
    }
    
    // Return first module's first page
    $firstModule = $sidebar[0];
    $firstPage = $firstModule['pages'][0] ?? null;
    
    return $firstPage['route_path'] ?? null;
}

/**
 * Require permission or redirect
 * @param string $page_key
 * @param string $action
 * @param string $redirect_url
 */
function requirePermission($page_key, $action = 'view', $redirect_url = '/index.php') {
    $user_id = $_SESSION['user_id'] ?? 0;
    
    if (!$user_id) {
        $_SESSION['error_message'] = 'يجب تسجيل الدخول أولاً';
        header("Location: /login.php");
        exit();
    }
    
    if (!hasPermission($user_id, $page_key, $action)) {
        $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
        header("Location: $redirect_url");
        exit();
    }
}

/**
 * Clear all permission cache for a user
 * @param int $user_id
 */
function clearUserPermissionCache($user_id) {
    global $db;
    
    try {
        $stmt = $db->prepare("DELETE FROM permission_cache WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return true;
    } catch (PDOException $e) {
        error_log("Cache clear error: " . $e->getMessage());
        return false;
    }
}

/**
 * Clear permission cache for all users in a role
 * @param int $role_id
 */
function clearRolePermissionCache($role_id) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            DELETE pc FROM permission_cache pc
            JOIN users u ON pc.user_id = u.id
            WHERE u.role_id = ?
        ");
        $stmt->execute([$role_id]);
        return true;
    } catch (PDOException $e) {
        error_log("Role cache clear error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get permission from cache
 */
function getPermissionCache($user_id, $cache_key) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            SELECT cache_value 
            FROM permission_cache 
            WHERE user_id = ? 
              AND cache_key = ? 
              AND expires_at > NOW()
        ");
        $stmt->execute([$user_id, $cache_key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['cache_value'] : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Set permission cache
 */
function setPermissionCache($user_id, $cache_key, $cache_value, $ttl = 3600) {
    global $db;
    
    try {
        $expires_at = date('Y-m-d H:i:s', time() + $ttl);
        
        $stmt = $db->prepare("
            INSERT INTO permission_cache (user_id, cache_key, cache_value, expires_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                cache_value = VALUES(cache_value),
                expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$user_id, $cache_key, $cache_value, $expires_at]);
        return true;
    } catch (PDOException $e) {
        error_log("Cache set error: " . $e->getMessage());
        return false;
    }
}

/**
 * Clean expired cache entries
 */
function cleanExpiredCache() {
    global $db;
    
    try {
        $stmt = $db->query("DELETE FROM permission_cache WHERE expires_at < NOW()");
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Cache cleanup error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Log permission change for audit
 */
function logPermissionChange($role_id, $permission_id, $action, $old_value, $new_value, $changed_by) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO permission_audit_log 
            (role_id, permission_id, action, old_value, new_value, changed_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $role_id,
            $permission_id,
            $action,
            json_encode($old_value),
            json_encode($new_value),
            $changed_by
        ]);
        return true;
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}

// Shorthand helper functions
function canView($page_key, $user_id = null) {
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    return hasPermission($user_id, $page_key, 'view');
}

function canAdd($page_key, $user_id = null) {
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    return hasPermission($user_id, $page_key, 'add');
}

function canEdit($page_key, $user_id = null) {
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    return hasPermission($user_id, $page_key, 'edit');
}
