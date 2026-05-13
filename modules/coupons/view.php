<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit();
}

require_once "../../config/database.php";
require_once "../../includes/check_permissions.php";

// Permission check for edit button
$canEdit = hasPermission($_SESSION['user_id'], 'coupons', 'edit');

$coupon_id = intval($_GET["id"] ?? 0);
if (!$coupon_id) {
    header("Location: index.php");
    exit();
}

$stmt = $db->prepare("SELECT * FROM coupons WHERE id = ?");
$stmt->execute([$coupon_id]);
$coupon = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$coupon) {
    header("Location: index.php");
    exit();
}

include "../../includes/header.php";
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4">
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b">
                <div class="flex justify-between items-center">
                    <h1 class="text-2xl font-bold">تفاصيل الكوبون: <?php echo htmlspecialchars($coupon["coupon_code"]); ?></h1>
                    <div class="space-x-2 space-x-reverse">
                        <?php if ($canEdit): ?>
                        <a href="edit.php?id=<?php echo $coupon_id; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">تعديل</a>
                        <?php endif; ?>
                        <a href="index.php" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">العودة</a>
                    </div>
                </div>
            </div>
            
            <div class="p-6">
                <div class="bg-gray-50 p-4 rounded-lg space-y-3">
                    <div><strong>الكود:</strong> <span class="bg-purple-100 px-2 py-1 rounded font-mono"><?php echo htmlspecialchars($coupon["coupon_code"]); ?></span></div>
                    <div><strong>الاسم:</strong> <?php echo htmlspecialchars($coupon["coupon_name"] ?? "غير محدد"); ?></div>
                    <div><strong>الوصف:</strong> <?php echo htmlspecialchars($coupon["description"] ?? "لا يوجد وصف"); ?></div>
                    <div><strong>نوع الخصم:</strong> <?php echo $coupon["discount_type"] == "percentage" ? "نسبة مئوية" : "مبلغ ثابت"; ?></div>
                    <div><strong>قيمة الخصم:</strong> 
                        <?php if ($coupon["discount_type"] == "percentage"): ?>
                            <?php echo $coupon["discount_value"]; ?>%
                        <?php else: ?>
                            <?php echo number_format($coupon["discount_value"], 2); ?> ريال
                        <?php endif; ?>
                    </div>
                    <div><strong>الحد الأدنى:</strong> <?php echo number_format($coupon["min_order_amount"], 2); ?> ريال</div>
                    <div><strong>تاريخ البداية:</strong> <?php echo date("Y-m-d", strtotime($coupon["start_date"])); ?></div>
                    <div><strong>تاريخ النهاية:</strong> <?php echo date("Y-m-d", strtotime($coupon["end_date"])); ?></div>
                    <div><strong>الحالة:</strong> 
                        <span class="px-2 py-1 rounded text-xs <?php echo $coupon["is_active"] ? "bg-amber-100 text-amber-800" : "bg-red-100 text-red-800"; ?>">
                            <?php echo $coupon["is_active"] ? "نشط" : "معطل"; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include "../../includes/footer.php"; ?>