<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/status_helpers.php';
$page_title = 'المراجعة المالية';

// --- مصفوفة ترجمة طرق الدفع من الإنجليزية للعربية ---
$payment_methods_ar = [
    'cash' => 'نقداً',
    'transfer' => 'تحويل بنكي',
    'kuraimi' => 'الكريمي',
    'al_kuraimi' => 'الكريمي',
    'cacc_bank' => 'كاك بنك',
    'yemen_kuwait_bank' => 'بنك اليمن والكويت',
    'wallet' => 'محفظة إلكترونية',
    'check' => 'شيك',
    'other' => 'أخرى',
    'غير محدد' => 'غير محدد'
];

// --- 1. SET TIMEZONE & DATE HELPER ---
date_default_timezone_set('Asia/Aden');

/**
 * Formats a date string to Yemen Time (Asia/Aden).
 * Updated to use 12-hour format with AM/PM (h:i A).
 */
function formatToYemenTime($dateString, $format = 'Y-m-d h:i A') {
    if (empty($dateString)) return '-';
    try {
        // Create DateTime object from the string (assuming DB is UTC)
        $date = new DateTime($dateString, new DateTimeZone('UTC'));
        // Convert to Yemen Time
        $date->setTimezone(new DateTimeZone('Asia/Aden'));
        return $date->format($format);
    } catch (Exception $e) {
        // Fallback if date parsing fails
        return $dateString;
    }
}


// معالجة طلب المراجعة عبر AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'review') {
    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    $transaction_type = $_POST['transaction_type'] ?? '';
    $review_note = trim($_POST['review_note'] ?? '');

    if ($transaction_id && $transaction_type) {
        try {
            $update_stmt = null;
            if ($transaction_type === 'order') {
                $update_stmt = $db->prepare("UPDATE customer_orders SET review_status = 'reviewed', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?");
            } elseif ($transaction_type === 'basket') {
                $update_stmt = $db->prepare("UPDATE purchase_baskets SET review_status = 'reviewed', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?");
            } elseif ($transaction_type === 'expense') {
                $update_stmt = $db->prepare("UPDATE expenses SET review_status = 'reviewed', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?");
            } elseif ($transaction_type === 'payment') {
                $update_stmt = $db->prepare("UPDATE customer_payments SET review_status = 'reviewed', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?");
            } elseif ($transaction_type === 'order_status_history') { 
                $update_stmt = $db->prepare("UPDATE order_status_history SET review_status = 'reviewed', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?");
            } elseif ($transaction_type === 'order_state_history') { 
                $update_stmt = $db->prepare("UPDATE order_state_history SET review_status = 'reviewed', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?");
            }

            if ($update_stmt) {
                $update_stmt->execute([$_SESSION['user_id'], $review_note, $transaction_id]);
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit();
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid transaction type.']);
                exit();
            }
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit();
        }
    }
}

// استكمال جلب البيانات
$filter = $_GET['filter'] ?? 'all';
$search_query = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';
$review_status_filter = $_GET['review_status'] ?? 'pending'; // التعديل هنا: الافتراضي قيد المراجعة فقط
$amount_filter = $_GET['amount'] ?? ''; // التعديل هنا: إضافة متغير المبلغ الجديد
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$bank_account_filter = $_GET['bank_account'] ?? '';

