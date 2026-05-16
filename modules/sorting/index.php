<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/shein_helpers.php';

$user_id = $_SESSION['user_id'] ?? 0;
if (!hasPermission($user_id, 'orders', 'view') && !hasPermission($user_id, 'orders', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لإدارة الفرز';
    header('Location: ../../index.php');
    exit();
}

sheinEnsureSchema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $scanInput = trim($_POST['scan_input'] ?? '');
        $resolved = sheinResolveInputToSku($scanInput);
        $sku = sheinNormalizeSku($resolved['shein_sku'] ?? '');

        if ($sku === '') {
            throw new InvalidArgumentException('تعذر استخراج SKU من المدخل');
        }

        $productStmt = $db->prepare("SELECT * FROM shein_products WHERE shein_sku = ? LIMIT 1");
        $productStmt->execute([$sku]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product && !empty($resolved['link'])) {
            $productId = sheinFindOrCreateProduct($db, $resolved);
            $productStmt = $db->prepare("SELECT * FROM shein_products WHERE id = ? LIMIT 1");
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$product) {
            throw new RuntimeException('لم يتم العثور على منتج SHEIN بهذا SKU في قاعدة البيانات');
        }

        $itemStmt = $db->prepare("SELECT oi.*, co.order_number, co.customer_id, co.final_amount, c.name AS customer_name
            FROM order_items oi
            JOIN customer_orders co ON co.id = oi.order_id
            LEFT JOIN customers c ON c.id = co.customer_id
            WHERE oi.shein_sku = ?
            ORDER BY CASE WHEN oi.status = 'pending' THEN 0 ELSE 1 END, oi.id ASC
            LIMIT 1");
        $itemStmt->execute([$sku]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new RuntimeException('تم العثور على المنتج، لكن لا يوجد عنصر طلب مرتبط بهذا SKU');
        }

        $alreadyScanned = ($item['status'] ?? 'pending') === 'scanned';
        if (!$alreadyScanned) {
            $updateStmt = $db->prepare("UPDATE order_items SET status = 'scanned' WHERE id = ?");
            $updateStmt->execute([$item['id']]);
            $item['status'] = 'scanned';
        }

        $countStmt = $db->prepare("SELECT
                COUNT(*) AS total_items,
                SUM(CASE WHEN status = 'scanned' THEN 1 ELSE 0 END) AS scanned_items
            FROM order_items
            WHERE order_id = ? AND shein_sku IS NOT NULL AND shein_sku <> ''");
        $countStmt->execute([$item['order_id']]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_items' => 0, 'scanned_items' => 0];

        echo json_encode([
            'success' => true,
            'already_scanned' => $alreadyScanned,
            'message' => $alreadyScanned ? 'تنبيه: تم فرز هذا المنتج مسبقاً' : 'تم العثور على المنتج وتحديث حالته إلى مفروز',
            'product' => $product,
            'item' => $item,
            'counts' => [
                'total_items' => (int) ($counts['total_items'] ?? 0),
                'scanned_items' => (int) ($counts['scanned_items'] ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

$page_title = 'إدارة الفرز';
include '../../includes/header.php';
?>

<div class="container mx-auto px-4 py-6" dir="rtl">
    <div class="bg-gradient-to-r from-purple-700 to-indigo-700 rounded-2xl p-6 mb-6 text-white shadow-lg">
        <h1 class="text-3xl font-bold flex items-center gap-3">
            <i class="fas fa-qrcode"></i>
            إدارة الفرز
        </h1>
        <p class="mt-2 text-purple-100">امسح QR/الرابط أو أدخل SKU يدوياً لربط المنتج بالطلب وتحديث حالته.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 space-y-4">
            <div class="bg-white rounded-2xl shadow p-5 border border-gray-100">
                <h2 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                    <i class="fas fa-camera text-purple-600"></i>
                    الماسح بالكاميرا
                </h2>
                <video id="scannerVideo" class="w-full rounded-xl bg-gray-900 min-h-52" playsinline muted></video>
                <div class="flex gap-2 mt-3">
                    <button type="button" id="startScanner" class="flex-1 bg-green-600 hover:bg-green-700 text-white rounded-lg px-4 py-2 font-semibold">تشغيل</button>
                    <button type="button" id="stopScanner" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white rounded-lg px-4 py-2 font-semibold">إيقاف</button>
                </div>
                <p class="text-xs text-gray-500 mt-2">امسح أو أدخل SKU المنتج ليجلب النظام بيانات SHEIN ويحدث حالة الفرز.</p>
            </div>

            <form id="manualScanForm" class="bg-white rounded-2xl shadow p-5 border border-gray-100">
                <label for="scanInput" class="block font-bold text-gray-800 mb-2">إدخال SKU يدوي</label>
                <input id="scanInput" name="scan_input" type="text" class="form-input w-full dir-ltr" placeholder="SKU SHEIN">
                <button type="submit" class="w-full mt-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg px-4 py-2 font-semibold">بحث وتحديث الفرز</button>
            </form>
        </div>

        <div class="lg:col-span-2">
            <div id="scanMessage" class="hidden rounded-xl p-4 mb-4 font-semibold"></div>

            <div id="resultCard" class="hidden bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                <div class="p-5 border-b bg-gray-50 flex items-center justify-between">
                    <h2 class="font-bold text-xl text-gray-800">نتيجة الفرز</h2>
                    <span id="itemStatusBadge" class="px-3 py-1 rounded-full text-sm font-bold"></span>
                </div>
                <div class="p-5 grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="md:col-span-1">
                        <img id="productImage" src="" alt="صورة المنتج" class="w-full h-64 object-cover rounded-xl border bg-gray-100">
                    </div>
                    <div class="md:col-span-2 space-y-3">
                        <h3 id="productName" class="text-2xl font-bold text-gray-900"></h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                            <div class="bg-gray-50 rounded-lg p-3"><span class="text-gray-500 block">SKU</span><strong id="productSku" class="dir-ltr block"></strong></div>
                            <div class="bg-gray-50 rounded-lg p-3"><span class="text-gray-500 block">رقم الطلب</span><strong id="orderId"></strong></div>
                            <div class="bg-gray-50 rounded-lg p-3"><span class="text-gray-500 block">اسم العميل</span><strong id="customerName"></strong></div>
                            <div class="bg-gray-50 rounded-lg p-3"><span class="text-gray-500 block">عدد المفروز</span><strong id="scanCount"></strong></div>
                        </div>
                        <a id="productLink" href="#" target="_blank" class="inline-flex items-center gap-2 text-purple-700 hover:text-purple-900 font-semibold">
                            <i class="fas fa-external-link-alt"></i>
                            فتح رابط المنتج
                        </a>
                    </div>
                </div>
            </div>

            <div id="emptyState" class="bg-white rounded-2xl shadow p-10 text-center border border-dashed border-gray-300">
                <i class="fas fa-barcode text-6xl text-gray-300 mb-4"></i>
                <h2 class="text-xl font-bold text-gray-600">بانتظار المسح</h2>
                <p class="text-gray-500 mt-2">ستظهر تفاصيل المنتج والطلب هنا بعد إدخال SKU أو رابط صالح.</p>
            </div>
        </div>
    </div>
</div>

<script>
let scannerStream = null;
let scannerTimer = null;
let lastScanValue = '';
let lastScanAt = 0;

function playTone(type) {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = type === 'success' ? 880 : 220;
        gain.gain.value = 0.08;
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.start();
        setTimeout(() => { oscillator.stop(); ctx.close(); }, 160);
    } catch (e) {}
}

function showMessage(message, type) {
    const el = document.getElementById('scanMessage');
    el.textContent = message;
    el.className = `rounded-xl p-4 mb-4 font-semibold ${type === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : type === 'warning' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' : 'bg-red-100 text-red-800 border border-red-200'}`;
    el.classList.remove('hidden');
}

async function submitScan(value) {
    value = (value || '').trim();
    if (!value) {
        showMessage('يرجى إدخال SKU أو رابط صالح', 'error');
        return;
    }

    const now = Date.now();
    if (value === lastScanValue && now - lastScanAt < 2000) return;
    lastScanValue = value;
    lastScanAt = now;

    try {
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=scan&scan_input=${encodeURIComponent(value)}`
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'لم يتم العثور على المنتج');

        renderResult(data);
        showMessage(data.message, data.already_scanned ? 'warning' : 'success');
        playTone(data.already_scanned ? 'warning' : 'success');
    } catch (error) {
        showMessage(error.message, 'error');
        playTone('error');
    }
}

function renderResult(data) {
    document.getElementById('emptyState').classList.add('hidden');
    document.getElementById('resultCard').classList.remove('hidden');

    const product = data.product || {};
    const item = data.item || {};
    const counts = data.counts || {};
    const image = product.image || 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#9ca3af" font-size="22">No Image</text></svg>');

    document.getElementById('productImage').src = image;
    document.getElementById('productName').textContent = product.name || item.product_name || 'منتج SHEIN';
    document.getElementById('productSku').textContent = product.shein_sku || item.shein_sku || '';
    document.getElementById('orderId').textContent = item.order_number ? `${item.order_number} (#${item.order_id})` : `#${item.order_id}`;
    document.getElementById('customerName').textContent = item.customer_name || 'غير محدد';
    document.getElementById('scanCount').textContent = `${counts.scanned_items || 0} / ${counts.total_items || 0}`;

    const link = document.getElementById('productLink');
    if (product.link) {
        link.href = product.link;
        link.classList.remove('hidden');
    } else {
        link.classList.add('hidden');
    }

    const badge = document.getElementById('itemStatusBadge');
    badge.textContent = data.already_scanned ? 'مفروز مسبقاً' : 'تم الفرز';
    badge.className = data.already_scanned
        ? 'px-3 py-1 rounded-full text-sm font-bold bg-yellow-100 text-yellow-800'
        : 'px-3 py-1 rounded-full text-sm font-bold bg-green-100 text-green-800';
}

document.getElementById('manualScanForm').addEventListener('submit', function(e) {
    e.preventDefault();
    submitScan(document.getElementById('scanInput').value);
});

document.getElementById('startScanner').addEventListener('click', async function() {
    if (!('BarcodeDetector' in window)) {
        showMessage('المتصفح لا يدعم ماسح QR المباشر. استخدم الإدخال اليدوي كبديل.', 'error');
        return;
    }

    try {
        const video = document.getElementById('scannerVideo');
        scannerStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        video.srcObject = scannerStream;
        await video.play();

        const detector = new BarcodeDetector({ formats: ['qr_code', 'code_128', 'ean_13', 'ean_8'] });
        scannerTimer = setInterval(async () => {
            try {
                const codes = await detector.detect(video);
                if (codes.length > 0) {
                    submitScan(codes[0].rawValue || '');
                }
            } catch (e) {}
        }, 700);
        showMessage('تم تشغيل الكاميرا. وجّهها نحو QR أو الرمز.', 'success');
    } catch (error) {
        showMessage('تعذر تشغيل الكاميرا: ' + error.message, 'error');
    }
});

document.getElementById('stopScanner').addEventListener('click', function() {
    if (scannerTimer) clearInterval(scannerTimer);
    scannerTimer = null;
    if (scannerStream) {
        scannerStream.getTracks().forEach(track => track.stop());
    }
    scannerStream = null;
    document.getElementById('scannerVideo').srcObject = null;
});
</script>

<?php include '../../includes/footer.php'; ?>
