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

// Optional filter by purchase card name
$card_name_filter = trim($_GET['card_name'] ?? '');

// Build query - Get all purchase cards with their actual data
// CORRECTED QUERY: Fetches card_purchase_amount, uses initial_balance for total_added, and calculates total_used from transactions.
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
        COALESCE(SUM(pb.final_amount), 0) as total_used
    FROM purchase_cards pc
    LEFT JOIN purchase_baskets pb ON pc.id = pb.payment_source_id
        AND pb.payment_source_type = 'purchase_card'
    WHERE 1=1
";

$params = [];

// Add date filter only if dates are provided
if (!empty($start_date) && !empty($end_date)) {
    $query .= " AND pc.created_at BETWEEN ? AND ?";
    $params[] = $start_date . ' 00:00:00';
    $params[] = $end_date . ' 23:59:59';
}

// Filter by card name if provided (partial match)
if ($card_name_filter !== '') {
    $query .= " AND pc.card_name LIKE ?";
    $params[] = '%' . $card_name_filter . '%';
}

$query .= " GROUP BY pc.id ORDER BY pc.created_at DESC";

try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    // CORRECTED TOTALS: Sums the 'card_purchase_amount' for total_purchase.
    $total_cards = count($cards);
    $total_current = array_sum(array_column($cards, 'current_balance'));
    $total_used = array_sum(array_column($cards, 'total_used'));
    $total_added = array_sum(array_column($cards, 'total_added'));
    $total_purchase = array_sum(array_column($cards, 'card_purchase_amount')); // Corrected this line
    $total_transactions = array_sum(array_column($cards, 'transactions_count'));

} catch (PDOException $e) {
    $error = $e->getMessage();
    $cards = [];
    $total_cards = $total_current = $total_used = $total_added = $total_purchase = $total_transactions = 0;
}

// Status labels (not used in this report but kept for context if needed later)
$status_labels = [
    'active' => 'نشطة',
    'inactive' => 'غير نشطة',
    'expired' => 'منتهية',
    'blocked' => 'محظورة'
];

include '../../includes/header.php';
?>