try {
    $orders_query = "SELECT DISTINCT o.id, CAST('order' AS CHAR(50)) COLLATE utf8mb4_unicode_ci as transaction_type, CAST(o.order_number AS CHAR(255)) COLLATE utf8mb4_unicode_ci as transaction_number, CAST(o.invoice_number AS CHAR(255)) COLLATE utf8mb4_unicode_ci as reference_number, o.customer_id, CAST(o.final_amount AS DECIMAL(10,2)) as amount, CAST(o.status AS CHAR(50)) COLLATE utf8mb4_unicode_ci as status, CAST(COALESCE(o.payment_method, 'غير محدد') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as payment_method, CAST(COALESCE(o.review_status, 'pending') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as review_status, o.reviewed_at, o.reviewed_by, CAST(COALESCE(o.review_note, '') AS CHAR(1000)) COLLATE utf8mb4_unicode_ci as review_note, o.created_at, o.updated_at, CAST(COALESCE(c.name, '') AS CHAR(255)) COLLATE utf8mb4_unicode_ci as customer_name, CAST(COALESCE(c.mobile_number, '') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as mobile_number, CAST(COALESCE(u.username, '') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as reviewed_by_name, CAST(NULL AS CHAR(255)) as bank_name FROM customer_orders o LEFT JOIN customers c ON o.customer_id = c.id LEFT JOIN users u ON o.reviewed_by = u.id";
    
    $baskets_query = "SELECT DISTINCT pb.id, CAST('basket' AS CHAR(50)) COLLATE utf8mb4_unicode_ci as transaction_type, CAST(pb.basket_code AS CHAR(255)) COLLATE utf8mb4_unicode_ci as transaction_number, CAST(NULL AS CHAR(255)) COLLATE utf8mb4_unicode_ci as reference_number, NULL as customer_id, CAST(pb.final_amount AS DECIMAL(10,2)) as amount, CAST(pb.status AS CHAR(50)) COLLATE utf8mb4_unicode_ci as status, CAST('غير محدد' AS CHAR(100)) COLLATE utf8mb4_unicode_ci as payment_method, CAST(COALESCE(pb.review_status, 'pending') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as review_status, pb.reviewed_at, pb.reviewed_by, CAST(COALESCE(pb.review_note, '') AS CHAR(1000)) COLLATE utf8mb4_unicode_ci as review_note, pb.created_at, pb.updated_at, CAST('سلة شراء' AS CHAR(255)) COLLATE utf8mb4_unicode_ci as customer_name, CAST('' AS CHAR(50)) COLLATE utf8mb4_unicode_ci as mobile_number, CAST(COALESCE(u.username, '') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as reviewed_by_name, CAST(NULL AS CHAR(255)) as bank_name FROM purchase_baskets pb LEFT JOIN users u ON pb.reviewed_by = u.id";
    
    $expenses_query = "SELECT DISTINCT e.id, CAST('expense' AS CHAR(50)) COLLATE utf8mb4_unicode_ci as transaction_type, CAST(e.expense_number AS CHAR(255)) COLLATE utf8mb4_unicode_ci as transaction_number, CAST(e.reference_number AS CHAR(255)) COLLATE utf8mb4_unicode_ci as reference_number, NULL as customer_id, CAST(e.amount AS DECIMAL(10,2)) as amount, CAST(e.status AS CHAR(50)) COLLATE utf8mb4_unicode_ci as status, CAST(COALESCE(e.payment_method, 'غير محدد') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as payment_method, CAST(COALESCE(e.review_status, 'pending') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as review_status, e.reviewed_at, e.reviewed_by, CAST(COALESCE(e.review_note, '') AS CHAR(1000)) COLLATE utf8mb4_unicode_ci as review_note, e.created_at, e.updated_at, CAST(COALESCE(e.vendor_name, 'مصروف') AS CHAR(255)) COLLATE utf8mb4_unicode_ci as customer_name, CAST(COALESCE(e.vendor_phone, '') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as mobile_number, CAST(COALESCE(u.username, '') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as reviewed_by_name, CAST(NULL AS CHAR(255)) as bank_name FROM expenses e LEFT JOIN users u ON e.reviewed_by = u.id";
    
    $payments_query = "SELECT DISTINCT cp.id, CAST('payment' AS CHAR(50)) COLLATE utf8mb4_unicode_ci as transaction_type, CAST(cp.payment_number AS CHAR(255)) COLLATE utf8mb4_unicode_ci as transaction_number, CAST(cp.reference_number AS CHAR(255)) COLLATE utf8mb4_unicode_ci as reference_number, cp.customer_id, CAST(cp.amount AS DECIMAL(10,2)) as amount, CAST('paid' AS CHAR(50)) COLLATE utf8mb4_unicode_ci as status, CAST(COALESCE(cp.payment_method, 'غير محدد') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as payment_method, CAST(COALESCE(cp.review_status, 'pending') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as review_status, cp.reviewed_at, cp.reviewed_by, CAST(COALESCE(cp.review_note, '') AS CHAR(1000)) COLLATE utf8mb4_unicode_ci as review_note, cp.created_at, cp.updated_at, CAST(COALESCE(c.name, 'دفعة عميل') AS CHAR(255)) COLLATE utf8mb4_unicode_ci as customer_name, CAST(COALESCE(c.mobile_number, '') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as mobile_number, CAST(COALESCE(u.username, '') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as reviewed_by_name, CAST(COALESCE(ba.bank_name, '') AS CHAR(255)) COLLATE utf8mb4_unicode_ci as bank_name FROM customer_payments cp LEFT JOIN customers c ON cp.customer_id = c.id LEFT JOIN users u ON cp.reviewed_by = u.id LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id";

    $order_status_history_query = "
        SELECT 
            osh.id, 
            CAST('order_status_history' AS CHAR(50)) COLLATE utf8mb4_unicode_ci as transaction_type, 
            CAST(CONCAT('تغيير حالة طلب #', co.order_number) AS CHAR(255)) COLLATE utf8mb4_unicode_ci as transaction_number, 
            CAST(osh.status AS CHAR(255)) COLLATE utf8mb4_unicode_ci as reference_number,
            osh.order_id as customer_id, 
            CAST(0 AS DECIMAL(10,2)) as amount, 
            CAST(osh.status AS CHAR(50)) COLLATE utf8mb4_unicode_ci as status,
            CAST('سجل حالات' AS CHAR(100)) COLLATE utf8mb4_unicode_ci as payment_method, 
            CAST(COALESCE(osh.review_status, 'pending') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as review_status, 
            osh.reviewed_at, 
            osh.reviewed_by, 
            CAST(COALESCE(osh.review_note, '') AS CHAR(1000)) COLLATE utf8mb4_unicode_ci as review_note, 
            osh.created_at, 
            osh.created_at as updated_at, 
            CAST(COALESCE(c.name, 'غير محدد') AS CHAR(255)) COLLATE utf8mb4_unicode_ci as customer_name, 
            CAST(COALESCE(c.mobile_number, '') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as mobile_number, 
            CAST(COALESCE(u.username, '') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as reviewed_by_name,
            CAST(NULL AS CHAR(255)) as bank_name
        FROM order_status_history osh
        LEFT JOIN customer_orders co ON osh.order_id = co.id
        LEFT JOIN customers c ON co.customer_id = c.id
        LEFT JOIN users u ON osh.reviewed_by = u.id
    ";

    $order_state_history_query = "
        SELECT 
            osh.id, 
            CAST('order_state_history' AS CHAR(50)) COLLATE utf8mb4_unicode_ci as transaction_type, 
            CAST(CONCAT('تغيير حالة طلب (جديد) #', co.order_number) AS CHAR(255)) COLLATE utf8mb4_unicode_ci as transaction_number, 
            CAST(osh.status AS CHAR(255)) COLLATE utf8mb4_unicode_ci as reference_number,
            osh.order_id as customer_id, 
            CAST(0 AS DECIMAL(10,2)) as amount, 
            CAST(osh.status AS CHAR(50)) COLLATE utf8mb4_unicode_ci as status,
            CAST('سجل حالات (جديد)' AS CHAR(100)) COLLATE utf8mb4_unicode_ci as payment_method, 
            CAST(COALESCE(osh.review_status, 'pending') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as review_status, 
            osh.reviewed_at, 
            osh.reviewed_by, 
            CAST(COALESCE(u.username, '') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as review_note, 
            osh.created_at, 
            osh.created_at as updated_at, 
            CAST(COALESCE(c.name, 'غير محدد') AS CHAR(255)) COLLATE utf8mb4_unicode_ci as customer_name, 
            CAST(COALESCE(c.mobile_number, '') AS CHAR(50)) COLLATE utf8mb4_unicode_ci as mobile_number, 
            CAST(COALESCE(u.username, '') AS CHAR(100)) COLLATE utf8mb4_unicode_ci as reviewed_by_name,
            CAST(NULL AS CHAR(255)) as bank_name
        FROM order_state_history osh
        LEFT JOIN customer_orders co ON osh.order_id = co.id
        LEFT JOIN customers c ON co.customer_id = c.id
        LEFT JOIN users u ON osh.reviewed_by = u.id 
    ";


    $combined_query = "($orders_query) UNION ALL ($baskets_query) UNION ALL ($expenses_query) UNION ALL ($payments_query) UNION ALL ($order_status_history_query) UNION ALL ($order_state_history_query)";
    
    // Apply filters to the combined query before fetching
    $where_clauses = [];
    $params = [];

    // تطبيق فلتر الحالة (مراجعة / قيد المراجعة / الكل)
    if ($review_status_filter === 'pending') {
        $where_clauses[] = "review_status = 'pending'";
    } elseif ($review_status_filter === 'reviewed') {
        $where_clauses[] = "review_status = 'reviewed'";
    }

    // Filter by bank account name
    if ($bank_account_filter === 'cash') {
        $where_clauses[] = "(bank_name = '' OR bank_name IS NULL)";
    } elseif (!empty($bank_account_filter)) {
        $where_clauses[] = "bank_name = :bank_account_filter";
        $params[':bank_account_filter'] = $bank_account_filter;
    }


    if (!empty($where_clauses)) {
        $combined_query = "SELECT * FROM (
            $combined_query
        ) AS all_transactions
        WHERE " . implode(' AND ', $where_clauses);
    }

    $combined_query .= " ORDER BY created_at DESC";

    $stmt = $db->prepare($combined_query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Re-calculate counts based on the filtered results
    $orders_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'order'));
    $baskets_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'basket'));
    $expenses_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'expense'));
    $payments_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'payment'));
    
    $order_status_history_count = count(array_filter($transactions, fn($t) => $t['transaction_type'] === 'order_status_history' || $t['transaction_type'] === 'order_state_history'));

    $reviewed_count = count(array_filter($transactions, fn($t) => $t['review_status'] === 'reviewed'));
    $pending_count = count(array_filter($transactions, fn($t) => $t['review_status'] === 'pending'));

    // Apply remaining filters after initial fetch and union
    if ($filter !== 'all') {
        if ($filter === 'order_history') {
            $transactions = array_filter($transactions, fn($t) => $t['transaction_type'] === 'order_status_history' || $t['transaction_type'] === 'order_state_history');
        } else {
            $transactions = array_filter($transactions, fn($t) => $t['transaction_type'] === $filter);
        }
    }
    
    // فلترة مخصصة باستخدام PHP (تاريخ، مبلغ، بحث)
    $transactions = array_filter($transactions, function ($t) use ($search_query, $status_filter, $date_from, $date_to, $amount_filter) {
        if ($status_filter !== '' && ($t['status'] ?? '') !== $status_filter) return false;
        
        // تطبيق فلتر المبلغ (السعر)
        if ($amount_filter !== '') {
            if (floatval($t['amount']) != floatval($amount_filter)) return false;
        }

        if (!empty($date_from) || !empty($date_to)) {
            $created_date = substr($t['created_at'] ?? '', 0, 10);
            if (!empty($date_from) && $created_date < $date_from) return false;
            if (!empty($date_to) && $created_date > $date_to) return false;
        }
        if ($search_query !== '') {
            $haystack = strtolower(($t['transaction_number'] ?? '') . ' ' . ($t['reference_number'] ?? '') . ' ' . ($t['customer_name'] ?? '') . ' ' . ($t['mobile_number'] ?? '') . ' ' . ($t['status'] ?? '')); // Added status to search
            if (strpos($haystack, strtolower($search_query)) === false) return false;
        }
        return true;
    });
} catch (PDOException $e) { die("Database Error: " . $e->getMessage()); }

