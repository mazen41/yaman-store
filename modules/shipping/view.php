<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}
require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// --- Global Helper Data ---
// Status translation map
$status_map = [
    'preparing' => 'قيد التجهيز',
    'picked_up' => 'تم الاستلام',
    'shipped' => 'تم الشحن',
    'in_transit' => 'في الطريق',
    'out_for_delivery' => 'خرج للتوصيل',
    'delivered' => 'تم التسليم',
    'cancelled' => 'ملغي',
    'returned' => 'مرتجع',
];

// Permission check for edit button
$canEdit = hasPermission($_SESSION['user_id'], 'shipping', 'edit');

$page_title = 'تفاصيل الشحنة';
$shipment_id = intval($_GET['id'] ?? 0);
$error_message = '';

if ($shipment_id <= 0) {
    header('Location: index.php');
    exit();
}

// ===================================================================
// START: EDITED DATA FETCHING LOGIC
// ===================================================================

// 1. Fetch main shipment details with sender info
$stmt = $db->prepare("
    SELECT s.*, sd.name as sender_name, sd.phone as sender_phone
    FROM shipments s
    LEFT JOIN senders sd ON s.sender_id = sd.id
    WHERE s.id = ?
");
$stmt->execute([$shipment_id]);
$shipment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shipment) {
    $_SESSION['error_message'] = 'الشحنة المطلوبة غير موجودة.';
    header('Location: index.php');
    exit();
}

// 2. Fetch ALL associated orders for this shipment
$orders_stmt = $db->prepare("
    SELECT 
        co.id,
        co.order_number, 
        co.final_amount, 
        c.name as customer_name
    FROM shipment_orders so
    JOIN customer_orders co ON so.order_id = co.id
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE so.shipment_id = ?
    ORDER BY co.created_at DESC
");
$orders_stmt->execute([$shipment_id]);
$orders_in_shipment = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);


// 3. Fetch tracking history
$tracking_stmt = $db->prepare("SELECT * FROM shipment_tracking WHERE shipment_id = ? ORDER BY occurred_at DESC");
$tracking_stmt->execute([$shipment_id]);
$tracking_history = $tracking_stmt->fetchAll(PDO::FETCH_ASSOC);

// ===================================================================
// END: EDITED DATA FETCHING LOGIC
// ===================================================================


