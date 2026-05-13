<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة الفواتير';
$error_message = '';
$success_message = '';

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$customer_filter = $_GET['customer_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

// Build query
$query = "SELECT i.*, c.name as customer_name, c.customer_code, c.mobile_number, c.whatsapp_number, co.order_number 
          FROM customer_invoices i 
          LEFT JOIN customers c ON i.customer_id = c.id 
          LEFT JOIN customer_orders co ON i.order_id = co.id 
          WHERE 1=1";
$params = [];

if ($status_filter) {
    $query .= " AND i.status = ?";
    $params[] = $status_filter;
}

if ($customer_filter) {
    $query .= " AND i.customer_id = ?";
    $params[] = $customer_filter;
}

if ($date_from) {
    $query .= " AND DATE(i.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(i.created_at) <= ?";
    $params[] = $date_to;
}

if ($search) {
    $query .= " AND (i.invoice_number LIKE ? OR c.name LIKE ? OR c.customer_code LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// Get total count for pagination
$count_query = str_replace("SELECT i.*, c.name as customer_name, c.customer_code, c.mobile_number, c.whatsapp_number", "SELECT COUNT(*) as total", $query);
$stmt = $db->prepare($count_query);
$stmt->execute($params);
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Add sorting and pagination to the main query
$query .= " ORDER BY i.created_at DESC LIMIT $offset, $records_per_page";

// Execute the main query
$stmt = $db->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get customers for filter dropdown
$customers_stmt = $db->prepare("SELECT id, name, customer_code FROM customers WHERE is_active = 1 ORDER BY name");
$customers_stmt->execute();
$customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get invoice statistics
$stats_query = "SELECT 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid_count,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                SUM(total_amount) as total_amount,
                SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as paid_amount
                FROM customer_invoices";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<style>
    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    .status-pending { background-color: #dbeafe; color: #1e40af; }
    .status-partially_paid { background-color: #fef3c7; color: #92400e; }
    .status-paid { background-color: #d1fae5; color: #065f46; }
    .status-cancelled { background-color: #fee2e2; color: #b91c1c; }
    .status-overdue { background-color: #fee2e2; color: #b91c1c; }
    
    .invoice-table th, .invoice-table td {
        padding: 0.75rem 1rem;
        text-align: right;
    }
    
    .action-button {
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        transition: all 0.2s;
    }
    
    .btn-whatsapp {
        background-color: #25D366;
        color: white;
    }
    
    .btn-whatsapp:hover {
        background-color: #128C7E;
    }
    
    .btn-email {
        background-color: #4A7AFF;
        color: white;
    }
    
    .btn-email:hover {
        background-color: #3A5FCC;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">إدارة الفواتير</h1>
                        <p class="text-gray-600 mt-1">إدارة وإنشاء وإرسال الفواتير للعملاء</p>
                    </div>
                    <div>
                        <a href="create.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            إنشاء فاتورة جديدة
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
                        <i class="fas fa-file-invoice fa-2x"></i>
                    </div>
                    <div class="mr-4">
                        <h2 class="text-gray-600 text-sm">إجمالي الفواتير</h2>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_invoices'], 0, '', ''); ?></p>
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
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                    <div class="mr-4">
                        <h2 class="text-gray-600 text-sm">الفواتير المدفوعة</h2>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['paid_count'], 0, '', ''); ?></p>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">إجمالي المبلغ</span>
                        <span class="font-medium"><?php echo number_format($stats['paid_amount'], 0, '', ''); ?> ريال</span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                        <i class="fas fa-paper-plane fa-2x"></i>
                    </div>
                    <div class="mr-4">
                        <h2 class="text-gray-600 text-sm">الفواتير المرسلة</h2>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['sent_count'], 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-gray-100 text-gray-600">
                        <i class="fas fa-file-alt fa-2x"></i>
                    </div>
                    <div class="mr-4">
                        <h2 class="text-gray-600 text-sm">المسودات</h2>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['draft_count'], 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900 mb-4">تصفية الفواتير</h2>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                        <select id="status" name="status" class="form-input">
                            <option value="">جميع الحالات</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                            <option value="partially_paid" <?php echo $status_filter == 'partially_paid' ? 'selected' : ''; ?>>مدفوعة جزئياً</option>
                            <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>مدفوعة</option>
                            <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>ملغاة</option>
                            <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>متأخرة</option>
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
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="رقم الفاتورة أو اسم العميل" class="form-input flex-1">
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

        <!-- Invoices Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">قائمة الفواتير</h2>
            </div>
            
            <?php if (empty($invoices)): ?>
            <div class="p-6 text-center text-gray-500">
                <i class="fas fa-file-invoice fa-3x mb-3"></i>
                <p>لا توجد فواتير متطابقة مع معايير البحث</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 invoice-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الفاتورة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العميل</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المبلغ الأساسي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الضريبة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجمالي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التاريخ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($invoices as $invoice): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($invoice['invoice_number']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($invoice['customer_name']); ?>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($invoice['customer_code']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo number_format($invoice['amount'], 0, '', ''); ?> ريال
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo number_format($invoice['tax_amount'], 0, '', ''); ?> ريال
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo number_format($invoice['total_amount'], 0, '', ''); ?> ريال
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $status_class = '';
                                $status_text = '';
                                switch ($invoice['status']) {
                                    case 'pending':
                                        $status_class = 'status-pending';
                                        $status_text = 'قيد الانتظار';
                                        break;
                                    case 'partially_paid':
                                        $status_class = 'status-partially_paid';
                                        $status_text = 'مدفوعة جزئياً';
                                        break;
                                    case 'paid':
                                        $status_class = 'status-paid';
                                        $status_text = 'مدفوعة';
                                        break;
                                    case 'cancelled':
                                        $status_class = 'status-cancelled';
                                        $status_text = 'ملغاة';
                                        break;
                                    case 'overdue':
                                        $status_class = 'status-overdue';
                                        $status_text = 'متأخرة';
                                        break;
                                }
                                ?>
                                <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('Y-m-d', strtotime($invoice['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2 space-x-reverse">
                                    <a href="view.php?id=<?php echo $invoice['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <?php if ($invoice['status'] == 'pending'): ?>
                                    <a href="edit.php?id=<?php echo $invoice['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <a href="print.php?id=<?php echo $invoice['id']; ?>" target="_blank" class="text-gray-600 hover:text-gray-900" title="طباعة">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    
                                    <?php if (!empty($invoice['whatsapp_number']) || !empty($invoice['mobile_number'])): ?>
                                    <a href="../whatsapp/send.php?customer_id=<?php echo $invoice['customer_id']; ?>&invoice_id=<?php echo $invoice['id']; ?>" 
                                       class="text-amber-600 hover:text-amber-900" 
                                       title="إرسال واتساب">
                                        <i class="fab fa-whatsapp"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($invoice['status'] != 'cancelled'): ?>
                                    <div class="relative group">
                                        <button type="button" class="text-purple-600 hover:text-purple-900" title="المزيد">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="absolute hidden group-hover:block left-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10">
                                            <div class="py-1">
                                                
                                                <a href="send.php?id=<?php echo $invoice['id']; ?>&method=email" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                    <i class="fas fa-envelope text-blue-500 ml-1"></i> إرسال عبر البريد الإلكتروني
                                                </a>
                                                
                                                <a href="send.php?id=<?php echo $invoice['id']; ?>&method=manual" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                    <i class="fas fa-hand-paper text-orange-500 ml-1"></i> إرسال يدوي
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($invoice['status'] == 'pending'): ?>
                                    <a href="delete.php?id=<?php echo $invoice['id']; ?>" class="text-red-600 hover:text-red-900" title="حذف" onclick="return confirm('هل أنت متأكد من حذف هذه الفاتورة؟');">
                                        <i class="fas fa-trash"></i>
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
                        عرض <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> إلى <?php echo min($page * $records_per_page, $total_records); ?> من أصل <?php echo $total_records; ?> فاتورة
                    </div>
                    <div class="flex space-x-1 space-x-reverse">
                        <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&customer_id=<?php echo $customer_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            السابق
                        </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&customer_id=<?php echo $customer_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1 <?php echo $i == $page ? 'bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'; ?> border rounded-md text-sm font-medium">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&customer_id=<?php echo $customer_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
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
