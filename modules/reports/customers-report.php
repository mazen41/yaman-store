<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تقرير العملاء';

// Get filter parameters
$customer_type_filter = $_GET['customer_type'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Fetch customers data
$sql = "
    SELECT 
        c.id,
        c.name,
        c.email,
        c.phone,
        c.customer_type,
        c.credit_limit,
        c.current_balance,
        c.is_active,
        c.created_at,
        COUNT(co.id) as total_orders,
        COALESCE(SUM(co.total_amount), 0) as total_purchases,
        MAX(co.order_date) as last_order_date
    FROM customers c
    LEFT JOIN customer_orders co ON c.id = co.customer_id
    WHERE 1=1
";
$params = [];

if ($customer_type_filter) {
    $sql .= " AND c.customer_type = ?";
    $params[] = $customer_type_filter;
}

if ($status_filter !== '') {
    $sql .= " AND c.is_active = ?";
    $params[] = $status_filter;
}

$sql .= " GROUP BY c.id ORDER BY c.name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers_data = $stmt->fetchAll();

// Calculate totals
$total_customers = count($customers_data);
$active_customers = 0;
$total_credit_limit = 0;
$total_balance = 0;
$total_sales_volume = 0;

foreach ($customers_data as $customer) {
    if ($customer['is_active']) $active_customers++;
    $total_credit_limit += $customer['credit_limit'];
    $total_balance += $customer['current_balance'];
    $total_sales_volume += $customer['total_purchases'];
}

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
                        <p class="text-gray-600 mt-1">قائمة شاملة بجميع العملاء والإحصائيات</p>
                    </div>
                    <div class="flex space-x-3 space-x-reverse">
                        <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">نوع العميل</label>
                        <select name="customer_type" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 bg-white">
                            <option value="">جميع الأنواع</option>
                            <option value="individual" <?php echo $customer_type_filter == 'individual' ? 'selected' : ''; ?>>فرد</option>
                            <option value="company" <?php echo $customer_type_filter == 'company' ? 'selected' : ''; ?>>شركة</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                        <select name="status" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 bg-white">
                            <option value="">جميع العملاء</option>
                            <option value="1" <?php echo $status_filter === '1' ? 'selected' : ''; ?>>نشط</option>
                            <option value="0" <?php echo $status_filter === '0' ? 'selected' : ''; ?>>غير نشط</option>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                        <i class="fas fa-filter ml-2"></i>فلترة
                    </button>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-users text-2xl text-blue-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي العملاء</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_customers, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-user-check text-2xl text-amber-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">العملاء النشطين</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($active_customers, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-credit-card text-2xl text-purple-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي حدود الائتمان</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_credit_limit, 0, '', ''); ?> ر.س</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-money-bill-wave text-2xl text-orange-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي المبيعات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_sales_volume, 0, '', ''); ?> ر.س</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customers Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">تفاصيل العملاء</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">اسم العميل</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">النوع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الهاتف</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">البريد الإلكتروني</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">حد الائتمان</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الرصيد الحالي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">عدد الطلبات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">إجمالي المشتريات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">آخر طلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحالة</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($customers_data)): ?>
                        <tr>
                            <td colspan="11" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-users text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد عملاء تطابق الفلتر المحدد</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($customers_data as $index => $customer): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo $index + 1; ?></td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $customer['customer_type'] == 'company' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800'; ?>">
                                    <?php echo $customer['customer_type'] == 'company' ? 'شركة' : 'فرد'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo htmlspecialchars($customer['phone'] ?? '-'); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo htmlspecialchars($customer['email'] ?? '-'); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($customer['credit_limit'], 0, '', ''); ?> ر.س
                            </td>
                            <td class="px-6 py-4 text-sm <?php echo $customer['current_balance'] > 0 ? 'text-amber-600' : ($customer['current_balance'] < 0 ? 'text-red-600' : 'text-gray-900'); ?>">
                                <?php echo number_format($customer['current_balance'], 0, '', ''); ?> ر.س
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded-full">
                                    <?php echo number_format($customer['total_orders'], 0, '', ''); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo number_format($customer['total_purchases'], 0, '', ''); ?> ر.س
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo $customer['last_order_date'] ? date('d/m/Y', strtotime($customer['last_order_date'])) : 'لا يوجد'; ?>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $customer['is_active'] ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $customer['is_active'] ? 'نشط' : 'غير نشط'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Summary Row -->
                        <tr class="bg-gray-50 font-bold">
                            <td colspan="5" class="px-6 py-4 text-sm text-gray-900">المجموع الكلي:</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_credit_limit, 0, '', ''); ?> ر.س</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_balance, 0, '', ''); ?> ر.س</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format(array_sum(array_column($customers_data, 'total_orders'))); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_sales_volume, 0, '', ''); ?> ر.س</td>
                            <td colspan="2" class="px-6 py-4 text-sm text-gray-900"><?php echo $total_customers; ?> عميل</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style media="print">
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .bg-gray-50 { background: white !important; }
    .shadow { box-shadow: none !important; }
</style>

<?php include '../../includes/footer.php'; ?>