include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                    <i class="fas fa-shipping-fast text-blue-600"></i>
                    <span>تفاصيل الشحنة</span>
                    <span class="text-blue-600 font-mono">#<?php echo htmlspecialchars($shipment['shipment_number']); ?></span>
                </h1>
                <p class="text-sm text-gray-500 mt-1">تاريخ الإنشاء: <?php echo date('Y-m-d H:i', strtotime($shipment['created_at'])); ?></p>
            </div>
            <div class="flex items-center gap-2">
                <?php if ($canEdit): ?>
                <a href="edit.php?id=<?php echo $shipment_id; ?>" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-all shadow-sm flex items-center gap-2">
                    <i class="fas fa-edit"></i> تعديل
                </a>
                <?php endif; ?>
                <a href="print.php?id=<?php echo $shipment_id; ?>" target="_blank" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-all shadow-sm flex items-center gap-2">
                    <i class="fas fa-print"></i> طباعة
                </a>
                <a href="index.php" class="bg-white text-gray-700 px-4 py-2 rounded-lg border hover:bg-gray-50 transition-all flex items-center gap-2">
                    <i class="fas fa-arrow-right"></i> عودة
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-8">
                
                <!-- Shipment Info Card -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4 border-b pb-3 flex items-center gap-2"><i class="fas fa-info-circle text-blue-500"></i> معلومات الشحنة</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                        <div class="info-item">
                            <label>الحالة</label>
                            <span class="font-bold text-lg text-blue-700"><?php echo $status_map[$shipment['status']] ?? htmlspecialchars($shipment['status']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>رقم التتبع</label>
                            <span class="font-mono"><?php echo htmlspecialchars($shipment['tracking_number'] ?: '-'); ?></span>
                        </div>
                        <div class="info-item">
                            <label>تكلفة الشحن</label>
                            <span class="font-bold text-lg text-green-600"><?php echo number_format($shipment['shipping_cost'], 0); ?> ريال</span>
                        </div>
                        <div class="info-item">
                            <label>تاريخ التوصيل المتوقع</label>
                            <span><?php echo $shipment['estimated_delivery'] ? date('Y-m-d', strtotime($shipment['estimated_delivery'])) : '-'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Orders in Shipment Card -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4 border-b pb-3 flex items-center gap-2"><i class="fas fa-box-open text-purple-500"></i> الطلبات في هذه الشحنة (<?php echo count($orders_in_shipment); ?>)</h3>
                     <div class="divide-y divide-gray-100">
                        <?php foreach($orders_in_shipment as $order): ?>
                            <div class="py-3 grid grid-cols-3 gap-4 items-center">
                                <div>
                                    <span class="text-sm text-gray-500">رقم الطلب</span>
                                    <a href="../orders/view.php?id=<?php echo $order['id']; ?>" class="font-bold text-blue-600 hover:underline">
                                        <?php echo htmlspecialchars($order['order_number']); ?>
                                    </a>
                                </div>
                                <div>
                                     <span class="text-sm text-gray-500">العميل</span>
                                     <p class="font-semibold"><?php echo htmlspecialchars($order['customer_name']); ?></p>
                                </div>
                                <div>
                                    <span class="text-sm text-gray-500">المبلغ</span>
                                    <p class="font-bold text-green-600"><?php echo number_format($order['final_amount'], 0); ?> ريال</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Tracking History Card -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4 border-b pb-3 flex items-center gap-2"><i class="fas fa-route text-teal-500"></i> سجل التتبع</h3>
                    <div class="relative pl-4">
                        <?php if (empty($tracking_history)): ?>
                            <p class="text-gray-500 text-center py-4">لا يوجد سجل تتبع لهذه الشحنة بعد</p>
                        <?php else: ?>
                            <div class="border-r-2 border-gray-200 absolute h-full top-0 right-2"></div>
                            <ul class="space-y-6">
                                <?php foreach ($tracking_history as $track): ?>
                                <li class="relative">
                                    <div class="absolute -right-[13px] w-6 h-6 rounded-full bg-blue-500 border-4 border-white"></div>
                                    <div class="ml-8">
                                        <h4 class="font-bold text-gray-800"><?php echo $status_map[$track['status']] ?? htmlspecialchars($track['status']); ?></h4>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($track['description']); ?></p>
                                        <span class="text-xs text-gray-400 mt-1 block"><i class="fas fa-clock"></i> <?php echo date('Y-m-d, h:i A', strtotime($track['occurred_at'])); ?></span>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Sidebar -->
            <div class="space-y-8">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4 border-b pb-3 flex items-center gap-2"><i class="fas fa-user-check text-green-500"></i> المستلم</h3>
                    <div class="space-y-3">
                        <div class="info-item">
                            <label>الاسم</label>
                            <span class="font-bold"><?php echo htmlspecialchars($shipment['recipient_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>رقم الهاتف</label>
                            <span class="font-mono text-left" dir="ltr"><?php echo htmlspecialchars($shipment['recipient_phone']); ?></span>
                        </div>
                        <div class="info-item col-span-2">
                            <label>العنوان</label>
                            <p class="text-gray-800 bg-gray-50 p-3 rounded-lg border text-sm"><?php echo nl2br(htmlspecialchars($shipment['delivery_address'])); ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4 border-b pb-3 flex items-center gap-2"><i class="fas fa-user-tie text-gray-500"></i> المرسل</h3>
                    <div class="space-y-3">
                         <div class="info-item">
                            <label>الاسم</label>
                            <span><?php echo htmlspecialchars($shipment['sender_name'] ?? 'غير محدد'); ?></span>
                        </div>
                        <?php if (!empty($shipment['sender_phone'])): ?>
                        <div class="info-item">
                            <label>الهاتف</label>
                            <span class="font-mono text-left" dir="ltr"><?php echo htmlspecialchars($shipment['sender_phone']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .info-item {
        display: flex;
        flex-direction: column;
    }
    .info-item label {
        font-size: 0.875rem; /* 14px */
        color: #6b7280; /* text-gray-500 */
        margin-bottom: 0.25rem;
    }
    .info-item span, .info-item p {
        font-size: 1rem; /* 16px */
        color: #1f2937; /* text-gray-800 */
        font-weight: 600; /* semibold */
    }
</style>

<?php include '../../includes/footer.php'; ?>