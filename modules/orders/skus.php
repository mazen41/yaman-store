<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!hasPermission($user_id, 'orders_skus', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول لهذه الصفحة.';
    header('Location: ../../index.php');
    exit();
}

// ── AJAX: update SKU ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_sku') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hasPermission($user_id, 'orders_skus', 'add')) {
        echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية التعديل']);
        exit();
    }

    $item_id = (int)($_POST['item_id'] ?? 0);
    $sku     = trim((string)($_POST['sku'] ?? ''));

    if ($item_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة']);
        exit();
    }

    try {
        $stmt = $db->prepare("UPDATE order_items SET shein_sku = :sku WHERE id = :id");
        $stmt->execute([':sku' => ($sku === '' ? null : $sku), ':id' => $item_id]);
        echo json_encode([
            'success'    => true,
            'message'    => 'تم حفظ SKU بنجاح ✓',
            'remove_row' => false,
            'item_id'    => $item_id,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'فشل الحفظ، حاول مجدداً']);
    }
    exit();
}

// ── Page setup ────────────────────────────────────────────────────────────────
$can_add_skus    = hasPermission($user_id, 'orders_skus', 'add');
$target_order_id = max(0, (int)($_GET['order_id'] ?? 0));
$date_from       = trim($_GET['date_from'] ?? '');
$date_to         = trim($_GET['date_to']   ?? '');

// ── Pagination constants ──────────────────────────────────────────────────────
const ORDERS_PER_PAGE = 20;
$current_page = max(1, (int)($_GET['page'] ?? 1));

// ── Build WHERE clause ────────────────────────────────────────────────────────
$single_order_mode = $target_order_id > 0;
$sql_conditions = $single_order_mode ? [] : ["TRIM(COALESCE(oi.shein_sku, '')) = ''"];
$sql_params     = [];

if ($target_order_id > 0) { $sql_conditions[] = 'o.id = ?';               $sql_params[] = $target_order_id; }
if ($date_from !== '')    { $sql_conditions[] = 'DATE(o.created_at) >= ?'; $sql_params[] = $date_from; }
if ($date_to   !== '')    { $sql_conditions[] = 'DATE(o.created_at) <= ?'; $sql_params[] = $date_to; }

$where_clause = !empty($sql_conditions) ? implode(' AND ', $sql_conditions) : '1=1';

