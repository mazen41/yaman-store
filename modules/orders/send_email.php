<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/EnterpriseEmailer.php';

$page_title = 'إرسال بريد إلكتروني';
$error_message = '';
$success_message = '';

// Check if order ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$order_id = intval($_GET['id']);

// Fetch order details
try {
    $stmt = $db->prepare("
        SELECT o.*, c.name as customer_name, c.customer_code, c.mobile_number, c.whatsapp_number, c.email, c.city_name
        FROM customer_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('Location: index.php');
        exit();
    }
    
    // Fetch order items
    $items_stmt = $db->prepare("
        SELECT * FROM order_items WHERE order_id = ? ORDER BY id
    ");
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $to_email = $_POST['to_email'] ?? '';
        $subject = $_POST['subject'] ?? '';
        $message = $_POST['message'] ?? '';
        
        // Validate inputs
        if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'عنوان البريد الإلكتروني غير صالح';
        } elseif (empty($subject)) {
            $error_message = 'موضوع الرسالة مطلوب';
        } elseif (empty($message)) {
            $error_message = 'محتوى الرسالة مطلوب';
        } else {
            try {
                // Create enterprise email sender
                $emailSender = new EnterpriseEmailer($db);
                
                // Send email using enterprise system
                $success = $emailSender->sendEmail($to_email, $subject, $message, $order['customer_name']);
                
                if (!$success) {
                    throw new Exception($emailSender->getLastError());
                }
                
                // Log notification (with error handling)
                try {
                    $log_stmt = $db->prepare("
                        INSERT INTO order_notifications (order_id, type, recipient, subject, content, status, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $log_stmt->execute([$order_id, 'email', $to_email, $subject, $message, 'sent', $_SESSION['user_id']]);
                } catch (PDOException $log_error) {
                    // Log the error but don't fail the email sending
                    error_log("Failed to log email notification: " . $log_error->getMessage());
                    // Continue with success message since email was sent
                }
                
                $success_message = 'تم إرسال البريد الإلكتروني بنجاح';
            } catch (Exception $e) {
                $error_message = 'فشل إرسال البريد الإلكتروني: ' . $e->getMessage();
                
                // Try to log the failed attempt
                try {
                    $log_stmt = $db->prepare("
                        INSERT INTO order_notifications (order_id, type, recipient, subject, content, status, error_message, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $log_stmt->execute([$order_id, 'email', $to_email, $subject, $message, 'failed', $e->getMessage(), $_SESSION['user_id']]);
                } catch (PDOException $log_error) {
                    // Ignore logging errors
                }
            }
        }
    }
    
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات الطلب: ' . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4">
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b">
                <div class="flex items-center justify-between">
                    <h1 class="text-xl font-bold">إرسال بريد إلكتروني - طلب #<?php echo htmlspecialchars($order['order_number'] ?? 'غير معروف'); ?></h1>
                    <a href="view.php?id=<?php echo $order_id; ?>" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة إلى الطلب
                    </a>
                </div>
            </div>
            
            <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg m-6">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
            <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg m-6">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <div class="p-6">
                <div class="mb-6">
                    <h2 class="text-lg font-medium mb-2">معلومات العميل</h2>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p><strong>اسم العميل:</strong> <?php echo htmlspecialchars($order['customer_name'] ?? 'غير معروف'); ?></p>
                        <p><strong>البريد الإلكتروني:</strong> <?php echo htmlspecialchars($order['email'] ?? 'غير متوفر'); ?></p>
                        <p><strong>رقم الجوال:</strong> <?php echo htmlspecialchars($order['mobile_number'] ?? 'غير متوفر'); ?></p>
                    </div>
                </div>
                
                <form method="POST" action="">
                    <div class="mb-4">
                        <label for="to_email" class="block text-sm font-medium text-gray-700 mb-1">إرسال إلى</label>
                        <input type="email" id="to_email" name="to_email" class="form-input w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($order['email'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-4">
                        <label for="subject" class="block text-sm font-medium text-gray-700 mb-1">الموضوع</label>
                        <input type="text" id="subject" name="subject" class="form-input w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" value="معلومات الطلب #<?php echo htmlspecialchars($order['order_number'] ?? 'غير معروف'); ?>" required>
                    </div>
                    
                    <div class="mb-4">
                        <label for="message" class="block text-sm font-medium text-gray-700 mb-1">الرسالة</label>
                        <textarea id="message" name="message" rows="10" class="form-input w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php
                        $default_message = "مرحباً " . htmlspecialchars($order['customer_name'] ?? 'العميل العزيز') . ",\n\n";
                        $default_message .= "نشكرك على طلبك رقم " . htmlspecialchars($order['order_number'] ?? 'غير معروف') . ".\n\n";
                        $default_message .= "تفاصيل الطلب:\n";
                        $default_message .= "- حالة الطلب: " . htmlspecialchars($order['status'] ?? 'غير معروف') . "\n";
                        $default_message .= "- تاريخ الطلب: " . date('Y-m-d', strtotime($order['created_at'] ?? 'now')) . "\n";
                        $default_message .= "- المبلغ الإجمالي: " . number_format($order['final_amount'] ?? 0, 2) . " ريال\n\n";
                        $default_message .= "المنتجات:\n";
                        foreach ($items as $item) {
                            $default_message .= "- " . htmlspecialchars($item['product_name']) . " (الكمية: " . $item['quantity'] . ") - " . number_format($item['total_price'], 2) . " ريال\n";
                        }
                        $default_message .= "\nشكراً لك،\nفريق نظام يمان";
                        echo htmlspecialchars($default_message);
                        ?></textarea>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i class="fas fa-paper-plane ml-2"></i>
                            إرسال البريد الإلكتروني
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>