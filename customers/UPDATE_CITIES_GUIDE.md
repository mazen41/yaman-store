# 🇾🇪 Update Cities to Yemen Governorates - Quick Guide

## 🎯 Purpose
This script replaces all Saudi Arabian cities with Yemen governorates in the database.

## 📋 What It Does

1. **Checks** if `cities` table exists (creates it if missing)
2. **Deletes** all existing Saudi cities
3. **Inserts** 21 Yemen governorates
4. **Displays** confirmation with list of all governorates

## 🚀 How to Run

### **Step 1: Access the Script**
Open your browser and navigate to:
```
http://localhost/yassin-admin-system/modules/customers/update_cities_yemen.php
```

### **Step 2: Wait for Completion**
The script will automatically:
- Delete old cities
- Insert Yemen governorates
- Show success message

### **Step 3: Verify**
You'll see a list of all 21 Yemen governorates added.

## 🗺️ Yemen Governorates Added (21 Total)

1. **صنعاء** (Sana'a) - Capital
2. **عدن** (Aden) - Economic capital
3. **تعز** (Taiz)
4. **الحديدة** (Hodeidah)
5. **إب** (Ibb)
6. **ذمار** (Dhamar)
7. **المكلا** (Al Mukalla)
8. **حضرموت** (Hadramaut)
9. **صعدة** (Saada)
10. **عمران** (Amran)
11. **مأرب** (Marib)
12. **لحج** (Lahij)
13. **أبين** (Abyan)
14. **شبوة** (Shabwah)
15. **المهرة** (Al Mahrah)
16. **حجة** (Hajjah)
17. **الجوف** (Al Jawf)
18. **البيضاء** (Al Bayda)
19. **ريمة** (Raymah)
20. **الضالع** (Ad Dali)
21. **سقطرى** (Socotra) - Bonus island governorate!

## ⚠️ Important Notes

### **Before Running:**
- ✅ Make sure you're logged in to the system
- ✅ Backup your database (optional but recommended)
- ✅ Ensure MySQL server is running

### **After Running:**
- ✅ All customer forms will show Yemen governorates
- ✅ Old Saudi city references will be removed
- ✅ New customers will use Yemen governorates

## 🔒 Security

- Requires user login (session check)
- Uses prepared statements (SQL injection safe)
- Only accessible to logged-in users

## 📊 Database Changes

### **Table Structure:**
```sql
CREATE TABLE `cities` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### **Data Changes:**
- **Before:** 10 Saudi cities
- **After:** 21 Yemen governorates

## ✅ Success Indicators

You'll see:
- ✅ "تم حذف جميع المدن القديمة" (Old cities deleted)
- ✅ "تم إضافة 21 محافظة يمنية بنجاح" (21 governorates added)
- ✅ Grid display of all governorates
- ✅ Links to customer forms

## 🐛 Troubleshooting

### **Error: "Unauthorized access"**
**Solution:** Login to the system first

### **Error: Database connection failed**
**Solution:** 
- Check if MySQL is running
- Verify database credentials in `config/database.php`

### **Error: Table creation failed**
**Solution:**
- Check database user permissions
- Ensure user has CREATE TABLE privileges

## 🔄 Re-running the Script

Safe to run multiple times:
- Will delete and re-insert governorates
- No duplicate entries
- Idempotent operation

## 📝 Manual Alternative

If you prefer SQL:
```sql
-- Delete old cities
DELETE FROM cities;

-- Insert Yemen governorates
INSERT INTO cities (name, is_active) VALUES
('صنعاء', 1),
('عدن', 1),
('تعز', 1),
('الحديدة', 1),
('إب', 1),
('ذمار', 1),
('المكلا', 1),
('حضرموت', 1),
('صعدة', 1),
('عمران', 1),
('مأرب', 1),
('لحج', 1),
('أبين', 1),
('شبوة', 1),
('المهرة', 1),
('حجة', 1),
('الجوف', 1),
('البيضاء', 1),
('ريمة', 1),
('الضالع', 1),
('سقطرى', 1);
```

## 🎯 Next Steps

After running the script:

1. **Test the form:**
   - Go to: `modules/customers/add.php`
   - Check المحافظة dropdown
   - Verify all 21 governorates appear

2. **Add a test customer:**
   - Select a Yemen governorate
   - Fill in other fields
   - Submit and verify

3. **Check existing customers:**
   - Old customers with Saudi cities remain unchanged
   - New customers will use Yemen governorates

## 📞 Support

If you encounter issues:
1. Check browser console for errors
2. Check MySQL error logs
3. Verify database connection
4. Ensure proper permissions

---

**File:** `update_cities_yemen.php`  
**Location:** `modules/customers/`  
**Status:** ✅ Ready to run  
**Safety:** 🔒 Secure (requires login)
