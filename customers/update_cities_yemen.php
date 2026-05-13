<?php
/**
 * Update Cities Table with Yemen Governorates
 * This script replaces Saudi cities with Yemen governorates
 */

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access. Please login first.');
}

require_once '../../config/database.php';

echo "<!DOCTYPE html>
<html dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <title>تحديث المحافظات اليمنية</title>
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
        .info {
            background: #dbeafe;
            border: 2px solid #3b82f6;
            color: #1e40af;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .city-list {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 20px 0;
        }
        .city-item {
            background: #f9fafb;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
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
        .btn-danger {
            background: #ef4444;
        }
        .btn-danger:hover {
            background: #dc2626;
        }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>🇾🇪 تحديث المحافظات اليمنية</h1>";

try {
    // Check if cities table exists
    $tableCheck = $db->query("SHOW TABLES LIKE 'cities'");
    
    if ($tableCheck->rowCount() == 0) {
        // Create cities table if it doesn't exist
        echo "<div class='info'>⚠️ جدول المدن غير موجود. سيتم إنشاؤه الآن...</div>";
        
        $db->exec("
            CREATE TABLE `cities` (
                `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "<div class='success'>✅ تم إنشاء جدول المدن بنجاح</div>";
    }
    
    // Delete all existing cities (Saudi cities)
    echo "<div class='info'>🗑️ حذف المدن السعودية القديمة...</div>";
    $db->exec("DELETE FROM cities");
    echo "<div class='success'>✅ تم حذف جميع المدن القديمة</div>";
    
    // Insert Yemen governorates
    echo "<div class='info'>📍 إضافة المحافظات اليمنية...</div>";
    
    $yemenGovernorates = [
        'صنعاء',           // Sana'a (Capital)
        'عدن',             // Aden
        'تعز',             // Taiz
        'الحديدة',         // Hodeidah
        'إب',              // Ibb
        'ذمار',            // Dhamar
        'المكلا',          // Al Mukalla
        'حضرموت',          // Hadramaut
        'صعدة',            // Saada
        'عمران',           // Amran
        'مأرب',            // Marib
        'لحج',             // Lahij
        'أبين',            // Abyan
        'شبوة',            // Shabwah
        'المهرة',          // Al Mahrah
        'حجة',             // Hajjah
        'الجوف',           // Al Jawf
        'البيضاء',         // Al Bayda
        'ريمة',            // Raymah
        'الضالع',          // Ad Dali
        'سقطرى'            // Socotra (Bonus!)
    ];
    
    $stmt = $db->prepare("INSERT INTO cities (name, is_active) VALUES (?, 1)");
    
    $count = 0;
    foreach ($yemenGovernorates as $governorate) {
        $stmt->execute([$governorate]);
        $count++;
    }
    
    echo "<div class='success'>✅ تم إضافة {$count} محافظة يمنية بنجاح!</div>";
    
    // Display all cities
    echo "<h2>📋 المحافظات اليمنية المضافة:</h2>";
    echo "<div class='city-list'>";
    
    $cities = $db->query("SELECT * FROM cities ORDER BY name")->fetchAll();
    foreach ($cities as $city) {
        echo "<div class='city-item'>✓ " . htmlspecialchars($city['name']) . "</div>";
    }
    
    echo "</div>";
    
    echo "<div class='success' style='margin-top: 30px;'>
        <h3>🎉 تم التحديث بنجاح!</h3>
        <p>تم استبدال جميع المدن السعودية بالمحافظات اليمنية الـ 21.</p>
        <p>يمكنك الآن استخدام نموذج إضافة العملاء مع المحافظات اليمنية.</p>
    </div>";
    
    echo "<div style='margin-top: 20px;'>
        <a href='add.php' class='btn'>الذهاب إلى نموذج إضافة عميل</a>
        <a href='index.php' class='btn'>العودة إلى قائمة العملاء</a>
    </div>";
    
} catch (PDOException $e) {
    echo "<div class='error'>
        <h3>❌ حدث خطأ:</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
    </div>";
    
    echo "<div class='info'>
        <h4>تأكد من:</h4>
        <ul>
            <li>تشغيل خادم MySQL</li>
            <li>صحة بيانات الاتصال بقاعدة البيانات</li>
            <li>وجود صلاحيات الكتابة على قاعدة البيانات</li>
        </ul>
    </div>";
}

echo "</div>
</body>
</html>";
?>
