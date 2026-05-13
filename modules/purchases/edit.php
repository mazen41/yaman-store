<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Permission guard
if (!hasPermission($_SESSION['user_id'], 'purchases', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل طلبات الشراء';
    header('Location: index.php');
    exit();
}

$page_title = 'تعديل طلب الشراء';
$error_message = '';
$success_message = '';

// Get purchase order ID
$order_id = intval($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $supplier_id = intval($_POST['supplier_id']);
        $purchase_group_id = !empty($_POST['purchase_group_id']) ? intval($_POST['purchase_group_id']) : null;
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
        $purchase_basket_number = trim($_POST['purchase_basket_number'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $expected_delivery_date = $_POST['expected_delivery_date'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        
        $product_names = $_POST['product_name'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unit_prices = $_POST['unit_price'] ?? [];

        if (empty($supplier_id)) {
            throw new Exception('يرجى تحديد المورد.');
        }
        
        // Filter out empty products
        $valid_products = [];
        for ($i = 0; $i < count($product_names); $i++) {
            if (!empty(trim($product_names[$i]))) {
                $valid_products[] = [
                    'name' => trim($product_names[$i]),
                    'quantity' => floatval($quantities[$i]),
                    'unit_price' => floatval($unit_prices[$i])
                ];
            }
        }
        
        if (empty($valid_products)) {
            throw new Exception('يرجى إضافة منتج واحد على الأقل.');
        }

        $db->beginTransaction();

        // Calculate totals
        $subtotal = 0;
        foreach ($valid_products as $product) {
            $subtotal += ($product['quantity'] * $product['unit_price']);
        }
        
        $tax_amount = $subtotal * 0.15; // 15% VAT
        $total_amount = $subtotal + $tax_amount;

        // Check which columns exist
        $columns_check = $db->query("DESCRIBE purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
        
        // Build dynamic UPDATE query
        $update_fields = [
            'supplier_id' => $supplier_id,
            'subtotal' => $subtotal,
            'tax_amount' => $tax_amount,
            'total_amount' => $total_amount,
            'notes' => $notes
        ];
        
        // Add optional fields if they exist
        $optional_fields = [
            'purchase_group_id' => $purchase_group_id,
            'purchase_date' => $purchase_date,
            'purchase_basket_number' => $purchase_basket_number,
            'account_number' => $account_number,
            'expected_delivery_date' => $expected_delivery_date,
            'remaining_amount' => $total_amount
        ];
        
        foreach ($optional_fields as $col => $val) {
            if (in_array($col, $columns_check)) {
                $update_fields[$col] = $val;
            }
        }
        
        $set_clause = [];
        $values = [];
        foreach ($update_fields as $col => $val) {
            $set_clause[] = "$col = ?";
            $values[] = $val;
        }
        $values[] = $order_id;
        
        $stmt = $db->prepare("UPDATE purchase_orders SET " . implode(', ', $set_clause) . " WHERE id = ?");
        $stmt->execute($values);

        // Delete existing items
        $db->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$order_id]);

        // Insert new items
        $item_columns_check = $db->query("DESCRIBE purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($valid_products as $product) {
            $total_price = $product['quantity'] * $product['unit_price'];
            
            $item_insert_cols = ['purchase_order_id', 'quantity', 'unit_price', 'total_price'];
            $item_insert_vals = [$order_id, $product['quantity'], $product['unit_price'], $total_price];
            
            if (in_array('product_name', $item_columns_check)) {
                $item_insert_cols[] = 'product_name';
                $item_insert_vals[] = $product['name'];
            }
            
            if (in_array('product_id', $item_columns_check)) {
                $item_insert_cols[] = 'product_id';
                $item_insert_vals[] = null;
            }
            
            $item_placeholders = str_repeat('?,', count($item_insert_cols) - 1) . '?';
            $item_cols_str = implode(', ', $item_insert_cols);
            
            $item_stmt = $db->prepare("INSERT INTO purchase_order_items ($item_cols_str) VALUES ($item_placeholders)");
            $item_stmt->execute($item_insert_vals);
        }

        $db->commit();
        $success_message = 'تم تحديث طلب الشراء بنجاح';
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Fetch purchase order details
$stmt = $db->prepare("
    SELECT po.*, s.name as supplier_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: index.php');
    exit();
}

// Check if order can be edited
if ($order['status'] != 'pending') {
    $_SESSION['error'] = 'لا يمكن تعديل طلب الشراء بعد تغيير حالته';
    header('Location: view.php?id=' . $order_id);
    exit();
}

// Fetch items
$item_columns = $db->query("DESCRIBE purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
$has_product_name = in_array('product_name', $item_columns);

if ($has_product_name) {
    $items_stmt = $db->prepare("
        SELECT poi.*, COALESCE(poi.product_name, p.name) as product_name
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = ?
    ");
} else {
    $items_stmt = $db->prepare("
        SELECT poi.*, p.name as product_name
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = ?
    ");
}
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll();

// Fetch suppliers
$suppliers_stmt = $db->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');
$suppliers = $suppliers_stmt->fetchAll();

// Fetch purchase groups
try {
    $groups_stmt = $db->query('SELECT id, group_name FROM purchase_groups WHERE is_active = 1 ORDER BY group_name');
    $groups = $groups_stmt->fetchAll();
} catch (PDOException $e) {
    $groups = [];
}

include '../../includes/header.php';
?>

<style>
    .form-input {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    .form-input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    .item-row:hover {
        background-color: #f8fafc;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white flex items-center">
                            <i class="fas fa-edit ml-3 text-blue-200"></i>
                            تعديل طلب الشراء
                        </h1>
                        <p class="text-blue-100 mt-2 text-lg">رقم الطلب: <?php echo htmlspecialchars($order['order_number']); ?></p>
                    </div>
                    <div>
                        <a href="view.php?id=<?php echo $order_id; ?>" class="inline-flex items-center px-6 py-3 bg-white text-blue-600 rounded-xl hover:bg-blue-50 transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-1 font-semibold">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للعرض
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-amber-100 border-2 border-amber-400 text-amber-700 px-6 py-4 rounded-xl mb-6 shadow-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle ml-3 text-2xl"></i>
                <span class="font-semibold"><?php echo $success_message; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border-2 border-red-400 text-red-700 px-6 py-4 rounded-xl mb-6 shadow-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle ml-3 text-2xl"></i>
                <span class="font-semibold"><?php echo $error_message; ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <form action="" method="POST" id="editForm" class="bg-white shadow-xl rounded-2xl overflow-hidden">
            <div class="px-6 py-4 border-b-2 border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                <h2 class="text-xl font-bold text-gray-900 flex items-center">
                    <i class="fas fa-file-invoice ml-2 text-blue-600"></i>
                    بيانات طلب الشراء
                </h2>
            </div>
            
            <div class="p-6 space-y-6">
                <!-- Basic Information -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="supplier_id" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-truck ml-1 text-amber-600"></i> المورد *
                        </label>
                        <select id="supplier_id" name="supplier_id" class="form-input" required>
                            <option value="">-- اختر المورد --</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo $order['supplier_id'] == $supplier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="purchase_group_id" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-layer-group ml-1 text-purple-600"></i> مجموعة الشراء
                        </label>
                        <select id="purchase_group_id" name="purchase_group_id" class="form-input">
                            <option value="">-- بدون مجموعة --</option>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>" <?php echo ($order['purchase_group_id'] ?? '') == $group['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($group['group_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="purchase_basket_number" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-shopping-basket ml-1 text-orange-600"></i> رقم سلة الشراء
                        </label>
                        <input type="text" id="purchase_basket_number" name="purchase_basket_number" 
                               value="<?php echo htmlspecialchars($order['purchase_basket_number'] ?? ''); ?>"
                               class="form-input" placeholder="أدخل رقم سلة الشراء">
                    </div>
                    
                    <div>
                        <label for="purchase_date" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar-alt ml-1 text-blue-600"></i> تاريخ الشراء *
                        </label>
                        <input type="date" id="purchase_date" name="purchase_date" 
                               value="<?php echo $order['purchase_date'] ?? date('Y-m-d'); ?>"
                               class="form-input" required>
                    </div>
                    
                    <div>
                        <label for="account_number" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-credit-card ml-1 text-indigo-600"></i> رقم حساب
                        </label>
                        <input type="text" id="account_number" name="account_number" 
                               value="<?php echo htmlspecialchars($order['account_number'] ?? ''); ?>"
                               class="form-input" placeholder="أدخل رقم الحساب">
                    </div>
                    
                    <div>
                        <label for="expected_delivery_date" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-shipping-fast ml-1 text-amber-600"></i> تاريخ التسليم المتوقع
                        </label>
                        <input type="date" id="expected_delivery_date" name="expected_delivery_date" 
                               value="<?php echo $order['expected_delivery_date'] ?? ''; ?>"
                               class="form-input">
                    </div>
                </div>

                <!-- Products Table -->
                <div>
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-bold text-gray-900 flex items-center">
                            <i class="fas fa-box ml-2 text-amber-600"></i>
                            المنتجات
                        </h3>
                        <button type="button" id="addItemBtn" 
                                class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-amber-500 to-amber-600 text-white font-semibold rounded-lg hover:from-amber-600 hover:to-amber-700 transition-all shadow-md">
                            <i class="fas fa-plus-circle ml-2"></i>
                            إضافة منتج
                        </button>
                    </div>
                    
                    <div class="overflow-x-auto rounded-lg border-2 border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                <tr>
                                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">#</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase">اسم المنتج</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase">الكمية</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase">سعر الوحدة</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-700 uppercase">الإجمالي</th>
                                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-700 uppercase">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody id="itemsContainer" class="bg-white divide-y divide-gray-200">
                                <?php $row_num = 1; foreach ($items as $item): ?>
                                <tr class="item-row hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3 text-center item-number font-semibold text-gray-700"><?php echo $row_num++; ?></td>
                                    <td class="px-4 py-3">
                                        <input type="text" name="product_name[]" value="<?php echo htmlspecialchars($item['product_name'] ?? ''); ?>"
                                               placeholder="أدخل اسم المنتج" class="form-input product-name" required>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input type="number" name="quantity[]" value="<?php echo $item['quantity']; ?>"
                                               placeholder="1" min="0.001" step="0.001" class="form-input text-center quantity" required>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input type="number" name="unit_price[]" value="<?php echo $item['unit_price']; ?>"
                                               placeholder="0.00" min="0" step="0.01" class="form-input text-right unit-price" required>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900">
                                        <span class="item-total"><?php echo number_format($item['total_price'], 0, '', ''); ?></span> ريال
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <button type="button" class="text-red-600 hover:text-red-800 transition-colors remove-item-btn" title="حذف">
                                            <i class="fas fa-trash-alt text-lg"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gradient-to-r from-gray-50 to-gray-100">
                                <tr class="border-t-2 border-gray-300">
                                    <td colspan="4" class="px-4 py-3 text-left font-semibold text-gray-700">
                                        <i class="fas fa-calculator ml-1 text-blue-600"></i>
                                        المجموع الفرعي:
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold text-lg text-gray-900">
                                        <span id="subtotalAmount">0.00</span> ريال
                                    </td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="px-4 py-3 text-left font-semibold text-gray-700">
                                        <i class="fas fa-percent ml-1 text-purple-600"></i>
                                        الضريبة (15%):
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold text-lg text-gray-900">
                                        <span id="taxAmount">0.00</span> ريال
                                    </td>
                                    <td></td>
                                </tr>
                                <tr class="bg-gradient-to-r from-amber-100 to-amber-50 border-t-2 border-amber-300">
                                    <td colspan="4" class="px-4 py-4 text-left font-bold text-amber-800 text-lg">
                                        <i class="fas fa-money-bill-wave ml-2"></i>
                                        الإجمالي:
                                    </td>
                                    <td class="px-4 py-4 text-right font-bold text-2xl text-amber-800">
                                        <span id="totalAmount" class="bg-amber-200 px-3 py-1 rounded-lg">0.00</span> ريال
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Notes -->
                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-sticky-note ml-1 text-yellow-600"></i>
                        ملاحظات
                    </label>
                    <textarea id="notes" name="notes" rows="3" 
                              class="form-input" placeholder="أضف أي ملاحظات إضافية..."><?php echo htmlspecialchars($order['notes'] ?? ''); ?></textarea>
                </div>

                <div class="flex justify-between items-center pt-6 border-t-2 border-gray-200">
                    <a href="view.php?id=<?php echo $order_id; ?>" class="inline-flex items-center px-6 py-3 bg-gray-500 text-white rounded-xl hover:bg-gray-600 transition-all duration-200 font-semibold shadow-md">
                        <i class="fas fa-times ml-2"></i>
                        إلغاء
                    </a>
                    <button type="submit" class="inline-flex items-center px-8 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-200 font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-save ml-2"></i>
                        حفظ التعديلات
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<template id="itemRowTemplate">
    <tr class="item-row hover:bg-gray-50 transition-colors">
        <td class="px-4 py-3 text-center item-number font-semibold text-gray-700">1</td>
        <td class="px-4 py-3">
            <input type="text" name="product_name[]" placeholder="أدخل اسم المنتج" 
                   class="form-input product-name" required>
        </td>
        <td class="px-4 py-3">
            <input type="number" name="quantity[]" placeholder="1" min="0.001" step="0.001" value="1" 
                   class="form-input text-center quantity" required>
        </td>
        <td class="px-4 py-3">
            <input type="number" name="unit_price[]" placeholder="0.00" min="0" step="0.01" value="0" 
                   class="form-input text-right unit-price" required>
        </td>
        <td class="px-4 py-3 text-right font-semibold text-gray-900">
            <span class="item-total">0.00</span> ريال
        </td>
        <td class="px-4 py-3 text-center">
            <button type="button" class="text-red-600 hover:text-red-800 transition-colors remove-item-btn" title="حذف">
                <i class="fas fa-trash-alt text-lg"></i>
            </button>
        </td>
    </tr>
</template>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const itemsContainer = document.getElementById('itemsContainer');
        const addItemBtn = document.getElementById('addItemBtn');
        const itemTemplate = document.getElementById('itemRowTemplate');

        addItemBtn.addEventListener('click', addNewItem);

        function addNewItem() {
            const newItemRow = itemTemplate.content.cloneNode(true);
            itemsContainer.appendChild(newItemRow);
            renumberRows();
            updateEventListeners();
            updateTotals();
        }

        function renumberRows() {
            const rows = document.querySelectorAll('.item-row');
            rows.forEach((row, index) => {
                row.querySelector('.item-number').textContent = index + 1;
            });
        }

        function updateEventListeners() {
            document.querySelectorAll('.item-row').forEach(row => {
                const quantityInput = row.querySelector('.quantity');
                const unitPriceInput = row.querySelector('.unit-price');
                const removeItemBtn = row.querySelector('.remove-item-btn');

                const newQuantityInput = quantityInput.cloneNode(true);
                const newUnitPriceInput = unitPriceInput.cloneNode(true);
                const newRemoveBtn = removeItemBtn.cloneNode(true);
                
                quantityInput.parentNode.replaceChild(newQuantityInput, quantityInput);
                unitPriceInput.parentNode.replaceChild(newUnitPriceInput, unitPriceInput);
                removeItemBtn.parentNode.replaceChild(newRemoveBtn, removeItemBtn);

                newQuantityInput.addEventListener('input', updateRowTotal);
                newUnitPriceInput.addEventListener('input', updateRowTotal);
                newRemoveBtn.addEventListener('click', function() {
                    row.remove();
                    renumberRows();
                    updateTotals();
                });
            });
        }

        function updateRowTotal(event) {
            const row = event.target.closest('.item-row');
            const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
            const unitPrice = parseFloat(row.querySelector('.unit-price').value) || 0;
            const total = quantity * unitPrice;
            row.querySelector('.item-total').textContent = total.toFixed(2);
            updateTotals();
        }

        function updateTotals() {
            let subtotal = 0;
            
            document.querySelectorAll('.item-row').forEach(row => {
                const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
                const unitPrice = parseFloat(row.querySelector('.unit-price').value) || 0;
                const total = quantity * unitPrice;
                row.querySelector('.item-total').textContent = total.toFixed(2);
                subtotal += total;
            });
            
            const taxAmount = subtotal * 0.15;
            const totalAmount = subtotal + taxAmount;
            
            document.getElementById('subtotalAmount').textContent = subtotal.toFixed(2);
            document.getElementById('taxAmount').textContent = taxAmount.toFixed(2);
            document.getElementById('totalAmount').textContent = totalAmount.toFixed(2);
        }

        // Initialize
        updateEventListeners();
        updateTotals();
    });
</script>

<?php include '../../includes/footer.php'; ?>
