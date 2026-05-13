<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login.php');
    exit();
}

require_once '../../../config/database.php';
$page_title = 'عرض مجموعة الشراء';
$group_id = intval($_GET['id'] ?? 0);
$success_message = '';
$error_message = '';

if (!$group_id) {
    header('Location: index.php');
    exit();
}

function isAdmin($user_id, $db) {
    static $cache = [];

    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }

    try {
        $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$user_id] = ($result && $result['is_admin'] == 1);
        return $cache[$user_id];
    } catch (PDOException $e) {
        return false;
    }
}
$current_user_id = $_SESSION['user_id'] ?? 0;
$isAdmin = isAdmin($current_user_id, $db);

// Handle POST requests for adding baskets and orders
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'add_basket') {
                // Add basket(s) to group - support multiple baskets
                $basket_ids = isset($_POST['basket_ids']) ? $_POST['basket_ids'] : (isset($_POST['basket_id']) ? [$_POST['basket_id']] : []);
                $added_count = 0;

                foreach ($basket_ids as $basket_id) {
                    $basket_id = intval($basket_id);
                    if ($basket_id) {
                        $stmt = $db->prepare("UPDATE purchase_baskets SET purchase_group_id = ? WHERE id = ?");
                        $stmt->execute([$group_id, $basket_id]);
                        $added_count++;
                    }
                }

                // Return JSON for AJAX requests
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'count' => $added_count]);
                    exit();
                }

                $success_message = "تم إضافة $added_count سلة بنجاح";
                header("Location: view.php?id=$group_id&success=basket_added");
                exit();
            } elseif ($_POST['action'] === 'add_order') {
                // Add customer order(s) to group - support multiple orders
                $order_ids = isset($_POST['order_ids']) ? $_POST['order_ids'] : (isset($_POST['order_id']) ? [$_POST['order_id']] : []);
                $added_count = 0;

                foreach ($order_ids as $order_id) {
                    $order_id = intval($order_id);
                    if ($order_id) {
                        $stmt = $db->prepare("UPDATE customer_orders SET purchase_group_id = ? WHERE id = ?");
                        $stmt->execute([$group_id, $order_id]);
                        $added_count++;
                    }
                }

                // Return JSON for AJAX requests
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'count' => $added_count]);
                    exit();
                }

                $success_message = "تم إضافة $added_count طلب بنجاح";
                header("Location: view.php?id=$group_id&success=order_added");
                exit();
            } elseif ($_POST['action'] === 'remove_basket') {
                // Remove basket from group
                $basket_id = intval($_POST['basket_id'] ?? 0);
                if ($basket_id) {
                    $stmt = $db->prepare("UPDATE purchase_baskets SET purchase_group_id = NULL WHERE id = ? AND purchase_group_id = ?");
                    $stmt->execute([$basket_id, $group_id]);
                    $success_message = 'تم إزالة السلة بنجاح';
                    header("Location: view.php?id=$group_id&success=basket_removed");
                    exit();
                }
            } elseif ($_POST['action'] === 'remove_order') {
                // Remove customer order from group
                $order_id = intval($_POST['order_id'] ?? 0);
                if ($order_id) {
                    $stmt = $db->prepare("UPDATE customer_orders SET purchase_group_id = NULL WHERE id = ? AND purchase_group_id = ?");
                    $stmt->execute([$order_id, $group_id]);
                    $success_message = 'تم إزالة الطلب بنجاح';
                    header("Location: view.php?id=$group_id&success=order_removed");
                    exit();
                }
            } elseif ($_POST['action'] === 'delete_basket') {
                // REMOVE basket from group (do not delete it)
                $basket_id = intval($_POST['basket_id'] ?? 0);
                if ($basket_id) {
                    // Set purchase_group_id to NULL to un-assign it from the group
                    $stmt = $db->prepare("UPDATE purchase_baskets SET purchase_group_id = NULL WHERE id = ? AND purchase_group_id = ?");
                    $stmt->execute([$basket_id, $group_id]);

                    // Return JSON for AJAX
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => 'تم إزالة السلة من المجموعة بنجاح.']);
                        exit();
                    }

                    // Fallback for non-AJAX
                    header("Location: view.php?id=$group_id&success=basket_removed");
                    exit();
                }
            } elseif ($_POST['action'] === 'delete_order') {
                // UNASSIGN customer order from this group only (do not delete the order)
                $order_id = intval($_POST['order_id'] ?? 0);
                if ($order_id) {
                    // Remove the group relation from the order
                    $db->prepare("UPDATE customer_orders SET purchase_group_id = NULL WHERE id = ? AND purchase_group_id = ?")
                        ->execute([$order_id, $group_id]);

                    // Return JSON for AJAX
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => 'تم إزالة الطلب من المجموعة بنجاح']);
                        exit();
                    }

                    header("Location: view.php?id=$group_id&success=order_removed");
                    exit();
                }
            }
        } catch (PDOException $e) {
            // Return JSON error for AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit();
            }
            $error_message = 'حدث خطأ: ' . $e->getMessage();
        }
    }
}

