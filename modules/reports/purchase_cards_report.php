<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'تقرير بطاقات الشراء';

// Date filters - default to all time to show all cards
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if ($start_date === '' || $end_date === '') {
    $rangeStmt = $db->query("SELECT DATE(MIN(created_at)) as min_date, DATE(MAX(created_at)) as max_date FROM purchase_cards");
    $range = $rangeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $start_date = $start_date !== '' ? $start_date : ($range['min_date'] ?? '');
    $end_date = $end_date !== '' ? $end_date : ($range['max_date'] ?? '');
}

// Optional filter by purchase card name
$card_name_filter = trim($_GET['card_name'] ?? '');

// Build query - Get all purchase cards with their actual data
$query = "
    SELECT
        pc.id,
        pc.card_number,
        pc.card_name,
        pc.balance as current_balance,
        pc.card_purchase_amount,
        pc.initial_balance as total_added,
        pc.created_at,
        COUNT(DISTINCT pb.id) as transactions_count,
        COALESCE(SUM(COALESCE(pb.grand_total_yer, pb.final_amount)), 0) as total_used,
        (COALESCE(pc.card_purchase_amount,0) - COALESCE(pc.initial_balance,0)) as profit_amount,
        CASE
            WHEN COALESCE(pc.card_purchase_amount,0) > 0
            THEN ((COALESCE(pc.card_purchase_amount,0) - COALESCE(pc.initial_balance,0)) / COALESCE(pc.card_purchase_amount,0)) * 100
            ELSE 0
        END as profit_percentage
    FROM purchase_cards pc
    LEFT JOIN purchase_baskets pb ON pc.id = pb.payment_source_id
        AND pb.payment_source_type = 'purchase_card'
    WHERE 1=1
";

$params = [];

if (!empty($start_date) && !empty($end_date)) {
    $query .= " AND pc.created_at BETWEEN ? AND ?";
    $params[] = $start_date . ' 00:00:00';
    $params[] = $end_date . ' 23:59:59';
}

if ($card_name_filter !== '') {
    $query .= " AND pc.card_name LIKE ?";
    $params[] = '%' . $card_name_filter . '%';
}

$query .= " GROUP BY pc.id ORDER BY pc.created_at DESC";

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_cards = count($cards);
    $total_current = array_sum(array_column($cards, 'current_balance'));
    $total_used = array_sum(array_column($cards, 'total_used'));
    $total_added = array_sum(array_column($cards, 'total_added'));
    $total_purchase = array_sum(array_column($cards, 'card_purchase_amount'));
    $total_transactions = array_sum(array_column($cards, 'transactions_count'));
    $avg_profit_percentage = $total_purchase > 0 ? (($total_purchase - $total_added) / $total_purchase) * 100 : 0;

} catch (PDOException $e) {
    $error = $e->getMessage();
    $cards = [];
    $total_cards = $total_current = $total_used = $total_added = $total_purchase = $total_transactions = 0;
}

