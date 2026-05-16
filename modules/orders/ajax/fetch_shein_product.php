<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/shein_helpers.php';

try {
    sheinEnsureSchema($db);
    $sku = trim($_POST['sku'] ?? $_GET['sku'] ?? '');
    if ($sku === '') {
        throw new InvalidArgumentException('يرجى إدخال SKU منتج SHEIN');
    }

    $product = sheinExtractProductDataBySku($sku);
    sheinFindOrCreateProduct($db, $product);

    echo json_encode([
        'success' => true,
        'product' => [
            'sku' => $product['sku'],
            'name' => $product['name'],
            'image' => $product['image'],
            'link' => $product['link'],
        ],
        'message' => 'تم جلب بيانات المنتج بنجاح',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
