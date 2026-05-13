<?php
session_start();
require_once '../../config/database.php';
$page_title = 'السلال حسب المجموعة';

$stmt = $db->query("
    SELECT pg.group_name, COUNT(pb.id) as basket_count, SUM(pb.final_amount) as total_amount
    FROM purchase_baskets pb
    LEFT JOIN purchase_groups pg ON pb.purchase_group_id = pg.id
    GROUP BY pb.purchase_group_id
    ORDER BY total_amount DESC
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
<div class="container mx-auto p-6" dir="rtl">
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-4">السلال حسب المجموعة</h1>
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 text-right">المجموعة</th>
                    <th class="p-3 text-right">عدد السلال</th>
                    <th class="p-3 text-right">إجمالي المبلغ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                <tr class="border-b">
                    <td class="p-3"><?php echo htmlspecialchars($group['group_name'] ?: 'بدون مجموعة'); ?></td>
                    <td class="p-3"><?php echo number_format($group['basket_count'], 0, ',', '.'); ?></td>
                    <td class="p-3"><?php echo number_format($group['total_amount'], 0, ',', '.'); ?> ر.ي</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>