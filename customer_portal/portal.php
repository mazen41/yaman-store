<?php
/**
 * Customer Portal - Specific Customer View
 * PREVIOUS FIXES: Mobile Optimized + No Overlay + Full Image Visibility
 * NEW: Added conditional "Shop/Products" button based on 'show_shop_for_customer' column, integrated into the top navigation bar.
 * طلباتي: simple legacy SQL on customer_orders (+ status join + small subqueries). Review/approval table keeps its own query.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('Invalid access. Please use the link provided to you.');
}

// 1. Validate Customer Token
$stmt = $db->prepare("SELECT * FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('Invalid or expired link. Please contact support.');
}

// NEW LOGIC: Determine if "Shop" button should be shown
// Assumes 'show_shop_for_customer' column exists in 'customers' table and is 1 for true.
// Default to false if the column doesn't exist to be safe.
$showShopLink = isset($customer['show_shop_for_customer']) && $customer['show_shop_for_customer'] == 1;

// Ensure the conditional value exists for existing 'enable_create_self_order' logic
if (!isset($customer['enable_create_self_order'])) {
    $customer['enable_create_self_order'] = 'inactive'; 
}

$customer_id = $customer['id'];

// NEW: Fetch Customer Cards
$customer_cards = [];
try {
    $cards_stmt = $db->prepare("SELECT id, card_number, current_balance, expiry_date, status FROM customer_cards WHERE customer_id = ? AND status = 'active' ORDER BY expiry_date ASC");
    $cards_stmt->execute([$customer_id]);
    $customer_cards = $cards_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Portal Error fetching customer cards: " . $e->getMessage());
    $customer_cards = [];
}

// 2. Fetch Slides
$slides = [];
try {
    $slides_stmt = $db->query("
        SELECT * FROM portal_slides 
        WHERE is_active = 1 
        ORDER BY 
            CASE WHEN display_order = 0 THEN 9999 ELSE display_order END ASC, 
            created_at DESC
    ");
    $slides = $slides_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $slides = [];
}

// 3. Totals Initialization for My Orders
$total_subtotal = 0; 
$total_discount = 0; 
$total_damaged = 0; 
$total_final = 0; 
$total_paid = 0; 
$total_remaining = 0; 
$total_quantity = 0;
$total_customer_orders = 0;
$total_all_discounts = 0;

$orders = [];
$order_status_options = [];

// Status Colors Helper
function getStatusClass($status) {
    $colors = [
        'new' => 'bg-blue-100 text-blue-800',
        'pending' => 'bg-orange-100 text-orange-800',
        'approved' => 'bg-teal-100 text-teal-800',
        'processing' => 'bg-blue-100 text-blue-800', 
        'in_preparation' => 'bg-yellow-100 text-yellow-800',
        'shipped' => 'bg-indigo-100 text-indigo-800',
        'out_for_delivery' => 'bg-purple-100 text-purple-800',
        'delivered' => 'bg-green-100 text-green-800',
        'completed' => 'bg-green-100 text-green-800',
        'cancelled' => 'bg-red-100 text-red-800',
        'rejected' => 'bg-red-100 text-red-800',
        'returned' => 'bg-gray-100 text-gray-800',
    ];
    return $colors[$status] ?? 'bg-gray-100 text-gray-800';
}

try {
    // Main customer orders (طلباتي): keep simple legacy query — no coupon CASE, no order_approvals flags.
    $query = "SELECT 
                co.*,
                COALESCE(NULLIF(co.status, ''), 'new') AS display_status_key,
                COALESCE(NULLIF(cos.status_name_ar, ''), NULLIF(co.status, ''), 'جديد') AS display_status_label,
                COALESCE((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = co.id), 0) AS total_quantity,
                (SELECT GROUP_CONCAT(invoice_number SEPARATOR ', ') FROM customer_invoices ci WHERE ci.order_id = co.id) AS invoice_numbers,
                co.automatic_discount_percentage AS display_discount_percentage,
                (SELECT oi.product_link FROM order_items oi WHERE oi.order_id = co.id AND oi.product_link IS NOT NULL AND oi.product_link <> '' ORDER BY oi.id LIMIT 1) AS first_product_link,
                EXISTS(SELECT 1 FROM order_approvals oa WHERE oa.final_order_id = co.id) AS is_self_order
            FROM customer_orders co
            LEFT JOIN customer_order_statuses cos ON co.status = cos.status_key
            WHERE co.customer_id = ? 
            AND co.status NOT IN ('cancelled', 'rejected')
            ORDER BY co.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute([$customer_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_customer_orders = count($orders);

    foreach ($orders as $order) {
        $status_key = $order['display_status_key'] ?? ($order['status'] ?? '');
        if ($status_key !== '') {
            $order_status_options[$status_key] = $order['display_status_label'] ?? $status_key;
        }
        $total_quantity += $order['total_quantity'];
        $total_subtotal += $order['subtotal_amount'];
        $total_discount += $order['discount_amount'];
        $total_damaged += (float) ($order['damaged_amount'] ?? 0);
        $total_final += $order['final_amount'];
        $total_paid += $order['paid_amount'];
        $total_remaining += ($order['final_amount'] - $order['paid_amount']);
        $total_all_discounts += $order['discount_amount'];
    }

} catch (PDOException $e) {
    $orders = [];
    error_log("Portal Error: " . $e->getMessage());
}

// --- PHP FOR SUBMITTED ORDERS SECTION ---
$approvals = [];
$submitted_stats = [
    'total_orders' => 0,
    'total_spent' => 0,
    'total_items' => 0
];
$currency = $customer['currency'] ?? 'YER'; 

function getSubmittedStatusDetails($status) {
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

if ($customer['enable_create_self_order'] === 'active') {
    try {
        // MODIFICATION: Filter out 'approved' orders and fetch necessary columns for display
        $query_approvals = "SELECT
                    oa.id,
                    oa.created_at,
                    oa.status,
                    oa.payment_proof_path,
                    oa.subtotal_amount,
                    oa.shipping_cost,
                    oa.coupon_code,
                    oa.coupon_discount_amount,
                    oa.automatic_discount_percentage,
                    oa.automatic_discount_amount,
                    oa.paid_amount,
                    oa.total_after_discounts,
                    oa.rejection_reason,
                    oa.notes,
                    COALESCE((SELECT SUM(oai.item_count) FROM order_approval_items oai WHERE oai.approval_id = oa.id), 0) AS total_quantity,
                    co.order_number AS final_order_number,
                    COALESCE(NULLIF(TRIM(co.order_link), ''),
                        (SELECT TRIM(oai.product_link) FROM order_approval_items oai WHERE oai.approval_id = oa.id AND TRIM(COALESCE(oai.product_link,'')) <> '' ORDER BY oai.id ASC LIMIT 1)
                    ) AS display_order_link,
                    COALESCE(NULLIF(TRIM(co.additional_link), ''),
                        (SELECT TRIM(oai.additional_link) FROM order_approval_items oai WHERE oai.approval_id = oa.id AND TRIM(COALESCE(oai.additional_link,'')) <> '' ORDER BY oai.id ASC LIMIT 1)
                    ) AS display_additional_link
                FROM order_approvals oa
                LEFT JOIN customer_orders co ON oa.final_order_id = co.id
                WHERE oa.customer_id = ?
                AND oa.status != 'approved'
                ORDER BY oa.created_at DESC";

        $stmt_approvals = $db->prepare($query_approvals);
        $stmt_approvals->execute([$customer_id]);
        $approvals = $stmt_approvals->fetchAll(PDO::FETCH_ASSOC);

        foreach ($approvals as $approval) {
            $submitted_stats['total_orders']++;
            $submitted_stats['total_items'] += $approval['total_quantity'];
            $submitted_stats['total_spent'] += $approval['paid_amount'];
        }

    } catch (PDOException $e) {
        $approvals = [];
        error_log("Submitted Orders Portal Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>بوابة العميل - <?php echo htmlspecialchars($customer['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; }
        
        /* --- SLIDER STYLES --- */
        .portal-slider { 
            position: relative; 
            width: 100%; 
            height: 450px; 
            margin-bottom: 2rem; 
            overflow: hidden; 
            border-radius: 20px; 
            background: transparent; 
            user-select: none;
            direction: ltr; 
        }

        .slider-container { 
            display: flex; 
            height: 100%; 
            width: 100%; 
            cursor: grab;
        }

        .slider-container:active {
            cursor: grabbing;
        }

        .slide { 
            flex: 0 0 100%; 
            width: 100%;
            height: 100%; 
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .slide-img-main { 
            width: 100%; 
            height: 100%; 
            object-fit: contain; 
            border-radius: 20px; 
            display: block;
        }

        .slider-dots { 
            position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); 
            display: flex; gap: 8px; z-index: 20; 
        }
        .slider-dot { 
            width: 8px; height: 8px; border-radius: 50%; background: rgba(0,0,0,0.2); 
            cursor: pointer; transition: all 0.3s ease; border: 1px solid rgba(0,0,0,0.1);
        }
        .slider-dot.active { background: #C7A46D; width: 25px; border-radius: 10px; opacity: 1; }
        
        .portal-nav-drawer { display: none; }
        .portal-nav-drawer.is-open { display: block; }
        @media (min-width: 768px) {
            .portal-nav-drawer { display: none !important; }
        }
        @media (max-width: 768px) {
            .portal-slider { height: 200px; min-height: auto; border-radius: 15px; margin-bottom: 1.5rem; }
            .slide-img-main { border-radius: 15px; }
            .nav-logo { max-height: 32px; }
        }

        /* Scrollbar styles for both tables */
        .overflow-x-auto::-webkit-scrollbar { width: 6px; height: 6px; }
        .overflow-x-auto::-webkit-scrollbar-track { background: #f1f1f1; }
        .overflow-x-auto::-webkit-scrollbar-thumb { background: #ef4444; border-radius: 4px; }
        .overflow-x-auto::-webkit-scrollbar-thumb:hover { background: #dc2626; }


        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }

        .card-glow-effect {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        .card-glow-effect:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        .card-number-display {
            letter-spacing: 2px;
            font-family: monospace;
        }
    </style>
</head>
<body class="bg-gray-50 pb-10">
    
    <!-- Navbar -->
    <nav class="text-white shadow-lg sticky top-0 z-50" style="background: linear-gradient(135deg, #C7A46D, #9e7f4e);">
        <div class="max-w-6xl mx-auto px-3 sm:px-4 min-h-[4rem] flex flex-wrap items-center justify-between gap-2 py-2 nav-container">
            <div class="flex items-center gap-2 sm:gap-4 min-w-0 flex-1 md:flex-initial">
                <button type="button" class="portal-nav-toggle md:hidden flex-shrink-0 w-10 h-10 rounded-lg bg-white/15 hover:bg-white/25 flex items-center justify-center border border-white/20" onclick="document.getElementById('portalNavDrawer').classList.add('is-open')" aria-label="القائمة">
                    <i class="fas fa-bars text-lg"></i>
                </button>
                <div class="flex items-center min-w-0 gap-2 sm:gap-3">
                    <i class="fas fa-user-circle text-2xl sm:text-3xl flex-shrink-0"></i>
                    <div class="truncate min-w-0">
                        <h1 class="text-base sm:text-lg font-bold truncate leading-tight"><?php echo htmlspecialchars($customer['name']); ?></h1>
                        <p class="text-[10px] sm:text-xs opacity-80 truncate">CUST<?php echo str_pad($customer['id'], 3, '0', STR_PAD_LEFT); ?></p>
                    </div>
                </div>
            </div>

            <div class="portal-nav-desktop hidden md:flex items-center gap-3 flex-shrink-0">
                <?php if ($showShopLink): ?>
                    <a href="products.php?token=<?php echo htmlspecialchars($token); ?>"
                       class="inline-flex items-center px-3 py-1.5 text-sm font-semibold rounded-full text-white bg-red-500 hover:bg-red-600 transition-colors whitespace-nowrap">
                        <i class="fas fa-store-alt ml-1"></i>
                        <span>المنتجات</span>
                    </a>
                <?php endif; ?>
                <img src="../assets/images/yamman_logo.png" alt="Yamman Logo" class="h-9 md:h-12 w-auto object-contain nav-logo" style="border-radius: 8px;">
            </div>

            <div class="flex md:hidden flex-shrink-0">
                <img src="../assets/images/yamman_logo.png" alt="" class="h-8 w-auto object-contain nav-logo rounded-lg">
            </div>
        </div>
    </nav>

    <div id="portalNavDrawer" class="portal-nav-drawer fixed inset-0 z-[60]">
        <div class="absolute inset-0 bg-black/40" onclick="document.getElementById('portalNavDrawer').classList.remove('is-open')"></div>
        <div class="absolute top-0 right-0 h-full w-[min(100%,280px)] shadow-2xl p-5 pt-16" style="background: linear-gradient(180deg, #2d2419 0%, #1a1510 100%);">
            <button type="button" class="absolute top-4 left-4 w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center" onclick="document.getElementById('portalNavDrawer').classList.remove('is-open')" aria-label="إغلاق">
                <i class="fas fa-times"></i>
            </button>
            <div class="flex flex-col gap-3 text-right">
                <?php if ($showShopLink): ?>
                    <a href="products.php?token=<?php echo htmlspecialchars($token); ?>" class="block w-full text-center py-3 rounded-xl bg-red-500 hover:bg-red-600 font-bold text-white" onclick="document.getElementById('portalNavDrawer').classList.remove('is-open')">
                        <i class="fas fa-store-alt ml-2"></i> المنتجات
                    </a>
                <?php endif; ?>
                <?php if ($customer['enable_create_self_order'] === 'active'): ?>
                    <a href="create_order.php?token=<?php echo htmlspecialchars($token); ?>" class="block w-full text-center py-3 rounded-xl bg-green-600 hover:bg-green-700 font-bold text-white" onclick="document.getElementById('portalNavDrawer').classList.remove('is-open')">
                        <i class="fas fa-plus-circle ml-2"></i> إنشاء طلب
                    </a>
                <?php endif; ?>
                <p class="text-xs text-white/60 mt-4">بوابة العميل</p>
            </div>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 py-8">
        
        <!-- Image Slider Section -->
        <?php if (!empty($slides)): ?>
        <div class="portal-slider">
            <div class="slider-container" id="sliderContainer">
                <?php foreach ($slides as $index => $slide): 
                    $imgUrl = "../" . htmlspecialchars($slide['image_path']);
                ?>
                <div class="slide">
                    <?php if (!empty($slide['link_url'])): ?><a href="<?php echo htmlspecialchars($slide['link_url']); ?>" target="_blank" class="w-full h-full block relative"><?php endif; ?>
                    <img src="<?php echo $imgUrl; ?>" alt="Slide" class="slide-img-main">
                    <?php if (!empty($slide['link_url'])): ?></a><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($slides) > 1): ?>
            <div class="slider-dots">
                <?php foreach ($slides as $i => $s): ?>
                <button class="slider-dot <?php echo $i === 0 ? 'active' : ''; ?>" onclick="goToSlide(<?php echo $i; ?>)"></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
            let currentSlide = 0;
            const totalSlides = <?php echo count($slides); ?>;
            const container = document.getElementById('sliderContainer');
            const dots = document.querySelectorAll('.slider-dot');
            let autoSlideInterval;
            
            container.style.transition = 'transform 0.5s cubic-bezier(0.25, 1, 0.5, 1)';

            function updateSliderPosition() {
                container.style.transform = `translateX(-${currentSlide * 100}%)`;
                dots.forEach((dot, idx) => dot.classList.toggle('active', idx === currentSlide));
            }

            function goToSlide(index) {
                currentSlide = index;
                if (currentSlide < 0) currentSlide = totalSlides - 1;
                if (currentSlide >= totalSlides) currentSlide = 0;
                container.style.transition = 'transform 0.5s cubic-bezier(0.25, 1, 0.5, 1)';
                updateSliderPosition();
            }

            function startAutoSlide() {
                clearInterval(autoSlideInterval);
                if(totalSlides > 1) {
                    autoSlideInterval = setInterval(() => { goToSlide(currentSlide + 1); }, 5000);
                }
            }

            let isDragging = false;
            let startX = 0;

            container.addEventListener('touchstart', (e) => {
                startX = e.touches[0].clientX;
                isDragging = true;
                clearInterval(autoSlideInterval);
            }, {passive: true});

            container.addEventListener('mousedown', (e) => {
                startX = e.clientX;
                isDragging = true;
                clearInterval(autoSlideInterval);
                e.preventDefault();
            });

            container.addEventListener('touchend', (e) => {
                if(!isDragging) return;
                let endX = e.changedTouches[0].clientX;
                handleSwipe(startX, endX);
                isDragging = false;
                startAutoSlide();
            });

            container.addEventListener('mouseup', (e) => {
                if(!isDragging) return;
                let endX = e.clientX;
                handleSwipe(startX, endX);
                isDragging = false;
                startAutoSlide();
            });
            
            container.addEventListener('mouseleave', (e) => {
                if (isDragging) {
                    let endX = e.clientX;
                    handleSwipe(startX, endX);
                    isDragging = false;
                    startAutoSlide();
                }
            });

            function handleSwipe(start, end) {
                const threshold = 50; 
                if (start - end > threshold) goToSlide(currentSlide + 1);
                else if (end - start > threshold) goToSlide(currentSlide - 1);
            }
            startAutoSlide();
        </script>
        <?php endif; ?>

        <!-- Customer Cards Section -->
        <?php if (!empty($customer_cards)): ?>
        <div class="mt-8">
            <div class="flex items-center justify-between mb-6 animate-fade-in delay-1">
                <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                    <span class="w-1 h-6 bg-blue-500 rounded-full"></span>
                    بطاقاتي
                </h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($customer_cards as $card): 
                    $is_expired = ($card['expiry_date'] && strtotime($card['expiry_date']) < time());
                    $card_status_class = 'bg-green-100 text-green-800';
                    $card_status_text = 'نشطة';
                    if ($is_expired) {
                        $card_status_class = 'bg-red-100 text-red-800';
                        $card_status_text = 'منتهية';
                    } elseif ($card['status'] === 'inactive') {
                         $card_status_class = 'bg-gray-100 text-gray-800';
                         $card_status_text = 'غير نشطة';
                    } elseif ($card['status'] === 'blocked') {
                        $card_status_class = 'bg-red-100 text-red-800';
                        $card_status_text = 'محظورة';
                    }
                ?>
                <div class="bg-white rounded-xl p-6 shadow-md border border-gray-100 card-glow-effect">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-credit-card text-blue-500"></i> بطاقة العميل 
                            <span class="text-sm font-normal text-gray-500">#<?php echo htmlspecialchars($card['id']); ?></span>
                        </h3>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $card_status_class; ?>">
                            <?php echo htmlspecialchars($card_status_text); ?>
                        </span>
                    </div>

                    <div class="mb-4">
                        <p class="text-sm text-gray-500 mb-1">رقم البطاقة:</p>
                        <p class="text-2xl font-bold text-gray-900 card-number-display"><?php echo htmlspecialchars($card['card_number']); ?></p>
                    </div>

                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-gray-500 mb-1">الرصيد الحالي:</p>
                            <p class="font-bold text-green-600"><?php echo number_format($card['current_balance'], 2); ?> ريال</p>
                        </div>
                        <div>
                            <p class="text-gray-500 mb-1">تاريخ الانتهاء:</p>
                            <p class="font-bold <?php echo $is_expired ? 'text-red-600' : 'text-gray-700'; ?>">
                                <?php echo $card['expiry_date'] ? date('m/Y', strtotime($card['expiry_date'])) : 'غير محدد'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Orders Table (MY ORDERS) -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden mt-8">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <h2 class="text-xl font-bold text-gray-800"><i class="fas fa-list ml-2"></i> طلباتي</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 w-full md:w-auto">
                    <input type="text" id="ordersSearch" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="بحث في طلباتي...">
                    <select id="ordersStatusFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        <option value="">كل الحالات</option>
                        <?php foreach ($order_status_options as $key => $label): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-right">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">رقم الطلب</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">تاريخ الطلب</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">عدد القطع</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">رابط الطلب</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">رابط إضافي</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">الحالة</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">المبلغ الأصلي</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">الخصم</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">نسبة الخصم</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">تالف / منتهي</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">المبلغ النهائي</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">المدفوع</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">المتبقي</th>
                            <th class="px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-500 uppercase">رقم الفاتورة</th>
                            <th class="px-4 py-3 whitespace-nowrap text-center text-xs font-medium text-gray-500 uppercase">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody" class="bg-white divide-y divide-gray-200">
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="15" class="px-4 py-8 text-center text-gray-500">لا توجد طلبات مسجلة حتى الآن</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): 
                                // Calculation logic exactly like Admin page
                                $remaining = $order['final_amount'] - $order['paid_amount'];
                                
                                $display_discount_pct = floatval($order['display_discount_percentage'] ?? 0);
                                $display_status_key = $order['display_status_key'] ?? ($order['status'] ?? 'new');
                                $status_text = $order['display_status_label'] ?? $display_status_key;
                                $status_class = getStatusClass($display_status_key);
                            ?>
                            <tr class="hover:bg-gray-50 transition" data-status="<?php echo htmlspecialchars($display_status_key); ?>">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <a href="order_details.php?token=<?php echo $token; ?>&order_id=<?php echo (int)$order['id']; ?>" class="text-blue-600 font-bold hover:underline"><?php echo htmlspecialchars($order['order_number']); ?></a>
                                        <?php if (!empty($order['is_self_order'])): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700 border border-purple-200" title="طلب ذاتي (من نظام المراجعة)">
                                                <i class="fas fa-user-edit ml-1"></i> ذاتي
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600"><?php echo date('Y-m-d', strtotime($order['created_at'])); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-center font-bold text-gray-900"><?php echo number_format($order['total_quantity']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <?php
                                    $pLink = !empty(trim($order['order_link'] ?? '')) ? trim($order['order_link']) : trim($order['first_product_link'] ?? '');
                                    if (!empty($pLink)): ?>
                                        <a href="<?php echo htmlspecialchars($pLink); ?>" target="_blank" class="px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-800 text-xs rounded transition"><i class="fas fa-link ml-1"></i> رابط</a>
                                    <?php else: echo '-'; endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <?php if (!empty($order['additional_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($order['additional_link']); ?>" target="_blank" class="px-2 py-1 bg-amber-100 hover:bg-amber-200 text-amber-800 text-xs rounded transition"><i class="fas fa-link ml-1"></i> رابط</a>
                                    <?php else: echo '-'; endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($status_text); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-blue-500"><?php echo number_format($order['subtotal_amount']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-green-500"><?php echo number_format($order['discount_amount']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-center text-amber-600">
                                    <?php 
                                    if ($display_discount_pct > 0.01) {
                                        echo round($display_discount_pct) . '%';
                                        if (!empty($order['coupon_id'])) {
                                            echo ' <i class="fas fa-ticket-alt text-green-600" title="كوبون"></i>';
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-red-500"><?php echo number_format((float) ($order['damaged_amount'] ?? 0)); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-emerald-600"><?php echo number_format($order['final_amount']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-blue-600"><?php echo number_format($order['paid_amount']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-bold <?php echo $remaining > 0.01 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo number_format($remaining); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600"><?php echo !empty($order['invoice_numbers']) ? $order['invoice_numbers'] : '<span class="text-gray-400 italic">بانتظار الفاتورة</span>'; ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-center">
                                    <a href="order_details.php?token=<?php echo $token; ?>&order_id=<?php echo (int)$order['id']; ?>" 
                                       class="inline-flex items-center justify-center px-4 py-2 bg-[#C7A46D] hover:bg-[#B8956A] text-white text-sm font-bold rounded-lg transition-all shadow-sm w-24">
                                       <i class="fas fa-eye ml-2"></i>
                                       <span>عرض</span>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($orders)): ?>
                    <tfoot class="bg-gray-100 font-bold border-t-2 border-gray-300">
                        <tr>
                            <td colspan="2" class="px-4 py-3 whitespace-nowrap">الإجمالي العام</td>
                            <td class="px-4 py-3 text-center whitespace-nowrap"><?php echo number_format($total_quantity); ?></td>
                            <td colspan="3"></td>
                            <td class="px-4 py-3 whitespace-nowrap text-blue-500"><?php echo number_format($total_subtotal); ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-green-500"><?php echo number_format($total_discount); ?></td>
                            <td></td>
                            <td class="px-4 py-3 whitespace-nowrap text-red-500"><?php echo number_format($total_damaged); ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-emerald-600"><?php echo number_format($total_final); ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-blue-600"><?php echo number_format($total_paid); ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-red-600"><?php echo number_format($total_remaining); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($customer['enable_create_self_order'] === 'active'): ?>

            <div class="mt-6 flex justify-center">
                <a href="create_order.php?token=<?php echo htmlspecialchars($token); ?>" 
                   class="inline-flex items-center justify-center px-8 py-3 bg-green-600 hover:bg-green-700 text-white text-lg font-bold rounded-xl transition-all shadow-xl">
                    <i class="fas fa-plus-circle ml-2"></i>
                    <span>طلب جديد</span>
                </a>
            </div>

            <!-- SUBMITTED ORDERS SECTION -->
            <div class="mt-12">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6 animate-fade-in delay-1">
                    <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                        <span class="w-1 h-6 bg-red-500 rounded-full"></span>
                        طلباتي للمراجعة (قيد الانتظار/مرفوضة)
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 w-full md:w-auto">
                        <input type="text" id="approvalsSearch" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="بحث في طلبات المراجعة...">
                        <select id="approvalsStatusFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">كل الحالات</option>
                            <option value="pending">قيد المراجعة</option>
                            <option value="rejected">مرفوض</option>
                        </select>
                    </div>
                </div>

                <?php if (empty($approvals)): ?>
                    <div class="bg-white rounded-2xl p-8 text-center shadow-sm animate-fade-in delay-2 border border-gray-100">
                        <div class="mb-4 text-gray-200">
                            <i class="fas fa-clipboard-check text-6xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800 mb-2">لا توجد طلبات مرسلة للمراجعة بعد</h3>
                        <p class="text-gray-500 mb-6">يمكنك إرسال طلبات جديدة للمراجعة وستظهر هنا للمتابعة.</p>
                        <a href="create_order.php?token=<?php echo htmlspecialchars($token); ?>" class="inline-block px-6 py-3 bg-red-500 text-white rounded-xl hover:bg-red-600 transition font-bold shadow-lg shadow-red-200">
                            إنشاء طلب جديد
                        </a>
                    </div>
                <?php else: ?>

                    <!-- Submitted Orders Table (now always a scrollable table) -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden animate-fade-in delay-2">
                        <div class="overflow-x-auto"> <!-- Added overflow-x-auto here -->
                            <table class="min-w-full text-right divide-y divide-gray-100">
                                <thead class="bg-red-50 border-b border-red-200">
                                    <tr>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">رقم الطلب</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">تاريخ الطلب</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">عدد القطع</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">رابط الطلب</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">رابط إضافي</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">الحالة</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">المبلغ الأصلي</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">الخصم</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">نسبة الخصم</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">تالف / منتهي</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">المبلغ النهائي</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">المدفوع</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">المتبقي</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-xs font-bold text-red-800 uppercase">رقم الفاتورة</th>
                                        <th class="px-4 py-3 whitespace-nowrap text-center text-xs font-bold text-red-800 uppercase">الإجراءات / ملاحظات</th>
                                    </tr>
                                </thead>
                                <tbody id="approvalsTableBody" class="divide-y divide-gray-100">
                                    <?php foreach ($approvals as $approval): 
                                        $statusData = getSubmittedStatusDetails($approval['status']);
                                        $total_discount_amount = $approval['coupon_discount_amount'] + $approval['automatic_discount_amount'];
                                        $display_discount_pct_approval = 0;
                                        if ($approval['subtotal_amount'] > 0) {
                                            $display_discount_pct_approval = ($total_discount_amount / $approval['subtotal_amount']) * 100;
                                        }

                                        // Total payable amount including shipping
                                        // Assumption: total_after_discounts is subtotal - all_discounts. Then shipping is added.
                                        $total_payable_approval = $approval['total_after_discounts'] + $approval['shipping_cost'];
                                        $remaining_approval = $total_payable_approval - $approval['paid_amount'];
                                    ?>
                                    <tr class="hover:bg-red-50 transition duration-150 group" data-status="<?php echo htmlspecialchars($approval['status'] ?? ''); ?>">
                                        <!-- 1. رقم الطلب -->
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="font-bold text-gray-800 block">#<?php echo $approval['id']; ?></span>
                                            <?php if ($approval['final_order_number']): ?>
                                                <span class="text-xs text-blue-500 font-mono bg-blue-50 px-1.5 py-0.5 rounded mt-1 inline-block">Ref: <?php echo $approval['final_order_number']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- 2. تاريخ الطلب -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600"><?php echo date('Y-m-d', strtotime($approval['created_at'])); ?></td>
                                        <!-- 3. عدد القطع -->
                                        <td class="px-4 py-3 whitespace-nowrap text-center font-bold text-gray-900"><?php echo number_format($approval['total_quantity']); ?></td>
                                        <!-- 4. رابط الطلب -->
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php if (!empty($approval['display_order_link'])): ?>
                                                <a href="<?php echo htmlspecialchars($approval['display_order_link']); ?>" target="_blank" class="px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-800 text-xs rounded transition"><i class="fas fa-link ml-1"></i> رابط</a>
                                            <?php else: echo '-'; endif; ?>
                                        </td>
                                        <!-- 5. رابط إضافي -->
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php if (!empty($approval['display_additional_link'])): ?>
                                                <a href="<?php echo htmlspecialchars($approval['display_additional_link']); ?>" target="_blank" class="px-2 py-1 bg-amber-100 hover:bg-amber-200 text-amber-800 text-xs rounded transition"><i class="fas fa-link ml-1"></i> رابط</a>
                                            <?php else: echo '-'; endif; ?>

                                        </td>
                                        <!-- 6. الحالة -->
                                        <td class="px-4 py-3 whitespace-nowrap">
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
                                        <!-- 7. المبلغ الأصلي -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-blue-500"><?php echo number_format($approval['subtotal_amount'], 2); ?></td>
                                        <!-- 8. الخصم -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-green-500"><?php echo number_format($total_discount_amount, 2); ?></td>
                                        <!-- 9. نسبة الخصم -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-center text-amber-600">
                                            <?php 
                                            if ($display_discount_pct_approval > 0.01) {
                                                echo round($display_discount_pct_approval) . '%';
                                                if (!empty($approval['coupon_code'])) {
                                                    echo ' <i class="fas fa-ticket-alt text-green-600" title="كوبون"></i>';
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <!-- 10. تالف / منتهي -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">-</td>
                                        <!-- 11. المبلغ النهائي -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-emerald-600"><?php echo number_format($total_payable_approval, 2); ?></td>
                                        <!-- 12. المدفوع -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-blue-600">
                                            <?php echo number_format($approval['paid_amount'], 2); ?>
                                            <div class="text-xs text-gray-400 mt-0.5 font-normal">شحن: <?php echo number_format($approval['shipping_cost'], 2); ?></div>
                                        </td>
                                        <!-- 13. المتبقي -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-bold <?php echo $remaining_approval > 0.01 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo number_format($remaining_approval, 2); ?></td>
                                        <!-- 14. رقم الفاتورة -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">-</td>
                                        <!-- 15. الإجراءات / ملاحظات -->
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo !empty($approval['notes']) ? htmlspecialchars($approval['notes']) : '-'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php endif; ?>
            </div>

        <?php endif; ?>
        
        <!-- Footer -->
        <div class="mt-12 text-center text-sm text-gray-400 pb-6">
            &copy; <?php echo date('Y'); ?> جميع الحقوق محفوظة.
        </div>
    </div>

    <script>

        function setupPortalTableFilter(searchId, statusId, tbodyId) {
            const searchInput = document.getElementById(searchId);
            const statusSelect = document.getElementById(statusId);
            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;
            const applyFilter = () => {
                const term = (searchInput?.value || '').toLowerCase().trim();
                const status = statusSelect?.value || '';
                tbody.querySelectorAll('tr').forEach(row => {
                    const matchesText = !term || row.textContent.toLowerCase().includes(term);
                    const matchesStatus = !status || row.dataset.status === status;
                    row.style.display = (matchesText && matchesStatus) ? '' : 'none';
                });
            };
            searchInput?.addEventListener('input', applyFilter);
            statusSelect?.addEventListener('change', applyFilter);
        }
        setupPortalTableFilter('ordersSearch', 'ordersStatusFilter', 'ordersTableBody');
        setupPortalTableFilter('approvalsSearch', 'approvalsStatusFilter', 'approvalsTableBody');

        function copyToClipboard(button, textToCopy) {
            navigator.clipboard.writeText(textToCopy).then(() => {
                const originalText = button.getAttribute('data-original-text');
                button.innerHTML = '<i class="fas fa-check ml-1"></i> <span>تم النسخ!</span>';
                setTimeout(() => {
                    button.innerHTML = '<i class="fas fa-copy ml-1"></i> <span>' + originalText + '</span>';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
                alert('فشل في نسخ الرابط. يرجى المحاولة يدوياً.');
            });
        }
    </script>
</body>
</html>