<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/accounting_functions.php';

$page_title = 'إضافة شحنة جديدة';
$error_message = '';
$success_message = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db->beginTransaction();

        // Collect order refs — new format: "customer:123" or "shop:45"
        $order_refs = $_POST['order_refs'] ?? [];

        // Backward compatibility: accept old order_ids[] or order_id (treat as customer source)
        if (isset($_POST['order_ids']) && is_array($_POST['order_ids'])) {
            foreach ($_POST['order_ids'] as $legacy_id) {
                $legacy_id = trim($legacy_id);
                if ($legacy_id === '') continue;
                if (strpos($legacy_id, ':') !== false) {
                    $order_refs[] = $legacy_id;
                } else {
                    $order_refs[] = 'customer:' . $legacy_id;
                }
            }
        }
        if (isset($_POST['order_id']) && !empty($_POST['order_id'])) {
            $order_refs[] = 'customer:' . $_POST['order_id'];
        }

        $order_refs = array_unique(array_filter($order_refs));

        if (empty($order_refs)) throw new Exception('يرجى اختيار طلب واحد على الأقل');

        $sender_id           = intval($_POST['sender_id'] ?? 0);
        $tracking_number     = trim($_POST['tracking_number'] ?? '');
        $shipping_cost       = floatval($_POST['shipping_cost']);
        $delivery_address    = trim($_POST['delivery_address']);
        $recipient_name      = trim($_POST['recipient_name']);
        $recipient_phone     = trim($_POST['recipient_phone']);
        $estimated_delivery  = $_POST['estimated_delivery'] ?? null;
        $notes               = trim($_POST['notes'] ?? '');
        $status              = $_POST['status'] ?? 'preparing';

        // Validation
        if (empty($sender_id))        throw new Exception('يرجى اختيار المرسل');
        if (empty($delivery_address)) throw new Exception('عنوان التسليم مطلوب');
        if (empty($recipient_name))   throw new Exception('اسم المستلم مطلوب');
        if (empty($recipient_phone))  throw new Exception('رقم هاتف المستلم مطلوب');

        // Generate shipment number
        $shipment_number = 'SHP-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $check = $db->prepare("SELECT id FROM shipments WHERE shipment_number = ?");
        $check->execute([$shipment_number]);
        if ($check->fetch()) {
            $shipment_number = 'SHP-' . date('Y') . '-' . str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        }

        // Insert shipment
        $stmt = $db->prepare("
            INSERT INTO shipments 
            (shipment_number, order_id, sender_id, tracking_number, shipping_cost,
             delivery_address, recipient_name, recipient_phone, estimated_delivery, notes, status, shipping_company, created_by) 
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $shipment_number, $sender_id, $tracking_number ?: null,
            $shipping_cost, $delivery_address, $recipient_name, $recipient_phone,
            $estimated_delivery ?: null, $notes, $status, '', $_SESSION['user_id']
        ]);
        $shipment_id = $db->lastInsertId();

        // Link orders & update their shipping status (per source)
        $insert_link           = $db->prepare("INSERT INTO shipment_orders (shipment_id, order_id, order_source) VALUES (?, ?, ?)");
        $update_customer_ship  = $db->prepare("UPDATE customer_orders SET shipping_status = ? WHERE id = ?");
        $update_shop_ship      = $db->prepare("UPDATE shop_orders SET shipping_status = ? WHERE id = ?");

        // Map english status → Arabic text for shop side (optional, for display)
        $shop_status_map = [
            'preparing'        => 'قيد التجهيز للشحن',
            'picked_up'        => 'تم الاستلام',
            'in_transit'       => 'في الطريق',
            'out_for_delivery' => 'خرج للتوصيل',
            'delivered'        => 'تم التسليم',
        ];

        foreach ($order_refs as $ref) {
            $parts = explode(':', $ref, 2);
            if (count($parts) !== 2) continue;
            $source = $parts[0];
            $oid    = intval($parts[1]);
            if ($oid <= 0) continue;
            if (!in_array($source, ['customer', 'shop'], true)) continue;

            $insert_link->execute([$shipment_id, $oid, $source]);

            if ($source === 'customer') {
                $update_customer_ship->execute([$status, $oid]);
            } else {
                $shop_display_status = $shop_status_map[$status] ?? $status;
                $update_shop_ship->execute([$shop_display_status, $oid]);
            }
        }

        // Initial tracking entry
        $db->prepare("
            INSERT INTO shipment_tracking 
            (shipment_id, status, description, occurred_at) 
            VALUES (?, ?, 'تم إنشاء الشحنة', NOW())
        ")->execute([$shipment_id, $status]);

        // ===================================================================
        // Accounting entry (unchanged)
        // ===================================================================
        if ($shipping_cost > 0) {
            try {
                $shipping_expense_account_id = get_accounting_setting($db, 'default_shipping_expense_account_id');
                $shipping_payment_account_id = get_accounting_setting($db, 'default_shipping_payment_account_id');

                $description = "مصروف شحن للطلبات المرتبطة بالشحنة رقم " . $shipment_number;
                $entry_items = [
                    ['account_id' => $shipping_expense_account_id, 'type' => 'debit',  'amount' => $shipping_cost],
                    ['account_id' => $shipping_payment_account_id, 'type' => 'credit', 'amount' => $shipping_cost],
                ];

                create_journal_entry(
                    $db, date('Y-m-d'), $description, $entry_items,
                    'shipping', $shipment_id, $_SESSION['user_id']
                );
            } catch (Exception $acc_e) {
                error_log("Accounting entry failed for Shipment ID $shipment_id: " . $acc_e->getMessage());
            }
        }

        $db->commit();
        $success_message = 'تم إضافة الشحنة بنجاح!';
        header("refresh:2;url=view.php?id=$shipment_id");

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
    }
}

