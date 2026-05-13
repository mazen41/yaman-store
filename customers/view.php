<?php
// Redirect to enhanced view
if (isset($_GET['id'])) {
    header('Location: view_enhanced.php?id=' . intval($_GET['id']));
} else {
    header('Location: index.php');
}
exit();
?>

$customer_id = intval($_GET['id']);

// Fetch customer data
try {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND is_active = 1");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        header('Location: index.php');
        exit();
    }
    
    // Fetch office data if office_id exists
    if (!empty($customer['office_id'])) {
        $office_stmt = $db->prepare("SELECT * FROM offices WHERE id = ?");
        $office_stmt->execute([$customer['office_id']]);
        $office = $office_stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $office = null;
    }
    
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات العميل';
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">بيانات العميل</h1>
                        <p class="text-gray-600 mt-1">عرض بيانات العميل بالتفصيل</p>
                    </div>
                    <div class="flex space-x-2 space-x-reverse">
                        <a href="edit.php?id=<?php echo $customer_id; ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-edit ml-2"></i>
                            تعديل
                        </a>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للقائمة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <!-- Customer Information -->
        <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">المعلومات الأساسية</h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <div class="flex items-center mb-4">
                            <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                <?php if ($customer['customer_type'] == 'company'): ?>
                                    <i class="fas fa-building text-xl"></i>
                                <?php elseif ($customer['customer_type'] == 'delegate'): ?>
                                    <i class="fas fa-user-tie text-xl"></i>
                                <?php else: ?>
                                    <i class="fas fa-user text-xl"></i>
                                <?php endif; ?>
                            </div>
                            <div class="mr-4">
                                <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($customer['name']); ?></h3>
                                <p class="text-sm text-gray-600">
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?php 
                                        if ($customer['customer_type'] == 'company') {
                                            echo 'bg-blue-100 text-blue-800';
                                        } elseif ($customer['customer_type'] == 'delegate') {
                                            echo 'bg-purple-100 text-purple-800';
                                        } else {
                                            echo 'bg-amber-100 text-amber-800';
                                        }
                                    ?>">
                                        <?php 
                                        if ($customer['customer_type'] == 'company') {
                                            echo 'شركة';
                                        } elseif ($customer['customer_type'] == 'delegate') {
                                            echo 'مندوب';
                                        } else {
                                            echo 'فرد';
                                        }
                                        ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                        
                        <div class="mt-4 space-y-3">
                            <div>
                                <span class="text-sm font-medium text-gray-500">رقم العميل:</span>
                                <span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['customer_code']); ?></span>
                            </div>
                            
                            <?php if (!empty($customer['office_name'])): ?>
                            <div>
                                <span class="text-sm font-medium text-gray-500">المكتب:</span>
                                <span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['office_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['credit_limit'])): ?>
                            <div>
                                <span class="text-sm font-medium text-gray-500">حد الائتمان:</span>
                                <span class="text-sm text-gray-900 mr-2"><?php echo number_format($customer['credit_limit'], 0, '', ''); ?> ريال</span>
                            </div>
                            <?php endif; ?>
                            
                            <div>
                                <span class="text-sm font-medium text-gray-500">الرصيد الحالي:</span>
                                <span class="text-sm <?php echo ($customer['current_balance'] ?? 0) >= 0 ? 'text-amber-600' : 'text-red-600'; ?> mr-2">
                                    <?php echo number_format($customer['current_balance'] ?? 0, 0, '', ''); ?> ريال
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="border-r border-gray-200 pr-6">
                        <h4 class="text-sm font-medium text-gray-500 mb-3">معلومات الاتصال</h4>
                        
                        <div class="space-y-3">
                            <?php if (!empty($customer['phone'])): ?>
                            <div class="flex items-center">
                                <i class="fas fa-phone text-gray-400 w-5"></i>
                                <span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['phone']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['mobile_number'])): ?>
                            <div class="flex items-center">
                                <i class="fas fa-mobile-alt text-gray-400 w-5"></i>
                                <span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['mobile_number']); ?></span>
                                <span class="text-xs text-gray-500 mr-2">(رقم الجوال)</span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['whatsapp_number'])): ?>
                            <div class="flex items-center">
                                <i class="fab fa-whatsapp text-gray-400 w-5"></i>
                                <span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['whatsapp_number']); ?></span>
                                <span class="text-xs text-gray-500 mr-2">(واتساب)</span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['alternative_number'])): ?>
                            <div class="flex items-center">
                                <i class="fas fa-phone-alt text-gray-400 w-5"></i>
                                <span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['alternative_number']); ?></span>
                                <span class="text-xs text-gray-500 mr-2">(رقم بديل)</span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['email'])): ?>
                            <div class="flex items-center">
                                <i class="fas fa-envelope text-gray-400 w-5"></i>
                                <span class="text-sm text-gray-900 mr-2"><?php echo htmlspecialchars($customer['email']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Location Information -->
        <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">معلومات الموقع</h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php if (!empty($customer['location_area'])): ?>
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 mb-2">منطقة الموقع</h4>
                        <p class="text-sm text-gray-900"><?php echo htmlspecialchars($customer['location_area']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['pickup_location'])): ?>
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 mb-2">موقع الاستلام</h4>
                        <p class="text-sm text-gray-900"><?php echo htmlspecialchars($customer['pickup_location']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($customer['address'])): ?>
                <div class="mt-6">
                    <h4 class="text-sm font-medium text-gray-500 mb-2">العنوان الكامل</h4>
                    <p class="text-sm text-gray-900"><?php echo nl2br(htmlspecialchars($customer['address'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Additional Information -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">معلومات إضافية</h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 mb-2">تاريخ الإضافة</h4>
                        <p class="text-sm text-gray-900"><?php echo date('d/m/Y', strtotime($customer['created_at'])); ?></p>
                    </div>
                    
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 mb-2">آخر تحديث</h4>
                        <p class="text-sm text-gray-900"><?php echo date('d/m/Y', strtotime($customer['updated_at'])); ?></p>
                    </div>
                </div>
                
                <?php if (!empty($customer['notes'])): ?>
                <div class="mt-6">
                    <h4 class="text-sm font-medium text-gray-500 mb-2">ملاحظات</h4>
                    <p class="text-sm text-gray-900"><?php echo nl2br(htmlspecialchars($customer['notes'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
