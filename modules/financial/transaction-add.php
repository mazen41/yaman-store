<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
// --- EDIT: Include the accounting functions file, which contains create_journal_entry() ---
require_once '../../includes/accounting_functions.php'; 

$page_title = 'معاملة مالية جديدة';
$success_message = '';
$error_message = '';

// Fetch active accounts for the form dropdowns
try {
    $accounts = $db->query("
        SELECT id, code, name 
        FROM accounts 
        WHERE is_active = 1 
        ORDER BY code
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $accounts = [];
    $error_message = "خطأ في تحميل الحسابات: " . $e->getMessage();
}

// =================================================================
// START: REFACTORED POST HANDLER (Logic copied from journal.php)
// =================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (empty($accounts)) {
        $error_message = 'لا يمكن إنشاء معاملة لعدم وجود حسابات نشطة. يرجى مراجعة شجرة الحسابات.';
    } else {
        try {
            $db->beginTransaction();

            $entry_date = $_POST['transaction_date'] ?? date('Y-m-d');
            $description = trim($_POST['description'] ?? '');
            $reference_type = $_POST['reference_type'] ?? 'other'; // Source Module
            $reference_number = $_POST['reference_number'] ?? null; // Source ID
            $form_accounts = $_POST['accounts'] ?? [];
            
            if (empty($description)) {
                throw new Exception('وصف المعاملة مطلوب.');
            }

            $items = [];
            $total_debit = 0;
            $total_credit = 0;

            // Process the submitted account lines from the Alpine.js form
            foreach ($form_accounts as $acc) {
                $account_id = intval($acc['id'] ?? 0);
                $debit = floatval($acc['debit'] ?? 0);
                $credit = floatval($acc['credit'] ?? 0);
                $item_description = trim($acc['description'] ?? '');

                if ($account_id > 0 && ($debit > 0 || $credit > 0)) {
                    if ($debit > 0) {
                        $items[] = ['account_id' => $account_id, 'type' => 'debit', 'amount' => $debit, 'description' => $item_description];
                        $total_debit += $debit;
                    }
                    if ($credit > 0) {
                        $items[] = ['account_id' => $account_id, 'type' => 'credit', 'amount' => $credit, 'description' => $item_description];
                        $total_credit += $credit;
                    }
                }
            }

            if (empty($items)) {
                throw new Exception('يجب إضافة بنود للقيد.');
            }

            // Server-side balance check (important!)
            if (abs($total_debit - $total_credit) > 0.01) {
                throw new Exception('القيد غير متوازن. لا يمكن الحفظ.');
            }

            // Use the centralized function to create the journal entry
            $entry_id = create_journal_entry(
                $db, 
                $entry_date, 
                $description, 
                $items, 
                $reference_type, 
                $reference_number, 
                $_SESSION['user_id']
            );
            
            $db->commit();
            $success_message = "تم إضافة المعاملة المالية بنجاح. رقم القيد المحاسبي: #" . $entry_id;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error_message = 'حدث خطأ: ' . $e->getMessage();
        }
    }
}
// =================================================================
// END: REFACTORED POST HANDLER
// =================================================================

include '../../includes/header.php';
?>

