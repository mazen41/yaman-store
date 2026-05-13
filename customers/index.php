<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/phone_utils.php';

$page_title = 'إدارة العملاء';

// Handle delete action (sets customer to inactive)
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    try {
        $stmt = $db->prepare("UPDATE customers SET is_active = 0 WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $success_message = 'تم حذف العميل بنجاح';
    } catch (PDOException $e) {
        $error_message = 'حدث خطأ أثناء الحذف';
    }
}

// Handle toggle active action
if (isset($_GET['action']) && $_GET['action'] == 'toggle_active' && isset($_GET['id'])) {
    try {
        // Determine the new status by reversing the current one
        $current_status = $_GET['status'] ?? 0;
        $new_status = $current_status == 1 ? 0 : 1;
        
        $stmt = $db->prepare("UPDATE customers SET is_active = ? WHERE id = ?");
        $stmt->execute([$new_status, $_GET['id']]);
        
        $success_message = $new_status == 1 ? 'تم تفعيل العميل بنجاح' : 'تم تعطيل العميل بنجاح';
    } catch (PDOException $e) {
        $error_message = 'حدث خطأ أثناء تحديث حالة العميل';
    }
}

// NEW: Handle toggle allow_no_deposit_orders action
if (isset($_GET['action']) && $_GET['action'] == 'toggle_no_deposit_orders' && isset($_GET['id'])) {
    try {
        $current_status = $_GET['status'] ?? 0;
        $new_status = $current_status == 1 ? 0 : 1;
        
        $stmt = $db->prepare("UPDATE customers SET allow_no_deposit_orders = ? WHERE id = ?");
        $stmt->execute([$new_status, $_GET['id']]);
        
        $success_message = $new_status == 1 ? 'تم السماح للعميل بإنشاء طلبات بدون دفعة مقدمة.' : 'تم إلزام العميل بالدفعة المقدمة عند إنشاء الطلبات.';
    } catch (PDOException $e) {
        $error_message = 'حدث خطأ أثناء تحديث صلاحية الدفعة المقدمة للعميل.';
    }
}


// Fetch customers
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// MODIFIED: Updated SQL query to select the new column
$sql = "SELECT 
            c.*, 
            COUNT(DISTINCT co.id) AS total_orders, 
            SUM(ci.total_amount) AS total_invoices_amount
        FROM 
            customers c
        LEFT JOIN 
            customer_orders co ON c.id = co.customer_id
        LEFT JOIN 
            customer_invoices ci ON c.id = ci.customer_id
        WHERE 
            c.is_active = 1";
$params = [];

if ($search) {
    $sql .= " AND (c.name LIKE ? OR c.customer_code LIKE ? OR c.phone LIKE ? OR c.mobile_number LIKE ? OR c.whatsapp_number LIKE ? OR c.alternative_number LIKE ? OR c.email LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param];
}

