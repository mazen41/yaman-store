<?php
/**
 * patch_view.php — ONE-TIME migration script
 * Run ONCE: http://localhost/yaman/modules/sorting/patch_view.php
 * Then DELETE this file immediately after.
 *
 * What it does:
 *   1. Adds sorting status check query to orders/view.php
 *   2. Adds "fully sorted" banner HTML to the view page
 *   3. Adds "حالة الفرز" column to the items table
 */

session_start();
if (!isset($_SESSION['user_id'])) { die('يجب تسجيل الدخول أولاً'); }

$targetFile = __DIR__ . '/../orders/view.php';

if (!file_exists($targetFile)) {
    die("❌ الملف غير موجود: $targetFile");
}

$content = file_get_contents($targetFile);
$changes = 0;

// ── PATCH 1: Add sorting check query ─────────────────────────────────────────
$needle1 = '    }

    // 2. Fetch Order Items';
$replace1 = '    }

    // 2b. Sorting status check
    try {
        $sorting_check = $db->prepare("
            SELECT COUNT(*) AS total_sku_items,
                   SUM(CASE WHEN status = \'scanned\' THEN 1 ELSE 0 END) AS scanned_items
            FROM order_items
            WHERE order_id = ? AND shein_sku IS NOT NULL AND shein_sku <> \'\'
        ");
        $sorting_check->execute([$order_id]);
        $sort_counts  = $sorting_check->fetch(PDO::FETCH_ASSOC);
        $sort_total   = (int)($sort_counts[\'total_sku_items\'] ?? 0);
        $sort_scanned = (int)($sort_counts[\'scanned_items\'] ?? 0);
        $is_fully_sorted = ($sort_total > 0 && $sort_scanned >= $sort_total);
    } catch (PDOException $e) {
        $sort_total = $sort_scanned = 0;
        $is_fully_sorted = false;
    }

    // 2. Fetch Order Items';

if (strpos($content, '// 2b. Sorting status check') === false) {
    $content = str_replace($needle1, $replace1, $content);
    $changes++;
    echo "✅ PATCH 1 applied: sorting check query added<br>";
} else {
    echo "⏭️ PATCH 1 already applied<br>";
}

// ── PATCH 2: Add banner HTML ──────────────────────────────────────────────────
$needle2 = '                <!-- Order Info -->';
$replace2 = '                <!-- Sorting Status Banner -->
                <?php if ($is_fully_sorted): ?>
                <div style="background:linear-gradient(135deg,rgba(34,197,94,.15),rgba(16,185,129,.08));border:1px solid rgba(34,197,94,.4);border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:14px;margin-bottom:8px;flex-wrap:wrap;" dir="rtl">
                    <span style="font-size:2rem;">&#x2705;</span>
                    <div>
                        <strong style="color:#4ade80;font-size:1.05rem;display:block;">هذا الطلب تم الفرز بالفعل</strong>
                        <span style="color:#86efac;font-size:.83rem;">تم فرز <?php echo $sort_scanned; ?> من أصل <?php echo $sort_total; ?> منتج بـ SKU</span>
                    </div>
                    <a href="/yaman/modules/sorting/index.php" style="margin-right:auto;background:rgba(34,197,94,.2);border:1px solid rgba(34,197,94,.4);color:#4ade80;padding:7px 14px;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                        <i class="fas fa-qrcode"></i> صفحة الفرز
                    </a>
                </div>
                <?php elseif (!empty($sort_total) && $sort_total > 0): ?>
                <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:12px;padding:12px 18px;display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                    <span style="font-size:1.5rem;">&#x23F3;</span>
                    <span style="color:#fcd34d;font-size:.85rem;font-weight:600;">الفرز جارٍ &mdash; <?php echo $sort_scanned; ?> / <?php echo $sort_total; ?> منتج</span>
                </div>
                <?php endif; ?>

                <!-- Order Info -->';

if (strpos($content, '<!-- Sorting Status Banner -->') === false) {
    $content = str_replace($needle2, $replace2, $content);
    $changes++;
    echo "✅ PATCH 2 applied: banner HTML injected<br>";
} else {
    echo "⏭️ PATCH 2 already applied<br>";
}

// ── PATCH 3: Add sorting status column header ─────────────────────────────────
$needle3 = '                                    <th class="text-center">الإجمالي</th>
                                </tr>';
$replace3 = '                                    <th class="text-center">الإجمالي</th>
                                    <th class="text-center">حالة الفرز</th>
                                </tr>';

if (strpos($content, 'حالة الفرز') === false) {
    $content = str_replace($needle3, $replace3, $content);
    $changes++;
    echo "✅ PATCH 3 applied: sorting column header added<br>";
} else {
    echo "⏭️ PATCH 3 already applied<br>";
}

// ── PATCH 4: Add sorting status cell to each row ──────────────────────────────
$needle4 = '                                        <td class="text-center dir-ltr font-bold text-indigo-600"><?php echo number_format($item[\'total_price\'], 0, \',\', \'.\'); ?></td>
                                    </tr>';
$replace4 = '                                        <td class="text-center dir-ltr font-bold text-indigo-600"><?php echo number_format($item[\'total_price\'], 0, \',\', \'.\'); ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($item[\'shein_sku\'])): ?>
                                                <?php if (($item[\'status\'] ?? \'\') === \'scanned\'): ?>
                                                    <span style="background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.3);border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:700;">&#x2705; مفروز</span>
                                                <?php else: ?>
                                                    <span style="background:rgba(245,158,11,.1);color:#f59e0b;border:1px solid rgba(245,158,11,.25);border-radius:99px;padding:3px 10px;font-size:.72rem;font-weight:700;">&#x23F3; لم يُفرز</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#9ca3af;font-size:.72rem;">&#x2014;</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>';

if (strpos($content, "&#x2705; مفروز") === false) {
    $content = str_replace($needle4, $replace4, $content);
    $changes++;
    echo "✅ PATCH 4 applied: sorting status cell added to rows<br>";
} else {
    echo "⏭️ PATCH 4 already applied<br>";
}

// ── Write back ────────────────────────────────────────────────────────────────
if ($changes > 0) {
    file_put_contents($targetFile, $content);
    echo "<br><strong style='color:green;'>✅ تم تطبيق $changes تعديل وحفظ الملف بنجاح.</strong>";
    echo "<br><a href='../orders/view.php?id=1' style='color:#3b82f6;'>→ اختبر صفحة العرض</a>";
} else {
    echo "<br><strong style='color:#888;'>ℹ️ لا يوجد تعديلات جديدة — الملف محدّث بالفعل.</strong>";
}

echo "<br><br><strong style='color:#ef4444;'>🗑️ احذف هذا الملف فوراً بعد التشغيل!</strong>";
echo "<br><code>C:\\xampp\\htdocs\\yaman\\modules\\sorting\\patch_view.php</code>";
