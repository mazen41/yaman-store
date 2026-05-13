<?php
session_start();
require_once '../../config/database.php';
$page_title = 'تقرير الطلبات اليومية';

$stmt = $db->query("
    SELECT co.*, c.name as customer_name 
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE DATE(co.created_at) = CURDATE()
    ORDER BY co.created_at DESC
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($orders, 'final_amount'));

include '../../includes/header.php';
?>
<div class="container mx-auto p-6" dir="rtl">
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-4">تقرير الطلبات اليومية</h1>
        <p class="text-gray-600 mb-4">التاريخ: <?php echo date('Y-m-d'); ?></p>
        <p class="text-lg font-semibold mb-4">إجمالي: <?php echo number_format($total, 0, '', ''); ?> ر.س</p>
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 text-right">رقم الطلب</th>
                    <th class="p-3 text-right">العميل</th>
                    <th class="p-3 text-right">المبلغ</th>
                    <th class="p-3 text-right">الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                <tr class="border-b">
                    <td class="p-3"><?php echo htmlspecialchars($order['order_number']); ?></td>
                    <td class="p-3"><?php echo htmlspecialchars($order['customer_name']); ?></td>
                    <td class="p-3"><?php echo number_format($order['final_amount'], 0, '', ''); ?> ر.س</td>
                    <td class="p-3"><?php echo htmlspecialchars($order['status']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
