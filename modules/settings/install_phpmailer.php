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
$phpmailer_class_file = $phpmailer_dir . 'class.phpmailer.php';
$phpmailer_smtp_file = $phpmailer_dir . 'class.smtp.php';

// Check if PHPMailer is already installed
$phpmailer_exists = false;

// Check via Composer
if (file_exists('../../vendor/autoload.php')) {
    require_once '../../vendor/autoload.php';
    $phpmailer_exists = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

// Check via manual installation in includes/phpmailer/
if (!$phpmailer_exists && file_exists($phpmailer_dir)) {
    if (file_exists($phpmailer_dir . 'PHPMailer.php')) {
        // Modern PHPMailer structure
        $phpmailer_exists = true;
    } elseif (file_exists($phpmailer_class_file)) {
        // Legacy PHPMailer structure
        $phpmailer_exists = true;
    }
}

// Handle manual installation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['manual_install'])) {
    // Create directory if it doesn't exist
    $phpmailerPath = '../../includes/phpmailer/';
    if (!file_exists($phpmailerPath)) {
        mkdir($phpmailerPath, 0755, true);
    }
    
    // Check if files were uploaded
    if (isset($_FILES['phpmailer_files']) && !empty($_FILES['phpmailer_files']['name'][0])) {
        $files = $_FILES['phpmailer_files'];
        $required_files = ['PHPMailer.php', 'SMTP.php', 'Exception.php'];
        $uploaded_files = [];
        
        // Process each uploaded file
        for ($i = 0; $i < count($files['name']); $i++) {
            $filename = basename($files['name'][$i]);
            $target_file = $phpmailerPath . $filename;
            
            // Check if it's a valid PHP file
            if (pathinfo($filename, PATHINFO_EXTENSION) !== 'php') {
                $error_message = 'الملف ' . $filename . ' ليس ملف PHP صالح';
                continue;
            }
            
            // Move the uploaded file
            if (move_uploaded_file($files['tmp_name'][$i], $target_file)) {
                $uploaded_files[] = $filename;
            } else {
                $error_message = 'فشل في تحميل الملف ' . $filename;
            }
        }
        
        // Check if all required files were uploaded
        $missing_files = array_diff($required_files, $uploaded_files);
        if (empty($missing_files)) {
            $success_message = 'تم تثبيت PHPMailer بنجاح';
            $phpmailer_exists = true;
        } else {
            $error_message = 'الملفات التالية مفقودة: ' . implode(', ', $missing_files);
        }
    } else {
        $error_message = 'يرجى تحديد ملفات PHPMailer للتحميل';
    }
}

// Include download helper functions
require_once 'download_helper.php';

// Check PHP settings that might affect downloads
$allow_url_fopen = ini_get('allow_url_fopen');
$has_curl = function_exists('curl_init');
$has_zip = class_exists('ZipArchive');
$can_download = $allow_url_fopen || $has_curl;

