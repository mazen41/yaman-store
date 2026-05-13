<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة المرسلين';
$error_message = '';
$success_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['add_sender'])) {
            $name = trim($_POST['name']);
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $portal_token = bin2hex(random_bytes(32));
            
            if (empty($name)) {
                throw new Exception('اسم المرسل مطلوب');
            }
            
            $stmt = $db->prepare("INSERT INTO senders (name, phone, email, address, portal_token) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $email, $address, $portal_token]);
            $success_message = 'تم إضافة المرسل بنجاح';
        }
        
        if (isset($_POST['edit_sender'])) {
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            
            if (empty($name)) {
                throw new Exception('اسم المرسل مطلوب');
            }
            
            $stmt = $db->prepare("UPDATE senders SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
            $stmt->execute([$name, $phone, $email, $address, $id]);
            $success_message = 'تم تحديث المرسل بنجاح';
        }
        
        if (isset($_POST['delete_sender'])) {
            $id = intval($_POST['id']);
            $db->prepare("DELETE FROM senders WHERE id = ?")->execute([$id]);
            $success_message = 'تم حذف المرسل بنجاح';
        }
        
        if (isset($_POST['regenerate_token'])) {
            $id = intval($_POST['id']);
            $portal_token = bin2hex(random_bytes(32));
            $db->prepare("UPDATE senders SET portal_token = ? WHERE id = ?")->execute([$portal_token, $id]);
            $success_message = 'تم إعادة توليد رابط البوابة بنجاح';
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Fetch all senders
$senders = $db->query("
    SELECT s.*, 
           (SELECT COUNT(*) FROM shipments WHERE sender_id = s.id) as shipment_count
    FROM senders s
    ORDER BY s.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

<style>
    @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap');
    
    body {
        font-family: 'Cairo', sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
    }
    
    .sender-card {
        background: white;
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }
    
    .sender-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 12px 40px rgba(102, 126, 234, 0.3);
        border-color: #667eea;
    }
    
    .portal-link {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        padding: 1rem;
        border-radius: 12px;
        font-size: 0.875rem;
        word-break: break-all;
        border: 2px solid #bae6fd;
        position: relative;
        overflow: hidden;
    }
    
    .portal-link::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.5), transparent);
        transition: left 0.5s;
    }
    
    .portal-link:hover::before {
        left: 100%;
    }
    
    .gradient-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 20px;
        padding: 2rem;
        color: white;
        box-shadow: 0 10px 40px rgba(102, 126, 234, 0.4);
        margin-bottom: 2rem;
    }
    
    .stat-badge {
        background: rgba(255,255,255,0.2);
        backdrop-filter: blur(10px);
        padding: 0.5rem 1rem;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .action-btn {
        transition: all 0.2s ease;
    }
    
    .action-btn:hover {
        transform: scale(1.1);
    }
</style>

<div class="min-h-screen py-8 px-4" dir="rtl">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="gradient-header">
            <div class="flex justify-between items-center flex-wrap gap-4">
                <div>
                    <h1 class="text-4xl font-bold mb-2 flex items-center gap-3">
                        <i class="fas fa-user-tie"></i>
                        <?php echo $page_title; ?>
                    </h1>
                    <p class="text-blue-100 text-lg">إدارة المرسلين وتتبع الشحنات</p>
                </div>
                <button onclick="document.getElementById('addSenderModal').classList.remove('hidden')" 
                        class="bg-white text-purple-600 px-8 py-4 rounded-2xl font-bold hover:bg-purple-50 transition-all duration-300 shadow-xl hover:shadow-2xl transform hover:scale-105 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-2xl"></i>
                    <span>إضافة مرسل جديد</span>
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-lg animate-pulse">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-2xl ml-3"></i>
                    <p class="font-medium"><?php echo $error_message; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="bg-green-100 border-r-4 border-green-500 text-green-700 p-4 rounded-lg mb-6 shadow-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-2xl ml-3"></i>
                    <p class="font-medium"><?php echo $success_message; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Senders Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($senders as $sender): ?>
            <div class="sender-card group">
                <!-- Header with Actions -->
                <div class="flex justify-between items-start mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white text-xl font-bold shadow-lg">
                            <?php echo mb_substr($sender['name'], 0, 1); ?>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($sender['name']); ?></h3>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-box ml-1"></i>
                                <?php echo $sender['shipment_count']; ?> شحنة
                            </span>
                        </div>
                    </div>
                    
                    <div class="relative">
                        <button onclick="toggleMenu(<?php echo $sender['id']; ?>)" class="action-btn p-2 hover:bg-gray-100 rounded-lg">
                            <i class="fas fa-ellipsis-v text-gray-600"></i>
                        </button>
                        <div id="menu-<?php echo $sender['id']; ?>" class="hidden absolute left-0 mt-2 w-48 bg-white rounded-lg shadow-xl z-10 border border-gray-200">
                            <a href="#" onclick="editSender(<?php echo htmlspecialchars(json_encode($sender)); ?>)" class="block px-4 py-2 text-sm text-gray-700 hover:bg-purple-50 rounded-t-lg">
                                <i class="fas fa-edit text-purple-600 ml-2"></i> تعديل
                            </a>
                            <a href="#" onclick="regenerateToken(<?php echo $sender['id']; ?>)" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50">
                                <i class="fas fa-sync text-blue-600 ml-2"></i> إعادة توليد الرابط
                            </a>
                            <hr class="my-1">
                            <a href="#" onclick="deleteSender(<?php echo $sender['id']; ?>, '<?php echo htmlspecialchars($sender['name']); ?>')" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 rounded-b-lg">
                                <i class="fas fa-trash ml-2"></i> حذف
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Contact Info -->
                <div class="space-y-3 mb-4">
                    <div class="flex items-center gap-2 text-sm">
                        <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-phone text-green-600"></i>
                        </div>
                        <span class="text-gray-700"><?php echo htmlspecialchars($sender['phone'] ?: 'غير محدد'); ?></span>
                    </div>
                    
                    <?php if ($sender['email']): ?>
                    <div class="flex items-center gap-2 text-sm">
                        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-envelope text-blue-600"></i>
                        </div>
                        <span class="text-gray-700"><?php echo htmlspecialchars($sender['email']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($sender['address']): ?>
                    <div class="flex items-start gap-2 text-sm">
                        <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-map-marker-alt text-red-600"></i>
                        </div>
                        <span class="text-gray-700 flex-1"><?php echo htmlspecialchars($sender['address']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Portal Link -->
                <div class="portal-link">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-semibold text-blue-700 flex items-center gap-1">
                            <i class="fas fa-link"></i>
                            رابط البوابة
                        </span>
                        <button onclick="copyPortalLink('<?php echo $sender['portal_token']; ?>')" class="action-btn px-3 py-1 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700 transition-all">
                            <i class="fas fa-copy ml-1"></i>
                            نسخ
                        </button>
                    </div>
                    <a href="https://yamanstore.org/sender_portal/?token=<?php echo $sender['portal_token']; ?>" target="_blank" class="text-xs text-blue-600 hover:text-blue-800 break-all">
                        https://yamanstore.org/?token=<?php echo substr($sender['portal_token'], 0, 30); ?>...
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Add Sender Modal -->
<div id="addSenderModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto">
        <form method="POST">
            <div class="bg-gradient-to-r from-purple-600 to-pink-600 text-white p-6 rounded-t-2xl">
                <div class="flex justify-between items-center">
                    <h3 class="text-2xl font-bold flex items-center gap-2">
                        <i class="fas fa-user-plus"></i>
                        إضافة مرسل جديد
                    </h3>
                    <button type="button" onclick="document.getElementById('addSenderModal').classList.add('hidden')" class="text-white hover:text-gray-200 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-user text-purple-600 ml-1"></i>
                        اسم المرسل <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="name" required
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all"
                           placeholder="أدخل اسم المرسل">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-phone text-green-600 ml-1"></i>
                        رقم الهاتف
                    </label>
                    <input type="text" name="phone"
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-green-500 focus:ring-2 focus:ring-green-200 transition-all"
                           placeholder="05xxxxxxxx">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-envelope text-blue-600 ml-1"></i>
                        البريد الإلكتروني
                    </label>
                    <input type="email" name="email"
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                           placeholder="example@email.com">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-map-marker-alt text-red-600 ml-1"></i>
                        العنوان
                    </label>
                    <textarea name="address" rows="3"
                              class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-red-500 focus:ring-2 focus:ring-red-200 transition-all"
                              placeholder="أدخل العنوان"></textarea>
                </div>
            </div>
            
            <div class="bg-gray-50 px-6 py-4 rounded-b-2xl flex gap-3 justify-end">
                <button type="button" onclick="document.getElementById('addSenderModal').classList.add('hidden')"
                        class="px-6 py-3 bg-gray-300 text-gray-700 rounded-lg font-bold hover:bg-gray-400 transition-all">
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </button>
                <button type="submit" name="add_sender"
                        class="px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-lg font-bold hover:from-purple-700 hover:to-pink-700 transition-all shadow-lg">
                    <i class="fas fa-save ml-2"></i>
                    حفظ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Sender Modal -->
<div id="editSenderModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto">
        <form method="POST">
            <input type="hidden" name="id" id="edit_sender_id">
            
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-6 rounded-t-2xl">
                <div class="flex justify-between items-center">
                    <h3 class="text-2xl font-bold flex items-center gap-2">
                        <i class="fas fa-edit"></i>
                        تعديل المرسل
                    </h3>
                    <button type="button" onclick="document.getElementById('editSenderModal').classList.add('hidden')" class="text-white hover:text-gray-200 text-2xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-user text-purple-600 ml-1"></i>
                        اسم المرسل <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="name" id="edit_sender_name" required
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-phone text-green-600 ml-1"></i>
                        رقم الهاتف
                    </label>
                    <input type="text" name="phone" id="edit_sender_phone"
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-green-500 focus:ring-2 focus:ring-green-200 transition-all">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-envelope text-blue-600 ml-1"></i>
                        البريد الإلكتروني
                    </label>
                    <input type="email" name="email" id="edit_sender_email"
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-map-marker-alt text-red-600 ml-1"></i>
                        العنوان
                    </label>
                    <textarea name="address" id="edit_sender_address" rows="3"
                              class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-red-500 focus:ring-2 focus:ring-red-200 transition-all"></textarea>
                </div>
            </div>
            
            <div class="bg-gray-50 px-6 py-4 rounded-b-2xl flex gap-3 justify-end">
                <button type="button" onclick="document.getElementById('editSenderModal').classList.add('hidden')"
                        class="px-6 py-3 bg-gray-300 text-gray-700 rounded-lg font-bold hover:bg-gray-400 transition-all">
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </button>
                <button type="submit" name="edit_sender"
                        class="px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg font-bold hover:from-blue-700 hover:to-indigo-700 transition-all shadow-lg">
                    <i class="fas fa-save ml-2"></i>
                    تحديث
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleMenu(id) {
    const menu = document.getElementById('menu-' + id);
    // Close all other menus
    document.querySelectorAll('[id^="menu-"]').forEach(m => {
        if (m.id !== 'menu-' + id) m.classList.add('hidden');
    });
    menu.classList.toggle('hidden');
}

// Close menus when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.relative')) {
        document.querySelectorAll('[id^="menu-"]').forEach(m => m.classList.add('hidden'));
    }
});

