# 🎟️ تعليمات إعداد نظام الكوبونات

## ⚠️ خطأ: "حدث خطأ في التحقق من الكوبون"

هذا الخطأ يعني أن نظام الكوبونات غير مفعل بعد.

## 🚀 الحل السريع:

### **الخطوة 1: تشغيل سكريبت الإعداد**

افتح المتصفح واذهب إلى:
```
http://localhost/yassin-admin-system/modules/orders/create_coupons_table.php
```

### **الخطوة 2: انتظر الإعداد**

سيقوم السكريبت بـ:
- ✅ إنشاء جدول الكوبونات
- ✅ إنشاء جدول تتبع الاستخدام
- ✅ إضافة 5 كوبونات تجريبية

### **الخطوة 3: استخدم الكوبونات**

بعد الإعداد، يمكنك استخدام هذه الكوبونات:

| الكود | النوع | القيمة | الوصف |
|------|------|--------|-------|
| **WELCOME10** | نسبة مئوية | 10% | للعملاء الجدد |
| **SAVE20** | نسبة مئوية | 20% | على طلبات +500 ريال |
| **FLAT50** | مبلغ ثابت | 50 ريال | على طلبات +200 ريال |
| **VIP30** | نسبة مئوية | 30% | VIP محدود |
| **FREESHIP** | مبلغ ثابت | 25 ريال | شحن مجاني |

---

## 📋 كيفية استخدام الكوبون:

1. أضف منتجات للطلب
2. أدخل كود الكوبون (مثال: WELCOME10)
3. اضغط "تطبيق"
4. سيظهر الخصم تلقائياً

---

## 🔧 إذا استمرت المشكلة:

### **تحقق من قاعدة البيانات:**

قم بتشغيل هذا الأمر في phpMyAdmin:

```sql
SHOW TABLES LIKE 'coupons';
```

إذا لم يظهر شيء، قم بتشغيل:

```sql
CREATE TABLE `coupons` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `discount_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
    `discount_value` DECIMAL(10,2) NOT NULL,
    `min_order_amount` DECIMAL(10,2) DEFAULT 0,
    `max_discount_amount` DECIMAL(10,2) DEFAULT NULL,
    `usage_limit` INT(11) DEFAULT NULL,
    `used_count` INT(11) DEFAULT 0,
    `valid_from` DATETIME DEFAULT NULL,
    `valid_until` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `description` TEXT DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## ✅ بعد الإعداد:

- ✅ ستعمل الكوبونات بشكل طبيعي
- ✅ يمكنك إضافة كوبونات جديدة
- ✅ يتم تتبع الاستخدام تلقائياً

---

**ملاحظة:** يجب تشغيل `create_coupons_table.php` مرة واحدة فقط!
