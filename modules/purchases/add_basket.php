<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/purchase_helpers.php';

$page_title = 'إنشاء سلة شراء جديدة';
$error_message = '';

// Redirect to new complete basket system
if (!isset($_GET['old'])) {
    header('Location: basket_complete.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_basket'])) {
    try {
        $db->beginTransaction();
        
        $basket_name = trim($_POST['basket_name'] ?? '');
        if (empty($basket_name)) throw new Exception('يرجى إدخال اسم السلة');
        
        $selected_orders = json_decode($_POST['selected_orders'] ?? '[]', true);
        if (empty($selected_orders)) throw new Exception('يرجى اختيار طلب واحد على الأقل');
        
        $basket_code = generateBasketCode($db);
        $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
        $expected_delivery_date = !empty($_POST['expected_delivery_date']) ? $_POST['expected_delivery_date'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
        
        $stmt = $db->prepare("INSERT INTO purchase_baskets (basket_code, basket_name, supplier_id, purchase_date, expected_delivery_date, notes, shipping_cost, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)");
        $stmt->execute([$basket_code, $basket_name, $supplier_id, $purchase_date, $expected_delivery_date, $notes, $shipping_cost, $_SESSION['user_id']]);
        
        $basket_id = $db->lastInsertId();
        
        foreach ($selected_orders as $order_id) {
            $order_stmt = $db->prepare("SELECT subtotal_amount, discount_amount, final_amount FROM customer_orders WHERE id = ?");
            $order_stmt->execute([$order_id]);
            $order = $order_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                $item_stmt = $db->prepare("INSERT INTO basket_items (basket_id, order_id, quantity, unit_price, total_price, added_by) VALUES (?, ?, 1, ?, ?, ?)");
                $item_stmt->execute([$basket_id, $order_id, $order['final_amount'], $order['final_amount'], $_SESSION['user_id']]);
                
                $update_order = $db->prepare("UPDATE customer_orders SET status = 'in_basket', basket_id = ? WHERE id = ?");
                $update_order->execute([$basket_id, $order_id]);
            }
        }
        
        updateBasketTotals($db, $basket_id);
        $db->commit();
        
        $_SESSION['success_message'] = "تم إنشاء سلة الشراء بنجاح: $basket_code";
        header("Location: index.php");
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
    }
}