<style>
    /* Base styles already good */
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
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        overflow-x: auto !important;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
        position: relative;
        width: 100%;
        display: block; /* Ensure block display for overflow-x to work */
    }

    .data-table table {
        width: 100%;
        min-width: 1200px; /* Forces horizontal scroll on smaller screens */
        border-collapse: collapse;
        display: table; /* Ensures table rendering */
        table-layout: auto;
    }

    .data-table th {
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

    .data-table td {
        padding: 1rem;
        border-bottom: 1px solid #e5e7eb;
        color: #6b7280;
        white-space: nowrap;
    }

    .data-table tr:hover {
        background: #f9fafb;
    }

    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
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
        /* Ensure buttons don't wrap text unnecessarily */
        white-space: nowrap;
        justify-content: center; /* Center content within button */
    }

    .btn-pdf {
        background: #ef4444;
        color: white;
    }

    .btn-excel {
        background: #C7A46D;
        color: white;
    }

    .cost-highlight {
        background: #fef3c7;
        font-weight: bold;
        color: #92400e;
    }

    /* Responsive styles */
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr); /* Two columns on medium/small screens */
        }

        .filter-card form {
            grid-template-columns: 1fr !important; /* Single column for filter form on small screens */
        }

        .export-buttons {
            flex-direction: column; /* Stack export buttons on small screens */
            align-items: stretch; /* Make buttons full width */
        }

        .export-btn {
            width: 100%; /* Ensure full width when stacked */
        }

        /* Adjust header font sizes for better mobile display */
        .min-h-screen .bg-gradient-to-r h1 {
            font-size: 1.75rem; /* Equivalent to text-2xl */
        }
        .min-h-screen .bg-gradient-to-r h1 .ml-3 {
            margin-left: 0.5rem; /* Adjust icon spacing */
        }
        .min-h-screen .bg-gradient-to-r p {
            font-size: 0.875rem; /* Equivalent to text-sm */
        }
        .min-h-screen .bg-gradient-to-r a {
            padding: 0.625rem 1.25rem; /* Adjust button padding */
            font-size: 0.875rem; /* Equivalent to text-sm */
        }
    }

    @media (max-width: 480px) { /* Even smaller screens, like older iPhones */
        .stats-grid {
            grid-template-columns: 1fr; /* Single column for stats on very small screens */
        }
        .filter-card {
            padding: 1rem; /* Slightly less padding for filter card */
        }
    }


    /* Scrollbar styling */
    .data-table::-webkit-scrollbar {
        height: 10px;
    }

    .data-table::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .data-table::-webkit-scrollbar-thumb {
        background: #10b981;
        border-radius: 10px;
    }

    .data-table::-webkit-scrollbar-thumb:hover {
        background: #059669;
    }

    /* Scroll indicator */
    .scroll-indicator {
        text-align: center;
        padding: 10px;
        background: #ecfdf5;
        color: #059669;
        font-size: 14px;
        font-weight: 600;
        border-radius: 8px;
        margin-bottom: 10px;
        display: none; /* Hidden by default, shown by JS if needed */
        opacity: 1; /* Default opacity */
        transition: opacity 0.3s ease;
    }

    @media (max-width: 1024px) {
        /* Only show the indicator by default on screens where horizontal scroll is likely */
        .scroll-indicator {
            display: block;
        }
    }

    /* Scroll shadow effects */
    .data-table.scrolled-right {
        box-shadow: inset 10px 0 10px -10px rgba(0, 0, 0, 0.15), 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .data-table.can-scroll-more {
        box-shadow: inset -10px 0 10px -10px rgba(0, 0, 0, 0.15), 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .data-table.scrolled-right.can-scroll-more {
        box-shadow: inset 10px 0 10px -10px rgba(0, 0, 0, 0.15), inset -10px 0 10px -10px rgba(0, 0, 0, 0.15), 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    /* Cursor styles for drag scrolling */
    .data-table {
        cursor: grab;
        user-select: none;
    }

    .data-table:active {
        cursor: grabbing;
    }

    /* Footer total row sticky behavior on horizontal scroll */
    .data-table tfoot tr {
        position: sticky;
        bottom: 0;
        z-index: 9; /* Slightly lower than sticky header */
        background: #f3f4f6; /* Ensure background is set for sticky */
    }
    .data-table tfoot td {
        border-top: 2px solid #e5e7eb;
        font-weight: 600;
        color: #374151;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8"> <!-- Added sm:px-6 lg:px-8 for better default padding -->

        <!-- Header -->
        <div class="bg-gradient-to-r from-green-600 to-teal-700 shadow-xl rounded-2xl mb-8 p-6">
            <div class="flex flex-col md:flex-row md:justify-between md:items-center"> <!-- Adjusted for mobile stacking -->
                <div class="mb-4 md:mb-0"> <!-- Added mb-4 for vertical spacing on mobile -->
                    <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center"> <!-- Adjusted h1 font size -->
                        <i class="fas fa-credit-card ml-3"></i>
                        تقرير بطاقات الشراء
                    </h1>
                    <p class="text-green-100 mt-2 text-sm sm:text-base">تقرير شامل لجميع بطاقات الشراء وتكلفتها</p> <!-- Adjusted p font size -->
                </div>
                <a href="index.php"
                    class="px-5 py-2 md:px-6 md:py-3 bg-white text-green-600 rounded-xl hover:bg-green-50 font-semibold transition text-sm whitespace-nowrap"> <!-- Adjusted button padding and font size -->
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
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_cards, 0, ',', '.'); ?>
                </p>
            </div>
            <div class="stat-box" style="border-right-color: #3b82f6;">
                <p class="text-gray-600 text-sm">المبلغ المضاف</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_added, 0, ',', '.'); ?>
                    ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #8b5cf6;">
                <p class="text-gray-600 text-sm">الرصيد الحالي</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">
                    <?php echo number_format($total_current, 0, ',', '.'); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #ef4444;">
                <p class="text-gray-600 text-sm">المبلغ المستخدم</p>
                <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($total_used, 0, ',', '.'); ?>
                    ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #f59e0b;">
                <p class="text-gray-600 text-sm">مبلغ الشراء</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">
                    <?php echo number_format($total_purchase, 0, ',', '.'); ?> ر.ي</p>
            </div>
            <div class="stat-box" style="border-right-color: #C7A46D;">
                <p class="text-gray-600 text-sm">عدد المعاملات</p>
                <p class="text-3xl font-bold text-gray-900 mt-2">
                    <?php echo number_format($total_transactions, 0, ',', '.'); ?></p>
            </div>
        </div>

        <!-- Data Table -->
        <div class="scroll-indicator">
            <i class="fas fa-arrows-alt-h"></i>
            اسحب لليمين أو اليسار لعرض جميع الأعمدة
        </div>
        <div class="data-table">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>رقم البطاقة</th>
                        <th>اسم البطاقة</th>
                        <th>الرصيد الحالي</th>
                        <th>مبلغ الشراء</th>
                        <th>المبلغ المستخدم</th>
                        <th>المبلغ المضاف</th>
                        <th>عدد المعاملات</th>
                        <th>تاريخ الإنشاء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cards)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-8 text-gray-500">
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
                                <td><strong><?php echo number_format($card['current_balance'], 0, ',', '.'); ?> ر.ي</strong>
                                </td>
                                <!-- CORRECTED TABLE CELL: Displays card_purchase_amount now -->
                                <td><strong
                                        style="color: #f59e0b;"><?php echo number_format($card['card_purchase_amount'], 0, ',', '.'); ?>
                                        ر.ي</strong></td>
                                <td><?php echo number_format($card['total_used'], 0, ',', '.'); ?> ر.ي</td>
                                <td><?php echo number_format($card['total_added'], 0, ',', '.'); ?> ر.ي</td>
                                <td><?php echo $card['transactions_count']; ?></td>
                                <td><?php echo date('Y-m-d', strtotime($card['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($cards)): ?>
                    <tfoot>
                        <tr style="background: #f3f4f6; font-weight: bold;">
                            <td colspan="3">الإجمالي</td>
                            <td><?php echo number_format($total_current, 0, ',', '.'); ?> ر.ي</td>
                            <td><strong style="color: #f59e0b;"><?php echo number_format($total_purchase, 0, ',', '.'); ?>
                                    ر.ي</strong></td>
                            <td><?php echo number_format($total_used, 0, ',', '.'); ?> ر.ي</td>
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

    // Enhanced scroll functionality
    document.addEventListener('DOMContentLoaded', function () {
        const tableContainer = document.querySelector('.data-table');
        const scrollIndicator = document.querySelector('.scroll-indicator');

        if (tableContainer) {
            // Check if table needs scrolling
            function checkScroll() {
                const hasScroll = tableContainer.scrollWidth > tableContainer.clientWidth;
                if (scrollIndicator) {
                    // Only display if the table actually needs to scroll
                    if (hasScroll) {
                        scrollIndicator.style.display = 'block';
                    } else {
                        scrollIndicator.style.display = 'none';
                    }
                }
                // Initial check for scroll shadows
                updateScrollShadows();
            }

            // Update scroll shadow classes
            function updateScrollShadows() {
                const scrollLeft = tableContainer.scrollLeft;
                const scrollWidth = tableContainer.scrollWidth;
                const clientWidth = tableContainer.clientWidth;
                const scrollableWidth = scrollWidth - clientWidth;

                tableContainer.classList.remove('scrolled-right', 'can-scroll-more');

                if (scrollableWidth > 0) { // Only apply shadows if actually scrollable
                    if (scrollLeft > 0) {
                        tableContainer.classList.add('scrolled-right');
                    }
                    // Add a small threshold (e.g., 5px) to prevent flicker at the very end
                    if (scrollLeft < scrollableWidth - 5) {
                        tableContainer.classList.add('can-scroll-more');
                    }
                }
            }


            // Initial check
            checkScroll();

            // Check on window resize
            window.addEventListener('resize', checkScroll);

            // Add scroll shadow effect and hide indicator
            tableContainer.addEventListener('scroll', function () {
                updateScrollShadows();

                // Hide indicator after first significant scroll
                if (scrollIndicator && this.scrollLeft > 50 && scrollIndicator.style.opacity !== '0') {
                    scrollIndicator.style.opacity = '0';
                    setTimeout(() => {
                        scrollIndicator.style.display = 'none';
                    }, 300); // Wait for fade out transition
                }
            });

            // Enable touch/mouse drag scrolling
            let isDown = false;
            let startX;
            let scrollLeftStart;

            tableContainer.addEventListener('mousedown', (e) => {
                // Prevent drag if clicking on interactive elements
                if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) {
                    return;
                }
                isDown = true;
                tableContainer.style.cursor = 'grabbing';
                startX = e.pageX - tableContainer.offsetLeft;
                scrollLeftStart = tableContainer.scrollLeft;
            });

            tableContainer.addEventListener('mouseleave', () => {
                isDown = false;
                tableContainer.style.cursor = 'grab';
            });

            tableContainer.addEventListener('mouseup', () => {
                isDown = false;
                tableContainer.style.cursor = 'grab';
            });

            tableContainer.addEventListener('mousemove', (e) => {
                if (!isDown) return;
                e.preventDefault();
                const x = e.pageX - tableContainer.offsetLeft;
                const walk = (x - startX) * 2; // Increase sensitivity for better dragging
                tableContainer.scrollLeft = scrollLeftStart - walk;
            });

            // Handle touch events for mobile drag scrolling
            let touchStartX = 0;
            let touchScrollLeftStart = 0;

            tableContainer.addEventListener('touchstart', (e) => {
                if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) {
                    return;
                }
                isDown = true;
                touchStartX = e.touches[0].pageX - tableContainer.offsetLeft;
                touchScrollLeftStart = tableContainer.scrollLeft;
                // Prevent default touch behavior that might interfere with custom scrolling
                // e.preventDefault(); // Might prevent scrolling on elements *inside* the table, be careful
            }, { passive: true }); // Use passive to avoid blocking scrolling unless necessary

            tableContainer.addEventListener('touchmove', (e) => {
                if (!isDown) return;
                const x = e.touches[0].pageX - tableContainer.offsetLeft;
                const walk = (x - touchStartX) * 2;
                tableContainer.scrollLeft = touchScrollLeftStart - walk;
                e.preventDefault(); // Prevent native scrolling once custom scroll starts
            });

            tableContainer.addEventListener('touchend', () => {
                isDown = false;
            });
            tableContainer.addEventListener('touchcancel', () => {
                isDown = false;
            });
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>