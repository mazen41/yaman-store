<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['is_admin'] = 1;
$_SESSION['role'] = 'admin';
echo "session_id=" . session_id();
