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

        echo json_encode(['success' => true, 'message' => 'تم الحفظ بنجاح', 'remove_row' => ($sku !== '')]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'فشل الحفظ']);
    }
    exit();
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$count_stmt = $db->query("SELECT COUNT(*) FROM order_items oi WHERE TRIM(COALESCE(oi.shein_sku, '')) = ''");
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$stmt = $db->prepare("SELECT oi.id AS item_id, o.order_number, oi.shein_sku
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE TRIM(COALESCE(oi.shein_sku, '')) = ''
    ORDER BY oi.id DESC
    LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
<div class="min-h-screen bg-gray-50 p-3 sm:p-5" dir="rtl">
    <div class="max-w-6xl mx-auto bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="p-4 sm:p-5 border-b border-gray-100 flex items-center justify-between gap-2">
            <h1 class="text-lg sm:text-xl font-bold text-gray-800">Orders SKUs</h1>
            <span class="text-xs sm:text-sm text-gray-500">العناصر الناقصة: <?php echo (int)$total; ?></span>
        </div>

        <div id="alertBox" class="hidden mx-4 mt-4 rounded-lg px-3 py-2 text-sm"></div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="px-3 py-2 text-right">Order #</th>
                        <th class="px-3 py-2 text-right">Row Ref</th>
                        <th class="px-3 py-2 text-right">SKU</th>
                        <th class="px-3 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="skuTableBody">
                <?php if (empty($rows)): ?>
                    <tr><td colspan="4" class="text-center px-3 py-8 text-gray-500">لا توجد عناصر ناقصة SKU.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <tr id="row-<?php echo (int)$row['item_id']; ?>" class="border-b border-gray-100">
                        <td class="px-3 py-2 font-medium"><?php echo htmlspecialchars($row['order_number'] ?: '-'); ?></td>
                        <td class="px-3 py-2">#<?php echo (int)$row['item_id']; ?></td>
                        <td class="px-3 py-2">
                            <input type="text" class="sku-input w-full border rounded-lg px-2 py-2 dir-ltr" data-item-id="<?php echo (int)$row['item_id']; ?>" value="<?php echo htmlspecialchars($row['shein_sku'] ?? ''); ?>" placeholder="SKU">
                        </td>
                        <td class="px-3 py-2">
                            <button type="button" class="save-btn bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-xs sm:text-sm" data-item-id="<?php echo (int)$row['item_id']; ?>">حفظ</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-gray-100 flex items-center justify-between text-sm">
            <span>الصفحة <?php echo (int)$page; ?> / <?php echo (int)$total_pages; ?></span>
            <div class="flex gap-2">
                <?php if ($page > 1): ?><a class="px-3 py-1 border rounded" href="?page=<?php echo $page - 1; ?>">السابق</a><?php endif; ?>
                <?php if ($page < $total_pages): ?><a class="px-3 py-1 border rounded" href="?page=<?php echo $page + 1; ?>">التالي</a><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function showAlert(message, type = 'success') {
    const box = document.getElementById('alertBox');
    box.className = 'mx-4 mt-4 rounded-lg px-3 py-2 text-sm ' + (type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700');
    box.textContent = message;
    box.classList.remove('hidden');
    setTimeout(() => box.classList.add('hidden'), 2500);
}

async function saveSku(itemId) {
    const input = document.querySelector(`.sku-input[data-item-id="${itemId}"]`);
    const btn = document.querySelector(`.save-btn[data-item-id="${itemId}"]`);
    if (!input || !btn) return;

    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = '...';

    const body = new URLSearchParams();
    body.set('action', 'update_sku');
    body.set('item_id', itemId);
    body.set('sku', input.value || '');

    try {
        const res = await fetch('skus.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'فشل');

        showAlert(data.message, 'success');
        if (data.remove_row) {
            const row = document.getElementById(`row-${itemId}`);
            if (row) row.remove();
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
