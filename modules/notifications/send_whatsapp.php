<?php
/**
 * WhatsApp Redirect - Old System to New System
 * Automatically redirects to the new WhatsApp system with all data pre-filled
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Get parameters from old system
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
$template_id = isset($_GET['template_id']) ? intval($_GET['template_id']) : 0;

// Build redirect URL to new WhatsApp system
$redirect_params = [];

if ($customer_id > 0) {
    $redirect_params[] = 'customer_id=' . $customer_id;
}

if ($order_id > 0) {
    $redirect_params[] = 'order_id=' . $order_id;
}

if ($invoice_id > 0) {
    $redirect_params[] = 'invoice_id=' . $invoice_id;
}

if ($template_id > 0) {
    $redirect_params[] = 'template_id=' . $template_id;
}

// Build final URL
$redirect_url = '../whatsapp/send.php';
if (!empty($redirect_params)) {
    $redirect_url .= '?' . implode('&', $redirect_params);
}

// Redirect to new system
header('Location: ' . $redirect_url);
exit();
?>
