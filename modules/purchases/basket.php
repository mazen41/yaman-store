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

// Redirect to new basket system
if (!isset($_GET['old'])) {
    header('Location: basket_complete.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_purchase_order') {
    try {
        $db->beginTransaction();
        
        // Collect main order data
        $order_number = trim($_POST['order_number']);
        $serial_number = trim($_POST['serial_number']);
        $purchase_date = $_POST['purchase_date'];
        $purchase_group_id = !empty($_POST['purchase_group_id']) ? intval($_POST['purchase_group_id']) : null;
        $discount_before = floatval($_POST['discount_before'] ?? 0);
        $discount_after = floatval($_POST['discount_after'] ?? 0);
        $tracking_code_1 = trim($_POST['tracking_code_1'] ?? '');
        $tracking_code_2 = trim($_POST['tracking_code_2'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // Validate required fields
        if (empty($order_number) || empty($serial_number) || empty($purchase_date)) {
            throw new Exception('رقم سلة الشراء والرقم التسلسلي وتاريخ الشراء مطلوبة');
        }
        
        // Calculate totals from items
        $total_items = 0;
        $total_price = 0;
        
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['client_name'])) {
                    $total_items += intval($item['quantity'] ?? 0);
                    $total_price += floatval($item['price'] ?? 0);
                }
            }
        }
        
        $price_after_discount = $total_price - $discount_before - $discount_after;
        
        // Insert main purchase order
        $stmt = $db->prepare("
            INSERT INTO purchase_order_baskets (
                order_number, serial_number, purchase_date, purchase_group_id,
                total_items, total_discount_before, total_discount_after,
                total_price_before_discount, total_price_after_discount,
                tracking_code_1, tracking_code_2, notes, created_by, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $order_number,
            $serial_number,
            $purchase_date,
            $purchase_group_id,
            $total_items,
            $discount_before,
            $discount_after,
            $total_price,
            $price_after_discount,
            $tracking_code_1 ?: null,
            $tracking_code_2 ?: null,
            $notes ?: null,
            $_SESSION['user_id']
        ]);
        
        $basket_id = $db->lastInsertId();
        
        // Insert items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $item_stmt = $db->prepare("
                INSERT INTO purchase_order_basket_items (
                    basket_id, item_number, client_name, client_order_number,
                    client_phone, item_quantity, item_price, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $item_number = 1;
            foreach ($_POST['items'] as $item) {
                if (!empty($item['client_name'])) {
                    $item_stmt->execute([
                        $basket_id,
                        $item_number++,
                        $item['client_name'],
                        $item['order_number'] ?? '',
                        $item['phone'] ?? '',
                        intval($item['quantity'] ?? 1),
                        floatval($item['price'] ?? 0),
                        $item['notes'] ?? null
                    ]);
                }
            }
        }
        
        // Insert additional tracking codes
        if (isset($_POST['additional_codes']) && is_array($_POST['additional_codes'])) {
            $code_stmt = $db->prepare("
                INSERT INTO purchase_order_tracking_codes (basket_id, code, description)
                VALUES (?, ?, ?)
            ");
            
            foreach ($_POST['additional_codes'] as $code_data) {
                if (!empty($code_data['code'])) {
                    $code_stmt->execute([
                        $basket_id,
                        $code_data['code'],
                        $code_data['description'] ?? null
                    ]);
                }
            }
        }
        
        $db->commit();
        $success_message = 'تم إنشاء طلب الشراء بنجاح! رقم الطلب: ' . $order_number;
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'خطأ: ' . $e->getMessage();
    }
}

// Check if tables exist and fetch data
$purchase_groups = [];
$purchase_orders = [];

