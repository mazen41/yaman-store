<?php
class CouponValidator {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function validateCoupon($coupon_code, $order_total, $customer_id = null) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM coupons WHERE coupon_code = ? AND is_active = 1");
            $stmt->execute([$coupon_code]);
            $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$coupon) {
                return ["valid" => false, "message" => "كود الكوبون غير صحيح"];
            }
            
            $today = date("Y-m-d");
            if ($today < $coupon["start_date"] || $today > $coupon["end_date"]) {
                return ["valid" => false, "message" => "الكوبون غير صالح للاستخدام"];
            }
            
            if ($order_total < $coupon["min_order_amount"]) {
                return ["valid" => false, "message" => "الحد الأدنى للطلب " . $coupon["min_order_amount"] . " ريال"];
            }
            
            $discount = $this->calculateDiscount($coupon, $order_total);
            
            return [
                "valid" => true,
                "coupon" => $coupon,
                "discount_amount" => $discount,
                "message" => "تم تطبيق الكوبون بنجاح"
            ];
            
        } catch (Exception $e) {
            return ["valid" => false, "message" => "خطأ في التحقق من الكوبون"];
        }
    }
    
    private function calculateDiscount($coupon, $order_total) {
        if ($coupon["discount_type"] == "percentage") {
            $discount = ($order_total * $coupon["discount_value"]) / 100;
            if ($coupon["max_discount_amount"] && $discount > $coupon["max_discount_amount"]) {
                $discount = $coupon["max_discount_amount"];
            }
        } else {
            $discount = $coupon["discount_value"];
        }
        return min($discount, $order_total);
    }
}
?>