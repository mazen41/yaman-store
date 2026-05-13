<?php
session_start();
require_once '../../config/database.php';
$page_title = 'تقرير السلال اليومية';

$stmt = $db->query("
    SELECT * FROM purchase_baskets
    WHERE DATE(created_at) = CURDATE()
    ORDER BY created_at DESC
");
$baskets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($baskets, 'final_amount'));

include '../../includes/header.php';
?>
<div class="container mx-auto p-6" dir="rtl">
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-4">تقرير السلال اليومية</h1>
        <p class="text-gray-600 mb-4">التاريخ: <?php echo date('Y-m-d'); ?></p>
        <p class="text-lg font-semibold mb-4">إجمالي: <?php echo number_format($total, 0, ',', '.'); ?> ر.ي</p>
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 text-right">كود السلة</th>
                    <th class="p-3 text-right">اسم السلة</th>
                    <th class="p-3 text-right">المبلغ</th>
                    <th class="p-3 text-right">الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($baskets as $basket): ?>
                <tr class="border-b">
                    <td class="p-3"><?php echo htmlspecialchars($basket['basket_code']); ?></td>
                    <td class="p-3"><?php echo htmlspecialchars($basket['basket_name']); ?></td>
                    <td class="p-3"><?php echo number_format($basket['final_amount'], 0, ',', '.'); ?> ر.ي</td>
                    <td class="p-3"><?php echo htmlspecialchars($basket['status']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>