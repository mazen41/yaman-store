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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_sku') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hasPermission($user_id, 'orders_skus', 'add')) {
        echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية التعديل']);
        exit();
    }

    $item_id = (int)($_POST['item_id'] ?? 0);
    $sku = trim((string)($_POST['sku'] ?? ''));

    if ($item_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة']);
        exit();
    }

    try {
        $stmt = $db->prepare("UPDATE order_items SET shein_sku = :sku WHERE id = :id");
        $stmt->execute([
            ':sku' => ($sku === '' ? null : $sku),
            ':id' => $item_id,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'تم حفظ SKU بنجاح ✓',
            'remove_row' => ($sku !== ''),
            'item_id' => $item_id,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'فشل الحفظ، حاول مجدداً']);
    }
    exit();
}

$can_add_skus = hasPermission($user_id, 'orders_skus', 'add');
$target_order_id = max(0, (int)($_GET['order_id'] ?? 0));

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Date filters
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');

$where_extra = '';
$params_count = [];
$params_fetch = [];

if ($target_order_id > 0) {
    $where_extra .= " AND o.id = :target_order_id";
    $params_count[':target_order_id'] = $target_order_id;
    $params_fetch[':target_order_id'] = $target_order_id;
}

if ($date_from !== '') {
    $where_extra .= " AND DATE(o.created_at) >= :date_from";
    $params_count[':date_from'] = $date_from;
    $params_fetch[':date_from'] = $date_from;
}
if ($date_to !== '') {
    $where_extra .= " AND DATE(o.created_at) <= :date_to";
    $params_count[':date_to'] = $date_to;
    $params_fetch[':date_to'] = $date_to;
}

$total_orders_stmt = $db->prepare("SELECT COUNT(DISTINCT o.id)
    FROM customer_orders o
    INNER JOIN order_items oi ON oi.order_id = o.id
    WHERE TRIM(COALESCE(oi.shein_sku, '')) = ''
    $where_extra");
$total_orders_stmt->execute($params_count);
$total_orders = (int)$total_orders_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_orders / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$orders_stmt = $db->prepare("SELECT o.id, o.order_number, o.created_at, COALESCE(c.name, '') AS customer_name, o.status, COUNT(oi.id) AS missing_items_count
    FROM customer_orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    INNER JOIN order_items oi ON oi.order_id = o.id
    WHERE TRIM(COALESCE(oi.shein_sku, '')) = ''
    $where_extra
    GROUP BY o.id, o.order_number, o.created_at, c.name, o.status
    ORDER BY o.id DESC
    LIMIT " . (int)$per_page . " OFFSET " . (int)$offset);
$orders_stmt->execute($params_fetch);
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

$order_ids = array_map('intval', array_column($orders, 'id'));
$items_by_order = [];
$total_missing_items = 0;

if (!empty($order_ids)) {
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $items_stmt = $db->prepare("SELECT
            oi.id,
            oi.order_id,
            oi.product_name,
            oi.quantity,
            oi.shein_sku
        FROM order_items oi
        WHERE oi.order_id IN ($placeholders)
          AND TRIM(COALESCE(oi.shein_sku, '')) = ''
        ORDER BY oi.order_id DESC, oi.id ASC");
    $items_stmt->execute($order_ids);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $oid = (int)$item['order_id'];
        if (!isset($items_by_order[$oid])) $items_by_order[$oid] = [];
        $items_by_order[$oid][] = $item;
        $total_missing_items++;
    }
}

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap');

*{box-sizing:border-box;}

.sku-page {
    font-family: 'Cairo', sans-serif;
    min-height: 100vh;
    background: #f1f5f9;
    padding: 1.5rem 1rem 3rem;
    direction: rtl;
}

/* ── Hero header ── */
.sku-header {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 60%, #1e3a5f 100%);
    border-radius: 20px;
    padding: 1.75rem 2rem;
    margin-bottom: 1.25rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(15,23,42,.25);
}
.sku-header::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse 60% 80% at 80% 50%, rgba(59,130,246,.12) 0%, transparent 70%);
    pointer-events: none;
}
.sku-header h1 {
    font-size: 1.7rem;
    font-weight: 900;
    color: #f8fafc;
    margin: 0 0 .25rem;
    letter-spacing: -.5px;
}
.sku-header p {
    font-size: .875rem;
    color: #94a3b8;
    margin: 0;
}
.stats-row {
    display: flex;
    gap: .75rem;
    margin-top: 1.25rem;
    flex-wrap: wrap;
}
.stat-pill {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .55rem 1rem;
    border-radius: 50px;
    font-size: .8rem;
    font-weight: 700;
    border: 1px solid;
    backdrop-filter: blur(8px);
}
.stat-pill.amber { background: rgba(251,191,36,.12); border-color: rgba(251,191,36,.3); color: #fbbf24; }
.stat-pill.blue  { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.3); color: #60a5fa; }
.stat-pill .dot  { width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: pulse-dot 1.8s ease-in-out infinite; }
@keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.7)} }

