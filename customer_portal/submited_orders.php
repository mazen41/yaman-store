<?php
/**
 * Customer Portal - Submitted Orders View
 * Enhanced UI Version
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('<div style="font-family:sans-serif; text-align:center; padding:50px;">Invalid access. Please use the link provided to you.</div>');
}

// 1. Validate Customer Token
$stmt = $db->prepare("SELECT * FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('<div style="font-family:sans-serif; text-align:center; padding:50px;">Invalid or expired link. Please contact support.</div>');
}

$customer_id = $customer['id'];

// Helper: Status Colors & Labels
function getStatusDetails($status) {
    switch ($status) {
        case 'approved':
            return ['class' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'icon' => 'fa-check-circle', 'label' => 'تمت الموافقة'];
        case 'rejected':
            return ['class' => 'bg-red-100 text-red-800 border-red-200', 'icon' => 'fa-times-circle', 'label' => 'مرفوض'];
        case 'pending':
        default:
            return ['class' => 'bg-amber-100 text-amber-800 border-amber-200', 'icon' => 'fa-clock', 'label' => 'قيد المراجعة'];
    }
}

$approvals = [];
$stats = [
    'total_orders' => 0,
    'total_spent' => 0,
    'total_items' => 0
];

$currency = $customer['currency'] ?? 'YER'; 

try {
    // 2. Main Approvals Query
    $query = "SELECT 
                oa.*,
                COALESCE((SELECT SUM(oai.item_count) FROM order_approval_items oai WHERE oai.approval_id = oa.id), 0) AS total_quantity,
                co.order_number AS final_order_number
            FROM order_approvals oa
            LEFT JOIN customer_orders co ON oa.final_order_id = co.id
            WHERE oa.customer_id = ?
            ORDER BY oa.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute([$customer_id]);
    $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals for the Dashboard
    foreach ($approvals as $approval) {
        $stats['total_orders']++;
        $stats['total_items'] += $approval['total_quantity'];
        $stats['total_spent'] += $approval['paid_amount'];
    }

} catch (PDOException $e) {
    $approvals = [];
    error_log("Submitted Orders Portal Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>بوابة العميل - <?php echo htmlspecialchars($customer['name']); ?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-gold: #c5a059;
            --primary-gold-dark: #a38243;
            --bg-light: #f8fafc;
        }
        body { font-family: 'Tajawal', sans-serif; background-color: var(--bg-light); }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Animations */
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        
        /* Glass Effect */
        .glass-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #b48811 0%, #d4af37 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>
