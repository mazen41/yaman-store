<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'حركات المخزون';

// Check and update stock_movements table structure
try {
    // Check if the table has the required columns
    $columns = $db->query("DESCRIBE stock_movements")->fetchAll();
    $column_names = [];
    foreach ($columns as $column) {
        $column_names[] = $column['Field'];
    }
    
    // Add missing columns if they don't exist
    if (!in_array('previous_quantity', $column_names)) {
        $db->exec("ALTER TABLE stock_movements ADD COLUMN previous_quantity INT DEFAULT 0");
    }
    if (!in_array('new_quantity', $column_names)) {
        $db->exec("ALTER TABLE stock_movements ADD COLUMN new_quantity INT DEFAULT 0");
    }
    if (!in_array('reason', $column_names)) {
        $db->exec("ALTER TABLE stock_movements ADD COLUMN reason VARCHAR(255)");
    }
    if (!in_array('reference_number', $column_names)) {
        $db->exec("ALTER TABLE stock_movements ADD COLUMN reference_number VARCHAR(100)");
    }
} catch (PDOException $e) {
    // Continue if there are issues with table structure
}

// Handle new stock movement
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_movement') {
    try {
        $product_id = intval($_POST['product_id']);
        $movement_type = $_POST['movement_type'];
        $quantity = intval($_POST['quantity']);
        $reason = trim($_POST['reason']);
        $reference_number = trim($_POST['reference_number']);
        $notes = trim($_POST['notes']);
        
        if ($product_id <= 0 || $quantity <= 0) {
            throw new Exception('بيانات غير صحيحة');
        }
        
        // Get current stock
        $product_stmt = $db->prepare("SELECT name, current_stock FROM products WHERE id = ?");
        $product_stmt->execute([$product_id]);
        $product = $product_stmt->fetch();
        
        if (!$product) {
            throw new Exception('المنتج غير موجود');
        }
        
        $previous_quantity = $product['current_stock'];
        
        // Calculate new quantity based on movement type
        switch ($movement_type) {
            case 'in':
                $new_quantity = $previous_quantity + $quantity;
                break;
            case 'out':
                $new_quantity = $previous_quantity - $quantity;
                if ($new_quantity < 0) {
                    throw new Exception('الكمية المطلوبة أكبر من المخزون المتاح');
                }
                break;
            case 'adjustment':
                $new_quantity = $quantity;
                $quantity = $new_quantity - $previous_quantity; // Adjustment amount
                break;
            default:
                throw new Exception('نوع الحركة غير صحيح');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Update product stock
        $update_stmt = $db->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
        $update_stmt->execute([$new_quantity, $product_id]);
        
        // Record movement
        $movement_stmt = $db->prepare("
            INSERT INTO stock_movements 
            (product_id, movement_type, quantity, previous_quantity, new_quantity, reason, reference_number, notes, created_by, movement_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $movement_stmt->execute([
            $product_id,
            $movement_type,
            abs($quantity),
            $previous_quantity,
            $new_quantity,
            $reason,
            $reference_number,
            $notes,
            $_SESSION['user_id']
        ]);
        
        $db->commit();
        $success_message = 'تم تسجيل حركة المخزون بنجاح';
        
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch stock movements
$search = $_GET['search'] ?? '';
$movement_filter = $_GET['movement_type'] ?? '';
$product_filter = $_GET['product_id'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$sql = "
    SELECT sm.*, p.name as product_name, p.product_code 
    FROM stock_movements sm 
    JOIN products p ON sm.product_id = p.id 
    WHERE 1=1
";
$params = [];

if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.product_code LIKE ? OR sm.reference_number LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param, $search_param];
}

if ($movement_filter) {
    $sql .= " AND sm.movement_type = ?";
    $params[] = $movement_filter;
}

if ($product_filter) {
    $sql .= " AND sm.product_id = ?";
    $params[] = $product_filter;
}

$sql .= " ORDER BY sm.movement_date DESC LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Get products for dropdown
$products_stmt = $db->prepare("SELECT id, name, product_code, current_stock FROM products WHERE is_active = 1 ORDER BY name");
$products_stmt->execute();
$products = $products_stmt->fetchAll();

// Count total movements for pagination
$count_sql = "
    SELECT COUNT(*) 
    FROM stock_movements sm 
    JOIN products p ON sm.product_id = p.id 
    WHERE 1=1
";
$count_params = [];

if ($search) {
    $count_sql .= " AND (p.name LIKE ? OR p.product_code LIKE ? OR sm.reference_number LIKE ?)";
    $count_params = [$search_param, $search_param, $search_param];
}
if ($movement_filter) {
    $count_sql .= " AND sm.movement_type = ?";
    $count_params[] = $movement_filter;
}
if ($product_filter) {
    $count_sql .= " AND sm.product_id = ?";
    $count_params[] = $product_filter;
}

$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($count_params);
$total_movements = $count_stmt->fetchColumn();
$total_pages = ceil($total_movements / $limit);

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">حركات المخزون</h1>
                        <p class="text-gray-600 mt-1">تتبع جميع حركات دخول وخروج المخزون</p>
                    </div>
                    <div class="mt-4 sm:mt-0 flex flex-wrap gap-3">
                        <button onclick="openMovementModal()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة حركة مخزون
                        </button>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للمخزون
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
                            placeholder="البحث في الحركات..." 
                            value="<?php echo htmlspecialchars($search); ?>"
                            class="w-full px-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200"
                        >
                    </div>
                    <div>
                        <select name="movement_type" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200 bg-white">
                            <option value="">جميع الحركات</option>
                            <option value="in" <?php echo $movement_filter == 'in' ? 'selected' : ''; ?>>دخول مخزون</option>
                            <option value="out" <?php echo $movement_filter == 'out' ? 'selected' : ''; ?>>خروج مخزون</option>
                            <option value="adjustment" <?php echo $movement_filter == 'adjustment' ? 'selected' : ''; ?>>تعديل مخزون</option>
                        </select>
                    </div>
                    <div>
                        <select name="product_id" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200 bg-white">
                            <option value="">جميع المنتجات</option>
                            <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>" <?php echo $product_filter == $product['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($product['name']); ?> (<?php echo htmlspecialchars($product['product_code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-filter ml-2"></i>بحث
                    </button>
                    <?php if ($search || $movement_filter || $product_filter): ?>
                    <a href="stock_movement.php" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200">
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

        <!-- Movements Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التاريخ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المنتج</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نوع الحركة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الكمية</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المخزون السابق</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المخزون الجديد</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">السبب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم المرجع</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($movements)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-exchange-alt text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد حركات مخزون</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($movements as $movement): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('Y-m-d H:i', strtotime($movement['movement_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div>
                                    <div class="font-medium"><?php echo htmlspecialchars($movement['product_name']); ?></div>
                                    <div class="text-gray-500"><?php echo htmlspecialchars($movement['product_code']); ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php
                                $type_class = '';
                                $type_text = '';
                                $type_icon = '';
                                
                                switch ($movement['movement_type']) {
                                    case 'in':
                                        $type_class = 'bg-amber-100 text-amber-800';
                                        $type_text = 'دخول مخزون';
                                        $type_icon = 'fa-arrow-down';
                                        break;
                                    case 'out':
                                        $type_class = 'bg-red-100 text-red-800';
                                        $type_text = 'خروج مخزون';
                                        $type_icon = 'fa-arrow-up';
                                        break;
                                    case 'adjustment':
                                        $type_class = 'bg-blue-100 text-blue-800';
                                        $type_text = 'تعديل مخزون';
                                        $type_icon = 'fa-edit';
                                        break;
                                }
                                ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_class; ?>">
                                    <i class="fas <?php echo $type_icon; ?> ml-1"></i>
                                    <?php echo $type_text; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php 
                                $sign = $movement['movement_type'] == 'out' ? '-' : '+';
                                echo $sign . $movement['quantity']; 
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo $movement['previous_quantity']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo $movement['new_quantity']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($movement['reason'] ?: '-'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($movement['reference_number'] ?: '-'); ?>
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
                            <span class="font-medium"><?php echo min($offset + $limit, $total_movements); ?></span>
                            من
                            <span class="font-medium"><?php echo $total_movements; ?></span>
                            حركة
                        </p>
                    </div>
                    <div>
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&movement_type=<?php echo urlencode($movement_filter); ?>&product_id=<?php echo urlencode($product_filter); ?>" 
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
    </div>
</div>

<!-- Movement Modal -->
<div id="movementModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900">إضافة حركة مخزون</h3>
                <button onclick="closeMovementModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_movement">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">المنتج</label>
                    <select name="product_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">اختر المنتج</option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>">
                            <?php echo htmlspecialchars($product['name']); ?> (المخزون الحالي: <?php echo $product['current_stock']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">نوع الحركة</label>
                    <select name="movement_type" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">اختر نوع الحركة</option>
                        <option value="in">دخول مخزون</option>
                        <option value="out">خروج مخزون</option>
                        <option value="adjustment">تعديل مخزون</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">الكمية</label>
                    <input type="number" name="quantity" min="1" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">السبب</label>
                    <input type="text" name="reason" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="سبب الحركة">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">رقم المرجع</label>
                    <input type="text" name="reference_number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="رقم الفاتورة أو المرجع">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ملاحظات</label>
                    <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="ملاحظات إضافية"></textarea>
                </div>
                
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-4">
                    <button type="button" onclick="closeMovementModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors duration-200">
                        إلغاء
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-save ml-2"></i>
                        حفظ الحركة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openMovementModal() {
    document.getElementById('movementModal').classList.remove('hidden');
}

function closeMovementModal() {
    document.getElementById('movementModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('movementModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeMovementModal();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
