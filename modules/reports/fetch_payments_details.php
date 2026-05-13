<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo "Access denied.";
    exit();
}

require_once '../../config/database.php';

// Sanitize and get date range from the request
$start_date = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_STRING) ?: date('Y-m-01');
$end_date = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_STRING) ?: date('Y-m-d');

// Fetch detailed payments along with customer names
$sql = "
    SELECT 
        p.id,
        p.amount,
        p.payment_date,
        p.payment_method,
        p.notes,
        c.name as customer_name
    FROM customer_payments p
    LEFT JOIN customers c ON p.customer_id = c.id
    WHERE p.payment_date BETWEEN ? AND ?
    ORDER BY p.payment_date DESC
";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Start generating the HTML table output
    $output = '
    <div class="overflow-x-auto" style="max-height: 60vh;">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase">#</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase">اسم العميل</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase">المبلغ</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase">تاريخ الدفع</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase">طريقة الدفع</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase">ملاحظات</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">';
    
    if (count($payments) > 0) {
        foreach ($payments as $payment) {
            $output .= '
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 whitespace-nowrap">' . htmlspecialchars($payment['id']) . '</td>
                <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-800">' . htmlspecialchars($payment['customer_name'] ?: 'N/A') . '</td>
                <td class="px-4 py-3 whitespace-nowrap text-emerald-600 font-bold">' . number_format($payment['amount'], 2) . ' ر.ي</td>
                <td class="px-4 py-3 whitespace-nowrap">' . htmlspecialchars(date('Y-m-d', strtotime($payment['payment_date']))) . '</td>
                <td class="px-4 py-3 whitespace-nowrap">' . htmlspecialchars($payment['payment_method']) . '</td>
                <td class="px-4 py-3">' . nl2br(htmlspecialchars($payment['notes'])) . '</td>
            </tr>';
        }
    } else {
        $output .= '<tr><td colspan="6" class="text-center px-4 py-5 text-gray-500">لا توجد مدفوعات مسجلة في هذه الفترة.</td></tr>';
    }

    $output .= '
            </tbody>
        </table>
    </div>';

    echo $output;

} catch (PDOException $e) {
    // In a real application, you should log this error instead of echoing it.
    http_response_code(500);
    echo "Database error: " . $e->getMessage();
}

?>