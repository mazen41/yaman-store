<?php
session_start();

// --- 1. SECURITY & CONFIGURATION ---
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$user_id = $_SESSION['user_id'];
$page_title = 'تتبع التوصيل - أرقام الشحنات';

$feedback_message = '';
$feedback_type = '';

// --- 2. HANDLE POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'add_tracking') {
        $basket_id = intval($_POST['basket_id'] ?? 0);
        $tracking_number = trim($_POST['tracking_number'] ?? '');
        $carrier = trim($_POST['carrier'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($basket_id && $tracking_number) {
            try {
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM basket_tracking WHERE basket_id = ? AND tracking_number = ?");
                $check_stmt->execute([$basket_id, $tracking_number]);

                if ($check_stmt->fetchColumn() > 0) {
                    $feedback_message = 'رقم الشحنة موجود مسبقاً لهذه السلة.';
                    $feedback_type = 'error';
                } else {
                    $stmt = $db->prepare("INSERT INTO basket_tracking (basket_id, tracking_number, carrier, notes, created_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$basket_id, $tracking_number, $carrier, $notes, $user_id]);
                    $_SESSION['feedback_message'] = 'تم إضافة رقم الشحنة بنجاح.';
                    $_SESSION['feedback_type'] = 'success';
                    header('Location: tracking.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
                    exit();
                }
            } catch (PDOException $e) {
                $feedback_message = 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage();
                $feedback_type = 'error';
            }
        } else {
            $feedback_message = 'يرجى تحديد السلة وإدخال رقم الشحنة.';
            $feedback_type = 'error';
        }
    }

    if ($action == 'ajax_update_status') {
        header('Content-Type: application/json');
        $tracking_id = intval($_POST['tracking_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $valid_statuses = ['pending', 'in_transit', 'delivered', 'returned'];
        if ($tracking_id && in_array($status, $valid_statuses)) {
            try {
                $stmt = $db->prepare("UPDATE basket_tracking SET status = ? WHERE id = ?");
                $stmt->execute([$status, $tracking_id]);
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false]);
            }
        }
        exit();
    }

    if ($action == 'ajax_delete_tracking') {
        header('Content-Type: application/json');
        $tracking_id = intval($_POST['tracking_id'] ?? 0);
        if ($tracking_id) {
            try {
                $stmt = $db->prepare("DELETE FROM basket_tracking WHERE id = ?");
                $stmt->execute([$tracking_id]);
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false]);
            }
        }
        exit();
    }
}

if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    $feedback_type = $_SESSION['feedback_type'] ?? 'success';
    unset($_SESSION['feedback_message'], $_SESSION['feedback_type']);
}

