<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission (assuming permission name is 'bank_accounts')
if (!hasPermission($_SESSION['user_id'], 'bank_accounts', 'add')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للإضافة';
    header('Location: index.php');
    exit();
}

$page_title = 'إضافة حساب بنكي جديد';
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $bank_name = trim($_POST['bank_name'] ?? '');
        $holder_name = trim($_POST['holder_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $iban = trim($_POST['iban'] ?? '');
        $currency = $_POST['currency'] ?? 'SAR';
        $initial_balance = floatval($_POST['initial_balance'] ?? 0);
        
        if (empty($bank_name)) throw new Exception('اسم البنك مطلوب');
        if (empty($holder_name)) throw new Exception('اسم صاحب الحساب مطلوب');
        if (empty($account_number)) throw new Exception('رقم الحساب مطلوب');
        
        $stmt = $db->prepare("INSERT INTO bank_accounts (bank_name, account_holder_name, account_number, iban, currency, initial_balance, current_balance, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)");
        $stmt->execute([
            $bank_name,
            $holder_name,
            $account_number,
            $iban,
            $currency,
            $initial_balance,
            $initial_balance, // Current starts equal to initial
            $_SESSION['user_id']
        ]);
        
        $success_message = 'تم إضافة الحساب البنكي بنجاح';
        header("refresh:2;url=index.php");
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-t-xl shadow-lg p-6 mb-0">
            <h1 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-university"></i>
                إضافة حساب بنكي جديد
            </h1>
            <p class="text-blue-100 mt-2">أدخل تفاصيل الحساب البنكي الجديد</p>
        </div>

        <div class="bg-white rounded-b-xl shadow-lg p-8">
            
            <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                <i class="fas fa-check-circle ml-2"></i>
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                <i class="fas fa-exclamation-circle ml-2"></i>
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-6">
                
                <!-- Bank Name -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">اسم البنك <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <i class="fas fa-building text-gray-400"></i>
                        </div>
                        <input type="text" name="bank_name" required placeholder="مثال: مصرف الراجحي" class="w-full pr-10 pl-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <!-- Account Holder -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">اسم صاحب الحساب <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                        <input type="text" name="holder_name" required placeholder="الاسم كما هو مسجل في البنك" class="w-full pr-10 pl-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Account Number -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">رقم الحساب <span class="text-red-500">*</span></label>
                        <input type="text" name="account_number" required placeholder="رقم الحساب المصرفي" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <!-- IBAN -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">رقم الآيبان (IBAN)</label>
                        <input type="text" name="iban" placeholder="SAXXXXXXXXXXXXXXXXXXXXXX" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Currency -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">عملة الحساب <span class="text-red-500">*</span></label>
                        <select name="currency" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                            <option value="SAR" selected>ريال سعودي (SAR)</option>
                            <option value="USD">دولار أمريكي (USD)</option>
                            <option value="YER">ريال يمني (YER)</option>
                            <option value="EUR">يورو (EUR)</option>
                        </select>
                    </div>

                    <!-- Initial Balance -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">الرصيد الافتتاحي</label>
                        <input type="number" name="initial_balance" step="0.01" value="0.00" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                
                <div class="pt-6 border-t border-gray-100 flex gap-4">
                    <button type="submit" class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-bold shadow-lg hover:shadow-xl transition-all transform hover:-translate-y-1">
                        <i class="fas fa-save ml-2"></i>
                        حفظ الحساب
                    </button>
                    <a href="index.php" class="flex-1 text-center bg-gray-100 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-200 font-bold transition-colors">
                        <i class="fas fa-times ml-2"></i>
                        إلغاء
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
