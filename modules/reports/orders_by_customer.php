<?php
session_start();
require_once '../../config/database.php';
$page_title = 'الطلبات حسب العميل';

$stmt = $db->query("
    SELECT c.name, COUNT(co.id) as order_count, SUM(co.final_amount) as total_amount
    FROM customer_orders co
    JOIN customers c ON co.customer_id = c.id
    GROUP BY co.customer_id
    ORDER BY total_amount DESC
");
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
<div class="container mx-auto p-6" dir="rtl">
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-4">الطلبات حسب العميل</h1>
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 text-right">العميل</th>
                    <th class="p-3 text-right">عدد الطلبات</th>
                    <th class="p-3 text-right">إجمالي المبلغ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $customer): ?>
                <tr class="border-b">
                    <td class="p-3"><?php echo htmlspecialchars($customer['name']); ?></td>
                    <td class="p-3"><?php echo number_format($customer['order_count'], 0, '', ''); ?></td>
                    <td class="p-3"><?php echo number_format($customer['total_amount'], 0, '', ''); ?> ر.س</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
