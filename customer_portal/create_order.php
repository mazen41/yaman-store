<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// --- 1. Customer Authentication via Token ---
$token = $_GET['token'] ?? '';
if (empty($token)) {
    die('Invalid access link. Please use the link provided to you.');
}

$stmt = $db->prepare("
    SELECT c.*, ct.id as customer_type_id_from_table, ct.name as customer_type_name
    FROM customers c
    LEFT JOIN customer_types ct ON c.customer_type_id = ct.id
    WHERE c.portal_token = ? AND c.is_active = 1
");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('Invalid or expired link. Please contact support.');
}

$customer_id = $customer['id'];
$customer_name = $customer['name'];
$customer_currency = $customer['currency'] ?? 'ريال يمني '; 
$customer_type_id_for_discount = $customer['customer_type_id_from_table'] ?? null;
$customer_type_name = htmlspecialchars($customer['customer_type_name'] ?? '');

$allow_no_deposit = $customer['allow_no_deposit_orders'] ?? 0;

// Fetch company phone from system settings
$company_phone_stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_phone'");
$company_phone_stmt->execute();
$raw_company_phone = $company_phone_stmt->fetchColumn() ?? '';

// Clean phone number for WhatsApp
$clean_phone = preg_replace('/[^0-9]/', '', $raw_company_phone);
if (strlen($clean_phone) == 9 && substr($clean_phone, 0, 1) === '5') {
    $clean_phone = '966' . $clean_phone;
} elseif (strlen($clean_phone) == 10 && substr($clean_phone, 0, 2) === '05') {
    $clean_phone = '966' . substr($clean_phone, 1);
} elseif (strlen($clean_phone) > 1 && substr($clean_phone, 0, 2) === '00') {
    $clean_phone = substr($clean_phone, 2);
} elseif (strlen($clean_phone) > 1 && substr($clean_phone, 0, 1) === '+') {
    $clean_phone = substr($clean_phone, 1);
}
if (strlen($clean_phone) < 10 && !empty($clean_phone) && substr($clean_phone, 0, 3) !== '966') {
     $clean_phone = '966' . $clean_phone; 
}
$whatsapp_number = $clean_phone; 

$order_success_data = $_SESSION['order_success_data'] ?? null;
unset($_SESSION['order_success_data']); 

// --- Function to get tiered discount ---
function getTieredCustomerDiscount($db, $customer_type_id, $amount, $currency = 'YER') {
    $discount_percentage = 0;
    $tier_info = 'لا يوجد خصم';

    if (empty($customer_type_id) || $amount <= 0) {
        return ['discount_percentage' => 0, 'tier_info' => $tier_info];
    }

    $typeStmt = $db->prepare("SELECT is_active FROM customer_types WHERE id = ?");
    $typeStmt->execute([$customer_type_id]);
    $type = $typeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$type || (isset($type['is_active']) && (int)$type['is_active'] === 0)) {
        return ['discount_percentage' => 0, 'tier_info' => 'نوع العميل غير نشط'];
    }

    $stmt = $db->prepare("
        SELECT min_amount, max_amount, discount_percentage
        FROM customer_type_discount_tiers
        WHERE customer_type_id = ?
        ORDER BY min_amount ASC
    ");
    $stmt->execute([$customer_type_id]);
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($tiers)) {
        return ['discount_percentage' => 0, 'tier_info' => 'لا توجد مستويات خصم لهذا النوع'];
    }

    $applicable_tier = null;
    usort($tiers, function($a, $b) {
        return floatval($b['min_amount']) - floatval($a['min_amount']);
    });
    
    foreach ($tiers as $tier) {
        $min = floatval($tier['min_amount']);
        $max = $tier['max_amount'];
        
        if ($amount >= $min) {
            if ($max === null || $max === '' || floatval($max) == 0 || $amount <= floatval($max)) {
                $applicable_tier = $tier;
                break;
            }
        }
    }

    if ($applicable_tier) {
        $discount_percentage = floatval($applicable_tier['discount_percentage']);
        $min_display = number_format($applicable_tier['min_amount'], 2);
        $max_val_display = $applicable_tier['max_amount'];
        $max_display = ($max_val_display !== null && $max_val_display !== '' && floatval($max_val_display) > 0) ? number_format($max_val_display, 2) : 'غير محدود';
        
        $tier_info = "خصم {$discount_percentage}% (الطلب بين {$min_display} و {$max_display} {$currency})";
    }

    return ['discount_percentage' => $discount_percentage, 'tier_info' => $tier_info];
}