$sql .= " GROUP BY c.id ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Count total customers for pagination
$count_sql = "SELECT COUNT(*) FROM customers WHERE is_active = 1";
if ($search) {
    $count_sql .= " AND (name LIKE ? OR customer_code LIKE ? OR phone LIKE ? OR mobile_number LIKE ? OR whatsapp_number LIKE ? OR alternative_number LIKE ? OR email LIKE ?)";
}
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($search ? [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param] : []);
$total_customers = $count_stmt->fetchColumn();
$total_pages = ceil($total_customers / $limit);

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">إدارة العملاء</h1>
                        <p class="text-gray-600 mt-1">إدارة بيانات العملاء والمشتريات</p>
                    </div>
                    <div class="mt-4 sm:mt-0">
                        <a href="add.php" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            عميل جديد
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Search and filters -->
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-col sm:flex-row gap-4">
                    <div class="flex-1">
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="البحث في العملاء..." 
                            value="<?php echo htmlspecialchars($search); ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                    </div>
                    <button type="submit" class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                        <i class="fas fa-search ml-2"></i>بحث
                    </button>
                    <?php if ($search): ?>
                    <a href="index.php" class="px-6 py-2 bg-gray-400 text-white rounded-lg hover:bg-gray-500 transition duration-200">
                        <i class="fas fa-times ml-2"></i>إلغاء
                    </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (isset($success_message)): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle ml-2"></i>
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <!-- Customers Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">العمليات</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">السماح بدون دفعة</th> <!-- NEW HEADER -->
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الإضافة</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">إجمالي الفواتير</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">إجمالي الطلبات</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الفئة</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الواتساب</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الجوال</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الهاتف</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">اسم العميل</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">رقم العميل</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($customers)): ?>
                        <tr>
                            <!-- MODIFIED: Colspan updated to 11 (original 10 + 1 new column) -->
                            <td colspan="11" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-users text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد عملاء مسجلين</p>
                                <a href="add.php" class="text-blue-600 hover:text-blue-800 mt-2 inline-block">
                                    إضافة عميل جديد
                                </a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-center">
                                <div class="flex justify-center items-center space-x-2 space-x-reverse">
                                    <a href="view_enhanced.php?id=<?php echo $customer['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $customer['id']; ?>" class="text-amber-600 hover:text-amber-900" title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?action=delete&id=<?php echo $customer['id']; ?>" 
                                       class="text-red-600 hover:text-red-900" 
                                       title="حذف"
                                       onclick="return confirm('هل أنت متأكد من حذف هذا العميل؟')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <!-- Toggle Active Button -->
                                    <a href="?action=toggle_active&id=<?php echo $customer['id']; ?>&status=<?php echo $customer['is_active']; ?>" 
                                       class="<?php echo $customer['is_active'] ? 'text-yellow-500 hover:text-yellow-700' : 'text-gray-400 hover:text-gray-600'; ?>" 
                                       title="<?php echo $customer['is_active'] ? 'تعطيل العميل' : 'تفعيل العميل'; ?>"
                                       onclick="return confirm('هل أنت متأكد من تغيير حالة هذا العميل؟')">
                                        <i class="fas fa-power-off"></i>
                                    </a>
                                </div>
                            </td>
                            <!-- NEW: Toggle Allow No Deposit Orders Button -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-center">
                                <a href="?action=toggle_no_deposit_orders&id=<?php echo $customer['id']; ?>&status=<?php echo $customer['allow_no_deposit_orders']; ?>" 
                                   class="inline-flex items-center <?php echo $customer['allow_no_deposit_orders'] ? 'text-green-600 bg-green-100 hover:bg-green-200' : 'text-gray-600 bg-gray-100 hover:bg-gray-200'; ?> px-3 py-1 rounded-full text-xs font-semibold transition"
                                   title="<?php echo $customer['allow_no_deposit_orders'] ? 'مسموح بدون دفعة مقدمة' : 'مطلوب دفعة مقدمة'; ?>"
                                   onclick="return confirm('هل أنت متأكد من تغيير صلاحية الدفعة المقدمة لهذا العميل؟')">
                                    <i class="fas fa-money-check-alt ml-2"></i>
                                    <?php echo $customer['allow_no_deposit_orders'] ? 'مسموح' : 'مطلوب'; ?>
                                </a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                <?php echo date('d/m/Y', strtotime($customer['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                <?php echo number_format($customer['total_invoices_amount'] ?? 0, 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                <?php echo $customer['total_orders']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php 
                                    if ($customer['customer_type'] == 'company') {
                                        echo 'bg-blue-100 text-blue-800';
                                    } elseif ($customer['customer_type'] == 'delegate') {
                                        echo 'bg-purple-100 text-purple-800';
                                    } else {
                                        echo 'bg-amber-100 text-amber-800';
                                    }
                                ?>">
                                    <?php 
                                    if ($customer['customer_type'] == 'company') {
                                        echo 'شركة';
                                    } elseif ($customer['customer_type'] == 'delegate') {
                                        echo 'مندوب';
                                    } else {
                                        echo 'فرد';
                                    }
                                    ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 phone">
                                <?php echo !empty($customer['whatsapp_number']) ? getPhoneLink($customer['whatsapp_number'], true) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 phone">
                                <?php echo !empty($customer['mobile_number']) ? getPhoneLink($customer['mobile_number'], false) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 phone">
                                <?php echo !empty($customer['phone']) ? formatYemenPhone($customer['phone']) : '-'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-center">
                                <?php echo htmlspecialchars($customer['customer_code']); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                <div class="flex-1 flex justify-between sm:hidden">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        السابق
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="mr-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        التالي
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-gray-700">
                            عرض
                            <span class="font-medium"><?php echo $offset + 1; ?></span>
                            إلى
                            <span class="font-medium"><?php echo min($offset + $limit, $total_customers); ?></span>
                            من
                            <span class="font-medium"><?php echo $total_customers; ?></span>
                            عميل
                        </p>
                    </div>
                    <div>
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?php echo $i == $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                        </nav>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Summary Cards -->
        <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 bg-amber-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-xl text-amber-600"></i>
                            </div>
                        </div>
                        <div class="mr-4 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">إجمالي العملاء الأفراد</dt>
                                <dd class="text-2xl font-bold text-gray-900">
                                    <?php
                                    $individual_stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_type = 'individual' AND is_active = 1");
                                    $individual_stmt->execute();
                                    echo $individual_stmt->fetchColumn();
                                    ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-building text-xl text-blue-600"></i>
                            </div>
                        </div>
                        <div class="mr-4 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">عملاء الشركات</dt>
                                <dd class="text-2xl font-bold text-gray-900">
                                    <?php
                                    $company_stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_type = 'company' AND is_active = 1");
                                    $company_stmt->execute();
                                    echo $company_stmt->fetchColumn();
                                    ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-file-invoice text-xl text-orange-600"></i>
                            </div>
                        </div>
                        <div class="mr-4 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">الشركات</dt>
                                <dd class="text-2xl font-bold text-gray-900">0</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-check text-xl text-purple-600"></i>
                            </div>
                        </div>
                        <div class="mr-4 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">المندوبين</dt>
                                <dd class="text-2xl font-bold text-gray-900">
                                    <?php
                                    $delegate_stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_type = 'delegate' AND is_active = 1");
                                    $delegate_stmt->execute();
                                    echo $delegate_stmt->fetchColumn();
                                    ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>