// Fetch bank accounts for the filter dropdown
try {
    $bank_stmt = $db->query("SELECT DISTINCT bank_name FROM bank_accounts WHERE bank_name IS NOT NULL AND bank_name != '' ORDER BY bank_name ASC");
    $bank_accounts = $bank_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $bank_accounts = [];
    error_log("Error fetching bank accounts: " . $e->getMessage());
}

include '../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    .page-container { background: #f3f4f6; min-height: 100vh; padding: 2rem; direction: rtl; }
    .header-card { background: linear-gradient(135deg, #059669 0%, #10b981 100%); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 10px 25px rgba(5, 150, 105, 0.3); }
    .header-title { color: white; font-size: 2rem; font-weight: 700; margin: 0; }
    .tabs-container { display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap; }
    .tab-button { background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 1rem 1.5rem; font-weight: 600; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 0.5rem; text-decoration: none; color: #374151; }
    .tab-button.active { background: #10b981; color: white; border-color: #10b981; }
    .tab-badge { background: rgba(0, 0, 0, 0.1); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.875rem; font-weight: 700; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .stat-card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); border-right: 5px solid; transition: transform 0.3s; }
    .table-card { background: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); overflow: visible; }
    .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; position: relative; width: 100%; display: block; }
    .data-table { width: 100%; min-width: 1200px; border-collapse: collapse; table-layout: auto; }
    .data-table thead { background: linear-gradient(135deg, #059669 0%, #10b981 100%); }
    .data-table thead th { color: white; padding: 1rem; text-align: right; font-weight: 600; font-size: 0.875rem; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
    .data-table tbody td { padding: 1rem; font-size: 0.875rem; white-space: nowrap; border-bottom: 1px solid #e5e7eb; }
    .status-badge { display: inline-block; padding: 0.375rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    .status-reviewed { background: #d1fae5; color: #065f46; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .btn { padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.875rem; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
    .btn-view { background: #dbeafe; color: #1e40af; }
    .btn-review { background: #10b981; color: white; }
    .modal { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; }
    .modal.active { display: flex; }
    .modal-content { background: white; border-radius: 12px; padding: 2rem; max-width: 500px; width: 90%; }
</style>

<div class="page-container">
    <div class="header-card">
        <h1 class="header-title"><i class="fas fa-clipboard-check ml-2"></i> المراجعة المالية الشاملة</h1>
        <p class="text-white opacity-90 mt-2">مراجعة جميع العمليات المالية: طلبات العملاء، سلال الشراء، والمصروفات</p>
    </div>

    <!-- علامات التبويب مع تمرير فلتر المبلغ الجديد -->
    <div class="tabs-container">
        <a href="?filter=all&q=<?php echo urlencode($search_query); ?>&amount=<?php echo urlencode($amount_filter); ?>&status=<?php echo urlencode($status_filter); ?>&review_status=<?php echo urlencode($review_status_filter); ?>&bank_account=<?php echo urlencode($bank_account_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="tab-button <?php echo $filter === 'all' ? 'active' : ''; ?>">الكل <span class="tab-badge"><?php echo count($transactions); ?></span></a>
        
        <a href="?filter=order&q=<?php echo urlencode($search_query); ?>&amount=<?php echo urlencode($amount_filter); ?>&status=<?php echo urlencode($status_filter); ?>&review_status=<?php echo urlencode($review_status_filter); ?>&bank_account=<?php echo urlencode($bank_account_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="tab-button <?php echo $filter === 'order' ? 'active' : ''; ?>">طلبات العملاء <span class="tab-badge"><?php echo $orders_count; ?></span></a>
        
        <a href="?filter=basket&q=<?php echo urlencode($search_query); ?>&amount=<?php echo urlencode($amount_filter); ?>&status=<?php echo urlencode($status_filter); ?>&review_status=<?php echo urlencode($review_status_filter); ?>&bank_account=<?php echo urlencode($bank_account_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="tab-button <?php echo $filter === 'basket' ? 'active' : ''; ?>">سلال الشراء <span class="tab-badge"><?php echo $baskets_count; ?></span></a>
        
        <a href="?filter=expense&q=<?php echo urlencode($search_query); ?>&amount=<?php echo urlencode($amount_filter); ?>&status=<?php echo urlencode($status_filter); ?>&review_status=<?php echo urlencode($review_status_filter); ?>&bank_account=<?php echo urlencode($bank_account_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="tab-button <?php echo $filter === 'expense' ? 'active' : ''; ?>">المصروفات <span class="tab-badge"><?php echo $expenses_count; ?></span></a>
        
        <a href="?filter=payment&q=<?php echo urlencode($search_query); ?>&amount=<?php echo urlencode($amount_filter); ?>&status=<?php echo urlencode($status_filter); ?>&review_status=<?php echo urlencode($review_status_filter); ?>&bank_account=<?php echo urlencode($bank_account_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="tab-button <?php echo $filter === 'payment' ? 'active' : ''; ?>">الدفعات <span class="tab-badge"><?php echo $payments_count; ?></span></a>
        
        <a href="?filter=order_history&q=<?php echo urlencode($search_query); ?>&amount=<?php echo urlencode($amount_filter); ?>&status=<?php echo urlencode($status_filter); ?>&review_status=<?php echo urlencode($review_status_filter); ?>&bank_account=<?php echo urlencode($bank_account_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="tab-button <?php echo $filter === 'order_history' ? 'active' : ''; ?>">سجل حالات الطلبات <span class="tab-badge"><?php echo $order_status_history_count; ?></span></a>
    </div>

    <div class="stats-grid">
        <div class="stat-card" style="border-color: #3b82f6;"><div class="text-2xl font-bold"><?php echo $orders_count; ?></div><div class="text-gray-500">طلبات العملاء</div></div>
        <div class="stat-card" style="border-color: #10b981;"><div class="text-2xl font-bold"><?php echo $reviewed_count; ?></div><div class="text-gray-500">تمت المراجعة</div></div>
        <div class="stat-card" style="border-color: #f59e0b;"><div class="text-2xl font-bold"><?php echo $pending_count; ?></div><div class="text-gray-500">قيد المراجعة</div></div>
        <div class="stat-card" style="border-color: #a855f7;"><div class="text-2xl font-bold"><?php echo $order_status_history_count; ?></div><div class="text-gray-500">سجل الحالات</div></div>
    </div>

    <!-- فلاتر البحث -->
    <form method="get" class="mb-6 bg-white rounded-xl shadow p-4 flex flex-wrap gap-4 items-end">
        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
        
        <div class="flex-1 min-w-[180px]">
            <label class="block text-sm font-semibold text-gray-700 mb-1">بحث نصي</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="رقم العملية، اسم العميل..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500">
        </div>
        
        <!-- الحقل الجديد: البحث بالمبلغ -->
        <div class="flex-1 min-w-[120px]">
            <label class="block text-sm font-semibold text-gray-700 mb-1">المبلغ (السعر)</label>
            <input type="number" step="0.01" name="amount" value="<?php echo htmlspecialchars($amount_filter); ?>" placeholder="مثال: 5000" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500">
        </div>

        <div class="flex-1 min-w-[160px]">
            <label class="block text-sm font-semibold text-gray-700 mb-1">حالة المراجعة</label>
            <select name="review_status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500">
                <option value="pending" <?php echo $review_status_filter === 'pending' ? 'selected' : ''; ?>>قيد المراجعة</option>
                <option value="reviewed" <?php echo $review_status_filter === 'reviewed' ? 'selected' : ''; ?>>تمت المراجعة</option>
                <option value="all" <?php echo $review_status_filter === 'all' ? 'selected' : ''; ?>>الكل</option>
            </select>
        </div>
        <div class="flex-1 min-w-[180px]">
            <label class="block text-sm font-semibold text-gray-700 mb-1">نوع الحساب / الدفع</label>
            <select name="bank_account" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500">
                <option value="">الكل</option>
                <option value="cash" <?php echo $bank_account_filter === 'cash' ? 'selected' : ''; ?>>نقداً</option>
                <?php foreach ($bank_accounts as $bank): ?>
                    <option value="<?php echo htmlspecialchars($bank); ?>" <?php echo $bank_account_filter === $bank ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($bank); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[140px]">
            <label class="block text-sm font-semibold text-gray-700 mb-1">من تاريخ</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500">
        </div>
        <div class="flex-1 min-w-[140px]">
            <label class="block text-sm font-semibold text-gray-700 mb-1">إلى تاريخ</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500">
        </div>
        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-5 py-2 rounded-lg text-sm flex items-center gap-2"><i class="fas fa-search"></i> بحث</button>
        <a href="?" class="bg-gray-400 hover:bg-gray-500 text-white font-semibold px-5 py-2 rounded-lg text-sm flex items-center gap-2"><i class="fas fa-times"></i> مسح</a>
    </form>

    <div class="table-card">
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>النوع</th>
                        <th>رقم العملية</th>
                        <th>العميل/المورد</th>
                        <th>اسم الحساب البنكي</th>
                        <th>تاريخ الإنشاء</th>
                        <th>الحالة / التفاصيل</th>
                        <th>حالة المراجعة</th>
                        <th>طريقة الدفع / الملاحظات</th>
                        <th>المبلغ</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-6 text-gray-500 font-semibold">لا توجد بيانات مطابقة للبحث</td>
                        </tr>
                    <?php else: ?>
                    <?php foreach ($transactions as $t):
                        $row_id = "row-" . $t['transaction_type'] . "-" . $t['id'];
                    ?>
                        <tr id="<?php echo $row_id; ?>" class="<?php echo ($t['transaction_type'] === 'order_status_history' || $t['transaction_type'] === 'order_state_history') ? 'bg-purple-50 bg-opacity-50' : ''; ?>">
                            <td><span class="px-2 py-1 rounded-full text-xs font-bold bg-gray-100"><?php
                                $type_text = '';
                                switch ($t['transaction_type']) {
                                    case 'order': $type_text = 'طلب'; break;
                                    case 'basket': $type_text = 'سلة'; break;
                                    case 'expense': $type_text = 'مصروف'; break;
                                    case 'payment': $type_text = 'دفعة'; break;
                                    case 'order_status_history': $type_text = 'سجل حالة (قديم)'; break;
                                    case 'order_state_history': $type_text = 'سجل حالة (جديد)'; break;
                                    default: $type_text = 'غير معروف'; break;
                                }
                                echo htmlspecialchars($type_text);
                            ?></span></td>
                            <td><strong><?php echo htmlspecialchars($t['transaction_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($t['customer_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($t['bank_name'] ?: '-'); ?></td>
                            <td><?php echo formatToYemenTime($t['created_at'], 'Y/m/d H:i'); ?></td>
                            <td>
                                <?php
                                $status_map = ['new'=>'جديد','pending'=>'انتظار','approved'=>'معتمد','completed'=>'مكتمل','cancelled'=>'ملغي','paid'=>'مدفوع','active'=>'نشط', 'processing'=>'قيد المعالجة', 'modified'=>'معدل', 'deleted'=>'محذوف'];
                                
                                if ($t['transaction_type'] === 'order_status_history') {
                                    echo '<span class="text-orange-600 font-semibold">' . htmlspecialchars($status_map[$t['status']] ?? $t['status']) . '</span><br>';
                                    echo '<span class="text-xs text-gray-500">' . htmlspecialchars($t['reference_number'] ?: $t['status']) . '</span>';
                                } elseif ($t['transaction_type'] === 'order_state_history') {
                                    echo '<span class="text-blue-600 font-semibold">' . htmlspecialchars($status_map[$t['status']] ?? $t['status']) . '</span><br>';
                                    echo '<span class="text-xs text-gray-500">' . htmlspecialchars($t['reference_number'] ?: $t['status']) . '</span>';
                                } else {
                                    echo htmlspecialchars($status_map[$t['status']] ?? $t['status']);
                                }
                                ?>
                            </td>
                            <td class="review-status-cell">
                                <span class="status-badge status-<?php echo $t['review_status']; ?>">
                                    <?php echo $t['review_status'] === 'reviewed' ? 'تمت المراجعة' : 'قيد المراجعة'; ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $method_key = strtolower($t['payment_method'] ?? '');
                                $method_ar = $payment_methods_ar[$method_key] ?? ($t['payment_method'] ?: 'غير محدد');

                                if ($t['transaction_type'] === 'order_status_history' || $t['transaction_type'] === 'order_state_history') {
                                    echo '<span class="text-xs text-gray-600">' . htmlspecialchars($t['review_note'] ?: $method_ar) . '</span>'; 
                                    if (!empty($t['reviewed_by_name'])) {
                                        echo '<br><span class="text-xs text-gray-400">بواسطة: ' . htmlspecialchars($t['reviewed_by_name']) . '</span>';
                                    }
                                } else {
                                    if (!empty($t['bank_name'])) {
                                        echo htmlspecialchars($method_ar) . ' : <strong>' . htmlspecialchars($t['bank_name']) . '</strong>';
                                    } else {
                                        echo htmlspecialchars($method_ar);
                                    }
                                    if (!empty($t['reference_number'])) {
                                        echo '<br><span class="text-xs text-gray-500">مرجع: ' . htmlspecialchars($t['reference_number']) . '</span>';
                                    }
                                }
                                ?>
                            </td>
                            <td><strong class="text-emerald-600"><?php echo number_format($t['amount'], 2); ?></strong></td>
                            <td class="actions-cell">
                                <div class="flex gap-2">
                                    <?php
                                    $view_url = '#';
                                    if ($t['transaction_type'] === 'order') {
                                        // هنا نستخدم معرّف الطلب (Order ID) بدلاً من معرّف العميل
                                        $view_url = '../../modules/orders/view.php?id=' . $t['id'];
                                    } elseif ($t['transaction_type'] === 'order_status_history' || $t['transaction_type'] === 'order_state_history') {
                                        // في استعلام السجلات (History) أعلاه، تم حفظ معرّف الطلب بداخل حقل customer_id
                                        $view_url = '../../modules/orders/view.php?id=' . $t['customer_id'];
                                    } elseif ($t['transaction_type'] === 'payment') {
                                        $view_url = '../../modules/payments/view.php?id=' . $t['id'];
                                    } elseif ($t['transaction_type'] === 'basket') {
                                        $view_url = '../../modules/purchases/view_basket.php?id=' . $t['id'];
                                    } elseif ($t['transaction_type'] === 'expense') {
                                        $view_url = '../../modules/expenses/view.php?id=' . $t['id'];
                                    }
                                    ?>
                                    <a href="<?php echo $view_url; ?>" class="btn btn-view" <?php if ($view_url !== '#') echo 'target="_blank"'; ?>>
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <!-- الزر يظهر فقط إذا كانت الحالة قيد المراجعة -->
                                    <?php if ($t['review_status'] === 'pending'): ?>
                                        <button onclick="openReviewModal(<?php echo $t['id']; ?>, '<?php echo $t['transaction_type']; ?>', '<?php echo htmlspecialchars($t['transaction_number']); ?>')" class="btn btn-review review-btn-trigger">
                                            <i class="fas fa-check-circle"></i> مراجعة
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال المراجعة -->
<div id="reviewModal" class="modal">
    <div class="modal-content">
        <h3 class="text-xl font-bold mb-4 text-emerald-600">تأكيد المراجعة المالية</h3>
        <form id="ajaxReviewForm">
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="transaction_id" id="modal_id">
            <input type="hidden" name="transaction_type" id="modal_type">

            <p class="mb-4 text-gray-600">هل تريد تأكيد مراجعة العملية رقم: <strong id="modal_ref"></strong>؟</p>

            <div class="mb-4">
                <label class="block text-sm font-bold mb-1">ملاحظات (اختياري)</label>
                <textarea name="review_note" id="modal_note" class="w-full border rounded-lg p-2" rows="3"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeReviewModal()" class="bg-gray-500 text-white px-4 py-2 rounded-lg">إلغاء</button>
                <button type="submit" id="submitBtn" class="bg-emerald-600 text-white px-4 py-2 rounded-lg font-bold">تأكيد الآن</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReviewModal(id, type, ref) {
    document.getElementById('modal_id').value = id;
    document.getElementById('modal_type').value = type;
    document.getElementById('modal_ref').innerText = ref;
    document.getElementById('reviewModal').classList.add('active');
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('active');
    document.getElementById('ajaxReviewForm').reset();
}

document.getElementById('ajaxReviewForm').onsubmit = function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const formData = new FormData(this);

    btn.disabled = true;
    btn.innerText = 'جاري الحفظ...';

    fetch('', { 
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            closeReviewModal();
            const type = formData.get('transaction_type');
            const id = formData.get('transaction_id');
            const row = document.getElementById(`row-${type}-${id}`);
            if(row) {
                // تحديث الحالة إخفاء الزر
                row.querySelector('.review-status-cell').innerHTML = '<span class="status-badge status-reviewed">تمت المراجعة</span>';
                const actionBtn = row.querySelector('.review-btn-trigger');
                if(actionBtn) actionBtn.remove();
            }
        } else {
            alert('حدث خطأ: ' + data.message);
        }
    })
    .catch(err => alert('خطأ في الاتصال'))
    .finally(() => {
        btn.disabled = false;
        btn.innerText = 'تأكيد الآن';
    });
};

window.onclick = function(e) {
    if (e.target == document.getElementById('reviewModal')) closeReviewModal();
}
</script>

<?php include '../../includes/footer.php'; ?>