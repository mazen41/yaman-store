<?php
/**
 * Discount calculation functions for the order system
 */

/**
 * Get applicable discount rule based on order amount
 * 
 * @param PDO $db Database connection
 * @param float $orderAmount Total order amount
 * @return array|null Discount rule or null if no rule applies
 */
function getApplicableDiscountRule($db, $orderAmount) {
    try {
        $stmt = $db->prepare("
            SELECT * FROM discount_rules 
            WHERE is_active = 1 
            AND min_amount <= ? 
            AND (max_amount IS NULL OR max_amount >= ?)
            ORDER BY min_amount DESC 
            LIMIT 1
        ");
        $stmt->execute([$orderAmount, $orderAmount]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If table doesn't exist or other error
        return null;
    }
}

/**
 * Calculate discount amount based on rule and order amount
 * 
 * @param array $discountRule Discount rule
 * @param float $orderAmount Total order amount
 * @return array Discount details (value, amount, requires_approval)
 */
function calculateDiscount($discountRule, $orderAmount) {
    $result = [
        'discount_type' => null,
        'discount_value' => 0,
        'discount_amount' => 0,
        'requires_approval' => false
    ];
    
    if (!$discountRule) {
        return $result;
    }
    
    $result['discount_type'] = $discountRule['discount_type'];
    $result['discount_value'] = $discountRule['discount_value'];
    $result['requires_approval'] = (bool)$discountRule['requires_approval'];
    
    if ($discountRule['discount_type'] === 'percentage') {
        $result['discount_amount'] = round($orderAmount * ($discountRule['discount_value'] / 100), 2);
    } else {
        $result['discount_amount'] = min($discountRule['discount_value'], $orderAmount);
    }
    
    return $result;
}

/**
 * Format discount for display
 * 
 * @param string $type Discount type ('percentage' or 'fixed')
 * @param float $value Discount value
 * @return string Formatted discount
 */
function formatDiscount($type, $value) {
    if ($type === 'percentage') {
        return $value . '%';
    } else {
        return number_format($value, 2) . ' ريال';
    }
}

/**
 * Get all active discount rules
 * 
 * @param PDO $db Database connection
 * @return array List of discount rules
 */
function getAllDiscountRules($db) {
    try {
        $stmt = $db->prepare("SELECT * FROM discount_rules WHERE is_active = 1 ORDER BY min_amount");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
?>