// Check for success messages from redirects
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'basket_added':
            $success_message = 'تم إضافة السلة بنجاح';
            break;
        case 'order_added':
            $success_message = 'تم إضافة الطلب بنجاح';
            break;
        case 'basket_removed':
            $success_message = 'تم إزالة السلة بنجاح';
            break;
        case 'order_removed':
            $success_message = 'تم إزالة الطلب بنجاح';
            break;
    }
}

try {
    // 1. Fetch group details and the creator's name
    $stmt = $db->prepare("
        SELECT pg.*, u.full_name as created_by_name
        FROM purchase_groups pg
        LEFT JOIN users u ON pg.created_by = u.id
        WHERE pg.id = ?
    ");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        header('Location: index.php');
        exit();
    }

    // 2. Fetch related purchase baskets (COST)
    $baskets_stmt = $db->prepare("
        SELECT pb.id, pb.basket_code, pb.basket_name, pb.final_amount, pb.status, pb.created_at,
               pb.purchase_date,
               pb.subtotal_amount,
               pb.account_number,
               pb.total_items as items_count,
               (SELECT SUM(bi.quantity) FROM basket_items bi WHERE bi.basket_id = pb.id) as total_quantity,
               (SELECT GROUP_CONCAT(tracking_number SEPARATOR ', ') FROM basket_tracking WHERE basket_id = pb.id) AS tracking_numbers
        FROM purchase_baskets pb
        WHERE pb.purchase_group_id = ?
        ORDER BY pb.created_at DESC
    ");
    $baskets_stmt->execute([$group_id]);
    $baskets = $baskets_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch related customer orders (REVENUE)
    $customer_orders_stmt = $db->prepare("
        SELECT co.id,
               co.order_number,
               co.order_date,
               co.currency,
               COALESCE(co.subtotal_amount, 0) as subtotal_amount,
               COALESCE(co.discount_amount, 0) as discount_amount,
               COALESCE(co.final_amount, 0) as final_amount,
               COALESCE(co.paid_amount, 0) as paid_amount,
               COALESCE(co.automatic_discount_percentage, 0) as automatic_discount_percentage,
               co.status,
               co.order_link,
               co.additional_link,
               co.created_at,
               co.customer_id,
               c.name as customer_name,
               c.mobile_number,
               c.whatsapp_number,
               (SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = co.id) as total_quantity,
               (SELECT GROUP_CONCAT(CONCAT(ci.id, ':', ci.invoice_number) SEPARATOR ';') FROM customer_invoices ci WHERE ci.order_id = co.id) as invoice_data,
               pb.basket_code,
               pg.group_name
        FROM customer_orders co
        LEFT JOIN customers c ON co.customer_id = c.id
        LEFT JOIN purchase_baskets pb ON co.basket_id = pb.id
        LEFT JOIN purchase_groups pg ON co.purchase_group_id = pg.id
        WHERE co.purchase_group_id = ?
        ORDER BY co.created_at DESC
    ");
    $customer_orders_stmt->execute([$group_id]);
    $customer_orders = $customer_orders_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Full financial calculations
    $stats = [
        'total_baskets' => count($baskets),
        'total_baskets_amount' => array_sum(array_column($baskets, 'final_amount')),
        'total_customer_orders' => count($customer_orders),
        'total_customer_orders_amount' => array_sum(array_column($customer_orders, 'final_amount'))
    ];

    $revenue = $stats['total_customer_orders_amount'];
    $cost = $stats['total_baskets_amount'];
    $profit = $revenue - $cost;

    $profit_margin = ($revenue > 0) ? ($profit / $revenue) * 100 : 0;
    $markup_percentage = ($cost > 0) ? ($profit / $cost) * 100 : 0;

    $stats['profit'] = $profit;
    $stats['profit_margin'] = $profit_margin;
    $stats['markup_percentage'] = $markup_percentage;

    $profit_color = 'text-gray-800';
    $profit_icon = 'fas fa-minus';
    if ($stats['profit'] > 0.01) {
        $profit_color = 'text-emerald-600';
        $profit_icon = 'fas fa-arrow-up';
    } elseif ($stats['profit'] < -0.01) {
        $profit_color = 'text-red-600';
        $profit_icon = 'fas fa-arrow-down';
    }
} catch (PDOException $e) {
    $error_message = "حدث خطأ في قاعدة البيانات: " . $e->getMessage();
    $group = [];
    $baskets = [];
    $customer_orders = [];
    $stats = array_fill_keys(['total_baskets', 'total_baskets_amount', 'total_customer_orders', 'total_customer_orders_amount', 'profit', 'profit_margin', 'markup_percentage'], 0);
    $profit_color = 'text-gray-800';
    $profit_icon = 'fas fa-minus';
}

include '../../../includes/header.php';
?>

<?php if ($success_message): ?>
    <div class="fixed top-4 right-4 z-50 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-lg" role="alert">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-2xl ml-3"></i>
            <p class="font-bold"><?php echo htmlspecialchars($success_message); ?></p>
        </div>
    </div>
    <script>setTimeout(() => { document.querySelector('[role="alert"]').remove(); }, 3000);</script>
<?php endif; ?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white flex items-center">
                            <i class="fas fa-layer-group ml-3 text-purple-200"></i>
                            <?php echo htmlspecialchars($group['group_name'] ?? 'مجموعة غير موجودة'); ?>
                        </h1>
                        <p class="text-purple-100 mt-2 text-lg">
                            رقم المجموعة: <?php echo htmlspecialchars($group['group_number'] ?? 'غير محدد'); ?>
                        </p>
                    </div>
                    <div class="flex gap-3">
                        <a href="edit.php?id=<?php echo $group_id; ?>" class="inline-flex items-center px-6 py-3 bg-white text-purple-600 rounded-xl hover:bg-purple-50 transition-all duration-200 shadow-lg font-semibold">
                            <i class="fas fa-edit ml-2"></i> تعديل
                        </a>
                        <a href="index.php" class="inline-flex items-center px-6 py-3 bg-purple-800 text-white rounded-xl hover:bg-purple-900 transition-all duration-200 shadow-lg font-semibold">
                            <i class="fas fa-arrow-right ml-2"></i> العودة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <!-- Full Financial Analysis Section -->
        <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 border-t-4 border-indigo-500">
            <h2 class="text-2xl font-bold text-gray-900 flex items-center mb-6">
                <i class="fas fa-chart-line ml-3 text-indigo-500"></i> التحليل المالي للمجموعة
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 text-center">
                <div class="bg-teal-50 p-5 rounded-xl border border-teal-200">
                    <p class="text-md font-semibold text-teal-800">إجمالي المبيعات</p>
                    <div class="flex items-center justify-center gap-2 mt-2">
                        <p class="text-3xl font-bold text-teal-600"><?php echo number_format($stats['total_customer_orders_amount']); ?></p>
                        <span class="text-md font-semibold text-teal-700">ر.ي</span>
                    </div>
                </div>
                <div class="bg-orange-50 p-5 rounded-xl border border-orange-200">
                    <p class="text-md font-semibold text-orange-800">إجمالي التكاليف</p>
                    <div class="flex items-center justify-center gap-2 mt-2">
                        <p class="text-3xl font-bold text-orange-600"><?php echo number_format($stats['total_baskets_amount']); ?></p>
                        <span class="text-md font-semibold text-orange-700">ر.ي</span>
                    </div>
                </div>
                <div class="bg-gray-50 p-5 rounded-xl border <?php echo ($stats['profit'] > 0.01) ? 'border-emerald-300' : (($stats['profit'] < -0.01) ? 'border-red-300' : 'border-gray-200'); ?>">
                    <p class="text-md font-semibold text-gray-800">صافي الربح / الخسارة</p>
                    <div class="flex items-center justify-center gap-2 mt-2">
                        <i class="<?php echo $profit_icon; ?> text-xl <?php echo $profit_color; ?>"></i>
                        <p class="text-3xl font-bold <?php echo $profit_color; ?>"><?php echo number_format(abs($stats['profit'])); ?></p>
                        <span class="text-md font-semibold <?php echo $profit_color; ?>">ر.ي</span>
                    </div>
                </div>
                <!-- PERCENTAGE COLUMN -->
                <div class="bg-indigo-50 p-5 rounded-xl border border-indigo-200">
                    <p class="text-md font-semibold text-indigo-800">نسبة الربح (%)</p>
                    <div class="flex flex-col items-center justify-center mt-2">
                        <div class="flex items-baseline gap-1">
                            <p class="text-3xl font-bold <?php echo $profit_color; ?>"><?php echo number_format($stats['profit_margin'], 2); ?>%</p>
                        </div>
                        <p class="text-xs text-indigo-500 mt-1">من إجمالي المبيعات</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Associated Purchase Baskets Table -->
        <div class="bg-white shadow-xl rounded-2xl overflow-hidden mb-8">
            <div class="px-6 py-4 bg-gradient-to-r from-gray-50 to-gray-100 border-b-2 border-gray-200 flex items-center justify-between">
                <h2 class="text-xl font-bold text-gray-900 flex items-center">
                    <i class="fas fa-shopping-basket ml-2 text-amber-600"></i> سلال الشراء المرتبطة
                </h2>
                <button onclick="openAddBasketModal()" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-all shadow-lg">
                    <i class="fas fa-plus ml-2"></i> إضافة سلة
                </button>
            </div>
            <div class="overflow-x-auto">
                <?php if (empty($baskets)): ?>
                    <p class="p-6 text-center text-gray-500">لا توجد سلال شراء مرتبطة.</p>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">اسم السلة</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">الكود</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">رقم الحساب</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">رقم التتبع</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">عدد المنتجات</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">رقم السلة</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">تاريخ الطلب</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">الإجمالي النهائي</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-700">الحالة</th>
                                <th class="px-6 py-3 text-center text-xs font-bold text-gray-700">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php 
                            $sum_basket_items = 0;
                            $sum_basket_subtotal = 0;
                            $sum_basket_final = 0;
                            foreach ($baskets as $basket): 
                                $sum_basket_items += intval($basket['items_count'] ?? 0);
                                $sum_basket_subtotal += floatval($basket['subtotal_amount'] ?? 0);
                                $sum_basket_final += floatval($basket['final_amount'] ?? 0);
                            ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($basket['basket_name'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($basket['basket_code']); ?></td>
                                    <td class="px-6 py-4 text-sm font-bold text-indigo-600"><?php echo htmlspecialchars($basket['account_number'] ?? '-'); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo !empty($basket['tracking_numbers']) ? '<span><i class="fas fa-truck ml-1"></i> '.htmlspecialchars((string)$basket['tracking_numbers']).'</span>' : '-'; ?></td>
                                    <td class="px-6 py-4 text-center text-sm font-bold text-blue-600"><?php echo intval($basket['items_count'] ?? 0); ?></td>
                                    <td class="px-6 py-4 text-sm font-bold text-blue-600"><?php echo htmlspecialchars($basket['id']); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-700"><?php echo $basket['purchase_date'] ? date('Y/m/d', strtotime($basket['purchase_date'])) : date('Y/m/d', strtotime($basket['created_at'])); ?></td>
                                    <td class="px-6 py-4 text-sm font-bold text-green-600"><?php echo number_format($basket['final_amount']); ?></td>
                                    <td class="px-6 py-4">
                                        <?php 
                                        $st = [
                                            'active' => 'نشطة',
                                            'ordered' => 'تم الطلب',
                                            'ready_to_deliver' => 'جاهز للتسليم',
                                            'delivered' => 'مسلمة',
                                            'under_inspection' => 'قيد الفحص',
                                            'completed' => 'مكتملة',
                                            'finished' => 'منتهية',
                                            'cancelled' => 'ملغية',
                                            'shipped' => 'مشحونة',
                                            't' => 'قيد المعالجة / ترانزيت' // معالجة حالة حرف T
                                        ]; 
                                        $raw_status = strtolower(trim($basket['status'] ?? ''));
                                        $display_status = $st[$raw_status] ?? $basket['status'];
                                        ?>
                                        <span class="px-3 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-800">
                                            <?php echo htmlspecialchars($display_status); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="/modules/purchases/view_basket.php?id=<?php echo $basket['id']; ?>" class="w-8 h-8 flex items-center justify-center rounded-full bg-blue-100 text-blue-600 hover:bg-blue-600 hover:text-white transition-all"><i class="fas fa-eye text-sm"></i></a>
                                            <button onclick="deleteBasket(<?php echo $basket['id']; ?>, '<?php echo htmlspecialchars(addslashes($basket['basket_code'])); ?>')" class="w-8 h-8 flex items-center justify-center rounded-full bg-red-100 text-red-600 hover:bg-red-600 hover:text-white transition-all"><i class="fas fa-trash text-sm"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-100 font-bold border-t-2 border-gray-300">
                            <tr>
                                <td colspan="4" class="px-6 py-4 text-left text-gray-900">إجمالي عدد المنتجات:</td>
                                <td class="px-6 py-4 text-center text-blue-700 font-bold"><?php echo $sum_basket_items; ?></td>
                                <td colspan="2" class="px-6 py-4 text-left text-gray-900">الإجمالي الكلي:</td>
                                <td class="px-6 py-4 text-green-700"><?php echo number_format($sum_basket_final); ?> ر.ي</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Associated Customer Orders Table -->
        <div class="bg-white shadow-xl rounded-2xl overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-gray-50 to-gray-100 border-b-2 border-gray-200 flex items-center justify-between">
                <h2 class="text-xl font-bold text-gray-900 flex items-center">
                    <i class="fas fa-users ml-2 text-teal-600"></i> طلبات العملاء المرتبطة
                </h2>
                <button onclick="openAddOrderModal()" class="inline-flex items-center px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-all shadow-lg">
                    <i class="fas fa-plus ml-2"></i> إضافة طلب
                </button>
            </div>
            <div class="overflow-x-auto">
                <?php if (empty($customer_orders)): ?>
                    <p class="p-6 text-center text-gray-500">لا توجد طلبات عملاء مرتبطة.</p>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-700">رقم الطلب</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-700">تاريخ الطلب</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-700">العميل</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-700">الكمية</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-700">الحالة</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-700">العملة</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-700">الإجمالي النهائي</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-700">المدفوع</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-700">المتبقي</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-gray-700">الروابط</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-gray-700">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php 
                            $sum_order_qty = 0;
                            $sum_order_subtotal = 0;
                            $sum_order_discount = 0;
                            $sum_order_final = 0;
                            $sum_order_paid = 0;
                            $sum_order_remaining = 0;
                            foreach ($customer_orders as $co): 
                                $rem = ($co['final_amount'] ?? 0) - ($co['paid_amount'] ?? 0);
                                $sum_order_qty += intval($co['total_quantity'] ?? 0);
                                $sum_order_subtotal += floatval($co['subtotal_amount'] ?? $co['final_amount']);
                                $sum_order_discount += floatval($co['discount_amount'] ?? 0);
                                $sum_order_final += floatval($co['final_amount'] ?? 0);
                                $sum_order_paid += floatval($co['paid_amount'] ?? 0);
                                $sum_order_remaining += $rem;
                            ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-4 text-sm font-bold text-blue-600"><?php echo htmlspecialchars($co['order_number']); ?></td>
                                    <td class="px-4 py-4 text-sm text-gray-700"><?php echo date('Y/m/d', strtotime($co['order_date'] ?? $co['created_at'])); ?></td>
                                    <td class="px-4 py-4 text-sm">
                                        <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($co['customer_name'] ?? 'N/A'); ?></div>
                                        <small class="text-gray-500"><?php echo htmlspecialchars($co['mobile_number'] ?? ''); ?></small>
                                    </td>
                                    <td class="px-4 py-4 text-center text-sm font-bold text-gray-800"><?php echo intval($co['total_quantity'] ?? 0); ?></td>
                                    <td class="px-4 py-4 text-xs">
                                        <?php 
                                        $os = [
                                            'new' => 'جديد',
                                            'pending' => 'قيد الانتظار',
                                            'processing' => 'قيد المعالجة',
                                            'completed' => 'مكتمل',
                                            'cancelled' => 'ملغي',
                                            'delivered' => 'تم التسليم',
                                            'ready_to_deliver' => 'جاهز للتسليم',
                                            'shipped' => 'قيد الشحن',
                                            'purchased' => 'تم الشراء',
                                            't' => 'قيد المعالجة / ترانزيت' // معالجة حالة حرف T
                                        ]; 
                                        $raw_order_status = strtolower(trim($co['status'] ?? ''));
                                        $display_order_status = $os[$raw_order_status] ?? $co['status'];
                                        ?>
                                        <span class="px-3 py-1 font-bold rounded-full bg-teal-100 text-teal-800">
                                            <?php echo htmlspecialchars($display_order_status); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm"><span class="px-2 py-1 text-xs font-bold rounded bg-blue-50 text-blue-700 border border-blue-200"><?php echo htmlspecialchars($co['currency'] ?? 'SAR'); ?></span></td>
                                    <td class="px-4 py-4 text-sm font-bold text-emerald-700"><?php echo number_format($co['final_amount'] ?? 0); ?></td>
                                    <td class="px-4 py-4 text-sm text-green-600 font-semibold"><?php echo number_format($co['paid_amount'] ?? 0); ?></td>
                                    <td class="px-4 py-4 text-sm <?php echo $rem > 0 ? 'text-red-600' : 'text-gray-700'; ?> font-semibold"><?php echo number_format($rem); ?></td>
                                    <td class="px-4 py-4 text-center text-sm">
                                        <div class="flex flex-col gap-1">
                                            <?php if (!empty($co['order_link'])): ?>
                                                <a href="<?php echo htmlspecialchars($co['order_link']); ?>" target="_blank" class="text-blue-500 hover:underline">
                                                    <i class="fas fa-link ml-1"></i> رابط الطلب
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($co['additional_link'])): ?>
                                                <a href="<?php echo htmlspecialchars($co['additional_link']); ?>" target="_blank" class="text-indigo-500 hover:underline">
                                                    <i class="fas fa-external-link-alt ml-1"></i> رابط إضافي
                                                </a>
                                            <?php endif; ?>
                                            <?php if (empty($co['order_link']) && empty($co['additional_link'])): ?>
                                                -
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="../../orders/view.php?id=<?php echo $co['id']; ?>" class="w-8 h-8 flex items-center justify-center rounded-full bg-blue-100 text-blue-600 hover:bg-blue-600 hover:text-white transition-all"><i class="fas fa-eye text-sm"></i></a>
                                            <button onclick="deleteOrder(<?php echo $co['id']; ?>, '<?php echo htmlspecialchars(addslashes($co['order_number'])); ?>')" class="w-8 h-8 flex items-center justify-center rounded-full bg-red-100 text-red-600 hover:bg-red-600 hover:text-white transition-all"><i class="fas fa-times"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-100 font-bold border-t-2 border-gray-300">
                            <tr>
                                <td colspan="3" class="px-4 py-4 text-left text-gray-900">الإجمالي الكلي للطلبات:</td>
                                <td class="px-4 py-4 text-center text-gray-900"><?php echo $sum_order_qty; ?></td>
                                <td colspan="2"></td>
                                <td class="px-4 py-4 text-emerald-700"><?php echo number_format($sum_order_final); ?> ر.ي</td>
                                <td class="px-4 py-4 text-green-700"><?php echo number_format($sum_order_paid); ?> ر.ي</td>
                                <td class="px-4 py-4 text-red-700"><?php echo number_format($sum_order_remaining); ?> ر.ي</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modals for adding items -->
<div id="addBasketModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" onclick="if(event.target === this) closeAddBasketModal()">
    <div class="bg-white rounded-2xl p-8 max-w-3xl w-full mx-4 max-h-[85vh] overflow-hidden flex flex-col">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-bold text-gray-900">إضافة سلال شراء للمجموعة</h3>
            <button onclick="closeAddBasketModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-2xl"></i></button>
        </div>
        <div class="mb-4">
            <input type="text" id="basketSearch" placeholder="🔍 ابحث عن سلة..." class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-right" oninput="filterBaskets()">
        </div>
        <div id="basketsList" class="flex-1 overflow-y-auto border-2 border-gray-200 rounded-lg mb-4"></div>
        <div class="flex gap-3 justify-end pt-4 border-t-2">
            <button onclick="closeAddBasketModal()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold">إلغاء</button>
            <button onclick="addSelectedBasketsToGroup()" class="px-6 py-3 bg-amber-600 text-white rounded-lg font-semibold">إضافة المختارة</button>
        </div>
    </div>
</div>

<div id="addOrderModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center" onclick="if(event.target === this) closeAddOrderModal()">
    <div class="bg-white rounded-2xl p-8 max-w-3xl w-full mx-4 max-h-[85vh] overflow-hidden flex flex-col">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-2xl font-bold text-gray-900">إضافة طلبات عملاء للمجموعة</h3>
            <button onclick="closeAddOrderModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-2xl"></i></button>
        </div>
        <div class="mb-4">
            <input type="text" id="orderSearch" placeholder="🔍 ابحث عن طلب..." class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg text-right" oninput="filterOrders()">
        </div>
        <div id="ordersList" class="flex-1 overflow-y-auto border-2 border-gray-200 rounded-lg mb-4"></div>
        <div class="flex gap-3 justify-end pt-4 border-t-2">
            <button onclick="closeAddOrderModal()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg font-semibold">إلغاء</button>
            <button onclick="addSelectedOrdersToGroup()" class="px-6 py-3 bg-teal-600 text-white rounded-lg font-semibold">إضافة المختارة</button>
        </div>
    </div>
</div>

<script>
    const groupId = <?php echo $group_id; ?>;
    let allBaskets = [];
    let selectedBasketIds = new Set();
    let allOrders = [];
    let selectedOrderIds = new Set();

    // Basket AJAX Functions
    async function loadAvailableBaskets() {
        const container = document.getElementById('basketsList');
        try {
            const response = await fetch(`/api_get_available_baskets.php`);
            const data = await response.json();
            if (data.success) {
                allBaskets = data.baskets;
                renderBaskets(allBaskets);
            }
        } catch (e) { console.error(e); }
    }

    function renderBaskets(baskets) {
        const container = document.getElementById('basketsList');
        container.innerHTML = baskets.map(b => `
            <label class="flex items-center p-4 border-b hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" onchange="toggleBasket(${b.id})" class="ml-3">
                <div class="flex-1 flex justify-between">
                    <span class="font-bold">${b.basket_code}</span>
                    <span class="text-green-600">${parseFloat(b.final_amount).toLocaleString()} ر.ي</span>
                </div>
            </label>
        `).join('');
    }

    function toggleBasket(id) { selectedBasketIds.has(id) ? selectedBasketIds.delete(id) : selectedBasketIds.add(id); }

    async function addSelectedBasketsToGroup() {
        const formData = new FormData();
        formData.append('action', 'add_basket');
        Array.from(selectedBasketIds).forEach(id => formData.append('basket_ids[]', id));
        const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        if ((await res.json()).success) window.location.reload();
    }

    // Order AJAX Functions
    async function loadAvailableOrders() {
        const container = document.getElementById('ordersList');
        try {
            const response = await fetch(`/api_get_available_orders.php`);
            const data = await response.json();
            if (data.success) {
                allOrders = data.orders;
                renderOrders(allOrders);
            }
        } catch (e) { console.error(e); }
    }

    function renderOrders(orders) {
        const container = document.getElementById('ordersList');
        container.innerHTML = orders.map(o => `
            <label class="flex items-center p-4 border-b hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" onchange="toggleOrder(${o.id})" class="ml-3">
                <div class="flex-1 flex justify-between">
                    <span class="font-bold">${o.order_number} (${o.customer_name})</span>
                    <span class="text-teal-600">${parseFloat(o.final_amount).toLocaleString()} ر.ي</span>
                </div>
            </label>
        `).join('');
    }

    function toggleOrder(id) { selectedOrderIds.has(id) ? selectedOrderIds.delete(id) : selectedOrderIds.add(id); }

    async function addSelectedOrdersToGroup() {
        const formData = new FormData();
        formData.append('action', 'add_order');
        Array.from(selectedOrderIds).forEach(id => formData.append('order_ids[]', id));
        const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        if ((await res.json()).success) window.location.reload();
    }

    function openAddBasketModal() { document.getElementById('addBasketModal').classList.remove('hidden'); loadAvailableBaskets(); }
    function closeAddBasketModal() { document.getElementById('addBasketModal').classList.add('hidden'); }
    function openAddOrderModal() { document.getElementById('addOrderModal').classList.remove('hidden'); loadAvailableOrders(); }
    function closeAddOrderModal() { document.getElementById('addOrderModal').classList.add('hidden'); }

    // Deletion Helpers
    async function deleteBasket(id, code) {
        if(confirm(`إزالة السلة ${code} من المجموعة؟`)) {
            const fd = new FormData(); fd.append('action', 'delete_basket'); fd.append('basket_id', id);
            const res = await fetch(window.location.href, { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} });
            if ((await res.json()).success) window.location.reload();
        }
    }

    async function deleteOrder(id, num) {
        if(confirm(`إزالة الطلب ${num} من المجموعة؟`)) {
            const fd = new FormData(); fd.append('action', 'delete_order'); fd.append('order_id', id);
            const res = await fetch(window.location.href, { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} });
            if ((await res.json()).success) window.location.reload();
        }
    }
</script>

<?php include '../../../includes/footer.php'; ?>