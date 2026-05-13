<?php
/**
 * Sender Portal - Final Modern Shipment Details v3.0
 * - Modern, animated UI/UX
 * - Correctly handles shipments with multiple orders.
 * - ENHANCED by Gemini: Table view with live filtering.
 */

require_once '../config/database.php';

$token = $_GET['token'] ?? '';
$error = '';
$sender = null;
$shipments = [];
$stats = [
    'total' => 0,
    'preparing' => 0,
    'in_transit' => 0,
    'delivered' => 0,
];

// Helper array for status display
$status_map = [
    'preparing' => ['text' => 'قيد التجهيز', 'class' => 'warning'],
    'picked_up' => ['text' => 'تم الاستلام', 'class' => 'info'],
    'in_transit' => ['text' => 'في الطريق', 'class' => 'primary'],
    'out_for_delivery' => ['text' => 'خرج للتوصيل', 'class' => 'primary'],
    'delivered' => ['text' => 'تم التسليم', 'class' => 'success'],
    'returned' => ['text' => 'مرتجع', 'class' => 'danger'],
    'cancelled' => ['text' => 'ملغي', 'class' => 'secondary'],
];


if (empty($token)) {
    $error = 'رابط غير صالح. يرجى استخدام الرابط المرسل إليك.';
} else {
    // 1. Verify token and get sender
    $stmt = $db->prepare("SELECT * FROM senders WHERE portal_token = ?");
    $stmt->execute([$token]);
    $sender = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sender) {
        $error = 'رابط غير صالح أو منتهي الصلاحية.';
    } else {
        // 2. Handle Status Update POST Request
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
            $shipment_id = intval($_POST['shipment_id'] ?? 0);
            $new_status = $_POST['status'] ?? '';

            $verify_stmt = $db->prepare("SELECT id FROM shipments WHERE id = ? AND sender_id = ?");
            $verify_stmt->execute([$shipment_id, $sender['id']]);

            if ($verify_stmt->fetch()) {
                try {
                    $db->beginTransaction();
                    
                    $db->prepare("UPDATE shipments SET status = ?, updated_at = NOW() WHERE id = ?")
                       ->execute([$new_status, $shipment_id]);

                    $description = 'تم تحديث الحالة من قبل المرسل';
                    $db->prepare("INSERT INTO shipment_tracking (shipment_id, status, description, occurred_at) VALUES (?, ?, ?, NOW())")
                       ->execute([$shipment_id, $new_status, $description]);

                    $db->prepare("UPDATE customer_orders SET shipping_status = ? WHERE id IN (SELECT order_id FROM shipment_orders WHERE shipment_id = ?)")
                       ->execute([$new_status, $shipment_id]);
                       
                    $db->commit();
                    header("Location: ?token=$token&success=1");
                    exit;
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "فشل تحديث الحالة: " . $e->getMessage();
                }
            }
        }

        // 3. Fetch all shipments for this sender
        $stmt = $db->prepare("
            SELECT 
                s.id, s.shipment_number, s.status, s.created_at, s.tracking_number,
                GROUP_CONCAT(DISTINCT co.order_number SEPARATOR '<br>') as order_numbers
            FROM shipments s
            LEFT JOIN shipment_orders so ON s.id = so.shipment_id
            LEFT JOIN customer_orders co ON so.order_id = co.id
            WHERE s.sender_id = ?
            GROUP BY s.id
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$sender['id']]);
        $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Calculate stats
        $stats['total'] = count($shipments);
        foreach ($shipments as $shipment) {
            if ($shipment['status'] == 'preparing') $stats['preparing']++;
            if (in_array($shipment['status'], ['shipped', 'in_transit', 'out_for_delivery'])) $stats['in_transit']++;
            if ($shipment['status'] == 'delivered') $stats['delivered']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة المرسل - <?php echo htmlspecialchars($sender['name'] ?? 'تتبع الشحنات'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bs-primary: #0d6efd;
            --bs-secondary: #6c757d;
            --bs-success: #198754;
            --bs-info: #0dcaf0;
            --bs-warning: #ffc107;
            --bs-danger: #dc3545;
            --bs-light: #f8f9fa;
            --bs-dark: #212529;
            --bs-font-sans-serif: 'Tajawal', sans-serif;
            --bs-body-bg: #f8f9fa;
        }

        body {
            font-family: var(--bs-font-sans-serif);
            background-color: var(--bs-body-bg);
            animation: fadeIn 0.8s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .portal-container { max-width: 1200px; margin: 2rem auto; }
        .portal-header {
            background-color: white; border-radius: 1rem; padding: 2rem;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05); margin-bottom: 2rem;
            display: flex; justify-content: space-between; align-items: center;
        }
        .portal-header h1 { color: var(--bs-dark); font-weight: 700; }
        .portal-header .sender-info { color: var(--bs-secondary); }
        .portal-header img { max-height: 50px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card {
            background: white; padding: 1.5rem; border-radius: 1rem;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05); display: flex;
            align-items: center; gap: 1rem; border-left: 5px solid var(--border-color);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,0.08); }
        .stat-card .icon { font-size: 2rem; width: 50px; height: 50px; display: grid; place-items: center; border-radius: 50%; color: var(--border-color); background-color: var(--bg-color); }
        .stat-card .value { font-size: 2.25rem; font-weight: 700; line-height: 1; }
        .stat-card .label { color: var(--bs-secondary); }

        .shipments-container { background-color: white; border-radius: 1rem; padding: 2rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05); }
        .table-responsive { animation: slideUp 0.6s ease-out forwards; }
        .table thead th { font-weight: 700; }
        .table tbody td { vertical-align: middle; }

        .status-form select { min-width: 150px; }
        .btn-filter.active { background-color: var(--bs-primary); color: white; }

        .error-container { background: white; border-radius: 1rem; padding: 3rem; text-align: center; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05); }

        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .stat-card { animation: slideUp 0.6s ease-out forwards; opacity: 0; }
        <?php for ($i = 0; $i < 4; $i++): ?>
        .stat-card:nth-child(<?php echo $i+1; ?>) { animation-delay: <?php echo $i * 0.1; ?>s; }
        <?php endfor; ?>
    </style>
