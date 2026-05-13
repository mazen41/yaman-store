# Filter Component Usage Guide

## How to Add Filters to Any Report

### Step 1: Include the Filter Component

Add this line after your database connection and before displaying the report:

```php
// Enable specific filters (optional)
$show_status_filter = true;      // Show status dropdown
$show_customer_filter = true;    // Show customer dropdown
$show_category_filter = true;    // Show category dropdown
$categories = ['فئة 1', 'فئة 2']; // Define categories if needed

// Include the filter component
include 'filter_component.php';
```

### Step 2: Use Filter Values in Your Query

The filter component provides these variables:
- `$date_from` - Start date
- `$date_to` - End date
- `$status_filter` - Selected status
- `$customer_filter` - Selected customer ID
- `$category_filter` - Selected category

Example SQL query with filters:

```php
$query = "SELECT * FROM customer_orders WHERE 1=1";
$params = [];

if ($date_from) {
    $query .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
}

if ($status_filter) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

if ($customer_filter) {
    $query .= " AND customer_id = ?";
    $params[] = $customer_filter;
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### Step 3: That's It!

The filter component will automatically:
- Display the filter form
- Handle form submissions
- Remember selected values
- Provide reset and print buttons
- Hide filters when printing

### Example: Complete Report with Filters

```php
<?php
session_start();
require_once '../../config/database.php';

$page_title = 'تقرير المبيعات';

// Enable filters you need
$show_status_filter = true;
$show_customer_filter = true;

// Include filter component
include 'filter_component.php';

// Build query with filters
$query = "SELECT * FROM customer_orders WHERE 1=1";
$params = [];

if ($date_from) {
    $query .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
}

if ($status_filter) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

if ($customer_filter) {
    $query .= " AND customer_id = ?";
    $params[] = $customer_filter;
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<!-- Your report content here -->
<div class="report-content">
    <!-- Display results -->
</div>

<?php include '../../includes/footer.php'; ?>
```

### Available Filter Options

- **Date Range**: Always available
- **Status Filter**: Set `$show_status_filter = true;`
- **Customer Filter**: Set `$show_customer_filter = true;`
- **Category Filter**: Set `$show_category_filter = true;` and define `$categories` array

### Features

✓ Responsive design
✓ Collapsible filters
✓ Print-friendly (hides on print)
✓ Reset button
✓ Search button
✓ Print button
✓ Remembers selected values
✓ RTL support
✓ Modern UI with icons
