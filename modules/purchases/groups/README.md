# 🛒 Purchase Groups Management System

## Overview
Complete purchase groups management system for organizing and tracking purchase orders in groups.

## 📋 Features Implemented

### 1. **Database Structure**
- ✅ `purchase_groups` table with all necessary fields
- ✅ Foreign key relationships with users table
- ✅ Support for group numbering, dates, status tracking
- ✅ Automatic timestamps (created_at, updated_at)
- ✅ Sample data for testing

### 2. **Backend Functionality**

#### **Index Page** (`index.php`)
- ✅ List all purchase groups with pagination
- ✅ Advanced filtering (search, status, date range)
- ✅ Statistics dashboard (total groups, active, completed, total orders)
- ✅ Dynamic order count and total amount calculation per group
- ✅ Soft delete functionality (is_active flag)

#### **Add Page** (`add.php`)
- ✅ Create new purchase groups
- ✅ Form validation
- ✅ Unique group number checking
- ✅ Auto-redirect after successful creation

#### **View Page** (`view.php`)
- ✅ Display complete group details
- ✅ Show all purchase orders in the group
- ✅ Statistics cards (total orders, amount, pending, completed)
- ✅ Quick add purchase order button
- ✅ Link to edit group

#### **Edit Page** (`edit.php`)
- ✅ Update group information
- ✅ Validation and duplicate checking
- ✅ Preserve existing data

### 3. **Frontend Design**

#### **Modern UI Elements**
- ✅ Purple/Indigo gradient theme
- ✅ Responsive design (mobile-friendly)
- ✅ Beautiful statistics cards with icons
- ✅ Color-coded status badges
- ✅ Hover effects and transitions
- ✅ Professional table layouts
- ✅ Empty states with helpful messages

#### **User Experience**
- ✅ Clear navigation breadcrumbs
- ✅ Intuitive action buttons
- ✅ Success/error message alerts
- ✅ Confirmation dialogs for delete
- ✅ Search and filter interface
- ✅ Pagination for large datasets

### 4. **Integration**

#### **Sidebar Navigation**
- ✅ Added "مجموعات الشراء" link
- ✅ Active state highlighting
- ✅ Icon: `fa-layer-group`
- ✅ Positioned between purchases and suppliers

#### **Database Migration**
- ✅ `create_purchase_groups_table.php` script
- ✅ Beautiful migration UI
- ✅ Automatic sample data insertion
- ✅ Table structure display
- ✅ Error handling

### 5. **Database Schema**

```sql
CREATE TABLE purchase_groups (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(255) NOT NULL,
    group_number VARCHAR(100) UNIQUE,
    description TEXT,
    start_date DATE,
    end_date DATE,
    status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
    total_orders INT(11) DEFAULT 0,
    total_amount DECIMAL(10,3) DEFAULT 0.000,
    notes TEXT,
    created_by INT(11),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_group_number (group_number),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
```

## 🚀 Installation Steps

### 1. Run Database Migration
```
http://localhost/yassin-admin-system/setup/create_purchase_groups_table.php
```

### 2. Access Purchase Groups
```
http://localhost/yassin-admin-system/modules/purchases/groups/index.php
```

### 3. Verify Sidebar Link
- Check that "مجموعات الشراء" appears in the sidebar
- Click to navigate to groups management

## 📁 File Structure

```
modules/purchases/groups/
├── index.php          # List all groups with filtering
├── add.php            # Create new group
├── view.php           # View group details and orders
├── edit.php           # Edit group information
└── README.md          # This file

setup/
└── create_purchase_groups_table.php  # Database migration
```

## 🎨 Design Features

### Color Scheme
- **Primary**: Purple (#9333ea) / Indigo (#4f46e5)
- **Success**: Green (#16a34a)
- **Warning**: Yellow (#eab308)
- **Danger**: Red (#dc2626)
- **Info**: Blue (#3b82f6)

### Icons Used
- 🛒 `fa-layer-group` - Main groups icon
- ➕ `fa-plus-circle` - Add new
- 👁️ `fa-eye` - View details
- ✏️ `fa-edit` - Edit
- 🗑️ `fa-trash` - Delete
- 📊 `fa-chart-bar` - Statistics
- 📅 `fa-calendar` - Dates
- ✅ `fa-check-circle` - Active status
- ⏸️ `fa-pause-circle` - Inactive status
- 🏁 `fa-flag-checkered` - Completed status

## 📊 Statistics Tracked

1. **Total Groups** - All groups in system
2. **Active Groups** - Currently active groups
3. **Completed Groups** - Finished groups
4. **Total Orders** - All orders across all groups
5. **Per Group Stats**:
   - Order count
   - Total amount
   - Pending orders
   - Completed orders

## 🔗 Integration Points

### With Purchase Orders
- Purchase orders can be assigned to groups
- Group ID stored in `purchase_orders.purchase_group_id`
- View all orders in a group from group details page
- Quick add order to group functionality

### With Users
- Track who created each group
- Display creator name in group details
- Foreign key relationship maintained

## 🎯 Use Cases

1. **Monthly Purchase Grouping**
   - Group all January purchases together
   - Track monthly spending
   - Compare month-over-month

2. **Special Campaigns**
   - Summer sale purchases
   - Bulk order campaigns
   - Seasonal inventory restocking

3. **Supplier-Specific Groups**
   - Group all orders from specific supplier
   - Track supplier performance
   - Negotiate better rates

4. **Project-Based Purchasing**
   - Group purchases for specific projects
   - Track project costs
   - Budget management

## 🔒 Security Features

- ✅ Session-based authentication
- ✅ SQL injection prevention (prepared statements)
- ✅ XSS protection (htmlspecialchars)
- ✅ CSRF protection ready
- ✅ Input validation
- ✅ Soft delete (data preservation)

## 📱 Responsive Design

- ✅ Mobile-friendly tables
- ✅ Collapsible filters on small screens
- ✅ Touch-friendly buttons
- ✅ Adaptive grid layouts
- ✅ Hamburger menu integration

## 🎉 Success!

The Purchase Groups Management System is now fully functional and integrated into your Yassin Admin System!

### Quick Links:
- **Groups List**: `/modules/purchases/groups/index.php`
- **Add Group**: `/modules/purchases/groups/add.php`
- **Database Setup**: `/setup/create_purchase_groups_table.php`

---

**Created by**: Senior Developer
**Date**: 2025-10-10
**Version**: 1.0.0
