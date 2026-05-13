<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'تقرير الدفعات المفصل';

// --- FILTERS ---
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$payment_method_filter = $_GET['payment_method'] ?? 'all';
$bank_account_filter = $_GET['bank_account_id'] ?? 'all';

// --- BUILD QUERY ---
$query = "
    SELECT 
        cp.id,
        cp.payment_number,
        cp.payment_date,
        cp.amount,
        cp.payment_method,
        cp.reference_number,
        c.name as customer_name,
        u.username as created_by,
        ba.bank_name
    FROM customer_payments cp
    LEFT JOIN customers c ON cp.customer_id = c.id
    LEFT JOIN users u ON cp.created_by = u.id
    LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
    WHERE cp.payment_date BETWEEN ? AND ?
";

$params = [$start_date, $end_date];

// Apply payment method filter
if ($payment_method_filter !== 'all') {
    $query .= " AND cp.payment_method = ?";
    $params[] = $payment_method_filter;
}

// Apply bank account filter
if ($bank_account_filter !== 'all') {
    $query .= " AND cp.bank_account_id = ?";
    $params[] = $bank_account_filter;
}

$query .= " ORDER BY cp.payment_date DESC, cp.id DESC";

try {
    // --- FETCH DATA ---
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- CALCULATE TOTALS ---
    $total_payments = count($payments);
    $total_amount = array_sum(array_column($payments, 'amount'));
    
    $total_cash_amount = 0;
    $total_transfer_amount = 0;
    foreach ($payments as $payment) {
        if ($payment['payment_method'] === 'cash') {
            $total_cash_amount += $payment['amount'];
        } elseif ($payment['payment_method'] === 'transfer') {
            $total_transfer_amount += $payment['amount'];
        }
    }

    // --- GET BANK ACCOUNTS FOR FILTER DROPDOWN ---
    $bank_accounts = $db->query("SELECT id, bank_name, account_number FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = $e->getMessage();
    $payments = [];
    $total_payments = $total_amount = $total_cash_amount = $total_transfer_amount = 0;
    $bank_accounts = [];
}

include '../../includes/header.php';
?>

<style>
    .filter-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        margin-bottom: 2rem;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-box {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border-right: 4px solid;
    }

    .data-table {
        background: white;
        border-radius: 12px;
        overflow-x: auto;
        overflow-y: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .data-table table {
        width: 100%;
        min-width: 900px;
        border-collapse: collapse;
    }

    .data-table th {
        background: #f3f4f6;
        padding: 1rem;
        text-align: right;
        font-weight: 600;
        color: #374151;
        border-bottom: 2px solid #e5e7eb;
    }

    .data-table td {
        padding: 1rem;
        border-bottom: 1px solid #e5e7eb;
        color: #6b7280;
    }

    .data-table tr:hover {
        background: #f9fafb;
    }
    
    .payment-method-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .method-cash { background-color: #dcfce7; color: #166534; }
    .method-transfer { background-color: #e0f2fe; color: #075985; }

    .export-buttons {
        display: flex;
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .export-btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .btn-pdf { background: #ef4444; color: white; }
    .btn-excel { background: #166534; color: white; }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4">

        <!-- Header -->
        <div class="bg-gradient-to-r from-emerald-600 to-green-700 shadow-xl rounded-2xl mb-8 p-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-money-bill-wave ml-3"></i>
                        <?php echo $page_title; ?>
                    </h1>
                    <p class="text-green-100 mt-2">تقرير شامل لجميع دفعات العملاء المستلمة</p>
                </div>
                <a href="index.php"
                    class="px-6 py-3 bg-white text-emerald-600 rounded-xl hover:bg-green-50 font-semibold transition">
                    <i class="fas fa-arrow-right ml-2"></i>
                    العودة للتقارير
                </a>
            </div>
        </div>

        <!-- Export Buttons -->
        <div class="export-buttons">
            <button class="export-btn btn-pdf" onclick="exportReport('pdf')">
                <i class="fas fa-file-pdf"></i>
                تصدير PDF
            </button>
            <button class="export-btn btn-excel" onclick="exportReport('excel')">
                <i class="fas fa-file-excel"></i>
                تصدير Excel
            </button>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">من تاريخ</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">إلى تاريخ</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">طريقة الدفع</label>
                    <select name="payment_method"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500">
                        <option value="all" <?php echo $payment_method_filter === 'all' ? 'selected' : ''; ?>>الكل</option>
                        <option value="cash" <?php echo $payment_method_filter === 'cash' ? 'selected' : ''; ?>>كاش</option>
                        <option value="transfer" <?php echo $payment_method_filter === 'transfer' ? 'selected' : ''; ?>>تحويل</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">الحساب البنكي</label>
                    <select name="bank_account_id"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500">
                        <option value="all" <?php echo $bank_account_filter === 'all' ? 'selected' : ''; ?>>الكل</option>
                        <?php foreach ($bank_accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>" <?php echo $bank_account_filter == $account['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($account['bank_name']); ?> (<?php echo htmlspecialchars($account['account_number']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit"
                        class="w-full px-6 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-semibold">
                        <i class="fas fa-filter ml-2"></i>
                        تصفية
                    </button>
                </div>
            </form>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-box" style="border-right-color: #3b82f6;">
                <p class="text-gray-600 text-sm">إجمالي الدفعات</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">
                    <?php echo number_format($total_payments); ?></p>
            </div>
            <div class="stat-box" style="border-right-color: #10b981;">
                <p class="text-gray-600 text-sm">إجمالي المبلغ</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">
                    <?php echo number_format($total_amount, 2); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #6366f1;">
                <p class="text-gray-600 text-sm">إجمالي الكاش</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">
                    <?php echo number_format($total_cash_amount, 2); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #f59e0b;">
                <p class="text-gray-600 text-sm">إجمالي التحويلات</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">
                    <?php echo number_format($total_transfer_amount, 2); ?> ر.ي</p>
            </div>
        </div>

        <!-- Data Table -->
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>رقم الدفعة</th>
                        <th>العميل</th>
                        <th>تاريخ الدفعة</th>
                        <th>طريقة الدفع</th>
                        <th>الرقم المرجعي</th>
                        <th>المبلغ</th>
                        <th>أنشئت بواسطة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-8 text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4"></i>
                                <p>لا توجد دفعات مطابقة لمعايير البحث</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $index => $payment): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($payment['payment_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($payment['customer_name'] ?? 'غير محدد'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?></td>
                                <td>
                                    <?php if ($payment['payment_method'] === 'cash'): ?>
                                        <span class="payment-method-badge method-cash">
                                            <i class="fas fa-money-bill"></i> كاش
                                        </span>
                                    <?php elseif ($payment['payment_method'] === 'transfer'): ?>
                                        <span class="payment-method-badge method-transfer">
                                            <i class="fas fa-university"></i>
                                            تحويل <?php echo $payment['bank_name'] ? ': ' . htmlspecialchars($payment['bank_name']) : ''; ?>
                                        </span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($payment['payment_method']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                                <td><strong class="text-emerald-700"><?php echo number_format($payment['amount'], 2); ?> ر.ي</strong></td>
                                <td><?php echo htmlspecialchars($payment['created_by'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($payments)): ?>
                    <tfoot>
                        <tr style="background: #f3f4f6; font-weight: bold;">
                            <td colspan="6">الإجمالي</td>
                            <td class="text-emerald-800"><?php echo number_format($total_amount, 2); ?> ر.ي</td>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

    </div>
</div>

<script>
    function exportReport(format) {
        // Create a URLSearchParams object from the current window's search string
        const params = new URLSearchParams(window.location.search);
        
        // Set the report type and format for the export script
        params.set('type', 'detail_payments_reports');
        params.set('format', format);
        
        // Redirect to the export script with all the current filters
        window.location.href = 'export_report.php?' + params.toString();
    }
</script>

<?php include '../../includes/footer.php'; ?>