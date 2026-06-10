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

$page_title = 'حاسبه يمان';
include '../../includes/header.php';

// Initialize variables
$percentage  = 11;
$percentage2 = 11;
$percentage3 = 11;
$price_sr = '';
$quantity = null;
$cut_date = "2026-02-23";
$amount_paid_yr = '';

$button1_template = '';
$button2_template = '';
$button3_template = '';
$current_exchange_rate = 140;

try {
    // Ensure per-button percentage columns exist (safe migration)
    foreach (['percentage2', 'percentage3'] as $col) {
        try {
            $db->exec("ALTER TABLE calculation_settings ADD COLUMN `$col` DECIMAL(8,4) NOT NULL DEFAULT 11");
        } catch (PDOException $e) {
            // Column already exists — ignore
        }
    }

    $stmt = $db->query("SELECT * FROM calculation_settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($settings) {
        $percentage  = (float)$settings['percentage'];
        $percentage2 = isset($settings['percentage2']) ? (float)$settings['percentage2'] : $percentage;
        $percentage3 = isset($settings['percentage3']) ? (float)$settings['percentage3'] : $percentage;
        $price_sr    = empty($settings['price_sr'])        ? '' : (float)$settings['price_sr'];
        $quantity    = empty($settings['quantity'])        ? '' : (int)$settings['quantity'];
        $cut_date    = $settings['cut_date'];
        $amount_paid_yr      = empty($settings['amount_paid_yr']) ? '' : (float)$settings['amount_paid_yr'];
        $button1_template    = $settings['button1_text_template'];
        $button2_template    = $settings['button2_text_template'];
        $button3_template    = $settings['button3_text_template'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_calculation') {
        if (!$canEditCalculations) {
            $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل إعدادات الحسابات.';
            header('Location: copying.php');
            exit();
        }

        $percentage  = filter_input(INPUT_POST, 'percentage',  FILTER_VALIDATE_FLOAT) ?? 11;
        $percentage2 = filter_input(INPUT_POST, 'percentage2', FILTER_VALIDATE_FLOAT) ?? 11;
        $percentage3 = filter_input(INPUT_POST, 'percentage3', FILTER_VALIDATE_FLOAT) ?? 11;
        $price_sr    = filter_input(INPUT_POST, 'price_sr',    FILTER_VALIDATE_FLOAT) ?? 0;
        $quantity    = filter_input(INPUT_POST, 'quantity',    FILTER_VALIDATE_INT)   ?? 0;
        $cut_date    = filter_input(INPUT_POST, 'cut_date',    FILTER_SANITIZE_STRING);
        $amount_paid_yr = filter_input(INPUT_POST, 'amount_paid_yr', FILTER_VALIDATE_FLOAT) ?? 0;

        $button1_template = $_POST['button1_template'] ?? '';
        $button2_template = $_POST['button2_template'] ?? '';
        $button3_template = $_POST['button3_template'] ?? '';

        if ($settings) {
            $update_stmt = $db->prepare("UPDATE calculation_settings SET percentage = ?, percentage2 = ?, percentage3 = ?, price_sr = ?, quantity = ?, cut_date = ?, amount_paid_yr = ?, button1_text_template = ?, button2_text_template = ?, button3_text_template = ? WHERE id = ?");
            $update_stmt->execute([$percentage, $percentage2, $percentage3, $price_sr, $quantity, $cut_date, $amount_paid_yr, $button1_template, $button2_template, $button3_template, $settings['id']]);
        } else {
            $insert_stmt = $db->prepare("INSERT INTO calculation_settings (percentage, percentage2, percentage3, price_sr, quantity, cut_date, amount_paid_yr, button1_text_template, button2_text_template, button3_text_template) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_stmt->execute([$percentage, $percentage2, $percentage3, $price_sr, $quantity, $cut_date, $amount_paid_yr, $button1_template, $button2_template, $button3_template]);
        }
        $_SESSION['success_message'] = 'تم حفظ الإعدادات بنجاح.';
        header('Location: copying.php');
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

    .content-padding { padding: 20px; }

    .input-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 20px;
    }

    .input-group { display: flex; flex-direction: column; }

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

    .full-width-group { margin-top: 10px; }

    .full-width-group .field-container { flex-direction: row-reverse; }

    .full-width-group .icon-box {
        border-left: none;
        border-right: 2px solid #d1d5db;
    }

    .divider-container {
        display: flex;
        align-items: center;
        margin: 30px 0;
    }

    .line { flex: 1; height: 3px; background: #000; }

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

    .button-stack { display: flex; flex-direction: column; gap: 12px; }

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

    .action-btn:active { opacity: 0.8; }

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

    .template-group { margin-top: 20px; margin-bottom: 15px; }

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

    .template-textarea:focus { outline: none; border-color: #4f46e5; }

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

    .save-settings-btn:hover { background-color: #4338ca; }

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

    /* Percentage settings row */
    .pct-settings-row {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 10px;
        margin: 15px 0;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 15px;
    }
    .pct-item { display: flex; flex-direction: column; gap: 5px; }
    .pct-item label { font-size: 13px; font-weight: bold; color: #374151; }
    .pct-item .pct-field {
        display: flex;
        align-items: center;
        border: 2px solid #d1d5db;
        border-radius: 8px;
        overflow: hidden;
        height: 42px;
    }
    .pct-item .pct-field input {
        border: none;
        flex: 1;
        padding: 0 8px;
        font-size: 16px;
        font-weight: bold;
        text-align: center;
        outline: none;
        width: 100%;
        color: #1f2937;
    }
    .pct-item .pct-field .pct-icon {
        width: 30px;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        border-right: 2px solid #d1d5db;
        font-weight: bold;
        color: #6b7280;
        font-size: 14px;
    }

    .hidden { display: none !important; }
</style>

<div class="app-screen">
    <!-- Header -->
    <div class="app-header">
        <a href="#" class="header-icon" onclick="clearFields(); return false;"><i class="fas fa-trash"></i></a>
        <h1>حاسبه يمان</h1>
        <div style="width: 24px;"></div>
    </div>

    <form method="POST" id="calcForm">
        <input type="hidden" name="action" value="save_calculation">

        <div class="content-padding">
            <!-- Row 1: Percentage and Price -->
            <div class="input-row">
                <div class="input-group">
                    <label>النسبة (العميل %)</label>
                    <div class="field-container">
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
                <button type="button" class="action-btn" onclick="copyText('button2')">مندوب RS</button>
                <button type="button" class="action-btn" onclick="copyText('button3')">مندوب RY</button>
            </div>

            <!-- Admin Settings Section -->
            <?php if ($canEditCalculations): ?>
                <div class="admin-settings">
                    <button type="button" onclick="document.getElementById('templates').classList.toggle('hidden')" class="settings-toggle-btn">
                        <i class="fas fa-sliders-h" style="margin-left: 8px;"></i> الإعدادات
                    </button>

                    <div id="templates" class="hidden">

                        <!-- Per-button Discount Percentages -->
                        <div style="margin-top: 15px;">
                            <label style="display:block; font-size:15px; font-weight:bold; color:#374151; margin-bottom:8px;">
                                <i class="fas fa-percent" style="margin-left:5px; color:#4f46e5;"></i> نسبة الخصم لكل زر
                            </label>
                            <div class="pct-settings-row">
                                <div class="pct-item">
                                    <label>العميل</label>
                                    <div class="pct-field">
                                        <div class="pct-icon">%</div>
                                        <input type="number" step="0.01" min="0" max="100" name="percentage" id="percentage_setting" value="<?php echo $percentage; ?>">
                                    </div>
                                </div>
                                <div class="pct-item">
                                    <label>مندوب RS</label>
                                    <div class="pct-field">
                                        <div class="pct-icon">%</div>
                                        <input type="number" step="0.01" min="0" max="100" name="percentage2" id="percentage2_setting" value="<?php echo $percentage2; ?>">
                                    </div>
                                </div>
                                <div class="pct-item">
                                    <label>مندوب RY</label>
                                    <div class="pct-field">
                                        <div class="pct-icon">%</div>
                                        <input type="number" step="0.01" min="0" max="100" name="percentage3" id="percentage3_setting" value="<?php echo $percentage3; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Message Templates -->
                        <div class="template-group">
                            <label>قالب رسالة العميل</label>
                            <textarea name="button1_template" class="template-textarea" placeholder="اكتب قالب رسالة العميل هنا..."><?php echo htmlspecialchars($button1_template); ?></textarea>
                        </div>

                        <div class="template-group">
                            <label>قالب رسالة مندوب RS</label>
                            <textarea name="button2_template" class="template-textarea" placeholder="اكتب قالب رسالة مندوب RS هنا..."><?php echo htmlspecialchars($button2_template); ?></textarea>
                        </div>

                        <div class="template-group">
                            <label>قالب رسالة مندوب RY</label>
                            <textarea name="button3_template" class="template-textarea" placeholder="اكتب قالب رسالة مندوب RY هنا..."><?php echo htmlspecialchars($button3_template); ?></textarea>
                        </div>

                        <div class="variables-hint">
                            <strong>المتغيرات المتاحة في القوالب:</strong><br>
                            <span dir="ltr">
                                <code>{PRICE_SR}</code> <code>{PRICE_YR}</code> (السعر قبل الخصم)<br>
                                <code>{DISCOUNT_SR}</code> <code>{DISCOUNT_YR}</code> (مبلغ الخصم)<br>
                                <code>{TOTAL_SR}</code> <code>{TOTAL_YR}</code> (الإجمالي بعد الخصم)<br>
                                <code>{PAID_YR}</code> <code>{AMOUNT_PAID_YR}</code> (المبلغ المدفوع)<br>
                                <code>{REMAINING_YR}</code> (المتبقي)<br>
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
<div id="toast" class="toast-copy">تم النسخ</div>

<script>
    const EXCHANGE_RATE = <?php echo $current_exchange_rate; ?>;

    // Per-button percentages loaded from DB
    const dbPercentages = {
        button1: <?php echo (float)$percentage; ?>,
        button2: <?php echo (float)$percentage2; ?>,
        button3: <?php echo (float)$percentage3; ?>
    };

    const phpTemplates = {
        button1: <?php echo json_encode($button1_template); ?>,
        button2: <?php echo json_encode($button2_template); ?>,
        button3: <?php echo json_encode($button3_template); ?>
    };

    // Keep the main visible percentage field (العميل) in sync with the settings input
    const pctSettingInput = document.getElementById('percentage_setting');
    if (pctSettingInput) {
        pctSettingInput.addEventListener('input', function() {
            document.getElementById('percentage').value = this.value;
        });
    }

    // Auto-update discount rules (price-based triggers) — applies to العميل percentage only
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
            percentage_input.value = dbPercentages.button1;
        }
        if (pctSettingInput) pctSettingInput.value = percentage_input.value;
    });

    function getButtonPercentage(buttonType) {
        if (buttonType === 'button1') {
            return parseFloat(document.getElementById('percentage').value) || 0;
        } else if (buttonType === 'button2') {
            const el = document.getElementById('percentage2_setting');
            return el ? (parseFloat(el.value) || 0) : dbPercentages.button2;
        } else if (buttonType === 'button3') {
            const el = document.getElementById('percentage3_setting');
            return el ? (parseFloat(el.value) || 0) : dbPercentages.button3;
        }
        return 0;
    }

    function copyText(buttonType) {
        const percentage = getButtonPercentage(buttonType);
        const price_sr   = parseFloat(document.getElementById('price_sr').value) || 0;
        const quantity   = parseInt(document.getElementById('quantity').value) || 0;
        const cut_date   = document.getElementById('cut_date').value;

        const discount_amount_sr = price_sr * (percentage / 100);
        const total_sr           = price_sr - discount_amount_sr;
        const total_yr           = total_sr * EXCHANGE_RATE;
        const price_yr           = price_sr * EXCHANGE_RATE;
        const discount_amount_yr = discount_amount_sr * EXCHANGE_RATE;

        const amount_paid_yr_str = document.getElementById('amount_paid_yr').value;
        const amount_paid_yr_val = parseFloat(amount_paid_yr_str);
        const hasPaidInput       = amount_paid_yr_str.trim() !== '' && !isNaN(amount_paid_yr_val);

        const remaining_yr = hasPaidInput ? (total_yr - amount_paid_yr_val) : null;

        // Saudi format (.) / Yemeni format (,)
        const price_sr_format    = price_sr.toFixed(2);
        const total_sr_format    = total_sr.toFixed(2);
        const discount_sr_format = discount_amount_sr.toFixed(2);
        const price_yr_format    = price_yr.toFixed(2).replace('.', ',');
        const total_yr_format    = total_yr.toFixed(2).replace('.', ',');
        const discount_yr_format = discount_amount_yr.toFixed(2).replace('.', ',');

        const paid_yr_display      = hasPaidInput ? amount_paid_yr_val.toFixed(2).replace('.', ',') : '';
        const remaining_yr_display = hasPaidInput ? remaining_yr.toFixed(2).replace('.', ',') : '';

        let template = '';
        let btnName  = '';

        const t1 = document.querySelector('textarea[name="button1_template"]');
        const t2 = document.querySelector('textarea[name="button2_template"]');
        const t3 = document.querySelector('textarea[name="button3_template"]');

        if (buttonType === 'button1') {
            template = t1 ? t1.value : phpTemplates.button1;
            if (!template) template = "طلب جديد\nالسعر: {TOTAL_SR} SR\nالمتبقي: {REMAINING_YR} YR";
            btnName = 'العميل';
        } else if (buttonType === 'button2') {
            template = t2 ? t2.value : phpTemplates.button2;
            if (!template) template = "تقرير مندوب RS: {TOTAL_SR}";
            btnName = 'مندوب RS';
        } else if (buttonType === 'button3') {
            template = t3 ? t3.value : phpTemplates.button3;
            if (!template) template = "تقرير مندوب RY: {TOTAL_YR}";
            btnName = 'مندوب RY';
        }

        // If no paid amount entered, strip paid/remaining placeholders entirely from message
        if (!hasPaidInput) {
            template = template.replace(/\{PAID_YR\}|\{AMOUNT_PAID\}|\{AMOUNT_PAID_YR\}|\{REMAINING_YR\}/g, '');
        }

        const replacements = {
            '{ID}'                       : '<?php echo $generated_id; ?>',
            '{PERCENTAGE}'               : percentage,
            '{PRICE_SR}'                 : price_sr_format,
            '{PRICE_YR}'                 : price_yr_format,
            '{TOTAL_BEFORE_DISCOUNT_SR}' : price_sr_format,
            '{TOTAL_BEFORE_DISCOUNT_YR}' : price_yr_format,
            '{DISCOUNT_SR}'              : discount_sr_format,
            '{DISCOUNT_YR}'              : discount_yr_format,
            '{QUANTITY}'                 : quantity,
            '{CUT_DATE}'                 : cut_date,
            '{TOTAL_SR}'                 : total_sr_format,
            '{TOTAL_YR}'                 : total_yr_format,
            '{PAID_YR}'                  : paid_yr_display,
            '{AMOUNT_PAID}'              : paid_yr_display,
            '{AMOUNT_PAID_YR}'           : paid_yr_display,
            '{REMAINING_YR}'             : remaining_yr_display
        };

        let finalMsg = template;
        for (const key in replacements) {
            finalMsg = finalMsg.replace(new RegExp(key.replace(/[{}]/g, '\\$&'), 'g'), replacements[key]);
        }

        navigator.clipboard.writeText(finalMsg).then(() => {
            const toast = document.getElementById('toast');
            toast.textContent = `تم نسخ رسالة ${btnName}`;
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 2500);
        });
    }

    function clearFields() {
        document.getElementById('percentage').value     = dbPercentages.button1;
        document.getElementById('price_sr').value       = '';
        document.getElementById('quantity').value       = '';
        document.getElementById('amount_paid_yr').value = '';
        if (pctSettingInput) pctSettingInput.value = dbPercentages.button1;
    }

    // NOTE: clearFields() is NOT called on page load.
    // All fields load with their saved DB values.
    // Use the trash icon (top-left) to manually reset all fields.
</script>

<?php include '../../includes/footer.php'; ?>