$status_labels = [
    'active' => 'نشطة',
    'inactive' => 'غير نشطة',
    'expired' => 'منتهية',
    'blocked' => 'محظورة'
];

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

    /* FIX: Wrapper handles BOTH horizontal and vertical scrolling */
    .data-table-wrapper {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        overflow: auto;                  /* both axes */
        -webkit-overflow-scrolling: touch;
        max-height: 70vh;               /* vertical scroll kicks in after this height */
        width: 100%;
        position: relative;
        cursor: grab;
        user-select: none;
        touch-action: pan-x pan-y;      /* allow both directions on touch */
    }

    .data-table-wrapper:active {
        cursor: grabbing;
    }

    .data-table-wrapper table {
        width: 100%;
        min-width: 1300px;
        border-collapse: collapse;
        table-layout: auto;
    }

    .data-table-wrapper th {
        background: #f3f4f6;
        padding: 1rem;
        text-align: right;
        font-weight: 600;
        color: #374151;
        border-bottom: 2px solid #e5e7eb;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .data-table-wrapper td {
        padding: 1rem;
        border-bottom: 1px solid #e5e7eb;
        color: #6b7280;
        white-space: nowrap;
    }

    .data-table-wrapper tr:hover {
        background: #f9fafb;
    }

    .data-table-wrapper tfoot tr {
        position: sticky;
        bottom: 0;
        z-index: 9;
        background: #f3f4f6;
    }

    .data-table-wrapper tfoot td {
        border-top: 2px solid #e5e7eb;
        font-weight: 600;
        color: #374151;
    }

    /* Scrollbar styling */
    .data-table-wrapper::-webkit-scrollbar {
        height: 10px;
        width: 8px;
    }

    .data-table-wrapper::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .data-table-wrapper::-webkit-scrollbar-thumb {
        background: #10b981;
        border-radius: 10px;
    }

    .data-table-wrapper::-webkit-scrollbar-thumb:hover {
        background: #059669;
    }

    /* Scroll shadows */
    .data-table-wrapper.scrolled-right {
        box-shadow: inset 10px 0 10px -10px rgba(0,0,0,0.15), 0 2px 8px rgba(0,0,0,0.08);
    }

    .data-table-wrapper.can-scroll-more {
        box-shadow: inset -10px 0 10px -10px rgba(0,0,0,0.15), 0 2px 8px rgba(0,0,0,0.08);
    }

    .data-table-wrapper.scrolled-right.can-scroll-more {
        box-shadow: inset 10px 0 10px -10px rgba(0,0,0,0.15), inset -10px 0 10px -10px rgba(0,0,0,0.15), 0 2px 8px rgba(0,0,0,0.08);
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
        white-space: nowrap;
        justify-content: center;
    }

    .btn-pdf  { background: #ef4444; color: white; }
    .btn-excel { background: #C7A46D; color: white; }

    .scroll-indicator {
        text-align: center;
        padding: 10px;
        background: #ecfdf5;
        color: #059669;
        font-size: 14px;
        font-weight: 600;
        border-radius: 8px;
        margin-bottom: 10px;
        display: none;
        opacity: 1;
        transition: opacity 0.3s ease;
    }

    @media (max-width: 1024px) {
        .scroll-indicator { display: block; }
    }

    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filter-card form { grid-template-columns: 1fr !important; }
        .export-buttons { flex-direction: column; align-items: stretch; }
        .export-btn { width: 100%; }
        .data-table-wrapper { max-height: 60vh; }
    }

    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr; }
        .filter-card { padding: 1rem; }
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-green-600 to-teal-700 shadow-xl rounded-2xl mb-8 p-6">
            <div class="flex flex-col md:flex-row md:justify-between md:items-center">
                <div class="mb-4 md:mb-0">
                    <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-credit-card ml-3"></i>
                        تقرير بطاقات الشراء
                    </h1>
                    <p class="text-green-100 mt-2 text-sm sm:text-base">تقرير شامل لجميع بطاقات الشراء وتكلفتها</p>
                </div>
                <a href="index.php"
                    class="px-5 py-2 md:px-6 md:py-3 bg-white text-green-600 rounded-xl hover:bg-green-50 font-semibold transition text-sm whitespace-nowrap">
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
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">من تاريخ</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">إلى تاريخ</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">فلترة حسب اسم بطاقة الشراء</label>
                    <input type="text" name="card_name" value="<?php echo htmlspecialchars($card_name_filter); ?>"
                        placeholder="اكتب جزء من اسم البطاقة"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div class="flex items-end">
                    <button type="submit"
                        class="w-full px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">
                        <i class="fas fa-filter ml-2"></i>
                        تصفية
                    </button>
                </div>
            </form>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-box" style="border-right-color: #10b981;">
                <p class="text-gray-600 text-sm">إجمالي البطاقات</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_cards, 0, ',', '.'); ?></p>
            </div>
            <div class="stat-box" style="border-right-color: #3b82f6;">
                <p class="text-gray-600 text-sm">المبلغ المضاف</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_added, 0, ',', '.'); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #8b5cf6;">
                <p class="text-gray-600 text-sm">الرصيد الحالي</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_current, 0, ',', '.'); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #ef4444;">
                <p class="text-gray-600 text-sm">المبلغ المستخدم</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_used, 0, ',', '.'); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #f59e0b;">
                <p class="text-gray-600 text-sm">مبلغ الشراء</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_purchase, 0, ',', '.'); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #C7A46D;">
                <p class="text-gray-600 text-sm">عدد المعاملات</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_transactions, 0, ',', '.'); ?></p>
            </div>
        </div>

        <!-- Data Table -->
        <div class="scroll-indicator">
            <i class="fas fa-arrows-alt-h"></i>
            اسحب لليمين أو اليسار لعرض جميع الأعمدة
        </div>
        <div class="data-table-wrapper" id="tableWrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>رقم البطاقة</th>
                        <th>اسم البطاقة</th>
                        <th>الرصيد الحالي</th>
                        <th>مبلغ الشراء</th>
                        <th>المبلغ المستخدم</th>
                        <th>نسبة الربح %</th>
                        <th>المبلغ المضاف</th>
                        <th>عدد المعاملات</th>
                        <th>تاريخ الإنشاء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cards)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-8 text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4"></i>
                                <p>لا توجد بيانات للعرض</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cards as $index => $card): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($card['card_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($card['card_name'] ?? '-'); ?></td>
                                <td><strong><?php echo number_format($card['current_balance'], 0, ',', '.'); ?> ر.ي</strong></td>
                                <td><strong style="color: #f59e0b;"><?php echo number_format($card['card_purchase_amount'], 0, ',', '.'); ?> ر.ي</strong></td>
                                <td><?php echo number_format($card['total_used'], 0, ',', '.'); ?> ر.ي</td>
                                <td><?php echo number_format($card['profit_percentage'], 1, '.', ','); ?>%</td>
                                <td><?php echo number_format($card['total_added'], 0, ',', '.'); ?> ر.ي</td>
                                <td><?php echo $card['transactions_count']; ?></td>
                                <td><?php echo date('Y-m-d', strtotime($card['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($cards)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="3">الإجمالي</td>
                            <td><?php echo number_format($total_current, 0, ',', '.'); ?> ر.ي</td>
                            <td><strong style="color: #f59e0b;"><?php echo number_format($total_purchase, 0, ',', '.'); ?> ر.ي</strong></td>
                            <td><?php echo number_format($total_used, 0, ',', '.'); ?> ر.ي</td>
                            <td></td>
                            <td><?php echo number_format($total_added, 0, ',', '.'); ?> ر.ي</td>
                            <td><?php echo number_format($total_transactions, 0, ',', '.'); ?></td>
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
        const params = new URLSearchParams(window.location.search);
        params.set('format', format);
        window.location.href = 'export_report.php?type=purchase_cards&' + params.toString();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const wrapper = document.getElementById('tableWrapper');
        const scrollIndicator = document.querySelector('.scroll-indicator');

        if (!wrapper) return;

        function checkScroll() {
            const hasHScroll = wrapper.scrollWidth > wrapper.clientWidth;
            if (scrollIndicator) {
                scrollIndicator.style.display = hasHScroll ? 'block' : 'none';
            }
            updateScrollShadows();
        }

        function updateScrollShadows() {
            const scrollLeft = wrapper.scrollLeft;
            const maxScroll = wrapper.scrollWidth - wrapper.clientWidth;
            wrapper.classList.toggle('scrolled-right', scrollLeft > 0);
            wrapper.classList.toggle('can-scroll-more', scrollLeft < maxScroll - 5);
        }

        checkScroll();
        window.addEventListener('resize', checkScroll);

        wrapper.addEventListener('scroll', function () {
            updateScrollShadows();
            if (scrollIndicator && this.scrollLeft > 50) {
                scrollIndicator.style.opacity = '0';
                setTimeout(() => { scrollIndicator.style.display = 'none'; }, 300);
            }
        });

        // Mouse drag scrolling (both axes)
        let isDown = false, startX, startY, scrollLeftStart, scrollTopStart;

        wrapper.addEventListener('mousedown', (e) => {
            if (e.target.closest('a, button')) return;
            isDown = true;
            startX = e.pageX - wrapper.offsetLeft;
            startY = e.pageY - wrapper.offsetTop;
            scrollLeftStart = wrapper.scrollLeft;
            scrollTopStart  = wrapper.scrollTop;
        });

        document.addEventListener('mouseup',   () => { isDown = false; });
        document.addEventListener('mouseleave', () => { isDown = false; });

        wrapper.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            wrapper.scrollLeft = scrollLeftStart - (e.pageX - wrapper.offsetLeft - startX) * 1.5;
            wrapper.scrollTop  = scrollTopStart  - (e.pageY - wrapper.offsetTop  - startY) * 1.5;
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>
