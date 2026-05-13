<?php
/**
 * Sender Portal - Final Modern Shipment Details v4.0 (Table Redesign)
 * - Modern white/blue theme with a single table layout for all orders.
 * - Shows remaining amount per order & total item quantity in table columns.
 * - Displays MANAGER NOTES and CUSTOMER NOTES in an expandable row under each order.
 * - FIXED: Deprecated htmlspecialchars() error by providing default values for potentially null variables.
 * - ADDED: Ability to update the status of each individual order within the shipment.
 * - EDITED: Now displays customer_notes and alternative_number from the customers table.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../config/database.php';
// No need to include the API script here, it's a separate endpoint.

// --- Global Helper Data ---
// Shipment statuses (for display only now)
$status_map = [
    'preparing' => ['text' => 'قيد التجهيز', 'icon' => 'fa-box', 'color' => 'secondary'],
    'picked_up' => ['text' => 'تم الاستلام', 'icon' => 'fa-people-carry-box', 'color' => 'info'],
    'shipped' => ['text' => 'تم الشحن', 'icon' => 'fa-shipping-fast', 'color' => 'primary'],
    'in_transit' => ['text' => 'في الطريق', 'icon' => 'fa-truck', 'color' => 'warning text-dark'],
    'out_for_delivery' => ['text' => 'خرج للتوصيل', 'icon' => 'fa-truck-loading', 'color' => 'info'],
    'delivered' => ['text' => 'تم التسليم', 'icon' => 'fa-check-circle', 'color' => 'success'],
    'cancelled' => ['text' => 'ملغي', 'icon' => 'fa-times-circle', 'color' => 'danger'],
    'returned' => ['text' => 'مرتجع', 'icon' => 'fa-undo', 'color' => 'dark'],
];

// --- Data Fetching Logic ---
$token = $_GET['token'] ?? '';
$shipment_id = intval($_GET['id'] ?? 0);
$error = '';
$sender = null;
$shipment = null;
$orders = [];
$tracking_history = [];
$all_order_statuses = []; // This will store statuses for the dropdown

if (empty($token)) {
    $error = 'رابط غير صالح';
} else {
    // 1. Verify token and get sender details
    $stmt = $db->prepare("SELECT * FROM senders WHERE portal_token = ?");
    $stmt->execute([$token]);
    $sender = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sender) {
        $error = 'رابط غير صالح';
    } else {
        // 2. Fetch the main shipment details
        $stmt = $db->prepare("SELECT * FROM shipments WHERE id = ? AND sender_id = ?");
        $stmt->execute([$shipment_id, $sender['id']]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shipment) {
            $error = 'الشحنة غير موجودة أو لا تملك صلاحية الوصول إليها.';
        } else {
            // 3. NEW: Fetch all available CUSTOMER ORDER statuses for the dropdowns
            try {
                $all_order_statuses = $db->query("SELECT status_key, status_name_ar FROM customer_order_statuses ORDER BY is_default DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Failed to fetch customer order statuses: " . $e->getMessage());
                $error = "فشل تحميل حالات الطلبات. يرجى التحقق من سجل الأخطاء.";
                $all_order_statuses = [];
            }

            // 4. Fetch ALL orders with PAID AMOUNT, TOTAL QUANTITY, and MANAGER NOTES
            $order_stmt = $db->prepare("
                SELECT
                    o.id,
                    o.order_number,
                    o.final_amount,
                    o.paid_amount,
                    o.status as order_status,
                    o.manager_notes as order_notes,
                    c.name as customer_name,
                    c.mobile_number as customer_phone,
                    c.alternative_number as customer_alternative_phone,
                    c.address as customer_address,
                    c.city_name as customer_city,
                    c.location_url as customer_location_url,
                    c.customer_notes as customer_notes,
                    (SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id) as total_quantity
                FROM shipment_orders so
                JOIN customer_orders o ON so.order_id = o.id
                JOIN customers c ON o.customer_id = c.id
                WHERE so.shipment_id = ?
                GROUP BY o.id
                ORDER BY o.created_at DESC
            ");
            $order_stmt->execute([$shipment_id]);
            $orders = $order_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Get tracking history
            $tracking_stmt = $db->prepare(
                "SELECT * FROM shipment_tracking WHERE shipment_id = ? ORDER BY occurred_at DESC"
            );
            $tracking_stmt->execute([$shipment_id]);
            $tracking_history = $tracking_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Helper function for status badges
function getStatusBadge($status) {
    global $status_map;
    $s = $status_map[$status] ?? ['text' => ucfirst($status), 'icon' => 'fa-question-circle', 'color' => 'light'];
    return "<span class=\"badge fs-6 bg-{$s['color']}\"><i class=\"fas {$s['icon']} me-1\"></i> {$s['text']}</span>";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل الشحنة - <?php echo htmlspecialchars($shipment['shipment_number'] ?? 'غير متوفر'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Tajawal', sans-serif; }
        .main-container { max-width: 1200px; margin: 2rem auto; } /* Increased width for table */
        .card { border-radius: 0.75rem; box-shadow: 0 0.25rem 1rem rgba(0,0,0,0.05); border: none; margin-bottom: 1.5rem; }
        .card-header { border-radius: 0.75rem 0.75rem 0 0 !important; background-color: #fff; border-bottom: 1px solid #dee2e6; padding: 1.25rem; }
        .card-title { font-weight: 700; color: #343a40; }
        .shipment-number { color: #0d6efd; font-weight: 700; font-size: 1.25rem; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; }
        .info-item { display: flex; align-items: flex-start; gap: 0.75rem; }
        .info-item .icon { font-size: 1.2rem; color: #6c757d; margin-top: 5px; }
        .info-item .text strong { display: block; color: #495057; margin-bottom: 0.25rem; }
        .info-item .text span { color: #212529; word-break: break-word; }
        .back-btn { text-decoration: none; color: #6c757d; font-weight: 500; transition: color 0.2s; }
        .back-btn:hover { color: #0d6efd; }
        .form-select { cursor: pointer; }
        .update-spinner, .update-success { font-size: 0.9em; display: none; }

        /* --- NEW TABLE STYLES --- */
        .table th {
            font-weight: 700;
            color: #343a40;
            background-color: #f8f9fa;
        }
        .table td {
            vertical-align: middle;
            font-size: 0.95rem;
        }
        .table .status-dropdown {
            min-width: 170px;
        }
        .notes-row > td {
            background-color: #f8f9fa;
            border-top: 1px solid #dee2e6;
        }
        .notes-container {
            padding: 1rem 1.5rem;
        }
        .notes-section {
            background-color: #f1f3f5;
            padding: 0.75rem;
            border-radius: 0.5rem;
            max-width: 700px; /* Limit width for readability */
        }
    </style>
</head>
<body>
    <div class="container main-container py-4">

        <div class="mb-4">
            <a href="index.php?token=<?php echo htmlspecialchars($token ?? ''); ?>" class="back-btn">
                <i class="fas fa-arrow-right me-2"></i> العودة إلى قائمة الشحنات
            </a>
        </div>
        
        <div id="alert-placeholder"></div>

        <?php if ($error): ?>
            <div class="card text-center"><div class="card-body p-5"><i class="fas fa-exclamation-triangle text-danger fa-3x mb-3"></i><h3 class="card-title"><?php echo $error; ?></h3></div></div>
        <?php else: ?>
            <!-- Main Shipment Details -->
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <h4 class="card-title mb-1">تفاصيل الشحنة</h4>
                        <span class="shipment-number"><?php echo htmlspecialchars($shipment['shipment_number'] ?? ''); ?></span>
                    </div>
                    <div class="mt-2 mt-md-0 text-md-end">
                        <small class="text-muted d-block mb-1">حالة الشحنة</small>
                        <?php echo getStatusBadge($shipment['status'] ?? 'unknown'); ?>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="info-grid">
                        <div class="info-item"><i class="fas fa-calendar-alt icon"></i><div class="text"><strong>تاريخ الإنشاء</strong> <span><?php echo date('Y-m-d H:i', strtotime($shipment['created_at'] ?? time())); ?></span></div></div>
                        <div class="info-item"><i class="fas fa-truck icon"></i><div class="text"><strong>رقم التتبع</strong> <span><?php echo htmlspecialchars($shipment['tracking_number'] ?? 'لم يحدد'); ?></span></div></div>
                        <div class="info-item"><i class="fas fa-money-bill-wave icon"></i><div class="text"><strong>تكلفة الشحن</strong> <span><?php echo number_format($shipment['shipping_cost'] ?? 0, 2); ?> ر.ي</span></div></div>
                    </div>
                </div>
            </div>

            <!-- ############### START: NEW TABLE DESIGN FOR ORDERS ############### -->
            <div class="mb-4 mt-5">
                <h5 class="card-title"><i class="fas fa-cubes me-2 text-primary"></i>الطلبات في هذه الشحنة (<?php echo count($orders); ?>)</h5>
            </div>
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>رقم الطلب</th>
                                    <th>العميل</th>
                                    <th>العنوان</th>
                                    <th class="text-center">الكمية</th>
                                    <th>المبلغ المتبقي</th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5">لا توجد طلبات في هذه الشحنة.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php $counter = 1; ?>
                                    <?php foreach($orders as $order): ?>
                                        <?php
                                            $remaining_amount = ($order['final_amount'] ?? 0) - ($order['paid_amount'] ?? 0);
                                            $remaining_class = ($remaining_amount > 0.01) ? 'text-danger' : 'text-success';
                                        ?>
                                        <!-- Main Order Data Row -->
                                        <tr>
                                            <td class="ps-4"><?php echo $counter++; ?></td>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($order['order_number'] ?? ''); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($order['customer_name'] ?? ''); ?></div>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars($order['customer_phone'] ?? ''); ?></small>
                                                <?php if (!empty($order['customer_alternative_phone'])): ?>
                                                    <small class="text-muted d-block">بديل: <?php echo htmlspecialchars($order['customer_alternative_phone']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars(($order['customer_address'] ?? '') . ', ' . ($order['customer_city'] ?? '')); ?>
                                                <?php if (!empty($order['customer_location_url'])): ?>
                                                    <a href="<?php echo htmlspecialchars($order['customer_location_url']); ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2 ms-2">
                                                        <i class="fas fa-map-marked-alt"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold fs-5"><?php echo htmlspecialchars($order['total_quantity'] ?? 0); ?></span>
                                            </td>
                                            <td>
                                                <span class="fw-bold fs-5 <?php echo $remaining_class; ?>"><?php echo number_format($remaining_amount, 2); ?> ر.ي</span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <select class="form-select form-select-sm status-dropdown" data-order-id="<?php echo $order['id']; ?>" data-original-status="<?php echo htmlspecialchars($order['order_status'] ?? ''); ?>">
                                                        <?php foreach ($all_order_statuses as $status): ?>
                                                            <option value="<?php echo htmlspecialchars($status['status_key'] ?? ''); ?>" <?php echo (($order['order_status'] ?? '') == ($status['status_key'] ?? '')) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($status['status_name_ar'] ?? ''); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <span class="update-spinner"><i class="fas fa-spinner fa-spin text-primary"></i></span>
                                                    <span class="update-success"><i class="fas fa-check-circle text-success"></i></span>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- Conditional Notes Row -->
                                        <?php if (!empty($order['order_notes']) || !empty($order['customer_notes'])): ?>
                                            <tr class="notes-row">
                                                <td colspan="7">
                                                    <div class="notes-container d-flex flex-column gap-2">
                                                        <?php if (!empty($order['order_notes'])): ?>
                                                            <div class="notes-section">
                                                                <strong class="d-block mb-2"><i class="fas fa-user-shield text-info me-2"></i>ملاحظات المدير</strong>
                                                                <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($order['order_notes'])); ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (!empty($order['customer_notes'])): ?>
                                                            <div class="notes-section">
                                                                <strong class="d-block mb-2"><i class="fas fa-sticky-note text-warning me-2"></i>ملاحظات العميل</strong>
                                                                <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($order['customer_notes'])); ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!-- ############### END: NEW TABLE DESIGN FOR ORDERS ############### -->

            <!-- Tracking History (Unchanged) -->
            <div class="card mt-5">
                <div class="card-header"><h5 class="card-title mb-0"><i class="fas fa-route me-2"></i> سجل تتبع الشحنة</h5></div>
                <div class="card-body p-4">
                    ... [Tracking history PHP code remains unchanged] ...
                </div>
            </div>
            
            <?php if (!empty($shipment['notes'])): ?>
            <div class="card"><div class="card-header bg-light"><h5 class="card-title mb-0"><i class="fas fa-sticky-note me-2"></i> ملاحظات الشحنة</h5></div><div class="card-body"><p class="mb-0"><?php echo nl2br(htmlspecialchars($shipment['notes'])); ?></p></div></div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- JAVASCRIPT FOR ORDER STATUS UPDATES (Unchanged and will work with the new design) -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const token = '<?php echo htmlspecialchars($token ?? ''); ?>';
        
        document.querySelectorAll('.status-dropdown').forEach(dropdown => {
            dropdown.addEventListener('change', async function() {
                const orderId = this.dataset.orderId;
                const newStatus = this.value;
                const originalStatus = this.dataset.originalStatus;
                
                const spinner = this.nextElementSibling;
                const successIcon = spinner.nextElementSibling;

                if (newStatus === originalStatus) return;
                
                if (!confirm('هل أنت متأكد من تغيير حالة هذا الطلب؟')) {
                    this.value = originalStatus;
                    return;
                }

                spinner.style.display = 'inline-block';
                successIcon.style.display = 'none';
                this.disabled = true;

                try {
                    const response = await fetch('api/update_portal_order_status.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            order_id: orderId,
                            new_status: newStatus,
                            token: token
                        })
                    });
                    
                    const result = await response.json();

                    if (result.success) {
                        this.dataset.originalStatus = newStatus;
                        successIcon.style.display = 'inline-block';
                        setTimeout(() => { successIcon.style.display = 'none'; }, 2500);
                    } else {
                        alert('فشل تحديث الحالة: ' + (result.message || 'خطأ غير معروف.'));
                        this.value = originalStatus;
                    }

                } catch (error) {
                    console.error('Update Error:', error);
                    alert('حدث خطأ في الشبكة. يرجى المحاولة مرة أخرى.');
                    this.value = originalStatus;
                } finally {
                    spinner.style.display = 'none';
                    this.disabled = false;
                }
            });
        });
    });
    </script>
</body>
</html>