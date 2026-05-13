<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('<div class="p-10 text-center text-red-600 text-xl font-bold">وصول غير صالح.</div>');
}

// 1. Validate Customer
$stmt = $db->prepare("SELECT id, name, currency FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('<div class="p-10 text-center text-red-600 text-xl font-bold">الرابط غير صالح.</div>');
}

$customer_id = $customer['id'];
$currency = $customer['currency'] ?? 'YER';

// 2. Fetch Customer Orders with items
// We fetch orders first
$orders_stmt = $db->prepare("SELECT * FROM shop_orders WHERE customer_id = ? ORDER BY created_at DESC");
$orders_stmt->execute([$customer_id]);
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طلباتي - سجل المشتريات</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; }
        .status-badge { @apply px-3 py-1 rounded-full text-xs font-bold; }
    </style>
</head>
<body class="bg-gray-50">

    <!-- Navbar -->
    <nav class="text-white shadow-lg sticky top-0 z-40 bg-gray-900">
        <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">
            <h1 class="text-lg font-bold text-[#C7A46D]"><i class="fas fa-shopping-bag ml-2"></i> سجل طلباتي</h1>
            <a href="products.php?token=<?php echo htmlspecialchars($token); ?>" class="text-sm bg-gray-700 hover:bg-gray-600 px-4 py-2 rounded-lg transition">العودة للمتجر</a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="mb-6">
            <h2 class="text-2xl font-black text-gray-800">مرحباً، <?php echo htmlspecialchars($customer['name']); ?></h2>
            <p class="text-gray-500">هنا يمكنك متابعة حالة طلباتك السابقة وتفاصيلها.</p>
        </div>

        <?php if (empty($orders)): ?>
            <div class="bg-white p-12 text-center rounded-2xl shadow-sm border border-gray-100">
                <i class="fas fa-box-open text-6xl text-gray-200 mb-4"></i>
                <p class="text-xl text-gray-500 font-bold">ليس لديك أي طلبات حالياً.</p>
                <a href="products.php?token=<?php echo htmlspecialchars($token); ?>" class="inline-block mt-4 text-[#C7A46D] hover:underline">ابدأ التسوق الآن</a>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto bg-white rounded-2xl shadow-sm border border-gray-100">
                <table class="w-full text-right border-collapse">
                    <thead>
                        <tr class="bg-gray-900 text-[#C7A46D]">
                            <th class="p-4 font-bold text-sm">رقم الطلب</th>
                            <th class="p-4 font-bold text-sm">التاريخ</th>
                            <th class="p-4 font-bold text-sm">الحالة</th>
                            <th class="p-4 font-bold text-sm">الإجمالي</th>
                            <th class="p-4 font-bold text-sm text-center">التفاصيل</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($orders as $order): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4 font-bold text-gray-800 text-sm">
                                    <?php echo htmlspecialchars($order['order_number']); ?>
                                </td>
                                <td class="p-4 text-gray-600 text-xs">
                                    <?php echo date('Y/m/d H:i', strtotime($order['created_at'])); ?>
                                </td>
                                <td class="p-4">
                                    <?php 
                                        $status = $order['order_status'];
                                        $color = "bg-gray-100 text-gray-600";
                                        if($status == 'طلب جديد') $color = "bg-blue-100 text-blue-600";
                                        elseif($status == 'قيد التنفيذ') $color = "bg-yellow-100 text-yellow-600";
                                        elseif($status == 'تم الشحن') $color = "bg-purple-100 text-purple-600";
                                        elseif($status == 'تم التسليم') $color = "bg-green-100 text-green-600";
                                        elseif($status == 'ملغي') $color = "bg-red-100 text-red-600";
                                    ?>
                                    <span class="px-3 py-1 rounded-full text-[11px] font-bold <?php echo $color; ?>">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </td>
                                <td class="p-4 font-black text-gray-900 text-sm">
                                    <?php echo number_format($order['total_amount'], 2); ?> <span class="text-[10px] text-gray-400 font-normal"><?php echo $currency; ?></span>
                                </td>
                                <td class="p-4 text-center">
                                    <button onclick="toggleDetails('<?php echo $order['id']; ?>')" class="text-blue-500 hover:text-blue-700 font-bold text-sm flex items-center justify-center gap-1 w-full">
                                        <i class="fas fa-eye"></i> <span class="hidden sm:inline">عرض</span>
                                    </button>
                                </td>
                            </tr>
                            <!-- Details Row (Hidden by default) -->
                            <tr id="details-<?php echo $order['id']; ?>" class="hidden bg-gray-50">
                                <td colspan="5" class="p-4">
                                    <div class="bg-white rounded-xl p-4 border border-blue-100 shadow-inner">
                                        <h4 class="font-bold text-gray-700 mb-3 border-b pb-2 text-sm"><i class="fas fa-info-circle ml-1"></i> محتويات الطلب:</h4>
                                        <div class="space-y-3">
                                            <?php
                                            // Fetch Items for this specific order
                                            $items_stmt = $db->prepare("SELECT * FROM shop_order_items WHERE order_id = ?");
                                            $items_stmt->execute([$order['id']]);
                                            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                                            foreach ($items as $item):
                                            ?>
                                                <div class="flex justify-between items-center text-xs sm:text-sm border-b border-dashed border-gray-100 pb-2">
                                                    <div>
                                                        <span class="font-bold text-gray-800"><?php echo htmlspecialchars($item['product_name']); ?></span>
                                                        <?php if($item['variant_text']): ?>
                                                            <span class="text-[10px] bg-gray-100 px-1 rounded mx-1"><?php echo htmlspecialchars($item['variant_text']); ?></span>
                                                        <?php endif; ?>
                                                        <span class="text-gray-400 mx-2">× <?php echo $item['quantity']; ?></span>
                                                    </div>
                                                    <div class="font-bold text-gray-600">
                                                        <?php echo number_format($item['total_price'], 2); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <!-- Order Summary Breakdown -->
                                        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-center border-t pt-4">
                                            <div>
                                                <p class="text-[10px] text-gray-400">المجموع</p>
                                                <p class="font-bold text-xs"><?php echo number_format($order['subtotal'], 2); ?></p>
                                            </div>
                                            <div>
                                                <p class="text-[10px] text-gray-400">الشحن</p>
                                                <p class="font-bold text-xs"><?php echo number_format($order['shipping_fee'], 2); ?></p>
                                            </div>
                                            <div>
                                                <p class="text-[10px] text-gray-400">الخصم</p>
                                                <p class="font-bold text-xs text-red-500">-<?php echo number_format($order['discount_amount'], 2); ?></p>
                                            </div>
                                            <div>
                                                <p class="text-[10px] text-gray-400">إيصال الدفع</p>
                                                <a href="../<?php echo htmlspecialchars($order['payment_evidence_url']); ?>" target="_blank" class="text-blue-500 underline text-xs font-bold">عرض الصورة</a>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleDetails(orderId) {
            const el = document.getElementById('details-' + orderId);
            if (el.classList.contains('hidden')) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        }
    </script>
</body>
</html>