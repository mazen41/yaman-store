<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إرسال إشعار الطلب';
$error_message = '';
$success_message = '';

// Check if order ID and method are provided
if (!isset($_GET['id']) || !is_numeric($_GET['id']) || !isset($_GET['method'])) {
    header('Location: index.php');
    exit();
}

$order_id = intval($_GET['id']);
$method = $_GET['method'];

// Validate method
$valid_methods = ['whatsapp', 'email', 'manual'];
if (!in_array($method, $valid_methods)) {
    header('Location: view.php?id=' . $order_id);
    exit();
}

// Fetch order details
try {
    $stmt = $db->prepare("
        SELECT o.*, c.name as customer_name, c.customer_code, c.mobile_number, c.whatsapp_number, c.email
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
    
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات الطلب: ' . $e->getMessage();
}

// Handle form submission for manual sending
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $recipient = trim($_POST['recipient'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    // Validation
    if (empty($recipient)) {
        $error_message = 'يرجى إدخال رقم المستلم';
    } elseif (empty($message)) {
        $error_message = 'يرجى إدخال نص الرسالة';
    } else {
        try {
            // Record notification
            $notification_stmt = $db->prepare("
                INSERT INTO order_notifications (order_id, notification_type, status, sent_to)
                VALUES (?, ?, 'pending', ?)
            ");
            $notification_stmt->execute([$order_id, $method, $recipient]);
            $notification_id = $db->lastInsertId();
            
            // If it's WhatsApp, redirect to WhatsApp web
            if ($method == 'whatsapp') {
                // Clean phone number (remove non-digits)
                $phone = preg_replace('/[^0-9]/', '', $recipient);
                
                // Encode message for URL
                $encoded_message = urlencode($message);
                
                // Update notification status to sent
                $update_stmt = $db->prepare("UPDATE order_notifications SET status = 'sent', sent_at = NOW() WHERE id = ?");
                $update_stmt->execute([$notification_id]);
                
                $success_message = 'تم فتح نافذة جديدة لإرسال رسالة الواتساب. يرجى إرسال الرسالة من خلالها.';
                
                // Redirect to WhatsApp
                echo '<script>window.open("https://wa.me/' . $phone . '?text=' . $encoded_message . '", "_blank");</script>';
            }
            // If it's email, send via EmailSender
            elseif ($method == 'email') {
                // Include EmailSender class
                require_once '../../includes/EmailSender.php';
                
                try {
                    // Get order items
                    $items_stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
                    $items_stmt->execute([$order_id]);
                    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Create EmailSender instance
                    $emailSender = new EmailSender($db);
                    
                    // Send custom email
                    $emailSender->sendCustomEmail($recipient, "إشعار بخصوص طلبكم رقم #{$order['order_number']}", $message, $order['customer_name']);
                    
                    // Update notification status to sent
                    $update_stmt = $db->prepare("UPDATE order_notifications SET status = 'sent', sent_at = NOW() WHERE id = ?");
                    $update_stmt->execute([$notification_id]);
                    
                    $success_message = 'تم إرسال البريد الإلكتروني بنجاح';
                } catch (Exception $e) {
                    // Update notification status to failed
                    $update_stmt = $db->prepare("UPDATE order_notifications SET status = 'failed', error_message = ? WHERE id = ?");
                    $update_stmt->execute([$e->getMessage(), $notification_id]);
                    
                    $error_message = 'فشل إرسال البريد الإلكتروني: ' . $e->getMessage();
                }
            }
            // Manual notification
            else {
                // Update notification status to sent
                $update_stmt = $db->prepare("UPDATE order_notifications SET status = 'sent', sent_at = NOW() WHERE id = ?");
                $update_stmt->execute([$notification_id]);
                
                $success_message = 'تم تسجيل الإشعار اليدوي بنجاح';
            }
            
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء تسجيل الإشعار: ' . $e->getMessage();
        }
    }
}

// Prepare default message
$default_message = "مرحباً {$order['customer_name']},\n\n";
$default_message .= "نود إعلامكم بأن طلبكم رقم {$order['order_number']} ";

switch ($order['status']) {
    case 'new':
        $default_message .= "تم استلامه بنجاح وهو قيد المراجعة.";
        break;
    case 'processing':
        $default_message .= "قيد المعالجة حالياً.";
        break;
    case 'completed':
        $default_message .= "تم اكتماله وهو جاهز للتسليم/الاستلام.";
        break;
    case 'cancelled':
        $default_message .= "تم إلغاؤه.";
        break;
    default:
        $default_message .= "تم تحديثه.";
}

$default_message .= "\n\nتفاصيل الطلب:";
$default_message .= "\n- المبلغ الإجمالي: " . number_format($order['final_amount'], 2) . " ريال";
if (!empty($order['expected_delivery_date'])) {
    $default_message .= "\n- تاريخ التسليم المتوقع: " . date('Y-m-d', strtotime($order['expected_delivery_date']));
}
$default_message .= "\n\nشكراً لتعاملكم معنا.";

// Set default recipient based on method
$default_recipient = '';
switch ($method) {
    case 'whatsapp':
        $default_recipient = $order['whatsapp_number'] ?? $order['mobile_number'] ?? '';
        break;
    case 'email':
        $default_recipient = $order['email'] ?? '';
        break;
}

include '../../includes/header.php';
?>

<style>
    .case-highlight { 
        border: 2px solid #111827; 
        border-radius: 0.75rem; 
        padding: 1rem; 
        position: relative; 
    }
    
    .btn-whatsapp {
        background-color: #25D366;
        color: white;
    }
    
    .btn-whatsapp:hover {
        background-color: #128C7E;
    }
    
    .btn-email {
        background-color: #4A7AFF;
        color: white;
    }
    
    .btn-email:hover {
        background-color: #3A5FCC;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">
                            <?php if ($method == 'whatsapp'): ?>
                            إرسال إشعار عبر واتساب
                            <?php elseif ($method == 'email'): ?>
                            إرسال إشعار عبر البريد الإلكتروني
                            <?php else: ?>
                            إرسال إشعار يدوي
                            <?php endif; ?>
                        </h1>
                        <p class="text-gray-600 mt-1">الطلب #<?php echo htmlspecialchars($order['order_number']); ?></p>
                    </div>
                    <div>
                        <a href="view.php?id=<?php echo $order_id; ?>" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة إلى تفاصيل الطلب
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($success_message): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle ml-2"></i>
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>
        
        <!-- Send Form -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">
                    <?php if ($method == 'whatsapp'): ?>
                    <i class="fab fa-whatsapp text-amber-500 ml-2"></i>
                    <?php elseif ($method == 'email'): ?>
                    <i class="fas fa-envelope text-blue-500 ml-2"></i>
                    <?php else: ?>
                    <i class="fas fa-paper-plane text-gray-500 ml-2"></i>
                    <?php endif; ?>
                    إرسال إشعار
                </h2>
            </div>
            
            <form method="POST" class="p-6 space-y-6">
                <div class="case-highlight">
                    <!-- Customer Info -->
                    <div class="mb-4">
                        <h3 class="text-md font-medium text-gray-900 mb-2">معلومات العميل</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <span class="block text-sm font-medium text-gray-500">اسم العميل:</span>
                                <span class="text-gray-900"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                            </div>
                            <div>
                                <span class="block text-sm font-medium text-gray-500">رقم العميل:</span>
                                <span class="text-gray-900"><?php echo htmlspecialchars($order['customer_code']); ?></span>
                            </div>
                            <?php if (!empty($order['mobile_number'])): ?>
                            <div>
                                <span class="block text-sm font-medium text-gray-500">رقم الجوال:</span>
                                <span class="text-gray-900"><?php echo htmlspecialchars($order['mobile_number']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($order['whatsapp_number'])): ?>
                            <div>
                                <span class="block text-sm font-medium text-gray-500">رقم الواتساب:</span>
                                <span class="text-gray-900"><?php echo htmlspecialchars($order['whatsapp_number']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($order['email'])): ?>
                            <div>
                                <span class="block text-sm font-medium text-gray-500">البريد الإلكتروني:</span>
                                <span class="text-gray-900"><?php echo htmlspecialchars($order['email']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recipient -->
                    <div class="mb-4">
                        <label for="recipient" class="block text-sm font-medium text-gray-700 mb-1">
                            <?php if ($method == 'whatsapp'): ?>
                            رقم الواتساب
                            <?php elseif ($method == 'email'): ?>
                            البريد الإلكتروني
                            <?php else: ?>
                            المستلم
                            <?php endif; ?>
                        </label>
                        <input 
                            type="<?php echo $method == 'email' ? 'email' : 'text'; ?>" 
                            id="recipient" 
                            name="recipient" 
                            value="<?php echo htmlspecialchars($default_recipient); ?>" 
                            class="form-input" 
                            required
                        >
                    </div>
                    
                    <!-- Message -->
                    <div>
                        <label for="message" class="block text-sm font-medium text-gray-700 mb-1">نص الرسالة</label>
                        <textarea 
                            id="message" 
                            name="message" 
                            rows="10" 
                            class="form-input" 
                            required
                        ><?php echo htmlspecialchars($default_message); ?></textarea>
                    </div>
                </div>
                
                <div class="flex justify-between">
                    <button type="button" onclick="window.location.href='view.php?id=<?php echo $order_id; ?>'" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition duration-200">
                        إلغاء
                    </button>
                    <button type="submit" class="px-6 py-2 <?php echo $method == 'whatsapp' ? 'btn-whatsapp' : ($method == 'email' ? 'btn-email' : 'bg-blue-600 text-white hover:bg-blue-700'); ?> rounded-lg transition duration-200">
                        <?php if ($method == 'whatsapp'): ?>
                        <i class="fab fa-whatsapp ml-2"></i>
                        <?php elseif ($method == 'email'): ?>
                        <i class="fas fa-envelope ml-2"></i>
                        <?php else: ?>
                        <i class="fas fa-paper-plane ml-2"></i>
                        <?php endif; ?>
                        إرسال
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
