<?php
session_start();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Load database connection first
require_once '../../config/database.php';

// Optional: If you have helper functions for messages, you can include them
// require_once '../../includes/functions.php';

$page_title = 'النسخ الاحتياطي والإعدادات';

$settings_file = __DIR__ . '/backup_settings.json';
$success_message = '';
$error_message = '';

// Handle saving automatic backup schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $allowed = ['disabled','daily','weekly','monthly'];
    $schedule = $_POST['schedule'] ?? 'disabled';
    if (!in_array($schedule, $allowed)) {
        $schedule = 'disabled';
    }

    $settings = ['schedule' => $schedule];
    if (file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT), LOCK_EX)) {
        $success_message = 'تم حفظ الإعدادات بنجاح.';
    } else {
        $error_message = 'فشل في حفظ الإعدادات. تأكد من أن للمجلد صلاحيات الكتابة.';
    }
}

// Load current settings safely
$current_settings = ['schedule' => 'disabled'];
if (file_exists($settings_file)) {
    $data = json_decode(file_get_contents($settings_file), true);
    if (is_array($data)) {
        $current_settings = $data;
    }
}
$current_schedule = $current_settings['schedule'] ?? 'disabled';

// Include header AFTER DB is ready
include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">إدارة النسخ الاحتياطي</h1>
                <p class="text-gray-600 mt-1">قم بتنزيل نسخة احتياطية من قاعدة البيانات أو قم بجدولة نسخ احتياطية تلقائية.</p>
            </div>
        </div>

        <!-- Success / Error Messages -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Manual Backup -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-5">
                <h2 class="text-xl font-semibold text-gray-800 mb-3">النسخ الاحتياطي الفوري</h2>
                <p class="text-gray-600 mb-4">
                    انقر على الزر أدناه لتنزيل نسخة احتياطية كاملة من قاعدة البيانات على الفور.
                </p>
                <form action="create_backup.php" method="POST">
                    <button type="submit"
                        class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                        <i class="fas fa-download ml-2"></i> تحميل نسخة احتياطية الآن
                    </button>
                </form>
            </div>
        </div>

        <!-- Automatic Backup Settings -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-5">
                <h2 class="text-xl font-semibold text-gray-800 mb-3">النسخ الاحتياطي التلقائي</h2>
                <p class="text-gray-600 mb-4">
                    اختر التكرار الذي ترغب في إنشاء نسخة احتياطية تلقائية فيه.
                </p>
                <form method="POST">
                    <div class="max-w-xs">
                        <label for="schedule" class="block text-sm font-medium text-gray-700 mb-1">
                            تكرار النسخ الاحتياطي
                        </label>
                        <select name="schedule" id="schedule" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="disabled" <?php if ($current_schedule === 'disabled') echo 'selected'; ?>>معطل</option>
                            <option value="daily" <?php if ($current_schedule === 'daily') echo 'selected'; ?>>يومياً</option>
                            <option value="weekly" <?php if ($current_schedule === 'weekly') echo 'selected'; ?>>أسبوعياً</option>
                            <option value="monthly" <?php if ($current_schedule === 'monthly') echo 'selected'; ?>>شهرياً</option>
                        </select>
                    </div>
                    <div class="mt-4">
                        <button type="submit" name="save_settings"
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200">
                            <i class="fas fa-save ml-2"></i> حفظ الإعدادات
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>
