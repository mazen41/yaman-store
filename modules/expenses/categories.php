<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة فئات المصروفات';

// Handle delete
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    try {
        $stmt = $db->prepare("UPDATE expense_categories SET is_active = 0 WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $success_message = 'تم حذف الفئة بنجاح';
    } catch (PDOException $e) {
        $error_message = 'حدث خطأ أثناء الحذف';
    }
}

// Handle add/edit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $category_name = trim($_POST['category_name']);
        $category_code = trim($_POST['category_code']);
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'fa-folder');
        $color = trim($_POST['color'] ?? '#6c757d');
        
        if (isset($_POST['id']) && !empty($_POST['id'])) {
            // Update
            $stmt = $db->prepare("UPDATE expense_categories SET category_name = ?, category_code = ?, description = ?, icon = ?, color = ? WHERE id = ?");
            $stmt->execute([$category_name, $category_code, $description, $icon, $color, $_POST['id']]);
            $success_message = 'تم تحديث الفئة بنجاح';
        } else {
            // Insert
            $stmt = $db->prepare("INSERT INTO expense_categories (category_name, category_code, description, icon, color, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$category_name, $category_code, $description, $icon, $color, $_SESSION['user_id']]);
            $success_message = 'تم إضافة الفئة بنجاح';
        }
    } catch (PDOException $e) {
        $error_message = 'حدث خطأ: ' . $e->getMessage();
    }
}

// Fetch categories
$categories = $db->query("
    SELECT ec.*, 
    (SELECT COUNT(*) FROM expenses WHERE category_id = ec.id) as expense_count,
    (SELECT SUM(amount) FROM expenses WHERE category_id = ec.id) as total_amount
    FROM expense_categories ec
    WHERE ec.is_active = 1
    ORDER BY ec.category_name
")->fetchAll();

// Get edit data
$edit_category = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM expense_categories WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $edit_category = $stmt->fetch();
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-600 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-2">
                            <i class="fas fa-folder-open mr-3"></i>
                            إدارة فئات المصروفات
                        </h1>
                        <p class="text-purple-100">تنظيم وإدارة فئات المصروفات</p>
                    </div>
                    <a href="index.php" class="bg-white text-purple-600 px-6 py-3 rounded-lg font-bold hover:bg-purple-50 transition-all">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة للمصروفات
                    </a>
                </div>
            </div>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="bg-amber-100 border-r-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6">
                <i class="fas fa-check-circle ml-2"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6">
                <i class="fas fa-exclamation-circle ml-2"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Add/Edit Form -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">
                        <?php echo $edit_category ? 'تعديل الفئة' : 'إضافة فئة جديدة'; ?>
                    </h3>
                    
                    <form method="POST" class="space-y-4">
                        <?php if ($edit_category): ?>
                            <input type="hidden" name="id" value="<?php echo $edit_category['id']; ?>">
                        <?php endif; ?>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">اسم الفئة *</label>
                            <input type="text" name="category_name" required
                                   value="<?php echo $edit_category['category_name'] ?? ''; ?>"
                                   class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">رمز الفئة *</label>
                            <input type="text" name="category_code" required
                                   value="<?php echo $edit_category['category_code'] ?? ''; ?>"
                                   class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">الوصف</label>
                            <textarea name="description" rows="3"
                                      class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"><?php echo $edit_category['description'] ?? ''; ?></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">الأيقونة</label>
                            <select name="icon" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                                <option value="fa-folder">📁 مجلد</option>
                                <option value="fa-users">👥 موظفين</option>
                                <option value="fa-building">🏢 مباني</option>
                                <option value="fa-bolt">⚡ كهرباء</option>
                                <option value="fa-tools">🔧 صيانة</option>
                                <option value="fa-bullhorn">📢 تسويق</option>
                                <option value="fa-car">🚗 مواصلات</option>
                                <option value="fa-paperclip">📎 قرطاسية</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">اللون</label>
                            <input type="color" name="color" 
                                   value="<?php echo $edit_category['color'] ?? '#6c757d'; ?>"
                                   class="w-full h-12 border-2 border-gray-300 rounded-lg">
                        </div>
                        
                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 bg-purple-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-purple-700">
                                <i class="fas fa-save ml-2"></i>
                                <?php echo $edit_category ? 'تحديث' : 'حفظ'; ?>
                            </button>
                            <?php if ($edit_category): ?>
                                <a href="categories.php" class="flex-1 bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-bold hover:bg-gray-300 text-center">
                                    إلغاء
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Categories List -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="px-6 py-4 bg-purple-50 border-b">
                        <h3 class="text-lg font-bold text-gray-800">الفئات الحالية</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-6">
                        <?php foreach ($categories as $cat): ?>
                            <div class="border-2 rounded-lg p-4 hover:shadow-lg transition-all" 
                                 style="border-color: <?php echo $cat['color']; ?>">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 rounded-full flex items-center justify-center text-2xl"
                                             style="background-color: <?php echo $cat['color']; ?>20;">
                                            <i class="fas <?php echo $cat['icon']; ?>" style="color: <?php echo $cat['color']; ?>"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-gray-800"><?php echo htmlspecialchars($cat['category_name']); ?></h4>
                                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($cat['category_code']); ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($cat['description']): ?>
                                    <p class="text-sm text-gray-600 mb-3"><?php echo htmlspecialchars($cat['description']); ?></p>
                                <?php endif; ?>
                                
                                <div class="flex items-center justify-between text-sm mb-3">
                                    <span class="text-gray-600">عدد المصروفات: <strong><?php echo $cat['expense_count']; ?></strong></span>
                                    <span class="text-gray-600">الإجمالي: <strong><?php echo number_format($cat['total_amount'] ?? 0, 0, '', ''); ?></strong></span>
                                </div>
                                
                                <div class="flex gap-2">
                                    <a href="?edit=<?php echo $cat['id']; ?>" 
                                       class="flex-1 bg-blue-500 text-white px-4 py-2 rounded-lg text-center hover:bg-blue-600">
                                        <i class="fas fa-edit ml-1"></i>
                                        تعديل
                                    </a>
                                    <a href="?action=delete&id=<?php echo $cat['id']; ?>" 
                                       onclick="return confirm('هل أنت متأكد من الحذف؟')"
                                       class="flex-1 bg-red-500 text-white px-4 py-2 rounded-lg text-center hover:bg-red-600">
                                        <i class="fas fa-trash ml-1"></i>
                                        حذف
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
