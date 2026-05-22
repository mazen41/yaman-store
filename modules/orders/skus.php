<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!canViewOrders($user_id)) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول لهذه الصفحة.';
    header('Location: ../../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_sku') {
    header('Content-Type: application/json; charset=utf-8');

    if (!canEditOrders($user_id)) {
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
            'message' => 'تم الحفظ بنجاح',
            'remove_row' => ($sku !== ''),
            'item_id' => $item_id,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'فشل الحفظ']);
    }
    exit();
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// FIX 1: Add the INNER JOIN to the count query so it matches the fetch query exactly.
$total_orders_stmt = $db->query("SELECT COUNT(DISTINCT o.id)
    FROM customer_orders o
    INNER JOIN order_items oi ON oi.order_id = o.id
    WHERE TRIM(COALESCE(oi.shein_sku, '')) = ''");
$total_orders = (int)$total_orders_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_orders / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

// FIX 2: Directly inject integer-casted values into LIMIT and OFFSET 
// to prevent the common PDO string-binding syntax error.
$orders_stmt = $db->prepare("SELECT o.id, o.order_number, o.created_at, COUNT(oi.id) AS missing_items_count
    FROM customer_orders o
    INNER JOIN order_items oi ON oi.order_id = o.id
    WHERE TRIM(COALESCE(oi.shein_sku, '')) = ''
    GROUP BY o.id, o.order_number, o.created_at
    ORDER BY o.id DESC
    LIMIT " . (int)$per_page . " OFFSET " . (int)$offset);

$orders_stmt->execute();
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
            oi.size,
            oi.color,
            oi.shein_sku
        FROM order_items oi
        WHERE oi.order_id IN ($placeholders)
          AND TRIM(COALESCE(oi.shein_sku, '')) = ''
        ORDER BY oi.order_id DESC, oi.id ASC");
    $items_stmt->execute($order_ids);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $oid = (int)$item['order_id'];
        if (!isset($items_by_order[$oid])) {
            $items_by_order[$oid] = [];
        }
        $items_by_order[$oid][] = $item;
        $total_missing_items++;
    }
}