// --- Function to get coupon discount ---
function getCouponDiscount($db, $coupon_code, $total_amount, $customer_id) {
    if (empty($coupon_code) || $total_amount <= 0) {
        return ['discount_amount' => 0, 'message' => ''];
    }

    $current_date = date('Y-m-d');
    $coupon_discount_amount = 0;

    try {
        $stmt = $db->prepare("SELECT * FROM coupons WHERE coupon_code = ? AND is_active = 1");
        $stmt->execute([$coupon_code]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$coupon || $current_date < $coupon['start_date'] || $current_date > $coupon['end_date'] || $total_amount < $coupon['min_order_amount']) {
            return ['discount_amount' => 0, 'message' => 'كوبون غير صالح أو منتهي الصلاحية'];
        }

        if ($coupon['usage_limit'] !== null) {
            $usageStmt = $db->prepare("SELECT COUNT(*) FROM order_approvals WHERE coupon_code = ? AND status != 'cancelled'");
            $usageStmt->execute([$coupon_code]);
            if ($usageStmt->fetchColumn() >= $coupon['usage_limit']) return ['discount_amount' => 0, 'message' => 'تم استهلاك الكوبون بالكامل'];
        }

        if ($coupon['user_usage_limit'] !== null) {
            $userUsageStmt = $db->prepare("SELECT COUNT(*) FROM order_approvals WHERE customer_id = ? AND coupon_code = ? AND status != 'cancelled'");
            $userUsageStmt->execute([$customer_id, $coupon_code]);
            if ($userUsageStmt->fetchColumn() >= $coupon['user_usage_limit']) return ['discount_amount' => 0, 'message' => 'تجاوزت الحد المسموح لاستخدام هذا الكوبون'];
        }

        if ($coupon['discount_type'] === 'percentage') {
            $coupon_discount_amount = $total_amount * (floatval($coupon['discount_value']) / 100);
        } else {
            $coupon_discount_amount = floatval($coupon['discount_value']);
        }

        if ($coupon['max_discount_amount'] !== null && $coupon_discount_amount > floatval($coupon['max_discount_amount'])) {
            $coupon_discount_amount = floatval($coupon['max_discount_amount']);
        }
        
        if ($coupon_discount_amount > $total_amount) $coupon_discount_amount = $total_amount;

        return ['discount_amount' => round($coupon_discount_amount, 2), 'message' => ''];

    } catch (PDOException $e) {
        return ['discount_amount' => 0, 'message' => 'خطأ داخلي في التحقق من الكوبون'];
    }
}

