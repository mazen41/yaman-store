<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة بطاقات الهدية والولاء';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    try {
        // Soft delete by setting is_active to 0
        $stmt = $db->prepare("UPDATE loyalty_cards SET is_active = 0 WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $_SESSION['success_message'] = 'تم حذف البطاقة بنجاح';
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'حدث خطأ أثناء الحذف';
    }
    header('Location: index.php');
    exit();
}

// Handle block/unblock action
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];

    if ($action == 'block') {
        try {
            $stmt = $db->prepare("UPDATE loyalty_cards SET status = 'blocked' WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success_message'] = 'تم حظر البطاقة بنجاح';
        } catch (PDOException $e) {
            $_SESSION['error_message'] = 'حدث خطأ أثناء الحظر';
        }
    } elseif ($action == 'unblock') {
        try {
            $stmt = $db->prepare("UPDATE loyalty_cards SET status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success_message'] = 'تم إلغاء حظر البطاقة بنجاح';
        } catch (PDOException $e) {
            $_SESSION['error_message'] = 'حدث خطأ أثناء إلغاء الحظر';
        }
    }
    header('Location: index.php');
    exit();
}

// Display messages from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}


// Fetch loyalty cards with filtering
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type_filter'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$params = [];
$where_clauses = ["lc.is_active = 1"];

if ($search) {
    $where_clauses[] = "(lc.card_number LIKE ? OR lc.customer_name LIKE ? OR lc.customer_phone LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param);
}

if ($status_filter) {
    $where_clauses[] = "lc.status = ?";
    $params[] = $status_filter;
}

if ($type_filter) {
    $where_clauses[] = "lc.card_type = ?";
    $params[] = $type_filter;
}

if ($date_from) {
    $where_clauses[] = "lc.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if ($date_to) {
    $where_clauses[] = "lc.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$where_sql = implode(' AND ', $where_clauses);

// *** OPTIMIZED SQL QUERY using LEFT JOIN and GROUP BY ***
$sql = "SELECT 
            lc.*, 
            COUNT(lct.id) as transaction_count,
            MAX(lct.transaction_date) as last_transaction_date
        FROM 
            loyalty_cards lc
        LEFT JOIN 
            loyalty_card_transactions lct ON lc.id = lct.card_id
        WHERE 
            $where_sql
        GROUP BY
            lc.id
        ORDER BY 
            lc.created_at DESC 
        LIMIT $limit OFFSET $offset";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cards = [];
    $error_message = 'حدث خطأ في جلب البيانات: ' . $e->getMessage();
}

// Count total for pagination
$count_sql = "SELECT COUNT(*) FROM loyalty_cards lc WHERE $where_sql";
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total_cards = $count_stmt->fetchColumn();
$total_pages = ceil($total_cards / $limit);

// Get statistics
$stats_query = "SELECT 
    COUNT(*) as total_cards,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_cards,
    SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_cards,
    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_cards,
    SUM(current_balance) as total_balance,
    SUM(bonus_balance) as total_bonus,
    SUM(total_spent) as total_spent
    FROM loyalty_cards WHERE is_active = 1";
$stats = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-pink-600 to-purple-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-2">
                            <i class="fas fa-gift mr-3"></i>
                            إدارة بطاقات الهدية والولاء
                        </h1>
                        <p class="text-pink-100">إدارة وتتبع بطاقات الهدية وبرامج الولاء</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <a href="transfer.php"
                            class="bg-white text-blue-600 px-6 py-3 rounded-lg font-bold hover:bg-blue-50 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                            <i class="fas fa-random ml-2"></i>
                            تحويل رصيد
                        </a>
                        <a href="add.php"
                            class="bg-white text-pink-600 px-6 py-3 rounded-lg font-bold hover:bg-pink-50 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                            <i class="fas fa-plus-circle ml-2"></i>
                            إضافة بطاقة جديدة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="bg-amber-100 border-r-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6 shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-2xl ml-3"></i>
                    <p class="font-medium"><?php echo $success_message; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-2xl ml-3"></i>
                    <p class="font-medium"><?php echo $error_message; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div
                class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-pink-500 hover:shadow-xl transition-all duration-300">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">إجمالي البطاقات</p>
                        <p class="text-3xl font-bold text-gray-800">
                            <?php echo number_format($stats['total_cards'] ?? 0); ?>
                        </p>
                    </div>
                    <div class="bg-pink-100 p-4 rounded-full">
                        <i class="fas fa-credit-card text-3xl text-pink-600"></i>
                    </div>
                </div>
            </div>

            <div
                class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-amber-500 hover:shadow-xl transition-all duration-300">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">البطاقات النشطة</p>
                        <p class="text-3xl font-bold text-gray-800">
                            <?php echo number_format($stats['active_cards'] ?? 0); ?>
                        </p>
                    </div>
                    <div class="bg-amber-100 p-4 rounded-full">
                        <i class="fas fa-check-circle text-3xl text-amber-600"></i>
                    </div>
                </div>
            </div>

            <div
                class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-blue-500 hover:shadow-xl transition-all duration-300">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">إجمالي الأرصدة</p>
                        <p class="text-3xl font-bold text-gray-800">
                            <?php echo number_format($stats['total_balance'] ?? 0); ?>
                        </p>
                        <p class="text-xs text-gray-500 mt-1">ريال يمني</p>
                    </div>
                    <div class="bg-blue-100 p-4 rounded-full">
                        <i class="fas fa-wallet text-3xl text-blue-600"></i>
                    </div>
                </div>
            </div>

            <div
                class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-purple-500 hover:shadow-xl transition-all duration-300">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm font-medium mb-1">إجمالي المكافآت</p>
                        <p class="text-3xl font-bold text-gray-800">
                            <?php echo number_format($stats['total_bonus'] ?? 0); ?>
                        </p>
                        <p class="text-xs text-gray-500 mt-1">ريال يمني</p>
                    </div>
                    <div class="bg-purple-100 p-4 rounded-full">
                        <i class="fas fa-gift text-3xl text-purple-600"></i>
                    </div>
                </div>
            </div>
        </div>


        <!-- Search and Filter -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <form method="GET" action="index.php" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-search ml-1 text-pink-600"></i>
                            البحث
                        </label>
                        <input type="text" name="search" placeholder="رقم البطاقة، اسم العميل، رقم الهاتف..."
                            value="<?php echo htmlspecialchars($search); ?>"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-tag ml-1 text-pink-600"></i>
                            نوع البطاقة
                        </label>
                        <!-- FIXED: name changed to type_filter -->
                        <select name="type_filter"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500">
                            <option value="">جميع الأنواع</option>
                            <option value="gift" <?php echo $type_filter == 'gift' ? 'selected' : ''; ?>>بطاقة هدية
                            </option>
                            <option value="loyalty" <?php echo $type_filter == 'loyalty' ? 'selected' : ''; ?>>بطاقة ولاء
                            </option>
                            <option value="promotional" <?php echo $type_filter == 'promotional' ? 'selected' : ''; ?>>
                                بطاقة ترويجية</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-info-circle ml-1 text-pink-600"></i>
                            الحالة
                        </label>
                        <select name="status"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500">
                            <option value="">جميع الحالات</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>نشطة
                            </option>
                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>غير
                                نشطة</option>
                            <option value="expired" <?php echo $status_filter == 'expired' ? 'selected' : ''; ?>>منتهية
                            </option>
                            <option value="blocked" <?php echo $status_filter == 'blocked' ? 'selected' : ''; ?>>محظورة
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar ml-1 text-pink-600"></i>
                            من تاريخ
                        </label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500">
                    </div>
                </div>

                <div class="flex justify-between items-center">
                    <button type="submit"
                        class="bg-pink-600 text-white px-6 py-2 rounded-lg hover:bg-pink-700 transition-all duration-300 shadow-md hover:shadow-lg">
                        <i class="fas fa-search ml-2"></i>
                        بحث
                    </button>
                    <a href="index.php" class="text-gray-600 hover:text-gray-800">
                        <i class="fas fa-redo ml-1"></i>
                        إعادة تعيين
                    </a>
                </div>
            </form>
        </div>

        <!-- Cards Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gradient-to-r from-pink-50 to-purple-50">
                        <tr>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">
                                رقم البطاقة</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">
                                العميل</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">
                                الرصيد / المكافأة</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">
                                الحالة</th>
                            <!-- ADDED: New Columns for Transaction Data -->
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">
                                عدد العمليات</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">
                                آخر عملية</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">
                                العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($cards)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        <i class="fas fa-credit-card text-6xl text-gray-300 mb-4"></i>
                                        <p class="text-gray-500 text-lg font-medium">لا توجد بطاقات تطابق البحث</p>
                                        <p class="text-gray-400 text-sm mt-2">حاول تعديل الفلاتر أو أضف بطاقة جديدة</p>
                                        <a href="add.php"
                                            class="mt-4 bg-pink-600 text-white px-6 py-2 rounded-lg hover:bg-pink-700 transition-all">
                                            <i class="fas fa-plus ml-2"></i>
                                            إضافة بطاقة
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cards as $card): ?>
                                <tr class="hover:bg-gray-50 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="font-medium text-gray-900">
                                            <?php echo htmlspecialchars($card['card_number']); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php
                                            $type_badges = [
                                                'gift' => 'هدية',
                                                'loyalty' => 'ولاء',
                                                'promotional' => 'ترويجية'
                                            ];
                                            echo $type_badges[$card['card_type']] ?? $card['card_type'];
                                            ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm">
                                            <div class="font-medium text-gray-900">
                                                <?php echo htmlspecialchars($card['customer_name'] ?? 'غير محدد'); ?>
                                            </div>
                                            <div class="text-gray-500">
                                                <?php echo htmlspecialchars($card['customer_phone'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div>
                                            <span
                                                class="text-md font-bold text-amber-600"><?php echo number_format($card['current_balance']); ?></span>
                                            <span class="text-xs text-gray-500">ر.ي</span>
                                        </div>
                                        <div>
                                            <span
                                                class="text-sm font-bold text-purple-600"><?php echo number_format($card['bonus_balance']); ?></span>
                                            <span class="text-xs text-gray-500">مكافأة</span>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status_badges = [
                                            'active' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800"><i class="fas fa-check-circle ml-1"></i> نشطة</span>',
                                            'inactive' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800"><i class="fas fa-pause-circle ml-1"></i> غير نشطة</span>',
                                            'expired' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800"><i class="fas fa-clock ml-1"></i> منتهية</span>',
                                            'blocked' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-ban ml-1"></i> محظورة</span>'
                                        ];
                                        echo $status_badges[$card['status']] ?? $card['status'];
                                        ?>
                                    </td>
                                    <!-- ADDED: Displaying Transaction Data -->
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <span
                                            class="text-lg font-bold text-blue-600"><?php echo $card['transaction_count']; ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php if ($card['last_transaction_date']): ?>
                                            <?php echo date("Y-m-d H:i", strtotime($card['last_transaction_date'])); ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">لا يوجد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex gap-2">
                                            <a href="view.php?id=<?php echo $card['id']; ?>"
                                                class="text-blue-600 hover:text-blue-800 transition-colors"
                                                title="عرض التفاصيل">
                                                <i class="fas fa-eye text-lg"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $card['id']; ?>"
                                                class="text-amber-600 hover:text-amber-800 transition-colors" title="تعديل">
                                                <i class="fas fa-edit text-lg"></i>
                                            </a>
                                            <a href="transactions.php?card_id=<?php echo $card['id']; ?>"
                                                class="text-purple-600 hover:text-purple-800 transition-colors"
                                                title="المعاملات">
                                                <i class="fas fa-exchange-alt text-lg"></i>
                                            </a>
                                            <?php if ($card['status'] == 'active'): ?>
                                                <a href="?action=block&id=<?php echo $card['id']; ?>"
                                                    class="text-orange-600 hover:text-orange-800 transition-colors"
                                                    title="حظر البطاقة"
                                                    onclick="return confirm('هل أنت متأكد من حظر هذه البطاقة؟')">
                                                    <i class="fas fa-ban text-lg"></i>
                                                </a>
                                            <?php elseif ($card['status'] == 'blocked'): ?>
                                                <a href="?action=unblock&id=<?php echo $card['id']; ?>"
                                                    class="text-amber-600 hover:text-amber-800 transition-colors"
                                                    title="إلغاء الحظر">
                                                    <i class="fas fa-check-circle text-lg"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="?action=delete&id=<?php echo $card['id']; ?>"
                                                class="text-red-600 hover:text-red-800 transition-colors" title="حذف"
                                                onclick="return confirm('هل أنت متأكد من حذف هذه البطاقة؟ الحذف لا يمكن التراجع عنه.')">
                                                <i class="fas fa-trash text-lg"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            عرض <span class="font-medium"><?php echo $offset + 1; ?></span> إلى
                            <span class="font-medium"><?php echo min($offset + $limit, $total_cards); ?></span> من
                            <span class="font-medium"><?php echo $total_cards; ?></span> بطاقة
                        </div>
                        <div class="flex gap-2">
                            <?php
                            // Build query string for pagination links
                            $query_params = [
                                'search' => $search,
                                'status' => $status_filter,
                                'type_filter' => $type_filter,
                                'date_from' => $date_from,
                                'date_to' => $date_to
                            ];
                            $query_string = http_build_query(array_filter($query_params));
                            ?>
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&<?php echo $query_string; ?>"
                                    class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">السابق</a>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&<?php echo $query_string; ?>"
                                    class="px-4 py-2 <?php echo $i == $page ? 'bg-pink-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'; ?> border border-gray-300 rounded-lg transition-colors"><?php echo $i; ?></a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&<?php echo $query_string; ?>"
                                    class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">التالي</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>