$suppliers = [];
try {
    $suppliers_stmt = $db->query("SELECT id, name, company_name FROM suppliers WHERE is_active = 1 ORDER BY name");
    $suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

include '../../includes/header.php';
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="basket_style.css">
</head>
<body class="bg-gray-50">

<div class="container mx-auto px-4 py-8" style="max-width: 1400px;">
    
    <div class="bg-gradient-to-r from-purple-600 via-purple-700 to-indigo-700 rounded-2xl shadow-2xl p-10 mb-8">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-4xl font-black text-white flex items-center gap-4">
                    <i class="fas fa-shopping-basket"></i>
                    <?php echo $page_title; ?>
                </h1>
                <p class="text-purple-100 mt-3 text-lg">تجميع طلبات العملاء في سلة واحدة للشراء من المورد</p>
            </div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-right"></i>
                العودة
            </a>
        </div>
    </div>
    
    <?php if ($error_message): ?>
    <div class="bg-red-50 border-r-4 border-red-500 text-red-800 p-5 rounded-xl mb-6 shadow-md">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-3xl ml-4 text-red-600"></i>
            <span class="text-lg font-semibold"><?php echo $error_message; ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <form method="POST" id="basketForm">
        
        <div class="section-card">
            <div class="section-title">
                <i class="fas fa-info-circle text-purple-600"></i>
                معلومات السلة
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-tag ml-1 text-purple-600"></i>
                        اسم السلة *
                    </label>
                    <input type="text" name="basket_name" class="form-control" required placeholder="مثال: سلة شراء يناير 2025">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-truck ml-1 text-blue-600"></i>
                        المورد
                    </label>
                    <select name="supplier_id" class="form-control">
                        <option value="">-- اختر المورد --</option>
                        <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo $supplier['id']; ?>">
                            <?php echo htmlspecialchars($supplier['name']); ?>
                            <?php if ($supplier['company_name']): ?>
                                (<?php echo htmlspecialchars($supplier['company_name']); ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-calendar ml-1 text-amber-600"></i>
                        تاريخ الشراء *
                    </label>
                    <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-calendar-check ml-1 text-orange-600"></i>
                        تاريخ التسليم المتوقع
                    </label>
                    <input type="date" name="expected_delivery_date" class="form-control">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-shipping-fast ml-1 text-red-600"></i>
                        تكلفة الشحن (ريال)
                    </label>
                    <input type="number" name="shipping_cost" id="shipping_cost" class="form-control" step="0.001" min="0" value="0">
                </div>
            </div>
            
            <div class="mt-6">
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-sticky-note ml-1 text-yellow-600"></i>
                    ملاحظات
                </label>
                <textarea name="notes" class="form-control" rows="3" placeholder="أي ملاحظات إضافية..."></textarea>
            </div>
        </div>
        
        <div class="section-card">
            <div class="section-title">
                <i class="fas fa-user-plus text-blue-600"></i>
                إضافة طلبات إلى السلة
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="customer-search-container">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-search ml-1"></i>
                        ابحث عن العميل (الاسم / الهاتف)
                    </label>
                    <input type="text" id="customer_search" class="form-control" placeholder="ابدأ الكتابة للبحث..." autocomplete="off">
                    <div id="customerResults" class="search-results"></div>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-user ml-1"></i>
                        العميل المختار
                    </label>
                    <input type="text" id="customer_name_display" class="form-control bg-gray-100" readonly placeholder="لم يتم اختيار عميل">
                    <input type="hidden" id="customer_id">
                </div>
            </div>
            
            <div id="ordersContainer" style="display: none;">
                <h3 class="text-xl font-black text-gray-800 mb-5 flex items-center gap-3">
                    <i class="fas fa-list text-purple-600"></i>
                    طلبات العميل
                </h3>
                <div id="ordersList" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"></div>
            </div>
            
            <div id="orderDetailsPanel" class="order-details-panel"></div>
        </div>
        
        <div class="section-card">
            <div class="section-title">
                <i class="fas fa-shopping-cart text-amber-600"></i>
                محتويات السلة
                <span id="itemsCount" class="badge-info order-badge">0 طلب</span>
            </div>
            
            <div id="basketItemsContainer">
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p class="text-2xl font-bold mb-3">لم يتم إضافة أي طلبات بعد</p>
                    <p class="text-base">ابحث عن عميل واختر طلباته لإضافتها إلى السلة</p>
                </div>
            </div>
            
            <table id="basketItemsTable" class="basket-table" style="display: none;">
                <thead>
                    <tr>
                        <th style="width: 60px;">#</th>
                        <th>رقم الطلب</th>
                        <th>العميل</th>
                        <th>تاريخ الطلب</th>
                        <th>المبلغ قبل الخصم</th>
                        <th>الخصم</th>
                        <th>المبلغ النهائي</th>
                        <th style="width: 120px;">إجراءات</th>
                    </tr>
                </thead>
                <tbody id="basketItemsBody"></tbody>
            </table>
            
            <div id="totalsBox" class="totals-box" style="display: none;">
                <div class="total-row">
                    <span><i class="fas fa-calculator ml-2"></i>المجموع قبل الخصم:</span>
                    <span id="subtotal" class="font-black">0.000 ريال</span>
                </div>
                <div class="total-row">
                    <span><i class="fas fa-tag ml-2"></i>إجمالي الخصم:</span>
                    <span id="totalDiscount" class="font-black">0.000 ريال</span>
                </div>
                <div class="total-row">
                    <span><i class="fas fa-shipping-fast ml-2"></i>تكلفة الشحن:</span>
                    <span id="shippingCostDisplay" class="font-black">0.000 ريال</span>
                </div>
                <div class="total-row grand-total">
                    <span><i class="fas fa-money-bill-wave ml-2"></i>صافي السلة:</span>
                    <span id="grandTotal">0.000 ريال</span>
                </div>
            </div>
        </div>
        
        <div class="flex justify-between items-center">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-times"></i>
                إلغاء
            </a>
            <button type="submit" name="save_basket" class="btn btn-success" id="saveBasketBtn" disabled>
                <i class="fas fa-save"></i>
                حفظ السلة
            </button>
        </div>
        
        <input type="hidden" name="selected_orders" id="selectedOrdersInput">
    </form>
</div>

<style>
/* Search Results Styling */
.search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #e5e7eb;
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 400px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.customer-search-container {
    position: relative;
}

.search-results-list {
    padding: 8px;
}

.search-result-item {
    padding: 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.search-result-item:hover {
    background: #f3f4f6;
    border-color: #3b82f6;
}

.result-main {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
}

.result-name {
    font-weight: 600;
    color: #1f2937;
    font-size: 15px;
}

.result-code {
    font-size: 12px;
    color: #6b7280;
    background: #f3f4f6;
    padding: 2px 8px;
    border-radius: 4px;
}

.result-details {
    display: flex;
    gap: 16px;
    font-size: 13px;
    color: #6b7280;
}

.result-details span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.search-loading, .search-no-results, .search-error {
    padding: 16px;
    text-align: center;
    color: #6b7280;
}

.search-error {
    color: #ef4444;
}

/* Order Details Panel */
.order-details-panel {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    max-width: 800px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    z-index: 2000;
}

.details-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 2px solid #e5e7eb;
    background: #f9fafb;
}

.details-header h3 {
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
}

.btn-close {
    background: #ef4444;
    color: white;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-close:hover {
    background: #dc2626;
    transform: rotate(90deg);
}

.details-body {
    padding: 20px;
}

.details-section {
    margin-bottom: 24px;
}

.details-section h4 {
    font-size: 16px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}

.details-grid div {
    padding: 8px;
    background: #f9fafb;
    border-radius: 6px;
    font-size: 14px;
}

.details-table {
    width: 100%;
    border-collapse: collapse;
}

.details-table th,
.details-table td {
    padding: 10px;
    text-align: right;
    border-bottom: 1px solid #e5e7eb;
}

.details-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
}

.details-amounts {
    background: #f9fafb;
    padding: 16px;
    border-radius: 8px;
}

.details-amounts > div {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e5e7eb;
}

.details-amounts > div:last-child {
    border-bottom: none;
}

.details-amounts .total {
    font-size: 18px;
    font-weight: 700;
    color: #059669;
    padding-top: 12px;
    border-top: 2px solid #C7A46D;
}

/* Loading Spinner */
.loading-spinner {
    text-align: center;
    padding: 40px;
    color: #6b7280;
}

/* Badges */
.badge-info { background: #3b82f6; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; }
.badge-success { background: #C7A46D; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; }
.badge-warning { background: #f59e0b; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; }
.badge-danger { background: #ef4444; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; }
.badge-primary { background: #8b5cf6; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; }

/* Overlay for modal */
.order-details-panel::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: -1;
}
</style>

<script src="basket_api.js"></script>
</body>
</html>
<?php include '../../includes/footer.php'; ?>