$page_title = 'إرسال طلب للموافقة';
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $items = $_POST['items'] ?? [];
    $notes = trim($_POST['notes'] ?? '');
    
    $coupon_code = trim($_POST['coupon_code'] ?? ''); 
    $paid_amount = floatval($_POST['paid_amount'] ?? 0);
    
    $clean_items = array_filter($items, function($item) {
        // Ensure that at least one of product_link or additional_link exists and item_count > 0, and total > 0
        return ((floatval($item['total'] ?? 0) > 0) || !empty(trim($item['product_link'] ?? '')) || !empty(trim($item['additional_link'] ?? ''))) && (intval($item['item_count'] ?? 0) > 0);
    });
    $items = $clean_items;

    $errors =[];
    if (empty($items)) {
        $errors[] = 'يرجى إضافة منتج واحد على الأقل (الرابط إلزامي، السعر إلزامي، وإجمالي القطع إلزامي ويجب أن يكون أكبر من صفر).';
    } else {
        foreach ($items as $index => $item) {
            if (empty(trim($item['product_link'] ?? '')) && empty(trim($item['additional_link'] ?? ''))) {
                 $errors[] = 'المنتج ' . ($index + 1) . ': رابط المنتج أو رابط إضافي إلزامي.';
            }
            if (intval($item['item_count'] ?? 0) <= 0) {
                $errors[] = 'المنتج ' . ($index + 1) . ': إجمالي القطع إلزامي ويجب أن يكون أكبر من صفر.';
            }
             if (floatval($item['total'] ?? 0) <= 0) {
                $errors[] = 'المنتج ' . ($index + 1) . ': الإجمالي إلزامي ويجب أن يكون أكبر من صفر.';
            }
        }
    }
    
    if (!$allow_no_deposit) {
        if (empty($_FILES['payment_proof']['name'])) {
            $errors[] = 'يرجى إرفاق إثبات الدفع.';
        }
        if ($paid_amount <= 0) {
            $errors[] = 'يرجى إدخال المبلغ المدفوع.';
        }
    }

    $subtotal_amount = 0;
    foreach ($items as $item) {
        $subtotal_amount += floatval($item['total'] ?? 0);
    }
    
    $discount_result = getTieredCustomerDiscount($db, $customer_type_id_for_discount, $subtotal_amount, $customer_currency);
    $calculated_automatic_discount_percentage = $discount_result['discount_percentage'];
    $calculated_automatic_discount_amount = round($subtotal_amount * ($calculated_automatic_discount_percentage / 100), 2);

    $coupon_discount_data = getCouponDiscount($db, $coupon_code, $subtotal_amount, $customer_id);
    $coupon_discount_amount = $coupon_discount_data['discount_amount'];
    if (!empty($coupon_discount_data['message']) && $coupon_discount_amount == 0) {
        $errors[] = $coupon_discount_data['message'];
        $coupon_code = ''; 
    }

    $total_after_discounts = $subtotal_amount - $calculated_automatic_discount_amount - $coupon_discount_amount;
    if ($total_after_discounts < 0) $total_after_discounts = 0; 

    $final_amount = $total_after_discounts; 
    
    if (!$allow_no_deposit) {
        $required_payment = $final_amount / 4;
        if ($paid_amount < $required_payment) {
            $errors[] = 'المبلغ المدفوع يجب أن يكون على الأقل ربع قيمة الطلب (' . number_format($required_payment, 0) . ' ' . $customer_currency . ').';
        }
    }

    if (!empty($errors)) {
        $error_message = implode(' • ', $errors);
    } else {
        try {
            $db->beginTransaction();

            $payment_proof_path = null;
            if (!$allow_no_deposit && isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/payment_proofs/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_ext = pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION);
                $new_filename = 'proof_' . $customer_id . '_' . time() . '.' . $file_ext;
                
                if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_dir . $new_filename)) {
                    $payment_proof_path = 'uploads/payment_proofs/' . $new_filename;
                } else {
                    throw new Exception('فشل رفع ملف إثبات الدفع.');
                }
            }

            $stmt = $db->prepare("
                INSERT INTO order_approvals (
                    customer_id, customer_name, subtotal_amount, notes, 
                    coupon_code, coupon_discount_amount, paid_amount, payment_proof_path, currency, status,
                    automatic_discount_percentage, automatic_discount_amount, total_after_discounts
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
            ");
            $stmt->execute([
                $customer_id,
                $customer['name'],
                $subtotal_amount,
                $notes,
                $coupon_code, 
                $coupon_discount_amount,
                $paid_amount,
                $payment_proof_path,
                $customer_currency,
                $calculated_automatic_discount_percentage, 
                $calculated_automatic_discount_amount, 
                $total_after_discounts 
            ]);
            $approval_id = $db->lastInsertId();

            $item_stmt = $db->prepare("
                INSERT INTO order_approval_items (approval_id, product_link, additional_link, item_count, total, notes) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $item) {
                $item_stmt->execute([
                    $approval_id,
                    trim($item['product_link'] ?? ''),
                    trim($item['additional_link'] ?? ''), 
                    intval($item['item_count'] ?? 0),
                    floatval($item['total'] ?? 0),
                    trim($item['notes'] ?? '') 
                ]);
            }

            if (isset($_FILES['order_approval_images']) && is_array($_FILES['order_approval_images']['name'])) {
                $upload_dir_approval_images = '../uploads/order_approval_images/';
                if (!is_dir($upload_dir_approval_images)) {
                    mkdir($upload_dir_approval_images, 0755, true);
                }
                
                $image_insert_stmt = $db->prepare("
                    INSERT INTO order_approvals_images (approval_id, image_path, image_name, image_type, image_size, display_order) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($_FILES['order_approval_images']['name'] as $key => $filename) {
                    if ($_FILES['order_approval_images']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_tmp = $_FILES['order_approval_images']['tmp_name'][$key];
                        $file_type = $_FILES['order_approval_images']['type'][$key];
                        $file_size = $_FILES['order_approval_images']['size'][$key];

                        $file_ext = pathinfo($filename, PATHINFO_EXTENSION);
                        $new_filename = 'approval_' . $approval_id . '_' . $customer_id . '_' . time() . '_' . $key . '.' . $file_ext;
                        
                        if (move_uploaded_file($file_tmp, $upload_dir_approval_images . $new_filename)) {
                            $image_insert_stmt->execute([
                                $approval_id, 
                                'uploads/order_approval_images/' . $new_filename, 
                                $filename, 
                                $file_type, 
                                $file_size, 
                                $key
                            ]);
                        }
                    }
                }
            }
            
            $notification_message = "تم استلام طلب جديد للموافقة من العميل: " . htmlspecialchars($customer['name']);
            if ($allow_no_deposit) $notification_message .= " (طلب بدون دفع مسبق)";

            $notif_stmt = $db->prepare(
                "INSERT INTO notifications (customer_id, related_id, related_table, message) VALUES (?, ?, 'order_approvals', ?)"
            );
            $notif_stmt->execute([$customer_id, $approval_id, $notification_message]);

            $db->commit();
            
            // ========================================================
            // جلب البيانات الإضافية للقالب الذكي للواتساب
            // ========================================================
            $total_items_count = 0;
            $all_product_links = [];
            $all_additional_links =[];

            foreach ($items as $item) {
                $total_items_count += intval($item['item_count'] ?? 0);
                if (!empty(trim($item['product_link'] ?? ''))) {
                    $all_product_links[] = trim($item['product_link']);
                }
                if (!empty(trim($item['additional_link'] ?? ''))) {
                    $all_additional_links[] = trim($item['additional_link']);
                }
            }

            $product_links_str = implode("\n", $all_product_links);
            $additional_links_str = implode("\n", $all_additional_links);

            $total_discount_amount = $subtotal_amount - $final_amount;
            $total_discount_percentage = ($subtotal_amount > 0) ? round(($total_discount_amount / $subtotal_amount) * 100, 2) : 0;
            $remaining_amount_for_template = max(0, $final_amount - $paid_amount);

            $whatsapp_template_stmt = $db->prepare("SELECT message_content FROM whatsapp_template WHERE target_event = 'order_created_admin' AND is_active = 1 LIMIT 1");
            $whatsapp_template_stmt->execute();
            $template_row = $whatsapp_template_stmt->fetch(PDO::FETCH_ASSOC);

            // القالب الافتراضي إذا لم يتم وضع قالب في لوحة التحكم
            if ($template_row && !empty(trim($template_row['message_content']))) {
                $whatsapp_message_content = $template_row['message_content'];
            } else {
                $whatsapp_message_content = "*🔔 تنبيه | طلب جديد بانتظار المراجعة*\n\n" .
                                         "الرجاء مراجعة و تأكيد الطلب التالي:\n\n" .
                                         "○ تــــــاريخ الطـــــــلب: {order_date}\n" .
                                         "○ عـــــــــــدد القطـــــع: {order_quantity}\n\n" .
                                         "○ الإجمالي قبل الخصم: {gross_total}\n" .
                                         "○ الإجمالي بعد ا لخصم: {order_total}\n" .
                                         "○ الخصــ({discount_percentage}%)ـــــــم: {discount_amount}\n" .
                                         "○ المبلــــــغ المدفـــــوع: {payment_amount}\n" .
                                         "○ المبلــــــغ المتبـقـــــي: {remaining_amount}\n\n" .
                                         "◾️ رابط الكـــل ◾️\n{order_link}\n\n" .
                                         "◾️ المكــــــرر ◾️\n{additional_link}";
            }

            // قراءة المتغيرات القديمة والجديدة التي طلبها العميل في سؤاله
            $search_tags =[
                '{{customer-name}}', '{customer_name}',
                '{{approval-id}}', '{approval_id}',
                '{{total-amount}}', '{order_total}',
                '{{paid-amount}}', '{payment_amount}',
                '{{remaining-amount}}', '{remaining_amount}',
                '{{currency}}',
                '{order_date}',
                '{order_quantity}',
                '{gross_total}',
                '{discount_percentage}',
                '{discount_amount}',
                '{order_link}',
                '{additional_link}'
            ];

            $replace_values =[
                $customer_name, $customer_name,
                $approval_id, $approval_id,
                number_format($final_amount, 0), number_format($final_amount, 0),
                number_format($paid_amount, 0), number_format($paid_amount, 0),
                number_format($remaining_amount_for_template, 0), number_format($remaining_amount_for_template, 0),
                $customer_currency,
                date('Y-m-d'),
                $total_items_count,
                number_format($subtotal_amount, 0),
                $total_discount_percentage,
                number_format($total_discount_amount, 0),
                empty($product_links_str) ? 'لا يوجد' : $product_links_str,
                empty($additional_links_str) ? 'لا يوجد' : $additional_links_str
            ];

            $whatsapp_message_final = str_replace($search_tags, $replace_values, $whatsapp_message_content);

            $_SESSION['order_success_data'] =[
                'success' => true,
                'approval_id' => $approval_id,
                'token' => $token, 
                'whatsapp_message' => $whatsapp_message_final 
            ];
            header('Location: ' . $_SERVER['PHP_SELF'] . '?token=' . $token);
            exit();

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error_message = 'حدث خطأ: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Cairo', sans-serif; }
        .coupon-input-success { border-color: #10B981; }
        .coupon-input-error { border-color: #EF4444; }
    </style>
</head>
<body class="bg-gray-100">

    <div class="max-w-5xl mx-auto py-10 px-4">
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="p-6 sm:p-8 text-white bg-[linear-gradient(to_left,#C7A46D,#B8956A)]">
                <h1 class="text-2xl sm:text-3xl font-bold flex items-center">
                    <i class="fas fa-paper-plane mr-3"></i> <?php echo $page_title; ?>
                </h1>
                <p class="mt-2 opacity-90">مرحباً, <?php echo htmlspecialchars($customer_name); ?>! يرجى ملء النموذج أدناه.</p>
            </div>

            <?php if ($error_message): ?>
                <div class="m-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">
                    <p class="font-bold">خطأ!</p>
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="p-6 sm:p-8 space-y-8">
                <!-- Hidden inputs -->
                <input type="hidden" name="automatic_discount_amount" id="automaticDiscountAmountHidden" value="0">
                <input type="hidden" name="automatic_discount_percentage" id="automaticDiscountPercentageHidden" value="0">
                <input type="hidden" name="coupon_discount_amount" id="couponDiscountAmountHidden" value="0">
                
                <!-- Order Items Section -->
                <div class="p-6 border border-gray-200 rounded-lg">
                    <h3 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-shopping-basket mr-2 text-blue-500"></i>تفاصيل الطلب</h3>
                    <div id="itemsContainer">
                        <div class="space-y-4 item-row">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">وصف المنتج / الرابط *</label>
                                <input type="text" name="items[0][product_link]" class="w-full p-2 border border-gray-300 rounded-md" placeholder="مثال: رابط السلة أو اسم المنتج" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">رابط إضافي للمنتج (اختياري)</label>
                                <input type="text" name="items[0][additional_link]" class="w-full p-2 border border-gray-300 rounded-md" placeholder="رابط آخر للمنتج">
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">إجمالي القطع *</label>
                                    <input type="number" name="items[0][item_count]" value="0" min="1" class="w-full p-2 border border-gray-300 rounded-md item-quantity" oninput="updateTotals()" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">الإجمالي <?php echo htmlspecialchars($customer_currency); ?> *</label>
                                    <input type="number" name="items[0][total]" value="0" min="0" step="1" class="w-full p-2 border border-gray-300 rounded-md item-total-input" oninput="updateTotals()" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- تم حذف زر "إضافة منتج آخر" هنا -->

                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <label for="order_approval_images" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-images mr-2 text-indigo-500"></i>صور توضيحية للطلب (اختياري)</label>
                        <p class="text-xs text-gray-500 mb-2">يمكنك رفع عدة صور للمنتجات أو الطلب.</p>
                        <input type="file" id="order_approval_images" name="order_approval_images[]" multiple class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100" onchange="previewImages(this)">
                        <div id="imagePreviewContainer" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 mt-4 hidden"></div>
                    </div>
                </div>

                <!-- Financial & Notes Section -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    
                    <div class="space-y-6">
                        <div class="p-6 border border-gray-200 rounded-lg space-y-5 bg-white">
                            <h3 class="text-lg font-bold text-gray-800 mb-2 border-b pb-3"><i class="fas fa-edit mr-2 text-blue-500"></i>بيانات الدفع والملاحظات</h3>
                            
                            <div>
                                <label for="coupon_code" class="block text-sm font-medium text-gray-700 mb-1">كود الكوبون (اختياري)</label>
                                <div class="flex items-center space-x-2 rtl:space-x-reverse">
                                    <input type="text" id="coupon_code" name="coupon_code" class="flex-grow p-2 border border-gray-300 rounded-md uppercase" placeholder="أدخل الكود إن وجد">
                                    <button type="button" onclick="applyCoupon()" class="px-4 py-2 bg-indigo-500 text-white font-semibold rounded-md hover:bg-indigo-600 transition">تطبيق</button>
                                </div>
                                <p id="couponMessage" class="mt-1 text-sm text-gray-600"></p>
                            </div>

                            <?php if (!$allow_no_deposit): ?>
                                <div>
                                    <label for="paid_amount" class="block text-sm font-medium text-gray-700 mb-1">المبلغ المدفوع *</label>
                                    <input type="number" id="paid_amount" name="paid_amount" min="0" step="1" class="w-full p-2 border border-gray-300 rounded-md" required oninput="updateTotals()">
                                    <p class="text-xs text-gray-500 mt-1">يجب أن يكون ربع الإجمالي على الأقل: <strong id="requiredPayment">0</strong> <?php echo htmlspecialchars($customer_currency); ?></p>
                                    <p class="text-xs text-red-500 mt-1">ملاحظة: مبلغ الريال السعودي يتم ضربه بـ 140.</p>
                                </div>

                                <div>
                                    <label for="payment_proof" class="block text-sm font-medium text-gray-700 mb-1">إرفاق إثبات الدفع *</label>
                                    <input type="file" id="payment_proof" name="payment_proof" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" required onchange="previewPaymentProof(this)">
                                    <div id="paymentProofPreview" class="mt-4 hidden"></div>
                                </div>
                            <?php else: ?>
                                <div class="p-4 bg-green-50 text-green-700 border border-green-200 rounded-lg">
                                    <i class="fas fa-check-circle mr-2"></i> مسموح لك برفع الطلب بدون دفع مسبق (عربون).
                                </div>
                            <?php endif; ?>

                            <div>
                                <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">ملاحظات إضافية</label>
                                <textarea id="notes" name="notes" rows="3" class="w-full p-2 border border-gray-300 rounded-md" placeholder="أضف أي ملاحظات تخص الطلب هنا..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="p-6 bg-white border border-gray-200 rounded-2xl shadow-sm">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-bold text-[#C7A46D]">الملخص المالي</h3>
                                <div class="w-10 h-10 rounded-full bg-[#fdfaf5] flex items-center justify-center text-[#C7A46D]">
                                    <i class="fas fa-file-invoice-dollar text-lg"></i>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div class="flex justify-between items-center text-gray-600 font-semibold text-sm">
                                    <span>المجموع الفرعي</span>
                                    <span class="whitespace-nowrap" dir="ltr"><span id="subtotalDisplay">0</span> <?php echo htmlspecialchars($customer_currency); ?></span>
                                </div>

                                <div class="flex justify-between items-center text-[#e89945] font-semibold text-sm bg-orange-50 p-2 rounded hidden" id="automaticDiscountRow">
                                    <span>خصم تلقائي (<span id="discountPercentDisplay">0</span>%)</span>
                                    <span class="whitespace-nowrap" dir="ltr">-<span id="automaticDiscountDisplay">0</span> <?php echo htmlspecialchars($customer_currency); ?></span>
                                </div>

                                <div class="flex justify-between items-center text-purple-600 font-semibold text-sm bg-purple-50 p-2 rounded hidden" id="couponDiscountRow">
                                    <span>خصم الكوبون <span id="couponDiscountDisplayPercent"></span></span>
                                    <span class="whitespace-nowrap" dir="ltr">-<span id="couponDiscountDisplayAmount">0</span> <?php echo htmlspecialchars($customer_currency); ?></span>
                                </div>

                                <div class="border-t-2 border-dashed border-gray-200 my-4"></div>

                                <div class="bg-[#F8F9FA] rounded-xl p-5 border border-gray-100 space-y-4">
                                    <div class="flex justify-between items-center font-bold text-gray-800 text-lg">
                                        <span>الإجمالي النهائي</span>
                                        <span class="text-[#C7A46D] whitespace-nowrap" dir="ltr"><span id="finalTotal">0</span> <?php echo htmlspecialchars($customer_currency); ?></span>
                                    </div>
                                    
                                    <div class="border-t border-gray-200 w-full my-2"></div>

                                    <div class="flex justify-between items-center font-bold text-[#10B981] text-sm">
                                        <span>المدفوع</span>
                                        <span class="whitespace-nowrap" dir="ltr"><span id="paidDisplay">0</span> <?php echo htmlspecialchars($customer_currency); ?></span>
                                    </div>
                                    
                                    <div class="flex justify-between items-center font-bold text-[#EF4444] text-sm">
                                        <span>المتبقي</span>
                                        <span class="whitespace-nowrap" dir="ltr"><span id="remainingDisplay">0</span> <?php echo htmlspecialchars($customer_currency); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Submission Buttons -->
                <div class="pt-6 border-t border-gray-200 flex items-center justify-end gap-4">
                    <a href="portal.php?token=<?php echo htmlspecialchars($token); ?>" class="px-6 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 font-semibold transition">
                        إلغاء
                    </a>
                    <button type="submit" class="px-8 py-2 text-white rounded-lg hover:opacity-90 font-semibold transition bg-[linear-gradient(to_left,#C7A46D,#B8956A)]">
                        <i class="fas fa-paper-plane mr-2"></i> إرسال للموافقة
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- WhatsApp Success Modal - Reverted to original design -->
    <div id="whatsappSuccessModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-lg shadow-xl p-8 max-w-sm w-full text-center">
            <button onclick="closeWhatsappModal()" class="absolute top-4 left-4 text-gray-400 hover:text-gray-600 focus:outline-none">
                <i class="fas fa-times text-xl"></i>
            </button>
            <div class="text-green-500 text-5xl mb-4">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3 class="text-2xl font-bold text-gray-800 mb-2">تم إرسال الطلب بنجاح!</h3>
            <p class="text-gray-600 mb-6">يمكنك التواصل مع المسؤول للمتابعة.</p>

            <h4 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fab fa-whatsapp text-green-500 mr-2"></i> إرسال طلبك لإدارة شركة يمان
            </h4>
            
            <a id="whatsappLink" href="#" target="_blank" class="inline-flex items-center justify-center bg-green-500 text-white font-bold py-3 px-6 rounded-full text-lg hover:bg-green-600 transition duration-300">
                <i class="fab fa-whatsapp text-2xl ml-3"></i> مراسلة عبر واتساب
            </a>
           
        </div>
    </div>

<script>
    const customerTypeIdForDiscount = <?php echo json_encode($customer_type_id_for_discount); ?>;
    const customerTypeName = <?php echo json_encode($customer_type_name); ?>;
    const customerCurrency = <?php echo json_encode($customer_currency); ?>;
    const customerId = <?php echo json_encode($customer_id); ?>;
    const allowNoDeposit = <?php echo json_encode((bool)$allow_no_deposit); ?>;
    
    // Removed itemCounter and related functions as "Add another product" button is removed.
    let currentCouponDiscountAmount = 0;
    let currentCouponDisplayPercentage = '';

    const whatsappNumber = <?php echo json_encode($whatsapp_number); ?>;
    const orderSuccessData = <?php echo json_encode($order_success_data); ?>;

    // The addItemRow and removeItemRow functions are no longer needed
    // function addItemRow() { ... }
    // function removeItemRow(button) { ... }

    async function applyCoupon() {
        const couponCodeInput = document.getElementById('coupon_code');
        const couponCode = couponCodeInput.value.trim();
        const couponMessageElement = document.getElementById('couponMessage');
        const subtotal = parseFloat(document.getElementById('subtotalDisplay').textContent) || 0;

        currentCouponDiscountAmount = 0; 
        currentCouponDisplayPercentage = ''; 

        couponCodeInput.classList.remove('coupon-input-success', 'coupon-input-error');
        couponMessageElement.classList.remove('text-green-600', 'text-red-600');
        couponMessageElement.classList.add('text-gray-600');

        if (couponCode === '') {
            couponMessageElement.textContent = 'الرجاء إدخال كود الكوبون.';
            couponCodeInput.classList.add('coupon-input-error');
            couponMessageElement.classList.add('text-red-600');
            updateTotals(); 
            return;
        }

        couponMessageElement.textContent = 'جاري التحقق...';

        try {
            const response = await fetch(`check_coupon.php?coupon_code=${encodeURIComponent(couponCode)}&total_amount=${subtotal}&customer_id=${customerId}&currency=${encodeURIComponent(customerCurrency)}`);
            const data = await response.json();

            if (data.success && data.coupon_discount_amount > 0) {
                couponMessageElement.textContent = data.message;
                couponMessageElement.classList.add('text-green-600');
                couponCodeInput.classList.add('coupon-input-success');
                
                currentCouponDiscountAmount = data.coupon_discount_amount;
                currentCouponDisplayPercentage = data.coupon_discount_percentage_display || '';
            } else {
                couponMessageElement.textContent = data.message;
                couponMessageElement.classList.add('text-red-600');
                couponCodeInput.classList.add('coupon-input-error');
            }
        } catch (error) {
            console.error(error);
            couponMessageElement.textContent = 'حدث خطأ بالاتصال.';
            couponMessageElement.classList.add('text-red-600');
        }
        updateTotals();
    }

    async function updateTotals() {
        let subtotal = 0;
        document.querySelectorAll('.item-total-input').forEach(input => {
            subtotal += parseFloat(input.value) || 0;
        });

        let automaticDiscountPercentage = 0;
        let automaticDiscountAmount = 0;

        if (customerTypeIdForDiscount && subtotal > 0) {
            try {
                const response = await fetch(`../modules/orders/get_customer_discount.php?customer_type_id=${customerTypeIdForDiscount}&amount=${subtotal}`);
                const data = await response.json();

                if (data.success && data.discount_percentage > 0) {
                    automaticDiscountPercentage = data.discount_percentage;
                    automaticDiscountAmount = subtotal * (automaticDiscountPercentage / 100);
                    document.getElementById('automaticDiscountRow').classList.remove('hidden');
                } else {
                    document.getElementById('automaticDiscountRow').classList.add('hidden');
                }
            } catch (error) {
                console.error("Error fetching customer discount:", error);
                document.getElementById('automaticDiscountRow').classList.add('hidden');
            }
        } else {
            document.getElementById('automaticDiscountRow').classList.add('hidden');
        }

        let totalAfterDiscounts = subtotal - automaticDiscountAmount - currentCouponDiscountAmount;
        if (totalAfterDiscounts < 0) totalAfterDiscounts = 0; 
        
        const finalAmount = totalAfterDiscounts;
        
        let paidVal = 0;
        let requiredPayment = 0;

        if (!allowNoDeposit) {
            const paidAmountInput = document.getElementById('paid_amount');
            if (paidAmountInput) {
                paidVal = parseFloat(paidAmountInput.value) || 0;
                requiredPayment = finalAmount / 4;
                
                if (paidVal < requiredPayment && paidVal > 0) {
                    paidAmountInput.classList.remove('border-gray-300', 'border-green-500');
                    paidAmountInput.classList.add('border-red-500'); 
                } else if (paidVal >= requiredPayment && paidVal > 0) {
                    paidAmountInput.classList.remove('border-gray-300', 'border-red-500');
                    paidAmountInput.classList.add('border-green-500'); 
                } else {
                     paidAmountInput.classList.remove('border-red-500', 'border-green-500');
                     paidAmountInput.classList.add('border-gray-300'); 
                }
                
                const reqEl = document.getElementById('requiredPayment');
                if(reqEl) reqEl.textContent = requiredPayment.toFixed(0);
            }
        }

        const remainingVal = finalAmount - paidVal;

        document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(0);
        document.getElementById('automaticDiscountDisplay').textContent = automaticDiscountAmount.toFixed(0);
        document.getElementById('discountPercentDisplay').textContent = automaticDiscountPercentage.toFixed(0);
        document.getElementById('finalTotal').textContent = finalAmount.toFixed(0);
        document.getElementById('paidDisplay').textContent = paidVal.toFixed(0);
        document.getElementById('remainingDisplay').textContent = (remainingVal < 0 ? 0 : remainingVal).toFixed(0);
        
        const couponRow = document.getElementById('couponDiscountRow');
        if (currentCouponDiscountAmount > 0) {
            couponRow.classList.remove('hidden');
            document.getElementById('couponDiscountDisplayAmount').textContent = currentCouponDiscountAmount.toFixed(0);
            document.getElementById('couponDiscountDisplayPercent').textContent = currentCouponDisplayPercentage ? `(${currentCouponDisplayPercentage})` : '';
        } else {
            couponRow.classList.add('hidden');
        }
        
        document.getElementById('automaticDiscountAmountHidden').value = automaticDiscountAmount.toFixed(2);
        document.getElementById('automaticDiscountPercentageHidden').value = automaticDiscountPercentage.toFixed(2);
        document.getElementById('couponDiscountAmountHidden').value = currentCouponDiscountAmount.toFixed(2);
    }

    document.addEventListener('DOMContentLoaded', () => {
        // No longer need to count existing items for itemCounter
        // const existingItems = document.querySelectorAll('.item-row');
        // if (existingItems.length > 0) {
        //     itemCounter = existingItems.length - 1; 
        // }

        document.querySelectorAll('.item-quantity, .item-total-input').forEach(input => {
            input.addEventListener('input', updateTotals);
        });
        
        const paidAmountInput = document.getElementById('paid_amount');
        if (paidAmountInput) {
            paidAmountInput.addEventListener('input', updateTotals);
        }
        
        updateTotals(); 

        if (orderSuccessData && orderSuccessData.success) {
            const modal = document.getElementById('whatsappSuccessModal');
            const whatsappLink = document.getElementById('whatsappLink');
            const whatsappMessage = orderSuccessData.whatsapp_message;
            const encodedMessage = encodeURIComponent(whatsappMessage);
            const whatsappUrl = `https://api.whatsapp.com/send/?phone=${whatsappNumber}&text=${encodedMessage}`;
            
            whatsappLink.href = whatsappUrl;
            modal.classList.remove('hidden'); 
            
            // Redirect after clicking WhatsApp button (after a short delay)
            whatsappLink.addEventListener('click', function() {
                setTimeout(function() {
                    window.location.href = 'portal.php?token=' + orderSuccessData.token;
                }, 2000);
            });
        }
    });

    function previewImages(input) {
        const container = document.getElementById('imagePreviewContainer');
        container.innerHTML = ''; 
        if (input.files && input.files.length > 0) {
            container.classList.remove('hidden');
            for (const file of input.files) {
                const reader = new FileReader();
                reader.onload = e => {
                    let previewHtml;
                    if (file.type.startsWith('image/')) {
                        previewHtml = `<div class="relative group"><img src="${e.target.result}" class="w-full h-24 sm:h-32 object-cover rounded-lg border"></div>`;
                    } else {
                        previewHtml = `<div class="relative group w-full h-24 sm:h-32 flex flex-col items-center justify-center bg-gray-100 border rounded-lg p-2">
                            <i class="fas fa-file-alt text-4xl text-gray-400"></i>
                            <span class="text-xs text-center text-gray-600 mt-2 break-all">${file.name}</span>
                        </div>`;
                    }
                    container.innerHTML += previewHtml;
                };
                reader.readAsDataURL(file);
            }
        } else {
            container.classList.add('hidden');
        }
    }

    function previewPaymentProof(input) {
        const container = document.getElementById('paymentProofPreview');
        container.innerHTML = '';
        if (input.files && input.files[0]) {
            container.classList.remove('hidden');
            const file = input.files[0];
            const reader = new FileReader();

            reader.onload = e => {
                let previewHtml;
                if (file.type.startsWith('image/')) {
                    previewHtml = `<img src="${e.target.result}" class="max-w-xs h-32 object-contain rounded-lg border">`;
                } else {
                    previewHtml = `<div class="w-full max-w-xs h-32 flex flex-col items-center justify-center bg-gray-100 border rounded-lg p-2">
                        <i class="fas fa-file-alt text-4xl text-gray-400"></i>
                        <span class="text-xs text-center text-gray-600 mt-2 break-all">${file.name}</span>
                    </div>`;
                }
                container.innerHTML = previewHtml;
            };
            reader.readAsDataURL(file);
        } else {
            container.classList.add('hidden');
        }
    }

    // Function to close WhatsApp success modal
    function closeWhatsappModal() {
        document.getElementById('whatsappSuccessModal').classList.add('hidden');
    }
</script>
</body>
</html>