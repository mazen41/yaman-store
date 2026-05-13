<?php
session_start();
require_once '../../config/database.php';
$page_title = 'المجموعات النشطة';

$stmt = $db->query("
    SELECT pg.*, COUNT(pb.id) as basket_count, SUM(pb.final_amount) as total_amount
    FROM purchase_groups pg
    LEFT JOIN purchase_baskets pb ON pg.id = pb.purchase_group_id
    WHERE pg.status = 'active'
    GROUP BY pg.id
    ORDER BY pg.created_at DESC
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
<div class="container mx-auto p-6" dir="rtl">
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-4">المجموعات النشطة</h1>
        <p class="text-gray-600 mb-4">عدد المجموعات: <?php echo count($groups); ?></p>
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 text-right">اسم المجموعة</th>
                    <th class="p-3 text-right">عدد السلال</th>
                    <th class="p-3 text-right">إجمالي المبلغ</th>
                    <th class="p-3 text-right">تاريخ البدء</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                <tr class="border-b">
                    <td class="p-3"><?php echo htmlspecialchars($group['group_name']); ?></td>
                    <td class="p-3"><?php echo number_format($group['basket_count'], 0, '', ''); ?></td>
                    <td class="p-3"><?php echo number_format($group['total_amount'], 0, '', ''); ?> ر.س</td>
                    <td class="p-3"><?php echo $group['start_date'] ? date('Y-m-d', strtotime($group['start_date'])) : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
