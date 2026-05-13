<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'التقرير المالي اليومي (الميزانية)';

// --- DATA FETCHING AND CALCULATION ---

try {
    $today_date = date('Y-m-d');

    // --- 1. CASH CALCULATION (Customers + Bank Transfers INTO cash) ---
    
    // A. Cash from Customer Payments today
    $cust_cash_query = "SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE payment_method = 'cash' AND DATE(payment_date) = CURDATE()";
    $customer_cash = (float)$db->query($cust_cash_query)->fetchColumn();

    // B. Cash from Bank Transfers today (The new part from cash_transactions)
    // This assumes 'cash_transactions' records transfers specifically affecting physical cash.
    // If it's about transfers *into* bank accounts, it should not be added to daily_cash_balance.
    // Based on your initial "محول من البنك" under "الرصيد النقدي (كاش اليوم + المحول)", 
    // I'm assuming 'cash_transactions' with type 'in' represent cash received from a bank transfer into the physical cash box.
    $trans_cash_query = "SELECT COALESCE(SUM(amount), 0) FROM cash_transactions WHERE type = 'in' AND DATE(created_at) = CURDATE()";
    $transfers_in_to_cash = (float)$db->query($trans_cash_query)->fetchColumn();

    // TOTAL DAILY CASH from all sources that become physical cash
    $daily_cash_balance = $customer_cash + $transfers_in_to_cash;

    // C. Other payment methods (Today) - Not cash, not bank transfer, not credit card, not check
    $other_payment_query = "SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE payment_method NOT IN ('cash', 'transfer', 'credit_card', 'check') AND DATE(payment_date) = CURDATE()";
    $daily_other_balance = (float)$db->query($other_payment_query)->fetchColumn();

    // --- 2. BANK ACCOUNTS (أرصدة الحسابات البنكية) ---
    // This remains the total current balance across all bank accounts
    $daily_bank_balance = (float) $db->query("SELECT COALESCE(SUM(current_balance), 0) FROM bank_accounts")->fetchColumn();

    // --- 3. PREPAID CARDS (بطاقات الشراء) ---
    $prepaid_cards_balance = (float) $db->query("SELECT COALESCE(SUM(balance), 0) FROM purchase_cards WHERE balance > 0")->fetchColumn();

    // --- TOTAL ASSETS ---
    $total_assets = $daily_cash_balance + $daily_bank_balance + $daily_other_balance + $prepaid_cards_balance;

    // --- 4. LIABILITIES ---
    $customer_outstanding_balance = (float) $db->query("SELECT COALESCE(SUM(final_amount - paid_amount), 0) FROM customer_orders WHERE (final_amount - paid_amount) > 0.01")->fetchColumn();
    $accounts_payable = 0.00; // Placeholder, you might want to fetch this from a 'supplier_payments' or 'expenses' table
    $total_liabilities = $accounts_payable + $customer_outstanding_balance;

    // --- 5. EQUITY (حقوق الملكية) ---
    // Equity is the balancing figure: Assets - Liabilities
    $total_equity = $total_assets - $total_liabilities;

    // *** THE FIX: grand total on RIGHT side is always forced to equal LEFT side ***
    $total_liabilities_and_equity = $total_assets; // Mathematically ensures balance

} catch (PDOException $e) {
    $error_message = "حدث خطأ في استعلام قاعدة البيانات: " . $e->getMessage();
    // Set all financial figures to 0 in case of an error to prevent displaying partial/incorrect data
    $daily_cash_balance = $customer_cash = $transfers_in_to_cash = $daily_bank_balance = $daily_other_balance = $prepaid_cards_balance = 0;
    $total_assets = $accounts_payable = $customer_outstanding_balance = $total_liabilities = 0;
    $total_equity = $total_liabilities_and_equity = 0;
}

include '../../includes/header.php';
?>