</head>
<body>
    <div class="portal-container">
        <?php if ($error): ?>
            <div class="error-container">
                <i class="fas fa-exclamation-triangle text-danger fa-4x mb-4"></i>
                <h2 class="mb-3">حدث خطأ</h2>
                <p class="text-muted fs-5"><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php else: ?>
            
            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success d-flex align-items-center" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <div>تم تحديث حالة الشحنة بنجاح!</div>
            </div>
            <?php endif; ?>
            
            <!-- Header -->
            <header class="portal-header">
                <div>
                    <h1><i class="fas fa-user-circle text-primary me-2"></i> بوابة المرسل</h1>
                    <p class="sender-info mb-0 fs-5">
                        أهلاً بك، <strong><?php echo htmlspecialchars($sender['name']); ?></strong>
                    </p>
                </div>
                <img src="https://taksoride.com/assets/logo.png" alt="Logo" onerror="this.style.display='none'">
            </header>

            <!-- Statistics -->
            <section class="stats-grid">
                <div class="stat-card" style="--border-color: var(--bs-primary); --bg-color: #e7f0fe;"><div class="icon"><i class="fas fa-boxes-stacked"></i></div><div><div class="value"><?php echo $stats['total']; ?></div><div class="label">إجمالي الشحنات</div></div></div>
                <div class="stat-card" style="--border-color: var(--bs-warning); --bg-color: #fff8e1;"><div class="icon"><i class="fas fa-hourglass-half"></i></div><div><div class="value"><?php echo $stats['preparing']; ?></div><div class="label">قيد التجهيز</div></div></div>
                <div class="stat-card" style="--border-color: var(--bs-info); --bg-color: #e0f8ff;"><div class="icon"><i class="fas fa-truck-fast"></i></div><div><div class="value"><?php echo $stats['in_transit']; ?></div><div class="label">في الطريق</div></div></div>
                <div class="stat-card" style="--border-color: var(--bs-success); --bg-color: #e2f5ea;"><div class="icon"><i class="fas fa-check-double"></i></div><div><div class="value"><?php echo $stats['delivered']; ?></div><div class="label">تم التسليم</div></div></div>
            </section>

            <!-- Shipments Table -->
            <main class="shipments-container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="mb-0 fw-bold"><i class="fas fa-list-ul me-2"></i>قائمة الشحنات</h3>
                    <!-- NEW: Filter Buttons -->
                    <div class="btn-group" role="group" id="shipment-filter">
                        <button type="button" class="btn btn-outline-primary btn-filter active" data-filter="all">عرض الكل</button>
                        <button type="button" class="btn btn-outline-primary btn-filter" data-filter="preparing">طلبات جديدة</button>
                        <button type="button" class="btn btn-outline-primary btn-filter" data-filter="delivered">الطلبات المكتملة</button>
                    </div>
                </div>
                
                <?php if (empty($shipments)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted">لم يتم العثور على أي شحنات.</h4>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الشحنة</th>
                                    <th>إجمالي الطلبات</th> <!-- New column for total orders -->
                                    <th>تاريخ الإنشاء</th>
                                    <th>الحالة</th>
                                    <th class="text-center">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody id="shipments-table-body">
                                <?php foreach ($shipments as $shipment): ?>
                                    <tr data-status="<?php echo htmlspecialchars($shipment['status']); ?>">
                                        <td>
                                            <i class="fas fa-barcode text-muted me-2"></i>
                                            <strong><?php echo htmlspecialchars($shipment['shipment_number']); ?></strong>
                                        </td>
                                        <td>
                                            <?php
                                                // Count the number of orders by counting '<br>' or if it's a single order
                                                if (!empty($shipment['order_numbers'])) {
                                                    $order_count = substr_count($shipment['order_numbers'], '<br>') + 1;
                                                    echo $order_count;
                                                } else {
                                                    echo '0'; // No orders associated
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo date('Y-m-d', strtotime($shipment['created_at'])); ?></td>
                                        <td>
                                            <form method="POST" class="status-form m-0">
                                                <input type="hidden" name="update_status" value="1">
                                                <input type="hidden" name="shipment_id" value="<?php echo $shipment['id']; ?>">
                                                <select name="status" class="form-select form-select-sm border-<?php echo $status_map[$shipment['status']]['class'] ?? 'secondary'; ?>" onchange="if(confirm('هل أنت متأكد من تغيير حالة الشحنة إلى \'' + this.options[this.selectedIndex].text + '\'؟')) this.form.submit()">
                                                    <?php foreach ($status_map as $key => $value): ?>
                                                        <option value="<?php echo $key; ?>" <?php echo $shipment['status'] == $key ? 'selected' : ''; ?>>
                                                            <?php echo $value['text']; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </td>
                                        <td class="text-center">
                                            <a href="shipment_details.php?token=<?php echo htmlspecialchars($token); ?>&id=<?php echo $shipment['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-search-plus"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </main>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- NEW: JavaScript for Filtering -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const filterButtons = document.querySelectorAll('#shipment-filter .btn-filter');
            const tableRows = document.querySelectorAll('#shipments-table-body tr');

            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // Manage active class for buttons
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');

                    const filter = this.getAttribute('data-filter');

                    // Loop through table rows to show/hide
                    tableRows.forEach(row => {
                        if (filter === 'all') {
                            row.style.display = ''; // Show row
                        } else {
                            if (row.getAttribute('data-status') === filter) {
                                row.style.display = ''; // Show row
                            } else {
                                row.style.display = 'none'; // Hide row
                            }
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>