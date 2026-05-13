# منطق الخصم الجديد (Tiered Discount Policy)

## 📋 نظرة عامة (Overview)

تم تطبيق نظام خصم متدرج جديد يعتمد على إجمالي الفاتورة قبل الخصم. يتم تطبيق نسبة الخصم تلقائياً بناءً على المبلغ الإجمالي.

**New tiered discount system based on invoice total before discount. Discount percentage is automatically applied based on the total amount.**

---

## 💰 جدول الخصومات (Discount Tiers)

| شرط المبلغ (بالريال السعودي) | نسبة الخصم | ملاحظات |
|------------------------------|-----------|---------|
| إذا كانت الفاتورة < 100 ريال | 0% | لا يوجد خصم |
| إذا كانت الفاتورة 100-499 ريال | 10% | خصم تلقائي |
| إذا كانت الفاتورة 500-999 ريال | 11% | خصم تلقائي |
| إذا كانت الفاتورة ≥ 1,000 ريال | 12% | خصم تلقائي |

---

## 🎯 كيفية العمل (How It Works)

### **1. بدون عميل محدد (No Customer Selected)**
عند إضافة منتجات بدون اختيار عميل، يتم تطبيق الخصم المتدرج تلقائياً:

```javascript
// Example: Invoice total = 750 SAR
// Tier: 500-999 SAR
// Automatic Discount: 11%
// Discount Amount: 750 × 0.11 = 82.50 SAR
// Total After Discount: 750 - 82.50 = 667.50 SAR
```

### **2. مع عميل له نوع خاص (Customer with Special Type)**
إذا كان للعميل نوع خاص (مثل "مندوب" أو "عميل مميز")، يتم استخدام خصم نوع العميل بدلاً من الخصم المتدرج:

```javascript
// Example: Customer Type = "مندوب" (10% discount)
// Invoice total = 750 SAR
// Customer Type Discount: 10%
// Discount Amount: 750 × 0.10 = 75.00 SAR
// Total After Discount: 750 - 75.00 = 675.00 SAR
```

### **3. عميل عادي (Regular Customer - "عميل")**
العملاء من نوع "عميل" لا يحصلون على أي خصم تلقائي:

```javascript
// Example: Customer Type = "عميل"
// Invoice total = 750 SAR
// Discount: 0%
// Total After Discount: 750 SAR
```

---

## 🔧 التطبيق التقني (Technical Implementation)

### **Frontend (JavaScript)**

**File**: `create.php` (Lines 1250-1279)

```javascript
// Otherwise, apply tiered discount based on subtotal (يعتمد على "أكثر من")
else {
    let tieredDiscountPercentage = 0;
    let tierInfo = '';
    // New discount policy based on invoice total
    if (subtotal >= 1000) {
        tieredDiscountPercentage = 12; // ≥ 1,000 ريال
        tierInfo = 'الفاتورة ≥ 1,000 ريال - خصم تلقائي 12%';
    } else if (subtotal >= 500) {
        tieredDiscountPercentage = 11; // 500-999 ريال
        tierInfo = 'الفاتورة 500-999 ريال - خصم تلقائي 11%';
    } else if (subtotal >= 100) {
        tieredDiscountPercentage = 10; // 100-499 ريال
        tierInfo = 'الفاتورة 100-499 ريال - خصم تلقائي 10%';
    } else {
        tieredDiscountPercentage = 0; // < 100 ريال - لا يوجد خصم
        tierInfo = 'الفاتورة < 100 ريال - لا يوجد خصم';
    }
    currentDiscountPercentage = tieredDiscountPercentage;
    discountInput.value = tieredDiscountPercentage.toFixed(2);
    
    // Update tier info display
    const discountTierRow = document.getElementById('discountTierRow');
    const discountTierInfo = document.getElementById('discountTierInfo');
    if (subtotal > 0 && !selectedCustomerType) {
        discountTierInfo.textContent = tierInfo;
        discountTierRow.classList.remove('hidden');
    } else {
        discountTierRow.classList.add('hidden');
    }
}
```

### **UI Indicator**

**File**: `create.php` (Lines 800-809)

```html
<!-- Discount Tier Indicator -->
<tr id="discountTierRow" class="hidden bg-indigo-50">
    <td colspan="7" class="px-3 py-2">
        <div class="flex items-center justify-center gap-2 text-xs">
            <i class="fas fa-info-circle text-indigo-600"></i>
            <span class="font-medium text-indigo-700">منطق الخصم الجديد:</span>
            <span id="discountTierInfo" class="font-semibold text-indigo-900"></span>
        </div>
    </td>
</tr>
```

---

## 📊 أمثلة عملية (Practical Examples)

### **مثال 1: فاتورة 80 ريال**
- المبلغ قبل الخصم: 80 ريال
- الخصم التلقائي: 0% (أقل من 100 ريال)
- المبلغ بعد الخصم: 80 ريال
- **الرسالة المعروضة**: "الفاتورة < 100 ريال - لا يوجد خصم"