include '../../includes/header.php';
?>
<div class="min-h-screen bg-slate-50 p-3 sm:p-6" dir="rtl">
    <div class="max-w-6xl mx-auto space-y-4">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 sm:p-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-800">Orders SKUs</h1>
                    <p class="text-sm text-slate-500 mt-1">أداة إدخال SKU اليدوي للعناصر الناقصة فقط</p>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs sm:text-sm">
                    <div class="bg-amber-50 text-amber-700 px-3 py-2 rounded-lg border border-amber-100">الطلبات: <?php echo (int)$total_orders; ?></div>
                    <div class="bg-blue-50 text-blue-700 px-3 py-2 rounded-lg border border-blue-100">العناصر الناقصة: <?php echo (int)$total_missing_items; ?></div>
                </div>
            </div>
        </div>

        <div id="alertBox" class="hidden rounded-lg px-3 py-2 text-sm"></div>

        <div id="ordersList" class="space-y-3">
            <?php if (empty($orders)): ?>
                <div class="bg-white border border-slate-200 rounded-xl p-8 text-center text-slate-500">لا توجد عناصر ناقصة SKU.</div>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <?php
                        $oid = (int)$order['id'];
                        $order_items = $items_by_order[$oid] ?? [];
                        if (empty($order_items)) {
                            continue;
                        }
                    ?>
                    <details id="order-<?php echo $oid; ?>" class="group bg-white border border-slate-200 rounded-xl overflow-hidden" open>
                        <summary class="list-none cursor-pointer px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-3">
                            <div>
                                <div class="font-semibold text-slate-800">Order #<?php echo htmlspecialchars($order['order_number'] ?: (string)$oid); ?></div>
                                <div class="text-xs text-slate-500 mt-1">Items missing SKU: <span id="count-<?php echo $oid; ?>"><?php echo count($order_items); ?></span></div>
                            </div>
                            <span class="text-slate-400 text-sm group-open:rotate-180 transition">⌄</span>
                        </summary>
                        <div class="p-3 sm:p-4 space-y-2" id="items-<?php echo $oid; ?>">
                            <?php foreach ($order_items as $idx => $item): ?>
                                <div id="row-<?php echo (int)$item['id']; ?>" class="bg-amber-50 border border-amber-200 rounded-lg p-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="font-medium text-slate-800 text-sm truncate"><?php echo htmlspecialchars($item['product_name'] ?: 'بدون اسم منتج'); ?></div>
                                        <div class="text-xs text-slate-600 mt-1">#<?php echo (int)$item['id']; ?> · Qty: <?php echo (int)($item['quantity'] ?? 0); ?><?php if (!empty($item['size'])): ?> · Size: <?php echo htmlspecialchars($item['size']); ?><?php endif; ?><?php if (!empty($item['color'])): ?> · Color: <?php echo htmlspecialchars($item['color']); ?><?php endif; ?></div>
                                    </div>
                                    <div class="flex items-center gap-2 w-full sm:w-auto">
                                        <input type="text" class="sku-input flex-1 sm:w-40 h-9 border border-slate-300 rounded-md px-2 text-sm dir-ltr focus:ring-2 focus:ring-blue-300 focus:border-blue-500" data-item-id="<?php echo (int)$item['id']; ?>" data-order-id="<?php echo $oid; ?>" data-index="<?php echo $idx; ?>" placeholder="Enter SKU">
                                        <button type="button" class="save-btn bg-blue-600 hover:bg-blue-700 text-white h-9 px-3 rounded-md text-xs sm:text-sm whitespace-nowrap" data-item-id="<?php echo (int)$item['id']; ?>">Save</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-4 flex items-center justify-between text-sm">
            <span>الصفحة <?php echo (int)$page; ?> / <?php echo (int)$total_pages; ?></span>
            <div class="flex gap-2">
                <?php if ($page > 1): ?><a class="px-3 py-1 border rounded hover:bg-slate-50" href="?page=<?php echo $page - 1; ?>">السابق</a><?php endif; ?>
                <?php if ($page < $total_pages): ?><a class="px-3 py-1 border rounded hover:bg-slate-50" href="?page=<?php echo $page + 1; ?>">التالي</a><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function showAlert(message, type = 'success') {
    const box = document.getElementById('alertBox');
    box.className = 'rounded-lg px-3 py-2 text-sm ' + (type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700');
    box.textContent = message;
    box.classList.remove('hidden');
    setTimeout(() => box.classList.add('hidden'), 2500);
}

function focusNextInput(currentInput) {
    const inputs = Array.from(document.querySelectorAll('.sku-input'));
    const currentIndex = inputs.indexOf(currentInput);
    if (currentIndex >= 0 && inputs[currentIndex + 1]) {
        inputs[currentIndex + 1].focus();
    }
}

function removeItemRow(itemId, orderId, inputRef = null) {
    const row = document.getElementById(`row-${itemId}`);
    if (row) row.remove();

    const countEl = document.getElementById(`count-${orderId}`);
    const itemsWrapper = document.getElementById(`items-${orderId}`);
    const orderCard = document.getElementById(`order-${orderId}`);

    if (itemsWrapper) {
        const leftRows = itemsWrapper.querySelectorAll('[id^="row-"]').length;
        if (countEl) countEl.textContent = leftRows;
        if (leftRows === 0 && orderCard) {
            orderCard.remove();
        }
    }

    if (inputRef) {
        focusNextInput(inputRef);
    }
}

async function saveSku(itemId) {
    const input = document.querySelector(`.sku-input[data-item-id="${itemId}"]`);
    const btn = document.querySelector(`.save-btn[data-item-id="${itemId}"]`);
    if (!input || !btn) return;

    const skuValue = (input.value || '').trim();
    if (!skuValue) {
        showAlert('الرجاء إدخال SKU أولاً', 'error');
        input.focus();
        return;
    }

    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = '...';

    const body = new URLSearchParams();
    body.set('action', 'update_sku');
    body.set('item_id', itemId);
    body.set('sku', skuValue);

    try {
        const res = await fetch('skus.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'فشل الحفظ');

        showAlert(data.message, 'success');
        if (data.remove_row) {
            removeItemRow(itemId, input.dataset.orderId, input);
        }
    } catch (e) {
        showAlert(e.message || 'فشل الحفظ', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
}

document.querySelectorAll('.save-btn').forEach(btn => {
    btn.addEventListener('click', () => saveSku(btn.dataset.itemId));
});

document.querySelectorAll('.sku-input').forEach(input => {
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveSku(input.dataset.itemId);
        }
    });
});
</script>
<?php include '../../includes/footer.php'; ?>