// --- 3. FETCH DATA ---
try {
    // Fetch baskets for the "Add Tracking" form/modal (only active ones)
    $baskets_for_form = $db->query("SELECT pb.id, pb.basket_code, pb.basket_name, pb.final_amount, pb.created_at FROM purchase_baskets pb WHERE pb.status NOT IN ('delivered', 'returned') ORDER BY pb.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch ALL baskets for the new filter dropdown
    $all_baskets_for_filter = $db->query("SELECT id, basket_code, basket_name FROM purchase_baskets ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Get all filter values from the URL
    $search_term = trim($_GET['search'] ?? '');
    $status_filter = trim($_GET['status_filter'] ?? '');
    $basket_filter = intval($_GET['basket_filter'] ?? 0); 

    $params = [];
    $where_clauses = [];

    $tracking_query = "SELECT bt.*, pb.basket_code, pb.basket_name, u.username as created_by_name FROM basket_tracking bt INNER JOIN purchase_baskets pb ON bt.basket_id = pb.id LEFT JOIN users u ON bt.created_by = u.id";

    if (!empty($search_term)) {
        $where_clauses[] = "(bt.tracking_number LIKE :search OR pb.basket_code LIKE :search)";
        $params[':search'] = '%' . $search_term . '%';
    }
    if (!empty($status_filter)) {
        $where_clauses[] = "bt.status = :status";
        $params[':status'] = $status_filter;
    }
    if ($basket_filter > 0) {
        $where_clauses[] = "bt.basket_id = :basket_id";
        $params[':basket_id'] = $basket_filter;
    }

    if (!empty($where_clauses)) $tracking_query .= " WHERE " . implode(' AND ', $where_clauses);
    $tracking_query .= " ORDER BY bt.created_at DESC";

    $stmt = $db->prepare($tracking_query);
    $stmt->execute($params);
    $tracking_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats query remains the same
    $stats = $db->query("SELECT (SELECT COUNT(DISTINCT basket_id) FROM basket_tracking) as baskets_with_tracking, (SELECT COUNT(*) FROM basket_tracking) as total_tracking_numbers, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count, SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit_count, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count, SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned_count FROM basket_tracking")->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

include '../../includes/header.php';
?>

<style>
    :root {
        --primary-color: #3b82f6;
        --primary-hover: #2563eb;
        --success-color: #10b981;
        --warning-color: #f59e0b;
        --danger-color: #ef4444;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-500: #6b7280;
        --gray-700: #374151;
        --bg-color: #f9fafb;
        --card-bg: #ffffff;
        --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        --border-radius: 12px;
    }
    .tracking-container {
        width: 100%;
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        gap: 25px;
    }
    .page-main-header {
        font-size: 24px;
        font-weight: 700;
        color: var(--gray-700);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .card {
        background: var(--card-bg);
        border-radius: var(--border-radius);
        box-shadow: var(--card-shadow);
        padding: 20px;
        border: 1px solid var(--gray-200);
    }
    .card-header {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--gray-700);
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
    }
    .stat-card {
        background: var(--card-bg);
        padding: 15px;
        border-radius: var(--border-radius);
        text-align: center;
        border: 1px solid var(--gray-200);
    }
    .stat-value { font-size: 24px; font-weight: bold; }
    .stat-label { color: var(--gray-500); font-size: 13px; }
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        align-items: flex-end;
    }
    .form-group { width: 100%; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 14px; }
    .form-control {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--gray-200);
        border-radius: 8px;
        box-sizing: border-box;
    }
    .filter-form-label {
        display: block;
        margin-bottom: 4px;
        font-size: 13px;
        font-weight: 500;
        color: var(--gray-700);
    }
    .btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
        white-space: nowrap;
    }
    .btn-primary { background: var(--primary-color); color: white; }
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        background: white;
        border-radius: 8px;
    }
    .tracking-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 900px;
    }
    .tracking-table th, .tracking-table td {
        padding: 12px;
        text-align: right;
        border-bottom: 1px solid var(--gray-200);
    }
    .status-select {
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        border: 1px solid transparent;
    }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-in_transit { background: #dbeafe; color: #1e40af; }
    .status-delivered { background: #d1fae5; color: #065f46; }
    .status-returned { background: #fee2e2; color: #991b1b; }

    /* ===== NEW MODAL STYLES ===== */
    .modal {
        display: none; /* Changed from 'hidden' class to direct style */
        position: fixed;
        inset: 0;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 50;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .modal-content {
        background-color: white;
        border-radius: 16px; /* 2xl */
        padding: 32px; /* p-8 */
        max-width: 896px; /* max-w-3xl */
        width: 100%;
        margin: 16px; /* mx-4 */
        max-height: 85vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px; /* mb-6 */
    }
    .modal-header h3 {
        font-size: 24px; /* text-2xl */
        font-weight: bold;
        color: #1f2937; /* text-gray-900 */
    }
    .modal-header button {
        color: #9ca3af; /* text-gray-400 */
        background: none;
        border: none;
        cursor: pointer;
    }
     .modal-header button:hover {
        color: #4b5563; /* text-gray-600 */
    }
    .modal-body {
        flex: 1;
        overflow-y: auto;
        border: 2px solid #e5e7eb; /* border-2 border-gray-200 */
        border-radius: 8px; /* rounded-lg */
        margin-bottom: 16px; /* mb-4 */
    }
    .modal-footer {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        padding-top: 16px; /* pt-4 */
        border-top: 2px solid #e5e7eb; /* border-t-2 */
    }
    #selected_basket_display { cursor: pointer; background-color: #f9fafb; }


    @media (max-width: 768px) {
        .tracking-container { padding: 10px; }
        .form-grid, .filter-form { grid-template-columns: 1fr; }
        .page-main-header { font-size: 20px; }
        .card { padding: 15px; }
        .btn { width: 100%; justify-content: center; }
    }
</style>

<div class="tracking-container">
    <header>
        <h1 class="page-main-header"><i class="fas fa-shipping-fast"></i> <?php echo $page_title; ?></h1>
    </header>

    <?php if ($feedback_message): ?>
        <div class="card" style="background: <?php echo $feedback_type == 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $feedback_type == 'success' ? '#065f46' : '#991b1b'; ?>;">
            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($feedback_message); ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-value" style="color:var(--warning-color)"><?php echo $stats['pending_count'] ?? 0; ?></div><div class="stat-label">قيد الانتظار</div></div>
        <div class="stat-card"><div class="stat-value" style="color:var(--primary-color)"><?php echo $stats['in_transit_count'] ?? 0; ?></div><div class="stat-label">في الطريق</div></div>
        <div class="stat-card"><div class="stat-value" style="color:var(--success-color)"><?php echo $stats['delivered_count'] ?? 0; ?></div><div class="stat-label">تم التوصيل</div></div>
        <div class="stat-card"><div class="stat-value" style="color:var(--danger-color)"><?php echo $stats['returned_count'] ?? 0; ?></div><div class="stat-label">مرتجع</div></div>
    </div>

    <!-- ===== EDITED: ADD TRACKING FORM ===== -->
    <div class="card">
        <h2 class="card-header"><i class="fas fa-plus-circle"></i> إضافة شحنة</h2>
        <form id="addTrackingForm" method="POST" action="tracking.php<?php echo ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''); ?>">
            <input type="hidden" name="action" value="add_tracking">
            <div class="form-grid">
                <div class="form-group">
                    <label for="selected_basket_display">السلة *</label>
                    <!-- Hidden input to store the actual basket ID -->
                    <input type="hidden" name="basket_id" id="selected_basket_id">
                    <!-- Visible input that shows the selected basket and opens the modal -->
                    <input type="text" id="selected_basket_display" class="form-control" placeholder="انقر لاختيار سلة..." readonly onclick="openSelectBasketModal()">
                </div>
                <div class="form-group">
                    <label>رقم الشحنة *</label>
                    <input type="text" name="tracking_number" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>شركة الشحن</label>
                    <input type="text" name="carrier" class="form-control">
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="width:100%"><i class="fas fa-plus"></i> إضافة</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2 class="card-header"><i class="fas fa-list"></i> سجلات الشحنات</h2>
        
        <form method="GET" class="form-grid" style="margin-bottom: 20px;">
            <div class="form-group">
                <label class="filter-form-label">بحث عام</label>
                <input type="text" name="search" class="form-control" placeholder="رقم الشحنة أو كود السلة..." value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            
            <div class="form-group">
                <label class="filter-form-label">بحث عن سلة للفلترة</label>
                <input type="text" id="basket_filter_search" class="form-control" placeholder="ابحث بالكود أو الاسم...">
            </div>
            <div class="form-group">
                <label class="filter-form-label">فلترة حسب السلة</label>
                <select name="basket_filter" id="basket_filter_select" class="form-control">
                    <option value="">كل السلات</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="filter-form-label">الحالة</label>
                <select name="status_filter" class="form-control">
                    <option value="">كل الحالات</option>
                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                    <option value="in_transit" <?php echo $status_filter == 'in_transit' ? 'selected' : ''; ?>>في الطريق</option>
                    <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>تم التوصيل</option>
                    <option value="returned" <?php echo $status_filter == 'returned' ? 'selected' : ''; ?>>مرتجع</option>
                </select>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary" style="width:100%;">بحث</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="tracking-table">
                <thead>
                    <tr>
                        <th>رقم الشحنة</th>
                        <th>السلة</th>
                        <th>الحالة</th>
                        <th>شركة الشحن</th>
                        <th>التاريخ</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tracking_records)): ?>
                        <tr><td colspan="6" style="text-align: center; padding: 20px;">لا توجد سجلات تطابق معايير البحث.</td></tr>
                    <?php else: ?>
                        <?php foreach ($tracking_records as $record): ?>
                        <tr id="row-<?php echo $record['id']; ?>">
                            <td><strong><?php echo htmlspecialchars($record['tracking_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($record['basket_code']); ?></td>
                            <td>
                                <select class="status-select status-<?php echo $record['status']; ?>" onchange="updateStatus(<?php echo $record['id']; ?>, this.value, this)">
                                    <option value="pending" <?php echo $record['status'] == 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                                    <option value="in_transit" <?php echo $record['status'] == 'in_transit' ? 'selected' : ''; ?>>في الطريق</option>
                                    <option value="delivered" <?php echo $record['status'] == 'delivered' ? 'selected' : ''; ?>>تم التوصيل</option>
                                    <option value="returned" <?php echo $record['status'] == 'returned' ? 'selected' : ''; ?>>مرتجع</option>
                                </select>
                            </td>
                            <td><?php echo htmlspecialchars($record['carrier']); ?></td>
                            <td><?php echo date('Y/m/d', strtotime($record['created_at'])); ?></td>
                            <td>
                                <button onclick="deleteRow(<?php echo $record['id']; ?>)" style="color:red; background:none; border:none; cursor:pointer;"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===== NEW: ADD BASKET SELECTION MODAL ===== -->
<div id="selectBasketModal" class="modal" style="display: none;" onclick="if(event.target === this) closeSelectBasketModal()">
    <div class="modal-content">
        <div class="modal-header">
            <h3>اختر سلة لإضافة رقم شحنة</h3>
            <button onclick="closeSelectBasketModal()"><i class="fas fa-times text-2xl"></i></button>
        </div>

        <div style="margin-bottom: 1rem; position: relative;">
            <input type="text" id="modalBasketSearch" placeholder="🔍 ابحث عن سلة بالرقم أو الاسم..."
                   class="form-control" style="padding-right: 2.5rem;"
                   oninput="filterBasketsInModal()">
            <i class="fas fa-search" style="position: absolute; right: 1rem; top: 0.9rem; color: #9ca3af;"></i>
        </div>

        <div id="modalBasketsList" class="modal-body">
             <!-- Basket list will be rendered here by JavaScript -->
        </div>

        <div class="modal-footer">
            <button onclick="closeSelectBasketModal()" class="btn" style="background-color: #e5e7eb; color: #374151;">إغلاق</button>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Data from PHP ---
    const basketsForAddForm = <?php echo json_encode($baskets_for_form); ?>;
    const allBasketsForFilter = <?php echo json_encode($all_baskets_for_filter); ?>;
    const selectedBasketIdForFilter = <?php echo json_encode($basket_filter); ?>;

    // --- Logic for Filter Dropdown (No change here) ---
    const basketFilterSearchInput = document.getElementById('basket_filter_search');
    const basketFilterSelect = document.getElementById('basket_filter_select');
    function populateFilterBaskets(filter = '') {
        basketFilterSelect.innerHTML = '<option value="">كل السلات</option>';
        allBasketsForFilter.filter(b => 
            (b.basket_code + b.basket_name).toLowerCase().includes(filter.toLowerCase())
        ).forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = `${b.basket_code} - ${b.basket_name}`;
            if (b.id == selectedBasketIdForFilter) opt.selected = true;
            basketFilterSelect.appendChild(opt);
        });
    }
    basketFilterSearchInput.addEventListener('input', e => populateFilterBaskets(e.target.value));
    populateFilterBaskets();

    // Prevent form submission on Enter key in modal search
    document.getElementById('modalBasketSearch').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
        }
    });
     // Validate form on submit
    document.getElementById('addTrackingForm').addEventListener('submit', function(e) {
        const basketId = document.getElementById('selected_basket_id').value;
        if (!basketId) {
            e.preventDefault();
            alert('يرجى اختيار سلة من القائمة.');
        }
    });

});

