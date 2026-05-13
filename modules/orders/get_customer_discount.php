<?php
/**
 * Get Customer Discount Based on Type and Amount
 * Returns discount percentage and tier information
 */

header('Content-Type: application/json');

require_once '../../config/database.php';

$customer_type_id = $_GET['customer_type_id'] ?? 0;
$amount = floatval($_GET['amount'] ?? 0);

$response = [
    'success' => false,
    'discount_percentage' => 0,
    'tier_info' => 'لا يوجد خصم'
];

try {
    // Debug logging
    error_log("=== GET CUSTOMER DISCOUNT DEBUG ===");
    error_log("Customer Type ID: " . $customer_type_id);
    error_log("Amount: " . $amount);
    
    if (empty($customer_type_id) || $amount <= 0) {
        error_log("Invalid parameters - returning no discount");
        echo json_encode($response);
        exit;
    }

    // Check if customer type itself is active
    $typeStmt = $db->prepare("SELECT is_active FROM customer_types WHERE id = ?");
    $typeStmt->execute([$customer_type_id]);
    $type = $typeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$type || (isset($type['is_active']) && (int)$type['is_active'] === 0)) {
        error_log("Customer type is inactive or not found - returning no discount");
        echo json_encode($response);
        exit;
    }

    // Get customer type discount tiers (only for active types)
    $stmt = $db->prepare("
        SELECT 
            min_amount,
            max_amount,
            discount_percentage
        FROM customer_type_discount_tiers
        WHERE customer_type_id = ?
        ORDER BY min_amount ASC
    ");
    $stmt->execute([$customer_type_id]);
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Tiers found: " . count($tiers));
    error_log("Tiers data: " . print_r($tiers, true));

    if (empty($tiers)) {
        error_log("No tiers found for customer type");
        echo json_encode($response);
        exit;
    }

    // Find applicable tier (check from highest to lowest)
    $applicable_tier = null;
    
    // Sort tiers by min_amount descending to check highest tier first
    usort($tiers, function($a, $b) {
        return floatval($b['min_amount']) - floatval($a['min_amount']);
    });
    
    foreach ($tiers as $tier) {
        $min = floatval($tier['min_amount']);
        $max = $tier['max_amount']; // Keep as is, can be NULL
        
        // Check if amount falls within this tier
        // If max is NULL or 0, it means unlimited (no upper limit)
        if ($amount >= $min) {
            if ($max === null || $max === '' || $max == 0 || $amount <= floatval($max)) {
                $applicable_tier = $tier;
                break;
            }
        }
    }

    if ($applicable_tier) {
        $discount = floatval($applicable_tier['discount_percentage']);
        $min = number_format($applicable_tier['min_amount'], 2);
        $max_val = $applicable_tier['max_amount'];
        $max = ($max_val !== null && $max_val !== '' && $max_val > 0) ? number_format($max_val, 2) : '∞';
        
        error_log("Applicable tier found - Discount: {$discount}%");
        
        $response = [
            'success' => true,
            'discount_percentage' => $discount,
            'tier_info' => "المستوى {$discount}% (من {$min} إلى {$max} ر.س)"
        ];
    } else {
        error_log("No applicable tier found for amount: " . $amount);
    }

} catch (PDOException $e) {
    error_log("Error fetching customer discount: " . $e->getMessage());
    $response['error'] = $e->getMessage();
}

error_log("Final response: " . json_encode($response));
echo json_encode($response);
