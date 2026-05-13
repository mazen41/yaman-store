
<?php
// /modules/accounting/settings.php

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
// require_once '../../includes/check_permissions.php'; // Add permission checks as needed

$page_title = 'إعدادات الربط المحاسبي';
$success_message = '';
$error_message = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_settings') {
    try {
        $settings_to_save = $_POST['settings'] ?? [];
        
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            INSERT INTO accounting_settings (setting_key, setting_value) 
            VALUES (:key, :value) 
            ON DUPLICATE KEY UPDATE setting_value = :value
        ");
        
        foreach ($settings_to_save as $key => $value) {
            // Only save if a value is selected
            if (!empty($value)) {
                $stmt->bindParam(':key', $key);
                $stmt->bindParam(':value', $value);
                $stmt->execute();
            }
        }
        
        $db->commit();
        $success_message = 'تم حفظ الإعدادات بنجاح!';
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = 'فشل حفظ الإعدادات: ' . $e->getMessage();
    }
}

// Fetch all accounts for dropdowns
$accounts_list = $db->query("SELECT id, code, name FROM accounts WHERE is_active = 1 ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch current settings
$current_settings_raw = $db->query("SELECT setting_key, setting_value FROM accounting_settings")->fetchAll(PDO::FETCH_ASSOC);
$current_settings = array_column($current_settings_raw, 'setting_value', 'setting_key');

// Define the settings fields
$settings_fields = [
    'Sales & Receivables' => [
        'default_accounts_receivable_id' => ['label' => 'حساب العملاء (الذمم المدينة)', 'icon' => 'fa-users'],
        'default_sales_revenue_id' => ['label' => 'حساب إيرادات المبيعات', 'icon' => 'fa-chart-line'],
        'default_shipping_revenue_id' => ['label' => 'حساب إيرادات الشحن', 'icon' => 'fa-shipping-fast'],
        'default_sales_discount_id' => ['label' => 'حساب الخصم المسموح به', 'icon' => 'fa-percent'],
    ],
    'Purchases & Payables' => [
        'default_purchases_account_id' => ['label' => 'حساب المشتريات / المخزون', 'icon' => 'fa-boxes'],
        'default_accounts_payable_id' => ['label' => 'حساب الموردين (الذمم الدائنة)', 'icon' => 'fa-handshake'],
        'default_shipping_expense_id' => ['label' => 'حساب مصروفات شحن المشتريات', 'icon' => 'fa-truck-loading'],
        'default_purchase_card_asset_id' => ['label' => 'حساب أصل بطاقات الشراء', 'icon' => 'fa-credit-card'],
    ],
    'Cash & Banks' => [
        'default_cash_account_id' => ['label' => 'حساب الصندوق (الكاش)', 'icon' => 'fa-cash-register'],
        // You can add more for specific banks if needed
    ],
     'Shipping & Expenses' => [
        'default_shipping_expense_account_id' => ['label' => 'حساب مصروفات الشحن', 'icon' => 'fa-truck'],
        'default_shipping_payment_account_id' => ['label' => 'حساب سداد تكلفة الشحن (الصندوق أو البنك)', 'icon' => 'fa-money-bill-wave'],
    ],
];

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-pink-600 rounded-xl shadow-lg p-6 mb-8 text-white">
            <div>
                <h1 class="text-3xl font-bold flex items-center gap-3"><i class="fas fa-cogs"></i> إعدادات الربط المحاسبي</h1>
                <p class="text-pink-100 mt-2">اربط العمليات التجارية بحساباتها المالية لأتمتة القيود اليومية.</p>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md shadow-sm"><i class="fas fa-check-circle mr-2"></i> <?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="save_settings">

            <?php foreach($settings_fields as $group_name => $fields): ?>
            <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
                <h2 class="text-xl font-bold text-gray-800 mb-6 pb-3 border-b"><?php echo $group_name; ?></h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                    <?php foreach($fields as $key => $field): ?>
                    <div>
                        <label for="<?php echo $key; ?>" class="block text-sm font-bold text-gray-700 mb-2 flex items-center gap-2">
                            <i class="fas <?php echo $field['icon']; ?> text-purple-500"></i>
                            <?php echo $field['label']; ?> <span class="text-red-500">*</span>
                        </label>
                        <select id="<?php echo $key; ?>" name="settings[<?php echo $key; ?>]" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 bg-white" required>
                            <option value="">-- اختر الحساب --</option>
                            <?php foreach ($accounts_list as $account): ?>
                                <option value="<?php echo $account['id']; ?>" <?php echo (isset($current_settings[$key]) && $current_settings[$key] == $account['id']) ? 'selected' : ''; ?>>
                                    [<?php echo htmlspecialchars($account['code']); ?>] <?php echo htmlspecialchars($account['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="bg-white rounded-xl shadow-lg p-4 sticky bottom-4">
                <button type="submit" class="w-full bg-purple-600 text-white py-3 rounded-lg font-bold hover:bg-purple-700 transition shadow-md">
                    <i class="fas fa-save mr-2"></i>
                    حفظ الإعدادات
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>