<?php
// /modules/accounting/journal.php

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/accounting_functions.php'; // We will use the function from our plan

$page_title = 'القيود اليومية';
$success_message = '';
$error_message = '';

// Handle Manual Entry Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_manual_entry') {
    try {
        $db->beginTransaction();

        $entry_date = $_POST['entry_date'] ?? date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        $accounts = $_POST['accounts'] ?? [];
        
        if (empty($description)) {
            throw new Exception('وصف القيد مطلوب.');
        }

        $items = [];
        $total_debit = 0;
        $total_credit = 0;

        foreach ($accounts as $acc) {
            $account_id = intval($acc['id'] ?? 0);
            $debit = floatval($acc['debit'] ?? 0);
            $credit = floatval($acc['credit'] ?? 0);

            if ($account_id > 0 && ($debit > 0 || $credit > 0)) {
                if ($debit > 0) {
                    $items[] = ['account_id' => $account_id, 'type' => 'debit', 'amount' => $debit];
                    $total_debit += $debit;
                }
                if ($credit > 0) {
                    $items[] = ['account_id' => $account_id, 'type' => 'credit', 'amount' => $credit];
                    $total_credit += $credit;
                }
            }
        }

        if (empty($items)) {
            throw new Exception('يجب إضافة بنود للقيد.');
        }

        // Use the function we defined in the plan
        create_journal_entry($db, $entry_date, $description, $items, 'manual', null, $_SESSION['user_id']);
        
        $db->commit();
        $success_message = 'تم إنشاء القيد اليدوي بنجاح.';

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
    }
}


// Fetch Journal Entries
$entries = $db->query("
    SELECT 
        je.id,
        je.entry_date,
        je.description,
        je.source_module,
        je.source_id,
        (SELECT SUM(amount) FROM journal_entry_items WHERE entry_id = je.id AND type = 'debit') as total_amount
    FROM journal_entries je
    ORDER BY je.entry_date DESC, je.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch accounts for the form dropdown
$accounts_list = $db->query("SELECT id, code, name FROM accounts WHERE is_active = 1 ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-teal-600 to-cyan-700 rounded-xl shadow-lg p-6 mb-8 text-white">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold flex items-center gap-3"><i class="fas fa-book-open"></i> القيود اليومية</h1>
                    <p class="text-cyan-100 mt-2">عرض وإدارة جميع الحركات المالية المسجلة</p>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md shadow-sm"><i class="fas fa-check-circle mr-2"></i> <?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Manual Entry Form -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8" x-data="journalEntryForm()">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="fas fa-plus-circle text-teal-600"></i> إضافة قيد يومية يدوي</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_manual_entry">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">تاريخ القيد</label>
                        <input type="date" name="entry_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">الوصف / البيان</label>
                        <input type="text" name="description" placeholder="مثال: سداد فاتورة كهرباء شهر يناير" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                    </div>
                </div>

                <!-- Items Table -->
                <div class="overflow-x-auto border rounded-lg">
                    <table class="min-w-full">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-2 text-right w-2/5">الحساب</th>
                                <th class="p-2 text-right">مدين</th>
                                <th class="p-2 text-right">دائن</th>
                                <th class="p-2 text-center w-12"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, index) in rows" :key="index">
                                <tr class="border-b">
                                    <td class="p-2"><select :name="`accounts[${index}][id]`" class="w-full p-2 border rounded-md bg-white" required><option value="">-- اختر الحساب --</option><?php foreach ($accounts_list as $account): ?><option value="<?php echo $account['id']; ?>">[<?php echo $account['code']; ?>] <?php echo htmlspecialchars($account['name']); ?></option><?php endforeach; ?></select></td>
                                    <td class="p-2"><input type="number" :name="`accounts[${index}][debit]`" x-model.number="row.debit" @input="updateRow(index, 'debit')" step="0.01" min="0" class="w-full p-2 border rounded-md" placeholder="0.00"></td>
                                    <td class="p-2"><input type="number" :name="`accounts[${index}][credit]`" x-model.number="row.credit" @input="updateRow(index, 'credit')" step="0.01" min="0" class="w-full p-2 border rounded-md" placeholder="0.00"></td>
                                    <td class="p-2 text-center"><button type="button" @click="removeRow(index)" x-show="rows.length > 2" class="text-red-500 hover:text-red-700">&times;</button></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="bg-gray-100 font-bold">
                            <tr>
                                <td class="p-2 text-left">الإجمالي</td>
                                <td class="p-2 font-mono" x-text="formatCurrency(totals.debit)"></td>
                                <td class="p-2 font-mono" x-text="formatCurrency(totals.credit)"></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-4 flex justify-between items-center">
                    <button type="button" @click="addRow()" class="bg-gray-200 text-gray-800 px-4 py-2 rounded-lg hover:bg-gray-300"><i class="fas fa-plus mr-2"></i> إضافة سطر</button>
                    <div>
                        <span x-show="!isBalanced()" class="text-red-600 font-semibold ml-4">القيد غير متوازن!</span>
                        <button type="submit" :disabled="!isBalanced() || totals.debit === 0" class="bg-teal-600 text-white px-6 py-2 rounded-lg font-bold hover:bg-teal-700 transition disabled:bg-gray-400 disabled:cursor-not-allowed">
                            <i class="fas fa-save mr-2"></i> حفظ القيد
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Journal Entries List -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
             <div class="px-6 py-4 border-b border-gray-100 bg-gray-50"><h2 class="text-lg font-bold text-gray-800">سجل القيود</h2></div>
             <table class="w-full">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-bold">
                    <tr>
                        <th class="px-6 py-3 text-right">رقم القيد</th>
                        <th class="px-6 py-3 text-right">التاريخ</th>
                        <th class="px-6 py-3 text-right">البيان</th>
                        <th class="px-6 py-3 text-right">المصدر</th>
                        <th class="px-6 py-3 text-right">الإجمالي</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach($entries as $entry): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 font-bold">#<?php echo $entry['id']; ?></td>
                        <td class="px-6 py-4"><?php echo $entry['entry_date']; ?></td>
                        <td class="px-6 py-4"><?php echo htmlspecialchars($entry['description']); ?></td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            <?php 
                                echo $entry['source_module'] === 'manual' ? 'يدوي' : htmlspecialchars($entry['source_module']) . ' #' . $entry['source_id'];
                            ?>
                        </td>
                        <td class="px-6 py-4 font-mono font-semibold"><?php echo number_format($entry['total_amount'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($entries)): ?>
                    <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">لا توجد قيود مسجلة بعد.</td></tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
    </div>
</div>

<script>
    function journalEntryForm() {
        return {
            rows: [{ debit: '', credit: '' }, { debit: '', credit: '' }],
            get totals() {
                return this.rows.reduce((acc, row) => {
                    acc.debit += parseFloat(row.debit || 0);
                    acc.credit += parseFloat(row.credit || 0);
                    return acc;
                }, { debit: 0, credit: 0 });
            },
            isBalanced() {
                return Math.abs(this.totals.debit - this.totals.credit) < 0.01;
            },
            addRow() {
                this.rows.push({ debit: '', credit: '' });
            },
            removeRow(index) {
                if (this.rows.length > 2) this.rows.splice(index, 1);
            },
            updateRow(index, field) {
                if (field === 'debit' && this.rows[index].debit > 0) {
                    this.rows[index].credit = '';
                } else if (field === 'credit' && this.rows[index].credit > 0) {
                    this.rows[index].debit = '';
                }
            },
            formatCurrency(value) {
                return parseFloat(value).toFixed(2);
            }
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>```
