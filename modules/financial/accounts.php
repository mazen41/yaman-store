<?php
// /modules/accounting/accounts.php

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
// require_once '../../includes/check_permissions.php'; // Uncomment if you have this file

$page_title = 'دليل الحسابات';
$success_message = '';
$error_message = '';

// Handle Add/Edit Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? '';
        $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;

        if (empty($code) || empty($name) || empty($type)) {
            throw new Exception('رمز الحساب، اسم الحساب، ونوع الحساب حقول مطلوبة.');
        }

        if ($action == 'add') {
            $stmt = $db->prepare("INSERT INTO accounts (code, name, type, parent_id, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$code, $name, $type, $parent_id, $_SESSION['user_id']]);
            $success_message = 'تم إضافة الحساب بنجاح.';
        } elseif ($action == 'edit') {
            $id = intval($_POST['account_id'] ?? 0);
            if ($id <= 0) throw new Exception('معرف الحساب غير صالح.');

            $stmt = $db->prepare("UPDATE accounts SET code = ?, name = ?, type = ?, parent_id = ? WHERE id = ?");
            $stmt->execute([$code, $name, $type, $parent_id, $id]);
            $success_message = 'تم تحديث الحساب بنجاح.';
        }
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') { // Integrity constraint violation (duplicate entry)
            $error_message = 'خطأ: رمز الحساب موجود بالفعل. يرجى اختيار رمز فريد.';
        } else {
            $error_message = 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage();
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}


