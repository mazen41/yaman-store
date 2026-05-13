<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'مجموعات الشراء';
$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action == 'add') {
            $group_name = trim($_POST['group_name']);
            $description = trim($_POST['description']);
            
            if (empty($group_name)) {
                throw new Exception('اسم المجموعة مطلوب');
            }
            
            // Generate group number
            $group_number = 'GRP-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Check if group number exists
            $check_stmt = $db->prepare("SELECT id FROM purchase_groups WHERE group_number = ?");
            $check_stmt->execute([$group_number]);
            if ($check_stmt->fetch()) {
                $group_number = 'GRP-' . date('Y') . '-' . str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
            }
            
            $stmt = $db->prepare("
                INSERT INTO purchase_groups (group_number, group_name, description, created_by) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$group_number, $group_name, $description, $_SESSION['user_id']]);
            
            $success_message = 'تم إنشاء مجموعة الشراء بنجاح. رقم المجموعة: ' . $group_number;
            
        } elseif ($action == 'edit') {
            $group_id = intval($_POST['group_id']);
            $group_name = trim($_POST['group_name']);
            $description = trim($_POST['description']);
            $status = $_POST['status'];
            
            if (empty($group_name)) {
                throw new Exception('اسم المجموعة مطلوب');
            }
            
            $stmt = $db->prepare("
                UPDATE purchase_groups 
                SET group_name = ?, description = ?, status = ? 
                WHERE id = ?
            ");
            $stmt->execute([$group_name, $description, $status, $group_id]);
            
            $success_message = 'تم تحديث مجموعة الشراء بنجاح';
            
        } elseif ($action == 'delete') {
            $group_id = intval($_POST['group_id']);
            
            // Check if group has purchase orders
            $check_stmt = $db->prepare("SELECT COUNT(*) FROM purchase_orders WHERE purchase_group_id = ?");
            $check_stmt->execute([$group_id]);
            $order_count = $check_stmt->fetchColumn();
            
            if ($order_count > 0) {
                throw new Exception('لا يمكن حذف المجموعة لأنها مرتبطة بطلبات شراء موجودة');
            }
            
            $stmt = $db->prepare("DELETE FROM purchase_groups WHERE id = ?");
            $stmt->execute([$group_id]);
            
            $success_message = 'تم حذف مجموعة الشراء بنجاح';
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Fetch purchase groups
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$sql = "
    SELECT pg.*, 
           COUNT(po.id) as order_count,
           SUM(po.total_amount) as total_amount
    FROM purchase_groups pg 
    LEFT JOIN purchase_orders po ON pg.id = po.purchase_group_id 
    WHERE 1=1
";
$params = [];

if ($search) {
    $sql .= " AND (pg.group_name LIKE ? OR pg.group_number LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param];
}

if ($status_filter) {
    $sql .= " AND pg.status = ?";
    $params[] = $status_filter;
}

$sql .= " GROUP BY pg.id ORDER BY pg.created_at DESC LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$groups = $stmt->fetchAll();

// Count total groups for pagination
$count_sql = "SELECT COUNT(*) FROM purchase_groups WHERE 1=1";
$count_params = [];

if ($search) {
    $count_sql .= " AND (group_name LIKE ? OR group_number LIKE ?)";
    $count_params = [$search_param, $search_param];
}
if ($status_filter) {
    $count_sql .= " AND status = ?";
    $count_params[] = $status_filter;
}

$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($count_params);
$total_groups = $count_stmt->fetchColumn();
$total_pages = ceil($total_groups / $limit);

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">مجموعات الشراء</h1>
                        <p class="text-gray-600 mt-1">إدارة مجموعات طلبات الشراء المترابطة</p>
                    </div>
                    <div class="mt-4 sm:mt-0 flex flex-wrap gap-3">
                        <button onclick="openAddModal()" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة مجموعة جديدة
                        </button>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للمشتريات
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Search and filters -->
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-col sm:flex-row gap-4">
                    <div class="flex-1 relative">
                        <span class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                            <i class="fas fa-search"></i>
                        </span>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="البحث في مجموعات الشراء..." 
                            value="<?php echo htmlspecialchars($search); ?>"
                            class="w-full px-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent shadow-sm transition-all duration-200"
                        >
                    </div>
                    <div>
                        <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent shadow-sm transition-all duration-200 bg-white">
                            <option value="">جميع الحالات</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>نشطة</option>
                            <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>مكتملة</option>
                            <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>ملغية</option>
                        </select>
                    </div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors duration-200">
                        <i class="fas fa-filter ml-2"></i>بحث
                    </button>
                    <?php if ($search || $status_filter): ?>
                    <a href="groups.php" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200">
                        <i class="fas fa-times ml-2"></i>إلغاء
                    </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-check-circle ml-2"></i>
                <?php echo $success_message; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle ml-2"></i>
                <?php echo $error_message; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Groups Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم المجموعة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">اسم المجموعة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الوصف</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">عدد الطلبات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">إجمالي المبلغ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الإنشاء</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($groups)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-layer-group text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد مجموعات شراء</p>
                                <button onclick="openAddModal()" class="text-purple-600 hover:text-purple-800 mt-2 inline-block">
                                    إضافة مجموعة جديدة
                                </button>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($groups as $group): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($group['group_number']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div class="font-medium"><?php echo htmlspecialchars($group['group_name']); ?></div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div class="max-w-xs truncate">
                                    <?php echo htmlspecialchars($group['description'] ?: '-'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <?php echo $group['order_count']; ?> طلب
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo number_format($group['total_amount'] ?: 0, 0, '', ''); ?> ر.ي
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php
                                $status_colors = [
                                    'active' => 'bg-amber-100 text-amber-800',
                                    'completed' => 'bg-blue-100 text-blue-800',
                                    'cancelled' => 'bg-red-100 text-red-800'
                                ];
                                $status_labels = [
                                    'active' => 'نشطة',
                                    'completed' => 'مكتملة',
                                    'cancelled' => 'ملغية'
                                ];
                                ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_colors[$group['status']]; ?>">
                                    <?php echo $status_labels[$group['status']]; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('d/m/Y', strtotime($group['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2 space-x-reverse">
                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($group)); ?>)" 
                                            class="w-8 h-8 flex items-center justify-center bg-amber-100 text-amber-600 rounded-full hover:bg-amber-200 transition-all duration-200 transform hover:scale-110" 
                                            title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($group['order_count'] == 0): ?>
                                    <button onclick="confirmDelete(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['group_name']); ?>')" 
                                            class="w-8 h-8 flex items-center justify-center bg-red-100 text-red-600 rounded-full hover:bg-red-200 transition-all duration-200 transform hover:scale-110" 
                                            title="حذف">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
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
                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-gray-700">
                            عرض
                            <span class="font-medium"><?php echo $offset + 1; ?></span>
                            إلى
                            <span class="font-medium"><?php echo min($offset + $limit, $total_groups); ?></span>
                            من
                            <span class="font-medium"><?php echo $total_groups; ?></span>
                            مجموعة
                        </p>
                    </div>
                    <div>
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                               class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?php echo $i == $page ? 'z-10 bg-purple-50 border-purple-500 text-purple-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                        </nav>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="groupModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 id="modalTitle" class="text-lg font-medium text-gray-900">إضافة مجموعة شراء جديدة</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="groupForm" method="POST" class="space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="group_id" id="groupId">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">اسم المجموعة <span class="text-red-500">*</span></label>
                    <input type="text" name="group_name" id="groupName" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">الوصف</label>
                    <textarea name="description" id="groupDescription" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"></textarea>
                </div>
                
                <div id="statusField" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2">الحالة</label>
                    <select name="status" id="groupStatus" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        <option value="active">نشطة</option>
                        <option value="completed">مكتملة</option>
                        <option value="cancelled">ملغية</option>
                    </select>
                </div>
                
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-4">
                    <button type="button" onclick="closeModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors duration-200">
                        إلغاء
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors duration-200">
                        <i class="fas fa-save ml-2"></i>
                        حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                <i class="fas fa-exclamation-triangle text-red-600"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mt-4">تأكيد الحذف</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500" id="deleteMessage">
                    هل أنت متأكد من حذف هذه المجموعة؟
                </p>
            </div>
            <div class="items-center px-4 py-3">
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="group_id" id="deleteGroupId">
                    <button type="button" onclick="closeDeleteModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-800 text-base font-medium rounded-md shadow-sm hover:bg-gray-400 ml-3">
                        إلغاء
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-red-600 text-white text-base font-medium rounded-md shadow-sm hover:bg-red-700">
                        حذف
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'إضافة مجموعة شراء جديدة';
    document.getElementById('formAction').value = 'add';
    document.getElementById('groupId').value = '';
    document.getElementById('groupName').value = '';
    document.getElementById('groupDescription').value = '';
    document.getElementById('statusField').classList.add('hidden');
    document.getElementById('groupModal').classList.remove('hidden');
}

function openEditModal(group) {
    document.getElementById('modalTitle').textContent = 'تعديل مجموعة الشراء';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('groupId').value = group.id;
    document.getElementById('groupName').value = group.group_name;
    document.getElementById('groupDescription').value = group.description || '';
    document.getElementById('groupStatus').value = group.status;
    document.getElementById('statusField').classList.remove('hidden');
    document.getElementById('groupModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('groupModal').classList.add('hidden');
}

function confirmDelete(groupId, groupName) {
    document.getElementById('deleteGroupId').value = groupId;
    document.getElementById('deleteMessage').textContent = 
        `هل أنت متأكد من حذف مجموعة "${groupName}"؟ لا يمكن التراجع عن هذا الإجراء.`;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

// Close modals when clicking outside
document.getElementById('groupModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