// -------------------------------------------------------------------
// Fetch customer orders (not yet shipped, not cancelled)
// -------------------------------------------------------------------
$customer_orders = $db->query("
    SELECT 
        o.id, 
        o.order_number, 
        o.final_amount, 
        o.status,
        o.created_at,
        c.name AS customer_name, 
        c.mobile_number, 
        c.address, 
        c.location_url, 
        c.city_name,
        'customer' AS source_type,
        CONCAT('customer:', o.id) AS ref_id
    FROM customer_orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.status != 'cancelled'
      AND NOT EXISTS (
          SELECT 1 FROM shipment_orders so 
          WHERE so.order_id = o.id AND so.order_source = 'customer'
      )
    ORDER BY o.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------
// Fetch shop orders (from the shop portal) — not cancelled, not yet shipped
// -------------------------------------------------------------------
$shop_orders = $db->query("
    SELECT 
        o.id, 
        o.order_number, 
        o.total_amount AS final_amount, 
        o.order_status AS status,
        o.created_at,
        c.name AS customer_name, 
        c.mobile_number, 
        c.address, 
        NULL AS location_url, 
        c.city_name,
        'shop' AS source_type,
        CONCAT('shop:', o.id) AS ref_id
    FROM shop_orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.order_status NOT IN ('ملغى', 'ملغي', 'cancelled', 'مرفوض')
      AND NOT EXISTS (
          SELECT 1 FROM shipment_orders so 
          WHERE so.order_id = o.id AND so.order_source = 'shop'
      )
    ORDER BY o.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Combine & sort (latest first)
$all_orders = array_merge($customer_orders, $shop_orders);
usort($all_orders, function ($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Fetch shipping companies (unchanged, kept in case used elsewhere)
$companies = $db->query("
    SELECT * FROM shipping_companies 
    WHERE is_active = 1 
    ORDER BY company_name
")->fetchAll();

// Fetch senders
$senders = $db->query("
    SELECT id, name, phone, email 
    FROM senders 
    ORDER BY name ASC
")->fetchAll();

include '../../includes/header.php';
?>

<style>
.order-details-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    display: none;
}
.order-details-card.active { display: block; animation: slideDown 0.3s ease-out; }
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
}
.info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.2);
}
.info-row:last-child { border-bottom: none; }

/* Source badges */
.src-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    margin-right: 6px;
    white-space: nowrap;
}
.src-badge.shop     { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }
.src-badge.customer { background: #DBEAFE; color: #1E40AF; border: 1px solid #93C5FD; }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-600 to-emerald-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <h1 class="text-3xl font-bold text-white flex items-center gap-3">
                    <i class="fas fa-shipping-fast"></i>
                    إضافة شحنة جديدة
                </h1>
                <p class="text-amber-100 mt-2">اختر الطلبات (من النظام أو من المتجر) وسيتم تعبئة بيانات العميل تلقائياً</p>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="bg-amber-100 border-r-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6 shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-2xl ml-3"></i>
                    <div>
                        <p class="font-medium"><?php echo $success_message; ?></p>
                        <p class="text-sm mt-1">جاري التحويل إلى صفحة الشحنة...</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-2xl ml-3"></i>
                    <p class="font-medium"><?php echo $error_message; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" class="bg-white rounded-xl shadow-lg p-8 space-y-6" id="shipmentForm">
            
            <!-- Order Selection -->
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-shopping-cart text-blue-600 ml-1"></i>
                    الطلبات المشحونة <span class="text-red-500">*</span>
                    <span class="text-xs text-gray-500 font-normal">(تشمل طلبات النظام وطلبات المتجر)</span>
                </label>
                
                <div id="selectedOrdersContainer" class="mb-3 space-y-2">
                    <div id="noOrdersSelected" class="p-6 border-2 border-dashed border-gray-300 rounded-lg text-center text-gray-500 bg-gray-50">
                        <i class="fas fa-box-open text-3xl mb-2 text-gray-400"></i>
                        <p>لم يتم اختيار أي طلبات بعد</p>
                        <p class="text-xs mt-1">اضغط على الزر أدناه لاختيار الطلبات</p>
                    </div>
                </div>

                <button type="button" onclick="openAddOrderModal()" class="w-full py-4 border-2 border-dashed border-blue-400 rounded-lg text-blue-600 hover:bg-blue-50 font-bold transition-all duration-200 flex items-center justify-center gap-2 shadow-sm hover:shadow-md">
                    <i class="fas fa-plus-circle text-xl"></i>
                    <span class="text-lg">إضافة طلبات للشحنة</span>
                </button>
            </div>

            <!-- Customer & Delivery Info -->
            <div id="deliverySection" style="display: block;">
                <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2 bg-blue-50 px-4 py-3 rounded-lg">
                    <i class="fas fa-user-circle text-blue-600"></i>
                    بيانات العميل والتوصيل
                </h3>
                
                <input type="hidden" name="recipient_name"  id="recipient_name"  value="">
                <input type="hidden" name="recipient_phone" id="recipient_phone" value="">

                <div class="mt-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        عنوان التسليم <span class="text-red-500">*</span>
                    </label>
                    <textarea 
                        name="delivery_address" 
                        id="delivery_address"
                        required
                        rows="3"
                        class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500"
                        placeholder="سيتم تعبئته تلقائياً"
                    ></textarea>
                </div>

                <!-- Shipping Info -->
                <div class="mt-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2 bg-amber-50 px-4 py-3 rounded-lg">
                        <i class="fas fa-truck text-amber-600"></i>
                        معلومات الشحن
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                اسم المرسل <span class="text-red-500">*</span>
                            </label>
                            <div class="flex gap-2">
                                <select name="sender_id" id="sender_id" required
                                    class="flex-1 px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500">
                                    <option value="">-- اختر المرسل --</option>
                                    <?php foreach ($senders as $sender): ?>
                                        <option value="<?php echo $sender['id']; ?>">
                                            <?php echo htmlspecialchars($sender['name']); ?>
                                            <?php if ($sender['phone']): ?>
                                                - <?php echo htmlspecialchars($sender['phone']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <a href="senders.php" target="_blank" class="bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition-all flex items-center gap-2" title="إدارة المرسلين">
                                    <i class="fas fa-cog"></i>
                                </a>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-info-circle"></i> 
                                لإضافة مرسل جديد، اضغط على زر الإعدادات
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">رقم التتبع</label>
                            <input type="text" name="tracking_number" id="tracking_number"
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500"
                                placeholder="اختياري">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                تكلفة الشحن <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="shipping_cost" id="shipping_cost"
                                step="0.01" min="0" value="0" required
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">تاريخ التسليم المتوقع</label>
                            <input type="date" name="estimated_delivery" id="estimated_delivery"
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">حالة الشحنة</label>
                            <select name="status" id="status"
                                class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500">
                                <option value="preparing">قيد التجهيز</option>
                                <option value="picked_up">تم الاستلام</option>
                                <option value="in_transit">في الطريق</option>
                                <option value="out_for_delivery">خرج للتوصيل</option>
                                <option value="delivered">تم التسليم</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">ملاحظات</label>
                        <textarea name="notes" id="notes" rows="3"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500"
                            placeholder="أي ملاحظات إضافية..."></textarea>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="flex gap-4 pt-6 border-t">
                    <button type="submit" class="flex-1 bg-amber-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-amber-700 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                        <i class="fas fa-check-circle ml-2"></i>
                        إنشاء الشحنة
                    </button>
                    <a href="index.php" class="flex-1 bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-bold hover:bg-gray-300 transition-all duration-300 text-center">
                        <i class="fas fa-times ml-2"></i>
                        إلغاء
                    </a>
                </div>
            </div>

        </form>

    </div>
</div>

<!-- Add Order Modal -->
<div id="addOrderModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" onclick="if(event.target === this) closeAddOrderModal()">
    <div class="bg-white rounded-2xl p-8 max-w-3xl w-full mx-4 max-h-[85vh] overflow-hidden flex flex-col">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-bold text-gray-900">اختيار الطلبات للشحنة</h3>
            <button onclick="closeAddOrderModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-2xl"></i>
            </button>
        </div>
        
        <div class="mb-4">
            <div class="relative">
                <input type="text" id="orderSearch" placeholder="🔍 ابحث بالرقم، اسم العميل، أو المبلغ..." 
                       class="w-full px-4 py-3 pr-10 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-right"
                       oninput="filterOrders()">
                <i class="fas fa-search absolute right-3 top-4 text-gray-400"></i>
            </div>
        </div>

        <!-- Source filter chips -->
        <div class="mb-3 flex items-center gap-2">
            <span class="text-xs text-gray-500">عرض:</span>
            <button type="button" onclick="setSourceFilter('all')"      id="srcFilterAll"      class="src-filter-btn px-3 py-1 rounded-full text-xs font-bold bg-gray-800 text-white">الكل</button>
            <button type="button" onclick="setSourceFilter('customer')" id="srcFilterCustomer" class="src-filter-btn px-3 py-1 rounded-full text-xs font-bold bg-gray-200 text-gray-700">طلبات النظام</button>
            <button type="button" onclick="setSourceFilter('shop')"     id="srcFilterShop"     class="src-filter-btn px-3 py-1 rounded-full text-xs font-bold bg-gray-200 text-gray-700">طلبات المتجر</button>
        </div>

        <div class="mb-3 flex items-center justify-between bg-teal-50 px-4 py-2 rounded-lg">
            <span class="text-sm font-semibold text-teal-800">
                <i class="fas fa-check-circle ml-1"></i>
                الطلبات المحددة: <span id="selectedOrderCount" class="font-bold">0</span>
            </span>
            <button onclick="clearOrderSelection()" class="text-xs text-teal-700 hover:text-teal-900 font-semibold">
                <i class="fas fa-times-circle ml-1"></i>
                إلغاء التحديد
            </button>
        </div>

        <div id="ordersList" class="flex-1 overflow-y-auto border-2 border-gray-200 rounded-lg mb-4" style="max-height: 400px;">
            <div class="p-4 text-center text-gray-500"><i class="fas fa-spinner fa-spin text-2xl mb-2"></i><p>جاري التحميل...</p></div>
        </div>

        <div class="flex gap-3 justify-end pt-4 border-t-2">
            <button onclick="closeAddOrderModal()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-semibold">إلغاء</button>
            <button onclick="confirmSelection()" class="px-6 py-3 bg-teal-600 text-white rounded-lg hover:bg-teal-700 font-semibold">
                <i class="fas fa-check ml-2"></i>
                تأكيد الاختيار
            </button>
        </div>
    </div>
</div>

<script>
// Embed orders directly from PHP (no AJAX needed)
const ALL_ORDERS_DATA = <?php echo json_encode($all_orders, JSON_UNESCAPED_UNICODE); ?>;

let allOrders = [];
let selectedOrderRefs = new Set();  // composite refs: "customer:123" or "shop:45"
let selectedOrdersData = [];
let currentSourceFilter = 'all';

// --- Modal Functions ---
function openAddOrderModal() {
    document.getElementById('addOrderModal').classList.remove('hidden');
    allOrders = ALL_ORDERS_DATA;
    renderOrders(applyFilters());
}

function closeAddOrderModal() {
    document.getElementById('addOrderModal').classList.add('hidden');
}

function setSourceFilter(src) {
    currentSourceFilter = src;
    // Update chip styles
    document.querySelectorAll('.src-filter-btn').forEach(b => {
        b.classList.remove('bg-gray-800', 'text-white');
        b.classList.add('bg-gray-200', 'text-gray-700');
    });
    const active = document.getElementById('srcFilter' + src.charAt(0).toUpperCase() + src.slice(1));
    if (active) {
        active.classList.remove('bg-gray-200', 'text-gray-700');
        active.classList.add('bg-gray-800', 'text-white');
    }
    renderOrders(applyFilters());
}

function applyFilters() {
    const searchTerm = (document.getElementById('orderSearch').value || '').trim().toLowerCase();
    return allOrders.filter(o => {
        if (currentSourceFilter !== 'all' && o.source_type !== currentSourceFilter) return false;
        if (!searchTerm) return true;
        return (
            (o.order_number && o.order_number.toLowerCase().includes(searchTerm)) ||
            (o.customer_name && o.customer_name.toLowerCase().includes(searchTerm)) ||
            (o.final_amount && o.final_amount.toString().includes(searchTerm))
        );
    });
}

function renderOrders(orders) {
    const container = document.getElementById('ordersList');
    if (!orders || orders.length === 0) {
        container.innerHTML = `<div class="p-8 text-center text-gray-500"><i class="fas fa-search text-3xl mb-2 text-gray-300"></i><p>لا توجد نتائج</p></div>`;
        return;
    }

    container.innerHTML = orders.map(order => {
        const isShop   = order.source_type === 'shop';
        const badgeCls = isShop ? 'shop' : 'customer';
        const badgeTxt = isShop ? '<i class="fas fa-store"></i> متجر' : '<i class="fas fa-database"></i> نظام';
        const safeRef  = String(order.ref_id).replace(/"/g, '&quot;');
        const checked  = selectedOrderRefs.has(order.ref_id) ? 'checked' : '';
        const rowBg    = selectedOrderRefs.has(order.ref_id) ? 'bg-teal-50' : '';

        const createdDate = order.created_at ? new Date(order.created_at).toLocaleDateString('ar-SA') : '';

        return `
        <label class="flex items-center p-4 hover:bg-teal-50 cursor-pointer border-b border-gray-100 transition-colors ${rowBg}">
            <input type="checkbox" value="${safeRef}" class="order-checkbox w-5 h-5 text-teal-600 rounded focus:ring-teal-500 ml-3"
                   ${checked} onchange="toggleOrderSelection('${safeRef}')">
            <div class="flex-1">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="src-badge ${badgeCls}">${badgeTxt}</span>
                        <span class="font-bold text-blue-600 text-lg">${order.order_number}</span>
                        <span class="text-gray-600 font-medium">(${order.customer_name || 'عميل غير محدد'})</span>
                    </div>
                    <span class="font-bold text-teal-700">${parseFloat(order.final_amount || 0).toFixed(2)} ر.ي</span>
                </div>
                <div class="text-sm text-gray-500 mt-1"><i class="fas fa-calendar ml-1"></i> ${createdDate}</div>
            </div>
        </label>`;
    }).join('');
}

function toggleOrderSelection(refId) {
    if (selectedOrderRefs.has(refId)) {
        selectedOrderRefs.delete(refId);
    } else {
        selectedOrderRefs.add(refId);
    }
    updateOrderSelectedCount();
    renderOrders(applyFilters());
}

function updateOrderSelectedCount() {
    document.getElementById('selectedOrderCount').textContent = selectedOrderRefs.size;
}

function clearOrderSelection() {
    selectedOrderRefs.clear();
    updateOrderSelectedCount();
    renderOrders(applyFilters());
}

function filterOrders() {
    renderOrders(applyFilters());
}

// --- Form Integration ---
function confirmSelection() {
    const container = document.getElementById('selectedOrdersContainer');
    const noOrdersMsg = document.getElementById('noOrdersSelected');
    
    container.innerHTML = '';
    container.appendChild(noOrdersMsg);
    
    if (selectedOrderRefs.size === 0) {
        noOrdersMsg.style.display = 'block';
        closeAddOrderModal();
        return;
    }
    
    noOrdersMsg.style.display = 'none';
    
    selectedOrdersData = allOrders.filter(o => selectedOrderRefs.has(o.ref_id));
    
    selectedOrdersData.forEach(order => {
        const isShop   = order.source_type === 'shop';
        const badgeCls = isShop ? 'shop' : 'customer';
        const badgeTxt = isShop ? '<i class="fas fa-store"></i> متجر' : '<i class="fas fa-database"></i> نظام';
        const iconCls  = isShop ? 'fa-store text-amber-500' : 'fa-box text-blue-500';
        const safeRef  = String(order.ref_id).replace(/"/g, '&quot;');

        const div = document.createElement('div');
        div.className = 'flex items-center justify-between p-3 bg-gray-50 border border-gray-200 rounded-lg';
        div.innerHTML = `
            <div class="flex items-center gap-3">
                <div class="bg-white p-2 rounded border border-gray-200">
                    <i class="fas ${iconCls}"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="src-badge ${badgeCls}">${badgeTxt}</span>
                        <p class="font-bold text-gray-800">${order.order_number}</p>
                    </div>
                    <p class="text-xs text-gray-500">${order.customer_name || 'عميل غير محدد'} - ${parseFloat(order.final_amount || 0).toFixed(2)} ر.ي</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <input type="hidden" name="order_refs[]" value="${safeRef}">
                <button type="button" onclick="removeOrder('${safeRef}')" class="text-red-500 hover:text-red-700 p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        container.appendChild(div);
    });
    
    // Auto-fill customer info from the first selected order
    if (selectedOrdersData.length > 0) {
        const firstOrder = selectedOrdersData[0];
        document.getElementById('recipient_name').value  = firstOrder.customer_name || '';
        document.getElementById('recipient_phone').value = firstOrder.mobile_number || '';
        document.getElementById('delivery_address').value =
            (firstOrder.address || '') + (firstOrder.city_name ? '\n' + firstOrder.city_name : '');
    }
    
    closeAddOrderModal();
}

function removeOrder(refId) {
    selectedOrderRefs.delete(refId);
    confirmSelection();
}
</script>

<?php include '../../includes/footer.php'; ?>