<?php
/**
 * Update Offices Table with Yemen Transport Offices
 * This script replaces existing offices with Yemen transport companies
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
    <title>تحديث مكاتب النقل اليمنية</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f3f4f6;
            padding: 40px;
            direction: rtl;
        }
        .container {
            max-width: 900px;
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
        .office-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .office-item {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            transition: all 0.2s;
        }
        .office-item:hover {
            border-color: #3b82f6;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
        }
        .office-name {
            font-weight: 700;
            color: #1f2937;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        .office-location {
            color: #6b7280;
            font-size: 0.9rem;
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

echo "<h1>🚚 تحديث مكاتب النقل اليمنية</h1>";

try {
    // Check if offices table exists
    $tableCheck = $db->query("SHOW TABLES LIKE 'offices'");
    
    if ($tableCheck->rowCount() == 0) {
        // Create offices table if it doesn't exist
        echo "<div class='info'>⚠️ جدول مكاتب النقل غير موجود. سيتم إنشاؤه الآن...</div>";
        
        $db->exec("
            CREATE TABLE `offices` (
                `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `location` VARCHAR(200) DEFAULT NULL,
                `phone` VARCHAR(20) DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "<div class='success'>✅ تم إنشاء جدول مكاتب النقل بنجاح</div>";
    } else {
        // Check if location column exists, add it if missing
        $columnsCheck = $db->query("SHOW COLUMNS FROM offices LIKE 'location'");
        if ($columnsCheck->rowCount() == 0) {
            echo "<div class='info'>⚠️ إضافة عمود الموقع إلى جدول المكاتب...</div>";
            $db->exec("ALTER TABLE offices ADD COLUMN location VARCHAR(200) DEFAULT NULL AFTER name");
            echo "<div class='success'>✅ تم إضافة عمود الموقع بنجاح</div>";
        }
        
        // Check if phone column exists, add it if missing
        $phoneCheck = $db->query("SHOW COLUMNS FROM offices LIKE 'phone'");
        if ($phoneCheck->rowCount() == 0) {
            echo "<div class='info'>⚠️ إضافة عمود الهاتف إلى جدول المكاتب...</div>";
            $db->exec("ALTER TABLE offices ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER location");
            echo "<div class='success'>✅ تم إضافة عمود الهاتف بنجاح</div>";
        }
    }
    
    // Delete all existing offices
    echo "<div class='info'>🗑️ حذف مكاتب النقل القديمة...</div>";
    $db->exec("DELETE FROM offices");
    echo "<div class='success'>✅ تم حذف جميع المكاتب القديمة</div>";
    
    // Insert Yemen transport offices
    echo "<div class='info'>📍 إضافة مكاتب النقل اليمنية...</div>";
    
    $yemenOffices = [
        // Major Transport Companies
        ['name' => 'مكتب النقل السريع - صنعاء', 'location' => 'صنعاء - شارع الزبيري'],
        ['name' => 'مكتب النقل السريع - عدن', 'location' => 'عدن - كريتر'],
        ['name' => 'مكتب النقل السريع - تعز', 'location' => 'تعز - شارع جمال'],
        
        ['name' => 'شركة النقل الوطني - صنعاء', 'location' => 'صنعاء - الحصبة'],
        ['name' => 'شركة النقل الوطني - عدن', 'location' => 'عدن - المعلا'],
        ['name' => 'شركة النقل الوطني - الحديدة', 'location' => 'الحديدة - شارع الكورنيش'],
        
        ['name' => 'مكتب اليمن للنقل - صنعاء', 'location' => 'صنعاء - شارع حدة'],
        ['name' => 'مكتب اليمن للنقل - إب', 'location' => 'إب - السوق المركزي'],
        ['name' => 'مكتب اليمن للنقل - ذمار', 'location' => 'ذمار - شارع الستين'],
        
        ['name' => 'مكتب الأمانة للنقل - صنعاء', 'location' => 'صنعاء - ميدان التحرير'],
        ['name' => 'مكتب الأمانة للنقل - عدن', 'location' => 'عدن - الشيخ عثمان'],
        
        ['name' => 'شركة الساحل للنقل - الحديدة', 'location' => 'الحديدة - باب المندب'],
        ['name' => 'شركة الساحل للنقل - المكلا', 'location' => 'المكلا - الميناء'],
        
        ['name' => 'مكتب حضرموت للنقل - المكلا', 'location' => 'المكلا - شارع الغرفة التجارية'],
        ['name' => 'مكتب حضرموت للنقل - سيئون', 'location' => 'حضرموت - سيئون'],
        
        ['name' => 'مكتب الشمال للنقل - صعدة', 'location' => 'صعدة - السوق القديم'],
        ['name' => 'مكتب الشمال للنقل - عمران', 'location' => 'عمران - شارع الحرية'],
        
        ['name' => 'شركة الجنوب للنقل - لحج', 'location' => 'لحج - الحوطة'],
        ['name' => 'شركة الجنوب للنقل - أبين', 'location' => 'أبين - زنجبار'],
        
        ['name' => 'مكتب الشرق للنقل - مأرب', 'location' => 'مأرب - المدينة'],
        ['name' => 'مكتب الشرق للنقل - شبوة', 'location' => 'شبوة - عتق'],
        
        ['name' => 'مكتب الوسط للنقل - تعز', 'location' => 'تعز - صالة'],
        ['name' => 'مكتب الوسط للنقل - البيضاء', 'location' => 'البيضاء - رداع'],
        
        ['name' => 'شركة الساحل الغربي - الحديدة', 'location' => 'الحديدة - حيس'],
        ['name' => 'شركة الساحل الغربي - حجة', 'location' => 'حجة - ميدي'],
        
        ['name' => 'مكتب الضالع للنقل', 'location' => 'الضالع - قعطبة'],
        ['name' => 'مكتب ريمة للنقل', 'location' => 'ريمة - الجبين'],
        ['name' => 'مكتب الجوف للنقل', 'location' => 'الجوف - الحزم'],
        ['name' => 'مكتب المهرة للنقل', 'location' => 'المهرة - الغيضة'],
        ['name' => 'مكتب سقطرى للنقل', 'location' => 'سقطرى - حديبو']
    ];
    
    $stmt = $db->prepare("INSERT INTO offices (name, location, is_active) VALUES (?, ?, 1)");
    
    $count = 0;
    foreach ($yemenOffices as $office) {
        $stmt->execute([$office['name'], $office['location']]);
        $count++;
    }
    
    echo "<div class='success'>✅ تم إضافة {$count} مكتب نقل يمني بنجاح!</div>";
    
    // Display all offices
    echo "<h2>📋 مكاتب النقل اليمنية المضافة:</h2>";
    echo "<div class='office-list'>";
    
    $offices = $db->query("SELECT * FROM offices ORDER BY name")->fetchAll();
    foreach ($offices as $office) {
        echo "<div class='office-item'>
            <div class='office-name'>🚚 " . htmlspecialchars($office['name']) . "</div>
            <div class='office-location'>📍 " . htmlspecialchars($office['location']) . "</div>
        </div>";
    }
    
    echo "</div>";
    
    echo "<div class='success' style='margin-top: 30px;'>
        <h3>🎉 تم التحديث بنجاح!</h3>
        <p>تم إضافة {$count} مكتب نقل يمني يغطي جميع المحافظات.</p>
        <p>يمكنك الآن اختيار مكتب النقل المناسب عند إضافة عميل جديد.</p>
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
