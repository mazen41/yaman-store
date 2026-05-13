<?php
header('Content-Type: application/json');
echo json_encode(['status' => 'API is reachable', 'time' => date('Y-m-d H:i:s')]);
