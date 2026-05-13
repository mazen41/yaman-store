<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إنشاء طلبات الشراء (سلة بالشراء)';
$error_message = '';
$success_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action == 'create_basket') {
            $basket_name = trim($_POST['basket_name']);
            $description = trim($_POST['description']);
            
            if (empty($basket_name)) {
                throw new Exception('اسم السلة مطلوب');
            }
            
            $stmt = $db->prepare("INSERT INTO purchase_baskets (basket_name, description, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$basket_name, $description, $_SESSION['user_id']]);
            
            $success_message = 'تم إنشاء سلة المشتريات بنجاح';
            
        } elseif ($action == 'add_to_basket') {
            $basket_id = intval($_POST['basket_id']);
            $product_id = intval($_POST['product_id']);
            $quantity = intval($_POST['quantity']);
            $estimated_price = floatval($_POST['estimated_price']);
            $priority = $_POST['priority'];
            $notes = trim($_POST['notes']);
            
            if (empty($basket_id) || empty($product_id) || $quantity <= 0) {
                throw new Exception('جميع البيانات مطلوبة');
            }
            
            // Check if item already exists in basket
            $check_stmt = $db->prepare("SELECT id, quantity FROM purchase_basket_items WHERE basket_id = ? AND product_id = ?");
            $check_stmt->execute([$basket_id, $product_id]);
            $existing = $check_stmt->fetch();
            
            if ($existing) {
                // Update existing item
                $new_quantity = $existing['quantity'] + $quantity;
                $stmt = $db->prepare("UPDATE purchase_basket_items SET quantity = ?, estimated_price = ?, priority = ?, notes = ? WHERE id = ?");
                $stmt->execute([$new_quantity, $estimated_price, $priority, $notes, $existing['id']]);
                $success_message = 'تم تحديث العنصر في السلة';
            } else {
                // Add new item
                $stmt = $db->prepare("INSERT INTO purchase_basket_items (basket_id, product_id, quantity, estimated_price, priority, notes) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$basket_id, $product_id, $quantity, $estimated_price, $priority, $notes]);
                $success_message = 'تم إضافة العنصر إلى السلة';
            }
            
            // Update basket totals
            $update_stmt = $db->prepare("
                UPDATE purchase_baskets 
                SET total_items = (SELECT COUNT(*) FROM purchase_basket_items WHERE basket_id = ?),
                    estimated_total = (SELECT SUM(quantity * estimated_price) FROM purchase_basket_items WHERE basket_id = ?)
                WHERE id = ?
            ");
            $update_stmt->execute([$basket_id, $basket_id, $basket_id]);
            
        } elseif ($action == 'convert_to_orders') {
            $basket_id = intval($_POST['basket_id']);
            $supplier_assignments = $_POST['supplier_assignments'] ?? [];
            
            if (empty($supplier_assignments)) {
                throw new Exception('يجب تحديد المورد لكل عنصر');
            }
            
            $db->beginTransaction();
            
            // Group items by supplier
            $supplier_groups = [];
            foreach ($supplier_assignments as $item_id => $supplier_id) {
                if (!isset($supplier_groups[$supplier_id])) {
                    $supplier_groups[$supplier_id] = [];
                }
                $supplier_groups[$supplier_id][] = $item_id;
            }
            
            $created_orders = [];
            
            foreach ($supplier_groups as $supplier_id => $item_ids) {
                // Generate order number
                $order_number = 'PO-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Get items for this supplier
                $items_stmt = $db->prepare("
                    SELECT pbi.*, p.name as product_name 
                    FROM purchase_basket_items pbi
                    JOIN products p ON pbi.product_id = p.id
                    WHERE pbi.id IN (" . implode(',', array_fill(0, count($item_ids), '?')) . ")
                ");
                $items_stmt->execute($item_ids);
                $items = $items_stmt->fetchAll();
                
                // Calculate totals
                $subtotal = 0;
                foreach ($items as $item) {
                    $subtotal += ($item['quantity'] * $item['estimated_price']);
                }
                
                $tax_amount = $subtotal * 0.15;
                $total_amount = $subtotal + $tax_amount;
                
                // Create purchase order
                $order_stmt = $db->prepare("
                    INSERT INTO purchase_orders 
                    (order_number, supplier_id, order_date, subtotal, tax_amount, total_amount, priority, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, 'medium', ?)
                ");
                $order_stmt->execute([
                    $order_number, $supplier_id, date('Y-m-d'), $subtotal, $tax_amount, $total_amount, $_SESSION['user_id']
                ]);
                $order_id = $db->lastInsertId();
                
                // Add order items
                $item_stmt = $db->prepare("
                    INSERT INTO purchase_order_items 
                    (purchase_order_id, product_id, quantity, unit_price, total_price, notes) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($items as $item) {
                    $total_price = $item['quantity'] * $item['estimated_price'];
                    $item_stmt->execute([
                        $order_id, $item['product_id'], $item['quantity'], 
                        $item['estimated_price'], $total_price, $item['notes']
                    ]);
                }
                
                $created_orders[] = $order_number;
            }
            
            // Mark basket as ordered
            $db->prepare("UPDATE purchase_baskets SET status = 'ordered' WHERE id = ?")->execute([$basket_id]);
            
            $db->commit();
            
            $success_message = 'تم تحويل السلة إلى طلبات شراء: ' . implode(', ', $created_orders);
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Fetch baskets
$baskets_stmt = $db->prepare("
    SELECT pb.*, u.full_name as created_by_name,
           COUNT(pbi.id) as item_count,
           SUM(pbi.quantity * pbi.estimated_price) as calculated_total
    FROM purchase_baskets pb
    LEFT JOIN users u ON pb.created_by = u.id
    LEFT JOIN purchase_basket_items pbi ON pb.id = pbi.basket_id
    WHERE pb.created_by = ? OR ? IN (SELECT id FROM users WHERE role = 'admin')
    GROUP BY pb.id
    ORDER BY pb.created_at DESC
");
$baskets_stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$baskets = $baskets_stmt->fetchAll();

// Fetch products for dropdown
$products_stmt = $db->query("SELECT id, name, product_code, cost_price FROM products WHERE is_active = 1 ORDER BY name");
$products = $products_stmt->fetchAll();

// Fetch suppliers for conversion
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
                        <h1 class="text-2xl font-bold text-gray-900">سلة المشتريات</h1>
                        <p class="text-gray-600 mt-1">إدارة سلال المشتريات والتحويل إلى طلبات</p>
                    </div>
                    <div class="flex space-x-3 space-x-reverse">
                        <button onclick="openCreateBasketModal()" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            سلة جديدة
                        </button>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للمشتريات
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

        <!-- Baskets Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($baskets as $basket): ?>
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($basket['basket_name']); ?></h3>
                        <span class="px-2 py-1 text-xs rounded-full <?php echo $basket['status'] == 'active' ? 'bg-amber-100 text-amber-800' : ($basket['status'] == 'ordered' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'); ?>">
                            <?php 
                            $status_labels = ['active' => 'نشطة', 'ordered' => 'تم الطلب', 'cancelled' => 'ملغية'];
                            echo $status_labels[$basket['status']]; 
                            ?>
                        </span>
                    </div>
                    <?php if ($basket['description']): ?>
                    <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($basket['description']); ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="px-6 py-4">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500">عدد العناصر:</span>
                            <span class="font-medium"><?php echo $basket['item_count']; ?></span>
                        </div>
                        <div>
                            <span class="text-gray-500">التكلفة المقدرة:</span>
                            <span class="font-medium"><?php echo number_format($basket['calculated_total'] ?: 0, 0, '', ''); ?> ر.س</span>
                        </div>
                        <div class="col-span-2">
                            <span class="text-gray-500">أنشئت بواسطة:</span>
                            <span class="font-medium"><?php echo htmlspecialchars($basket['created_by_name']); ?></span>
                        </div>
                        <div class="col-span-2">
                            <span class="text-gray-500">تاريخ الإنشاء:</span>
                            <span class="font-medium"><?php echo date('d/m/Y', strtotime($basket['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="flex space-x-2 space-x-reverse">
                        <?php if ($basket['status'] == 'active'): ?>
                        <button onclick="addItemToBasket(<?php echo $basket['id']; ?>)" 
                                class="flex-1 px-3 py-2 bg-amber-600 text-white text-sm rounded hover:bg-amber-700 transition-colors duration-200">
                            <i class="fas fa-plus ml-1"></i>
                            إضافة عنصر
                        </button>
                        <?php endif; ?>
                        <?php if ($basket['status'] == 'active' && $basket['item_count'] > 0): ?>
                        <button onclick="convertBasketToOrders(<?php echo $basket['id']; ?>)" 
                                class="flex-1 px-3 py-2 bg-purple-600 text-white text-sm rounded hover:bg-purple-700 transition-colors duration-200">
                            <i class="fas fa-shopping-cart ml-1"></i>
                            تحويل لطلبات
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($baskets)): ?>
            <div class="col-span-full bg-white shadow rounded-lg p-12 text-center">
                <i class="fas fa-shopping-basket text-4xl text-gray-300 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد سلال مشتريات</h3>
                <p class="text-gray-600 mb-4">ابدأ بإنشاء سلة مشتريات جديدة لتجميع العناصر المطلوبة</p>
                <button onclick="openCreateBasketModal()" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors duration-200">
                    <i class="fas fa-plus ml-2"></i>
                    إنشاء سلة جديدة
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Basket Modal -->
<div id="createBasketModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900">إنشاء سلة مشتريات جديدة</h3>
                <button onclick="closeCreateBasketModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="create_basket">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">اسم السلة <span class="text-red-500">*</span></label>
                    <input type="text" name="basket_name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                           placeholder="أدخل اسم السلة">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">الوصف</label>
                    <textarea name="description" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                              placeholder="وصف مختصر للسلة (اختياري)"></textarea>
                </div>
                
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-4">
                    <button type="button" onclick="closeCreateBasketModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors duration-200">
                        إلغاء
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors duration-200">
                        <i class="fas fa-save ml-2"></i>
                        إنشاء السلة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div id="addItemModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-2/3 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900">إضافة عنصر إلى السلة</h3>
                <button onclick="closeAddItemModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_to_basket">
                <input type="hidden" name="basket_id" id="addItemBasketId">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">المنتج <span class="text-red-500">*</span></label>
                        <select name="product_id" id="productSelect" required 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <option value="">اختر المنتج</option>
                            <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['cost_price']; ?>">
                                <?php echo htmlspecialchars($product['name']); ?> (<?php echo htmlspecialchars($product['product_code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">الكمية <span class="text-red-500">*</span></label>
                        <input type="number" name="quantity" min="1" value="1" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">السعر المقدر <span class="text-red-500">*</span></label>
                        <input type="number" name="estimated_price" step="0.01" min="0" required id="estimatedPrice"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">الأولوية</label>
                        <select name="priority" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <option value="low">منخفضة</option>
                            <option value="medium" selected>متوسطة</option>
                            <option value="high">عالية</option>
                            <option value="urgent">عاجلة</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ملاحظات</label>
                    <textarea name="notes" rows="2" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                              placeholder="ملاحظات إضافية (اختياري)"></textarea>
                </div>
                
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-4">
                    <button type="button" onclick="closeAddItemModal()" 
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors duration-200">
                        إلغاء
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors duration-200">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة إلى السلة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Modal functions
function openCreateBasketModal() {
    document.getElementById('createBasketModal').classList.remove('hidden');
}

function closeCreateBasketModal() {
    document.getElementById('createBasketModal').classList.add('hidden');
}

function addItemToBasket(basketId) {
    document.getElementById('addItemBasketId').value = basketId;
    document.getElementById('addItemModal').classList.remove('hidden');
}

function closeAddItemModal() {
    document.getElementById('addItemModal').classList.add('hidden');
}

function convertBasketToOrders(basketId) {
    if (confirm('هل أنت متأكد من تحويل هذه السلة إلى طلبات شراء؟ سيتم إنشاء طلب منفصل لكل مورد.')) {
        // For now, submit a simple form
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="convert_to_orders">
            <input type="hidden" name="basket_id" value="${basketId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Product selection handler
document.getElementById('productSelect').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const price = selectedOption.dataset.price;
    if (price) {
        document.getElementById('estimatedPrice').value = price;
    }
});

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('fixed')) {
        if (e.target.id === 'createBasketModal') closeCreateBasketModal();
        if (e.target.id === 'addItemModal') closeAddItemModal();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
