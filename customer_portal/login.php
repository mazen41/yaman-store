<?php
session_start();
require_once '../config/database.php';

$error_message = '';
$success_message = '';

// Handle mobile phone login (passwordless)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $mobile = trim($_POST['mobile']);
    
    // Remove all non-digit characters
    $mobile = preg_replace('/\D/', '', $mobile);
    
    if (empty($mobile)) {
        $error_message = 'يرجى إدخال رقم الجوال';
    } elseif (strlen($mobile) < 8) {
        $error_message = 'رقم الجوال قصير جداً (يجب أن يكون 9-12 رقم)';
    } else {
        // Check if customer exists by mobile number
        try {
            // Try multiple phone fields with LIKE for flexible matching
            $stmt = $db->prepare("
                SELECT id, name, customer_code, mobile_number 
                FROM customers 
                WHERE mobile_number LIKE CONCAT('%', ?, '%')
                   OR whatsapp_number LIKE CONCAT('%', ?, '%')
                   OR alternative_number LIKE CONCAT('%', ?, '%')
                   OR phone LIKE CONCAT('%', ?, '%')
                LIMIT 1
            ");
            $stmt->execute([$mobile, $mobile, $mobile, $mobile]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($customer) {
                // Direct login - set session
                $_SESSION['customer_id'] = $customer['id'];
                $_SESSION['customer_name'] = $customer['name'];
                $_SESSION['customer_code'] = $customer['customer_code'];
                $_SESSION['customer_mobile'] = $customer['mobile_number'];
                
                // Redirect to dashboard
                header('Location: dashboard.php');
                exit();
            } else {
                $error_message = 'رقم الجوال غير مسجل في النظام. يرجى التواصل مع الإدارة لإضافة رقمك.';
            }
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ في الاتصال بقاعدة البيانات: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - بوابة العملاء</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        #mobile:focus {
            transform: scale(1.02);
        }
    </style>
</head>
<body class="flex items-center justify-center p-4">
    <div class="max-w-md w-full">
        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <div class="text-center mb-8">
                <div class="inline-block p-4 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full mb-4">
                    <i class="fas fa-mobile-alt text-white text-5xl"></i>
                </div>
                <h1 class="text-3xl font-bold text-gray-800">بوابة العملاء</h1>
                <p class="text-gray-600 mt-2">تسجيل الدخول برقم الجوال فقط</p>
            </div>

            <?php if ($success_message): ?>
            <div class="bg-amber-100 border-r-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-check-circle ml-2"></i>
                    <span><?php echo $success_message; ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle ml-2"></i>
                    <span><?php echo $error_message; ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Mobile Login Form -->
            <form method="POST" class="space-y-6">
                <div>
                    <label for="mobile" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-mobile-alt ml-2"></i>رقم الجوال
                    </label>
                    <input 
                        type="tel" 
                        id="mobile" 
                        name="mobile" 
                        required 
                        class="w-full px-4 py-4 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition text-center text-xl tracking-wider"
                        placeholder="05xxxxxxxx"
                        maxlength="10"
                        dir="ltr"
                        autocomplete="tel"
                    >
                    <p class="text-sm text-gray-500 mt-2 text-center">
                        <i class="fas fa-info-circle ml-1"></i>
                        أدخل رقم جوالك المسجل في النظام
                    </p>
                </div>

                <button 
                    type="submit" 
                    name="login"
                    class="w-full bg-gradient-to-r from-blue-500 to-purple-600 text-white font-bold py-4 px-4 rounded-lg hover:from-blue-600 hover:to-purple-700 transition duration-200 flex items-center justify-center shadow-lg hover:shadow-xl transform hover:-translate-y-1"
                >
                    <i class="fas fa-sign-in-alt ml-2"></i>
                    تسجيل الدخول
                </button>
            </form>

            <div class="mt-8 pt-6 border-t border-gray-200 text-center">
                <p class="text-sm text-gray-600">
                    <i class="fas fa-shield-alt ml-1"></i>
                    تسجيل دخول آمن ومشفر
                </p>
                <p class="text-xs text-gray-500 mt-2">
                    لا حاجة لكلمة مرور - فقط رقم جوالك
                </p>
            </div>
        </div>
    </div>

    <script>
        // Auto-format mobile number
        const mobileInput = document.getElementById('mobile');
        
        mobileInput.addEventListener('input', function(e) {
            // Remove all non-digit characters
            let value = e.target.value.replace(/\D/g, '');
            
            // Limit to 10 digits
            if (value.length > 10) {
                value = value.slice(0, 10);
            }
            
            e.target.value = value;
        });
        
        // Focus on mobile input on page load
        window.addEventListener('DOMContentLoaded', function() {
            mobileInput.focus();
        });
        
        // Add enter key support
        mobileInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.target.form.submit();
            }
        });
    </script>
</body>
</html>
