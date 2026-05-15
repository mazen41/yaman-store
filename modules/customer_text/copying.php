<?php
// modules/customer_text/copying.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check for 'view' permission for the calculations page itself
if (!hasPermission($_SESSION['user_id'], 'calculations', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى صفحة الحسابات.';
    header('Location: ../dashboard.php');
    exit();
}

// Determine if the user has 'edit' permission for calculations
$canEditCalculations = hasPermission($_SESSION['user_id'], 'calculations', 'edit');


$page_title = 'حاسبة المحاسبة يمان';
include '../../includes/header.php';

// Initialize variables
$percentage = 11;
$price_sr = '';
$quantity = null;
$cut_date = "2026-02-23";
$amount_paid_yr = '';

$button1_template = '';
$button2_template = '';
$button3_template = '';
$current_exchange_rate = 140;

try {
    $stmt = $db->query("SELECT * FROM calculation_settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($settings) {
        $percentage = (float)$settings['percentage'];
        $price_sr = empty($settings['price_sr']) ? '' : (float)$settings['price_sr'];
        $quantity = empty($settings['quantity']) ? '' : (int)$settings['quantity'];
        $cut_date = $settings['cut_date'];
        $amount_paid_yr = empty($settings['amount_paid_yr']) ? '' : (float)$settings['amount_paid_yr'];
        $button1_template = $settings['button1_text_template'];
        $button2_template = $settings['button2_text_template'];
        $button3_template = $settings['button3_text_template'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_calculation') {
        // Permission check for saving settings
        if (!$canEditCalculations) {
            $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل إعدادات الحسابات.';
            header('Location: calculate_page.php'); // Redirect back to the same page
            exit();
        }

        $percentage = filter_input(INPUT_POST, 'percentage', FILTER_VALIDATE_FLOAT) ?? 0.00;
        $price_sr = filter_input(INPUT_POST, 'price_sr', FILTER_VALIDATE_FLOAT) ?? 0.00;
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT) ?? 0;
        $cut_date = filter_input(INPUT_POST, 'cut_date', FILTER_SANITIZE_STRING);
        $amount_paid_yr = filter_input(INPUT_POST, 'amount_paid_yr', FILTER_VALIDATE_FLOAT) ?? 0.00;

        $button1_template = $_POST['button1_template'] ?? '';
        $button2_template = $_POST['button2_template'] ?? '';
        $button3_template = $_POST['button3_template'] ?? '';

        if ($settings) {
            $update_stmt = $db->prepare("UPDATE calculation_settings SET percentage = ?, price_sr = ?, quantity = ?, cut_date = ?, amount_paid_yr = ?, button1_text_template = ?, button2_text_template = ?, button3_text_template = ? WHERE id = ?");
            $update_stmt->execute([$percentage, $price_sr, $quantity, $cut_date, $amount_paid_yr, $button1_template, $button2_template, $button3_template, $settings['id']]);
        } else {
            $insert_stmt = $db->prepare("INSERT INTO calculation_settings (percentage, price_sr, quantity, cut_date, amount_paid_yr, button1_text_template, button2_text_template, button3_text_template) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->execute([$percentage, $price_sr, $quantity, $cut_date, $amount_paid_yr, $button1_template, $button2_template, $button3_template]);
        }
        $_SESSION['success_message'] = 'تم حفظ الإعدادات بنجاح.';
        header('Location: calculate_page.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
}

$generated_id = 'CALC-' . time();
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap');

    body {
        font-family: 'Tajawal', sans-serif;
        background-color: #f8f9fa;
        direction: rtl;
        margin: 0;
        padding: 0;
        color: #333;
    }

    .app-screen {
        max-width: 450px;
        margin: 0 auto;
        background: white;
        min-height: 100vh;
        position: relative;
        padding-bottom: 80px;
    }

    /* Top Black Header */
    .app-header {
        background-color: #000;
        color: white;
        padding: 40px 20px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        text-align: center;
    }

    .app-header h1 {
        margin: 0;
        font-size: 24px;
        font-weight: bold;
        flex-grow: 1;
    }

    .header-icon {
        font-size: 20px;
        cursor: pointer;
        color: white;
        text-decoration: none;
    }

    /* Input Grid System */
    .content-padding {
        padding: 20px;
    }

    .input-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 20px;
    }

    .input-group {
        display: flex;
        flex-direction: column;
    }

    .input-group label {
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 8px;
        color: #333;
    }

    .field-container {
        display: flex;
        align-items: center;
        border: 2px solid #d1d5db;
        border-radius: 12px;
        height: 55px;
        background: white;
        overflow: hidden;
    }

    .field-container input {
        border: none;
        flex: 1;
        padding: 0 15px;
        font-size: 18px;
        text-align: center;
        outline: none;
        width: 100%;
    }

    /* Add style for readonly inputs */
    .field-container input[readonly] {
        background-color: #e9ecef;
        cursor: not-allowed;
    }

    .icon-box {
        width: 50px;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        border-left: 2px solid #d1d5db;
        color: #6b7280;
        font-size: 18px;
        font-weight: bold;
    }

    /* Full Width Input (Paid Amount) */
    .full-width-group {
        margin-top: 10px;
    }

    .full-width-group .field-container {
        flex-direction: row-reverse;
    }

    .full-width-group .icon-box {
        border-left: none;
        border-right: 2px solid #d1d5db;
    }

    /* Divider with Arrow */
    .divider-container {
        display: flex;
        align-items: center;
        margin: 30px 0;
    }

    .line {
        flex: 1;
        height: 3px;
        background: #000;
    }

    .arrow-circle {
        width: 40px;
        height: 40px;
        border: 3px solid #000;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 10px;
    }

    /* Action Buttons */
    .button-stack {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .action-btn {
        background-color: #000;
        color: white;
        border: none;
        border-radius: 12px;
        padding: 18px;
        font-size: 18px;
        font-weight: bold;
        cursor: pointer;
        width: 100%;
        transition: opacity 0.2s;
    }

    .action-btn:active {
        opacity: 0.8;
    }

    /* Toast Notification (Bottom) */
    .toast-copy {
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background-color: #333;
        color: white;
        padding: 12px 25px;
        border-radius: 8px;
        font-size: 16px;
        display: none;
        z-index: 1000;
        width: 90%;
        max-width: 400px;
        text-align: center;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    /* =========================================
       NEW DESIGN FOR ADMIN SETTINGS (TEMPLATES)
       ========================================= */
    .admin-settings {
        background: #ffffff;
        padding: 20px;
        border-radius: 12px;
        margin-top: 30px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .settings-toggle-btn {
        background: none;
        border: none;
        color: #4f46e5;
        font-size: 16px;
        font-weight: bold;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 5px 0;
        font-family: inherit;
    }

    .template-group {
        margin-top: 20px;
        margin-bottom: 15px;
    }

    .template-group label {
        display: block;
        font-size: 14px;
        font-weight: bold;
        color: #4b5563;
        margin-bottom: 8px;
    }

    .template-textarea {
        width: 100%;
        min-height: 120px; 
        padding: 15px;
        border: 2px solid #e5e7eb;
        border-radius: 10px;
        font-family: inherit;
        font-size: 15px;
        line-height: 1.6;
        color: #1f2937;
        resize: vertical;
        box-sizing: border-box; 
        transition: border-color 0.2s;
    }

    .template-textarea:focus {
        outline: none;
        border-color: #4f46e5;
    }

    .save-settings-btn {
        background-color: #4f46e5;
        color: white;
        border: none;
        border-radius: 10px;
        padding: 15px;
        font-size: 16px;
        font-weight: bold;
        cursor: pointer;
        width: 100%;
        margin-top: 15px;
        transition: background-color 0.2s;
    }

    .save-settings-btn:hover {
        background-color: #4338ca;
    }

    .variables-hint {
        background: #f3f4f6;
        padding: 12px;
        border-radius: 8px;
        font-size: 13px;
        color: #6b7280;
        margin-bottom: 15px;
        line-height: 1.8;
    }
    
    .variables-hint code {
        background: #e5e7eb;
        padding: 2px 5px;
        border-radius: 4px;
        color: #111827;
        font-family: monospace;
    }

    .hidden { display: none !important; }
</style>

<div class="app-screen">
    <!-- Header -->
    <div class="app-header">
        <a href="#" class="header-icon" onclick="clearFields(); return false;"><i class="fas fa-trash"></i></a>
        <h1>حاسبة يمان</h1>
        <div style="width: 24px;"></div> <!-- Spacer for balance -->
    </div>

    <form method="POST" id="calcForm">
        <input type="hidden" name="action" value="save_calculation">

        <div class="content-padding">
            <!-- Row 1: Percentage and Price -->
            <div class="input-row">
                <div class="input-group">
                    <label>النسبة</label>
                    <div class="field-container">
                        <!-- Percentage field is strictly readonly to lock the automated rules -->
                        <input type="number" step="0.01" name="percentage" id="percentage" value="<?php echo $percentage; ?>" readonly>
                        <div class="icon-box">%</div>
                    </div>
                </div>
                <div class="input-group">
                    <label>السعر (SR)</label>
                    <div class="field-container">
                        <input type="number" step="0.01" name="price_sr" id="price_sr" value="<?php echo $price_sr; ?>" <?= $canEditCalculations ? '' : 'readonly' ?>>
                        <div class="icon-box"><i class="fas fa-dollar-sign"></i></div>
                    </div>
                </div>
            </div>

            <!-- Row 2: Date and Pieces -->
            <div class="input-row">
                <div class="input-group">
                    <label>التاريخ</label>
                    <div class="field-container">
                        <input type="text" name="cut_date" id="cut_date" value="<?php echo $cut_date; ?>" <?= $canEditCalculations ? '' : 'readonly' ?>>
                        <div class="icon-box"><i class="far fa-calendar-alt"></i></div>
                    </div>
                </div>
                <div class="input-group">
                    <label>القطع</label>
                    <div class="field-container">
                        <input type="number" name="quantity" id="quantity" placeholder="أدخل عدد القطع" value="<?php echo $quantity; ?>" <?= $canEditCalculations ? '' : 'readonly' ?>>
                        <div class="icon-box"><i class="fas fa-shopping-cart"></i></div>
                    </div>
                </div>
            </div>

            <!-- Full Width: Paid Amount -->
            <div class="full-width-group">
                <label style="font-size: 18px; font-weight: bold; margin-bottom: 8px; display: block;">المبلغ المدفوع</label>
                <div class="field-container" style="flex-direction: row-reverse;">
                    <input type="number" step="0.01" name="amount_paid_yr" id="amount_paid_yr" placeholder="المبلغ المدفوع" value="<?php echo $amount_paid_yr; ?>" <?= $canEditCalculations ? '' : 'readonly' ?>>
                    <div class="icon-box" style="border-right: 2px solid #d1d5db; border-left: none;">YR</div>
                </div>
            </div>

            <!-- Divider -->
            <div class="divider-container">
                <div class="line"></div>
                <div class="arrow-circle">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="line"></div>
            </div>

            <!-- Main Copy Buttons -->
            <div class="button-stack">
                <button type="button" class="action-btn" onclick="copyText('button1')">العميل</button>
                <button type="button" class="action-btn" onclick="copyText('button2')">مندوب SR</button>
                <button type="button" class="action-btn" onclick="copyText('button3')">مندوب YR</button>
            </div>

            <!-- Admin Section (For Templates) - Only visible if user has edit permission -->
            <?php if ($canEditCalculations): ?>
                <div class="admin-settings">
                    <button type="button" onclick="document.getElementById('templates').classList.toggle('hidden')" class="settings-toggle-btn">
                        <i class="fas fa-sliders-h" style="margin-left: 8px;"></i> إعدادات القوالب والحفظ
                    </button>
                    
                    <div id="templates" class="hidden">
                        <div class="template-group">
                            <label>قالب رسالة العميل</label>
                            <textarea name="button1_template" class="template-textarea" placeholder="اكتب قالب رسالة العميل هنا..."><?php echo htmlspecialchars($button1_template); ?></textarea>
                        </div>
                        
                        <div class="template-group">
                            <label>قالب رسالة مندوب SR</label>
                            <textarea name="button2_template" class="template-textarea" placeholder="اكتب قالب رسالة مندوب SR هنا..."><?php echo htmlspecialchars($button2_template); ?></textarea>
                        </div>
                        
                        <div class="template-group">
                            <label>قالب رسالة مندوب YR</label>
                            <textarea name="button3_template" class="template-textarea" placeholder="اكتب قالب رسالة مندوب YR هنا..."><?php echo htmlspecialchars($button3_template); ?></textarea>
                        </div>

                        <!-- Expanded Variables Hint -->
                        <div class="variables-hint">
                            <strong>المتغيرات المتاحة للاستخدام في القوالب:</strong><br>
                            <span dir="ltr">
                                <code>{PRICE_SR}</code> <code>{PRICE_YR}</code> (Total before discount)<br>
                                <code>{DISCOUNT_SR}</code> <code>{DISCOUNT_YR}</code> (Discount amount)<br>
                                <code>{TOTAL_SR}</code> <code>{TOTAL_YR}</code> (Total after discount)<br>
                                <code>{PAID_YR}</code> <code>{AMOUNT_PAID}</code> <code>{AMOUNT_PAID_YR}</code> (Amount paid)<br>
                                <code>{REMAINING_YR}</code> (Remaining YR balance)<br>
                                <code>{PERCENTAGE}</code> <code>{QUANTITY}</code> <code>{CUT_DATE}</code>
                            </span>
                        </div>

                        <button type="submit" class="save-settings-btn">
                            <i class="fas fa-save" style="margin-left: 8px;"></i> حفظ الإعدادات
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Toast -->
<div id="toast" class="toast-copy">تم نسخ رسالة العميل إلى الحافظة</div>

<script>
    const EXCHANGE_RATE = <?php echo $current_exchange_rate; ?>;
    const defaultPercentage = <?php echo $settings['percentage'] ?? 11; ?>; 
    
    // PHP variables mapped directly so variables operate properly even for non-admins
    const phpTemplates = {
        button1: <?php echo json_encode($button1_template); ?>,
        button2: <?php echo json_encode($button2_template); ?>,
        button3: <?php echo json_encode($button3_template); ?>
    };

    // Auto-update discount rules automatically applies for ALL users (Admin/non-Admin alike)
    document.getElementById('price_sr').addEventListener('input', function() {
        const price_sr_val = parseFloat(this.value);
        const percentage_input = document.getElementById('percentage');

        if (price_sr_val === 100) {
            percentage_input.value = 10;
        } else if (price_sr_val === 500) {
            percentage_input.value = 11;
        } else if (price_sr_val === 1000) {
            percentage_input.value = 1;
        } else {
            // Revert to the default percentage if no rule applies
            percentage_input.value = defaultPercentage;
        }
    });

    function copyText(buttonType) {
        const percentage = parseFloat(document.getElementById('percentage').value) || 0;
        const price_sr = parseFloat(document.getElementById('price_sr').value) || 0;
        const quantity = parseInt(document.getElementById('quantity').value) || 0;
        const cut_date = document.getElementById('cut_date').value;
        
        // Calculations
        const discount_amount_sr = price_sr * (percentage / 100);
        const total_sr = price_sr - discount_amount_sr;
        const total_yr = total_sr * EXCHANGE_RATE;
        const price_yr = price_sr * EXCHANGE_RATE;
        const discount_amount_yr = discount_amount_sr * EXCHANGE_RATE;

        let amount_paid_yr_str = document.getElementById('amount_paid_yr').value;
        let amount_paid_yr = parseFloat(amount_paid_yr_str);
        const hasPaidInput = amount_paid_yr_str.trim() !== '' && !isNaN(amount_paid_yr);

        let remaining_yr = hasPaidInput ? (total_yr - amount_paid_yr) : null;
        let paid_yr_display = hasPaidInput ? amount_paid_yr.toFixed(2).replace('.', ',') : ''; // Yemeni formatting (,)
        let remaining_yr_display = hasPaidInput ? remaining_yr.toFixed(2).replace('.', ',') : '';

        // Apply distinct formatting (Saudi = . and Yemeni = ,)
        const price_sr_format = price_sr.toFixed(2);
        const total_sr_format = total_sr.toFixed(2);
        const discount_sr_format = discount_amount_sr.toFixed(2);

        const price_yr_format = price_yr.toFixed(2).replace('.', ',');
        const total_yr_format = total_yr.toFixed(2).replace('.', ',');
        const discount_yr_format = discount_amount_yr.toFixed(2).replace('.', ',');

        let template = "";
        let btnName = "";

        const button1TemplateElem = document.querySelector('textarea[name="button1_template"]');
        const button2TemplateElem = document.querySelector('textarea[name="button2_template"]');
        const button3TemplateElem = document.querySelector('textarea[name="button3_template"]');

        if (buttonType === 'button1') {
            template = button1TemplateElem ? button1TemplateElem.value : phpTemplates.button1;
            if(!template) template = "طلب جديد\nالسعر: {TOTAL_SR} SR\nالمتبقي: {REMAINING_YR} YR";
            btnName = "العميل";
        } else if (buttonType === 'button2') {
            template = button2TemplateElem ? button2TemplateElem.value : phpTemplates.button2;
            if(!template) template = "تقرير المندوب SR: {TOTAL_SR}";
            btnName = "مندوب SR";
        } else if (buttonType === 'button3') {
            template = button3TemplateElem ? button3TemplateElem.value : phpTemplates.button3;
            if(!template) template = "تقرير المندوب YR: {TOTAL_YR}";
            btnName = "مندوب YR";
        }

        // Fully expanded replacements mapping
        const replacements = {
            '{ID}': '<?php echo $generated_id; ?>',
            '{PERCENTAGE}': percentage,
            '{PRICE_SR}': price_sr_format,
            '{PRICE_YR}': price_yr_format,
            '{TOTAL_BEFORE_DISCOUNT_SR}': price_sr_format,
            '{TOTAL_BEFORE_DISCOUNT_YR}': price_yr_format,
            '{DISCOUNT_SR}': discount_sr_format,
            '{DISCOUNT_YR}': discount_yr_format,
            '{QUANTITY}': quantity,
            '{CUT_DATE}': cut_date,
            '{TOTAL_SR}': total_sr_format,
            '{TOTAL_YR}': total_yr_format,
            '{PAID_YR}': paid_yr_display,
            '{AMOUNT_PAID}': paid_yr_display,
            '{AMOUNT_PAID_YR}': paid_yr_display, // <-- تم إضافة هذا السطر لحل المشكلة
            '{REMAINING_YR}': remaining_yr_display
        };

        let finalMsg = template;
        for (const key in replacements) {
            finalMsg = finalMsg.replace(new RegExp(key, 'g'), replacements[key]);
        }

        navigator.clipboard.writeText(finalMsg).then(() => {
            const toast = document.getElementById('toast');
            toast.textContent = `تم نسخ رسالة ${btnName} إلى الحافظة`;
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 2500);
        });
    }

    function clearFields() {
        document.getElementById('percentage').value = defaultPercentage; 
        document.getElementById('price_sr').value = '';
        document.getElementById('quantity').value = '';
        document.getElementById('amount_paid_yr').value = '';
    }
</script>

<?php include '../../includes/footer.php'; ?>