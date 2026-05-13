# 🚚 Update Transport Offices to Yemen - Quick Guide

## 🎯 Purpose
This script replaces existing transport offices with Yemen-based transport companies covering all governorates.

## 📋 What It Does

1. **Checks** if `offices` table exists (creates it if missing)
2. **Deletes** all existing offices
3. **Inserts** 30 Yemen transport offices
4. **Displays** confirmation with full list

## 🚀 How to Run

### **Step 1: Access the Script**
```
http://localhost/yassin-admin-system/modules/customers/update_offices_yemen.php
```

### **Step 2: Wait for Completion**
The script will automatically:
- Delete old offices
- Insert Yemen transport offices
- Show success message with grid display

### **Step 3: Verify**
You'll see all 30 transport offices with their locations.

## 🚚 Yemen Transport Offices Added (30 Total)

### **Major Transport Companies:**

#### **النقل السريع (Express Transport)**
1. مكتب النقل السريع - صنعاء (شارع الزبيري)
2. مكتب النقل السريع - عدن (كريتر)
3. مكتب النقل السريع - تعز (شارع جمال)

#### **النقل الوطني (National Transport)**
4. شركة النقل الوطني - صنعاء (الحصبة)
5. شركة النقل الوطني - عدن (المعلا)
6. شركة النقل الوطني - الحديدة (الكورنيش)

#### **اليمن للنقل (Yemen Transport)**
7. مكتب اليمن للنقل - صنعاء (حدة)
8. مكتب اليمن للنقل - إب (السوق المركزي)
9. مكتب اليمن للنقل - ذمار (شارع الستين)

#### **الأمانة للنقل (Al-Amanah Transport)**
10. مكتب الأمانة للنقل - صنعاء (ميدان التحرير)
11. مكتب الأمانة للنقل - عدن (الشيخ عثمان)

#### **الساحل للنقل (Coastal Transport)**
12. شركة الساحل للنقل - الحديدة (باب المندب)
13. شركة الساحل للنقل - المكلا (الميناء)

#### **حضرموت للنقل (Hadramaut Transport)**
14. مكتب حضرموت للنقل - المكلا (الغرفة التجارية)
15. مكتب حضرموت للنقل - سيئون

#### **الشمال للنقل (North Transport)**
16. مكتب الشمال للنقل - صعدة (السوق القديم)
17. مكتب الشمال للنقل - عمران (شارع الحرية)

#### **الجنوب للنقل (South Transport)**
18. شركة الجنوب للنقل - لحج (الحوطة)
19. شركة الجنوب للنقل - أبين (زنجبار)

#### **الشرق للنقل (East Transport)**
20. مكتب الشرق للنقل - مأرب (المدينة)
21. مكتب الشرق للنقل - شبوة (عتق)

#### **الوسط للنقل (Central Transport)**
22. مكتب الوسط للنقل - تعز (صالة)
23. مكتب الوسط للنقل - البيضاء (رداع)

#### **الساحل الغربي (West Coast)**
24. شركة الساحل الغربي - الحديدة (حيس)
25. شركة الساحل الغربي - حجة (ميدي)

#### **Regional Offices:**
26. مكتب الضالع للنقل (قعطبة)
27. مكتب ريمة للنقل (الجبين)
28. مكتب الجوف للنقل (الحزم)
29. مكتب المهرة للنقل (الغيضة)
30. مكتب سقطرى للنقل (حديبو)

## 🗺️ Coverage Map

### **Capital Region:**
- صنعاء: 4 offices (النقل السريع، الوطني، اليمن، الأمانة)

### **Southern Region:**
- عدن: 3 offices
- لحج، أبين: 2 offices

### **Western Coast:**
- الحديدة: 3 offices
- حجة: 1 office

### **Central Highlands:**
- تعز: 3 offices
- إب، ذمار، البيضاء: 3 offices

### **Eastern Region:**
- حضرموت: 2 offices (المكلا، سيئون)
- مأرب، شبوة: 2 offices

### **Northern Region:**
- صعدة، عمران: 2 offices

### **Other Regions:**
- الضالع، ريمة، الجوف، المهرة، سقطرى: 5 offices

## 📊 Database Structure

```sql
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
```

## ✅ Success Indicators

You'll see:
- ✅ "تم حذف جميع المكاتب القديمة" (Old offices deleted)
- ✅ "تم إضافة 30 مكتب نقل يمني بنجاح" (30 offices added)
- ✅ Grid display with office names and locations
- ✅ Links to customer forms

## 🎯 Usage in Customer Form

After running this script, when adding a customer:

1. Go to **مكتب النقل** field
2. Click dropdown
3. See all 30 Yemen transport offices
4. Select appropriate office based on customer location

**Example:**
- Customer in صنعاء → Choose "مكتب النقل السريع - صنعاء"
- Customer in عدن → Choose "شركة النقل الوطني - عدن"
- Customer in المكلا → Choose "مكتب حضرموت للنقل - المكلا"

## 🔒 Security

- ✅ Requires user login
- ✅ Uses prepared statements
- ✅ SQL injection safe
- ✅ Session validation

## 🔄 Re-running

Safe to run multiple times:
- Deletes and re-inserts offices
- No duplicates
- Idempotent operation

## 📝 Manual SQL Alternative

```sql
-- Delete old offices
DELETE FROM offices;

-- Insert Yemen offices (sample)
INSERT INTO offices (name, location, is_active) VALUES
('مكتب النقل السريع - صنعاء', 'صنعاء - شارع الزبيري', 1),
('مكتب النقل السريع - عدن', 'عدن - كريتر', 1),
('شركة النقل الوطني - صنعاء', 'صنعاء - الحصبة', 1);
-- ... (add all 30 offices)
```

## 🎨 Visual Display

The script shows offices in a beautiful grid:

```
┌─────────────────────────────────┐  ┌─────────────────────────────────┐
│ 🚚 مكتب النقل السريع - صنعاء   │  │ 🚚 مكتب النقل السريع - عدن     │
│ 📍 صنعاء - شارع الزبيري        │  │ 📍 عدن - كريتر                  │
└─────────────────────────────────┘  └─────────────────────────────────┘
```

## 🐛 Troubleshooting

### **Error: "Unauthorized access"**
**Solution:** Login first

### **Error: Database connection failed**
**Solution:** Check MySQL and credentials

### **Error: Table creation failed**
**Solution:** Verify database permissions

## 📞 Next Steps

1. **Run the script** to update offices
2. **Test the form** at `modules/customers/add.php`
3. **Verify dropdown** shows Yemen offices
4. **Add test customer** with office selection

---

**File:** `update_offices_yemen.php`  
**Location:** `modules/customers/`  
**Offices Added:** 30  
**Coverage:** All Yemen governorates  
**Status:** ✅ Ready to run
