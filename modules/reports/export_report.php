<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$report_type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'pdf';

if (empty($report_type)) {
    die('نوع التقرير غير محدد');
}

// Get report data based on type
$report_data = [];
$report_title = '';

// Default to empty dates to fetch all data if no filter is applied
// This ensures that if the user doesn't select dates, the report covers all time
$start_date = $_GET['date_from'] ?? '';
$end_date = $_GET['date_to'] ?? '';

try {
    switch ($report_type) {
        case 'orders':
            $report_title = 'تقرير طلبات العملاء';

            $status_filter = $_GET['status'] ?? '';
            $creator_filter = $_GET['creator_id'] ?? '';
            $search = $_GET['search'] ?? '';
            $group_filter = $_GET['group_id'] ?? '';
            $remaining_filter = $_GET['remaining'] ?? '';

            $from_joins = "
                FROM customer_orders o
                LEFT JOIN customers c ON o.customer_id = c.id
                LEFT JOIN users u ON o.created_by = u.id
                LEFT JOIN purchase_baskets pb ON o.basket_id = pb.id
                LEFT JOIN purchase_groups pg ON pg.id = COALESCE(o.purchase_group_id, pb.purchase_group_id)
                LEFT JOIN customer_order_statuses cos ON o.status = cos.status_key
            ";

            $query = "SELECT
                        o.order_number AS 'رقم الطلب',
                        DATE(o.order_date) AS 'تاريخ الطلب',
                        c.name AS 'اسم العميل',
                        (SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id) AS 'عدد القطع',
                        cos.status_name_ar AS 'الحالة',
                        o.currency AS 'العملة',
                        o.subtotal_amount AS 'المبلغ الأصلي',
                        o.discount_amount AS 'الخصم',
                        o.final_amount AS 'المبلغ النهائي',
                        o.paid_amount AS 'المدفوع',
                        (o.final_amount - o.paid_amount) AS 'المتبقي',
                        pg.group_name AS 'المجموعة',
                        u.username AS 'الموظف'
                    " . $from_joins . " WHERE 1=1";

            $params = [];

            if ($status_filter) {
                if ($status_filter === 'new') {
                    $query .= " AND o.status IN (?, ?)";
                    $params[] = 'new';
                    $params[] = 'processing';
                } else {
                    $query .= " AND o.status = ?";
                    $params[] = $status_filter;
                }
            }
            if ($creator_filter) {
                $query .= " AND o.created_by = ?";
                $params[] = $creator_filter;
            }
            
            if (!empty($start_date)) {
                $query .= " AND DATE(o.created_at) >= ?";
                $params[] = $start_date;
            }
            if (!empty($end_date)) {
                $query .= " AND DATE(o.created_at) <= ?";
                $params[] = $end_date;
            }
            
            if ($group_filter) {
                if ($group_filter === 'not_in_group') {
                    $query .= " AND COALESCE(o.purchase_group_id, pb.purchase_group_id) IS NULL";
                } else {
                    $query .= " AND COALESCE(o.purchase_group_id, pb.purchase_group_id) = ?";
                    $params[] = $group_filter;
                }
            }
            if ($search) {
                $search_param = "%$search%";
                $query .= " AND (o.order_number LIKE ? OR c.name LIKE ? OR c.mobile_number LIKE ?)";
                $params = array_merge($params, [$search_param, $search_param, $search_param]);
            }
            if ($remaining_filter === 'has_remaining') {
                $query .= " AND (o.final_amount - o.paid_amount) > 0.01";
            } elseif ($remaining_filter === 'fully_paid') {
                $query .= " AND (o.final_amount - o.paid_amount) <= 0.01";
            }

            $query .= " ORDER BY o.created_at DESC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'purchase_groups':
            $report_title = 'تقرير مجموعات الشراء';
            
            $filter_status = $_GET['status'] ?? '';
            $filter_search = $_GET['search'] ?? '';

            $where_clauses = [];
            $params = [];

            if (!empty($filter_status)) {
                $where_clauses[] = "pg.status = ?";
                $params[] = $filter_status;
            }
            if (!empty($filter_search)) {
                $where_clauses[] = "(pg.group_number LIKE ? OR pg.group_name LIKE ?)";
                $search_term = '%' . $filter_search . '%';
                $params[] = $search_term;
                $params[] = $search_term;
            }
            $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

            $query = "
                SELECT 
                    pg.group_number AS 'رقم المجموعة', 
                    pg.group_name AS 'اسم المجموعة', 
                    pgs.status_name_ar AS 'الحالة',
                    (SELECT COUNT(DISTINCT pb.id) FROM purchase_baskets pb WHERE pb.purchase_group_id = pg.id) AS 'عدد السلال',
                    (SELECT COUNT(DISTINCT co.id) FROM customer_orders co WHERE co.purchase_group_id = pg.id) AS 'عدد الطلبات',
                    (SELECT SUM(COALESCE(co.final_amount, 0)) FROM customer_orders co WHERE co.purchase_group_id = pg.id) AS 'القيمة الإجمالية',
                    DATE(pg.created_at) AS 'تاريخ الإنشاء'
                FROM purchase_groups pg
                LEFT JOIN purchase_group_statuses pgs ON pg.status = pgs.status_key
                $where_sql
                ORDER BY pg.created_at DESC
            ";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'detail_payments_reports':
            $report_title = 'تقرير الدفعات المفصل';
            $query = "
                SELECT
                    cp.payment_number AS 'رقم الدفعة',
                    c.name AS 'اسم العميل',
                    cp.payment_date AS 'تاريخ الدفعة',
                    cp.amount AS 'المبلغ',
                    CASE
                        WHEN cp.payment_method = 'cash' THEN 'كاش'
                        WHEN cp.payment_method = 'transfer' AND ba.bank_name IS NOT NULL
                            THEN CONCAT('تحويل : ', ba.bank_name)
                        WHEN cp.payment_method = 'cheque' AND ba.bank_name IS NOT NULL
                            THEN CONCAT('شيك : ', ba.bank_name)
                        ELSE COALESCE(cp.payment_method, 'غير محدد')
                    END AS 'طريقة الدفع',
                    cp.reference_number AS 'الرقم المرجعي',
                    u.username AS 'تمت بواسطة',
                    cp.notes AS 'ملاحظات'
                FROM customer_payments cp
                LEFT JOIN customers c ON cp.customer_id = c.id
                LEFT JOIN users u ON cp.created_by = u.id
                LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
                WHERE 1=1
            ";
            
            $params = [];
            if (!empty($start_date) && !empty($end_date)) {
                $query .= " AND cp.payment_date BETWEEN ? AND ?";
                $params = [$start_date, $end_date];
            }
            
            $query .= " ORDER BY cp.payment_date DESC, cp.id DESC;";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'financial_report_purchase_baskets':
            $report_title = 'التقرير المالي لسلات الشراء';
            $query = "
                SELECT 
                    pb.basket_code AS 'كود السلة', pb.basket_name AS 'اسم السلة', pb.purchase_date AS 'تاريخ الشراء',
                    CASE pb.status
                        WHEN 'under_review' THEN 'قيد المراجعة' WHEN 'purchased' THEN 'تم الشراء' WHEN 'shipped' THEN 'قيد الشحن'
                        WHEN 'under_sorting' THEN 'قيد الفرز' WHEN 'ready' THEN 'جاهزة' WHEN 'active' THEN 'نشطة'
                        WHEN 'ordered' THEN 'تم الطلب' WHEN 'cancelled' THEN 'ملغاة' ELSE pb.status
                    END AS 'الحالة',
                    pb.subtotal_amount AS 'المجموع الفرعي', pb.shipping_cost AS 'تكلفة الشحن',
                    CONCAT(pb.tax_rate, IF(pb.tax_included, ' (شامل)', '')) AS 'الضريبة (%)', pb.tax_amount AS 'مبلغ الضريبة',
                    pb.discount_amount AS 'إجمالي الخصم', pb.final_amount AS 'الصافي النهائي', pb.final_price_override AS 'المبلغ المدفوع',
                    u.username AS 'أنشئت بواسطة'
                FROM purchase_baskets pb
                LEFT JOIN users u ON pb.created_by = u.id
                WHERE 1=1
            ";
            
            $params = [];
            if (!empty($start_date) && !empty($end_date)) {
                $query .= " AND pb.purchase_date BETWEEN ? AND ?";
                $params = [$start_date, $end_date];
            }
            
            $query .= " ORDER BY pb.purchase_date DESC, pb.id DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'balance_sheet':
            $report_title = 'الميزانية العمومية';
            $params = [];
            $date_filter_sql_payments = "";
            $date_filter_sql_orders = "";
            $date_filter_sql_bank = "";
            $date_filter_sql_purchase_cards = "";

            if (!empty($start_date) && !empty($end_date)) {
                $date_filter_sql_payments = " AND DATE(payment_date) BETWEEN ? AND ?";
                $date_filter_sql_orders = " AND DATE(order_date) BETWEEN ? AND ?";
                $date_filter_sql_bank = " AND DATE(created_at) BETWEEN ? AND ?";
                $date_filter_sql_purchase_cards = " AND DATE(created_at) BETWEEN ? AND ?";
                $params_dates = [$start_date, $end_date];
            }

            $payment_query = "
                SELECT 
                    COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END), 0) as total_cash,
                    COALESCE(SUM(CASE WHEN payment_method NOT IN ('cash', 'transfer', 'credit_card', 'check') THEN amount ELSE 0 END), 0) as total_other
                FROM customer_payments WHERE 1=1 {$date_filter_sql_payments}
            ";
            $payment_stmt = $db->prepare($payment_query);
            if (!empty($params_dates)) {
                $payment_stmt->execute($params_dates);
            } else {
                $payment_stmt->execute();
            }
            $payment_data = $payment_stmt->fetch(PDO::FETCH_ASSOC);
            $daily_cash_balance = $payment_data['total_cash'];
            $daily_other_balance = $payment_data['total_other'];

            $bank_balance_query = "SELECT COALESCE(SUM(current_balance), 0) FROM bank_accounts WHERE 1=1 {$date_filter_sql_bank}";
            $bank_balance_stmt = $db->prepare($bank_balance_query);
            if (!empty($params_dates)) {
                $bank_balance_stmt->execute($params_dates);
            } else {
                $bank_balance_stmt->execute();
            }
            $daily_bank_balance = $bank_balance_stmt->fetchColumn();

            $prepaid_cards_balance_query = "SELECT COALESCE(SUM(balance), 0) FROM purchase_cards WHERE balance > 0 {$date_filter_sql_purchase_cards}";
            $prepaid_cards_balance_stmt = $db->prepare($prepaid_cards_balance_query);
            if (!empty($params_dates)) {
                $prepaid_cards_balance_stmt->execute($params_dates);
            } else {
                $prepaid_cards_balance_stmt->execute();
            }
            $prepaid_cards_balance = $prepaid_cards_balance_stmt->fetchColumn();

            $total_assets = $daily_cash_balance + $daily_bank_balance + $daily_other_balance + $prepaid_cards_balance;

            $orders_query = "SELECT COALESCE(SUM(final_amount - paid_amount), 0) as total_remaining FROM customer_orders WHERE (final_amount - paid_amount) > 0.01 {$date_filter_sql_orders}";
            $orders_stmt = $db->prepare($orders_query);
            if (!empty($params_dates)) {
                $orders_stmt->execute($params_dates);
            } else {
                $orders_stmt->execute();
            }
            $customer_outstanding_balance = $orders_stmt->fetchColumn();

            $accounts_payable = 0.00;
            $total_liabilities = $accounts_payable + $customer_outstanding_balance;
            $total_equity = $total_assets - $total_liabilities;

            $report_data = [
                'assets_items' => [
                    ['description' => 'الرصيد النقدي (كاش)', 'value' => $daily_cash_balance],
                    ['description' => 'أرصدة الحسابات البنكية', 'value' => $daily_bank_balance],
                    ['description' => 'طرق دفع أخرى', 'value' => $daily_other_balance],
                    ['description' => 'أرصدة بطاقات الشراء', 'value' => $prepaid_cards_balance]
                ],
                'liabilities_items' => [
                    ['description' => 'أرصدة العملاء الدائنة (متبقي)', 'value' => $customer_outstanding_balance],
                    ['description' => 'الذمم الدائنة (الموردون)', 'value' => $accounts_payable]
                ],
                'equity_items' => [['description' => 'الفرق (حقوق الملكية)', 'value' => $total_equity]]
            ];
            break;

        case 'profit_loss':
            $report_title = 'تقرير الأرباح والخسائر';
            
            $revenue_query = "SELECT COALESCE(SUM(final_amount), 0) as total_revenue FROM customer_orders WHERE status NOT IN ('cancelled', 'rejected')";
            $expenses_query = "SELECT COALESCE(SUM(amount), 0) as total_expenses FROM expenses WHERE 1=1";
            $expenses_by_category_query = "
                SELECT ec.category_name, COALESCE(SUM(e.amount), 0) as category_total
                FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id
                WHERE 1=1
            ";
            
            $params = [];
            if (!empty($start_date) && !empty($end_date)) {
                $revenue_query .= " AND order_date BETWEEN ? AND ?";
                $expenses_query .= " AND expense_date BETWEEN ? AND ?";
                $expenses_by_category_query .= " AND e.expense_date BETWEEN ? AND ?";
                $params = [$start_date, $end_date];
            }
            
            $expenses_by_category_query .= " GROUP BY e.category_id, ec.category_name ORDER BY category_total DESC";

            $revenue_stmt = $db->prepare($revenue_query);
            $revenue_stmt->execute($params);
            $total_revenue = $revenue_stmt->fetchColumn();
            
            $expenses_stmt = $db->prepare($expenses_query);
            $expenses_stmt->execute($params);
            $total_expenses = $expenses_stmt->fetchColumn();
            
            $expenses_cat_stmt = $db->prepare($expenses_by_category_query);
            $expenses_cat_stmt->execute($params);
            $expenses_by_category = $expenses_cat_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $net_profit = floatval($total_revenue) - floatval($total_expenses);
            
            $report_data = [
                'total_revenue' => $total_revenue,
                'total_expenses' => $total_expenses,
                'net_profit' => $net_profit,
                'expenses_by_category' => $expenses_by_category
            ];
            break;

        case 'expenses_category':
            $report_title = 'تقرير المصروفات حسب الفئات';
            $selected_currency = $_GET['currency'] ?? 'YER';
            
            $query = "
                SELECT 
                    COALESCE(ec.category_name, 'غير مصنف') as 'الفئة',
                    COUNT(e.id) as 'عدد المصروفات',
                    SUM(e.amount) as 'المبلغ الإجمالي',
                    AVG(e.amount) as 'متوسط المبلغ'
                FROM expenses e
                LEFT JOIN expense_categories ec ON e.category_id = ec.id
                WHERE e.currency = ?
            ";
            
            $params = [$selected_currency];
            
            if (!empty($start_date) && !empty($end_date)) {
                $query .= " AND e.expense_date BETWEEN ? AND ?";
                $params[] = $start_date;
                $params[] = $end_date;
            }
            
            $query .= " GROUP BY e.category_id, ec.category_name ORDER BY SUM(e.amount) DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'revenue_income':
            $report_title = 'تقرير الإيرادات والخدمات';
            $report_data = [];

            $orders_sql = "
                SELECT 
                    'طلبات العملاء' AS 'المصدر',
                    COUNT(id) AS 'عدد المعاملات',
                    COALESCE(SUM(final_amount), 0) AS 'إجمالي المبلغ',
                    COALESCE(SUM(paid_amount), 0) AS 'المدفوع'
                FROM customer_orders
                WHERE 1=1
            ";
            $orders_params = [];
            if (!empty($start_date) && !empty($end_date)) {
                $orders_sql .= " AND order_date BETWEEN ? AND ?";
                $orders_params = [$start_date, $end_date];
            }
            $stmt_orders = $db->prepare($orders_sql);
            $stmt_orders->execute($orders_params);
            $orders_row = $stmt_orders->fetch(PDO::FETCH_ASSOC);

            $cards_sql = "
                SELECT 
                    'بطاقات العملاء' AS 'المصدر',
                    COUNT(id) AS 'عدد المعاملات',
                    COALESCE(SUM(purchase_amount), 0) AS 'إجمالي المبلغ',
                    COALESCE(SUM(purchase_amount), 0) AS 'المدفوع'
                FROM customer_cards
                WHERE 1=1
            ";
            $cards_params = [];
            if (!empty($start_date) && !empty($end_date)) {
                $cards_sql .= " AND issue_date BETWEEN ? AND ?";
                $cards_params = [$start_date, $end_date];
            }
            $stmt_cards = $db->prepare($cards_sql);
            $stmt_cards->execute($cards_params);
            $cards_row = $stmt_cards->fetch(PDO::FETCH_ASSOC);

            foreach ([$orders_row, $cards_row] as $row) {
                if ($row['عدد المعاملات'] > 0) {
                    $total = (float)$row['إجمالي المبلغ'];
                    $paid = (float)$row['المدفوع'];
                    $remaining = $total - $paid;
                    $percentage = ($total > 0) ? ($paid / $total * 100) : 0;

                    $report_data[] = [
                        'المصدر' => $row['المصدر'],
                        'عدد المعاملات' => $row['عدد المعاملات'],
                        'إجمالي المبلغ' => $total,
                        'المدفوع' => $paid,
                        'المتبقي' => $remaining,
                        'نسبة التحصيل' => number_format($percentage, 2) . '%'
                    ];
                }
            }
            break;

        case 'expenses':
            $report_title = 'تقرير المصروفات';
            $category_id = $_GET['category'] ?? '';
            $selected_currency = $_GET['currency'] ?? 'YER';
            
            $where_clauses = [];
            $params = [];
            
            if (!empty($start_date) && !empty($end_date)) {
                $where_clauses[] = "e.expense_date BETWEEN ? AND ?";
                $params[] = $start_date;
                $params[] = $end_date;
            }
            
            if (!empty($category_id)) {
                $where_clauses[] = "e.category_id = ?";
                $params[] = $category_id;
            }
            
            $check_column = $db->query("SHOW COLUMNS FROM expenses LIKE 'currency'");
            if ($check_column->rowCount() > 0) {
                $where_clauses[] = "(e.currency = ? OR e.currency IS NULL)";
                $params[] = $selected_currency;
            }
            
            $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
            
            $expenses_query = "
                SELECT e.*, ec.category_name, u.full_name as created_by_name
                FROM expenses e
                LEFT JOIN expense_categories ec ON e.category_id = ec.id
                LEFT JOIN users u ON e.created_by = u.id
                $where_sql
                ORDER BY e.expense_date DESC, e.created_at DESC
            ";
            $expenses_stmt = $db->prepare($expenses_query);
            $expenses_stmt->execute($params);
            $report_data = $expenses_stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'coupons':
            $report_title = 'تقرير الكوبونات والخصومات';
            $coupon_code_filter = $_GET['coupon_code'] ?? '';
            $customer_id_filter = $_GET['customer_id'] ?? '';

            $query = "
                SELECT 
                    co.order_number AS 'رقم الطلب',
                    co.coupon_code AS 'كود الكوبون',
                    DATE(co.created_at) AS 'التاريخ',
                    c.name AS 'اسم العميل',
                    COALESCE(co.subtotal_amount, 0) AS 'المبلغ الأصلي',
                    (
                        COALESCE(co.discount_amount, 0) + 
                        COALESCE(co.additional_discount, 0) + 
                        COALESCE(co.automatic_discount_amount, 0)
                    ) AS 'إجمالي الخصم',
                    CASE
                        WHEN co.coupon_id IS NOT NULL AND coup.discount_type = 'percentage' THEN coup.discount_value
                        WHEN co.coupon_id IS NOT NULL AND coup.discount_type = 'fixed' AND COALESCE(co.subtotal_amount, 0) > 0.01 THEN (
                            (COALESCE(co.discount_amount, 0) + COALESCE(co.additional_discount, 0)) / COALESCE(co.subtotal_amount, 1)
                        ) * 100
                        ELSE COALESCE(co.automatic_discount_percentage, 0)
                    END as 'نسبة الخصم %',
                    co.final_amount AS 'المبلغ النهائي'
                FROM customer_orders co
                LEFT JOIN customers c ON co.customer_id = c.id
                LEFT JOIN coupons coup ON co.coupon_id = coup.id
                WHERE co.status <> 'cancelled'
                AND (
                        COALESCE(co.discount_amount, 0) + 
                        COALESCE(co.additional_discount, 0) + 
                        COALESCE(co.automatic_discount_amount, 0)
                    ) > 0
            ";

            $params = [];

            if (!empty($start_date) && !empty($end_date)) {
                $query .= " AND DATE(co.created_at) BETWEEN ? AND ?";
                $params[] = $start_date;
                $params[] = $end_date;
            }

            if (!empty($coupon_code_filter)) {
                $query .= " AND co.coupon_code = ?";
                $params[] = $coupon_code_filter;
            }

            if (!empty($customer_id_filter)) {
                $query .= " AND co.customer_id = ?";
                $params[] = $customer_id_filter;
            }

            $query .= " ORDER BY co.created_at DESC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'baskets':
            $report_title = 'تقرير سلال الشراء';
            $status_filter = $_GET['status'] ?? 'all';
            $group_filter = $_GET['group'] ?? 'all';

            $query = "
                SELECT
                    pb.basket_name AS 'اسم السلة',
                    pb.basket_code AS 'الكود',
                    pb.account_number AS 'رقم الحساب',
                    (SELECT GROUP_CONCAT(tracking_number SEPARATOR ', ') FROM basket_tracking WHERE basket_id = pb.id) AS 'أرقام التتبع',
                    pb.total_items AS 'عدد المنتجات',
                    pb.subtotal_amount AS 'المبلغ قبل الخصم',
                    pb.final_amount AS 'المبلغ النهائي',
                    CASE
                        WHEN pb.payment_source_type = 'bank_account' THEN CONCAT('حساب بنكي: ', COALESCE(ba.bank_name, ''))
                        WHEN pb.payment_source_type = 'purchase_card' THEN CONCAT('بطاقة شراء: ', COALESCE(pc.card_name, pc.card_number, ''))
                        ELSE 'غير محدد'
                    END AS 'مصدر الدفع',
                    COALESCE(pbs.status_name_ar, pb.status) AS 'الحالة',
                    COALESCE(pg.group_name, 'بدون مجموعة') AS 'المجموعة',
                    DATE(pb.created_at) AS 'تاريخ الإنشاء',
                    u.username AS 'بواسطة'
                FROM purchase_baskets pb
                LEFT JOIN purchase_basket_statuses pbs ON pb.status = pbs.status_key
                LEFT JOIN purchase_groups pg ON pb.purchase_group_id = pg.id
                LEFT JOIN users u ON pb.created_by = u.id
                LEFT JOIN bank_accounts ba ON pb.payment_source_type = 'bank_account' AND pb.payment_source_id = ba.id
                LEFT JOIN purchase_cards pc ON pb.payment_source_type = 'purchase_card' AND pb.payment_source_id = pc.id
                WHERE 1=1
            ";

            $params = [];

            if (!empty($start_date) && !empty($end_date)) {
                $query .= " AND DATE(pb.created_at) BETWEEN ? AND ?";
                $params[] = $start_date;
                $params[] = $end_date;
            }

            if ($status_filter !== 'all') {
                $query .= " AND pb.status = ?";
                $params[] = $status_filter;
            }

            if ($group_filter !== 'all') {
                $query .= " AND pb.purchase_group_id = ?";
                $params[] = $group_filter;
            }

            $query .= " ORDER BY pb.created_at DESC";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'customer_accounts':
            $report_title = 'تقرير حسابات العملاء';
            $query = "
                SELECT 
                    c.customer_code AS 'كود العميل', c.name AS 'اسم العميل', c.phone AS 'الهاتف', c.mobile_number AS 'رقم الجوال',
                    c.address AS 'العنوان', c.current_balance AS 'الرصيد الحالي', COUNT(o.id) AS 'إجمالي الطلبات',
                    COALESCE(SUM(o.final_amount), 0) AS 'إجمالي المبيعات', COALESCE(SUM(o.paid_amount), 0) AS 'المبلغ المدفوع',
                    (COALESCE(SUM(o.final_amount), 0) - COALESCE(SUM(o.paid_amount), 0)) AS 'الرصيد المتبقي',
                    CASE WHEN c.is_active = 1 THEN 'نشط' ELSE 'غير نشط' END AS 'الحالة'
                FROM customers c LEFT JOIN customer_orders o ON c.id = o.customer_id
                GROUP BY c.id, c.customer_code, c.name, c.phone, c.mobile_number, c.address, c.current_balance, c.is_active
                ORDER BY SUM(o.final_amount) DESC
            ";
            $report_data = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'purchase_cards':
            $report_title = 'تقرير بطاقات الشراء';
            $card_name_filter = trim($_GET['card_name'] ?? '');
            
            $query = "
                SELECT 
                    pc.card_number AS 'رقم البطاقة', 
                    pc.card_name AS 'اسم البطاقة', 
                    pc.balance AS 'الرصيد الحالي',
                    pc.card_purchase_amount AS 'مبلغ الشراء', 
                    pc.initial_balance AS 'المبلغ المضاف',
                    COALESCE(SUM(pb.final_amount), 0) AS 'المبلغ المستخدم', 
                    COUNT(DISTINCT pb.id) AS 'عدد المعاملات',
                    DATE(pc.created_at) AS 'تاريخ الإنشاء'
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
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'bank_accounts':
            $report_title = 'تقرير الحسابات البنكية';
            $account_name_filter = trim($_GET['account_name'] ?? '');
            $query = "
                SELECT 
                    ba.account_name as 'اسم الحساب',
                    ba.account_number as 'رقم الحساب',
                    ba.bank_name as 'اسم البنك',
                    ba.current_balance as 'الرصيد الحالي',
                    0 as 'إجمالي الإيداعات',
                    0 as 'إجمالي السحوبات',
                    0 as 'عدد المعاملات',
                    ba.created_at as 'تاريخ الإنشاء'
                FROM bank_accounts ba
                WHERE 1=1
            ";
            $params = [];
            
            if (!empty($start_date) && !empty($end_date)) {
                $query .= " AND ba.created_at BETWEEN ? AND ?";
                $params[] = $start_date . ' 00:00:00';
                $params[] = $end_date . ' 23:59:59';
            }
            
            if ($account_name_filter !== '') {
                $query .= " AND (ba.name LIKE ? OR ba.account_name LIKE ?)";
                $like = '%' . $account_name_filter . '%';
                $params[] = $like;
                $params[] = $like;
            }
            $query .= " ORDER BY ba.created_at DESC";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'customer_cards':
            $report_title = 'تقرير بطاقات العملاء';
            $search = trim($_GET['search'] ?? '');
            
            $where_clauses = [];
            $params = [];

            if (!empty($start_date) && !empty($end_date)) {
                $where_clauses[] = "cc.issue_date BETWEEN ? AND ?";
                $params[] = $start_date;
                $params[] = $end_date;
            }

            if (!empty($search)) {
                $where_clauses[] = "(cc.card_number LIKE ? OR c.name LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

            $query = "
                SELECT 
                    cc.issue_date AS 'تاريخ الإصدار',
                    cc.card_number AS 'رقم البطاقة',
                    c.name AS 'اسم العميل',
                    cc.initial_amount AS 'الرصيد الأولي',
                    cc.current_balance AS 'الرصيد الحالي',
                    cc.purchase_amount AS 'مبلغ الشراء',
                    cc.expiry_date AS 'تاريخ الانتهاء',
                    u.username AS 'بواسطة',
                    cc.notes AS 'ملاحظات'
                FROM customer_cards cc
                LEFT JOIN customers c ON cc.customer_id = c.id
                LEFT JOIN users u ON cc.created_by = u.id
                $where_sql
                ORDER BY cc.issue_date DESC, cc.id DESC
            ";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        default:
            die('نوع تقرير غير معروف');
    }
} catch (PDOException $e) {
    die('خطأ في جلب البيانات: ' . $e->getMessage());
}

