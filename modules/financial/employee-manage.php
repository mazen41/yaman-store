<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'إنشاء وحذف الموظفين';

// Handle delete
if (isset($_GET['delete'])) {
    try {
        $id = intval($_GET['delete']);
        // Delete user (cascade will delete permissions)
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        $success_message = 'تم حذف الموظف بنجاح';
    } catch (PDOException $e) {
        $error_message = 'حدث خطأ: ' . $e->getMessage();
    }
}

// Handle add/edit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $username = trim($_POST['username']);
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $password = $_POST['password'];
        
        if (isset($_POST['id']) && !empty($_POST['id'])) {
            // Update
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    UPDATE users 
                    SET username = ?, full_name = ?, email = ?, role = ?, password = ?
                    WHERE id = ?
                ");
                $stmt->execute([$username, $full_name, $email, $role, $hashed_password, $_POST['id']]);
            } else {
                $stmt = $db->prepare("
                    UPDATE users 
                    SET username = ?, full_name = ?, email = ?, role = ?
                    WHERE id = ?
                ");
                $stmt->execute([$username, $full_name, $email, $role, $_POST['id']]);
            }
            $success_message = 'تم تحديث الموظف بنجاح';
        } else {
            // Insert
            if (empty($password)) {
                $error_message = 'كلمة المرور مطلوبة للموظف الجديد';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    INSERT INTO users (username, full_name, email, password, role, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$username, $full_name, $email, $hashed_password, $role]);
                $success_message = 'تم إضافة الموظف بنجاح';
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error_message = 'اسم المستخدم أو البريد الإلكتروني موجود مسبقاً';
        } else {
            $error_message = 'حدث خطأ: ' . $e->getMessage();
        }
    }
}

// Fetch all users
$users = $db->query("
    SELECT u.*, 
    (SELECT COUNT(*) FROM user_permissions WHERE user_id = u.id) as permissions_count
    FROM users u
    WHERE u.id != {$_SESSION['user_id']}
    ORDER BY u.created_at DESC
")->fetchAll();

// Get edit data
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_user = $db->query("SELECT * FROM users WHERE id = $edit_id")->fetch();
}

// Statistics
$total_users = count($users);
$active_users = $db->query("SELECT COUNT(*) FROM users WHERE id != {$_SESSION['user_id']}")->fetchColumn();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">
                            <i class="fas fa-users-cog ml-2 text-amber-600"></i>
                            إنشاء وحذف الموظفين وإعطائهم صلاحيات معينة
                        </h1>
                        <p class="text-gray-600 mt-1">إدارة الموظفين وصلاحياتهم</p>
                    </div>
                    <div class="mt-4 sm:mt-0 flex gap-2">
                        <a href="employee-permissions.php" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition duration-200">
                            <i class="fas fa-user-shield ml-2"></i>
                            إدارة الصلاحيات
                        </a>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($success_message)): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded mb-6">
            <i class="fas fa-check-circle ml-2"></i><?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i><?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-users text-3xl text-blue-600"></i>
                    </div>
                    <div class="mr-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">إجمالي الموظفين</dt>
                            <dd class="text-2xl font-bold text-gray-900"><?php echo $total_users; ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-user-check text-3xl text-amber-600"></i>
                    </div>
                    <div class="mr-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">الموظفين النشطين</dt>
                            <dd class="text-2xl font-bold text-gray-900"><?php echo $active_users; ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            
            <div class="bg-white shadow rounded-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-shield-alt text-3xl text-purple-600"></i>
                    </div>
                    <div class="mr-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">الصلاحيات الممنوحة</dt>
                            <dd class="text-2xl font-bold text-gray-900">
                                <?php echo $db->query("SELECT COUNT(*) FROM user_permissions")->fetchColumn(); ?>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Add/Edit Form -->
            <div class="lg:col-span-1">
                <div class="bg-white shadow rounded-lg p-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">
                        <i class="fas fa-user-plus ml-2 text-amber-600"></i>
                        <?php echo $edit_user ? 'تعديل الموظف' : 'إضافة موظف جديد'; ?>
                    </h2>
                    
                    <form method="POST" class="space-y-4">
                        <?php if ($edit_user): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
                        <?php endif; ?>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">اسم المستخدم</label>
                            <input type="text" name="username" required
                                   value="<?php echo $edit_user['username'] ?? ''; ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">الاسم الكامل</label>
                            <input type="text" name="full_name" required
                                   value="<?php echo $edit_user['full_name'] ?? ''; ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">البريد الإلكتروني</label>
                            <input type="email" name="email" required
                                   value="<?php echo $edit_user['email'] ?? ''; ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">الدور الوظيفي</label>
                            <select name="role" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                                <option value="employee" <?php echo ($edit_user['role'] ?? '') == 'employee' ? 'selected' : ''; ?>>موظف</option>
                                <option value="accountant" <?php echo ($edit_user['role'] ?? '') == 'accountant' ? 'selected' : ''; ?>>محاسب</option>
                                <option value="manager" <?php echo ($edit_user['role'] ?? '') == 'manager' ? 'selected' : ''; ?>>مدير</option>
                                <option value="admin" <?php echo ($edit_user['role'] ?? '') == 'admin' ? 'selected' : ''; ?>>مسؤول</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                كلمة المرور <?php echo $edit_user ? '(اتركها فارغة إذا لم ترد التغيير)' : ''; ?>
                            </label>
                            <input type="password" name="password" 
                                   <?php echo !$edit_user ? 'required' : ''; ?>
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        </div>
                        
                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 bg-amber-600 text-white px-4 py-2 rounded-lg hover:bg-amber-700 transition duration-200">
                                <i class="fas fa-save ml-2"></i>
                                <?php echo $edit_user ? 'تحديث' : 'حفظ'; ?>
                            </button>
                            <?php if ($edit_user): ?>
                            <a href="employee-manage.php" class="flex-1 text-center bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition duration-200">
                                إلغاء
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Users List -->
            <div class="lg:col-span-2">
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">قائمة الموظفين</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الموظف</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">البريد</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الدور</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الصلاحيات</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                        <i class="fas fa-users text-4xl mb-4 text-gray-300"></i>
                                        <p>لا يوجد موظفين</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center ml-3">
                                                <i class="fas fa-user text-amber-600"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['username']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php
                                        $role_colors = [
                                            'admin' => 'bg-red-100 text-red-800',
                                            'manager' => 'bg-purple-100 text-purple-800',
                                            'accountant' => 'bg-blue-100 text-blue-800',
                                            'employee' => 'bg-amber-100 text-amber-800',
                                        ];
                                        $role_names = [
                                            'admin' => 'مسؤول',
                                            'manager' => 'مدير',
                                            'accountant' => 'محاسب',
                                            'employee' => 'موظف',
                                        ];
                                        ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $role_colors[$user['role']]; ?>">
                                            <?php echo $role_names[$user['role']]; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">
                                            <i class="fas fa-shield-alt ml-1"></i>
                                            <?php echo $user['permissions_count']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <a href="employee-permissions.php?user_id=<?php echo $user['id']; ?>" 
                                           class="text-indigo-600 hover:text-indigo-900 ml-3"
                                           title="إدارة الصلاحيات">
                                            <i class="fas fa-user-shield"></i>
                                        </a>
                                        <a href="?edit=<?php echo $user['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-900 ml-3"
                                           title="تعديل">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?delete=<?php echo $user['id']; ?>" 
                                           onclick="return confirm('هل أنت متأكد من حذف هذا الموظف؟ سيتم حذف جميع صلاحياته أيضاً.')"
                                           class="text-red-600 hover:text-red-900"
                                           title="حذف">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>
