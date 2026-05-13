<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إضافة بطاقة هدية جديدة';
$error_message = '';
$success_message = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $card_number = trim($_POST['card_number']);
        $card_password = $_POST['card_password'];
        $card_type = $_POST['card_type'];
        $initial_balance = floatval($_POST['initial_balance']);
        $bonus_balance = floatval($_POST['bonus_balance'] ?? 0);
        $customer_name = trim($_POST['customer_name'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $customer_email = trim($_POST['customer_email'] ?? '');
        $expiry_date = $_POST['expiry_date'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        $promo_code = trim($_POST['promo_code'] ?? '');

        // Validation
        if (empty($card_number)) {
            throw new Exception('رقم البطاقة مطلوب');
        }

        if (empty($card_password)) {
            throw new Exception('كلمة المرور مطلوبة');
        }

        if ($initial_balance < 0) {
            throw new Exception('الرصيد الأولي يجب أن يكون أكبر من أو يساوي صفر');
        }

        // Check if card number already exists
        $check_stmt = $db->prepare("SELECT id FROM loyalty_cards WHERE card_number = ?");
        $check_stmt->execute([$card_number]);
        if ($check_stmt->fetch()) {
            throw new Exception('رقم البطاقة موجود مسبقاً');
        }

        // Apply promo code if provided
        $promo_bonus = 0;
        if (!empty($promo_code)) {
            $promo_stmt = $db->prepare("
                SELECT * FROM loyalty_card_promotions 
                WHERE promo_code = ? 
                AND status = 'active' 
                AND start_date <= CURDATE() 
                AND end_date >= CURDATE()
                AND (usage_limit = 0 OR used_count < usage_limit)
            ");
            $promo_stmt->execute([$promo_code]);
            $promo = $promo_stmt->fetch();

            if ($promo) {
                if ($initial_balance >= $promo['min_purchase_amount']) {
                    if ($promo['promo_type'] == 'bonus_balance') {
                        $promo_bonus = $promo['promo_value'];
                        $bonus_balance += $promo_bonus;
                    } elseif ($promo['promo_type'] == 'percentage') {
                        $discount = ($initial_balance * $promo['promo_value']) / 100;
                        if ($promo['max_discount_amount'] && $discount > $promo['max_discount_amount']) {
                            $discount = $promo['max_discount_amount'];
                        }
                        $bonus_balance += $discount;
                        $promo_bonus = $discount;
                    } elseif ($promo['promo_type'] == 'fixed_amount') {
                        $promo_bonus = $promo['promo_value'];
                        $bonus_balance += $promo_bonus;
                    }
                }
            }
        }

        // Hash password
        $hashed_password = password_hash($card_password, PASSWORD_DEFAULT);

        // Insert card
        $stmt = $db->prepare("
            INSERT INTO loyalty_cards 
            (card_number, card_password, card_type, initial_balance, current_balance, bonus_balance,
             customer_name, customer_phone, customer_email, expiry_date, notes, created_by, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");

        $current_balance = $initial_balance + $bonus_balance;

        $stmt->execute([
            $card_number,
            $hashed_password,
            $card_type,
            $initial_balance,
            $current_balance,
            $bonus_balance,
            $customer_name,
            $customer_phone,
            $customer_email,
            $expiry_date ?: null,
            $notes,
            $_SESSION['user_id']
        ]);

        $card_id = $db->lastInsertId();

        // Create initial transaction
        $trans_stmt = $db->prepare("
            INSERT INTO loyalty_card_transactions 
            (card_id, transaction_type, amount, balance_before, balance_after, description, created_by) 
            VALUES (?, 'purchase', ?, 0, ?, ?, ?)
        ");
        $trans_stmt->execute([
            $card_id,
            $current_balance,
            $current_balance,
            "إنشاء بطاقة جديدة - رصيد أولي: {$initial_balance} + مكافأة: {$bonus_balance}",
            $_SESSION['user_id']
        ]);

        // Record promo usage if applicable
        if ($promo_bonus > 0 && isset($promo)) {
            $promo_usage_stmt = $db->prepare("
                INSERT INTO loyalty_card_promo_usage 
                (card_id, promo_id, discount_amount) 
                VALUES (?, ?, ?)
            ");
            $promo_usage_stmt->execute([$card_id, $promo['id'], $promo_bonus]);

            // Update promo usage count
            $db->prepare("UPDATE loyalty_card_promotions SET used_count = used_count + 1 WHERE id = ?")
              ->execute([$promo['id']]);
        }

        $success_message = 'تم إضافة البطاقة بنجاح!';
        
        // Redirect after 2 seconds
        header("refresh:2;url=view.php?id=$card_id");

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Fetch active promotions
$promos = $db->query("
    SELECT * FROM loyalty_card_promotions 
    WHERE status = 'active' 
    AND start_date <= CURDATE() 
    AND end_date >= CURDATE()
    ORDER BY promo_name
")->fetchAll();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-pink-600 to-purple-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <h1 class="text-3xl font-bold text-white">
                    <i class="fas fa-plus-circle mr-3"></i>
                    إضافة بطاقة هدية جديدة
                </h1>
                <p class="text-pink-100 mt-2">املأ البيانات التالية لإنشاء بطاقة هدية جديدة</p>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="bg-amber-100 border-r-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6 shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-2xl ml-3"></i>
                    <div>
                        <p class="font-medium"><?php echo $success_message; ?></p>
                        <p class="text-sm mt-1">جاري التحويل إلى صفحة البطاقة...</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-2xl ml-3"></i>
                    <p class="font-medium"><?php echo $error_message; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" class="bg-white rounded-xl shadow-lg p-8 space-y-6">
            
            <!-- Card Information -->
            <div class="border-b border-gray-200 pb-6">
                <h3 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-credit-card ml-2 text-pink-600"></i>
                    معلومات البطاقة
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            رقم البطاقة <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="card_number" 
                            required
                            placeholder="مثال: GIFT-2025-001"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                        <p class="text-xs text-gray-500 mt-1">رقم فريد للبطاقة</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            كلمة المرور <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="password" 
                            name="card_password" 
                            required
                            placeholder="أدخل كلمة مرور للبطاقة"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                        <p class="text-xs text-gray-500 mt-1">لحماية البطاقة</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            نوع البطاقة <span class="text-red-500">*</span>
                        </label>
                        <select 
                            name="card_type" 
                            required
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                            <option value="gift">بطاقة هدية</option>
                            <option value="loyalty">بطاقة ولاء</option>
                            <option value="promotional">بطاقة ترويجية</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            تاريخ الانتهاء
                        </label>
                        <input 
                            type="date" 
                            name="expiry_date"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                        <p class="text-xs text-gray-500 mt-1">اختياري</p>
                    </div>
                </div>
            </div>

            <!-- Balance Information -->
            <div class="border-b border-gray-200 pb-6">
                <h3 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-wallet ml-2 text-amber-600"></i>
                    معلومات الرصيد
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            الرصيد الأولي (ريال) <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            name="initial_balance" 
                            step="0.001"
                            min="0"
                            required
                            placeholder="0.000"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            رصيد المكافأة (ريال)
                        </label>
                        <input 
                            type="number" 
                            name="bonus_balance" 
                            step="0.001"
                            min="0"
                            value="0"
                            placeholder="0.000"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                        <p class="text-xs text-gray-500 mt-1">رصيد إضافي مجاني</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-tag ml-1 text-purple-600"></i>
                            كود العرض الترويجي
                        </label>
                        <select 
                            name="promo_code"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                            <option value="">-- بدون عرض --</option>
                            <?php foreach ($promos as $promo): ?>
                                <option value="<?php echo htmlspecialchars($promo['promo_code']); ?>">
                                    <?php echo htmlspecialchars($promo['promo_name']); ?> 
                                    (<?php echo htmlspecialchars($promo['promo_code']); ?>)
                                    - 
                                    <?php 
                                    if ($promo['promo_type'] == 'percentage') {
                                        echo $promo['promo_value'] . '% خصم';
                                    } elseif ($promo['promo_type'] == 'bonus_balance') {
                                        echo '+' . $promo['promo_value'] . ' ريال مكافأة';
                                    } else {
                                        echo $promo['promo_value'] . ' ريال خصم';
                                    }
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">سيتم تطبيق العرض تلقائياً إذا كان مؤهلاً</p>
                    </div>
                </div>
            </div>

            <!-- Customer Information -->
            <div class="border-b border-gray-200 pb-6">
                <h3 class="text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-user ml-2 text-blue-600"></i>
                    معلومات العميل
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            اسم العميل
                        </label>
                        <input 
                            type="text" 
                            name="customer_name"
                            placeholder="الاسم الكامل"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            رقم الهاتف
                        </label>
                        <input 
                            type="tel" 
                            name="customer_phone"
                            placeholder="05xxxxxxxx"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            البريد الإلكتروني
                        </label>
                        <input 
                            type="email" 
                            name="customer_email"
                            placeholder="email@example.com"
                            class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                        >
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-sticky-note ml-1 text-yellow-600"></i>
                    ملاحظات
                </label>
                <textarea 
                    name="notes" 
                    rows="3"
                    placeholder="أي ملاحظات إضافية..."
                    class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500"
                ></textarea>
            </div>

            <!-- Submit Buttons -->
            <div class="flex gap-4 pt-4">
                <button 
                    type="submit" 
                    class="flex-1 bg-gradient-to-r from-pink-600 to-purple-600 text-white px-6 py-3 rounded-lg font-bold hover:from-pink-700 hover:to-purple-700 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105"
                >
                    <i class="fas fa-save ml-2"></i>
                    حفظ البطاقة
                </button>
                <a 
                    href="index.php" 
                    class="flex-1 bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-bold hover:bg-gray-300 transition-all duration-300 text-center"
                >
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </a>
            </div>
        </form>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>
