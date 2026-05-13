<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إضافة طلب شراء جديد';
$error_message = '';
$success_message = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $supplier_id = intval($_POST['supplier_id']);
        $purchase_group_id = !empty($_POST['purchase_group_id']) ? intval($_POST['purchase_group_id']) : null;
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
        $purchase_basket_number = trim($_POST['purchase_basket_number'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $expected_delivery_date = $_POST['expected_delivery_date'] ?? null;
        $payment_terms = $_POST['payment_terms'] ?? '';
        $delivery_address = $_POST['delivery_address'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
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

        // Generate sequential order number
        $count_stmt = $db->query("SELECT COUNT(*) FROM purchase_orders");
        $count = $count_stmt->fetchColumn();
        $order_number = 'PO-' . date('Y') . '-' . str_pad($count + 1, 6, '0', STR_PAD_LEFT);

        // Calculate totals
        $subtotal = 0;
        foreach ($valid_products as $product) {
            $subtotal += ($product['quantity'] * $product['unit_price']);
        }
        
        $tax_amount = $subtotal * 0.15; // 15% VAT
        $total_amount = $subtotal + $tax_amount;

        // Check which columns exist in purchase_orders table
        $columns_check = $db->query("DESCRIBE purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
        
        // Build dynamic INSERT query based on existing columns
        $insert_columns = ['order_number', 'supplier_id', 'subtotal', 'tax_amount', 'total_amount', 'created_by'];
        $insert_values = [$order_number, $supplier_id, $subtotal, $tax_amount, $total_amount, $_SESSION['user_id']];
        
        // Add optional columns if they exist
        $optional_fields = [
            'purchase_group_id' => $purchase_group_id,
            'purchase_date' => $purchase_date,
            'purchase_basket_number' => $purchase_basket_number,
            'account_number' => $account_number,
            'expected_delivery_date' => $expected_delivery_date,
            'payment_terms' => $payment_terms,
            'delivery_address' => $delivery_address,
            'notes' => $notes,
            'status' => 'pending',
            'paid_amount' => 0,
            'remaining_amount' => $total_amount
        ];
        
        foreach ($optional_fields as $col => $val) {
            if (in_array($col, $columns_check)) {
                $insert_columns[] = $col;
                $insert_values[] = $val;
            }
        }
        
        $placeholders = str_repeat('?,', count($insert_columns) - 1) . '?';
        $columns_str = implode(', ', $insert_columns);
        
        $stmt = $db->prepare("INSERT INTO purchase_orders ($columns_str) VALUES ($placeholders)");
        $stmt->execute($insert_values);
        $purchase_order_id = $db->lastInsertId();

        // Insert purchase order items
        // Check which columns exist in purchase_order_items table
        $item_columns_check = $db->query("DESCRIBE purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($valid_products as $product) {
            $total_price = $product['quantity'] * $product['unit_price'];
            
            // Build dynamic INSERT for items
            $item_insert_cols = ['purchase_order_id', 'quantity', 'unit_price', 'total_price'];
            $item_insert_vals = [$purchase_order_id, $product['quantity'], $product['unit_price'], $total_price];
            
            // Add product_name if column exists
            if (in_array('product_name', $item_columns_check)) {
                $item_insert_cols[] = 'product_name';
                $item_insert_vals[] = $product['name'];
            }
            
            // Add product_id as NULL if column exists (for compatibility)
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
        $success_message = 'تم إنشاء طلب الشراء بنجاح. رقم الطلب: ' . $order_number;
        
        // Redirect to prevent resubmission
        header("Location: add.php?success=1&order=" . urlencode($order_number));
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Handle success message from redirect
if (isset($_GET['success']) && isset($_GET['order'])) {
    $success_message = 'تم إنشاء طلب الشراء بنجاح. رقم الطلب: ' . htmlspecialchars($_GET['order']);
}

// Fetch suppliers for dropdown
$suppliers_stmt = $db->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');
$suppliers = $suppliers_stmt->fetchAll();

// Fetch products for dropdown
$products_stmt = $db->query('SELECT id, name, product_code, cost_price FROM products WHERE is_active = 1 ORDER BY name');
$products = $products_stmt->fetchAll();

// Fetch purchase groups for dropdown
try {
    $groups_stmt = $db->query('SELECT id, group_name FROM purchase_groups WHERE is_active = 1 ORDER BY group_name');
    $groups = $groups_stmt->fetchAll();
} catch (PDOException $e) {
    // If table doesn't exist or has issues, use empty array
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
    .optional-field {
        color: #ef4444;
        font-weight: 600;
    }
    .item-row:hover {
        background-color: #f8fafc;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-600 to-amber-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white flex items-center">
                            <i class="fas fa-shopping-cart ml-3 text-amber-200"></i>
                            <?php echo $page_title; ?>
                        </h1>
                        <p class="text-amber-100 mt-2 text-lg">إنشاء وإدارة طلب شراء جديد من المورد</p>
                    </div>
                    <div>
                        <a href="index.php" class="inline-flex items-center px-6 py-3 bg-white text-amber-600 rounded-xl hover:bg-amber-50 transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-1 font-semibold">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة إلى قائمة المشتريات
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

        <form action="add.php" method="POST" id="purchaseForm" class="bg-white p-6 rounded-lg shadow-md">
            
            <!-- Right Side: Requirements List -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-6">
                <div class="lg:col-span-3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="supplier_id" class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-truck ml-1 text-amber-600"></i> المورد *
                            </label>
                            <select id="supplier_id" name="supplier_id" class="form-input" required>
                                <option value="">-- اختر المورد --</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="purchase_group_id" class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-layer-group ml-1 text-purple-600"></i> مجموعة الشراء
                            </label>
                            <select id="purchase_group_id" name="purchase_group_id" class="form-input">
                                <option value="">-- بدون مجموعة --</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['group_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="purchase_basket_number" class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-shopping-basket ml-1 text-orange-600"></i> 
                                رقم سلة الشراء <span class="optional-field">(إضافة يدوية)</span>
                            </label>
                            <input type="text" id="purchase_basket_number" name="purchase_basket_number" 
                                   class="form-input" placeholder="أدخل رقم سلة الشراء">
                        </div>
                        
                        <div>
                            <label for="purchase_date" class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-calendar-alt ml-1 text-blue-600"></i> تاريخ الشراء *
                            </label>
                            <input type="date" id="purchase_date" name="purchase_date" 
                                   value="<?php echo date('Y-m-d'); ?>" class="form-input" required>
                        </div>
                        
                        <div>
                            <label for="account_number" class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-credit-card ml-1 text-indigo-600"></i> 
                                رقم حساب <span class="optional-field">(إضافة يدوية)</span>
                            </label>
                            <input type="text" id="account_number" name="account_number" 
                                   class="form-input" placeholder="أدخل رقم الحساب">
                        </div>
                        
                        <div>
                            <label for="expected_delivery_date" class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-shipping-fast ml-1 text-amber-600"></i> تاريخ التسليم المتوقع
                            </label>
                            <input type="date" id="expected_delivery_date" name="expected_delivery_date" 
                                   class="form-input">
                        </div>
                    </div>
                </div>
                
                <!-- Requirements Box -->
                <div class="lg:col-span-1">
                    <div class="bg-gradient-to-br from-red-50 to-orange-50 border-2 border-red-200 rounded-lg p-4 sticky top-4">
                        <h3 class="text-lg font-bold text-gray-900 mb-3 flex items-center">
                            <i class="fas fa-clipboard-list ml-2 text-red-600"></i>
                            حقول طلب الشراء
                        </h3>
                        <ul class="space-y-2 text-sm">
                            <li class="flex items-start">
                                <span class="text-gray-700 font-medium">-1.</span>
                                <span class="mr-2 text-gray-800">الرقم التسلسلي</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-gray-700 font-medium">-2.</span>
                                <span class="mr-2">رقم سلة الشراء <span class="optional-field">(إضافة يدوية)</span></span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-gray-700 font-medium">-3.</span>
                                <span class="mr-2 text-gray-800">تاريخ الشراء</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-gray-700 font-medium">-4.</span>
                                <span class="mr-2">رقم حساب <span class="optional-field">(إضافة يدوية)</span></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Products Table -->
            <div class="mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-box ml-2 text-amber-600"></i>
                        المنتجات
                    </h3>
                    <button type="button" id="add-item-btn" 
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
                        <tbody id="items-container" class="bg-white divide-y divide-gray-200">
                            <tr id="noItemsRow">
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                    <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                                    <p>لا توجد منتجات. اضغط على "إضافة منتج" لإضافة منتج جديد.</p>
                                </td>
                            </tr>
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
            <div class="mb-6">
                <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-sticky-note ml-1 text-yellow-600"></i>
                    ملاحظات
                </label>
                <textarea id="notes" name="notes" rows="3" 
                          class="form-input" placeholder="أضف أي ملاحظات إضافية..."></textarea>
            </div>

            <div class="flex justify-between items-center pt-6 border-t-2 border-gray-200">
                <a href="index.php" class="inline-flex items-center px-6 py-3 bg-gray-500 text-white rounded-xl hover:bg-gray-600 transition-all duration-200 font-semibold shadow-md">
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </a>
                <button type="submit" class="inline-flex items-center px-8 py-3 bg-gradient-to-r from-amber-600 to-amber-700 text-white rounded-xl hover:from-amber-700 hover:to-amber-800 transition-all duration-200 font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                    <i class="fas fa-save ml-2"></i>
                    حفظ طلب الشراء
                </button>
            </div>
        </form>
    </div>
</div>

<template id="item-template">
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
        const itemsContainer = document.getElementById('items-container');
        const addItemBtn = document.getElementById('add-item-btn');
        const itemTemplate = document.getElementById('item-template');
        const noItemsRow = document.getElementById('noItemsRow');

        addItemBtn.addEventListener('click', addNewItem);

        function addNewItem() {
            // Remove "no items" row if exists
            if (noItemsRow) {
                noItemsRow.remove();
            }
            
            const newItemRow = itemTemplate.content.cloneNode(true);
            itemsContainer.appendChild(newItemRow);
            
            // Renumber all rows
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

                // Remove old listeners by cloning
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
                    
                    // Show "no items" row if no items left
                    if (document.querySelectorAll('.item-row').length === 0) {
                        const noItems = document.createElement('tr');
                        noItems.id = 'noItemsRow';
                        noItems.innerHTML = `
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl text-gray-300 mb-2"></i>
                                <p>لا توجد منتجات. اضغط على "إضافة منتج" لإضافة منتج جديد.</p>
                            </td>
                        `;
                        itemsContainer.appendChild(noItems);
                    }
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
            
            const taxAmount = subtotal * 0.15; // 15% VAT
            const totalAmount = subtotal + taxAmount;
            
            document.getElementById('subtotalAmount').textContent = subtotal.toFixed(2);
            document.getElementById('taxAmount').textContent = taxAmount.toFixed(2);
            document.getElementById('totalAmount').textContent = totalAmount.toFixed(2);
        }

        // Form validation
        document.getElementById('purchaseForm').addEventListener('submit', function(e) {
            const rows = document.querySelectorAll('.item-row');
            if (rows.length === 0) {
                e.preventDefault();
                alert('يرجى إضافة منتج واحد على الأقل');
                return false;
            }
            
            let hasEmptyProduct = false;
            rows.forEach(row => {
                const productName = row.querySelector('.product-name').value.trim();
                if (!productName) {
                    hasEmptyProduct = true;
                }
            });
            
            if (hasEmptyProduct) {
                e.preventDefault();
                alert('يرجى إدخال اسم المنتج لجميع الصفوف');
                return false;
            }
        });

        // Add one item row by default
        addItemBtn.click();
    });
</script>

<?php include '../../includes/footer.php'; ?>
