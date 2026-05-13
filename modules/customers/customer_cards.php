<?php
/**
 * List Customer Cards
 * - Displays a table of all customer cards.
 * - Includes search, pagination, and sorting.
 * - Provides actions: View, Edit, Delete.
 */

date_default_timezone_set('Asia/Aden');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/accounting_functions.php'; // Needed for delete accounting entry

$page_title = 'قائمة بطاقات العملاء';
$error_message = '';
$success_message = '';

// --- Handle Delete Action ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $card_to_delete_id = intval($_GET['id']);

    try {
        $db->beginTransaction();

        // Check if card has balance or pending transactions (optional but recommended)
        $card_check_stmt = $db->prepare("SELECT current_balance FROM customer_cards WHERE id = ?");
        $card_check_stmt->execute([$card_to_delete_id]);
        $card_balance = $card_check_stmt->fetchColumn();

        if ($card_balance > 0) {
            throw new Exception("لا يمكن حذف البطاقة ذات الرصيد المتبقي. يرجى تصفية الرصيد أولاً.");
        }

        // Delete related transactions first
        $db->prepare("DELETE FROM customer_card_transactions WHERE card_id = ?")->execute([$card_to_delete_id]);
        
        // Delete related journal entries (if any were created for this card, e.g., initial revenue/liability)
        $db->prepare("DELETE FROM journal_entries WHERE source_module = 'customer_cards' AND source_id = ?")->execute([$card_to_delete_id]);
        $db->prepare("DELETE FROM journal_entries WHERE source_module = 'customer_cards_topup' AND source_id = ?")->execute([$card_to_delete_id]);


        // Finally, delete the card
        $stmt = $db->prepare("DELETE FROM customer_cards WHERE id = ?");
        $stmt->execute([$card_to_delete_id]);

        $db->commit();
        $success_message = 'تم حذف البطاقة بنجاح.';
        // Remove delete parameters from URL to prevent re-deletion on refresh
        header('Location: index.php?success=deleted');
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = 'خطأ في حذف البطاقة: ' . $e->getMessage();
    }
}


// --- Pagination & Search ---
$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$search_query = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'DESC';

$where_clauses = [];
$params = [];

if (!empty($search_query)) {
    $where_clauses[] = "(cc.card_number LIKE ? OR c.name LIKE ?)";
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
}