// Handle Delete
if (isset($_GET['delete'])) {
    try {
        $id = intval($_GET['delete']);
        // Check if the account is being used in journal_entry_items
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM journal_entry_items WHERE account_id = ?");
        $check_stmt->execute([$id]);
        if ($check_stmt->fetchColumn() > 0) {
            throw new Exception('لا يمكن حذف هذا الحساب لأنه مرتبط بقيود يومية.');
        }

        $stmt = $db->prepare("DELETE FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        $success_message = 'تم حذف الحساب بنجاح.';
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Fetch Accounts with their balances
$accounts_query = "
    SELECT
        a.*,
        p.name as parent_name,
        COALESCE(SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END), 0) as total_debit,
        COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) as total_credit
    FROM
        accounts a
    LEFT JOIN
        accounts p ON a.parent_id = p.id
    LEFT JOIN
        journal_entry_items jei ON a.id = jei.account_id
    GROUP BY
        a.id, p.name
    ORDER BY
        a.code ASC
";
$accounts = $db->query($accounts_query)->fetchAll(PDO::FETCH_ASSOC);

// This query is now only for the dropdown to avoid complexity with the GROUP BY for the main list
$accounts_for_dropdown = $db->query("SELECT id, code, name FROM accounts ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);


// Fetch Stats
$stats = [
    'asset' => 0, 'liability' => 0, 'equity' => 0, 'revenue' => 0, 'expense' => 0
];
// We use the dropdown query for stats to avoid counting issues from the main query join
foreach ($accounts_for_dropdown as $acc) {
    // To get the type, we need to fetch it or use the main $accounts array
    $account_details = array_values(array_filter($accounts, function($a) use ($acc) { return $a['id'] == $acc['id']; }));
    if (!empty($account_details) && isset($stats[$account_details[0]['type']])) {
        $stats[$account_details[0]['type']]++;
    }
}


include '../../includes/header.php';
?>

<!-- AlpineJS for modal functionality -->
<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" x-data="accountPage()">

        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-xl shadow-lg p-6 mb-8 text-white">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold flex items-center gap-3"><i class="fas fa-sitemap"></i> دليل الحسابات</h1>
                    <p class="text-indigo-100 mt-2">إدارة شجرة الحسابات المالية للمؤسسة</p>
                </div>
                <button @click="openModal()" class="bg-white text-indigo-700 px-6 py-2 rounded-lg font-bold hover:bg-indigo-50 transition shadow-md">
                    <i class="fas fa-plus mr-2"></i> إضافة حساب جديد
                </button>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md shadow-sm"><i class="fas fa-check-circle mr-2"></i> <?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-white p-4 rounded-xl shadow-sm border-b-4 border-blue-500 text-center"><h3 class="text-gray-500 font-bold mb-1">الأصول</h3><p class="text-2xl font-bold text-blue-600"><?php echo $stats['asset']; ?></p></div>
            <div class="bg-white p-4 rounded-xl shadow-sm border-b-4 border-red-500 text-center"><h3 class="text-gray-500 font-bold mb-1">الخصوم</h3><p class="text-2xl font-bold text-red-600"><?php echo $stats['liability']; ?></p></div>
            <div class="bg-white p-4 rounded-xl shadow-sm border-b-4 border-green-500 text-center"><h3 class="text-gray-500 font-bold mb-1">حقوق الملكية</h3><p class="text-2xl font-bold text-green-600"><?php echo $stats['equity']; ?></p></div>
            <div class="bg-white p-4 rounded-xl shadow-sm border-b-4 border-purple-500 text-center"><h3 class="text-gray-500 font-bold mb-1">الإيرادات</h3><p class="text-2xl font-bold text-purple-600"><?php echo $stats['revenue']; ?></p></div>
            <div class="bg-white p-4 rounded-xl shadow-sm border-b-4 border-orange-500 text-center"><h3 class="text-gray-500 font-bold mb-1">المصروفات</h3><p class="text-2xl font-bold text-orange-600"><?php echo $stats['expense']; ?></p></div>
        </div>

        <!-- Accounts List -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h2 class="text-lg font-bold text-gray-800">قائمة الحسابات</h2>
                <span class="bg-gray-200 text-gray-700 px-3 py-1 rounded-full text-xs font-bold"><?php echo count($accounts); ?> حساب</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-bold">
                        <tr>
                            <th class="px-6 py-3 text-right">رمز الحساب</th>
                            <th class="px-6 py-3 text-right">اسم الحساب</th>
                            <th class="px-6 py-3 text-right">النوع</th>
                            <th class="px-6 py-3 text-right">الحساب الرئيسي</th>
                            <th class="px-6 py-3 text-right">المدين</th>
                            <th class="px-6 py-3 text-right">الدائن</th>
                            <th class="px-6 py-3 text-right">الرصيد</th>
                            <th class="px-6 py-3 text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($accounts as $acc): ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4 font-mono text-sm font-bold text-gray-600"><?php echo htmlspecialchars($acc['code']); ?></td>
                            <td class="px-6 py-4 font-bold text-gray-800"><?php echo htmlspecialchars($acc['name']); ?></td>
                            <td class="px-6 py-4">
                                <?php
                                $types = [
                                    'asset'     => ['text' => 'أصول', 'class' => 'bg-blue-100 text-blue-700'],
                                    'liability' => ['text' => 'خصوم', 'class' => 'bg-red-100 text-red-700'],
                                    'equity'    => ['text' => 'حقوق ملكية', 'class' => 'bg-green-100 text-green-700'],
                                    'revenue'   => ['text' => 'إيرادات', 'class' => 'bg-purple-100 text-purple-700'],
                                    'expense'   => ['text' => 'مصروفات', 'class' => 'bg-orange-100 text-orange-700'],
                                ];
                                $typeInfo = $types[$acc['type']] ?? ['text' => $acc['type'], 'class' => 'bg-gray-100 text-gray-700'];
                                ?>
                                <span class="px-3 py-1 rounded-full text-xs <?php echo $typeInfo['class']; ?>"><?php echo $typeInfo['text']; ?></span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($acc['parent_name'] ?? '-'); ?></td>
                            <td class="px-6 py-4 text-sm text-green-600 font-mono"><?php echo number_format($acc['total_debit'], 2); ?></td>
                            <td class="px-6 py-4 text-sm text-red-600 font-mono"><?php echo number_format($acc['total_credit'], 2); ?></td>
                            <td class="px-6 py-4 text-sm text-indigo-800 font-bold font-mono">
                                <?php
                                $balance = 0;
                                // Assets and Expenses have a natural Debit balance
                                if (in_array($acc['type'], ['asset', 'expense'])) {
                                    $balance = $acc['total_debit'] - $acc['total_credit'];
                                }
                                // Liabilities, Equity, and Revenue have a natural Credit balance
                                else {
                                    $balance = $acc['total_credit'] - $acc['total_debit'];
                                }
                                echo number_format($balance, 2);
                                ?>
                            </td>
                            <td class="px-6 py-4 text-center flex justify-center gap-2">
                                <button @click="openModal(<?php echo htmlspecialchars(json_encode($acc)); ?>)" class="text-blue-500 hover:text-blue-700 p-1"><i class="fas fa-edit"></i></button>
                                <a href="?delete=<?php echo $acc['id']; ?>" onclick="return confirm('هل أنت متأكد من حذف هذا الحساب؟')" class="text-red-500 hover:text-red-700 p-1"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($accounts)): ?>
                        <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500">لا توجد حسابات مضافة بعد.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add/Edit Modal -->
        <div x-show="isModalOpen" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true" style="display: none;">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div x-show="isModalOpen" @click="isModalOpen = false" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div x-show="isModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="inline-block align-bottom bg-white rounded-lg text-right overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <form method="POST">
                        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                            <h3 class="text-lg leading-6 font-bold text-gray-900 mb-4" id="modal-title" x-text="modalTitle"></h3>
                            <input type="hidden" name="action" :value="formAction">
                            <input type="hidden" name="account_id" :value="formData.id">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-1">رمز الحساب <span class="text-red-500">*</span></label>
                                    <input type="text" name="code" x-model="formData.code" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-1">اسم الحساب <span class="text-red-500">*</span></label>
                                    <input type="text" name="name" x-model="formData.name" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-1">نوع الحساب <span class="text-red-500">*</span></label>
                                    <select name="type" x-model="formData.type" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 bg-white">
                                        <option value="">-- اختر النوع --</option>
                                        <option value="asset">أصول (Assets)</option>
                                        <option value="liability">خصوم (Liabilities)</option>
                                        <option value="equity">حقوق ملكية (Equity)</option>
                                        <option value="revenue">إيرادات (Revenue)</option>
                                        <option value="expense">مصروفات (Expenses)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-1">الحساب الرئيسي</label>
                                    <select name="parent_id" x-model="formData.parent_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 bg-white">
                                        <option value="">-- لا يوجد (حساب رئيسي) --</option>
                                        <?php foreach ($accounts_for_dropdown as $acc_dd): ?>
                                            <option :disabled="formData.id == <?php echo $acc_dd['id']; ?>" value="<?php echo $acc_dd['id']; ?>"><?php echo htmlspecialchars($acc_dd['code'] . ' - ' . $acc_dd['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                            <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm" x-text="modalButtonText"></button>
                            <button @click="isModalOpen = false" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:w-auto sm:text-sm">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function accountPage() {
        return {
            isModalOpen: false,
            modalTitle: 'إضافة حساب جديد',
            modalButtonText: 'حفظ الحساب',
            formAction: 'add',
            formData: {
                id: null,
                code: '',
                name: '',
                type: '',
                parent_id: ''
            },
            openModal(account = null) {
                if (account) {
                    // Editing existing account
                    this.modalTitle = 'تعديل حساب';
                    this.modalButtonText = 'حفظ التعديلات';
                    this.formAction = 'edit';
                    this.formData = {
                        id: account.id,
                        code: account.code,
                        name: account.name,
                        type: account.type,
                        parent_id: account.parent_id || ''
                    };
                } else {
                    // Adding new account
                    this.modalTitle = 'إضافة حساب جديد';
                    this.modalButtonText = 'حفظ الحساب';
                    this.formAction = 'add';
                    this.formData = { id: null, code: '', name: '', type: '', parent_id: '' };
                }
                this.isModalOpen = true;
            }
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>