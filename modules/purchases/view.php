<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Permission check for edit
$canEdit = hasPermission($_SESSION['user_id'], 'purchases', 'edit');

$page_title = 'عرض طلب الشراء';
$error_message = '';
$success_message = '';

// Get purchase order ID
$order_id = intval($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: index.php');
    exit();
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        
        if ($action == 'update_status') {
            $new_status = $_POST['status'];
            $notes = trim($_POST['notes'] ?? '');
            
            $stmt = $db->prepare("UPDATE purchase_orders SET status = ?, notes = CONCAT(COALESCE(notes, ''), '\n', ?) WHERE id = ?");
            $stmt->execute([$new_status, "تم تحديث الحالة إلى: $new_status - $notes", $order_id]);
            
            $success_message = 'تم تحديث حالة الطلب بنجاح';
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Fetch purchase order details
$stmt = $db->prepare("
    SELECT po.*, s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
           pg.group_name,
           u.full_name as created_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_groups pg ON po.purchase_group_id = pg.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: index.php');
    exit();
}

// Fetch purchase order items
// Check if product_name column exists in purchase_order_items
$item_columns = $db->query("DESCRIBE purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
$has_product_name = in_array('product_name', $item_columns);

if ($has_product_name) {
    // Use product_name from purchase_order_items if it exists
    $items_stmt = $db->prepare("
        SELECT poi.*, 
               COALESCE(poi.product_name, p.name) as product_name,
               p.product_code, p.unit
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = ?
        ORDER BY poi.id
    ");
} else {
    // Fallback to products table only
    $items_stmt = $db->prepare("
        SELECT poi.*, p.name as product_name, p.product_code, p.unit
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = ?
        ORDER BY poi.id
    ");
}
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">طلب شراء رقم: <?php echo htmlspecialchars($order['order_number']); ?></h1>
                        <p class="text-gray-600 mt-1">تفاصيل طلب الشراء والعناصر المطلوبة</p>
                    </div>
                    <div class="flex space-x-3 space-x-reverse">
                        <?php if ($canEdit && ($order['status'] == 'draft' || $order['status'] == 'pending')): ?>
                        <button onclick="openStatusModal()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                            <i class="fas fa-edit ml-2"></i>
                            تحديث الحالة
                        </button>
                        <?php endif; ?>
                        <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors duration-200">
                            <i class="fas fa-print ml-2"></i>
                            طباعة
                        </button>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للقائمة
                        </a>
                    </div>
                </div>
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Order Details -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- Basic Information -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">معلومات الطلب</h3>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">رقم الطلب</label>
                                <p class="mt-1 text-sm text-gray-900 font-mono bg-gray-100 px-3 py-2 rounded">
                                    <?php echo htmlspecialchars($order['order_number']); ?>
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">تاريخ الطلب</label>
                                <p class="mt-1 text-sm text-gray-900">
                                    <?php echo date('d/m/Y', strtotime($order['order_date'])); ?>
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">تاريخ التسليم المتوقع</label>
                                <p class="mt-1 text-sm text-gray-900">
                                    <?php echo $order['expected_delivery_date'] ? date('d/m/Y', strtotime($order['expected_delivery_date'])) : 'غير محدد'; ?>
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">الحالة</label>
                                <div class="mt-1">
                                    <?php
                                    $status_colors = [
                                        'draft' => 'bg-gray-100 text-gray-800',
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'approved' => 'bg-blue-100 text-blue-800',
                                        'ordered' => 'bg-purple-100 text-purple-800',
                                        'partial_received' => 'bg-orange-100 text-orange-800',
                                        'received' => 'bg-amber-100 text-amber-800',
                                        'cancelled' => 'bg-red-100 text-red-800'
                                    ];
                                    $status_labels = [
                                        'draft' => 'مسودة',
                                        'pending' => 'قيد الانتظار',
                                        'approved' => 'معتمد',
                                        'ordered' => 'تم الطلب',
                                        'partial_received' => 'استلام جزئي',
                                        'received' => 'تم الاستلام',
                                        'cancelled' => 'ملغي'
                                    ];
                                    ?>
                                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full <?php echo $status_colors[$order['status']]; ?>">
                                        <?php echo $status_labels[$order['status']]; ?>
                                    </span>
                                </div>
                            </div>
                            <?php if ($order['group_name']): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">مجموعة الشراء</label>
                                <p class="mt-1 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($order['group_name']); ?>
                                    <span class="text-gray-500">(<?php echo htmlspecialchars($order['group_number']); ?>)</span>
                                </p>
                            </div>
                            <?php endif; ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">شروط الدفع</label>
                                <p class="mt-1 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($order['payment_terms'] ?: 'غير محدد'); ?>
                                </p>
                            </div>
                        </div>
                        
                        <?php if ($order['delivery_address']): ?>
                        <div class="mt-6">
                            <label class="block text-sm font-medium text-gray-700">عنوان التسليم</label>
                            <p class="mt-1 text-sm text-gray-900 bg-gray-50 p-3 rounded">
                                <?php echo nl2br(htmlspecialchars($order['delivery_address'])); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($order['notes']): ?>
                        <div class="mt-6">
                            <label class="block text-sm font-medium text-gray-700">ملاحظات</label>
                            <p class="mt-1 text-sm text-gray-900 bg-gray-50 p-3 rounded">
                                <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">عناصر الطلب</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المنتج</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الكمية</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">سعر الوحدة</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المجموع</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تم الاستلام</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <div>
                                            <div class="font-medium"><?php echo htmlspecialchars($item['product_name'] ?: 'منتج محذوف'); ?></div>
                                            <div class="text-gray-500"><?php echo htmlspecialchars($item['product_code'] ?: ''); ?></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit'] ?: ''); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo number_format($item['unit_price'], 0, '', ''); ?> ر.ي
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                                        <?php echo number_format($item['total_price'], 0, '', ''); ?> ر.ي
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <span class="px-2 py-1 text-xs rounded-full <?php echo $item['received_quantity'] >= $item['quantity'] ? 'bg-amber-100 text-amber-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                            <?php echo $item['received_quantity']; ?> / <?php echo $item['quantity']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                
                <!-- Supplier Information -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">معلومات المورد</h3>
                    </div>
                    <div class="px-6 py-4 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">اسم المورد</label>
                            <p class="mt-1 text-sm text-gray-900 font-medium">
                                <?php echo htmlspecialchars($order['supplier_name']); ?>
                            </p>
                        </div>
                        <?php if ($order['contact_person']): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">الشخص المسؤول</label>
                            <p class="mt-1 text-sm text-gray-900">
                                <?php echo htmlspecialchars($order['contact_person']); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                        <?php if ($order['phone']): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">الهاتف</label>
                            <p class="mt-1 text-sm text-gray-900">
                                <a href="tel:<?php echo htmlspecialchars($order['phone']); ?>" class="text-blue-600 hover:text-blue-800">
                                    <?php echo htmlspecialchars($order['phone']); ?>
                                </a>
                            </p>
                        </div>
                        <?php endif; ?>
                        <?php if ($order['email']): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">البريد الإلكتروني</label>
                            <p class="mt-1 text-sm text-gray-900">
                                <a href="mailto:<?php echo htmlspecialchars($order['email']); ?>" class="text-blue-600 hover:text-blue-800">
                                    <?php echo htmlspecialchars($order['email']); ?>
                                </a>
                            </p>
                        </div>
                        <?php endif; ?>
                        <?php if ($order['address']): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">العنوان</label>
                            <p class="mt-1 text-sm text-gray-900">
                                <?php echo nl2br(htmlspecialchars($order['address'])); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">ملخص الطلب</h3>
                    </div>
                    <div class="px-6 py-4 space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">المجموع الفرعي:</span>
                            <span class="text-sm font-medium text-gray-900"><?php echo number_format($order['subtotal'], 0, '', ''); ?> ر.ي</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">ضريبة القيمة المضافة:</span>
                            <span class="text-sm font-medium text-gray-900"><?php echo number_format($order['tax_amount'], 0, '', ''); ?> ر.ي</span>
                        </div>
                        <?php if ($order['discount_amount'] > 0): ?>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">الخصم:</span>
                            <span class="text-sm font-medium text-red-600">-<?php echo number_format($order['discount_amount'], 0, '', ''); ?> ر.ي</span>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($order['shipping_cost']) && $order['shipping_cost'] > 0): ?>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">تكلفة الشحن:</span>
                            <span class="text-sm font-medium text-gray-900"><?php echo number_format($order['shipping_cost'], 0, '', ''); ?> ر.ي</span>
                        </div>
                        <?php endif; ?>
                        <div class="border-t pt-3">
                            <div class="flex justify-between">
                                <span class="text-base font-medium text-gray-900">المجموع الإجمالي:</span>
                                <span class="text-lg font-bold text-blue-600"><?php echo number_format($order['total_amount'], 0, '', ''); ?> ر.ي</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order History -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">تاريخ الطلب</h3>
                    </div>
                    <div class="px-6 py-4 space-y-3">
                        <div class="flex items-center space-x-3 space-x-reverse">
                            <div class="flex-shrink-0 w-2 h-2 bg-blue-600 rounded-full"></div>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">تم إنشاء الطلب</p>
                                <p class="text-xs text-gray-500">
                                    <?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?>
                                    بواسطة <?php echo htmlspecialchars($order['created_by_name']); ?>
                                </p>
                            </div>
                        </div>
                        
                        <?php if (isset($order['approved_at']) && $order['approved_at']): ?>
                        <div class="flex items-center space-x-3 space-x-reverse">
                            <div class="flex-shrink-0 w-2 h-2 bg-amber-600 rounded-full"></div>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">تم اعتماد الطلب</p>
                                <p class="text-xs text-gray-500">
                                    <?php echo date('d/m/Y H:i', strtotime($order['approved_at'])); ?>
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900">تحديث حالة الطلب</h3>
                <button onclick="closeStatusModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_status">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">الحالة الجديدة</label>
                    <select name="status" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="pending" <?php echo $order['status'] == 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                        <option value="approved" <?php echo $order['status'] == 'approved' ? 'selected' : ''; ?>>معتمد</option>
                        <option value="ordered" <?php echo $order['status'] == 'ordered' ? 'selected' : ''; ?>>تم الطلب</option>
                        <option value="partial_received" <?php echo $order['status'] == 'partial_received' ? 'selected' : ''; ?>>استلام جزئي</option>
                        <option value="received" <?php echo $order['status'] == 'received' ? 'selected' : ''; ?>>تم الاستلام</option>
                        <option value="cancelled" <?php echo $order['status'] == 'cancelled' ? 'selected' : ''; ?>>ملغي</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ملاحظات التحديث</label>
                    <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="أدخل ملاحظات حول تحديث الحالة"></textarea>
                </div>
                
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-4">
                    <button type="button" onclick="closeStatusModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors duration-200">
                        إلغاء
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-save ml-2"></i>
                        تحديث الحالة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openStatusModal() {
    document.getElementById('statusModal').classList.remove('hidden');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('statusModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeStatusModal();
    }
});

// Print styles
const printStyles = `
    @media print {
        .no-print { display: none !important; }
        body { font-size: 12px; }
        .bg-gray-50 { background: white !important; }
        .shadow { box-shadow: none !important; }
        .border { border: 1px solid #ccc !important; }
    }
`;

const styleSheet = document.createElement("style");
styleSheet.type = "text/css";
styleSheet.innerText = printStyles;
document.head.appendChild(styleSheet);
</script>

<?php include '../../includes/footer.php'; ?>