<!-- EDIT: Add Alpine.js script -->
<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">
                            <i class="fas fa-plus-circle ml-2 text-yellow-600"></i>
                            معاملة مالية جديدة
                        </h1>
                        <p class="text-gray-600 mt-1">إضافة قيد محاسبي جديد</p>
                    </div>
                    <div class="mt-4 sm:mt-0">
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($success_message)): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded mb-6">
            <i class="fas fa-check-circle ml-2"></i><?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i><?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <!-- EDIT: Add x-data to the form wrapper div -->
        <div class="bg-white shadow rounded-lg p-6" x-data="transactionForm()">
            <form method="POST">
                
                <!-- Basic Information (No major changes here) -->
                <div class="mb-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4 border-b pb-2">
                        <i class="fas fa-info-circle ml-2 text-blue-600"></i>
                        المعلومات الأساسية
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">تاريخ المعاملة</label>
                            <input type="date" name="transaction_date" required value="<?php echo date('Y-m-d'); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">نوع المعاملة</label>
                            <select name="reference_type" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                                <option value="sale">مبيعات</option>
                                <option value="purchase">مشتريات</option>
                                <option value="payment">دفعة</option>
                                <option value="receipt">إيصال</option>
                                <option value="expense">مصروف</option>
                                <option value="adjustment">تسوية</option>
                                <option value="transfer">تحويل</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">رقم المرجع (اختياري)</label>
                            <input type="text" name="reference_number"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">الوصف العام</label>
                            <input type="text" name="description" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                        </div>
                    </div>
                </div>

                <!-- Transaction Details -->
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <i class="fas fa-list ml-2 text-amber-600"></i>
                            تفاصيل القيد
                        </h2>
                        <!-- EDIT: Button now calls Alpine.js function -->
                        <button type="button" @click="addRow()" 
                                class="px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i>إضافة حساب
                        </button>
                    </div>
                    
                    <div class="overflow-x-auto border rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase w-2/5">الحساب</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">مدين</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">دائن</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">وصف البند</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-12"></th>
                                </tr>
                            </thead>
                            <!-- ================================================================= -->
                            <!-- START: EDIT - Replaced static table body with Alpine.js template  -->
                            <!-- ================================================================= -->
                            <tbody>
                                <template x-for="(row, index) in rows" :key="index">
                                    <tr class="border-b">
                                        <td class="px-2 py-2">
                                            <select :name="`accounts[${index}][id]`" class="w-full p-2 border rounded-md bg-white" required>
                                                <option value="">-- اختر الحساب --</option>
                                                <?php foreach ($accounts as $account): ?>
                                                <option value="<?php echo $account['id']; ?>">
                                                    [<?php echo $account['code']; ?>] <?php echo htmlspecialchars($account['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="px-2 py-2">
                                            <input type="number" :name="`accounts[${index}][debit]`" x-model.number="row.debit" @input="updateRow(index, 'debit')" step="0.01" min="0" class="w-full p-2 border rounded-md" placeholder="0.00">
                                        </td>
                                        <td class="px-2 py-2">
                                            <input type="number" :name="`accounts[${index}][credit]`" x-model.number="row.credit" @input="updateRow(index, 'credit')" step="0.01" min="0" class="w-full p-2 border rounded-md" placeholder="0.00">
                                        </td>
                                        <td class="px-2 py-2">
                                            <input type="text" :name="`accounts[${index}][description]`" class="w-full p-2 border rounded-md" placeholder="وصف السطر (اختياري)">
                                        </td>
                                        <td class="px-2 py-2 text-center">
                                            <button type="button" @click="removeRow(index)" x-show="rows.length > 2" class="text-red-500 hover:text-red-700 text-xl">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <!-- ================================================================= -->
                            <!-- END: EDIT                                                         -->
                            <!-- ================================================================= -->
                            <tfoot class="bg-gray-100 font-bold">
                                <tr>
                                    <td class="px-4 py-3 text-left">الإجمالي:</td>
                                    <td class="px-4 py-3 font-mono" x-text="formatCurrency(totals.debit)"></td>
                                    <td class="px-4 py-3 font-mono" x-text="formatCurrency(totals.credit)"></td>
                                    <td class="px-4 py-3" colspan="2">
                                        <div class="text-center font-bold">
                                            <span x-show="isBalanced() && totals.debit > 0" class="text-amber-600"><i class="fas fa-check-circle ml-1"></i> متوازن</span>
                                            <span x-show="!isBalanced()" class="text-red-600"><i class="fas fa-exclamation-circle ml-1"></i> غير متوازن</span>
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="flex gap-4">
                     <!-- EDIT: Submit button is now controlled by Alpine.js -->
                    <button type="submit" :disabled="!isBalanced() || totals.debit === 0"
                            class="flex-1 bg-yellow-600 text-white px-6 py-3 rounded-lg hover:bg-yellow-700 transition duration-200 font-bold disabled:bg-gray-400 disabled:cursor-not-allowed">
                        <i class="fas fa-save ml-2"></i>حفظ المعاملة
                    </button>
                    <a href="index.php" 
                       class="flex-1 text-center bg-gray-600 text-white px-6 py-3 rounded-lg hover:bg-gray-700 transition duration-200 font-bold">
                        <i class="fas fa-times ml-2"></i>إلغاء
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================= -->
<!-- START: EDIT - Added Alpine.js component logic, removed old JS   -->
<!-- ================================================================= -->
<script>
    function transactionForm() {
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
                // Use a small epsilon for floating point comparison
                return Math.abs(this.totals.debit - this.totals.credit) < 0.01;
            },
            addRow() {
                this.rows.push({ debit: '', credit: '' });
            },
            removeRow(index) {
                if (this.rows.length > 2) {
                    this.rows.splice(index, 1);
                }
            },
            updateRow(index, field) {
                // When a value is entered in one field (debit/credit), clear the other
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
<!-- ================================================================= -->
<!-- END: EDIT                                                         -->
<!-- ================================================================= -->

<?php include '../../includes/footer.php'; ?>