function editSender(sender) {
    document.getElementById('edit_sender_id').value = sender.id;
    document.getElementById('edit_sender_name').value = sender.name;
    document.getElementById('edit_sender_phone').value = sender.phone || '';
    document.getElementById('edit_sender_email').value = sender.email || '';
    document.getElementById('edit_sender_address').value = sender.address || '';
    document.getElementById('editSenderModal').classList.remove('hidden');
    document.querySelectorAll('[id^="menu-"]').forEach(m => m.classList.add('hidden'));
}

function deleteSender(id, name) {
    if (confirm('هل أنت متأكد من حذف المرسل: ' + name + '؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="id" value="' + id + '"><input type="hidden" name="delete_sender" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}

function regenerateToken(id) {
    if (confirm('هل أنت متأكد من إعادة توليد رابط البوابة؟ الرابط القديم لن يعمل بعد ذلك.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="id" value="' + id + '"><input type="hidden" name="regenerate_token" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}

function copyPortalLink(token) {
    const link = 'https://yamanstore.org/sender_portal/?token=' + token;
    navigator.clipboard.writeText(link).then(() => {
        // Show success notification
        const notification = document.createElement('div');
        notification.className = 'fixed top-4 left-1/2 transform -translate-x-1/2 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 animate-bounce';
        notification.innerHTML = '<i class="fas fa-check-circle ml-2"></i> تم نسخ الرابط بنجاح!';
        document.body.appendChild(notification);
        setTimeout(() => notification.remove(), 3000);
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
