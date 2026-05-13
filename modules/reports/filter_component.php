<?php
/**
 * Reusable Filter Component for All Reports
 * Include this file in any report to add filtering functionality
 */

// Get filter parameters
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? '';
$customer_filter = $_GET['customer_id'] ?? '';
$category_filter = $_GET['category'] ?? '';
?>

<!-- Filter Section -->
<div class="bg-white shadow-lg rounded-2xl mb-6 p-6 print:hidden">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-xl font-bold text-gray-800 flex items-center">
            <i class="fas fa-filter ml-2 text-blue-600"></i>
            تصفية التقرير
        </h3>
        <button type="button" onclick="toggleFilters()" class="text-sm text-blue-600 hover:text-blue-800">
            <i class="fas fa-chevron-down" id="filterToggleIcon"></i>
            <span id="filterToggleText">إخفاء</span>
        </button>
    </div>
    
    <form method="GET" action="" id="filterForm" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Date From -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                <i class="fas fa-calendar-alt ml-1 text-gray-500"></i>
                من التاريخ
            </label>
            <input type="date" 
                   name="date_from" 
                   value="<?php echo htmlspecialchars($date_from); ?>" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
        </div>
        
        <!-- Date To -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                <i class="fas fa-calendar-alt ml-1 text-gray-500"></i>
                إلى التاريخ
            </label>
            <input type="date" 
                   name="date_to" 
                   value="<?php echo htmlspecialchars($date_to); ?>" 
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
        </div>
        
        <!-- Status Filter (if applicable) -->
        <?php if (isset($show_status_filter) && $show_status_filter): ?>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                <i class="fas fa-info-circle ml-1 text-gray-500"></i>
                الحالة
            </label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                <option value="">الكل</option>
                <option value="new" <?php echo $status_filter == 'new' ? 'selected' : ''; ?>>جديد</option>
                <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>معتمد</option>
                <option value="in_preparation" <?php echo $status_filter == 'in_preparation' ? 'selected' : ''; ?>>قيد التحضير</option>
                <option value="shipped" <?php echo $status_filter == 'shipped' ? 'selected' : ''; ?>>تم الشحن</option>
                <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>مكتمل</option>
                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>ملغي</option>
            </select>
        </div>
        <?php endif; ?>
        
        <!-- Customer Filter (if applicable) -->
        <?php if (isset($show_customer_filter) && $show_customer_filter): ?>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                <i class="fas fa-user ml-1 text-gray-500"></i>
                العميل
            </label>
            <select name="customer_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                <option value="">الكل</option>
                <?php
                if (isset($db)) {
                    try {
                        $customers = $db->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($customers as $customer) {
                            $selected = ($customer_filter == $customer['id']) ? 'selected' : '';
                            echo '<option value="' . $customer['id'] . '" ' . $selected . '>' . htmlspecialchars($customer['name']) . '</option>';
                        }
                    } catch (PDOException $e) {
                        // Silent fail
                    }
                }
                ?>
            </select>
        </div>
        <?php endif; ?>
        
        <!-- Category Filter (if applicable) -->
        <?php if (isset($show_category_filter) && $show_category_filter): ?>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                <i class="fas fa-tags ml-1 text-gray-500"></i>
                الفئة
            </label>
            <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                <option value="">الكل</option>
                <?php
                if (isset($categories) && is_array($categories)) {
                    foreach ($categories as $cat) {
                        $selected = ($category_filter == $cat) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($cat) . '" ' . $selected . '>' . htmlspecialchars($cat) . '</option>';
                    }
                }
                ?>
            </select>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="flex items-end gap-2 md:col-span-2 lg:col-span-1">
            <button type="submit" class="flex-1 px-4 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 transition font-semibold shadow-md hover:shadow-lg">
                <i class="fas fa-search ml-1"></i>
                بحث
            </button>
            <button type="button" onclick="resetFilters()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-semibold">
                <i class="fas fa-redo ml-1"></i>
            </button>
            <button type="button" onclick="window.print()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-semibold">
                <i class="fas fa-print ml-1"></i>
            </button>
        </div>
    </form>
</div>

<script>
function toggleFilters() {
    const form = document.getElementById('filterForm');
    const icon = document.getElementById('filterToggleIcon');
    const text = document.getElementById('filterToggleText');
    
    if (form.style.display === 'none') {
        form.style.display = 'grid';
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
        text.textContent = 'إخفاء';
    } else {
        form.style.display = 'none';
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
        text.textContent = 'إظهار';
    }
}

function resetFilters() {
    window.location.href = window.location.pathname;
}
</script>

<style>
@media print {
    .print\:hidden {
        display: none !important;
    }
}
</style>