### **مثال 2: فاتورة 250 ريال**
- المبلغ قبل الخصم: 250 ريال
- الخصم التلقائي: 10%
- مبلغ الخصم: 25 ريال
- المبلغ بعد الخصم: 225 ريال
- **الرسالة المعروضة**: "الفاتورة 100-499 ريال - خصم تلقائي 10%"

### **مثال 3: فاتورة 750 ريال**
- المبلغ قبل الخصم: 750 ريال
- الخصم التلقائي: 11%
- مبلغ الخصم: 82.50 ريال
- المبلغ بعد الخصم: 667.50 ريال
- **الرسالة المعروضة**: "الفاتورة 500-999 ريال - خصم تلقائي 11%"

### **مثال 4: فاتورة 1,500 ريال**
- المبلغ قبل الخصم: 1,500 ريال
- الخصم التلقائي: 12%
- مبلغ الخصم: 180 ريال
- المبلغ بعد الخصم: 1,320 ريال
- **الرسالة المعروضة**: "الفاتورة ≥ 1,000 ريال - خصم تلقائي 12%"

---

## 🔒 أولويات الخصم (Discount Priority)

يتم تطبيق الخصومات بالترتيب التالي:

1. **خصم نوع العميل** (Customer Type Discount)
   - إذا كان للعميل نوع خاص مع خصم محدد
   - يتم استخدام خصم نوع العميل بدلاً من الخصم المتدرج

2. **الخصم المتدرج** (Tiered Discount)
   - يُطبق فقط عند عدم وجود عميل محدد
   - أو عند اختيار عميل من نوع "عميل" (بدون خصم خاص)

3. **خصم الكوبون** (Coupon Discount)
   - يُضاف بعد الخصم التلقائي
   - يمكن استخدامه مع أي نوع من الخصومات التلقائية

4. **الخصم الإضافي** (Additional Discount)
   - متاح فقط للمدير العام
   - يُطرح من المبلغ النهائي

---

## 🎨 التصميم المتجاوب (Responsive Design)

### **Desktop View**
- يظهر مؤشر الخصم المتدرج في صف منفصل
- ألوان مميزة (indigo) للتمييز عن الخصومات الأخرى
- أيقونة معلومات توضيحية

### **Mobile View**
- يتحول الصف إلى عرض كامل العرض
- النص يبقى واضحاً ومقروءاً
- يتكيف مع تصميم الكروت على الجوال

---

## ✅ اختبار الميزة (Testing Checklist)

- [x] إضافة منتجات بمبلغ < 100 ريال → لا يوجد خصم
- [x] إضافة منتجات بمبلغ 100-499 ريال → خصم 10%
- [x] إضافة منتجات بمبلغ 500-999 ريال → خصم 11%
- [x] إضافة منتجات بمبلغ ≥ 1000 ريال → خصم 12%
- [x] اختيار عميل من نوع "مندوب" → يستخدم خصم نوع العميل
- [x] اختيار عميل من نوع "عميل" → لا يوجد خصم
- [x] عرض مؤشر الخصم المتدرج بشكل صحيح
- [x] إخفاء المؤشر عند اختيار عميل له خصم خاص

---

## 🚀 النشر (Deployment)

**File Updated**: 
- `create.php` - Main order creation form

**Changes Made**:
1. Updated tiered discount logic (Lines 1250-1279)
2. Added discount tier indicator UI (Lines 800-809)
3. Updated discount calculation to match new policy

**Server Path**: 
```
/home/taksoride-admin/htdocs/modules/orders/create.php
```

**Deployment Command**:
```bash
scp create.php root@45.93.139.14:/home/taksoride-admin/htdocs/modules/orders/create.php
ssh root@45.93.139.14 "chown taksoride-admin:taksoride-admin /home/taksoride-admin/htdocs/modules/orders/create.php && chmod 644 /home/taksoride-admin/htdocs/modules/orders/create.php"
```

---

## 📝 ملاحظات مهمة (Important Notes)

1. **الخصم يعتمد على "أكثر من أو يساوي"** - يتم استخدام `>=` في المقارنات
2. **الخصم المتدرج لا يُطبق مع العملاء ذوي الخصم الخاص** - أولوية لخصم نوع العميل
3. **المؤشر يظهر فقط عند عدم اختيار عميل** - لتوضيح الخصم المتدرج النشط
4. **التحديث يحدث تلقائياً** - عند إضافة أو تعديل المنتجات
5. **متوافق مع الجوال** - تصميم متجاوب بالكامل

---

## 🔄 التحديثات المستقبلية (Future Enhancements)

### **اقتراحات للتطوير**:

1. **إضافة المزيد من المستويات**
   ```javascript
   if (subtotal >= 2000) {
       tieredDiscountPercentage = 15; // ≥ 2,000 ريال
   }
   ```

2. **خصومات موسمية**
   - تطبيق نسب خصم مختلفة حسب الموسم
   - تفعيل/تعطيل الخصومات المتدرجة

3. **تقارير الخصومات**
   - عرض إحصائيات الخصومات المطبقة
   - تحليل توفير العملاء

---

**تاريخ التطبيق**: 13 نوفمبر 2025  
**المطور**: Senior PHP & TailwindCSS Engineer  
**الحالة**: ✅ جاهز للنشر (Production Ready)
