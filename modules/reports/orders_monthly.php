<?php
session_start();
require_once '../../config/database.php';
$page_title = 'تقرير الطلبات الشهرية';

$stmt = $db->query("
    SELECT co.*, c.name as customer_name 
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    WHERE MONTH(co.created_at) = MONTH(CURDATE()) 
    AND YEAR(co.created_at) = YEAR(CURDATE())
    ORDER BY co.created_at DESC
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($orders, 'final_amount'));

include '../../includes/header.php';
?>
<div class="container mx-auto p-6" dir="rtl">
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-4">تقرير الطلبات الشهرية</h1>
        <p class="text-gray-600 mb-4">الشهر: <?php echo date('Y-m'); ?></p>
        <p class="text-lg font-semibold mb-4">إجمالي: <?php echo number_format($total, 0, '', ''); ?> ر.س</p>
        <p class="text-gray-600 mb-4">عدد الطلبات: <?php echo count($orders); ?></p>
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 text-right">رقم الطلب</th>
                    <th class="p-3 text-right">العميل</th>
                    <th class="p-3 text-right">المبلغ</th>
                    <th class="p-3 text-right">التاريخ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                <tr class="border-b">
                    <td class="p-3"><?php echo htmlspecialchars($order['order_number']); ?></td>
                    <td class="p-3"><?php echo htmlspecialchars($order['customer_name']); ?></td>
                    <td class="p-3"><?php echo number_format($order['final_amount'], 0, '', ''); ?> ر.س</td>
                    <td class="p-3"><?php echo date('Y-m-d', strtotime($order['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
