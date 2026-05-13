<?php
session_start();
require_once "../../config/database.php";
require_once "../../includes/CouponValidator.php";

header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);
$coupon_code = $input["coupon_code"] ?? "";
$order_total = floatval($input["order_total"] ?? 0);
$customer_id = intval($input["customer_id"] ?? 0) ?: null;

$validator = new CouponValidator($db);
$result = $validator->validateCoupon($coupon_code, $order_total, $customer_id);

echo json_encode($result);
?>