if (!empty($status_filter) && in_array($status_filter, ['active', 'inactive', 'expired', 'blocked'])) {
    $where_clauses[] = "cc.status = ?";
    $params[] = $status_filter;
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total records for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM customer_cards cc JOIN customers c ON cc.customer_id = c.id " . $where_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch cards
$cards = [];
try {
$sql = "
    SELECT 
        cc.id, cc.card_number, cc.current_balance, cc.status, cc.issue_date, cc.expiry_date,
        c.name as customer_name
    FROM customer_cards cc
    JOIN customers c ON cc.customer_id = c.id
    $where_sql
    ORDER BY " . ($sort_by === 'customer_name' ? 'c.name' : 'cc.' . $sort_by) . " " . ($sort_order === 'ASC' ? 'ASC' : 'DESC') . "
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($sql);

// bind search params first
$bindIndex = 1;
foreach ($params as $p) {
    $stmt->bindValue($bindIndex++, $p);
}

// bind limit + offset as INT
$stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

$stmt->execute();
$cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = 'فشل في تحميل بطاقات العملاء: ' . $e->getMessage();
}

// Check for success messages from redirects
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added': $success_message = 'تم إنشاء البطاقة بنجاح.'; break;
        case 'updated': $success_message = 'تم تحديث البطاقة بنجاح.'; break;
        case 'deleted': $success_message = 'تم حذف البطاقة بنجاح.'; break;
        case 'money_added': $success_message = 'تمت إضافة المبلغ إلى البطاقة بنجاح.'; break;
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header Section -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">بطاقات العملاء</h1>
                        <p class="text-gray-600 mt-1">إدارة بطاقات العملاء المدفوعة مسبقاً.</p>
                    </div>
                    <div>
                        <a href="add_customer_card.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"><i class="fas fa-plus ml-2"></i> إضافة بطاقة جديدة</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error / Success Display -->
        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <p class="font-bold">خطأ!</p>
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="bg-green-100 border-r-4 border-green-500 text-green-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <p class="font-bold">نجاح!</p>
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Search and Filter Form -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700">بحث</label>
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_query); ?>" 
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500" 
                           placeholder="البحث بالرقم أو اسم العميل">
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">الحالة</label>
                    <select name="status" id="status" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">-- الكل --</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>نشط</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>غير نشط</option>
                        <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>منتهي</option>
                        <option value="blocked" <?php echo $status_filter === 'blocked' ? 'selected' : ''; ?>>محظور</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="w-full inline-flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i class="fas fa-filter ml-2"></i> تصفية
                    </button>
                    <a href="index.php" class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-undo ml-2"></i> مسح
                    </a>
                </div>
            </form>
        </div>

        <!-- Cards Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="relative overflow-x-auto">
                <?php if (!empty($cards)): ?>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'card_number', 'sort_order' => ($sort_by === 'card_number' && $sort_order === 'ASC' ? 'DESC' : 'ASC')])); ?>">
                                        رقم البطاقة <i class="fas fa-sort<?php echo ($sort_by === 'card_number' ? ($sort_order === 'ASC' ? '-up' : '-down') : ''); ?> ml-1"></i>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'customer_name', 'sort_order' => ($sort_by === 'customer_name' && $sort_order === 'ASC' ? 'DESC' : 'ASC')])); ?>">
                                        العميل <i class="fas fa-sort<?php echo ($sort_by === 'customer_name' ? ($sort_order === 'ASC' ? '-up' : '-down') : ''); ?> ml-1"></i>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'current_balance', 'sort_order' => ($sort_by === 'current_balance' && $sort_order === 'ASC' ? 'DESC' : 'ASC')])); ?>">
                                        الرصيد الحالي <i class="fas fa-sort<?php echo ($sort_by === 'current_balance' ? ($sort_order === 'ASC' ? '-up' : '-down') : ''); ?> ml-1"></i>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'status', 'sort_order' => ($sort_by === 'status' && $sort_order === 'ASC' ? 'DESC' : 'ASC')])); ?>">
                                        الحالة <i class="fas fa-sort<?php echo ($sort_by === 'status' ? ($sort_order === 'ASC' ? '-up' : '-down') : ''); ?> ml-1"></i>
                                    </a>
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    تاريخ الانتهاء
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    الإجراءات
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($cards as $card): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($card['card_number']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($card['customer_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 font-semibold">
                                        <?php echo number_format($card['current_balance'], 2); ?> YER
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                            switch($card['status']) {
                                                case 'active': echo 'bg-green-100 text-green-800'; break;
                                                case 'inactive': echo 'bg-gray-100 text-gray-800'; break;
                                                case 'expired': echo 'bg-red-100 text-red-800'; break;
                                                case 'blocked': echo 'bg-red-100 text-red-800'; break;
                                                default: echo 'bg-blue-100 text-blue-800'; break;
                                            }
                                        ?>">
                                            <?php 
                                                $status_map = ['active' => 'نشط', 'inactive' => 'غير نشط', 'expired' => 'منتهي', 'blocked' => 'محظور'];
                                                echo htmlspecialchars($status_map[$card['status']] ?? $card['status']); 
                                            ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $card['expiry_date'] ? date('Y-m-d', strtotime($card['expiry_date'])) : 'غير محدد'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-left text-sm font-medium">
                                        <div class="flex space-x-2 space-x-reverse">
                                            <a href="view_customer_card.php?id=<?php echo $card['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض"><i class="fas fa-eye"></i></a>
                                            <a href="edit_customer_card.php?id=<?php echo $card['id']; ?>" class="text-yellow-600 hover:text-yellow-900" title="تعديل"><i class="fas fa-edit"></i></a>
                                            <a href="#" onclick="confirmDelete(<?php echo $card['id']; ?>, '<?php echo htmlspecialchars($card['card_number']); ?>')" class="text-red-600 hover:text-red-900" title="حذف"><i class="fas fa-trash-alt"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="p-6 text-center text-gray-500">لا توجد بطاقات عملاء مطابقة للمعايير المحددة.</p>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <nav class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6" aria-label="Pagination">
                <div class="hidden sm:block">
                    <p class="text-sm text-gray-700">
                        عرض
                        <span class="font-medium"><?php echo min($offset + 1, $total_records); ?></span>
                        إلى
                        <span class="font-medium"><?php echo min($offset + $limit, $total_records); ?></span>
                        من
                        <span class="font-medium"><?php echo $total_records; ?></span>
                        نتائج
                    </p>
                </div>
                <div class="flex-1 flex justify-between sm:justify-end">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            السابق
                        </a>
                    <?php endif; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            التالي
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </div>
</div>

<script>
    function confirmDelete(cardId, cardNumber) {
        if (confirm(`هل أنت متأكد أنك تريد حذف البطاقة رقم ${cardNumber}؟ هذا الإجراء لا يمكن التراجع عنه وقد يتطلب تصفير الرصيد أولاً.`)) {
            window.location.href = `index.php?action=delete&id=${cardId}`;
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>