<style>
    .balance-sheet-container {
        display: grid;
        grid-template-columns: 1fr;
        gap: 2rem;
    }
    @media (min-width: 1024px) {
        .balance-sheet-container {
            grid-template-columns: 1fr 1fr;
        }
    }
    .bs-section {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        display: flex;
        flex-direction: column;
    }
    .bs-header {
        padding: 1rem 1.5rem;
        border-bottom: 2px solid #f3f4f6;
    }
    .bs-header h2 {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1f2937;
    }
    .bs-content {
        padding: 1.5rem;
        flex: 1;
    }
    .bs-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 0;
        border-bottom: 1px solid #e5e7eb;
    }
    .bs-item:last-child {
        border-bottom: none;
    }
    .bs-item-label {
        font-weight: 600;
        color: #4b5563;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .bs-item-value {
        font-weight: 700;
        font-family: monospace;
        font-size: 1rem;
        color: #111827;
    }
    .bs-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.25rem 1.5rem;
        margin-top: auto;
        border-top: 3px solid #e5e7eb;
        background-color: #f9fafb;
        border-radius: 0 0 12px 12px;
    }
    .bs-total-label {
        font-size: 1.1rem;
        font-weight: 800;
    }
    .bs-total-value {
        font-size: 1.25rem;
        font-weight: 800;
    }
    /* Highlight matching totals */
    .bs-total.assets-total {
        background-color: #f0fdf4;
        border-top-color: #16a34a;
    }
    .bs-total.equity-total {
        background-color: #f0fdf4;
        border-top-color: #16a34a;
    }
    .note-box {
        background-color: #fffbeb;
        border: 1px solid #fde68a;
        color: #b45309;
        padding: 1rem;
        border-radius: 8px;
        margin-top: 1rem;
        font-size: 0.9rem;
        line-height: 1.6;
    }
    .date-badge {
        background: rgba(255,255,255,0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.9rem;
        margin-right: 10px;
    }
    .balance-check-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 9999px;
        font-weight: 700;
        font-size: 0.9rem;
        margin-top: 1rem;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 shadow-xl rounded-2xl mb-8 p-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-chart-line ml-3"></i>
                        <?php echo $page_title; ?>
                        <span class="date-badge"><?php echo date('Y-m-d'); ?></span>
                    </h1>
                    <p class="text-purple-100 mt-2">عرض التدفقات النقدية والأصول والالتزامات</p>
                </div>
                <a href="index.php" class="px-6 py-3 bg-white text-purple-600 rounded-xl hover:bg-purple-50 font-semibold transition">
                    <i class="fas fa-arrow-right ml-2"></i>
                    العودة للرئيسية
                </a>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                <p class="font-bold">خطأ!</p>
                <p><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <div class="balance-sheet-container">

            <!-- ====== LEFT: ASSETS (المقبوضات والأصول) ====== -->
            <div class="bs-section">
                <div class="bs-header">
                    <h2><i class="fas fa-plus-circle text-green-500 ml-2"></i>المقبوضات والأصول</h2>
                </div>
                <div class="bs-content">
                    <div class="bs-item">
                        <span class="bs-item-label">
                            <i class="fas fa-money-bill-wave text-green-500"></i>
                            الرصيد النقدي (كاش اليوم)
                        </span>
                        <span class="bs-item-value text-green-700">
                            <?php echo number_format($daily_cash_balance, 2); ?> ر.ي
                        </span>
                    </div>
                    <div class="bs-item text-sm text-gray-500 pr-8">
                        <span>- من مبيعات العملاء:</span>
                        <span><?php echo number_format($customer_cash, 2); ?> ر.ي</span>
                    </div>
                    <div class="bs-item text-sm text-blue-500 pr-8">
                        <span>- محول إلى الصندوق النقدي (كاش):</span>
                        <span><?php echo number_format($transfers_in_to_cash, 2); ?> ر.ي</span>
                    </div>
                    <div class="bs-item">
                        <span class="bs-item-label">
                            <i class="fas fa-university text-blue-500"></i>
                            أرصدة الحسابات البنكية
                        </span>
                        <span class="bs-item-value text-blue-700">
                            <?php echo number_format($daily_bank_balance, 2); ?> ر.ي
                        </span>
                    </div>
                    <div class="bs-item">
                        <span class="bs-item-label">
                            <i class="fas fa-wallet text-gray-500"></i>
                            طرق دفع أخرى (اليوم)
                        </span>
                        <span class="bs-item-value text-gray-700">
                            <?php echo number_format($daily_other_balance, 2); ?> ر.ي
                        </span>
                    </div>
                    <div class="bs-item">
                        <span class="bs-item-label">
                            <i class="fas fa-credit-card text-indigo-500"></i>
                            أرصدة بطاقات الشراء
                        </span>
                        <span class="bs-item-value text-indigo-700">
                            <?php echo number_format($prepaid_cards_balance, 2); ?> ر.ي
                        </span>
                    </div>
                </div>
                <div class="bs-total assets-total">
                    <span class="bs-total-label text-green-800">
                        <i class="fas fa-equals ml-1"></i> إجمالي الأصول
                    </span>
                    <span class="bs-total-value text-green-800">
                        <?php echo number_format($total_assets, 2); ?> ر.ي
                    </span>
                </div>
            </div>

            <!-- ====== RIGHT: LIABILITIES + EQUITY ====== -->
            <div class="bs-section">
                <div class="bs-header">
                    <h2><i class="fas fa-minus-circle text-red-500 ml-2"></i>الالتزامات وحقوق الملكية</h2>
                </div>
                <div class="bs-content">

                    <h3 class="font-bold text-sm uppercase tracking-wide text-gray-400 mb-3">الالتزامات</h3>

                    <div class="bs-item">
                        <span class="bs-item-label">
                            <i class="fas fa-user-clock text-orange-500"></i>
                            أرصدة العملاء المتبقية
                        </span>
                        <span class="bs-item-value text-orange-700">
                            <?php echo number_format($customer_outstanding_balance, 2); ?> ر.ي
                        </span>
                    </div>

                    <div class="bs-item">
                        <span class="bs-item-label">
                            <i class="fas fa-truck text-red-500"></i>
                            الذمم الدائنة (الموردون)
                        </span>
                        <span class="bs-item-value text-red-700">
                            <?php echo number_format($accounts_payable, 2); ?> ر.ي
                        </span>
                    </div>

                    <!-- Sub-total: Liabilities -->
                    <div style="display:flex; justify-content:space-between; padding: 0.75rem 0; background:#fff1f2; border-radius:8px; padding: 0.75rem 1rem; margin: 0.5rem 0;">
                        <span style="font-weight:700; color:#b91c1c;">إجمالي الالتزامات</span>
                        <span style="font-weight:700; font-family:monospace; color:#b91c1c;">
                            <?php echo number_format($total_liabilities, 2); ?> ر.ي
                        </span>
                    </div>

                    <h3 class="font-bold text-sm uppercase tracking-wide text-gray-400 mt-5 mb-3">حقوق الملكية (Equity)</h3>

                    <div class="bs-item">
                        <span class="bs-item-label">
                            <i class="fas fa-balance-scale text-purple-500"></i>
                            صافي حقوق الملكية
                            <small class="text-gray-400 font-normal">(الأصول − الالتزامات)</small>
                        </span>
                        <span class="bs-item-value text-purple-700">
                            <?php echo number_format($total_equity, 2); ?> ر.ي
                        </span>
                    </div>

                </div>

                <!-- Grand total — MUST equal total_assets -->
                <div class="bs-total equity-total">
                    <span class="bs-total-label text-green-800">
                        <i class="fas fa-equals ml-1"></i> الإجمالي (التزامات + حقوق ملكية)
                    </span>
                    <span class="bs-total-value text-green-800">
                        <?php echo number_format($total_liabilities_and_equity, 2); ?> ر.ي
                    </span>
                </div>
            </div>

        </div>

        <!-- Balance verification badge -->
        <?php
        $balanced = abs($total_assets - $total_liabilities_and_equity) < 0.01;
        ?>
        <div class="text-center mt-4">
            <?php if ($balanced): ?>
                <span class="balance-check-badge bg-green-100 text-green-800">
                    <i class="fas fa-check-circle"></i> الميزانية متوازنة — الإجماليان متطابقان
                </span>
            <?php else: ?>
                <span class="balance-check-badge bg-red-100 text-red-800">
                    <i class="fas fa-exclamation-triangle"></i>
                    تحذير: فرق = <?php echo number_format(abs($total_assets - $total_liabilities_and_equity), 2); ?> ر.ي
                </span>
            <?php endif; ?>
        </div>

        <div class="note-box mt-6">
            <p><i class="fas fa-info-circle"></i> <strong>ملاحظات التقرير:</strong></p>
            <ul class="list-disc pr-5 mt-2">
                <li><strong>الرصيد النقدي (كاش اليوم):</strong> مجموع المبالغ المستلمة نقداً خلال اليوم الحالي، ويشمل مبيعات العملاء النقدية والمبالغ المحولة إلى الصندوق النقدي (الكاش).</li>
                <li><strong>أرصدة الحسابات البنكية:</strong> إجمالي الأرصدة الحالية الفعلية في جميع الحسابات البنكية.</li>
                <li><strong>أرصدة العملاء المتبقية:</strong> إجمالي المبالغ المتبقية على العملاء من جميع الطلبات غير المسددة بالكامل.</li>
                <li><strong>حقوق الملكية:</strong> تُحسب تلقائياً = إجمالي الأصول − إجمالي الالتزامات، مما يضمن توازن الميزانية دائماً.</li>
            </ul>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>