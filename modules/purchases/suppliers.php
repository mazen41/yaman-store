<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة الموردين';
$error_message = '';
$success_message = '';

// Handle Add/Edit/Delete Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $supplier_id = $_POST['supplier_id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    try {
        if ($action == 'add' && !empty($name)) {
            // Generate unique supplier code
            $supplier_code = 'SUP-' . date('Y') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            // Check if code exists
            $check_stmt = $db->prepare("SELECT id FROM suppliers WHERE supplier_code = ?");
            $check_stmt->execute([$supplier_code]);
            if ($check_stmt->fetch()) {
                $supplier_code = 'SUP-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            }
            
            $city = trim($_POST['city'] ?? '');
            $country = trim($_POST['country'] ?? 'السعودية');
            $tax_number = trim($_POST['tax_number'] ?? '');
            $payment_terms = trim($_POST['payment_terms'] ?? '');
            $credit_limit = floatval($_POST['credit_limit'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            $stmt = $db->prepare('INSERT INTO suppliers (supplier_code, name, contact_person, phone, email, address, city, country, tax_number, payment_terms, credit_limit, is_active, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$supplier_code, $name, $contact_person, $phone, $email, $address, $city, $country, $tax_number, $payment_terms, $credit_limit, $is_active, $notes]);
            $success_message = 'تمت إضافة المورد بنجاح. كود المورد: ' . $supplier_code;
        } elseif ($action == 'edit' && !empty($supplier_id) && !empty($name)) {
            $city = trim($_POST['city'] ?? '');
            $country = trim($_POST['country'] ?? 'السعودية');
            $tax_number = trim($_POST['tax_number'] ?? '');
            $payment_terms = trim($_POST['payment_terms'] ?? '');
            $credit_limit = floatval($_POST['credit_limit'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            $stmt = $db->prepare('UPDATE suppliers SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?, city = ?, country = ?, tax_number = ?, payment_terms = ?, credit_limit = ?, is_active = ?, notes = ? WHERE id = ?');
            $stmt->execute([$name, $contact_person, $phone, $email, $address, $city, $country, $tax_number, $payment_terms, $credit_limit, $is_active, $notes, $supplier_id]);
            $success_message = 'تم تحديث بيانات المورد بنجاح.';
        } elseif ($action == 'delete' && !empty($supplier_id)) {
            // Check if supplier has purchase orders
            $check_stmt = $db->prepare('SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?');
            $check_stmt->execute([$supplier_id]);
            $order_count = $check_stmt->fetchColumn();
            
            if ($order_count > 0) {
                $error_message = 'لا يمكن حذف المورد لأنه مرتبط بطلبات شراء موجودة.';
            } else {
                $stmt = $db->prepare('UPDATE suppliers SET is_active = 0 WHERE id = ?');
                $stmt->execute([$supplier_id]);
                $success_message = 'تم إلغاء تفعيل المورد بنجاح.';
            }
        }
    } catch (PDOException $e) {
        $error_message = 'حدث خطأ: ' . $e->getMessage();
    }
}

// Fetch all suppliers
$search = $_GET['search'] ?? '';
$query = 'SELECT * FROM suppliers';
$params = [];
if (!empty($search)) {
    $query .= ' WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$query .= ' ORDER BY created_at DESC';
$stmt = $db->prepare($query);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="container mx-auto px-4 sm:px-8 max-w-5xl">
    <div class="py-8">
        <div class="flex flex-col sm:flex-row mb-4 justify-between items-center">
            <h2 class="text-2xl font-bold leading-tight">
                <?php echo $page_title; ?>
            </h2>
            <div class="text-end mt-4 sm:mt-0 flex space-x-2 space-x-reverse">
                <form action="suppliers.php" method="GET" class="flex items-center space-x-2 space-x-reverse">
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-2">
                            <i class="fas fa-search text-gray-400"></i>
                        </span>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="pl-8 pr-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="بحث..."/>
                    </div>
                    <button type="submit" class="px-4 py-2 font-semibold text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        بحث
                    </button>
                </form>
                <button onclick="openModal('addSupplierModal')" class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300 flex items-center">
                    <i class="fas fa-plus mr-2"></i> إضافة مورد جديد
                </button>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="bg-amber-100 border-l-4 border-amber-500 text-amber-700 p-4 mt-4 rounded-md" role="alert">
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mt-4 rounded-md" role="alert">
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <div class="-mx-4 sm:-mx-8 px-4 sm:px-8 py-4 overflow-x-auto">
            <div class="inline-block min-w-full shadow-md rounded-lg overflow-hidden">
                <table class="min-w-full leading-normal">
                    <thead>
                        <tr>
                                                        <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"><i class="fas fa-barcode mr-2"></i>كود المورد</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"><i class="fas fa-building mr-2"></i>الاسم</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"><i class="fas fa-user-tie mr-2"></i>شخص الاتصال</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"><i class="fas fa-envelope mr-2"></i>البريد الإلكتروني</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider"><i class="fas fa-phone mr-2"></i>الهاتف</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider"><i class="fas fa-check-circle mr-2"></i>الحالة</th>
                            <th class="px-5 py-3 border-b-2 border-gray-200 bg-gray-100 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider"><i class="fas fa-cogs mr-2"></i>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="7" class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">لا يوجد موردين لعرضهم.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm font-mono"><?php echo htmlspecialchars($supplier['supplier_code']); ?></td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?php echo htmlspecialchars($supplier['name']); ?></td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?php echo htmlspecialchars($supplier['contact_person']); ?></td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?php echo htmlspecialchars($supplier['email']); ?></td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm"><?php echo htmlspecialchars($supplier['phone']); ?></td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                    <span class="relative inline-block px-3 py-1 font-semibold leading-tight <?php echo $supplier['is_active'] ? 'text-amber-900' : 'text-red-900'; ?>">
                                        <span aria-hidden class="absolute inset-0 <?php echo $supplier['is_active'] ? 'bg-amber-200' : 'bg-red-200'; ?> opacity-50 rounded-full"></span>
                                        <span class="relative"><?php echo $supplier['is_active'] ? 'نشط' : 'غير نشط'; ?></span>
                                    </span>
                                </td>
                                <td class="px-5 py-5 border-b border-gray-200 bg-white text-sm text-center">
                                    <button onclick='openModal("editSupplierModal", <?php echo json_encode($supplier); ?>)' class="text-indigo-600 hover:text-indigo-900 mx-2">
                                        <i class="fas fa-pencil-alt"></i>
                                    </button>
                                    <form action="suppliers.php" method="POST" class="inline-block" onsubmit="return confirm('هل أنت متأكد من رغبتك في حذف هذا المورد؟');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="supplier_id" value="<?php echo $supplier['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900 mx-2">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
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

<!-- Add/Edit Supplier Modal -->
<div id="addSupplierModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center pb-3">
            <p class="text-2xl font-bold">إضافة مورد جديد</p>
            <div class="cursor-pointer z-50" onclick="closeModal('addSupplierModal')">
                <i class="fas fa-times"></i>
            </div>
        </div>
        <form action="suppliers.php" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">اسم المورد</label>
                    <input type="text" name="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">شخص الاتصال</label>
                    <input type="text" name="contact_person" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">البريد الإلكتروني</label>
                    <input type="email" name="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">الهاتف</label>
                    <input type="text" name="phone" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">العنوان</label>
                    <textarea name="address" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"></textarea>
                </div>
                <div class="flex items-center">
                    <input type="checkbox" name="is_active" id="is_active_add" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded" checked>
                    <label for="is_active_add" class="ml-2 block text-sm text-gray-900">نشط</label>
                </div>
                <div class="pt-4 flex justify-end">
                    <button type="button" onclick="closeModal('addSupplierModal')" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded-lg mr-2">إلغاء</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">حفظ</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="editSupplierModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center pb-3">
            <p class="text-2xl font-bold">تعديل بيانات المورد</p>
            <div class="cursor-pointer z-50" onclick="closeModal('editSupplierModal')">
                <i class="fas fa-times"></i>
            </div>
        </div>
        <form action="suppliers.php" method="POST">
            <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="supplier_id" id="edit_supplier_id">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">كود المورد</label>
                    <input type="text" id="edit_supplier_code" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm bg-gray-100" readonly>
                </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">اسم المورد</label>
                    <input type="text" name="name" id="edit_name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">شخص الاتصال</label>
                    <input type="text" name="contact_person" id="edit_contact_person" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">البريد الإلكتروني</label>
                    <input type="email" name="email" id="edit_email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">الهاتف</label>
                    <input type="text" name="phone" id="edit_phone" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">العنوان</label>
                    <textarea name="address" id="edit_address" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"></textarea>
                </div>
                <div class="flex items-center">
                    <input type="checkbox" name="is_active" id="edit_is_active" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                    <label for="edit_is_active" class="ml-2 block text-sm text-gray-900">نشط</label>
                </div>
                <div class="pt-4 flex justify-end">
                    <button type="button" onclick="closeModal('editSupplierModal')" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded-lg mr-2">إلغاء</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">حفظ التغييرات</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(modalId, data = null) {
        if (modalId === 'editSupplierModal' && data) {
                        document.getElementById('edit_supplier_id').value = data.id;
            document.getElementById('edit_supplier_code').value = data.supplier_code;
            document.getElementById('edit_name').value = data.name;
            document.getElementById('edit_contact_person').value = data.contact_person;
            document.getElementById('edit_email').value = data.email;
            document.getElementById('edit_phone').value = data.phone;
            document.getElementById('edit_address').value = data.address;
            document.getElementById('edit_is_active').checked = data.is_active == 1;
        }
        document.getElementById(modalId).classList.remove('hidden');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
    }
</script>

<?php include '../../includes/footer.php'; ?>
