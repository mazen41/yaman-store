<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'تقرير سلال الشراء';

// --- 1. GET FILTERS & INITIALIZE VARIABLES ---
// Date filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? 'all';
$group_filter = $_GET['group'] ?? 'all';

$baskets = [];
$all_statuses = [];
$groups = [];
$error = '';


try {
    // --- 2. FETCH DATA FOR FILTERS ---
    // Fetch all available statuses for the filter dropdown
    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM purchase_basket_statuses ORDER BY is_default DESC, status_name_ar ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Get all groups for the filter dropdown
    $groups = $db->query("SELECT id, group_name FROM purchase_groups ORDER BY group_name")->fetchAll(PDO::FETCH_ASSOC);

    // --- 3. BUILD THE MAIN SQL QUERY ---
    // This query is now based on the comprehensive one from your show_baskets page
    $sql = "
        SELECT
            pb.id, pb.basket_name, pb.basket_code, pb.created_at, pb.total_items,
            pb.account_number,
            pb.subtotal_amount, pb.discount_amount, pb.final_amount, pb.status,
            pbs.status_name_ar,
            pb.payment_source_type, pb.payment_source_id, pg.group_name,
            u.username AS created_by, ba.bank_name, ba.account_number AS source_account_number,
            pc.card_name, pc.card_number,
            (SELECT GROUP_CONCAT(tracking_number SEPARATOR ', ') FROM basket_tracking WHERE basket_id = pb.id) AS tracking_numbers
        FROM purchase_baskets pb
        LEFT JOIN purchase_basket_statuses pbs ON pb.status = pbs.status_key
        LEFT JOIN purchase_groups pg ON pb.purchase_group_id = pg.id
        LEFT JOIN users u ON pb.created_by = u.id
        LEFT JOIN bank_accounts ba ON pb.payment_source_type = 'bank_account' AND pb.payment_source_id = ba.id
        LEFT JOIN purchase_cards pc ON pb.payment_source_type = 'purchase_card' AND pb.payment_source_id = pc.id
    ";

    $conditions = [];
    $params = [];

    // Add date filter condition
    // NOTE: Using created_at to match the show_baskets page logic. Change to purchase_date if needed.
    $conditions[] = "DATE(pb.created_at) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;

    // Add status filter condition
    if ($status_filter !== 'all') {
        $conditions[] = "pb.status = :status";
        $params[':status'] = $status_filter;
    }

    // Add group filter condition
    if ($group_filter !== 'all') {
        $conditions[] = "pb.purchase_group_id = :group";
        $params[':group'] = $group_filter;
    }

    // Append conditions to the query
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }

    $sql .= " GROUP BY pb.id ORDER BY pb.created_at DESC, pb.id DESC";

    // --- 4. EXECUTE QUERY AND FETCH DATA ---
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $baskets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- 5. CALCULATE TOTALS ---
    $total_baskets = count($baskets);
    $total_subtotal = array_sum(array_column($baskets, 'subtotal_amount'));
    $total_final_amount = array_sum(array_column($baskets, 'final_amount'));
    $total_items = array_sum(array_column($baskets, 'total_items'));

} catch (PDOException $e) {
    $error = "حدث خطأ في جلب البيانات: " . $e->getMessage();
    $baskets = [];
    $total_baskets = $total_subtotal = $total_final_amount = $total_items = 0;
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
        /* Allow horizontal scroll on small screens */
        overflow-x: auto;
        overflow-y: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .data-table table {
        width: 100%;
        /* Ensure table remains wider than small viewports so user can scroll */
        min-width: 1400px;
        border-collapse: collapse;
    }

    .data-table th {
        background: #f3f4f6;
        padding: 1rem;
        text-align: right;
        font-weight: 600;
        color: #374151;
        border-bottom: 2px solid #e5e7eb;
        white-space: nowrap;
    }

    .data-table td {
        padding: 1rem;
        border-bottom: 1px solid #e5e7eb;
        color: #6b7280;
        white-space: nowrap;
        vertical-align: middle;
    }

    .data-table tr:hover {
        background: #f9fafb;
    }

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

    .btn-pdf {
        background: #ef4444;
        color: white;
    }

    .btn-excel {
        background: #C7A46D;
        color: white;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4">

        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 shadow-xl rounded-2xl mb-8 p-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-file-alt ml-3"></i>
                        تقرير سلال الشراء
                    </h1>
                    <p class="text-purple-100 mt-2">تقرير شامل لعرض تفاصيل سلال الشراء</p>
                </div>
                <a href="index.php" class="px-6 py-3 bg-white text-purple-600 rounded-xl hover:bg-purple-50 font-semibold transition">
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
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">إلى تاريخ</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">الحالة</label>
                    <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>الكل</option>
                        <?php foreach ($all_statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo $status_filter === $status['status_key'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status['status_name_ar']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">المجموعة</label>
                    <select name="group" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        <option value="all" <?php echo $group_filter === 'all' ? 'selected' : ''; ?>>الكل</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>" <?php echo $group_filter == $group['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($group['group_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-semibold">
                        <i class="fas fa-filter ml-2"></i>
                        تصفية
                    </button>
                </div>
            </form>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-box" style="border-right-color: #667eea;">
                <p class="text-gray-600 text-sm">إجمالي السلال</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_baskets); ?></p>
            </div>
            <div class="stat-box" style="border-right-color: #3b82f6;">
                <p class="text-gray-600 text-sm">إجمالي المبلغ (قبل الخصم)</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_subtotal); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #C7A46D;">
                <p class="text-gray-600 text-sm">إجمالي المبلغ (النهائي)</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_final_amount); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #10b981;">
                <p class="text-gray-600 text-sm">إجمالي المنتجات</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_items); ?></p>
            </div>
        </div>
        
        <?php if ($error) : ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">خطأ!</strong>
                <span class="block sm:inline"><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <!-- Data Table -->
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم السلة</th>
                        <th>الكود</th>
                        <th>رقم الحساب</th>
                        <th>رقم التتبع</th>
                        <th>عدد المنتجات</th>
                        <th>المبلغ قبل الخصم</th>
                        <th>المبلغ النهائي</th>
                        <th>مصدر الدفع</th>
                        <th>الحالة</th>
                        <th>المجموعة</th>
                        <th>التاريخ</th>
                        <th>أنشئت بواسطة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($baskets)): ?>
                        <tr>
                            <td colspan="13" class="text-center py-8 text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4"></i>
                                <p>لا توجد بيانات للعرض حسب الفلاتر المحددة</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($baskets as $index => $basket): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($basket['basket_name'] ?? 'سلة بدون اسم'); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($basket['basket_code'] ?? ''); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($basket['account_number'] ?? '-'); ?></strong></td>
                                <td><?php echo htmlspecialchars($basket['tracking_numbers'] ?? '-'); ?></td>
                                <td><?php echo number_format($basket['total_items'] ?? 0); ?></td>
                                <td><?php echo number_format($basket['subtotal_amount'] ?? 0); ?> ر.ي</td>
                                <td><strong><?php echo number_format($basket['final_amount'] ?? 0); ?> ر.ي</strong></td>
                                <td>
                                    <?php
                                    $payment_type = $basket['payment_source_type'] ?? '';
                                    if ($payment_type == 'bank_account') {
                                        echo "<div><span>حساب بنكي</span></div><small>" . htmlspecialchars(($basket['bank_name'] ?? '') . ' - ' . ($basket['source_account_number'] ?? '')) . "</small>";
                                    } elseif ($payment_type == 'purchase_card') {
                                        echo "<div><span>بطاقة شراء</span></div><small>" . htmlspecialchars(!empty($basket['card_name']) ? ($basket['card_name'] ?? '') : ($basket['card_number'] ?? '')) . "</small>";
                                    } else {
                                        echo "<span>غير محدد</span>";
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="px-3 py-1 text-sm font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?php echo htmlspecialchars($basket['status_name_ar'] ?? $basket['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($basket['group_name'] ?? 'بدون مجموعة'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($basket['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($basket['created_by'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($baskets)): ?>
                    <tfoot>
                        <tr style="background: #f3f4f6; font-weight: bold; border-top: 2px solid #ddd;">
                            <td colspan="5">الإجمالي</td>
                            <td><?php echo number_format($total_items); ?></td>
                            <td><?php echo number_format($total_subtotal); ?> ر.ي</td>
                            <td><strong><?php echo number_format($total_final_amount); ?> ر.ي</strong></td>
                            <td colspan="5"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

    </div>
</div>

<script>
    function exportReport(format) {
        // This function creates a URL with the current filter parameters for the export script
        const params = new URLSearchParams(window.location.search);
        params.set('format', format);
        // Assuming your export script is named export_handler.php or similar
        // Adjust the URL if your export script has a different name or path
        window.location.href = 'export_purchase_baskets.php?' + params.toString();
    }
</script>

<?php include '../../includes/footer.php'; ?>