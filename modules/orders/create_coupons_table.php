<?php
/**
 * Create Coupons Table
 * Run this once to create the coupons system
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access. Please login first.');
}

require_once '../../config/database.php';

echo "<!DOCTYPE html>
<html dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <title>إنشاء جدول الكوبونات</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f3f4f6;
            padding: 40px;
            direction: rtl;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1f2937;
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 15px;
        }
        .success {
            background: #d1fae5;
            border: 2px solid #C7A46D;
            color: #065f46;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .error {
            background: #fee2e2;
            border: 2px solid #ef4444;
            color: #991b1b;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .btn {
            background: #3b82f6;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>🎟️ إنشاء نظام الكوبونات</h1>";

try {
    // Create coupons table
    echo "<div class='info'>📋 إنشاء جدول الكوبونات...</div>";
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS coupons (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            discount_type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
            discount_value DECIMAL(10,2) NOT NULL,
            min_order_amount DECIMAL(10,2) DEFAULT 0,
            max_discount_amount DECIMAL(10,2) DEFAULT NULL,
            usage_limit INT(11) DEFAULT NULL,
            used_count INT(11) DEFAULT 0,
            valid_from DATETIME DEFAULT NULL,
            valid_until DATETIME DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            description TEXT DEFAULT NULL,
            created_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_code (code),
            INDEX idx_active (is_active),
            INDEX idx_valid (valid_from, valid_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "<div class='success'>✅ تم إنشاء جدول الكوبونات بنجاح</div>";
    
    // Create coupon usage tracking table
    echo "<div class='info'>📋 إنشاء جدول تتبع استخدام الكوبونات...</div>";
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS coupon_usage (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            coupon_id INT(11) NOT NULL,
            order_id INT(11) NOT NULL,
            customer_id INT(11) NOT NULL,
            discount_amount DECIMAL(10,2) NOT NULL,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
            INDEX idx_coupon (coupon_id),
            INDEX idx_order (order_id),
            INDEX idx_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "<div class='success'>✅ تم إنشاء جدول تتبع الاستخدام بنجاح</div>";
    
    // Insert sample coupons
    echo "<div class='info'>🎁 إضافة كوبونات تجريبية...</div>";
    
    $sampleCoupons = [
        [
            'code' => 'WELCOME10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'min_order_amount' => 100,
            'max_discount_amount' => 50,
            'description' => 'خصم 10% للعملاء الجدد'
        ],
        [
            'code' => 'SAVE20',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'min_order_amount' => 500,
            'max_discount_amount' => 100,
            'description' => 'خصم 20% على الطلبات فوق 500 ريال'
        ],
        [
            'code' => 'FLAT50',
            'discount_type' => 'fixed',
            'discount_value' => 50,
            'min_order_amount' => 200,
            'description' => 'خصم 50 ريال على الطلبات فوق 200 ريال'
        ],
        [
            'code' => 'VIP30',
            'discount_type' => 'percentage',
            'discount_value' => 30,
            'min_order_amount' => 1000,
            'max_discount_amount' => 300,
            'usage_limit' => 100,
            'description' => 'خصم VIP 30% - محدود'
        ],
        [
            'code' => 'FREESHIP',
            'discount_type' => 'fixed',
            'discount_value' => 25,
            'min_order_amount' => 150,
            'description' => 'شحن مجاني (خصم 25 ريال)'
        ]
    ];
    
    $stmt = $db->prepare("
        INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, max_discount_amount, usage_limit, description, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $count = 0;
    foreach ($sampleCoupons as $coupon) {
        try {
            $stmt->execute([
                $coupon['code'],
                $coupon['discount_type'],
                $coupon['discount_value'],
                $coupon['min_order_amount'],
                $coupon['max_discount_amount'] ?? null,
                $coupon['usage_limit'] ?? null,
                $coupon['description'],
                $_SESSION['user_id']
            ]);
            $count++;
        } catch (PDOException $e) {
            // Skip if coupon already exists
        }
    }
    
    echo "<div class='success'>✅ تم إضافة {$count} كوبون تجريبي</div>";
    
    // Display sample coupons
    echo "<h2>🎟️ الكوبونات المتاحة:</h2>";
    echo "<table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>";
    echo "<tr style='background: #f3f4f6; font-weight: bold;'>
            <td style='padding: 10px; border: 1px solid #ddd;'>الكود</td>
            <td style='padding: 10px; border: 1px solid #ddd;'>النوع</td>
            <td style='padding: 10px; border: 1px solid #ddd;'>القيمة</td>
            <td style='padding: 10px; border: 1px solid #ddd;'>الوصف</td>
          </tr>";
    
    foreach ($sampleCoupons as $coupon) {
        $type = $coupon['discount_type'] === 'percentage' ? 'نسبة مئوية' : 'مبلغ ثابت';
        $value = $coupon['discount_type'] === 'percentage' 
            ? $coupon['discount_value'] . '%' 
            : $coupon['discount_value'] . ' ريال';
        
        echo "<tr>
                <td style='padding: 10px; border: 1px solid #ddd; font-weight: bold; color: #3b82f6;'>{$coupon['code']}</td>
                <td style='padding: 10px; border: 1px solid #ddd;'>{$type}</td>
                <td style='padding: 10px; border: 1px solid #ddd;'>{$value}</td>
                <td style='padding: 10px; border: 1px solid #ddd;'>{$coupon['description']}</td>
              </tr>";
    }
    echo "</table>";
    
    echo "<div class='success' style='margin-top: 30px;'>
        <h3>🎉 تم إعداد نظام الكوبونات بنجاح!</h3>
        <p>يمكنك الآن استخدام الكوبونات في صفحة إنشاء الطلبات.</p>
    </div>";
    
    echo "<div style='margin-top: 20px;'>
        <a href='create.php' class='btn'>الذهاب إلى إنشاء طلب</a>
        <a href='manage_coupons.php' class='btn'>إدارة الكوبونات</a>
    </div>";
    
} catch (PDOException $e) {
    echo "<div class='error'>
        <h3>❌ حدث خطأ:</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
    </div>";
}

echo "</div>
</body>
</html>";
?>
