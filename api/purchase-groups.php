<?php
require_once __DIR__ . '/api_helper.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام GET.', 405);
}
authenticateRequest($db);
try {
    $stmt = $db->query("SELECT id, group_name, group_number FROM purchase_groups ORDER BY created_at DESC LIMIT 500");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $groups = array_map(static function(array $r): array {
        $id = (int)($r['id'] ?? 0);
        $name = trim((string)($r['group_name'] ?? ''));
        $number = trim((string)($r['group_number'] ?? ''));
        $label = trim($number . ' - ' . $name);
        if ($label === '-') {
            $label = 'مجموعة #' . $id;
        }
        return ['id'=>$id,'group_name'=>$name,'group_number'=>$number,'label'=>$label];
    }, $rows);
    ok(['success'=>true,'groups'=>$groups]);
} catch (Throwable $e) {
    fail('تعذر تحميل مجموعات الشراء: ' . $e->getMessage(), 500);
}
