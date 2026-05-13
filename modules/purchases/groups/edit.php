<?php
session_start();

// --- 1. INITIALIZATION & SECURITY ---
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login.php');
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/check_permissions.php'; // Added for security

$page_title = 'تعديل مجموعة الشراء';
$error_message = '';
$success_message = '';
$group_id = intval($_GET['id'] ?? 0);
$group = null;
$all_statuses = [];

if (!$group_id) {
    $_SESSION['error_message'] = 'معرف المجموعة غير صالح.';
    header('Location: index.php');
    exit();
}

// --- 2. PERMISSION CHECK ---
// Crucial: Check if the user has permission to edit groups
$user_id = $_SESSION['user_id'];
if (!hasPermission($user_id, 'purchase_groups', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل المجموعات.';
    header('Location: index.php');
    exit();
}


// --- 3. FETCH DATA ---
try {
    // Fetch the group details to edit
    $stmt = $db->prepare("SELECT * FROM purchase_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        $_SESSION['error_message'] = 'المجموعة غير موجودة.';
        header('Location: index.php');
        exit();
    }

    // Fetch all available statuses from the new table
    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM purchase_group_statuses ORDER BY is_default DESC, status_name_ar ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = 'فشل في جلب البيانات: ' . $e->getMessage();
}


// --- 4. HANDLE FORM SUBMISSION (POST REQUEST) ---
// --- 4. HANDLE FORM SUBMISSION (POST REQUEST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $group) {
    try {
        // Sanitize and retrieve POST data
        $group_name = trim($_POST['group_name']);
        
        // --- START: FIX ---
        // If group_number is empty, set it to NULL, otherwise trim the input.
        $group_number = !empty(trim($_POST['group_number'])) ? trim($_POST['group_number']) : null;
        // --- END: FIX ---
        
        $description = trim($_POST['description'] ?? '');
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $status = $_POST['status'] ?? 'active';
        $notes = trim($_POST['notes'] ?? '');

        if (empty($group_name)) {
            throw new Exception('يرجى إدخال اسم المجموعة.');
        }

        // Check if group number already exists (excluding the current group)
        // This check will now only run if a group_number is actually provided.
        if ($group_number !== null) {
            $check_stmt = $db->prepare("SELECT id FROM purchase_groups WHERE group_number = ? AND id != ?");
            $check_stmt->execute([$group_number, $group_id]);
            if ($check_stmt->fetch()) {
                throw new Exception('رقم المجموعة هذا مستخدم بالفعل.');
            }
        }

        // Prepare and execute the update query
        $stmt = $db->prepare("
            UPDATE purchase_groups 
            SET group_name = ?, group_number = ?, description = ?, start_date = ?, 
                end_date = ?, status = ?, notes = ?, updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $group_name,
            $group_number, // This will now correctly be either the number or NULL
            $description,
            $start_date,
            $end_date,
            $status,
            $notes,
            $group_id
        ]);

        $success_message = 'تم تحديث المجموعة بنجاح!';

        // **IMPORTANT**: Re-fetch the group data after update to show the new values in the form
        $stmt = $db->prepare("SELECT * FROM purchase_groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// --- 5. RENDER PAGE ---
include '../../../includes/header.php';
?>
<!-- The CSS and general page structure can remain the same -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white flex items-center">
                            <i class="fas fa-edit ml-3 text-purple-200"></i>
                            تعديل مجموعة الشراء
                        </h1>
                        <p class="text-purple-100 mt-2 text-lg"><?php echo htmlspecialchars($group['group_name'] ?? '...'); ?></p>
                    </div>
                    <div>
                        <a href="index.php" class="inline-flex items-center px-6 py-3 bg-white text-purple-600 rounded-xl hover:bg-purple-50 transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-1 font-semibold">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للقائمة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg shadow-md" role="alert">
                <p class="font-bold"><i class="fas fa-check-circle mr-2"></i> نجاح</p>
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg shadow-md" role="alert">
                <p class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i> خطأ</p>
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($group): // Only show form if group data was fetched successfully 
        ?>
            <!-- Form -->
            <form action="" method="POST" class="bg-white shadow-xl rounded-2xl overflow-hidden">
                <div class="p-8 space-y-6">
                    <!-- Group Name and Number -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="group_name" class="block text-sm font-medium text-gray-700 mb-2">اسم المجموعة *</label>
                            <input type="text" id="group_name" name="group_name" value="<?php echo htmlspecialchars($group['group_name']); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        <div>
                            <label for="group_number" class="block text-sm font-medium text-gray-700 mb-2">رقم المجموعة</label>
                            <input type="text" id="group_number" name="group_number" value="<?php echo htmlspecialchars($group['group_number'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500">
                        </div>
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">الوصف</label>
                        <textarea id="description" name="description" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500"><?php echo htmlspecialchars($group['description'] ?? ''); ?></textarea>
                    </div>

                    <!-- Date Range -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">تاريخ البداية</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo $group['start_date'] ?? ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">تاريخ النهاية</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo $group['end_date'] ?? ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500">
                        </div>
                    </div>

                    <!-- #################################### -->
                    <!-- START: DYNAMIC STATUS DROPDOWN       -->
                    <!-- #################################### -->
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-2">الحالة</label>
                        <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500 bg-white">
                            <?php if (empty($all_statuses)): ?>
                                <option value="">لا توجد حالات متاحة</option>
                            <?php else: ?>
                                <?php foreach ($all_statuses as $status_item): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($status_item['status_key']); ?>"
                                        <?php echo ($group['status'] == $status_item['status_key']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($status_item['status_name_ar']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <!-- #################################### -->
                    <!-- END: DYNAMIC STATUS DROPDOWN         -->
                    <!-- #################################### -->

                    <!-- Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">ملاحظات</label>
                        <textarea id="notes" name="notes" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-purple-500 focus:border-purple-500"><?php echo htmlspecialchars($group['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="flex justify-between items-center p-6 bg-gray-50 border-t border-gray-200">
                    <a href="index.php" class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">إلغاء</a>
                    <button type="submit" class="px-8 py-2 bg-gradient-to-r from-purple-600 to-indigo-700 text-white rounded-lg hover:from-purple-700 hover:to-indigo-800 font-bold shadow-lg transform hover:scale-105 transition-transform">
                        <i class="fas fa-save mr-2"></i>
                        حفظ التعديلات
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>