# Paid Amount Feature - Implementation Summary

## Overview
Added "Paid Amount" and "Remaining Amount" tracking to the orders system, allowing tracking of partial payments and outstanding balances.

## Changes Made

### 1. Database Migration
**File:** `setup/add_paid_amount_column.php`
- Created migration script to add `paid_amount` column to `customer_orders` table
- Column type: `DECIMAL(10,3)` with default value `0.000`
- Automatically updates existing orders with `paid_amount = 0`

**To Run Migration:**
- Visit: `http://localhost/yassin-admin-system/setup/add_paid_amount_column.php`
- Or run SQL directly in phpMyAdmin:
  ```sql
  ALTER TABLE customer_orders 
  ADD COLUMN paid_amount DECIMAL(10,3) DEFAULT 0.000 AFTER final_amount;
  ```

### 2. Orders Index Page (`modules/orders/index.php`)
**Updated SQL Query:**
- Added `paid_amount` field
- Added calculated `remaining_amount` field: `(final_amount - paid_amount)`

**Updated Table Columns (Right to Left):**
1. رقم الطلب (Order Number)
2. اسم العميل ورقم الجوال (Customer Name & Mobile)
3. عدد الطلبات (Items Count)
4. الإجمالي (Subtotal)
5. مبلغ الخصم (Discount Amount)
6. الإجمالي بعد الخصم (Final Amount)
7. **المبلغ المدفوع (Paid Amount)** ✨ NEW
8. **المبلغ المتبقي (Remaining Amount)** ✨ NEW
9. تاريخ الطلب (Order Date)
10. حالة الطلب (Order Status)
11. رقم الفاتورة (Invoice Number)
12. الإجراءات (Actions)

### 3. Create Order Page (`modules/orders/create.php`)
**Backend Changes:**
- Added `$paid_amount` parameter to form processing
- Updated INSERT query to include `paid_amount` field
- Stores paid amount when creating new order

**Frontend Changes:**
- Added "المبلغ المدفوع" (Paid Amount) input field in order summary table
- Added "المبلغ المتبقي" (Remaining Amount) calculated display
- Real-time calculation of remaining amount
- Color-coded payment status:
  - 🟡 Yellow: No payment (remaining = final amount)
  - 🟠 Orange: Partial payment (0 < remaining < final amount)
  - 🟢 Green: Fully paid (remaining ≤ 0)

**JavaScript Functions:**
- `updateRemainingAmount()`: Calculates and displays remaining balance
- Integrated with `updateTotals()` for automatic updates
- Updates when:
  - Items are added/removed
  - Prices change
  - Discount applied
  - Shipping cost changes
  - Paid amount changes

## Features

### Payment Tracking
- Track partial payments on orders
- Calculate remaining balance automatically
- Visual indicators for payment status

### Real-time Calculations
- Remaining Amount = Final Amount - Paid Amount
- Updates automatically when any amount changes
- Formatted to 3 decimal places (Riyal precision)

### Visual Feedback
- Color-coded rows based on payment status
- Icons for better UX
- Responsive design

## Usage

### Creating an Order with Payment
1. Fill in customer and order details
2. Add products
3. Enter paid amount in "المبلغ المدفوع" field
4. System automatically calculates remaining amount
5. Submit order

### Viewing Orders
- Index page shows paid and remaining amounts for all orders
- Easy identification of unpaid/partially paid orders
- Filter and sort capabilities

## Database Schema

```sql
customer_orders table:
- paid_amount: DECIMAL(10,3) DEFAULT 0.000
- Calculated: remaining_amount = final_amount - paid_amount
```

## Next Steps (Optional Enhancements)

1. **Payment History Table**
   - Track multiple payments per order
   - Payment date and method
   - Payment receipts

2. **Edit Order Page**
   - Add paid amount field to edit form
   - Update payment status

3. **Payment Reports**
   - Outstanding balances report
   - Payment collection report
   - Customer payment history

4. **Payment Reminders**
   - Automatic reminders for unpaid orders
   - WhatsApp/Email notifications

5. **Payment Status Field**
   - Auto-update based on paid_amount
   - Values: 'unpaid', 'partial', 'paid'

## Testing Checklist

- [x] Database migration runs successfully
- [x] Create order with paid amount
- [x] View orders with paid/remaining amounts
- [x] Real-time calculation works
- [x] Color coding displays correctly
- [ ] Edit order with paid amount (pending)
- [ ] Payment reports (pending)

## Files Modified

1. `modules/orders/index.php` - Updated list view
2. `modules/orders/create.php` - Added paid amount field
3. `setup/add_paid_amount_column.php` - Database migration

## Date
October 10, 2025

## Status
✅ **Completed** - Core functionality implemented and ready for use
