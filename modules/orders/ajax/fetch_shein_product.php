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
    $link = trim($_POST['link'] ?? $_GET['link'] ?? '');
    if ($link === '') {
        throw new InvalidArgumentException('يرجى إدخال رابط منتج SHEIN');
    }

    $product = sheinExtractProductData($link);
    sheinFindOrCreateProduct($db, $product);

    echo json_encode([
        'success' => true,
        'product' => $product,
        'message' => 'تم جلب بيانات المنتج بنجاح',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