if ($format === 'excel') {
    exportToExcel($report_data, $report_title, $report_type);
} else {
    exportToPDF($report_data, $report_title, $report_type);
}

// ─────────────────────────────────────────────
//  EXCEL EXPORT
// ─────────────────────────────────────────────
function exportToExcel($data, $title, $type) {
    $filename = str_replace(' ', '_', $title) . '_' . date('Y-m-d') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Arabic support

    $base_style = '
        <style>
            body  { font-family: "Segoe UI", Tahoma, sans-serif; direction: rtl; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
            th    { background-color: #f2f2f2; font-weight: bold; border: 1px solid #ccc; padding: 8px; text-align: right; }
            td    { border: 1px solid #ccc; padding: 8px; text-align: right; }
            .title        { font-size: 20px; font-weight: bold; text-align: center; margin-bottom: 10px; }
            .section-head { font-size: 15px; font-weight: bold; background: #dde8f7; padding: 6px 10px; margin-top: 16px; margin-bottom: 4px; }
            .total-row td { font-weight: bold; background: #e5e7eb; }
        </style>
    ';

    echo '<html dir="rtl"><head><meta charset="UTF-8">' . $base_style . '</head><body>';
    echo '<div class="title">' . htmlspecialchars($title) . '</div>';
    echo '<p style="text-align:center;">تاريخ التصدير: ' . date('Y-m-d H:i:s') . '</p>';

    // ── Special: Balance Sheet ──────────────────────────────────────────
    if ($type === 'balance_sheet') {

        // Assets
        echo '<div class="section-head">المقبوضات والأصول</div>';
        echo '<table><thead><tr><th>البيان</th><th>المبلغ (ر.ي)</th></tr></thead><tbody>';
        $total_assets = 0;
        foreach ($data['assets_items'] as $row) {
            echo '<tr><td>' . htmlspecialchars($row['description']) . '</td>'
               . '<td>' . number_format((float)$row['value'], 2) . '</td></tr>';
            $total_assets += $row['value'];
        }
        echo '<tr class="total-row"><td>إجمالي الأصول</td>'
           . '<td>' . number_format($total_assets, 2) . '</td></tr>';
        echo '</tbody></table>';

        // Liabilities
        echo '<div class="section-head">الالتزامات والمتبقي</div>';
        echo '<table><thead><tr><th>البيان</th><th>المبلغ (ر.ي)</th></tr></thead><tbody>';
        $total_liabilities = 0;
        foreach ($data['liabilities_items'] as $row) {
            echo '<tr><td>' . htmlspecialchars($row['description']) . '</td>'
               . '<td>' . number_format((float)$row['value'], 2) . '</td></tr>';
            $total_liabilities += $row['value'];
        }
        echo '<tr class="total-row"><td>إجمالي الالتزامات</td>'
           . '<td>' . number_format($total_liabilities, 2) . '</td></tr>';
        echo '</tbody></table>';

        // Equity
        echo '<div class="section-head">صافي المركز (Equity)</div>';
        echo '<table><thead><tr><th>البيان</th><th>المبلغ (ر.ي)</th></tr></thead><tbody>';
        $total_equity = 0;
        foreach ($data['equity_items'] as $row) {
            echo '<tr><td>' . htmlspecialchars($row['description']) . '</td>'
               . '<td>' . number_format((float)$row['value'], 2) . '</td></tr>';
            $total_equity += $row['value'];
        }
        $grand_total = $total_liabilities + $total_equity;
        echo '<tr class="total-row"><td>الإجمالي العام</td>'
           . '<td>' . number_format($grand_total, 2) . '</td></tr>';
        echo '</tbody></table>';

    // ── Special: Profit & Loss ─────────────────────────────────────────
    } elseif ($type === 'profit_loss') {

        // Summary table
        echo '<div class="section-head">ملخص الأرباح والخسائر</div>';
        echo '<table><thead><tr><th>البيان</th><th>المبلغ (ر.ي)</th></tr></thead><tbody>';
        echo '<tr><td>إجمالي الإيرادات</td>'
           . '<td>' . number_format((float)$data['total_revenue'], 2) . '</td></tr>';
        echo '<tr><td>إجمالي المصروفات</td>'
           . '<td>' . number_format((float)$data['total_expenses'], 2) . '</td></tr>';

        $profit_style = ((float)$data['net_profit'] >= 0)
            ? 'color:#16a34a;'
            : 'color:#dc2626;';
        echo '<tr class="total-row"><td>صافي الربح / الخسارة</td>'
           . '<td style="' . $profit_style . '">'
           . number_format((float)$data['net_profit'], 2) . '</td></tr>';
        echo '</tbody></table>';

        // Expenses by category
        if (!empty($data['expenses_by_category'])) {
            echo '<div class="section-head">المصروفات حسب الفئة</div>';
            echo '<table><thead><tr><th>الفئة</th><th>الإجمالي (ر.ي)</th></tr></thead><tbody>';
            foreach ($data['expenses_by_category'] as $row) {
                echo '<tr><td>' . htmlspecialchars($row['category_name'] ?: 'غير مصنف') . '</td>'
                   . '<td>' . number_format((float)$row['category_total'], 2) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

    // ── Default: flat array of rows ────────────────────────────────────
    } else {

        if (!empty($data)) {
            echo '<table><thead><tr>';
            foreach (array_keys($data[0]) as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($data as $row) {
                echo '<tr>';
                foreach ($row as $key => $cell) {
                    $is_numeric = is_numeric($cell)
                        && !preg_match('/(رقم|كود|ID|code|phone|هاتف|جوال)/i', $key);
                    if ($is_numeric) {
                        echo '<td>' . number_format((float)$cell, 2, '.', '') . '</td>';
                    } else {
                        echo '<td>' . htmlspecialchars($cell ?? '-') . '</td>';
                    }
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="text-align:center;">لا توجد بيانات متاحة لهذا التقرير.</p>';
        }
    }

    echo '</body></html>';
    exit;
}

// ─────────────────────────────────────────────
//  PDF / HTML EXPORT  (unchanged)
// ─────────────────────────────────────────────
function exportToPDF($data, $title, $type) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html dir="rtl"><head>';
    echo '<meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title>';
    echo '<style>
        body { font-family: "DejaVu Sans", "Segoe UI", "Tahoma", sans-serif; direction: rtl; padding: 20px; }
        h1, h2 { text-align: center; color: #1f2937; }
        h2 { margin-top: 30px; border-bottom: 2px solid #e5e7eb; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px; }
        th, td { border: 1px solid #d1d5db; padding: 10px; text-align: right; }
        th { background: #f3f4f6; font-weight: bold; }
        tr:nth-child(even) { background: #f9fafb; }
        .total-row { font-weight: bold; background: #e5e7eb !important; }
        .print-button { position: fixed; top: 10px; left: 10px; padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; z-index: 100; }
        .expense-card { border: 1px solid #d1d5db; border-radius: 8px; padding: 15px; margin-bottom: 15px; page-break-inside: avoid; background: #f9fafb; }
        .expense-card-header { font-size: 1.1em; font-weight: bold; color: #1f2937; margin-bottom: 10px; }
        .expense-card-item { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #e5e7eb; }
        .expense-card-item:last-child { border-bottom: none; }
        .expense-card-label { font-weight: bold; color: #4b5563; }
        .expense-card-value { color: #111827; }
        @media print {
            @page { size: A4; margin: 0.5in; }
            body { padding: 0; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .print-button { display: none; }
            table { font-size: 9pt; }
        }
    </style></head><body>';
    echo '<button onclick="window.print()" class="print-button">طباعة / حفظ PDF</button>';
    echo '<h1>' . htmlspecialchars($title) . '</h1>';
    echo '<p style="text-align: center; color: #6b7280;">تاريخ التقرير: ' . date('Y-m-d H:i:s') . '</p>';

    if ($type === 'balance_sheet') {
        echo '<h2>المقبوضات والأصول</h2>';
        echo '<table><tr><th>البيان</th><th>المبلغ (ر.ي)</th></tr>';
        $total_assets = 0;
        foreach ($data['assets_items'] as $row) {
            echo '<tr><td>' . htmlspecialchars($row['description']) . '</td><td>' . number_format($row['value'], 2) . '</td></tr>';
            $total_assets += $row['value'];
        }
        echo '<tr class="total-row"><td colspan="1">إجمالي المقبوضات/الأصول</td><td>' . number_format($total_assets, 2) . ' ر.ي</td></tr></table>';
        echo '<h2>الالتزامات والمتبقي</h2>';
        echo '<table><tr><th>البيان</th><th>المبلغ (ر.ي)</th></tr>';
        $total_liabilities = 0;
        foreach ($data['liabilities_items'] as $row) {
            echo '<tr><td>' . htmlspecialchars($row['description']) . '</td><td>' . number_format($row['value'], 2) . '</td></tr>';
            $total_liabilities += $row['value'];
        }
        echo '<tr class="total-row"><td colspan="1">إجمالي الالتزامات</td><td>' . number_format($total_liabilities, 2) . ' ر.ي</td></tr></table>';
        echo '<h2>صافي المركز (Equity)</h2>';
        echo '<table><tr><th>البيان</th><th>المبلغ (ر.ي)</th></tr>';
        $total_equity = 0;
        foreach ($data['equity_items'] as $row) {
            echo '<tr><td>' . htmlspecialchars($row['description']) . '</td><td>' . number_format($row['value'], 2) . '</td></tr>';
            $total_equity += $row['value'];
        }
        echo '</table>';
        $total_liabilities_and_equity = $total_liabilities + $total_equity;
        echo '<table><tr class="total-row" style="font-size: 1.2em;"><td>الإجمالي العام</td><td>' . number_format($total_liabilities_and_equity, 2) . ' ر.ي</td></tr></table>';
    
    } elseif ($type === 'profit_loss') {
        echo '<h2>ملخص الأرباح والخسائر</h2>';
        echo '<table>';
        echo '<tr><td>إجمالي الإيرادات</td><td>' . number_format($data['total_revenue'], 2) . ' ر.ي</td></tr>';
        echo '<tr><td>إجمالي المصروفات</td><td>' . number_format($data['total_expenses'], 2) . ' ر.ي</td></tr>';
        echo '<tr class="total-row ' . ($data['net_profit'] >= 0 ? 'profit' : 'loss') . '"><td>صافي الربح/الخسارة</td><td>' . number_format($data['net_profit'], 2) . ' ر.ي</td></tr>';
        echo '</table>';
        if (!empty($data['expenses_by_category'])) {
            echo '<h2>المصروفات حسب الفئة</h2>';
            echo '<table><tr><th>الفئة</th><th>المجموع</th></tr>';
            foreach ($data['expenses_by_category'] as $row) {
                echo '<tr><td>' . htmlspecialchars($row['category_name'] ?: 'غير مصنف') . '</td><td>' . number_format($row['category_total'], 2) . ' ر.ي</td></tr>';
            }
            echo '</table>';
        }
    } elseif ($type === 'expenses') {
        if (!empty($data)) {
            $total_expenses = array_sum(array_column($data, 'amount'));
            echo '<h2>إجمالي المصروفات: ' . number_format($total_expenses, 2) . ' ر.ي</h2>';
            foreach ($data as $index => $expense) {
                echo '<div class="expense-card">';
                echo '<div class="expense-card-header">المصروف #' . ($index + 1) . '</div>';
                echo '<div class="expense-card-item"><span class="expense-card-label">التاريخ:</span> <span class="expense-card-value">' . htmlspecialchars($expense['expense_date']) . '</span></div>';
                echo '<div class="expense-card-item"><span class="expense-card-label">المبلغ:</span> <span class="expense-card-value" style="font-weight:bold; color: #dc2626;">' . number_format($expense['amount'], 2) . ' ' . htmlspecialchars($expense['currency'] ?? 'YER') . '</span></div>';
                echo '<div class="expense-card-item"><span class="expense-card-label">الفئة:</span> <span class="expense-card-value">' . htmlspecialchars($expense['category_name'] ?: 'غير مصنف') . '</span></div>';
                echo '<div class="expense-card-item"><span class="expense-card-label">الوصف:</span> <span class="expense-card-value">' . htmlspecialchars($expense['description']) . '</span></div>';
                echo '<div class="expense-card-item"><span class="expense-card-label">طريقة الدفع:</span> <span class="expense-card-value">' . htmlspecialchars($expense['payment_method']) . '</span></div>';
                echo '<div class="expense-card-item"><span class="expense-card-label">المستخدم:</span> <span class="expense-card-value">' . htmlspecialchars($expense['created_by_name']) . '</span></div>';
                echo '</div>';
            }
        } else {
            echo '<p style="text-align: center;">لا توجد مصروفات لعرضها</p>';
        }
    } else {
        if (!empty($data)) {
            echo '<table>';
            $first_row = reset($data);
            echo '<tr>';
            foreach (array_keys($first_row) as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr>';
            foreach ($data as $row) {
                echo '<tr>';
                foreach ($row as $key => $cell) {
                    $is_numeric = is_numeric($cell) && !preg_match('/(code|id|رقم|كود|%|الهاتف|الجوال)/i', (string)$key) && !is_string($cell);
                    echo '<td>' . ($is_numeric ? number_format((float)$cell, 2) : htmlspecialchars($cell ?? '-')) . '</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p style="text-align: center;">لا توجد بيانات لعرضها</p>';
        }
    }
    echo '</body></html>';
    exit;
}
?>