// ===== NEW: MODAL MANAGEMENT FUNCTIONS =====

const modal = document.getElementById('selectBasketModal');
const allBasketsForModal = <?php echo json_encode($baskets_for_form); ?>;

function openSelectBasketModal() {
    modal.style.display = 'flex';
    document.getElementById('modalBasketSearch').value = ''; // Clear search on open
    renderBasketsInModal(allBasketsForModal);
    document.getElementById('modalBasketSearch').focus();
}

function closeSelectBasketModal() {
    modal.style.display = 'none';
}

function renderBasketsInModal(baskets) {
    const container = document.getElementById('modalBasketsList');
    if (!baskets || baskets.length === 0) {
        container.innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--gray-500);"><i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 0.5rem;"></i><p>لا توجد سلال متاحة.</p></div>`;
        return;
    }
    container.innerHTML = baskets.map(basket => `
        <div onclick="selectBasket(${basket.id}, '${basket.basket_code}', '${basket.basket_name.replace(/'/g, "\\'")}')" 
             style="display: flex; align-items: center; padding: 1rem; cursor: pointer; border-bottom: 1px solid var(--gray-100); transition: background-color 0.2s;"
             onmouseover="this.style.backgroundColor='#fef3c7'" onmouseout="this.style.backgroundColor='transparent'">
            <div style="flex: 1;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: bold; color: #1e40af; font-size: 1.1rem;">${basket.basket_code}</span>
                    <span style="font-weight: bold; color: var(--warning-color);">${parseFloat(basket.final_amount || 0).toFixed(2)} ر.ي</span>
                </div>
                <div style="font-size: 0.9rem; color: var(--gray-500); margin-top: 0.25rem;">
                   <i class="fas fa-shopping-basket" style="margin-left: 4px;"></i> ${basket.basket_name || 'بدون اسم'}
                </div>
            </div>
        </div>
    `).join('');
}

function filterBasketsInModal() {
    const searchTerm = document.getElementById('modalBasketSearch').value.trim().toLowerCase();
    const filtered = searchTerm 
        ? allBasketsForModal.filter(b => 
            b.basket_code.toLowerCase().includes(searchTerm) || 
            (b.basket_name && b.basket_name.toLowerCase().includes(searchTerm))
          ) 
        : allBasketsForModal;
    renderBasketsInModal(filtered);
}

function selectBasket(id, code, name) {
    document.getElementById('selected_basket_id').value = id;
    document.getElementById('selected_basket_display').value = `${code} - ${name}`;
    closeSelectBasketModal();
}


// --- AJAX Functions (No changes here) ---
async function updateStatus(id, status, el) {
    const formData = new FormData();
    formData.append('action', 'ajax_update_status');
    formData.append('tracking_id', id);
    formData.append('status', status);
    
    el.className = `status-select status-${status}`;
    try {
        await fetch('tracking.php', { method: 'POST', body: formData });
    } catch (error) {
        console.error("Failed to update status:", error);
    }
}

async function deleteRow(id) {
    if (!confirm('هل أنت متأكد من حذف هذا السجل؟')) return;
    const formData = new FormData();
    formData.append('action', 'ajax_delete_tracking');
    formData.append('tracking_id', id);
    
    try {
        const res = await fetch('tracking.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            document.getElementById(`row-${id}`).remove();
        } else {
            alert('فشل حذف السجل.');
        }
    } catch (error) {
        console.error("Deletion error:", error);
        alert('حدث خطأ أثناء محاولة الحذف.');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>