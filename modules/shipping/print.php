<?php
session_start();
if (!isset($_SESSION['user_id'])) die('Access Denied');
require_once '../../config/database.php';

$shipment_id = intval($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM shipments WHERE id = ?");
$stmt->execute([$shipment_id]);
$shipment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shipment) die('Shipment not found');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة الشحنة #<?php echo $shipment['shipment_number']; ?></title>
    <style>
        body { font-family: Tahoma, sans-serif; padding: 20px; }
        .label { border: 2px solid #000; padding: 20px; max-width: 600px; margin: 0 auto; }
        .header { text-align: center; border-bottom: 1px solid #ccc; padding-bottom: 10px; margin-bottom: 20px; }
        .row { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .barcode { text-align: center; margin-top: 20px; font-size: 24px; letter-spacing: 5px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <button class="no-print" onclick="window.print()">طباعة</button>
    <div class="label">
        <div class="header">
            <h1>بوليصة شحن</h1>
            <h3><?php echo $shipment['shipment_number']; ?></h3>
        </div>
        
        <div class="row">
            <div><strong>المستلم:</strong><br><?php echo htmlspecialchars($shipment['recipient_name']); ?><br><?php echo htmlspecialchars($shipment['recipient_phone']); ?></div>
            <div><strong>التاريخ:</strong><br><?php echo date('Y-m-d', strtotime($shipment['created_at'])); ?></div>
        </div>
        
        <div style="margin: 20px 0; border: 1px dashed #ccc; padding: 10px;">
            <strong>العنوان:</strong><br>
            <?php echo nl2br(htmlspecialchars($shipment['delivery_address'])); ?>
        </div>
        
        <div class="row">
            <div><strong>الحالة:</strong> <?php echo $shipment['status']; ?></div>
            <div><strong>التكلفة:</strong> <?php echo number_format($shipment['shipping_cost'], 2); ?></div>
        </div>
        
        <div class="barcode">
            ||| ||||| |||| |||||| ||
        </div>
    </div>
</body>
</html>
