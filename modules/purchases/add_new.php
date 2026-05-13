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
        $order_date = $_POST['order_date'] ?? date('Y-m-d');
        $expected_delivery_date = $_POST['expected_delivery_date'] ?? null;
        $payment_terms = $_POST['payment_terms'] ?? '';
        $delivery_address = $_POST['delivery_address'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        $product_ids = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unit_prices = $_POST['unit_price'] ?? [];

        if (empty($supplier_id) || empty($product_ids)) {
            throw new Exception('يرجى تحديد المورد وإضافة منتج واحد على الأقل.');
        }

        $db->beginTransaction();

        // Generate order number
        $order_number = 'PO-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check if order number exists
        $check_stmt = $db->prepare("SELECT id FROM purchase_orders WHERE order_number = ?");
        $check_stmt->execute([$order_number]);
        if ($check_stmt->fetch()) {
            $order_number = 'PO-' . date('Y') . '-' . str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        }

        // Calculate totals
        $subtotal = 0;
        for ($i = 0; $i < count($product_ids); $i++) {
            $subtotal += ($quantities[$i] * $unit_prices[$i]);
        }
        
        $tax_amount = $subtotal * 0.15; // 15% VAT
        $total_amount = $subtotal + $tax_amount;

        // Insert purchase order
        $stmt = $db->prepare("
            INSERT INTO purchase_orders 
            (order_number, supplier_id, purchase_group_id, order_date, expected_delivery_date, 
             subtotal, tax_amount, total_amount, payment_terms, delivery_address, notes, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $order_number, $supplier_id, $purchase_group_id, $order_date, $expected_delivery_date,
            $subtotal, $tax_amount, $total_amount, $payment_terms, $delivery_address, $notes, $_SESSION['user_id']
        ]);
        $purchase_order_id = $db->lastInsertId();

        // Insert purchase order items
        $item_stmt = $db->prepare("
            INSERT INTO purchase_order_items 
            (purchase_order_id, product_id, quantity, unit_price, total_price) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        for ($i = 0; $i < count($product_ids); $i++) {
            $total_price = $quantities[$i] * $unit_prices[$i];
            $item_stmt->execute([
                $purchase_order_id, $product_ids[$i], $quantities[$i], $unit_prices[$i], $total_price
            ]);
        }

        $db->commit();
        $success_message = 'تم إنشاء طلب الشراء بنجاح. رقم الطلب: ' . $order_number;
        
        // Clear form data
        $_POST = [];
        
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch suppliers for dropdown
$suppliers_stmt = $db->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');
$suppliers = $suppliers_stmt->fetchAll();

// Fetch products for dropdown
$products_stmt = $db->query('SELECT id, name, product_code, cost_price FROM products WHERE is_active = 1 ORDER BY name');
$products = $products_stmt->fetchAll();

// Fetch purchase groups for dropdown
$groups_stmt = $db->query('SELECT id, group_name FROM purchase_groups WHERE status = "active" ORDER BY group_name');
$groups = $groups_stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                        <p class="text-gray-600 mt-1">إنشاء طلب شراء جديد من المورد</p>
                    </div>
                    <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة للقائمة
                    </a>
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

        <!-- Purchase Order Form -->
        <div class="bg-white shadow rounded-lg">
            <form method="POST" class="p-6 space-y-6">
                
                <!-- Basic Information -->
                <div class="border-b border-gray-200 pb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">المعلومات الأساسية</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="supplier_id" class="block text-sm font-medium text-gray-700 mb-2">
                                المورد <span class="text-red-500">*</span>
                            </label>
                            <select 
                                id="supplier_id" 
                                name="supplier_id" 
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200"
                            >
                                <option value="">اختر المورد</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo (isset($_POST['supplier_id']) && $_POST['supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="purchase_group_id" class="block text-sm font-medium text-gray-700 mb-2">
                                مجموعة الشراء (اختياري)
                            </label>
                            <select 
                                id="purchase_group_id" 
                                name="purchase_group_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200"
                            >
                                <option value="">بدون مجموعة</option>
                                <?php foreach ($groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>" <?php echo (isset($_POST['purchase_group_id']) && $_POST['purchase_group_id'] == $group['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($group['group_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="order_date" class="block text-sm font-medium text-gray-700 mb-2">
                                تاريخ الطلب <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="date" 
                                id="order_date" 
                                name="order_date" 
                                value="<?php echo $_POST['order_date'] ?? date('Y-m-d'); ?>"
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200"
                            >
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                        <div>
                            <label for="expected_delivery_date" class="block text-sm font-medium text-gray-700 mb-2">
                                تاريخ التسليم المتوقع
                            </label>
                            <input 
                                type="date" 
                                id="expected_delivery_date" 
                                name="expected_delivery_date" 
                                value="<?php echo $_POST['expected_delivery_date'] ?? ''; ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200"
                            >
                        </div>
                        
                        <div>
                            <label for="payment_terms" class="block text-sm font-medium text-gray-700 mb-2">
                                شروط الدفع
                            </label>
                            <select 
                                id="payment_terms" 
                                name="payment_terms"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200"
                            >
                                <option value="">اختر شروط الدفع</option>
                                <option value="نقد عند التسليم" <?php echo (isset($_POST['payment_terms']) && $_POST['payment_terms'] == 'نقد عند التسليم') ? 'selected' : ''; ?>>نقد عند التسليم</option>
                                <option value="7 أيام" <?php echo (isset($_POST['payment_terms']) && $_POST['payment_terms'] == '7 أيام') ? 'selected' : ''; ?>>7 أيام</option>
                                <option value="15 يوم" <?php echo (isset($_POST['payment_terms']) && $_POST['payment_terms'] == '15 يوم') ? 'selected' : ''; ?>>15 يوم</option>
                                <option value="30 يوم" <?php echo (isset($_POST['payment_terms']) && $_POST['payment_terms'] == '30 يوم') ? 'selected' : ''; ?>>30 يوم</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Products Section -->
                <div class="border-b border-gray-200 pb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">المنتجات</h3>
                        <button type="button" id="add-product-btn" class="inline-flex items-center px-3 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة منتج
                        </button>
                    </div>
                    
                    <div id="products-container" class="space-y-4">
                        <!-- Products will be added here dynamically -->
                    </div>
                    
                    <div class="mt-4 p-4 bg-blue-50 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-info-circle text-blue-600 ml-2"></i>
                            <span class="text-sm text-blue-800">
                                يجب إضافة منتج واحد على الأقل لإنشاء طلب الشراء
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="border-b border-gray-200 pb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">معلومات إضافية</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="delivery_address" class="block text-sm font-medium text-gray-700 mb-2">
                                عنوان التسليم
                            </label>
                            <textarea 
                                id="delivery_address" 
                                name="delivery_address" 
                                rows="3"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200"
                                placeholder="أدخل عنوان التسليم"
                            ><?php echo htmlspecialchars($_POST['delivery_address'] ?? ''); ?></textarea>
                        </div>
                        
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                                ملاحظات
                            </label>
                            <textarea 
                                id="notes" 
                                name="notes" 
                                rows="3"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition-all duration-200"
                                placeholder="أدخل أي ملاحظات إضافية"
                            ><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="bg-gray-50 p-6 rounded-lg">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">ملخص الطلب</h3>
                    
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-gray-600">المجموع الفرعي:</span>
                            <span id="subtotal-amount" class="font-medium">0.00 ر.ي</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">ضريبة القيمة المضافة (15%):</span>
                            <span id="tax-amount" class="font-medium">0.00 ر.ي</span>
                        </div>
                        <div class="flex justify-between text-lg font-bold border-t pt-2">
                            <span>المجموع الإجمالي:</span>
                            <span id="total-amount" class="text-blue-600">0.00 ر.ي</span>
                        </div>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-6 border-t border-gray-200">
                    <a href="index.php" class="inline-flex items-center px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors duration-200">
                        إلغاء
                    </a>
                    <button type="submit" class="inline-flex items-center px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-save ml-2"></i>
                        حفظ طلب الشراء
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Product Row Template -->
<template id="product-row-template">
    <div class="product-row bg-gray-50 p-4 rounded-lg border">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">المنتج</label>
                <select name="product_id[]" required class="product-select w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">اختر المنتج</option>
                    <?php foreach ($products as $product): ?>
                    <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['cost_price']; ?>">
                        <?php echo htmlspecialchars($product['name']); ?> (<?php echo htmlspecialchars($product['product_code']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">الكمية</label>
                <input type="number" name="quantity[]" min="1" value="1" required class="quantity-input w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">سعر الوحدة (ر.ي)</label>
                <input type="number" name="unit_price[]" step="0.01" min="0" required class="price-input w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">المجموع</label>
                <div class="row-total px-3 py-2 bg-gray-100 rounded-lg text-center font-medium">0.00 ر.ي</div>
            </div>
            
            <div>
                <button type="button" class="remove-product-btn w-full px-3 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const productsContainer = document.getElementById('products-container');
    const addProductBtn = document.getElementById('add-product-btn');
    const productTemplate = document.getElementById('product-row-template');
    
    // Add first product row on page load
    addProductRow();
    
    addProductBtn.addEventListener('click', addProductRow);
    
    function addProductRow() {
        const newRow = productTemplate.content.cloneNode(true);
        productsContainer.appendChild(newRow);
        updateEventListeners();
        calculateTotals();
    }
    
    function updateEventListeners() {
        // Remove product buttons
        document.querySelectorAll('.remove-product-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (document.querySelectorAll('.product-row').length > 1) {
                    this.closest('.product-row').remove();
                    calculateTotals();
                }
            });
        });
        
        // Product selection change
        document.querySelectorAll('.product-select').forEach(select => {
            select.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const priceInput = this.closest('.product-row').querySelector('.price-input');
                if (selectedOption.dataset.price) {
                    priceInput.value = selectedOption.dataset.price;
                }
                calculateRowTotal(this.closest('.product-row'));
                calculateTotals();
            });
        });
        
        // Quantity and price changes
        document.querySelectorAll('.quantity-input, .price-input').forEach(input => {
            input.addEventListener('input', function() {
                calculateRowTotal(this.closest('.product-row'));
                calculateTotals();
            });
        });
    }
    
    function calculateRowTotal(row) {
        const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        const total = quantity * price;
        row.querySelector('.row-total').textContent = total.toFixed(2) + ' ر.ي';
    }
    
    function calculateTotals() {
        let subtotal = 0;
        
        document.querySelectorAll('.product-row').forEach(row => {
            const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            subtotal += quantity * price;
        });
        
        const taxAmount = subtotal * 0.15;
        const totalAmount = subtotal + taxAmount;
        
        document.getElementById('subtotal-amount').textContent = subtotal.toFixed(2) + ' ر.ي';
        document.getElementById('tax-amount').textContent = taxAmount.toFixed(2) + ' ر.ي';
        document.getElementById('total-amount').textContent = totalAmount.toFixed(2) + ' ر.ي';
    }
    
    // Initialize event listeners
    updateEventListeners();
});
</script>

<?php include '../../includes/footer.php'; ?>
