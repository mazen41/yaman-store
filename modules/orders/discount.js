/**
 * Discount calculation functions for the order system
 */

// Discount rules (should match the database rules)
const discountRules = [
    { minAmount: 0, maxAmount: 99.99, discountType: 'percentage', discountValue: 0, requiresApproval: false },
    { minAmount: 100, maxAmount: 499.99, discountType: 'percentage', discountValue: 10, requiresApproval: false },
    { minAmount: 500, maxAmount: 999.99, discountType: 'percentage', discountValue: 11, requiresApproval: false },
    { minAmount: 1000, maxAmount: null, discountType: 'percentage', discountValue: 12, requiresApproval: true }
];

/**
 * Get applicable discount rule based on order amount
 * 
 * @param {number} orderAmount Total order amount
 * @return {object|null} Discount rule or null if no rule applies
 */
function getApplicableDiscountRule(orderAmount) {
    for (let i = discountRules.length - 1; i >= 0; i--) {
        const rule = discountRules[i];
        if (orderAmount >= rule.minAmount && 
            (rule.maxAmount === null || orderAmount <= rule.maxAmount)) {
            return rule;
        }
    }
    return null;
}

/**
 * Calculate discount amount based on rule and order amount
 * 
 * @param {object} discountRule Discount rule
 * @param {number} orderAmount Total order amount
 * @return {object} Discount details (type, value, amount, requires_approval)
 */
function calculateDiscount(discountRule, orderAmount) {
    const result = {
        discountType: null,
        discountValue: 0,
        discountAmount: 0,
        requiresApproval: false
    };
    
    if (!discountRule) {
        return result;
    }
    
    result.discountType = discountRule.discountType;
    result.discountValue = discountRule.discountValue;
    result.requiresApproval = discountRule.requiresApproval;
    
    if (discountRule.discountType === 'percentage') {
        result.discountAmount = parseFloat((orderAmount * (discountRule.discountValue / 100)).toFixed(2));
    } else {
        result.discountAmount = Math.min(discountRule.discountValue, orderAmount);
    }
    
    return result;
}

/**
 * Format discount for display
 * 
 * @param {string} type Discount type ('percentage' or 'fixed')
 * @param {number} value Discount value
 * @return {string} Formatted discount
 */
function formatDiscount(type, value) {
    if (type === 'percentage') {
        return value + '%';
    } else {
        return value.toFixed(2) + ' ريال';
    }
}

/**
 * Calculate manual discount
 * 
 * @param {string} type Discount type ('percentage' or 'fixed')
 * @param {number} value Discount value
 * @param {number} orderAmount Total order amount
 * @return {object} Discount details
 */
function calculateManualDiscount(type, value, orderAmount) {
    const result = {
        discountType: type,
        discountValue: value,
        discountAmount: 0,
        requiresApproval: false
    };
    
    if (!type || value <= 0) {
        return result;
    }
    
    if (type === 'percentage') {
        result.discountAmount = parseFloat((orderAmount * (value / 100)).toFixed(2));
        // Require approval if percentage is higher than 12%
        result.requiresApproval = value > 12;
    } else {
        result.discountAmount = Math.min(value, orderAmount);
        // Require approval if fixed amount is higher than 12% of order amount
        result.requiresApproval = value > (orderAmount * 0.12);
    }
    
    return result;
}
