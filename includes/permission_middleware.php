<?php
/**
 * Permission Middleware
 * Add this at the top of every protected page
 * 
 * Usage:
 * require_once 'includes/permission_middleware.php';
 * requirePermission('orders', 'view'); // or 'edit', 'create', 'delete'
 */

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit();
}

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/PermissionManager.php';

// Initialize permission manager
$permissionManager = new PermissionManager($db, $_SESSION['user_id']);

/**
 * Require permission function
 */
function requirePermission($module_name, $action = 'view') {
    global $permissionManager;
    $permissionManager->requirePermission($module_name, $action);
}

/**
 * Check permission function
 */
function hasPermission($module_name, $action = 'view') {
    global $permissionManager;
    return $permissionManager->hasPermission($module_name, $action);
}

/**
 * Get module permissions
 */
function getModulePermissions($module_name) {
    global $permissionManager;
    return $permissionManager->getModulePermissions($module_name);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    global $permissionManager;
    return $permissionManager->isAdmin();
}
