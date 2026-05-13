<?php
/**
 * Pending Orders Report
 * Advanced reporting system for Yassin Admin
 * 
 * @author Senior PHP Engineer
 * @version 1.0
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'الطلبات المعلقة';

// Get filter parameters
$supplier_filter = $_GET['supplier_id'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$min_days = isset($_GET['min_days']) && is_numeric($_GET['min_days']) ? intval($_GET['min_days']) : '';
$sort_by = $_GET['sort_by'] ?? 'order_date';
$sort_dir = $_GET['sort_dir'] ?? 'asc';

// Validate sort parameters
$valid_sort_fields = ['order_number', 'order_date', 'supplier_name', 'total_amount', 'priority', 'days_pending'];
if (!in_array($sort_by, $valid_sort_fields)) {
    $sort_by = 'order_date';
}

$valid_sort_dirs = ['asc', 'desc'];
if (!in_array($sort_dir, $valid_sort_dirs)) {
    $sort_dir = 'asc';
}

// Fetch pending orders data
$sql = "
    SELECT 
        po.id,
        po.order_number,
        po.order_date,
        DATEDIFF(CURRENT_DATE, po.order_date) as days_pending,
        s.name as supplier_name,
        s.contact_person,
        s.phone,
        po.subtotal,
        po.tax_amount,
        po.discount_amount,
        po.total_amount,
        po.status,
        po.priority,
        po.notes,
        u.full_name as created_by
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.status IN ('pending', 'ordered')
";
$params = [];

if ($supplier_filter) {
    $sql .= " AND po.supplier_id = ?";
    $params[] = $supplier_filter;
}

if ($priority_filter) {
    $sql .= " AND po.priority = ?";
    $params[] = $priority_filter;
}

if ($min_days !== '') {
    $sql .= " AND DATEDIFF(CURRENT_DATE, po.order_date) >= ?";
    $params[] = $min_days;
}

// Handle sorting
if ($sort_by == 'days_pending') {
    $sql .= " ORDER BY days_pending $sort_dir, order_date ASC";
} else {
    $sql .= " ORDER BY $sort_by $sort_dir";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$pending_orders = $stmt->fetchAll();

// Calculate totals
$total_orders = count($pending_orders);
$total_amount = 0;
$avg_days_pending = 0;
$total_days = 0;
$priority_counts = [
    'low' => 0,
    'medium' => 0,
    'high' => 0,
    'urgent' => 0
];

foreach ($pending_orders as $order) {
    $total_amount += $order['total_amount'];
    $total_days += $order['days_pending'];
    $priority_counts[$order['priority']]++;
}

if ($total_orders > 0) {
    $avg_days_pending = $total_days / $total_orders;
}

// Get suppliers for filter
$suppliers_stmt = $db->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                        <p class="text-gray-600 mt-1">
                            تقرير الطلبات المعلقة وقيد التنفيذ
                        </p>
                    </div>
                    <div class="flex space-x-3 space-x-reverse">
                        <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i class="fas fa-print ml-2"></i>
                            طباعة
                        </button>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">المورد</label>
                        <select name="supplier_id" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 bg-white">
                            <option value="">جميع الموردين</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">الأولوية</label>
                        <select name="priority" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 bg-white">
                            <option value="">جميع الأولويات</option>
                            <option value="low" <?php echo $priority_filter == 'low' ? 'selected' : ''; ?>>منخفضة</option>
                            <option value="medium" <?php echo $priority_filter == 'medium' ? 'selected' : ''; ?>>متوسطة</option>
                            <option value="high" <?php echo $priority_filter == 'high' ? 'selected' : ''; ?>>عالية</option>
                            <option value="urgent" <?php echo $priority_filter == 'urgent' ? 'selected' : ''; ?>>عاجلة</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">الحد الأدنى للأيام المعلقة</label>
                        <input type="number" name="min_days" value="<?php echo $min_days; ?>" placeholder="أي عدد أيام"
                               class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ترتيب حسب</label>
                        <select name="sort_by" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 bg-white">
                            <option value="order_date" <?php echo $sort_by == 'order_date' ? 'selected' : ''; ?>>تاريخ الطلب</option>
                            <option value="days_pending" <?php echo $sort_by == 'days_pending' ? 'selected' : ''; ?>>عدد أيام التعليق</option>
                            <option value="priority" <?php echo $sort_by == 'priority' ? 'selected' : ''; ?>>الأولوية</option>
                            <option value="total_amount" <?php echo $sort_by == 'total_amount' ? 'selected' : ''; ?>>المبلغ الإجمالي</option>
                            <option value="supplier_name" <?php echo $sort_by == 'supplier_name' ? 'selected' : ''; ?>>اسم المورد</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">اتجاه الترتيب</label>
                        <select name="sort_dir" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 bg-white">
                            <option value="asc" <?php echo $sort_dir == 'asc' ? 'selected' : ''; ?>>تصاعدي</option>
                            <option value="desc" <?php echo $sort_dir == 'desc' ? 'selected' : ''; ?>>تنازلي</option>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-filter ml-2"></i>فلترة
                    </button>
                    <a href="pending-orders.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                        <i class="fas fa-redo ml-2"></i>إعادة تعيين
                    </a>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-clock text-2xl text-yellow-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي الطلبات المعلقة</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_orders, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-money-bill-wave text-2xl text-amber-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي قيمة الطلبات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_amount, 0, '', ''); ?> ر.ي</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-calendar-day text-2xl text-purple-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">متوسط أيام التعليق</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($avg_days_pending, 0, '', ''); ?> يوم</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-2xl text-red-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">طلبات عاجلة</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($priority_counts['urgent'], 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Priority Distribution -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">توزيع الطلبات حسب الأولوية</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <!-- Urgent Priority -->
                    <div class="bg-red-50 p-4 rounded-lg border-r-4 border-red-500">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-red-800">عاجلة</h4>
                            <span class="px-2 py-1 bg-red-100 text-red-800 text-xs font-medium rounded-full">
                                <?php echo number_format($priority_counts['urgent'], 0, '', ''); ?> طلب
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <?php $urgent_percentage = $total_orders > 0 ? ($priority_counts['urgent'] / $total_orders) * 100 : 0; ?>
                            <div class="bg-red-600 h-2.5 rounded-full" style="width: <?php echo $urgent_percentage; ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1"><?php echo number_format($urgent_percentage, 0, '', ''); ?>% من إجمالي الطلبات</p>
                    </div>
                    
                    <!-- High Priority -->
                    <div class="bg-orange-50 p-4 rounded-lg border-r-4 border-orange-500">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-orange-800">عالية</h4>
                            <span class="px-2 py-1 bg-orange-100 text-orange-800 text-xs font-medium rounded-full">
                                <?php echo number_format($priority_counts['high'], 0, '', ''); ?> طلب
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <?php $high_percentage = $total_orders > 0 ? ($priority_counts['high'] / $total_orders) * 100 : 0; ?>
                            <div class="bg-orange-600 h-2.5 rounded-full" style="width: <?php echo $high_percentage; ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1"><?php echo number_format($high_percentage, 0, '', ''); ?>% من إجمالي الطلبات</p>
                    </div>
                    
                    <!-- Medium Priority -->
                    <div class="bg-blue-50 p-4 rounded-lg border-r-4 border-blue-500">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-blue-800">متوسطة</h4>
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded-full">
                                <?php echo number_format($priority_counts['medium'], 0, '', ''); ?> طلب
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <?php $medium_percentage = $total_orders > 0 ? ($priority_counts['medium'] / $total_orders) * 100 : 0; ?>
                            <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo $medium_percentage; ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1"><?php echo number_format($medium_percentage, 0, '', ''); ?>% من إجمالي الطلبات</p>
                    </div>
                    
                    <!-- Low Priority -->
                    <div class="bg-gray-50 p-4 rounded-lg border-r-4 border-gray-500">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-800">منخفضة</h4>
                            <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs font-medium rounded-full">
                                <?php echo number_format($priority_counts['low'], 0, '', ''); ?> طلب
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <?php $low_percentage = $total_orders > 0 ? ($priority_counts['low'] / $total_orders) * 100 : 0; ?>
                            <div class="bg-gray-600 h-2.5 rounded-full" style="width: <?php echo $low_percentage; ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1"><?php echo number_format($low_percentage, 0, '', ''); ?>% من إجمالي الطلبات</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Orders Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">قائمة الطلبات المعلقة</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">رقم الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">تاريخ الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">أيام التعليق</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المورد</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الإجمالي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحالة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الأولوية</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">ملاحظات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($pending_orders)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-check-circle text-4xl mb-4 text-amber-300"></i>
                                <p class="text-lg font-medium text-gray-900 mb-2">لا توجد طلبات معلقة حالياً</p>
                                <p>جميع الطلبات مكتملة أو لا توجد طلبات تطابق معايير البحث</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($pending_orders as $order): ?>
                        <tr class="hover:bg-gray-50 <?php echo $order['priority'] == 'urgent' ? 'bg-red-50' : ($order['priority'] == 'high' ? 'bg-orange-50' : ''); ?>">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($order['order_number']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo date('d/m/Y', strtotime($order['order_date'])); ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium <?php echo $order['days_pending'] > 14 ? 'text-red-600' : ($order['days_pending'] > 7 ? 'text-orange-600' : 'text-gray-900'); ?>">
                                <?php echo number_format($order['days_pending'], 0, '', ''); ?> يوم
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div>
                                    <div class="font-medium"><?php echo htmlspecialchars($order['supplier_name']); ?></div>
                                    <?php if ($order['contact_person']): ?>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($order['contact_person']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                <?php echo number_format($order['total_amount'], 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php
                                $status_colors = [
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    'ordered' => 'bg-blue-100 text-blue-800'
                                ];
                                $status_labels = [
                                    'pending' => 'معلق',
                                    'ordered' => 'تم الطلب'
                                ];
                                ?>
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $status_colors[$order['status']]; ?>">
                                    <?php echo $status_labels[$order['status']]; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php
                                $priority_colors = [
                                    'low' => 'bg-gray-100 text-gray-800',
                                    'medium' => 'bg-blue-100 text-blue-800',
                                    'high' => 'bg-orange-100 text-orange-800',
                                    'urgent' => 'bg-red-100 text-red-800'
                                ];
                                $priority_labels = [
                                    'low' => 'منخفضة',
                                    'medium' => 'متوسطة',
                                    'high' => 'عالية',
                                    'urgent' => 'عاجلة'
                                ];
                                ?>
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $priority_colors[$order['priority']]; ?>">
                                    <?php echo $priority_labels[$order['priority']]; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php if ($order['notes']): ?>
                                <div class="max-w-xs truncate" title="<?php echo htmlspecialchars($order['notes']); ?>">
                                    <?php echo htmlspecialchars(substr($order['notes'], 0, 50)) . (strlen($order['notes']) > 50 ? '...' : ''); ?>
                                </div>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <div class="flex space-x-2 space-x-reverse">
                                    <a href="../purchases/view.php?id=<?php echo $order['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض التفاصيل">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../purchases/edit.php?id=<?php echo $order['id']; ?>" class="text-amber-600 hover:text-amber-900" title="تعديل الطلب">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Action Recommendations -->
        <?php if (!empty($pending_orders)): ?>
        <div class="bg-blue-50 rounded-lg p-6 mt-6">
            <h3 class="text-lg font-medium text-blue-900 mb-4">توصيات للإجراءات:</h3>
            <div class="space-y-2 text-sm text-blue-800">
                <?php if ($priority_counts['urgent'] > 0): ?>
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle ml-2 text-red-600"></i>
                    <span>يوجد <?php echo $priority_counts['urgent']; ?> طلب عاجل يحتاج إلى متابعة فورية</span>
                </div>
                <?php endif; ?>
                
                <?php if ($priority_counts['high'] > 0): ?>
                <div class="flex items-center">
                    <i class="fas fa-arrow-left ml-2 text-orange-600"></i>
                    <span>يوجد <?php echo $priority_counts['high']; ?> طلب ذو أولوية عالية يحتاج إلى متابعة</span>
                </div>
                <?php endif; ?>
                
                <?php 
                $overdue_orders = 0;
                foreach ($pending_orders as $order) {
                    if ($order['days_pending'] > 14) $overdue_orders++;
                }
                if ($overdue_orders > 0):
                ?>
                <div class="flex items-center">
                    <i class="fas fa-calendar-times ml-2 text-red-600"></i>
                    <span>يوجد <?php echo $overdue_orders; ?> طلب متأخر لأكثر من 14 يوم</span>
                </div>
                <?php endif; ?>
                
                <div class="flex items-center">
                    <i class="fas fa-phone ml-2 text-blue-600"></i>
                    <span>تواصل مع الموردين لمتابعة حالة الطلبات المعلقة</span>
                </div>
                
                <div class="flex items-center">
                    <i class="fas fa-chart-line ml-2 text-blue-600"></i>
                    <span>راجع متوسط وقت التسليم للموردين وقم بتحديث توقعات التسليم</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style media="print">
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .bg-gray-50, .bg-red-50, .bg-orange-50, .bg-blue-50 { background: white !important; }
    .shadow { box-shadow: none !important; }
</style>

<?php include '../../includes/footer.php'; ?>
