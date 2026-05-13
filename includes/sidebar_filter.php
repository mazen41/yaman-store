<?php
/**
 * Sidebar Filter - Show only permitted modules
 * Include this in header.php or sidebar.php
 */

function getUserPermittedModules($user_id, $db) {
    // Admins see everything
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        return 'all';
    }
    
    try {
        // Get user's permission IDs
        $stmt = $db->prepare("
            SELECT DISTINCT permission_id 
            FROM user_permissions 
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $permission_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($permission_ids)) {
            return [];
        }
        
        // Map permission IDs to module keys
        $permission_to_modules = [
            16 => ['customers', 'customer_invoices', 'customer_types', 'cities'],
            17 => ['customers', 'customer_invoices', 'customer_types', 'cities'],
            10 => ['orders', 'orders_edit', 'loyalty_cards', 'shipping', 'whatsapp', 'coupons'],
            11 => ['orders', 'orders_view', 'loyalty_cards', 'shipping', 'whatsapp', 'coupons'],
            12 => ['orders', 'orders_edit'],
            1 => ['financial_review', 'financial', 'bank_accounts', 'expenses'],
            2 => ['financial_review', 'financial', 'bank_accounts', 'expenses'],
            3 => ['financial_review', 'financial', 'expenses'],
            4 => ['financial_review', 'financial', 'expenses'],
            5 => ['financial_review', 'reports'],
            6 => ['financial_review', 'reports'],
            13 => ['purchases', 'purchase_groups', 'groups_edit', 'baskets', 'baskets_edit', 'purchase_cards'],
            14 => ['purchases', 'purchase_groups', 'groups_view', 'baskets', 'baskets_view', 'purchase_cards'],
            15 => ['purchases', 'purchase_groups', 'groups_edit', 'baskets', 'baskets_edit', 'purchase_cards'],
            9 => ['suppliers'],
            7 => ['inventory'],
            8 => ['inventory'],
            18 => ['employees'],
            20 => ['employees'],
            19 => ['permissions'],
            21 => ['settings']
        ];
        
        $permitted_modules = [];
        foreach ($permission_ids as $perm_id) {
            if (isset($permission_to_modules[$perm_id])) {
                $permitted_modules = array_merge($permitted_modules, $permission_to_modules[$perm_id]);
            }
        }
        
        return array_unique($permitted_modules);
        
    } catch (PDOException $e) {
        return [];
    }
}

function getFirstPermittedModuleUrl($user_id, $db) {
    // Admins go to dashboard
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        return '/index.php';
    }
    
    try {
        // Get user's permission IDs in order
        $stmt = $db->prepare("
            SELECT DISTINCT permission_id 
            FROM user_permissions 
            WHERE user_id = ?
            ORDER BY granted_at ASC
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $first_perm = $stmt->fetchColumn();
        
        if (!$first_perm) {
            return '/index.php';
        }
        
        // Map permission ID to first module URL
        $permission_to_url = [
            16 => '/modules/customers/index.php',
            17 => '/modules/customers/index.php',
            10 => '/modules/orders/index.php',
            11 => '/modules/orders/index.php',
            12 => '/modules/orders/index.php',
            1 => '/modules/orders/financial_review.php',
            2 => '/modules/orders/financial_review.php',
            13 => '/modules/purchases/index.php',
            14 => '/modules/purchases/index.php',
            15 => '/modules/purchases/index.php',
            9 => '/modules/purchases/suppliers.php',
            7 => '/modules/inventory/index.php',
            8 => '/modules/inventory/index.php',
            18 => '/modules/financial/employee-manage.php',
            19 => '/modules/financial/employee-permissions.php',
            21 => '/modules/settings/index.php',
            5 => '/modules/reports/index.php',
            6 => '/modules/reports/index.php'
        ];
        
        if (isset($permission_to_url[$first_perm])) {
            return $permission_to_url[$first_perm];
        }
        
    } catch (PDOException $e) {
        // Error, return default
    }
    
    return '/index.php';
}

function canAccessModule($user_id, $module_key, $db) {
    // Admins can access everything
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        return true;
    }
    
    $permitted = getUserPermittedModules($user_id, $db);
    
    if ($permitted === 'all') {
        return true;
    }
    
    return in_array($module_key, $permitted);
}
?>