try {
    // Fetch purchase groups
    $groups_stmt = $db->query("SELECT * FROM purchase_groups WHERE is_active = 1 ORDER BY group_name");
    $purchase_groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all purchase orders for display
    $orders_stmt = $db->query("
        SELECT pob.*, pg.group_name,
               COUNT(DISTINCT pobi.id) as customer_count
        FROM purchase_order_baskets pob
        LEFT JOIN purchase_groups pg ON pob.purchase_group_id = pg.id
        LEFT JOIN purchase_order_basket_items pobi ON pob.id = pobi.basket_id
        GROUP BY pob.id
        ORDER BY pob.created_at DESC
        LIMIT 20
    ");
    $purchase_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tables don't exist yet
    $error_message = 'يرجى تشغيل سكريبت إعداد قاعدة البيانات أولاً: <a href="../../setup_purchase_order_system.php" style="color: #fff; text-decoration: underline;">اضغط هنا لإعداد قاعدة البيانات</a>';
}

include '../../includes/header.php';
?>

<style>
.purchase-order-form {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
.form-section {
    padding: 20px;
    border-bottom: 1px solid #e5e7eb;
}
.form-section:last-child {
    border-bottom: none;
}
.section-header {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 15px;
    padding: 10px;
    border-radius: 4px;
}
.bg-primary { background-color: #3b82f6; color: white; }
.bg-info { background-color: #06b6d4; color: white; }
.bg-warning { background-color: #f59e0b; color: white; }
.bg-secondary { background-color: #6b7280; color: white; }
.bg-dark { background-color: #1f2937; color: white; }
.table-responsive {
    overflow-x: auto;
}
.items-table {
    width: 100%;
    border-collapse: collapse;
}
.items-table th,
.items-table td {
    border: 1px solid #d1d5db;
    padding: 8px;
    text-align: center;
}
.items-table th {
    background-color: #f3f4f6;
    font-weight: bold;
}
.btn {
    padding: 8px 16px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s;
}
.btn-success { background-color: #C7A46D; color: white; }
.btn-success:hover { background-color: #059669; }
.btn-danger { background-color: #ef4444; color: white; }
.btn-danger:hover { background-color: #dc2626; }
.btn-info { background-color: #06b6d4; color: white; }
.btn-info:hover { background-color: #0891b2; }
.btn-primary { background-color: #3b82f6; color: white; }
.btn-primary:hover { background-color: #2563eb; }
.alert {
    padding: 12px 20px;
    border-radius: 4px;
    margin-bottom: 20px;
}
.alert-success {
    background-color: #d1fae5;
    border: 1px solid #C7A46D;
    color: #065f46;
}
.alert-danger {
    background-color: #fee2e2;
    border: 1px solid #ef4444;
    color: #991b1b;
}
.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 14px;
}
.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.totals-box {
    background-color: #f9fafb;
    padding: 15px;
    border-radius: 4px;
    margin-top: 10px;
}
.totals-box h4 {
    margin: 10px 0;
    font-size: 20px;
}
</style>

<div class="container-fluid" dir="rtl">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4">إنشاء طلبات الشراء (سلة بالشراء)</h1>
            
            <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" id="purchaseOrderForm" class="purchase-order-form">
                <input type="hidden" name="action" value="create_purchase_order">
                
                <!-- Section 1: Basic Information -->
                <div class="form-section">
                    <div class="section-header bg-primary">
                        إنشاء طلب شراء (سلة بالشراء)
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label>رقم سلة الشراء <span class="text-danger">*</span></label>
                            <input type="text" name="order_number" class="form-control" required 
                                   placeholder="مثال: PO-2025-001" value="PO-<?php echo date('Y'); ?>-<?php echo str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>الرقم التسلسلي <span class="text-danger">*</span></label>
                            <input type="text" name="serial_number" class="form-control" required 
                                   placeholder="مثال: SN-001" value="SN-<?php echo str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>تاريخ الشراء <span class="text-danger">*</span></label>
                            <input type="date" name="purchase_date" class="form-control" required 
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>اختيار مجموعة الشراء</label>
                            <select name="purchase_group_id" class="form-control">
                                <option value="">-- اختر مجموعة --</option>
                                <?php foreach ($purchase_groups as $group): ?>
                                <option value="<?php echo $group['id']; ?>">
                                    <?php echo htmlspecialchars($group['group_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Section 2: Customer Orders Table -->
                <div class="form-section">
                    <div class="section-header bg-info">
                        إضافة عدة طلبات شراء تحتوي على عدة سلات شراء تم قوم بالشراء طلبات شراء (سلة بالشراء)
                        <p style="margin: 5px 0 0 0; font-size: 14px;">حسب الجدول التالية:</p>
                    </div>
                    <div class="table-responsive">
                        <table class="items-table" id="itemsTable">
                            <thead>
                                <tr>
                                    <th width="5%">م</th>
                                    <th width="20%">اسم العميل</th>
                                    <th width="15%">رقم الطلب</th>
                                    <th width="15%">رقم جوال العميل</th>
                                    <th width="10%">عدد القطع</th>
                                    <th width="15%">قيمة الطلب</th>
                                    <th width="15%">ملاحظات</th>
                                    <th width="5%">حذف</th>
                                </tr>
                            </thead>
                            <tbody id="itemsTableBody">
                                <tr>
                                    <td>1</td>
                                    <td><input type="text" name="items[0][client_name]" class="form-control" placeholder="اسم العميل"></td>
                                    <td><input type="text" name="items[0][order_number]" class="form-control" placeholder="رقم الطلب"></td>
                                    <td><input type="text" name="items[0][phone]" class="form-control" placeholder="05xxxxxxxx"></td>
                                    <td><input type="number" name="items[0][quantity]" class="form-control" value="1" min="1" onchange="calculateTotals()"></td>
                                    <td><input type="number" name="items[0][price]" class="form-control" step="0.01" min="0" placeholder="0.00" onchange="calculateTotals()"></td>
                                    <td><input type="text" name="items[0][notes]" class="form-control" placeholder="ملاحظات"></td>
                                    <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)" disabled><i class="fas fa-trash"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-success mt-3" onclick="addItemRow()">
                        <i class="fas fa-plus"></i> إضافة عميل
                    </button>
                </div>
                
                <!-- Section 3: Calculations -->
                <div class="form-section">
                    <div class="section-header bg-warning">
                        إجمالي عدد القطع (إجمالي من طلبات الشراء)
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="totals-box">
                                <strong>سعر السلة قبل الخصم (قابل للتعديل):</strong>
                                <h4 id="priceBeforeDiscount">0.00 ريال</h4>
                            </div>
                            <div class="mt-3">
                                <label>خصم النقطة</label>
                                <input type="number" step="0.01" name="discount_before" class="form-control" value="0" onchange="calculateTotals()">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="totals-box">
                                <strong>سعر السلة بعد الخصم (قابل للتعديل):</strong>
                                <h4 id="priceAfterDiscount">0.00 ريال</h4>
                            </div>
                            <div class="mt-3">
                                <label>خصم النادي</label>
                                <input type="number" step="0.01" name="discount_after" class="form-control" value="0" onchange="calculateTotals()">
                            </div>
                        </div>
                    </div>
                    <div class="totals-box mt-3">
                        <strong>إجمالي عدد القطع:</strong>
                        <h4 id="totalItems">0</h4>
                    </div>
                </div>
                
                <!-- Section 4: Tracking Management -->
                <div class="form-section">
                    <div class="section-header bg-secondary">
                        إدارة حالة سلة الشراء
                    </div>
                    <div class="alert alert-warning">
                        <p><strong>إضافة حقل رمز تتبع</strong> - وقد تكون السلة تحتوي على أكثر من رمز</p>
                        <p>تضيف حقل رمز تتبع - وقد تكون السلة تحتوي على أكثر من رمز</p>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>قيد التعليق</label>
                            <input type="text" name="tracking_code_1" class="form-control" placeholder="رمز التتبع 1">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>قيد الشحن</label>
                            <input type="text" name="tracking_code_2" class="form-control" placeholder="رمز التتبع 2">
                        </div>
                    </div>
                    <button type="button" class="btn btn-info" onclick="addTrackingCode()">
                        <i class="fas fa-plus"></i> إضافة رمز آخر
                    </button>
                    <div id="additionalTrackingCodes" class="mt-3"></div>
                </div>
                
                <!-- Notes Section -->
                <div class="form-section">
                    <label>ملاحظات إضافية</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="أي ملاحظات إضافية..."></textarea>
                </div>
                
                <!-- Submit Button -->
                <div class="form-section text-center">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save"></i> حفظ طلب الشراء
                    </button>
                    <a href="index.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-times"></i> إلغاء
                    </a>
                </div>
            </form>
            
            <!-- Section 5: Display Purchase Orders List -->
            <div class="purchase-order-form mt-4">
                <div class="form-section">
                    <div class="section-header bg-dark">
                        عرض قائمة طلبات الشراء
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>رقم التسلسلي</th>
                                    <th>تاريخ الشراء</th>
                                    <th>مجموعة الشراء</th>
                                    <th>عدد العملاء</th>
                                    <th>إجمالي القطع</th>
                                    <th>السعر بعد الخصم</th>
                                    <th>السعر قبل الخصم</th>
                                    <th>كود التتبع 1</th>
                                    <th>كود التتبع 2</th>
                                    <th>الحالة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($purchase_orders)): ?>
                                <tr>
                                    <td colspan="12" class="text-center">لا توجد طلبات شراء حتى الآن</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($purchase_orders as $order): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($order['serial_number']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($order['purchase_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($order['group_name'] ?? '-'); ?></td>
                                    <td><?php echo $order['customer_count']; ?></td>
                                    <td><?php echo $order['total_items']; ?></td>
                                    <td><?php echo number_format($order['total_price_after_discount'], 0, '', ''); ?> ريال</td>
                                    <td><?php echo number_format($order['total_price_before_discount'], 0, '', ''); ?> ريال</td>
                                    <td><?php echo htmlspecialchars($order['tracking_code_1'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($order['tracking_code_2'] ?? '-'); ?></td>
                                    <td>
                                        <?php
                                        $status_labels = [
                                            'pending' => '<span class="badge bg-warning">قيد الانتظار</span>',
                                            'in_progress' => '<span class="badge bg-info">قيد التنفيذ</span>',
                                            'completed' => '<span class="badge bg-success">مكتمل</span>',
                                            'cancelled' => '<span class="badge bg-danger">ملغي</span>'
                                        ];
                                        echo $status_labels[$order['status']] ?? $order['status'];
                                        ?>
                                    </td>
                                    <td>
                                        <a href="view_basket.php?id=<?php echo $order['id']; ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-eye"></i> عرض
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let rowCount = 1;
let trackingCodeCount = 0;

// Add new item row
function addItemRow() {
    const tbody = document.getElementById('itemsTableBody');
    rowCount++;
    
    const row = tbody.insertRow();
    row.innerHTML = `
        <td>${rowCount}</td>
        <td><input type="text" name="items[${rowCount-1}][client_name]" class="form-control" placeholder="اسم العميل"></td>
        <td><input type="text" name="items[${rowCount-1}][order_number]" class="form-control" placeholder="رقم الطلب"></td>
        <td><input type="text" name="items[${rowCount-1}][phone]" class="form-control" placeholder="05xxxxxxxx"></td>
        <td><input type="number" name="items[${rowCount-1}][quantity]" class="form-control" value="1" min="1" onchange="calculateTotals()"></td>
        <td><input type="number" name="items[${rowCount-1}][price]" class="form-control" step="0.01" min="0" placeholder="0.00" onchange="calculateTotals()"></td>
        <td><input type="text" name="items[${rowCount-1}][notes]" class="form-control" placeholder="ملاحظات"></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-trash"></i></button></td>
    `;
    
    updateRowNumbers();
}

// Remove row
function removeRow(btn) {
    const row = btn.closest('tr');
    row.remove();
    rowCount--;
    updateRowNumbers();
    calculateTotals();
}

// Update row numbers
function updateRowNumbers() {
    const tbody = document.getElementById('itemsTableBody');
    const rows = tbody.getElementsByTagName('tr');
    for (let i = 0; i < rows.length; i++) {
        rows[i].cells[0].textContent = i + 1;
    }
    
    // Enable/disable delete buttons
    const deleteButtons = tbody.querySelectorAll('.btn-danger');
    deleteButtons.forEach((btn, index) => {
        btn.disabled = (deleteButtons.length === 1);
    });
}

// Calculate totals
function calculateTotals() {
    const tbody = document.getElementById('itemsTableBody');
    const rows = tbody.getElementsByTagName('tr');
    
    let totalItems = 0;
    let totalPrice = 0;
    
    for (let i = 0; i < rows.length; i++) {
        const quantityInput = rows[i].querySelector('input[name*="[quantity]"]');
        const priceInput = rows[i].querySelector('input[name*="[price]"]');
        
        if (quantityInput && priceInput) {
            const quantity = parseFloat(quantityInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            
            totalItems += quantity;
            totalPrice += price;
        }
    }
    
    const discountBefore = parseFloat(document.querySelector('input[name="discount_before"]').value) || 0;
    const discountAfter = parseFloat(document.querySelector('input[name="discount_after"]').value) || 0;
    
    const priceAfterDiscount = totalPrice - discountBefore - discountAfter;
    
    document.getElementById('totalItems').textContent = totalItems;
    document.getElementById('priceBeforeDiscount').textContent = totalPrice.toFixed(2) + ' ريال';
    document.getElementById('priceAfterDiscount').textContent = priceAfterDiscount.toFixed(2) + ' ريال';
}

// Add tracking code
function addTrackingCode() {
    trackingCodeCount++;
    const container = document.getElementById('additionalTrackingCodes');
    
    const div = document.createElement('div');
    div.className = 'row mb-2';
    div.innerHTML = `
        <div class="col-md-5">
            <input type="text" name="additional_codes[${trackingCodeCount}][code]" class="form-control" placeholder="رمز التتبع">
        </div>
        <div class="col-md-5">
            <input type="text" name="additional_codes[${trackingCodeCount}][description]" class="form-control" placeholder="وصف الرمز">
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-danger" onclick="this.closest('.row').remove()">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(div);
}

// Form validation
document.getElementById('purchaseOrderForm').addEventListener('submit', function(e) {
    const tbody = document.getElementById('itemsTableBody');
    const rows = tbody.getElementsByTagName('tr');
    let hasItems = false;
    
    for (let i = 0; i < rows.length; i++) {
        const clientName = rows[i].querySelector('input[name*="[client_name]"]');
        if (clientName && clientName.value.trim() !== '') {
            hasItems = true;
            break;
        }
    }
    
    if (!hasItems) {
        e.preventDefault();
        alert('يجب إضافة عميل واحد على الأقل');
        return false;
    }
});

// Initialize calculations
calculateTotals();
</script>

<?php include '../../includes/footer.php'; ?>
