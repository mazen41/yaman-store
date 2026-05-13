<?php
// customer_portal/check_coupon.php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$response = [
    'success'                            => false,
    'message'                            => 'Invalid request.',
    'coupon_discount_amount'             => 0,
    'coupon_discount_percentage_display' => '',
    'coupon_id'                          => null
];

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['coupon_code'], $_GET['total_amount'], $_GET['customer_id'])
) {
    $coupon_code  = trim($_GET['coupon_code']);
    $total_amount = floatval($_GET['total_amount']);
    $customer_id  = intval($_GET['customer_id']);
    $current_date = date('Y-m-d');

    if (empty($coupon_code)) {
        $response['success'] = true;
        $response['message'] = 'No coupon entered.';
        echo json_encode($response);
        exit;
    }

    try {
        // ── 1. Fetch coupon from shop_coupons ──────────────────────────────
        $stmt = $db->prepare("
            SELECT
                id, discount_type, discount_value,
                min_order_amount, max_discount_amount,
                usage_limit, user_usage_limit,
                usage_count, start_date, end_date, is_active
            FROM shop_coupons
            WHERE coupon_code = ? AND is_active = 1
        ");
        $stmt->execute([$coupon_code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$coupon) {
            $response['message'] = 'كود الكوبون غير صالح أو غير نشط.';
            echo json_encode($response);
            exit;
        }

        // ── 2. Validate dates ──────────────────────────────────────────────
        if ($current_date < $coupon['start_date'] || $current_date > $coupon['end_date']) {
            $response['message'] = 'هذا الكوبون منتهي الصلاحية أو لم يصبح ساري المفعول بعد.';
            echo json_encode($response);
            exit;
        }

        // ── 3. Validate minimum order amount ──────────────────────────────
        if ($total_amount < floatval($coupon['min_order_amount'])) {
            $currency = $_GET['currency'] ?? '';
            $response['message'] = 'الحد الأدنى للطلب لاستخدام هذا الكوبون هو '
                . number_format($coupon['min_order_amount'], 0) . ' ' . $currency . '.';
            echo json_encode($response);
            exit;
        }

        // ── 4. Validate global usage limit (uses usage_count column) ───────
        if ($coupon['usage_limit'] !== null) {
            if (intval($coupon['usage_count']) >= intval($coupon['usage_limit'])) {
                $response['message'] = 'تم استهلاك هذا الكوبون بالكامل.';
                echo json_encode($response);
                exit;
            }
        }

        // ── 5. Validate per-customer usage limit ──────────────────────────
        if ($coupon['user_usage_limit'] !== null) {
            $userUsageStmt = $db->prepare("
                SELECT COUNT(*)
                FROM order_approvals
                WHERE customer_id = ?
                  AND coupon_code = ?
                  AND status != 'cancelled'
            ");
            $userUsageStmt->execute([$customer_id, $coupon_code]);
            $user_used_count = $userUsageStmt->fetchColumn();

            if ($user_used_count >= intval($coupon['user_usage_limit'])) {
                $response['message'] = 'لقد استخدمت هذا الكوبون الحد الأقصى من المرات المسموح بها.';
                echo json_encode($response);
                exit;
            }
        }

        // ── 6. Calculate discount ──────────────────────────────────────────
        $discount_amount  = 0;
        $discount_display = '';
        $currency         = $_GET['currency'] ?? '';

        if ($coupon['discount_type'] === 'percentage') {
            $discount_amount  = $total_amount * (floatval($coupon['discount_value']) / 100);
            $discount_display = number_format($coupon['discount_value'], 0) . '%';
        } elseif ($coupon['discount_type'] === 'fixed') {
            $discount_amount  = floatval($coupon['discount_value']);
            $discount_display = number_format($coupon['discount_value'], 0) . ' ' . $currency;
        }

        // ── 7. Apply max discount cap ──────────────────────────────────────
        if (
            $coupon['max_discount_amount'] !== null &&
            $discount_amount > floatval($coupon['max_discount_amount'])
        ) {
            $discount_amount   = floatval($coupon['max_discount_amount']);
            $discount_display .= ' (الحد الأقصى)';
        }

        // ── 8. Discount can't exceed order total ───────────────────────────
        if ($discount_amount > $total_amount) {
            $discount_amount = $total_amount;
        }

        $response['success']                            = true;
        $response['message']                            = 'تم تطبيق الكوبون بنجاح.';
        $response['coupon_discount_amount']             = round($discount_amount, 2);
        $response['coupon_discount_percentage_display'] = $discount_display;
        $response['coupon_id']                          = $coupon['id'];

    } catch (PDOException $e) {
        error_log("Coupon validation error: " . $e->getMessage());
        $response['message'] = 'حدث خطأ أثناء التحقق من الكوبون. يرجى المحاولة مرة أخرى.';
    }
}

echo json_encode($response);
?>