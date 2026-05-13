<?php
session_start();
require_once '../../config/database.php';
$page_title = 'السلال حسب الحالة';

$stmt = $db->query("
    SELECT status, COUNT(*) as count, SUM(final_amount) as total
    FROM purchase_baskets
    GROUP BY status
    ORDER BY count DESC
");
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
<div class="container mx-auto p-6" dir="rtl">
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-4">السلال حسب الحالة</h1>
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 text-right">الحالة</th>
                    <th class="p-3 text-right">عدد السلال</th>
                    <th class="p-3 text-right">إجمالي المبلغ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statuses as $status): ?>
                <tr class="border-b">
                    <td class="p-3"><?php echo htmlspecialchars($status['status']); ?></td>
                    <td class="p-3"><?php echo number_format($status['count'], 0, ',', '.'); ?></td>
                    <td class="p-3"><?php echo number_format($status['total'], 0, ',', '.'); ?> ر.ي</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>