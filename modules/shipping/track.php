<?php
require_once '../../config/database.php';
$shipment_id = intval($_GET['id'] ?? 0);
// Redirect to view if logged in admin, otherwise show public tracking (simplified for now)
header("Location: view.php?id=$shipment_id");
exit;
?>