/* ── Filter bar ── */
.filter-bar {
    background: #fff;
    border-radius: 16px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: .75rem;
}
.filter-bar label {
    display: block;
    font-size: .75rem;
    font-weight: 700;
    color: #64748b;
    margin-bottom: .35rem;
    letter-spacing: .3px;
}
.filter-bar input[type="date"] {
    height: 2.4rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 0 .75rem;
    font-family: 'Cairo', sans-serif;
    font-size: .85rem;
    color: #1e293b;
    background: #f8fafc;
    transition: border-color .2s, box-shadow .2s;
    outline: none;
    direction: ltr;
}
.filter-bar input[type="date"]:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
.btn-filter {
    height: 2.4rem;
    padding: 0 1.25rem;
    border-radius: 10px;
    border: none;
    font-family: 'Cairo', sans-serif;
    font-size: .85rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
}
.btn-filter.primary { background: #1e293b; color: #fff; }
.btn-filter.primary:hover { background: #0f172a; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15,23,42,.25); }
.btn-filter.ghost { background: #f1f5f9; color: #64748b; border: 1.5px solid #e2e8f0; }
.btn-filter.ghost:hover { background: #e2e8f0; }

/* ── Order card ── */
.order-card {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    transition: box-shadow .25s, border-color .25s;
    animation: card-in .35s ease both;
}
.order-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.09); border-color: #cbd5e1; }
@keyframes card-in { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

.order-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.25rem;
    cursor: pointer;
    background: #f8fafc;
    border-bottom: 1.5px solid #e2e8f0;
    user-select: none;
    list-style: none;
    transition: background .2s;
}
.order-summary:hover { background: #f1f5f9; }
.order-summary::-webkit-details-marker { display: none; }

.order-meta { flex: 1; min-width: 0; }
.order-num {
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: .5rem;
    flex-wrap: wrap;
}
.order-num .badge-status {
    font-size: .68rem;
    font-weight: 700;
    padding: .2rem .6rem;
    border-radius: 50px;
    background: #dbeafe;
    color: #1d4ed8;
    letter-spacing: .2px;
}
.order-sub {
    font-size: .78rem;
    color: #64748b;
    margin-top: .3rem;
    display: flex;
    flex-wrap: wrap;
    gap: .6rem;
    align-items: center;
}
.order-sub span { display: flex; align-items: center; gap: .25rem; }

.missing-badge {
    background: #fff7ed;
    border: 1.5px solid #fed7aa;
    color: #c2410c;
    font-size: .75rem;
    font-weight: 800;
    padding: .35rem .8rem;
    border-radius: 50px;
    white-space: nowrap;
    flex-shrink: 0;
}

.chevron {
    color: #94a3b8;
    font-size: 1.1rem;
    transition: transform .3s ease;
    flex-shrink: 0;
}
details[open] .chevron { transform: rotate(180deg); }

/* ── Items area ── */
.items-area {
    padding: .875rem 1.25rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: .625rem;
}

.item-row {
    background: #fffbf5;
    border: 1.5px solid #fed7aa;
    border-radius: 12px;
    padding: .75rem 1rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
    transition: border-color .2s, background .2s, transform .2s, opacity .3s;
    animation: item-in .3s ease both;
}
.item-row:hover { border-color: #fb923c; background: #fff7ed; }
@keyframes item-in { from{opacity:0;transform:translateX(8px)} to{opacity:1;transform:translateX(0)} }
.item-row.saving { opacity: .6; pointer-events: none; }
.item-row.saved-out { transform: translateX(-20px); opacity: 0; }

.item-num {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: #fed7aa;
    color: #c2410c;
    font-size: .72rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.item-info { flex: 1; min-width: 0; }
.item-name {
    font-size: .875rem;
    font-weight: 700;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.item-details {
    font-size: .75rem;
    color: #94a3b8;
    margin-top: .2rem;
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
}
.item-details span {
    background: #f1f5f9;
    padding: .1rem .45rem;
    border-radius: 4px;
    font-family: 'JetBrains Mono', monospace;
    font-size: .7rem;
}

.sku-field {
    display: flex;
    align-items: center;
    gap: .5rem;
    flex-shrink: 0;
}
.sku-input {
    width: 160px;
    height: 2.25rem;
    border: 1.5px solid #cbd5e1;
    border-radius: 9px;
    padding: 0 .75rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: .82rem;
    color: #0f172a;
    background: #fff;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    direction: ltr;
    text-align: left;
}
.sku-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
.sku-input.has-value { border-color: #22c55e; background: #f0fdf4; }

.save-btn {
    height: 2.25rem;
    padding: 0 1rem;
    background: #1e293b;
    color: #fff;
    border: none;
    border-radius: 9px;
    font-family: 'Cairo', sans-serif;
    font-size: .82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: .35rem;
}
.save-btn:hover:not(:disabled) { background: #0f172a; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15,23,42,.2); }
.save-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.save-btn .spinner {
    width: 14px; height: 14px;
    border: 2px solid rgba(255,255,255,.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .6s linear infinite;
    display: none;
}
.save-btn.loading .spinner { display: block; }
.save-btn.loading .btn-label { display: none; }

@keyframes spin { to{transform:rotate(360deg)} }

/* ── Alert toast ── */
#alertBox {
    position: fixed;
    bottom: 1.5rem;
    left: 50%;
    transform: translateX(-50%) translateY(80px);
    min-width: 260px;
    max-width: 90vw;
    padding: .75rem 1.25rem;
    border-radius: 12px;
    font-size: .875rem;
    font-weight: 700;
    text-align: center;
    z-index: 9999;
    pointer-events: none;
    transition: transform .35s cubic-bezier(.34,1.56,.64,1), opacity .3s;
    opacity: 0;
    box-shadow: 0 8px 30px rgba(0,0,0,.18);
}
#alertBox.show { transform: translateX(-50%) translateY(0); opacity: 1; }
#alertBox.success { background: #052e16; color: #4ade80; border: 1px solid rgba(74,222,128,.25); }
#alertBox.error   { background: #450a0a; color: #f87171; border: 1px solid rgba(248,113,113,.25); }

/* ── Empty state ── */
.empty-state {
    background: #fff;
    border: 1.5px dashed #cbd5e1;
    border-radius: 18px;
    padding: 3.5rem 2rem;
    text-align: center;
    color: #94a3b8;
}
.empty-state .icon { font-size: 2.5rem; margin-bottom: .75rem; }
.empty-state p { margin: 0; font-size: .95rem; font-weight: 600; }

/* ── Pagination ── */
.pagination {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: .875rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: .25rem;
    font-size: .875rem;
    color: #64748b;
    font-weight: 600;
}
.pag-btns { display: flex; gap: .5rem; }
.pag-btn {
    padding: .45rem .9rem;
    border: 1.5px solid #e2e8f0;
    border-radius: 9px;
    background: #f8fafc;
    color: #475569;
    font-family: 'Cairo', sans-serif;
    font-size: .82rem;
    font-weight: 700;
    text-decoration: none;
    transition: all .2s;
}
.pag-btn:hover { background: #1e293b; color: #fff; border-color: #1e293b; transform: translateY(-1px); }

/* ── Progress bar at top ── */
.progress-bar {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, #3b82f6, #60a5fa, #3b82f6);
    background-size: 200% 100%;
    z-index: 10000;
    transform: scaleX(0);
    transform-origin: left;
    transition: transform .3s ease;
    animation: progress-shimmer 1.5s linear infinite;
    display: none;
}
.progress-bar.active { display: block; transform: scaleX(1); }
@keyframes progress-shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

@media (max-width: 600px) {
    .sku-header { padding: 1.25rem; }
    .sku-header h1 { font-size: 1.3rem; }
    .sku-field { width: 100%; }
    .sku-input { flex: 1; width: auto; }
    .item-row { align-items: flex-start; }
    .filter-bar { flex-direction: column; align-items: stretch; }
    .filter-bar .filter-group { width: 100%; }
    .filter-bar input[type="date"] { width: 100%; }
}
</style>

<div class="progress-bar" id="progressBar"></div>

<div class="sku-page">
<div style="max-width:860px;margin:0 auto;display:flex;flex-direction:column;gap:1rem;">

    <!-- Header -->
    <div class="sku-header">
        <h1>🏷️ إدخال رموز SKU</h1>
        <p>الطلبات التي تحتوي على منتجات بدون رمز SKU — أدخل الرموز الناقصة بسرعة</p>
        <div class="stats-row">
            <div class="stat-pill amber">
                <div class="dot"></div>
                <?php echo (int)$total_orders; ?> طلب ناقص
            </div>
            <div class="stat-pill blue">
                <div class="dot"></div>
                <?php echo (int)$total_missing_items; ?> منتج بدون SKU
            </div>
            <?php if ($date_from || $date_to): ?>
            <div class="stat-pill" style="background:rgba(168,85,247,.12);border-color:rgba(168,85,247,.3);color:#c084fc;">
                🗓 فلتر مفعّل
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter bar -->
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
            <div style="display:flex;gap:.5rem;align-items:flex-end;">
                <button type="submit" class="btn-filter primary">🔍 تطبيق</button>
                <?php if ($date_from || $date_to): ?>
                <a href="?" class="btn-filter ghost" style="display:inline-flex;align-items:center;text-decoration:none;">✕ إلغاء</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Orders list -->
    <div id="ordersList">
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <div class="icon">✅</div>
                <p>رائع! لا توجد منتجات ناقصة SKU<?php echo ($date_from || $date_to) ? ' في النطاق المحدد' : ''; ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($orders as $idx => $order): ?>
                <?php
                    $oid = (int)$order['id'];
                    $order_items_list = $items_by_order[$oid] ?? [];
                    if (empty($order_items_list)) continue;
                    $created = $order['created_at'] ? date('Y/m/d — H:i', strtotime($order['created_at'])) : '—';
                    $customer = htmlspecialchars($order['customer_name'] ?? '');
                ?>
                <details id="order-<?php echo $oid; ?>" class="order-card" style="animation-delay:<?php echo $idx * 0.04; ?>s" <?php echo $idx === 0 ? 'open' : ''; ?>>
                    <summary class="order-summary">
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
                                ⚠️ <span id="count-<?php echo $oid; ?>"><?php echo count($order_items_list); ?></span> ناقص
                            </div>
                            <div class="chevron">▾</div>
                        </div>
                    </summary>

                    <div class="items-area" id="items-<?php echo $oid; ?>">
                        <?php foreach ($order_items_list as $iidx => $item): ?>
                            <div id="row-<?php echo (int)$item['id']; ?>" class="item-row" style="animation-delay:<?php echo $iidx * 0.04; ?>s">
                                <div class="item-num"><?php echo $iidx + 1; ?></div>
                                <div class="item-info">
                                    <div class="item-name"><?php echo htmlspecialchars($item['product_name'] ?: 'بدون اسم منتج'); ?></div>
                                    <div class="item-details">
                                        <span>ID: <?php echo (int)$item['id']; ?></span>
                                        <span>الكمية: <?php echo (int)($item['quantity'] ?? 1); ?></span>
                                    </div>
                                </div>
                                <div class="sku-field">
                                    <input
                                        type="text"
                                        class="sku-input"
                                        data-item-id="<?php echo (int)$item['id']; ?>"
                                        data-order-id="<?php echo $oid; ?>"
                                        placeholder="أدخل SKU..."
                                        autocomplete="off"
                                        spellcheck="false"
                                        <?php echo $can_add_skus ? '' : 'readonly disabled'; ?>
                                    >
                                    <button
                                        type="button"
                                        class="save-btn"
                                        data-item-id="<?php echo (int)$item['id']; ?>"
                                        title="حفظ"
                                        <?php echo $can_add_skus ? '' : 'disabled'; ?>
                                    >
                                        <span class="spinner"></span>
                                        <span class="btn-label">حفظ ↵</span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <span>صفحة <strong><?php echo (int)$page; ?></strong> من <strong><?php echo (int)$total_pages; ?></strong></span>
        <div class="pag-btns">
            <?php
                $qs = http_build_query(array_filter(['date_from'=>$date_from,'date_to'=>$date_to,'order_id'=>$target_order_id]));
                $qs_sep = $qs ? '&' : '';
            ?>
            <?php if ($page > 1): ?>
                <a class="pag-btn" href="?<?php echo $qs.$qs_sep; ?>page=<?php echo $page-1; ?>">→ السابق</a>
            <?php endif; ?>
            <?php if ($page < $total_pages): ?>
                <a class="pag-btn" href="?<?php echo $qs.$qs_sep; ?>page=<?php echo $page+1; ?>">التالي ←</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>

<!-- Toast -->
<div id="alertBox"></div>

<script>
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

function focusNextInput(currentInput) {
    const inputs = [...document.querySelectorAll('.sku-input')];
    const i = inputs.indexOf(currentInput);
    if (i >= 0 && inputs[i + 1]) inputs[i + 1].focus();
}

function removeItemRow(itemId, orderId, inputRef) {
    const row = document.getElementById(`row-${itemId}`);
    if (row) {
        row.classList.add('saved-out');
        setTimeout(() => {
            row.remove();
            const wrapper  = document.getElementById(`items-${orderId}`);
            const countEl  = document.getElementById(`count-${orderId}`);
            const badgeEl  = document.getElementById(`badge-${orderId}`);
            const card     = document.getElementById(`order-${orderId}`);
            if (wrapper) {
                const left = wrapper.querySelectorAll('[id^="row-"]').length;
                if (countEl) countEl.textContent = left;
                if (left === 0 && card) {
                    card.style.transition = 'opacity .4s, transform .4s';
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(-10px)';
                    setTimeout(() => card.remove(), 420);
                }
            }
        }, 320);
    }
    if (inputRef) focusNextInput(inputRef);
}

async function saveSku(itemId) {
    <?php if (!$can_add_skus): ?>
    showAlert('ليس لديك صلاحية إضافة SKU', 'error');
    return;
    <?php endif; ?>
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
        const res  = await fetch('skus.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'فشل الحفظ');
        showAlert(data.message, 'success');
        if (data.remove_row) removeItemRow(itemId, input.dataset.orderId, input);
    } catch (e) {
        showAlert(e.message || 'فشل الحفظ، حاول مجدداً', 'error');
        btn.disabled = false;
        btn.classList.remove('loading');
        input.closest('.item-row').classList.remove('saving');
    } finally {
        progressBar.classList.remove('active');
    }
}

<?php if (!$can_add_skus): ?>
showAlert('لديك صلاحية عرض فقط. لا يمكنك تعديل أو حفظ SKU.', 'error');
<?php endif; ?>

// Events
document.querySelectorAll('.save-btn').forEach(btn =>
    btn.addEventListener('click', () => saveSku(btn.dataset.itemId))
);
document.querySelectorAll('.sku-input').forEach(input => {
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); saveSku(input.dataset.itemId); }
    });
    input.addEventListener('input', () => {
        input.classList.toggle('has-value', input.value.trim().length > 0);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
