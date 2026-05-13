<?php
/**
 * Purchase System Helper Functions
 */

/**
 * Generate unique basket code
 */
function generateBasketCode($db) {
    $year = date('Y');
    $stmt = $db->query("SELECT basket_code FROM purchase_baskets WHERE basket_code LIKE 'BASKET-$year-%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $num = intval(substr($last, -4)) + 1;
    } else {
        $num = 1;
    }
    
    return 'BASKET-' . $year . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate unique group code
 */
function generateGroupCode($db) {
    $year = date('Y');
    $stmt = $db->query("SELECT group_code FROM purchase_groups WHERE group_code LIKE 'GROUP-$year-%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $num = intval(substr($last, -4)) + 1;
    } else {
        $num = 1;
    }
    
    return 'GROUP-' . $year . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate unique supplier code
 */
function generateSupplierCode($db) {
    $stmt = $db->query("SELECT supplier_code FROM suppliers WHERE supplier_code LIKE 'SUP-%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $num = intval(substr($last, 4)) + 1;
    } else {
        $num = 1;
    }
    
    return 'SUP-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

/**
 * Update basket totals
 */
function updateBasketTotals($db, $basket_id) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_orders, 
               COALESCE(SUM(total_price), 0) as total_amount
        FROM basket_items
        WHERE basket_id = ?
    ");
    $stmt->execute([$basket_id]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $update = $db->prepare("
        UPDATE purchase_baskets 
        SET total_orders = ?, total_amount = ?
        WHERE id = ?
    ");
    $update->execute([
        $totals['total_orders'],
        $totals['total_amount'],
        $basket_id
    ]);
}

/**
 * Update group totals
 */
function updateGroupTotals($db, $group_id) {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT gb.basket_id) as total_baskets,
               COALESCE(SUM(pb.total_orders), 0) as total_orders,
               COALESCE(SUM(pb.total_amount), 0) as total_amount
        FROM group_baskets gb
        LEFT JOIN purchase_baskets pb ON gb.basket_id = pb.id
        WHERE gb.group_id = ?
    ");
    $stmt->execute([$group_id]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $update = $db->prepare("
        UPDATE purchase_groups 
        SET total_baskets = ?, total_orders = ?, total_amount = ?
        WHERE id = ?
    ");
    $update->execute([
        $totals['total_baskets'],
        $totals['total_orders'],
        $totals['total_amount'],
        $group_id
    ]);
}
?>