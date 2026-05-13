<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'التقرير المالي المتقدم لسلال الشراء';

// --- 1. GET FILTERS & INITIALIZE ---
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? 'all';
$group_filter = $_GET['group_filter'] ?? 'all';
$payment_source_type_filter = $_GET['payment_source_type'] ?? 'all';
$source_id_filter = $_GET['source_id'] ?? 'all';

$baskets = [];
$error = '';

try {
    // --- 2. FETCH DATA FOR FILTERS ---
    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM purchase_basket_statuses ORDER BY is_default DESC, status_name_ar ASC")->fetchAll(PDO::FETCH_ASSOC);
    $groups = $db->query("SELECT id, group_name FROM purchase_groups ORDER BY group_name")->fetchAll(PDO::FETCH_ASSOC);
    $purchase_cards = $db->query("SELECT id, card_name, card_number FROM purchase_cards ORDER BY card_name")->fetchAll(PDO::FETCH_ASSOC);
    $bank_accounts = $db->query("SELECT id, bank_name, account_number FROM bank_accounts ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);

    // --- 3. BUILD THE COMPREHENSIVE & ACCURATE SQL QUERY ---
    // ** FIX START: The query is updated to correctly sum discounts from both manual baskets and order-based baskets. **
    $sql = "
        SELECT 
            pb.id, pb.basket_name, pb.basket_code, pb.created_at, pb.purchase_date, pb.total_items,
            pb.account_number, pb.subtotal_amount, pb.final_amount, pb.status,
            pbs.status_name_ar,
            pb.payment_source_type, pb.payment_source_id, pg.group_name,
            u.username AS created_by, 
            ba.bank_name, ba.account_number AS source_account_number,
            pc.card_name, pc.card_number,
            (SELECT GROUP_CONCAT(tracking_number SEPARATOR ', ') FROM basket_tracking WHERE basket_id = pb.id) AS tracking_numbers,
            
            -- CORRECTED DISCOUNT LOGIC:
            -- 1. Manual Discount: Combines the value from the manual basket's `discount_amount` field
            --    with any additional discounts from linked customer orders.
            (COALESCE(pb.discount_amount, 0) + (SELECT COALESCE(SUM(co.additional_discount), 0) FROM customer_orders co WHERE co.id IN (SELECT bi.order_id FROM basket_items bi WHERE bi.basket_id = pb.id))) AS total_manual_discount,
            
            -- 2. Coupon Discount: Summed from linked customer orders.
            (SELECT COALESCE(SUM(co.coupon_discount), 0) FROM customer_orders co WHERE co.id IN (SELECT bi.order_id FROM basket_items bi WHERE bi.basket_id = pb.id)) AS total_coupon_discount,

            -- 3. Club & Points Discounts: These are stored directly on the basket table for all types.
            COALESCE(pb.club_discount, 0) AS club_discount,
            COALESCE(pb.points_discount, 0) AS points_discount

        FROM purchase_baskets pb
        LEFT JOIN purchase_basket_statuses pbs ON pb.status = pbs.status_key
        LEFT JOIN purchase_groups pg ON pb.purchase_group_id = pg.id
        LEFT JOIN users u ON pb.created_by = u.id
        LEFT JOIN bank_accounts ba ON pb.payment_source_type = 'bank_account' AND pb.payment_source_id = ba.id
        LEFT JOIN purchase_cards pc ON pb.payment_source_type = 'purchase_card' AND pb.payment_source_id = pc.id
    ";
    // ** FIX END **

    $conditions = [];
    $params = [];

    // Apply date filter (using purchase_date for financial reports)
    $conditions[] = "pb.purchase_date BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;

    // Apply other filters
    if ($status_filter !== 'all') {
        $conditions[] = "pb.status = :status";
        $params[':status'] = $status_filter;
    }
    if ($group_filter !== 'all') {
        $conditions[] = "pb.purchase_group_id = :group_id";
        $params[':group_id'] = $group_filter;
    }
    if ($payment_source_type_filter !== 'all') {
        $conditions[] = "pb.payment_source_type = :payment_type";
        $params[':payment_type'] = $payment_source_type_filter;
    }
    if ($source_id_filter !== 'all') {
        $conditions[] = "pb.payment_source_id = :source_id";
        $params[':source_id'] = $source_id_filter;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY pb.purchase_date DESC, pb.id DESC";
    
    // --- 4. EXECUTE QUERY ---
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $baskets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- 5. CALCULATE TOTALS ---
    $total_baskets = count($baskets);
    $total_subtotal = array_sum(array_column($baskets, 'subtotal_amount'));
    $total_final_amount = array_sum(array_column($baskets, 'final_amount'));
    
    // Discount totals
    $total_manual_discount = array_sum(array_column($baskets, 'total_manual_discount'));
    $total_coupon_discount = array_sum(array_column($baskets, 'total_coupon_discount'));
    $total_club_discount = array_sum(array_column($baskets, 'club_discount'));
    $total_points_discount = array_sum(array_column($baskets, 'points_discount'));
    
    // This is the overall total of all discounts combined
    $total_overall_discount = $total_manual_discount + $total_coupon_discount + $total_club_discount + $total_points_discount;

} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
    $baskets = [];
    $total_baskets = $total_subtotal = $total_final_amount = $total_overall_discount = 0;
    $total_manual_discount = $total_coupon_discount = $total_club_discount = $total_points_discount = 0;
    $groups = $purchase_cards = $bank_accounts = $all_statuses = [];
}


include '../../includes/header.php';
?>

<style>
    .filter-card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 2rem; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .stat-box { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-right: 4px solid; }
    .data-table { background: white; border-radius: 12px; overflow-x: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .data-table table { width: 100%; min-width: 1600px; border-collapse: collapse; }
    .data-table th { background: #f3f4f6; padding: 1rem; text-align: right; font-weight: 600; color: #374151; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
    .data-table td { padding: 1rem; border-bottom: 1px solid #e5e7eb; color: #6b7280; white-space: nowrap; vertical-align: middle; }
    .data-table tr:hover { background: #f9fafb; }
    .export-buttons { display: flex; gap: 1rem; margin-bottom: 2rem; }
    .export-btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; display: flex; align-items: center; gap: 8px; }
    .btn-pdf { background: #ef4444; color: white; }
    .btn-excel { background: #10b981; color: white; }
    .hidden-filter { display: none; }
    .discount-value { color: #ef4444; font-weight: 500; }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4">

        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 shadow-xl rounded-2xl mb-8 p-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-search-dollar ml-3"></i> <?php echo $page_title; ?>
                    </h1>
                    <p class="text-indigo-100 mt-2">تحليل متقدم للسلال مع فلاتر مصادر الدفع</p>
                </div>
                 <a href="index.php" class="px-6 py-3 bg-white text-indigo-600 rounded-xl hover:bg-indigo-50 font-semibold transition">
                    <i class="fas fa-arrow-right ml-2"></i> العودة للتقارير
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                <strong class="font-bold">خطأ!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Export Buttons -->
        <div class="export-buttons">
            <button class="export-btn btn-pdf" onclick="exportReport('pdf')"><i class="fas fa-file-pdf"></i> تصدير PDF</button>
            <button class="export-btn btn-excel" onclick="exportReport('excel')"><i class="fas fa-file-excel"></i> تصدير Excel</button>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form id="filterForm" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">من تاريخ</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">إلى تاريخ</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">الحالة</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                        <option value="all">الكل</option>
                        <?php foreach ($all_statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo ($status_filter === $status['status_key']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($status['status_name_ar']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">المجموعة</label>
                    <select name="group_filter" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                        <option value="all">الكل</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>" <?php echo ($group_filter == $group['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($group['group_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">نوع مصدر الدفع</label>
                    <select id="paymentSourceType" name="payment_source_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                        <option value="all">الكل</option>
                        <option value="purchase_card" <?php echo ($payment_source_type_filter === 'purchase_card') ? 'selected' : ''; ?>>بطاقة شراء</option>
                        <option value="bank_account" <?php echo ($payment_source_type_filter === 'bank_account') ? 'selected' : ''; ?>>حساب بنكي</option>
                    </select>
                </div>
                <div id="purchaseCardFilter" class="hidden-filter">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">تحديد بطاقة الشراء</label>
                    <select name="source_id_card" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                        <option value="all">كل البطاقات</option>
                        <?php foreach ($purchase_cards as $card): ?>
                            <option value="<?php echo $card['id']; ?>" <?php echo ($payment_source_type_filter === 'purchase_card' && $source_id_filter == $card['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($card['card_name'] . ' (' . $card['card_number'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="bankAccountFilter" class="hidden-filter">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">تحديد الحساب البنكي</label>
                    <select name="source_id_bank" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                        <option value="all">كل الحسابات</option>
                        <?php foreach ($bank_accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>" <?php echo ($payment_source_type_filter === 'bank_account' && $source_id_filter == $account['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($account['bank_name'] . ' (' . $account['account_number'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-span-full flex items-end">
                    <button type="submit" class="w-full md:w-auto px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-semibold">
                        <i class="fas fa-filter ml-2"></i> تصفية
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Main Statistics -->
        <div class="stats-grid">
            <div class="stat-box" style="border-right-color: #4f46e5;"><p class="text-gray-600 text-sm">إجمالي السلال</p><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_baskets); ?></p></div>
            <div class="stat-box" style="border-right-color: #3b82f6;"><p class="text-gray-600 text-sm">الإجمالي قبل الخصم</p><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_subtotal, 2); ?> ر.ي</p></div>
            <div class="stat-box" style="border-right-color: #ef4444;"><p class="text-gray-600 text-sm">إجمالي الخصومات</p><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_overall_discount, 2); ?> ر.ي</p></div>
            <div class="stat-box" style="border-right-color: #10b981;"><p class="text-gray-600 text-sm">المبلغ النهائي (الصافي)</p><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_final_amount, 2); ?> ر.ي</p></div>
        </div>

        <!-- Data Table -->
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>#</th><th>اسم السلة</th><th>الكود</th><th>تاريخ الشراء</th><th>الحالة</th><th>مصدر الدفع</th>
                        <th>المبلغ قبل الخصم</th><th>خصم يدوي</th><th>خصم الكوبون</th><th>خصم النادي</th><th>خصم النقاط</th>
                        <th>إجمالي الخصم</th><th>المبلغ النهائي</th><th>المنشئ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($baskets)): ?>
                        <tr><td colspan="14" class="text-center py-8 text-gray-500"><i class="fas fa-inbox text-4xl mb-4"></i><p>لا توجد سلال مطابقة لمعايير البحث</p></td></tr>
                    <?php else: ?>
                        <?php foreach ($baskets as $index => $basket): 
                            $basket_total_discount = $basket['total_manual_discount'] + $basket['total_coupon_discount'] + $basket['club_discount'] + $basket['points_discount'];
                        ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($basket['basket_name'] ?? 'سلة بدون اسم'); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($basket['basket_code']); ?></code></td>
                                <td><?php echo date('Y-m-d', strtotime($basket['purchase_date'])); ?></td>
                                <td><span style="background: #e0e7ff; color: #4338ca; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo htmlspecialchars($basket['status_name_ar'] ?? $basket['status']); ?></span></td>
                                <td>
                                    <?php
                                    $payment_type = $basket['payment_source_type'] ?? '';
                                    if ($payment_type == 'bank_account') {
                                        echo "<div><small>" . htmlspecialchars(($basket['bank_name'] ?? '') . ' - ' . ($basket['source_account_number'] ?? '')) . "</small></div>";
                                    } elseif ($payment_type == 'purchase_card') {
                                        echo "<div><small>" . htmlspecialchars(!empty($basket['card_name']) ? ($basket['card_name'] ?? '') : ($basket['card_number'] ?? '')) . "</small></div>";
                                    } else { echo "<span>غير محدد</span>"; }
                                    ?>
                                </td>
                                <td><?php echo number_format($basket['subtotal_amount'], 2); ?> ر.ي</td>
                                <td class="discount-value"><?php echo number_format($basket['total_manual_discount'], 2); ?> ر.ي</td>
                                <td class="discount-value"><?php echo number_format($basket['total_coupon_discount'], 2); ?> ر.ي</td>
                                <td class="discount-value"><?php echo number_format($basket['club_discount'], 2); ?> ر.ي</td>
                                <td class="discount-value"><?php echo number_format($basket['points_discount'], 2); ?> ر.ي</td>
                                <td><strong><?php echo number_format($basket_total_discount, 2); ?> ر.ي</strong></td>
                                <td style="font-weight:bold; color:#10b981;"><?php echo number_format($basket['final_amount'], 2); ?> ر.ي</td>
                                <td><?php echo htmlspecialchars($basket['created_by']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($baskets)): ?>
                    <tfoot style="background: #f3f4f6; font-weight: bold;">
                        <tr>
                            <td colspan="6">الإجماليات</td>
                            <td><?php echo number_format($total_subtotal, 2); ?> ر.ي</td>
                            <td class="discount-value"><?php echo number_format($total_manual_discount, 2); ?> ر.ي</td>
                            <td class="discount-value"><?php echo number_format($total_coupon_discount, 2); ?> ر.ي</td>
                            <td class="discount-value"><?php echo number_format($total_club_discount, 2); ?> ر.ي</td>
                            <td class="discount-value"><?php echo number_format($total_points_discount, 2); ?> ر.ي</td>
                            <td><?php echo number_format($total_overall_discount, 2); ?> ر.ي</td>
                            <td style="color:#10b981;"><?php echo number_format($total_final_amount, 2); ?> ر.ي</td>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paymentTypeSelect = document.getElementById('paymentSourceType');
    const cardFilter = document.getElementById('purchaseCardFilter');
    const bankFilter = document.getElementById('bankAccountFilter');
    const cardSelect = cardFilter.querySelector('select');
    const bankSelect = bankFilter.querySelector('select');
    const form = document.getElementById('filterForm');

    function toggleSourceFilters() {
        const selectedType = paymentTypeSelect.value;
        cardFilter.classList.add('hidden-filter');
        bankFilter.classList.add('hidden-filter');
        cardSelect.disabled = true;
        bankSelect.disabled = true;

        if (selectedType === 'purchase_card') {
            cardFilter.classList.remove('hidden-filter');
            cardSelect.disabled = false;
        } else if (selectedType === 'bank_account') {
            bankFilter.classList.remove('hidden-filter');
            bankSelect.disabled = false;
        }
    }

    form.addEventListener('submit', function(e) {
        const existingHidden = form.querySelector('input[name="source_id"]');
        if (existingHidden) existingHidden.remove();

        const selectedType = paymentTypeSelect.value;
        let selectedId = 'all';

        if (selectedType === 'purchase_card') selectedId = cardSelect.value;
        else if (selectedType === 'bank_account') selectedId = bankSelect.value;

        if (selectedId !== 'all') {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'source_id';
            hiddenInput.value = selectedId;
            form.appendChild(hiddenInput);
        }
    });

    toggleSourceFilters();
    paymentTypeSelect.addEventListener('change', toggleSourceFilters);
});

function exportReport(format) {
    const form = document.getElementById('filterForm');
    const params = new URLSearchParams(new FormData(form));
    const selectedType = document.getElementById('paymentSourceType').value;
    let selectedId = 'all';
    
    if (selectedType === 'purchase_card') selectedId = form.querySelector('select[name="source_id_card"]').value;
    else if (selectedType === 'bank_account') selectedId = form.querySelector('select[name="source_id_bank"]').value;
    
    params.delete('source_id_card');
    params.delete('source_id_bank');
    params.set('source_id', selectedId);
    
    params.set('format', format);
    window.location.href = 'export_advanced_financial_report.php?' + params.toString();
}
</script>

<?php include '../../includes/footer.php'; ?>