<body class="text-gray-700">
    
    <!-- Navbar -->
    <nav class="glass-header sticky top-0 z-50 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <!-- User Info -->
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-yellow-100 to-amber-200 flex items-center justify-center text-amber-700 font-bold text-xl shadow-inner">
                        <?php echo mb_substr($customer['name'], 0, 1); ?>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold text-gray-900 leading-tight">مرحباً، <?php echo htmlspecialchars($customer['name']); ?></h1>
                        <p class="text-xs text-gray-500 font-mono">ID: <?php echo htmlspecialchars($customer['customer_code'] ?? $customer['id']); ?></p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex gap-3">
                    <a href="portal.php?token=<?php echo htmlspecialchars($token); ?>" 
                       class="hidden md:inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition font-medium text-sm">
                        <i class="fas fa-home ml-2"></i> الرئيسية
                    </a>
                    <a href="create_order.php?token=<?php echo htmlspecialchars($token); ?>" 
                       class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-xl hover:bg-gray-800 transition font-bold text-sm shadow-lg shadow-gray-300">
                        <i class="fas fa-plus ml-2"></i> <span class="hidden sm:inline">طلب جديد</span><span class="sm:hidden">جديد</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Stats Dashboard -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 animate-fade-in">
            <!-- Stat 1 -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">إجمالي الطلبات</p>
                    <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['total_orders']); ?></h3>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl">
                    <i class="fas fa-file-invoice"></i>
                </div>
            </div>
            <!-- Stat 2 -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">القطع المطلوبة</p>
                    <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['total_items']); ?></h3>
                </div>
                <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl">
                    <i class="fas fa-boxes"></i>
                </div>
            </div>
            <!-- Stat 3 -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500 mb-1">إجمالي المدفوعات</p>
                    <h3 class="text-2xl font-bold text-emerald-600"><?php echo number_format($stats['total_spent']); ?> <span class="text-xs text-gray-400"><?php echo $currency; ?></span></h3>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                    <i class="fas fa-wallet"></i>
                </div>
            </div>
        </div>

        <!-- Section Title -->
        <div class="flex items-center justify-between mb-6 animate-fade-in delay-1">
            <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                <span class="w-1 h-6 bg-yellow-500 rounded-full"></span>
                سجل الطلبات السابقة
            </h2>
        </div>
        
        <!-- Empty State -->
        <?php if (empty($approvals)): ?>
            <div class="bg-white rounded-3xl p-12 text-center shadow-sm animate-fade-in delay-2">
                <div class="mb-4 text-gray-200">
                    <i class="fas fa-clipboard-list text-6xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 mb-2">لا توجد طلبات حتى الآن</h3>
                <p class="text-gray-500 mb-6">يمكنك البدء بإنشاء طلب جديد وسيظهر هنا للمتابعة.</p>
                <a href="create_order.php?token=<?php echo htmlspecialchars($token); ?>" class="inline-block px-6 py-3 bg-yellow-500 text-white rounded-xl hover:bg-yellow-600 transition font-bold shadow-lg shadow-yellow-200">
                    إنشاء أول طلب
                </a>
            </div>
        <?php else: ?>

            <!-- Desktop Table View (Hidden on Mobile) -->
            <div class="hidden md:block bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden animate-fade-in delay-2">
                <div class="overflow-x-auto">
                    <table class="w-full text-right">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase">#الطلب</th>
                                <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase">التاريخ</th>
                                <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase text-center">القطع</th>
                                <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase">المدفوع</th>
                                <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase text-center">الحالة</th>
                                <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase">ملاحظات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($approvals as $approval): 
                                $statusData = getStatusDetails($approval['status']);
                            ?>
                            <tr class="hover:bg-gray-50 transition duration-150 group">
                                <td class="px-6 py-4">
                                    <span class="font-bold text-gray-800 block">#<?php echo $approval['id']; ?></span>
                                    <?php if ($approval['final_order_number']): ?>
                                        <span class="text-xs text-blue-500 font-mono bg-blue-50 px-1.5 py-0.5 rounded mt-1 inline-block">Ref: <?php echo $approval['final_order_number']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <div class="flex items-center gap-2">
                                        <i class="far fa-calendar-alt text-gray-400"></i>
                                        <?php echo date('Y/m/d', strtotime($approval['created_at'])); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="bg-gray-100 text-gray-700 px-2 py-1 rounded-lg text-sm font-bold">
                                        <?php echo number_format($approval['total_quantity']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-bold text-gray-900"><?php echo number_format($approval['paid_amount'], 2); ?> <small class="text-gray-500 font-normal"><?php echo $currency; ?></small></div>
                                    <div class="text-xs text-gray-400 mt-0.5">الشحن: <?php echo number_format($approval['shipping_cost']); ?></div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border <?php echo $statusData['class']; ?>">
                                        <i class="fas <?php echo $statusData['icon']; ?>"></i>
                                        <?php echo $statusData['label']; ?>
                                    </span>
                                    <?php if($approval['status'] === 'rejected' && !empty($approval['rejection_reason'])): ?>
                                        <div class="mt-2 text-xs text-red-500 bg-red-50 p-2 rounded text-right">
                                            <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($approval['rejection_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                                    <?php echo !empty($approval['notes']) ? htmlspecialchars($approval['notes']) : '<span class="text-gray-300">-</span>'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Mobile Card View (Shown on Mobile) -->
            <div class="md:hidden space-y-4 animate-fade-in delay-2">
                <?php foreach ($approvals as $approval): 
                     $statusData = getStatusDetails($approval['status']);
                ?>
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-lg font-bold text-gray-900">#<?php echo $approval['id']; ?></span>
                                <?php if ($approval['final_order_number']): ?>
                                    <span class="text-xs font-mono bg-blue-50 text-blue-600 px-1.5 rounded border border-blue-100"><?php echo $approval['final_order_number']; ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="text-xs text-gray-500 block mt-1"><i class="far fa-clock ml-1"></i> <?php echo date('Y-m-d h:i A', strtotime($approval['created_at'])); ?></span>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold border <?php echo $statusData['class']; ?>">
                            <?php echo $statusData['label']; ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="bg-gray-50 p-3 rounded-xl">
                            <span class="text-xs text-gray-500 block mb-1">عدد القطع</span>
                            <span class="font-bold text-gray-800"><i class="fas fa-box ml-1 text-gray-400"></i> <?php echo number_format($approval['total_quantity']); ?></span>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-xl">
                            <span class="text-xs text-gray-500 block mb-1">المدفوع</span>
                            <span class="font-bold text-emerald-600"><?php echo number_format($approval['paid_amount']); ?> <?php echo $currency; ?></span>
                        </div>
                    </div>

                    <?php if($approval['status'] === 'rejected' && !empty($approval['rejection_reason'])): ?>
                        <div class="bg-red-50 text-red-800 p-3 rounded-xl text-sm border border-red-100 mb-3">
                            <strong class="block text-xs text-red-500 mb-1">سبب الرفض:</strong>
                            <?php echo htmlspecialchars($approval['rejection_reason']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($approval['notes'])): ?>
                        <div class="border-t border-gray-100 pt-3 mt-2">
                            <p class="text-sm text-gray-600">
                                <span class="text-gray-400 ml-1"><i class="fas fa-comment-alt"></i></span>
                                <?php echo htmlspecialchars($approval['notes']); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
        
        <!-- Simple Footer -->
        <div class="mt-12 text-center text-sm text-gray-400 pb-6">
            &copy; <?php echo date('Y'); ?> جميع الحقوق محفوظة.
        </div>
    </div>

</body>
</html>