<?php
/**
 * patch_index.php — ONE-TIME migration for orders/index.php
 * Run ONCE: http://localhost/yaman/modules/sorting/patch_index.php
 * DELETE after running.
 *
 * Adds a ✅ sorting complete indicator to the orders table.
 */

session_start();
if (!isset($_SESSION['user_id'])) { die('يجب تسجيل الدخول أولاً'); }

$targetFile = __DIR__ . '/../orders/index.php';
if (!file_exists($targetFile)) { die("❌ الملف غير موجود"); }

$content = file_get_contents($targetFile);
$changes = 0;

// ── PATCH 1: Add sorting counts to the SELECT query ──────────────────────────
// We inject a subquery into the existing big SELECT
$needle1 = "                        (SELECT GROUP_CONCAT(CONCAT(ci.id, ':', ci.invoice_number) SEPARATOR ';')
                         FROM customer_invoices ci WHERE ci.order_id = o.id) as invoice_data,";

$replace1 = "                        (SELECT GROUP_CONCAT(CONCAT(ci.id, ':', ci.invoice_number) SEPARATOR ';')
                         FROM customer_invoices ci WHERE ci.order_id = o.id) as invoice_data,
                        (SELECT COUNT(*) FROM order_items oi2 WHERE oi2.order_id = o.id AND oi2.shein_sku IS NOT NULL AND oi2.shein_sku <> '') as sort_total_items,
                        (SELECT SUM(CASE WHEN oi2.status = 'scanned' THEN 1 ELSE 0 END) FROM order_items oi2 WHERE oi2.order_id = o.id AND oi2.shein_sku IS NOT NULL AND oi2.shein_sku <> '') as sort_scanned_items,";

if (strpos($content, 'sort_total_items') === false) {
    $content = str_replace($needle1, $replace1, $content);
    $changes++;
    echo "✅ PATCH 1 applied: sorting subqueries added to SELECT<br>";
} else {
    echo "⏭️ PATCH 1 already applied<br>";
}

// ── PATCH 2: Add sorting icon to the order number cell in tbody ───────────────
// Inject after the keyboard/globe icon lines
$needle2 = '                                <?php if (!$is_manual_order): // If NOT manual, it\'s from portal ?>
                                        <i class="fas fa-globe" style="color: #3b82f6; margin-right: 5px; font-size: 13px;" title="تم إنشاؤه من بوابة العملاء (رقم الموافقة: <?php echo $order[\'source_approval_id\']; ?>)"></i>
                                    <?php else: // If manual ?>
                                        <i class="fas fa-keyboard" style="color: #6c757d; margin-right: 5px; font-size: 13px;" title="طلب يدوي"></i>
                                    <?php endif; ?>';

$replace2 = '                                <?php if (!$is_manual_order): // If NOT manual, it\'s from portal ?>
                                        <i class="fas fa-globe" style="color: #3b82f6; margin-right: 5px; font-size: 13px;" title="تم إنشاؤه من بوابة العملاء (رقم الموافقة: <?php echo $order[\'source_approval_id\']; ?>)"></i>
                                    <?php else: // If manual ?>
                                        <i class="fas fa-keyboard" style="color: #6c757d; margin-right: 5px; font-size: 13px;" title="طلب يدوي"></i>
                                    <?php endif; ?>
                                    <?php
                                        $st = (int)($order[\'sort_total_items\'] ?? 0);
                                        $ss = (int)($order[\'sort_scanned_items\'] ?? 0);
                                        if ($st > 0 && $ss >= $st):
                                    ?>
                                        <i class="fas fa-check-double" style="color:#22c55e;margin-right:4px;font-size:13px;" title="تم الفرز بالكامل (<?php echo $ss; ?>/<?php echo $st; ?>)"></i>
                                    <?php elseif ($st > 0 && $ss > 0): ?>
                                        <i class="fas fa-sort-amount-down" style="color:#f59e0b;margin-right:4px;font-size:13px;" title="فرز جزئي (<?php echo $ss; ?>/<?php echo $st; ?>)"></i>
                                    <?php endif; ?>';

if (strpos($content, 'check-double') === false) {
    $content = str_replace($needle2, $replace2, $content);
    $changes++;
    echo "✅ PATCH 2 applied: sorting icon added to order rows<br>";
} else {
    echo "⏭️ PATCH 2 already applied<br>";
}

if ($changes > 0) {
    file_put_contents($targetFile, $content);
    echo "<br><strong style='color:green;'>✅ تم تطبيق $changes تعديل.</strong>";
    echo "<br><a href='../orders/index.php' style='color:#3b82f6;'>→ عرض قائمة الطلبات</a>";
} else {
    echo "<br><strong>ℹ️ لا جديد — محدّث بالفعل.</strong>";
}

echo "<br><br><strong style='color:#ef4444;'>🗑️ احذف هذا الملف فوراً!</strong>";
