<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'قائمة الطلبات';
$error_message = '';
$success_message = '';

// Check for success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case '1':
            $success_message = 'تم إنشاء الطلب بنجاح';
            break;
        case '2':
            $success_message = 'تم تحديث الطلب بنجاح';
            break;
        case '3':
            $success_message = 'تم حذف الطلب بنجاح';
            break;
    }
}

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

// Build query with proper field handling
$query = "SELECT o.id, o.order_number, o.customer_id, o.status, o.total_amount, o.subtotal_amount, 
                 o.discount_amount, o.final_amount, o.shipping_cost, o.created_at, o.requires_approval,
                 c.name as customer_name, c.customer_code, c.mobile_number, c.whatsapp_number 
          FROM customer_orders o 
          LEFT JOIN customers c ON o.customer_id = c.id 
          WHERE 1=1";
$params = [];

if ($status_filter) {
    $query .= " AND o.status = ?";
    $params[] = $status_filter;
}

if ($customer_filter) {
    $query .= " AND o.customer_id = ?";
    $params[] = $customer_filter;
}

if ($date_from) {
    $query .= " AND DATE(o.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

if ($search) {
    $query .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.customer_code LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// Get total count for pagination
$count_query = str_replace("SELECT o.*, c.name as customer_name, c.customer_code, c.mobile_number, c.whatsapp_number", "SELECT COUNT(*) as total", $query);
try {
    $stmt = $db->prepare($count_query);
    $stmt->execute($params);
    $total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {
    // If table doesn't exist yet
    $total_records = 0;
}
$total_pages = ceil($total_records / $records_per_page);

// Add sorting and pagination to the main query
$query .= " ORDER BY o.created_at DESC LIMIT $offset, $records_per_page";

// Execute the main query
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If table doesn't exist yet
    $orders = [];
}

// Get customers for filter dropdown
$customers_stmt = $db->prepare("SELECT id, name, customer_code FROM customers WHERE is_active = 1 ORDER BY name");
$customers_stmt->execute();
$customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    .status-new { background-color: #dbeafe; color: #1e40af; }
    .status-processing { background-color: #fef3c7; color: #92400e; }
    .status-completed { background-color: #d1fae5; color: #065f46; }
    .status-cancelled { background-color: #fee2e2; color: #b91c1c; }
    
    .order-table th, .order-table td {
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
    
    .case-highlight { 
        border: 2px solid #000; 
        border-radius: 0.5rem; 
        padding: 0.5rem; 
        position: relative; 
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">إدارة طلبات العملاء</h1>
                        <p class="text-gray-600 mt-1">إنشاء وإدارة طلبات العملاء</p>
                    </div>
                    <div>
                        <a href="create.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            إنشاء طلب جديد
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

        <!-- Filters -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <h2 class="text-lg font-medium text-gray-900 mb-4">تصفية الطلبات</h2>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                        <select id="status" name="status" class="form-input">
                            <option value="">جميع الحالات</option>
                            <option value="new" <?php echo $status_filter == 'new' ? 'selected' : ''; ?>>جديد</option>
                            <option value="processing" <?php echo $status_filter == 'processing' ? 'selected' : ''; ?>>قيد المعالجة</option>
                            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>مكتمل</option>
                            <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>ملغي</option>
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
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="رقم الطلب أو اسم العميل" class="form-input flex-1">
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

        <!-- Orders Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">قائمة الطلبات</h2>
            </div>
            
            <?php if (empty($orders)): ?>
            <div class="p-6 text-center text-gray-500">
                <i class="fas fa-shopping-cart fa-3x mb-3"></i>
                <p>لا توجد طلبات متطابقة مع معايير البحث</p>
                <p class="mt-2">يمكنك إنشاء طلب جديد من خلال الضغط على زر "إنشاء طلب جديد"</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 order-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العميل</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">عدد المنتجات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجمالي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التاريخ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($orders as $order): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($order['order_number']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($order['customer_name']); ?>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($order['customer_code']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo $order['items_count'] ?? '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo number_format($order['total_amount'], 2); ?> ريال
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $status_class = '';
                                $status_text = '';
                                switch ($order['status']) {
                                    case 'new':
                                        $status_class = 'status-new';
                                        $status_text = 'جديد';
                                        break;
                                    case 'processing':
                                        $status_class = 'status-processing';
                                        $status_text = 'قيد المعالجة';
                                        break;
                                    case 'completed':
                                        $status_class = 'status-completed';
                                        $status_text = 'مكتمل';
                                        break;
                                    case 'cancelled':
                                        $status_class = 'status-cancelled';
                                        $status_text = 'ملغي';
                                        break;
                                }
                                ?>
                                <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('Y-m-d', strtotime($order['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2 space-x-reverse">
                                    <a href="view.php?id=<?php echo $order['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <?php if ($order['status'] == 'new'): ?>
                                    <a href="edit.php?id=<?php echo $order['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <a href="print.php?id=<?php echo $order['id']; ?>" target="_blank" class="text-gray-600 hover:text-gray-900" title="طباعة">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    
                                    <?php if ($order['status'] != 'cancelled' && $order['status'] != 'completed'): ?>
                                    <div class="relative group">
                                        <button type="button" class="text-amber-600 hover:text-amber-900" title="إرسال">
                                            <i class="fas fa-paper-plane"></i>
                                        </button>
                                        <div class="absolute hidden group-hover:block left-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10">
                                            <div class="py-1">
                                                <?php if (!empty($order['whatsapp_number'])): ?>
                                                <a href="send.php?id=<?php echo $order['id']; ?>&method=whatsapp" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                    <i class="fab fa-whatsapp text-amber-500 ml-1"></i> إرسال عبر واتساب
                                                </a>
                                                <?php endif; ?>
                                                
                                                <a href="send.php?id=<?php echo $order['id']; ?>&method=email" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                    <i class="fas fa-envelope text-blue-500 ml-1"></i> إرسال عبر البريد الإلكتروني
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($order['status'] == 'new'): ?>
                                    <a href="delete.php?id=<?php echo $order['id']; ?>" class="text-red-600 hover:text-red-900" title="حذف" onclick="return confirm('هل أنت متأكد من حذف هذا الطلب؟');">
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
                        عرض <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> إلى <?php echo min($page * $records_per_page, $total_records); ?> من أصل <?php echo $total_records; ?> طلب
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