// Handle automatic download
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['auto_download'])) {
    // Create directory if it doesn't exist
    $phpmailerPath = '../../includes/phpmailer/';
    if (!file_exists($phpmailerPath)) {
        mkdir($phpmailerPath, 0755, true);
    }
    
    // URL to download PHPMailer
    $phpmailer_url = 'https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v6.8.1.zip';
    $zip_file = $phpmailerPath . 'phpmailer.zip';
    $extract_dir = $phpmailerPath . 'temp/';
    
    // Check if we can use direct file download instead of ZIP extraction
    $direct_download_urls = [
        'PHPMailer.php' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.8.1/src/PHPMailer.php',
        'SMTP.php' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.8.1/src/SMTP.php',
        'Exception.php' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.8.1/src/Exception.php'
    ];
    
    // Try direct file download first
    $download_success = true;
    $downloaded_files = [];
    
    foreach ($direct_download_urls as $filename => $url) {
        $file_content = download_file($url);
        if ($file_content === false) {
            $download_success = false;
            break;
        }
        
        if (file_put_contents($phpmailerPath . $filename, $file_content)) {
            $downloaded_files[] = $filename;
        } else {
            $download_success = false;
            break;
        }
    }
    
    if ($download_success && count($downloaded_files) === 3) {
        $success_message = 'تم تثبيت PHPMailer بنجاح';
        $phpmailer_exists = true;
    } else {
        // If direct download failed, try ZIP method if ZipArchive is available
        if (class_exists('ZipArchive')) {
            // Download the file
            if (file_put_contents($zip_file, @file_get_contents($phpmailer_url))) {
                // Create extraction directory
                if (!file_exists($extract_dir)) {
                    mkdir($extract_dir, 0755, true);
                }
                
                // Extract the zip file
                $zip = new ZipArchive;
                if ($zip->open($zip_file) === TRUE) {
                    $zip->extractTo($extract_dir);
                    $zip->close();
                    
                    // Copy required files
                    $src_dir = $extract_dir . 'PHPMailer-6.8.1/src/';
                    $required_files = ['PHPMailer.php', 'SMTP.php', 'Exception.php'];
                    $copied_files = [];
                    
                    foreach ($required_files as $file) {
                        if (file_exists($src_dir . $file)) {
                            copy($src_dir . $file, $phpmailerPath . $file);
                            $copied_files[] = $file;
                        }
                    }
                    
                    // Check if all required files were copied
                    $missing_files = array_diff($required_files, $copied_files);
                    if (empty($missing_files)) {
                        $success_message = 'تم تثبيت PHPMailer بنجاح';
                        $phpmailer_exists = true;
                    } else {
                        $error_message = 'الملفات التالية مفقودة: ' . implode(', ', $missing_files);
                    }
                    
                    // Clean up
                    removeDirectory($extract_dir);
                    unlink($zip_file);
                } else {
                    $error_message = 'فشل في استخراج ملف ZIP';
                }
            } else {
                $error_message = 'فشل في تنزيل PHPMailer';
            }
        } else {
            // If ZipArchive is not available and direct download failed
            $error_message = 'فشل في تثبيت PHPMailer. امتداد ZIP غير متوفر في PHP ولم ينجح التنزيل المباشر. يرجى تثبيت امتداد ZIP أو استخدام التثبيت اليدوي.';
        }
    }
}

// Function to recursively remove a directory
function removeDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        if (!removeDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    
    return rmdir($dir);
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
                <h2 class="text-lg font-medium text-gray-900">خيارات التثبيت</h2>
            </div>
            <div class="p-6">
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-lg mb-6">
                    <i class="fas fa-exclamation-triangle ml-2"></i>
                    لم يتم العثور على مكتبة PHPMailer. يرجى تثبيتها باستخدام أحد الخيارات أدناه.
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Option 1: Automatic Download -->
                    <div class="case-highlight">
                        <h3 class="text-md font-medium text-gray-900 mb-3">التثبيت التلقائي</h3>
                        <p class="text-sm text-gray-600 mb-4">تنزيل وتثبيت PHPMailer تلقائياً من GitHub.</p>
                        
                        <form method="POST">
                            <button type="submit" name="auto_download" value="1" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                                <i class="fas fa-download ml-2"></i>
                                تثبيت تلقائي
                            </button>
                        </form>
                    </div>
                    
                    <!-- Option 2: Manual Upload -->
                    <div class="case-highlight">
                        <h3 class="text-md font-medium text-gray-900 mb-3">التثبيت اليدوي</h3>
                        <p class="text-sm text-gray-600 mb-4">تحميل ملفات PHPMailer يدوياً.</p>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">ملفات PHPMailer</label>
                                <input type="file" name="phpmailer_files[]" multiple class="w-full" accept=".php">
                                <p class="text-xs text-gray-500 mt-1">يرجى تحميل: PHPMailer.php, SMTP.php, Exception.php</p>
                            </div>
                            
                            <button type="submit" name="manual_install" value="1" class="w-full px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200">
                                <i class="fas fa-upload ml-2"></i>
                                تحميل الملفات
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Option 3: Composer -->
                <div class="case-highlight mt-6">
                    <h3 class="text-md font-medium text-gray-900 mb-3">التثبيت باستخدام Composer</h3>
                    <p class="text-sm text-gray-600 mb-4">إذا كان Composer مثبتاً، يمكنك تنفيذ الأمر التالي في مجلد المشروع:</p>
                    
                    <div class="bg-gray-100 p-3 rounded-md mb-4 font-mono text-sm overflow-x-auto">
                        composer require phpmailer/phpmailer
                    </div>
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
                    
                    <div class="mt-4">
                        <a href="https://github.com/PHPMailer/PHPMailer" target="_blank" class="inline-flex items-center text-blue-600 hover:text-blue-800">
                            <i class="fab fa-github ml-1"></i>
                            PHPMailer على GitHub
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
