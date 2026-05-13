<?php
session_start();

// Only allow admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

$page_title = 'تثبيت PHPMailer';
$error_message = '';
$success_message = '';

// Define the PHPMailer paths
$phpmailer_dir = '../../includes/phpmailer/';

// Create directory if it doesn't exist
if (!file_exists($phpmailer_dir)) {
    mkdir($phpmailer_dir, 0755, true);
}

// Check if PHPMailer is already installed
$phpmailer_exists = false;

// Check via Composer
if (file_exists('../../vendor/autoload.php')) {
    require_once '../../vendor/autoload.php';
    $phpmailer_exists = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

// Check via manual installation
if (!$phpmailer_exists) {
    if (file_exists($phpmailer_dir . 'PHPMailer.php') && 
        file_exists($phpmailer_dir . 'SMTP.php') && 
        file_exists($phpmailer_dir . 'Exception.php')) {
        $phpmailer_exists = true;
    }
}

// Handle installation request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['install_phpmailer'])) {
    // Create the files directly with the content
    $phpmailer_content = file_get_contents(__DIR__ . '/phpmailer_files/PHPMailer.php.txt');
    $smtp_content = file_get_contents(__DIR__ . '/phpmailer_files/SMTP.php.txt');
    $exception_content = file_get_contents(__DIR__ . '/phpmailer_files/Exception.php.txt');
    
    if ($phpmailer_content && $smtp_content && $exception_content) {
        file_put_contents($phpmailer_dir . 'PHPMailer.php', $phpmailer_content);
        file_put_contents($phpmailer_dir . 'SMTP.php', $smtp_content);
        file_put_contents($phpmailer_dir . 'Exception.php', $exception_content);
        
        $success_message = 'تم تثبيت PHPMailer بنجاح!';
        $phpmailer_exists = true;
    } else {
        $error_message = 'فشل في قراءة ملفات PHPMailer';
    }
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
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">تثبيت PHPMailer</h1>
                        <p class="text-gray-600 mt-1">تثبيت مكتبة PHPMailer لإرسال البريد الإلكتروني</p>
                    </div>
                    <div>
                        <a href="email_settings.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة إلى الإعدادات
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
        
        <?php if ($phpmailer_exists): ?>
        <!-- PHPMailer is installed -->
        <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">حالة التثبيت</h2>
            </div>
            <div class="p-6">
                <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg">
                    <i class="fas fa-check-circle ml-2"></i>
                    تم تثبيت PHPMailer بنجاح!
                </div>
                
                <div class="mt-4">
                    <a href="email_settings.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                        <i class="fas fa-cog ml-2"></i>
                        إعداد SMTP
                    </a>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Installation options -->
        <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">تثبيت PHPMailer</h2>
            </div>
            <div class="p-6">
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-lg mb-6">
                    <i class="fas fa-exclamation-triangle ml-2"></i>
                    لم يتم العثور على مكتبة PHPMailer. يرجى تثبيتها باستخدام الزر أدناه.
                </div>
                
                <div class="case-highlight">
                    <h3 class="text-md font-medium text-gray-900 mb-3">تثبيت PHPMailer</h3>
                    <p class="text-sm text-gray-600 mb-4">انقر على الزر أدناه لتثبيت PHPMailer مباشرة في المشروع.</p>
                    
                    <form method="POST">
                        <button type="submit" name="install_phpmailer" value="1" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-download ml-2"></i>
                            تثبيت PHPMailer
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Documentation -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">معلومات إضافية</h2>
            </div>
            <div class="p-6">
                <div class="case-highlight">
                    <h3 class="text-md font-medium text-gray-900 mb-3">حول PHPMailer</h3>
                    <p class="text-sm text-gray-600 mb-2">PHPMailer هي مكتبة PHP شائعة لإرسال رسائل البريد الإلكتروني. تتيح لك إرسال رسائل البريد الإلكتروني بسهولة من خلال خوادم SMTP.</p>
                    
                    <h4 class="text-sm font-semibold text-gray-800 mt-4 mb-2">المميزات:</h4>
                    <ul class="list-disc list-inside space-y-1 text-sm text-gray-600">
                        <li>دعم SMTP مع المصادقة</li>
                        <li>دعم UTF-8 للغة العربية</li>
                        <li>إرسال مرفقات البريد الإلكتروني</li>
                        <li>دعم HTML و Plain Text</li>
                        <li>دعم SSL/TLS للاتصال الآمن</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
