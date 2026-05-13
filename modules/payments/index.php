<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة المدفوعات';
$error_message = '';
$success_message = '';

// Get filter parameters
$method_filter = $_GET['method'] ?? '';
$customer_filter = $_GET['customer_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Build query
$query = "SELECT p.*, c.name as customer_name, c.customer_code, ci.invoice_number 
          FROM customer_payments p 
          LEFT JOIN customers c ON p.customer_id = c.id 
          LEFT JOIN customer_invoices ci ON p.invoice_id = ci.id 
          WHERE 1=1";
$params = [];

if ($method_filter) {
    $query .= " AND p.payment_method = ?";
    $params[] = $method_filter;
}

if ($customer_filter) {
    $query .= " AND p.customer_id = ?";
    $params[] = $customer_filter;
}

if ($date_from) {
    $query .= " AND DATE(p.payment_date) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(p.payment_date) <= ?";
    $params[] = $date_to;
}

if ($search) {
    $query .= " AND (p.payment_number LIKE ? OR c.name LIKE ? OR ci.invoice_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// Get total count for pagination
$count_query = str_replace("SELECT p.*, c.name as customer_name, c.customer_code, ci.invoice_number", "SELECT COUNT(*) as total", $query);
$stmt = $db->prepare($count_query);
$stmt->execute($params);
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Add sorting and pagination to the main query
$query .= " ORDER BY p.created_at DESC LIMIT $offset, $records_per_page";

// Execute the main query
$stmt = $db->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get customers for filter dropdown
$customers_stmt = $db->prepare("SELECT id, name, customer_code FROM customers WHERE is_active = 1 ORDER BY name");
$customers_stmt->execute();
$customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment statistics
$stats_query = "SELECT 
                COUNT(*) as total_payments,
                SUM(amount) as total_amount,
                SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash_amount,
                SUM(CASE WHEN payment_method = 'transfer' THEN amount ELSE 0 END) as transfer_amount,
                SUM(CASE WHEN payment_method = 'credit_card' THEN amount ELSE 0 END) as credit_card_amount,
                SUM(CASE WHEN payment_method = 'check' THEN amount ELSE 0 END) as check_amount,
                SUM(CASE WHEN payment_method = 'other' THEN amount ELSE 0 END) as other_amount
                FROM customer_payments";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<style>
    .method-badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    .method-cash { background-color: #d1fae5; color: #065f46; }
    .method-transfer { background-color: #dbeafe; color: #1e40af; }
    .method-credit_card { background-color: #ede9fe; color: #5b21b6; }
    .method-check { background-color: #fef3c7; color: #92400e; }
    .method-other { background-color: #f3f4f6; color: #374151; }
    
    .payment-table th, .payment-table td {
        padding: 0.75rem 1rem;
        text-align: right;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">إدارة المدفوعات</h1>
                        <p class="text-gray-600 mt-1">إدارة وتتبع مدفوعات العملاء</p>
                    </div>
                    <div>
                        <a href="add.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة دفعة جديدة
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($success_message): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle ml-2"></i>
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                        <i class="fas fa-money-bill-wave fa-2x"></i>
                    </div>
                    <div class="mr-4">
                        <h2 class="text-gray-600 text-sm">إجمالي المدفوعات</h2>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_payments'], 0, '', ''); ?></p>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">إجمالي المبلغ</span>
                        <span class="font-medium"><?php echo number_format($stats['total_amount'], 0, '', ''); ?> ريال</span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-amber-100 text-amber-600">
                        <i class="fas fa-coins fa-2x"></i>
                    </div>
                    <div class="mr-4">
                        <h2 class="text-gray-600 text-sm">المدفوعات النقدية</h2>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['cash_amount'], 0, '', ''); ?> ريال</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                        <i class="fas fa-exchange-alt fa-2x"></i>
                    </div>
                    <div class="mr-4">
                        <h2 class="text-gray-600 text-sm">التحويلات البنكية</h2>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['transfer_amount'], 0, '', ''); ?> ريال</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                        <i class="fas fa-credit-card fa-2x"></i>
                    </div>
                    <div class="mr-4">
                        <h2 class="text-gray-600 text-sm">بطاقات الائتمان</h2>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['credit_card_amount'], 0, '', ''); ?> ريال</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900 mb-4">تصفية المدفوعات</h2>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label for="method" class="block text-sm font-medium text-gray-700 mb-1">طريقة الدفع</label>
                        <select id="method" name="method" class="form-input">
                            <option value="">جميع الطرق</option>
                            <option value="cash" <?php echo $method_filter == 'cash' ? 'selected' : ''; ?>>نقدي</option>
                            <option value="transfer" <?php echo $method_filter == 'transfer' ? 'selected' : ''; ?>>تحويل بنكي</option>
                            <option value="credit_card" <?php echo $method_filter == 'credit_card' ? 'selected' : ''; ?>>بطاقة ائتمانية</option>
                            <option value="check" <?php echo $method_filter == 'check' ? 'selected' : ''; ?>>شيك</option>
                            <option value="other" <?php echo $method_filter == 'other' ? 'selected' : ''; ?>>أخرى</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="customer_id" class="block text-sm font-medium text-gray-700 mb-1">العميل</label>
                        <select id="customer_id" name="customer_id" class="form-input">
                            <option value="">جميع العملاء</option>
                            <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>" <?php echo $customer_filter == $customer['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']) . ' (' . htmlspecialchars($customer['customer_code']) . ')'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">من تاريخ</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo $date_from; ?>" class="form-input">
                    </div>
                    
                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">إلى تاريخ</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo $date_to; ?>" class="form-input">
                    </div>
                    
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">بحث</label>
                        <div class="flex">
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="رقم الدفعة أو اسم العميل" class="form-input flex-1">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg mr-2 hover:bg-blue-700">
                                <i class="fas fa-search"></i>
                            </button>
                            <a href="index.php" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payments Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">قائمة المدفوعات</h2>
            </div>
            
            <?php if (empty($payments)): ?>
            <div class="p-6 text-center text-gray-500">
                <i class="fas fa-money-bill-wave fa-3x mb-3"></i>
                <p>لا توجد مدفوعات متطابقة مع معايير البحث</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 payment-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الدفعة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العميل</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الفاتورة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المبلغ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">طريقة الدفع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الدفع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($payments as $payment): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($payment['payment_number']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($payment['customer_name']); ?>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($payment['customer_code']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($payment['invoice_number'] ?? '-'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo number_format($payment['amount'], 0, '', ''); ?> ريال
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $method_class = '';
                                $method_text = '';
                                switch ($payment['payment_method']) {
                                    case 'cash':
                                        $method_class = 'method-cash';
                                        $method_text = 'نقدي';
                                        break;
                                    case 'transfer':
                                        $method_class = 'method-transfer';
                                        $method_text = 'تحويل بنكي';
                                        break;
                                    case 'credit_card':
                                        $method_class = 'method-credit_card';
                                        $method_text = 'بطاقة ائتمانية';
                                        break;
                                    case 'check':
                                        $method_class = 'method-check';
                                        $method_text = 'شيك';
                                        break;
                                    default:
                                        $method_class = 'method-other';
                                        $method_text = 'أخرى';
                                }
                                ?>
                                <span class="method-badge <?php echo $method_class; ?>"><?php echo $method_text; ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2 space-x-reverse">
                                    <a href="view.php?id=<?php echo $payment['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <a href="print.php?id=<?php echo $payment['id']; ?>" target="_blank" class="text-gray-600 hover:text-gray-900" title="طباعة">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    
                                    <?php if ($payment['invoice_id']): ?>
                                    <a href="../invoices/view.php?id=<?php echo $payment['invoice_id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="عرض الفاتورة">
                                        <i class="fas fa-file-invoice"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        عرض <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> إلى <?php echo min($page * $records_per_page, $total_records); ?> من أصل <?php echo $total_records; ?> دفعة
                    </div>
                    <div class="flex space-x-1 space-x-reverse">
                        <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&method=<?php echo $method_filter; ?>&customer_id=<?php echo $customer_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            السابق
                        </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&method=<?php echo $method_filter; ?>&customer_id=<?php echo $customer_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1 <?php echo $i == $page ? 'bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'; ?> border rounded-md text-sm font-medium">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&method=<?php echo $method_filter; ?>&customer_id=<?php echo $customer_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            التالي
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