// ── Step 1: count distinct orders (for pagination) ────────────────────────────
$count_stmt = $db->prepare("
    SELECT COUNT(DISTINCT o.id) AS total_orders
    FROM order_items oi
    INNER JOIN customer_orders o ON o.id = oi.order_id
    WHERE {$where_clause}
");
$count_stmt->execute($sql_params);
$total_orders = (int)$count_stmt->fetchColumn();
$total_pages  = max(1, (int)ceil($total_orders / ORDERS_PER_PAGE));
$current_page = min($current_page, $total_pages);
$offset       = ($current_page - 1) * ORDERS_PER_PAGE;

// ── Step 2: fetch the ORDER IDs for the current page ─────────────────────────
$order_ids_stmt = $db->prepare("
    SELECT DISTINCT o.id
    FROM order_items oi
    INNER JOIN customer_orders o ON o.id = oi.order_id
    WHERE {$where_clause}
    ORDER BY o.id DESC
    LIMIT " . ORDERS_PER_PAGE . " OFFSET {$offset}
");
$order_ids_stmt->execute($sql_params);
$page_order_ids = $order_ids_stmt->fetchAll(PDO::FETCH_COLUMN);

// ── Step 3: count ALL missing items (global stat, not page-scoped) ────────────
$total_items_stmt = $db->prepare("
    SELECT COUNT(*) FROM order_items oi
    INNER JOIN customer_orders o ON o.id = oi.order_id
    WHERE {$where_clause}
");
$total_items_stmt->execute($sql_params);
$total_missing_items = (int)$total_items_stmt->fetchColumn();

// ── Step 4: fetch all items for the orders on this page ──────────────────────
$orders_map     = [];
$items_by_order = [];

if (!empty($page_order_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($page_order_ids), '?'));
    
    // Build the WHERE clause conditionally based on mode
    $sku_condition = $single_order_mode ? '1=1' : "TRIM(COALESCE(oi.shein_sku, '')) = ''";

    $flat_stmt = $db->prepare("
        SELECT
            oi.id          AS item_id,
            oi.order_id,
            oi.product_name,
            oi.quantity,
            oi.shein_sku,
            o.order_number,
            o.created_at,
            o.status,
            COALESCE(c.name, '') AS customer_name
        FROM order_items oi
        INNER JOIN customer_orders o ON o.id = oi.order_id
        LEFT  JOIN customers       c ON c.id = o.customer_id
        WHERE o.id IN ({$in_placeholders})
          AND ({$sku_condition})
        ORDER BY o.id DESC, oi.id ASC
    ");
    $flat_stmt->execute($page_order_ids);
    $flat_rows = $flat_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($flat_rows as $row) {
        $oid = (int)$row['order_id'];
        if (!isset($orders_map[$oid])) {
            $orders_map[$oid] = [
                'id'            => $oid,
                'order_number'  => $row['order_number'],
                'created_at'    => $row['created_at'],
                'status'        => $row['status'],
                'customer_name' => $row['customer_name'],
            ];
            $items_by_order[$oid] = [];
        }
        $items_by_order[$oid][] = [
            'id'           => (int)$row['item_id'],
            'order_id'     => $oid,
            'product_name' => $row['product_name'],
            'quantity'     => (int)($row['quantity'] ?? 1),
            'shein_sku'    => $row['shein_sku'],
        ];
    }
}

// Preserve original order (DESC by id) using the page_order_ids sequence
$orders = array_values(array_filter(
    array_map(fn($id) => $orders_map[$id] ?? null, $page_order_ids)
));

// ── Pagination URL builder ─────────────────────────────────────────────────────
function pagUrl(int $page, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page' => $page]);
    unset($params['order_id']); // don't carry single-order filter across pages
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap');

*{box-sizing:border-box;}

.sku-page {
    font-family:'Cairo',sans-serif;
    min-height:100vh;
    background:#f1f5f9;
    padding:1.5rem 1rem 3rem;
    direction:rtl;
}

/* ── Header ── */
.sku-header {
    background:linear-gradient(135deg,#1e293b 0%,#0f172a 60%,#1e3a5f 100%);
    border-radius:20px; padding:1.75rem 2rem; margin-bottom:1.25rem;
    position:relative; overflow:hidden;
    box-shadow:0 10px 40px rgba(15,23,42,.25);
}
.sku-header::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(ellipse 60% 80% at 80% 50%,rgba(59,130,246,.12) 0%,transparent 70%);
    pointer-events:none;
}
.sku-header h1 { font-size:1.7rem; font-weight:900; color:#f8fafc; margin:0 0 .25rem; letter-spacing:-.5px; }
.sku-header p  { font-size:.875rem; color:#94a3b8; margin:0; }
.stats-row { display:flex; gap:.75rem; margin-top:1.25rem; flex-wrap:wrap; }
.stat-pill {
    display:flex; align-items:center; gap:.5rem;
    padding:.55rem 1rem; border-radius:50px;
    font-size:.8rem; font-weight:700; border:1px solid; backdrop-filter:blur(8px);
}
.stat-pill.amber { background:rgba(251,191,36,.12); border-color:rgba(251,191,36,.3); color:#fbbf24; }
.stat-pill.blue  { background:rgba(59,130,246,.12);  border-color:rgba(59,130,246,.3);  color:#60a5fa; }
.stat-pill.green { background:rgba(34,197,94,.12);   border-color:rgba(34,197,94,.3);   color:#4ade80; }
.stat-pill .dot  { width:7px; height:7px; border-radius:50%; background:currentColor; animation:pulse-dot 1.8s ease-in-out infinite; }
@keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.7)} }

/* ── Filter bar ── */
.filter-bar {
    background:#fff; border-radius:16px; padding:1rem 1.25rem;
    margin-bottom:1.25rem; border:1px solid #e2e8f0;
    box-shadow:0 1px 6px rgba(0,0,0,.05);
    display:flex; flex-wrap:wrap; align-items:flex-end; gap:.75rem;
}
.filter-bar label { display:block; font-size:.75rem; font-weight:700; color:#64748b; margin-bottom:.35rem; letter-spacing:.3px; }
.filter-bar input[type="date"] {
    height:2.4rem; border:1.5px solid #e2e8f0; border-radius:10px;
    padding:0 .75rem; font-family:'Cairo',sans-serif; font-size:.85rem;
    color:#1e293b; background:#f8fafc; outline:none; direction:ltr;
    transition:border-color .2s,box-shadow .2s;
}
.filter-bar input[type="date"]:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
.btn-filter {
    height:2.4rem; padding:0 1.25rem; border-radius:10px; border:none;
    font-family:'Cairo',sans-serif; font-size:.85rem; font-weight:700; cursor:pointer; transition:all .2s;
}
.btn-filter.primary { background:#1e293b; color:#fff; }
.btn-filter.primary:hover { background:#0f172a; transform:translateY(-1px); box-shadow:0 4px 12px rgba(15,23,42,.25); }
.btn-filter.ghost { background:#f1f5f9; color:#64748b; border:1.5px solid #e2e8f0; }
.btn-filter.ghost:hover { background:#e2e8f0; }

/* ── Order card ── */
.order-card {
    background:#fff; border:1.5px solid #e2e8f0; border-radius:18px;
    overflow:hidden; margin-bottom:1rem;
    box-shadow:0 2px 8px rgba(0,0,0,.05);
    transition:box-shadow .25s,border-color .25s;
    animation:card-in .35s ease both;
}
.order-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.09); border-color:#cbd5e1; }
@keyframes card-in { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

.order-header {
    display:flex; align-items:center; justify-content:space-between;
    gap:1rem; padding:1rem 1.25rem; cursor:pointer;
    background:#f8fafc; border-bottom:1.5px solid transparent;
    user-select:none; transition:background .2s,border-color .2s;
}
.order-header:hover { background:#f1f5f9; }
.order-card.is-open .order-header { border-bottom-color:#e2e8f0; }

.order-meta { flex:1; min-width:0; }
.order-num { font-size:1rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
.order-num .badge-status { font-size:.68rem; font-weight:700; padding:.2rem .6rem; border-radius:50px; background:#dbeafe; color:#1d4ed8; }
.order-sub { font-size:.78rem; color:#64748b; margin-top:.3rem; display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; }
.order-sub span { display:flex; align-items:center; gap:.25rem; }

.missing-badge {
    background:#fff7ed; border:1.5px solid #fed7aa; color:#c2410c;
    font-size:.75rem; font-weight:800; padding:.35rem .8rem;
    border-radius:50px; white-space:nowrap; flex-shrink:0;
}
.chevron { color:#94a3b8; font-size:1.1rem; transition:transform .3s ease; flex-shrink:0; }
.order-card.is-open .chevron { transform:rotate(180deg); }

/* ── Collapsible items ── */
.items-wrapper {
    display:grid; grid-template-rows:0fr;
    transition:grid-template-rows .35s cubic-bezier(.4,0,.2,1); overflow:hidden;
}
.order-card.is-open .items-wrapper { grid-template-rows:1fr; }
.items-inner { min-height:0; overflow:hidden; }
.items-area { padding:.875rem 1.25rem 1.25rem; display:flex; flex-direction:column; gap:.625rem; }

/* ── Item row ── */
.item-row {
    background:#fffbf5; border:1.5px solid #fed7aa; border-radius:12px;
    padding:.75rem 1rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap;
    animation:item-in .28s ease both;
    transition:border-color .2s,background .2s,transform .2s,opacity .3s;
}
.item-row:hover  { border-color:#fb923c; background:#fff7ed; }
.item-row.saving { opacity:.6; pointer-events:none; }
.item-row.saved-out { transform:translateX(-20px); opacity:0; }
.item-row.saved { border-color:#86efac; background:#f0fdf4; }
.item-row.saved:hover { border-color:#22c55e; background:#ecfdf5; }
.item-row.saved .item-num { background:#bbf7d0; color:#15803d; }
.saved-sku-pill { background:#dcfce7 !important; color:#166534 !important; border:1px solid #86efac; }
@keyframes item-in { from{opacity:0;transform:translateX(8px)} to{opacity:1;transform:translateX(0)} }

.item-num { width:28px; height:28px; border-radius:8px; background:#fed7aa; color:#c2410c; font-size:.72rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.item-info { flex:1; min-width:0; }
.item-name { font-size:.875rem; font-weight:700; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.item-details { font-size:.75rem; color:#94a3b8; margin-top:.2rem; display:flex; gap:.5rem; flex-wrap:wrap; }
.item-details span { background:#f1f5f9; padding:.1rem .45rem; border-radius:4px; font-family:'JetBrains Mono',monospace; font-size:.7rem; }

.sku-field { display:flex; align-items:center; gap:.5rem; flex-shrink:0; }
.sku-input {
    width:160px; height:2.25rem; border:1.5px solid #cbd5e1; border-radius:9px;
    padding:0 .75rem; font-family:'JetBrains Mono',monospace; font-size:.82rem;
    color:#0f172a; background:#fff; outline:none;
    transition:border-color .2s,box-shadow .2s; direction:ltr; text-align:left;
}
.sku-input:focus     { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
.sku-input.has-value { border-color:#22c55e; background:#f0fdf4; }

.save-btn {
    height:2.25rem; padding:0 1rem; background:#1e293b; color:#fff; border:none;
    border-radius:9px; font-family:'Cairo',sans-serif; font-size:.82rem; font-weight:700;
    cursor:pointer; transition:all .2s; white-space:nowrap; display:flex; align-items:center; gap:.35rem;
}
.save-btn:hover:not(:disabled) { background:#0f172a; transform:translateY(-1px); box-shadow:0 4px 12px rgba(15,23,42,.2); }
.save-btn:disabled { opacity:.5; cursor:not-allowed; transform:none; }
.item-row.saved .save-btn:disabled { opacity:1; background:#16a34a; }
.save-btn .spinner { width:14px; height:14px; border:2px solid rgba(255,255,255,.3); border-top-color:#fff; border-radius:50%; animation:spin .6s linear infinite; display:none; }
.save-btn.loading .spinner   { display:block; }
.save-btn.loading .btn-label { display:none; }
@keyframes spin { to{transform:rotate(360deg)} }

/* ── Show-more/less footer ── */
.items-footer {
    display:flex; align-items:center; justify-content:space-between;
    padding:.625rem 1.25rem .875rem; gap:.5rem; flex-wrap:wrap;
}
.batch-btn {
    height:2rem; padding:0 1.1rem; border-radius:8px; border:1.5px solid #e2e8f0;
    background:#f8fafc; color:#475569; font-family:'Cairo',sans-serif;
    font-size:.8rem; font-weight:700; cursor:pointer; transition:all .18s;
    display:flex; align-items:center; gap:.35rem;
}
.batch-btn:hover { background:#1e293b; color:#fff; border-color:#1e293b; }
.batch-counter { font-size:.75rem; color:#94a3b8; font-weight:600; }

/* ── Pagination ── */
.pagination-wrap {
    background:#fff; border:1.5px solid #e2e8f0; border-radius:16px;
    padding:1rem 1.25rem; margin-top:.5rem;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:.75rem;
    box-shadow:0 1px 6px rgba(0,0,0,.05);
}
.pagination-info { font-size:.8rem; color:#64748b; font-weight:600; }
.pagination-info strong { color:#1e293b; }
.pagination-btns { display:flex; align-items:center; gap:.375rem; flex-wrap:wrap; }

.pag-btn {
    min-width:2.25rem; height:2.25rem; padding:0 .625rem;
    border:1.5px solid #e2e8f0; border-radius:10px;
    background:#f8fafc; color:#475569;
    font-family:'Cairo',sans-serif; font-size:.82rem; font-weight:700;
    text-decoration:none; display:inline-flex; align-items:center; justify-content:center;
    transition:all .18s; cursor:pointer; white-space:nowrap;
}
.pag-btn:hover:not(.disabled):not(.active) { background:#1e293b; color:#fff; border-color:#1e293b; transform:translateY(-1px); }
.pag-btn.active { background:#1e293b; color:#fff; border-color:#1e293b; pointer-events:none; box-shadow:0 3px 10px rgba(15,23,42,.25); }
.pag-btn.disabled { opacity:.35; cursor:not-allowed; pointer-events:none; }
.pag-btn.ellipsis { pointer-events:none; border-color:transparent; background:transparent; color:#94a3b8; }

/* ── Toast ── */
#alertBox {
    position:fixed; bottom:1.5rem; left:50%; transform:translateX(-50%) translateY(80px);
    min-width:260px; max-width:90vw; padding:.75rem 1.25rem; border-radius:12px;
    font-size:.875rem; font-weight:700; text-align:center; z-index:9999;
    pointer-events:none; opacity:0; box-shadow:0 8px 30px rgba(0,0,0,.18);
    transition:transform .35s cubic-bezier(.34,1.56,.64,1),opacity .3s;
}
#alertBox.show    { transform:translateX(-50%) translateY(0); opacity:1; }
#alertBox.success { background:#052e16; color:#4ade80; border:1px solid rgba(74,222,128,.25); }
#alertBox.error   { background:#450a0a; color:#f87171; border:1px solid rgba(248,113,113,.25); }

/* ── Empty state ── */
.empty-state { background:#fff; border:1.5px dashed #cbd5e1; border-radius:18px; padding:3.5rem 2rem; text-align:center; color:#94a3b8; }
.empty-state .icon { font-size:2.5rem; margin-bottom:.75rem; }
.empty-state p { margin:0; font-size:.95rem; font-weight:600; }

/* ── Progress bar ── */
.progress-bar {
    position:fixed; top:0; left:0; right:0; height:3px;
    background:linear-gradient(90deg,#3b82f6,#60a5fa,#3b82f6);
    background-size:200% 100%; z-index:10000; transform:scaleX(0);
    transform-origin:left; transition:transform .3s ease; display:none;
    animation:progress-shimmer 1.5s linear infinite;
}
.progress-bar.active { display:block; transform:scaleX(1); }
@keyframes progress-shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

@media (max-width:600px) {
    .sku-header { padding:1.25rem; }
    .sku-header h1 { font-size:1.3rem; }
    .sku-field { width:100%; }
    .sku-input { flex:1; width:auto; }
    .item-row  { align-items:flex-start; }
    .filter-bar { flex-direction:column; align-items:stretch; }
    .filter-bar .filter-group { width:100%; }
    .filter-bar input[type="date"] { width:100%; }
    .pagination-wrap { flex-direction:column; align-items:flex-start; }
}
</style>

<div class="progress-bar" id="progressBar"></div>

<div class="sku-page">
<div style="max-width:860px;margin:0 auto;display:flex;flex-direction:column;gap:1rem;">

    <!-- ── Header ── -->
    <div class="sku-header">
        <h1>🏷️ إدخال رموز SKU</h1>
        <p>الطلبات التي تحتوي على منتجات بدون رمز SKU — أدخل الرموز الناقصة بسرعة</p>
        <div class="stats-row">
            <div class="stat-pill amber">
                <div class="dot"></div>
                <?php echo $total_orders; ?> طلب يحتوي عناصر ناقصة
            </div>
            <div class="stat-pill blue">
                <div class="dot"></div>
                <?php echo $total_missing_items; ?> منتج بدون SKU
            </div>
            <?php if ($total_pages > 1): ?>
            <div class="stat-pill green">
                <div class="dot"></div>
                صفحة <?php echo $current_page; ?> من <?php echo $total_pages; ?>
            </div>
            <?php endif; ?>
            <?php if ($date_from || $date_to): ?>
            <div class="stat-pill" style="background:rgba(168,85,247,.12);border-color:rgba(168,85,247,.3);color:#c084fc;">
                🗓 فلتر مفعّل
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Filters ── -->
    <div class="filter-bar">
        <form method="GET" style="display:contents;">
            <div class="filter-group">
                <label>من تاريخ</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="filter-group">
                <label>إلى تاريخ</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
                <button type="submit" class="btn-filter primary">🔍 تطبيق</button>
                <?php if ($can_add_skus): ?>
                <button type="button" class="btn-filter ghost" id="saveAllBtn">💾 حفظ الكل</button>
                <?php endif; ?>
                <?php if ($date_from || $date_to): ?>
                <a href="?" class="btn-filter ghost" style="display:inline-flex;align-items:center;text-decoration:none;">✕ إلغاء</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ── Orders list ── -->
    <div id="ordersList">
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <div class="icon">✅</div>
                <p>رائع! لا توجد منتجات ناقصة SKU<?php echo ($date_from || $date_to) ? ' في النطاق المحدد' : ''; ?></p>
            </div>
        <?php else: ?>
            <?php
            $BATCH_SIZE = 5;
            foreach ($orders as $idx => $order):
                $oid         = (int)$order['id'];
                $all_items   = $items_by_order[$oid] ?? [];
                if (empty($all_items)) continue;
                $total_count = count($all_items);
                $created     = $order['created_at'] ? date('Y/m/d — H:i', strtotime($order['created_at'])) : '—';
                $customer    = htmlspecialchars($order['customer_name'] ?? '');
                $items_json  = json_encode(array_map(fn($it) => [
                    'id'           => (int)$it['id'],
                    'order_id'     => $oid,
                    'product_name' => $it['product_name'],
                    'quantity'     => (int)($it['quantity'] ?? 1),
                    'shein_sku'    => $it['shein_sku'] ?? '',
                ], $all_items), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_QUOT);
            ?>
            <div
                id="order-<?php echo $oid; ?>"
                class="order-card"
                style="animation-delay:<?php echo $idx * 0.04; ?>s"
                data-order-id="<?php echo $oid; ?>"
                data-items='<?php echo $items_json; ?>'
                data-rendered="0"
                data-total="<?php echo $total_count; ?>"
                data-batch="<?php echo $BATCH_SIZE; ?>"
            >
                <div class="order-header" onclick="toggleOrder(<?php echo $oid; ?>)">
                    <div class="order-meta">
                        <div class="order-num">
                            رقم الطلب: <?php echo htmlspecialchars($order['order_number'] ?: '#'.$oid); ?>
                            <?php if ($order['status']): ?>
                            <span class="badge-status"><?php echo htmlspecialchars($order['status']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="order-sub">
                            <span>🕐 <?php echo $created; ?></span>
                            <?php if ($customer): ?><span>👤 <?php echo $customer; ?></span><?php endif; ?>
                            <span>🆔 <?php echo $oid; ?></span>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:.625rem;flex-shrink:0;">
                        <div class="missing-badge" id="badge-<?php echo $oid; ?>">
                            ⚠️ <span id="count-<?php echo $oid; ?>"><?php echo $total_count; ?></span> ناقص
                        </div>
                        <div class="chevron">▾</div>
                    </div>
                </div>

                <div class="items-wrapper">
                    <div class="items-inner">
                        <div class="items-area" id="items-<?php echo $oid; ?>"></div>
                        <div class="items-footer" id="footer-<?php echo $oid; ?>" style="display:none;">
                            <button class="batch-btn" id="show-more-<?php echo $oid; ?>"
                                    onclick="showMore(<?php echo $oid; ?>)" style="display:none;">
                                ⬇️ عرض المزيد
                            </button>
                            <span class="batch-counter" id="counter-<?php echo $oid; ?>"></span>
                            <button class="batch-btn" id="show-less-<?php echo $oid; ?>"
                                    onclick="showLess(<?php echo $oid; ?>)" style="display:none;">
                                ⬆️ عرض أقل
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── Pagination ── -->
    <?php if ($total_pages > 1): ?>
    <?php
    // Build page numbers with ellipsis
    function buildPageNumbers(int $current, int $total): array {
        $pages = [];
        if ($total <= 7) {
            for ($i = 1; $i <= $total; $i++) $pages[] = $i;
        } else {
            $pages[] = 1;
            if ($current > 4)           $pages[] = '...';
            $start = max(2, $current - 2);
            $end   = min($total - 1, $current + 2);
            for ($i = $start; $i <= $end; $i++) $pages[] = $i;
            if ($current < $total - 3)  $pages[] = '...';
            $pages[] = $total;
        }
        return $pages;
    }
    $page_numbers = buildPageNumbers($current_page, $total_pages);
    $page_start   = ($current_page - 1) * ORDERS_PER_PAGE + 1;
    $page_end     = min($current_page * ORDERS_PER_PAGE, $total_orders);
    ?>
    <div class="pagination-wrap">
        <div class="pagination-info">
            عرض <strong><?php echo $page_start; ?>–<?php echo $page_end; ?></strong>
            من <strong><?php echo $total_orders; ?></strong> طلب
        </div>
        <div class="pagination-btns">
            <!-- First -->
            <a class="pag-btn <?php echo $current_page <= 1 ? 'disabled' : ''; ?>"
               href="<?php echo $current_page > 1 ? pagUrl(1) : '#'; ?>" title="الأولى">«</a>
            <!-- Prev -->
            <a class="pag-btn <?php echo $current_page <= 1 ? 'disabled' : ''; ?>"
               href="<?php echo $current_page > 1 ? pagUrl($current_page - 1) : '#'; ?>" title="السابقة">‹</a>

            <?php foreach ($page_numbers as $pn): ?>
                <?php if ($pn === '...'): ?>
                    <span class="pag-btn ellipsis">…</span>
                <?php else: ?>
                    <a class="pag-btn <?php echo (int)$pn === $current_page ? 'active' : ''; ?>"
                       href="<?php echo pagUrl((int)$pn); ?>"><?php echo $pn; ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Next -->
            <a class="pag-btn <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>"
               href="<?php echo $current_page < $total_pages ? pagUrl($current_page + 1) : '#'; ?>" title="التالية">›</a>
            <!-- Last -->
            <a class="pag-btn <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>"
               href="<?php echo $current_page < $total_pages ? pagUrl($total_pages) : '#'; ?>" title="الأخيرة">»</a>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>

<div id="alertBox"></div>

<script>
const CAN_ADD_SKUS = <?php echo $can_add_skus ? 'true' : 'false'; ?>;

const progressBar = document.getElementById('progressBar');
const alertBox    = document.getElementById('alertBox');
let alertTimer;

function showAlert(msg, type = 'success') {
    clearTimeout(alertTimer);
    alertBox.textContent = msg;
    alertBox.className = type;
    alertBox.classList.add('show');
    alertTimer = setTimeout(() => alertBox.classList.remove('show'), 3000);
}

function buildItemRow(item, index) {
    const row = document.createElement('div');
    row.id = `row-${item.id}`;
    row.className = 'item-row';
    row.dataset.missingQty = item.quantity || 1;
    row.style.animationDelay = `${(index % 5) * 0.04}s`;

    const numBadge = document.createElement('div');
    numBadge.className = 'item-num';
    numBadge.textContent = index + 1;

    const info = document.createElement('div');
    info.className = 'item-info';

    const name = document.createElement('div');
    name.className = 'item-name';
    name.title = item.product_name || 'بدون اسم منتج';
    name.textContent = item.product_name || 'بدون اسم منتج';

    const details = document.createElement('div');
    details.className = 'item-details';
    details.innerHTML = `<span>ID: ${item.id}</span><span>الكمية: ${item.quantity || 1}</span>`;

    info.appendChild(name);
    info.appendChild(details);

    const skuField = document.createElement('div');
    skuField.className = 'sku-field';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'sku-input';
    input.dataset.itemId  = item.id;
    input.dataset.orderId = item.order_id;
    input.placeholder = 'أدخل SKU...';
    input.autocomplete = 'off';
    input.spellcheck = false;
    if (item.shein_sku) { input.value = item.shein_sku; input.classList.add('has-value'); row.classList.add('saved'); }
    if (!CAN_ADD_SKUS) { input.readOnly = true; input.disabled = true; }
    input.addEventListener('input', () => input.classList.toggle('has-value', input.value.trim().length > 0));
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saveSku(item.id); } });

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'save-btn';
    btn.dataset.itemId = item.id;
    btn.title = 'حفظ';
    if (!CAN_ADD_SKUS) btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span><span class="btn-label">حفظ ↵</span>';
    btn.addEventListener('click', () => saveSku(item.id));

    skuField.appendChild(input);
    skuField.appendChild(btn);
    row.appendChild(numBadge);
    row.appendChild(info);
    row.appendChild(skuField);
    return row;
}

function toggleOrder(oid) {
    const card = document.getElementById(`order-${oid}`);
    if (!card) return;
    if (card.classList.contains('is-open')) {
        card.classList.remove('is-open');
    } else {
        card.classList.add('is-open');
        if (parseInt(card.dataset.rendered, 10) === 0) renderBatch(oid);
    }
}

function getCardData(oid) {
    const card = document.getElementById(`order-${oid}`);
    if (!card) return null;
    return {
        card,
        items:    JSON.parse(card.dataset.items),
        rendered: parseInt(card.dataset.rendered, 10),
        total:    parseInt(card.dataset.total, 10),
        batch:    parseInt(card.dataset.batch, 10),
    };
}

function renderBatch(oid) {
    const d = getCardData(oid);
    if (!d) return;

    const area    = document.getElementById(`items-${oid}`);
    const footer  = document.getElementById(`footer-${oid}`);
    const moreBtn = document.getElementById(`show-more-${oid}`);
    const lessBtn = document.getElementById(`show-less-${oid}`);
    const counter = document.getElementById(`counter-${oid}`);

    const start = d.rendered;
    const end   = Math.min(start + d.batch, d.total);

    for (let i = start; i < end; i++) area.appendChild(buildItemRow(d.items[i], i));

    d.card.dataset.rendered = end;

    const hasMore         = end < d.total;
    const hasRenderedPast = end > d.batch;

    footer.style.display  = (hasMore || hasRenderedPast) ? 'flex' : 'none';
    moreBtn.style.display = hasMore ? 'inline-flex' : 'none';
    lessBtn.style.display = hasRenderedPast ? 'inline-flex' : 'none';
    if (counter) counter.textContent = `${end} / ${d.total} منتج`;
}

function showMore(oid) { renderBatch(oid); }

function showLess(oid) {
    const d = getCardData(oid);
    if (!d) return;

    const area    = document.getElementById(`items-${oid}`);
    const footer  = document.getElementById(`footer-${oid}`);
    const moreBtn = document.getElementById(`show-more-${oid}`);
    const lessBtn = document.getElementById(`show-less-${oid}`);
    const counter = document.getElementById(`counter-${oid}`);

    area.querySelectorAll('.item-row').forEach((row, i) => { if (i >= d.batch) row.remove(); });
    d.card.dataset.rendered = Math.min(d.batch, d.total);

    const hasMore = d.total > d.batch;
    footer.style.display  = hasMore ? 'flex' : 'none';
    moreBtn.style.display = hasMore ? 'inline-flex' : 'none';
    lessBtn.style.display = 'none';
    if (counter) counter.textContent = hasMore ? `${d.batch} / ${d.total} منتج` : '';
}

function focusNextInput(currentInput) {
    const inputs = [...document.querySelectorAll('.sku-input')];
    const i = inputs.indexOf(currentInput);
    if (i >= 0 && inputs[i + 1]) inputs[i + 1].focus();
}

function markItemRowSaved(itemId, sku, inputRef) {
    const row = document.getElementById(`row-${itemId}`);
    if (!row) return;
    row.classList.remove('saving');
    row.classList.add('saved');

    const input = row.querySelector('.sku-input');
    if (input) {
        input.value = sku;
        input.classList.add('has-value');
        // Keep editable so user can fix typos
        input.readOnly = false;
        input.disabled = false;
    }

    const btn = row.querySelector('.save-btn');
    if (btn) {
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.innerHTML = '<span class="spinner"></span><span class="btn-label">✏️ تحديث</span>';
    }

    const details = row.querySelector('.item-details');
    if (details) {
        let pill = details.querySelector('.saved-sku-pill');
        if (!pill) {
            pill = document.createElement('span');
            pill.className = 'saved-sku-pill';
            details.appendChild(pill);
        }
        pill.textContent = `SKU: ${sku}`;
    }

    if (inputRef) focusNextInput(inputRef);
}

function removeItemRow(itemId, orderId, inputRef) {
    const row = document.getElementById(`row-${itemId}`);
    if (row) {
        row.classList.add('saved-out');
        setTimeout(() => {
            row.remove();
            const card = document.getElementById(`order-${orderId}`);
            if (card) {
                let items = JSON.parse(card.dataset.items);
                items = items.filter(it => it.id !== itemId);
                card.dataset.items    = JSON.stringify(items);
                card.dataset.total    = items.length;
                card.dataset.rendered = Math.max(0, parseInt(card.dataset.rendered, 10) - 1);
            }
            const countEl = document.getElementById(`count-${orderId}`);
            const cardEl  = document.getElementById(`order-${orderId}`);
            if (countEl) {
                const left = parseInt(countEl.textContent, 10) - 1;
                countEl.textContent = left;
                if (left <= 0 && cardEl) {
                    cardEl.style.transition = 'opacity .4s,transform .4s';
                    cardEl.style.opacity    = '0';
                    cardEl.style.transform  = 'translateY(-10px)';
                    setTimeout(() => cardEl.remove(), 420);
                }
            }
        }, 320);
    }
    if (inputRef) focusNextInput(inputRef);
}

async function saveSku(itemId) {
    if (!CAN_ADD_SKUS) { showAlert('ليس لديك صلاحية إضافة SKU', 'error'); return; }

    const input = document.querySelector(`.sku-input[data-item-id="${itemId}"]`);
    const btn   = document.querySelector(`.save-btn[data-item-id="${itemId}"]`);
    if (!input || !btn) return;

    const sku = input.value.trim();
    if (!sku) {
        showAlert('⚠️ الرجاء إدخال رمز SKU أولاً', 'error');
        input.focus();
        input.style.borderColor = '#ef4444';
        setTimeout(() => input.style.borderColor = '', 1200);
        return;
    }

    btn.disabled = true;
    btn.classList.add('loading');
    input.closest('.item-row').classList.add('saving');
    progressBar.classList.add('active');

    const body = new URLSearchParams();
    body.set('action', 'update_sku');
    body.set('item_id', itemId);
    body.set('sku', sku);

    try {
        const res  = await fetch('skus.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'فشل الحفظ');
        showAlert(data.message, 'success');
        markItemRowSaved(itemId, sku, input);
    } catch (e) {
        showAlert(e.message || 'فشل الحفظ، حاول مجدداً', 'error');
        btn.disabled = false;
        btn.classList.remove('loading');
        const row = input.closest('.item-row');
        if (row) row.classList.remove('saving');
    } finally {
        progressBar.classList.remove('active');
    }
}

async function saveAllSkus() {
    const inputs = [...document.querySelectorAll('.sku-input')]
        .filter(inp => inp.value.trim().length > 0 && !inp.disabled && !inp.readOnly);
    if (!inputs.length) { showAlert('⚠️ أدخل SKU في منتج واحد على الأقل أولاً', 'error'); return; }
    for (const inp of inputs) await saveSku(inp.dataset.itemId);
}

const saveAllBtn = document.getElementById('saveAllBtn');
if (saveAllBtn) saveAllBtn.addEventListener('click', saveAllSkus);

<?php if (!$can_add_skus): ?>
showAlert('لديك صلاحية عرض فقط. لا يمكنك تعديل أو حفظ SKU.', 'error');
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
