<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'purchase_cards', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

// Get user's permissions
$can_add = hasPermission($_SESSION['user_id'], 'purchase_cards', 'add');
$can_edit = hasPermission($_SESSION['user_id'], 'purchase_cards', 'edit');
$can_delete = hasPermission($_SESSION['user_id'], 'purchase_cards', 'delete');

$page_title = 'بطاقات الشراء';
$currency_symbol = 'ر.ي';

// --- Filtering and Sorting Logic ---
$filter_search = $_GET['search'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'created_at'; // Default sort by created_at
$sort_order = $_GET['sort'] ?? 'desc'; // Default sort by date descending

// Base query
// Ensure card_purchase_amount exists in your database table for profit calculation
$sql = "SELECT id, card_number, card_name, initial_balance, purchase_amount, balance, card_purchase_amount, created_at FROM purchase_cards";
$params = [];

// Add search filter
if (!empty($filter_search)) {
    $sql .= " WHERE card_number LIKE :search OR card_name LIKE :search";
    $params[':search'] = '%' . $filter_search . '%';
}

// Add sorting
$sort_direction = ($sort_order === 'asc') ? 'ASC' : 'DESC';
$order_by_clause = " ORDER BY ";

switch ($sort_by) {
    case 'balance_high_low':
    case 'balance_low_high':
        // For current balance, we calculate (initial_balance - purchase_amount)
        // If 'balance' column in DB is truly the *current* balance, use that directly.
        // Assuming 'balance' is the actual current balance after deductions.
        $order_by_clause .= "balance $sort_direction";
        break;
    case 'profit_high_low':
    case 'profit_low_high':
        // Assuming profit is initial_balance - card_purchase_amount
        $order_by_clause .= "(initial_balance - card_purchase_amount) $sort_direction";
        break;
    case 'created_at':
    default:
        $order_by_clause .= "created_at $sort_direction";
        break;
}
$sql .= $order_by_clause;

// Fetch data with filters and sorting
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items = [];
    $error_message = 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <!-- Page Header -->
    <div class="bg-gradient-to-r from-blue-600 to-indigo-700 text-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
            <div>
                <h1 class="text-3xl font-bold flex items-center gap-3">
                    <i class="fas fa-credit-card"></i>
                    بطاقات الشراء
                </h1>
                <p class="text-blue-100 mt-2">إدارة وتتبع بطاقات الشراء الخاصة بك</p>
            </div>
            <?php if ($can_add): ?>
            <a href="add.php" class="bg-white text-blue-600 px-6 py-3 rounded-lg hover:bg-blue-50 font-semibold transition flex items-center gap-2 shadow-md">
                <i class="fas fa-plus-circle"></i>
                إضافة بطاقة جديدة
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Search and Filter Controls -->
    <div class="mb-6 p-4 bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col lg:flex-row gap-4 items-center">
        <form method="GET" action="" class="flex flex-col md:flex-row gap-4 w-full items-center">
            <div class="w-full md:w-1/3">
                <label for="search" class="sr-only">بحث</label>
                <div class="relative">
                    <input type="text" id="search" name="search" placeholder="ابحث عن رقم أو اسم البطاقة..." class="p-3 border border-gray-300 rounded-lg w-full pr-10 focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($filter_search); ?>">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="fas fa-search"></i></span>
                </div>
            </div>

            <div class="flex items-center gap-2 w-full md:w-auto">
                <label for="sort_by" class="font-medium text-gray-700 whitespace-nowrap">ترتيب حسب:</label>
                <select id="sort_by" name="sort_by" class="p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 w-full" onchange="this.form.submit()">
                    <option value="created_at" <?php echo ($sort_by === 'created_at') ? 'selected' : ''; ?>>تاريخ الإنشاء</option>
                    <option value="balance_high_low" <?php echo ($sort_by === 'balance_high_low') ? 'selected' : ''; ?>>الرصيد الحالي (الأعلى)</option>
                    <option value="balance_low_high" <?php echo ($sort_by === 'balance_low_high') ? 'selected' : ''; ?>>الرصيد الحالي (الأدنى)</option>
                    <option value="profit_high_low" <?php echo ($sort_by === 'profit_high_low') ? 'selected' : ''; ?>>الربح (الأعلى)</option>
                    <option value="profit_low_high" <?php echo ($sort_by === 'profit_low_high') ? 'selected' : ''; ?>>الربح (الأدنى)</option>
                </select>
            </div>

            <div class="flex items-center gap-2 w-full md:w-auto">
                <label for="sort" class="font-medium text-gray-700 whitespace-nowrap">الاتجاه:</label>
                <select id="sort" name="sort" class="p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 w-full" onchange="this.form.submit()">
                    <option value="desc" <?php echo ($sort_order === 'desc') ? 'selected' : ''; ?>>تنازلي</option>
                    <option value="asc" <?php echo ($sort_order === 'asc') ? 'selected' : ''; ?>>تصاعدي</option>
                </select>
            </div>

            <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition font-semibold flex items-center gap-2 w-full md:w-auto justify-center">
                <i class="fas fa-filter"></i>
                تطبيق الفلاتر
            </button>
            <a href="?" class="bg-gray-300 text-gray-800 px-6 py-3 rounded-lg hover:bg-gray-400 transition font-semibold flex items-center gap-2 w-full md:w-auto justify-center">
                <i class="fas fa-times-circle"></i>
                إعادة تعيين
            </a>
        </form>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">خطأ!</strong>
            <span class="block sm:inline"><?php echo $error_message; ?></span>
        </div>
    <?php endif; ?>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم البطاقة</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">اسم البطاقة</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">سعر شراء البطاقة</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الرصيد الأولي</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">مبلغ المشتريات</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الرصيد الحالي</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الربح من البطاقة</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الإنشاء</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="10" class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-inbox text-5xl mb-3 text-gray-300"></i>
                            <p class="text-lg font-semibold">لا توجد بطاقات شراء لعرضها.</p>
                            <?php if ($can_add): ?>
                                <p class="mt-2 text-md">ابدأ بإضافة <a href="add.php" class="text-blue-600 hover:underline">بطاقة شراء جديدة</a>.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php
                        $total_card_purchase_amount = 0;
                        $total_initial_balance = 0;
                        $total_purchase_amount = 0;
                        $total_current_balance = 0;
                        $total_profit = 0;

                        foreach ($items as $index => $item):
                            // Ensure these keys exist, provide default 0 if not
                            $card_purchase_amount = $item['card_purchase_amount'] ?? 0;
                            $initial_balance = $item['initial_balance'] ?? 0;
                            $purchase_amount = $item['purchase_amount'] ?? 0;
                            $current_balance = $item['balance'] ?? ($initial_balance - $purchase_amount); // Use 'balance' if it exists, else calculate

                            // Calculate profit for this card
                            $profit = $initial_balance - $card_purchase_amount;

                            $total_card_purchase_amount += $card_purchase_amount;
                            $total_initial_balance += $initial_balance;
                            $total_purchase_amount += $purchase_amount;
                            $total_current_balance += $current_balance;
                            $total_profit += $profit;
                        ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $index + 1; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($item['card_number']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?php echo htmlspecialchars($item['card_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-purple-600 font-medium">
                                <?php echo number_format($card_purchase_amount, 0, '', ''); ?> <?php echo $currency_symbol; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 font-medium">
                                <?php echo number_format($initial_balance, 0, '', ''); ?> <?php echo $currency_symbol; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 font-medium">
                                <?php echo number_format($purchase_amount, 0, '', ''); ?> <?php echo $currency_symbol; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-600">
                                <?php echo number_format($current_balance, 0, '', ''); ?> <?php echo $currency_symbol; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?php echo ($profit >= 0) ? 'text-teal-600' : 'text-orange-600'; ?>">
                                <?php echo number_format($profit, 0, '', ''); ?> <?php echo $currency_symbol; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php echo date('Y-m-d H:i', strtotime($item['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                <?php if ($can_edit): ?>
                                    <a href="edit.php?id=<?php echo $item['id']; ?>" class="text-blue-600 hover:text-blue-800 mr-4 transition-colors">
                                        <i class="fas fa-edit"></i> تعديل
                                    </a>
                                <?php endif; ?>

                                <?php if ($can_delete): ?>
                                    <a href="delete.php?id=<?php echo $item['id']; ?>"
                                       class="text-red-600 hover:text-red-800 transition-colors"
                                       onclick="return confirm('هل أنت متأكد من رغبتك في حذف هذه البطاقة؟ لا يمكن التراجع عن هذا الإجراء.');">
                                        <i class="fas fa-trash-alt"></i> حذف
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($items)): ?>
                <tfoot class="bg-gray-100 font-bold border-t-2 border-gray-300">
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 text-center" colspan="3">الإجمالي</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-purple-700">
                            <?php echo number_format($total_card_purchase_amount, 0); ?> <?php echo $currency_symbol; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-700">
                            <?php echo number_format($total_initial_balance, 0); ?> <?php echo $currency_symbol; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-red-700">
                            <?php echo number_format($total_purchase_amount, 0); ?> <?php echo $currency_symbol; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-green-700">
                            <?php echo number_format($total_current_balance, 0); ?> <?php echo $currency_symbol; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-teal-700">
                            <?php echo number_format($total_profit, 0); ?> <?php echo $currency_symbol; ?>
                        </td>
                        <td class="px-6 py-4" colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>