<?php
session_start();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login.php');
    exit();
}

require_once '../../../config/database.php';

$page_title = 'إضافة مجموعة شراء جديدة';
$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Collect and sanitize data
        $group_name = trim($_POST['group_name']);
        // Use null if empty, otherwise trim the value
        $group_number = !empty(trim($_POST['group_number'])) ? trim($_POST['group_number']) : null;
        $description = trim($_POST['description'] ?? '');
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $status = $_POST['status'] ?? 'active';
        $notes = trim($_POST['notes'] ?? '');

        // Server-side validation
        if (empty($group_name)) {
            throw new Exception('يرجى إدخال اسم المجموعة');
        }

        // Check if group number already exists (only if it's not null)
        if ($group_number !== null) {
            $check_stmt = $db->prepare("SELECT id FROM purchase_groups WHERE group_number = ?");
            $check_stmt->execute([$group_number]);
            if ($check_stmt->fetch()) {
                throw new Exception('رقم المجموعة المدخل موجود بالفعل. يرجى استخدام رقم آخر.');
            }
        }

        // Prepare and execute the INSERT statement
        $stmt = $db->prepare("
            INSERT INTO purchase_groups 
            (group_name, group_number, description, start_date, end_date, status, notes, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $group_name,
            $group_number,
            $description,
            $start_date,
            $end_date,
            $status,
            $notes,
            $_SESSION['user_id']
        ]);

        $success_message = 'تمت إضافة المجموعة بنجاح. سيتم توجيهك الآن...';
        
        // Redirect after a short delay to show the success message
        header("refresh:2;url=index.php");
        
    } catch (PDOException $e) {
        // Handle potential SQL errors, like a duplicate group number
        if ($e->getCode() == 23000) {
            $error_message = 'رقم المجموعة المدخل موجود بالفعل. يرجى استخدام رقم آخر.';
        } else {
            $error_message = 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage();
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

include '../../../includes/header.php';
?>

<!-- Your HTML and CSS are excellent, no changes needed there. -->
<!-- I have added the 'value' attribute to each input to preserve data on error. -->
<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-white flex items-center"><i class="fas fa-plus-circle ml-3 text-purple-200"></i> إضافة مجموعة شراء جديدة</h1>
                        <p class="text-purple-100 mt-2 text-lg">إنشاء مجموعة جديدة لتنظيم طلبات الشراء</p>
                    </div>
                    <div>
                        <a href="index.php" class="inline-flex items-center px-6 py-3 bg-white text-purple-600 rounded-xl hover:bg-purple-50 transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-1 font-semibold"><i class="fas fa-arrow-right ml-2"></i> العودة للقائمة</a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-amber-100 border-2 border-amber-400 text-amber-700 px-6 py-4 rounded-xl mb-6 shadow-lg">
            <div class="flex items-center"><i class="fas fa-check-circle ml-3 text-2xl"></i><span class="font-semibold"><?php echo $success_message; ?></span></div>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border-2 border-red-400 text-red-700 px-6 py-4 rounded-xl mb-6 shadow-lg">
            <div class="flex items-center"><i class="fas fa-exclamation-circle ml-3 text-2xl"></i><span class="font-semibold"><?php echo $error_message; ?></span></div>
        </div>
        <?php endif; ?>

        <form action="" method="POST" class="bg-white shadow-xl rounded-2xl overflow-hidden">
            <div class="px-6 py-4 border-b-2 border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                <h2 class="text-xl font-bold text-gray-900 flex items-center"><i class="fas fa-info-circle ml-2 text-purple-600"></i> معلومات المجموعة</h2>
            </div>
            
            <div class="p-6 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="group_name" class="block text-sm font-medium text-gray-700 mb-2"><i class="fas fa-tag ml-1 text-purple-600"></i> اسم المجموعة *</label>
                        <input type="text" id="group_name" name="group_name" required class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" placeholder="مثال: مجموعة الشراء - يناير 2025" value="<?php echo htmlspecialchars($_POST['group_name'] ?? ''); ?>">
                    </div>
                    
                    <div>
                        <label for="group_number" class="block text-sm font-medium text-gray-700 mb-2"><i class="fas fa-hashtag ml-1 text-indigo-600"></i> رقم المجموعة</label>
                        <input type="text" id="group_number" name="group_number" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" placeholder="مثال: PG-2025-01" value="<?php echo htmlspecialchars($_POST['group_number'] ?? ''); ?>">
                        <p class="text-xs text-gray-500 mt-1">رقم فريد للمجموعة (اختياري)</p>
                    </div>
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2"><i class="fas fa-align-right ml-1 text-blue-600"></i> الوصف</label>
                    <textarea id="description" name="description" rows="3" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" placeholder="وصف مختصر للمجموعة..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2"><i class="fas fa-calendar-alt ml-1 text-amber-600"></i> تاريخ البداية</label>
                        <input type="date" id="start_date" name="start_date" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>">
                    </div>
                    
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2"><i class="fas fa-calendar-check ml-1 text-red-600"></i> تاريخ النهاية</label>
                        <input type="date" id="end_date" name="end_date" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>">
                    </div>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2"><i class="fas fa-flag ml-1 text-orange-600"></i> الحالة</label>
                    <select id="status" name="status" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <option value="active" <?php echo (($_POST['status'] ?? 'active') == 'active') ? 'selected' : ''; ?>>نشطة</option>
                        <option value="inactive" <?php echo (($_POST['status'] ?? '') == 'inactive') ? 'selected' : ''; ?>>غير نشطة</option>
                        <option value="completed" <?php echo (($_POST['status'] ?? '') == 'completed') ? 'selected' : ''; ?>>مكتملة</option>
                    </select>
                </div>

                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-2"><i class="fas fa-sticky-note ml-1 text-yellow-600"></i> ملاحظات</label>
                    <textarea id="notes" name="notes" rows="3" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500" placeholder="أي ملاحظات إضافية..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>

                <div class="flex justify-between items-center pt-6 border-t-2 border-gray-200">
                    <a href="index.php" class="inline-flex items-center px-6 py-3 bg-gray-500 text-white rounded-xl hover:bg-gray-600 transition-all duration-200 font-semibold shadow-md"><i class="fas fa-times ml-2"></i> إلغاء</a>
                    <button type="submit" class="inline-flex items-center px-8 py-3 bg-gradient-to-r from-purple-600 to-indigo-700 text-white rounded-xl hover:from-purple-700 hover:to-indigo-800 transition-all duration-200 font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-1"><i class="fas fa-save ml-2"></i> حفظ المجموعة</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>```