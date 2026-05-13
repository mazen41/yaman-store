<?php
session_start();
session_destroy();
header('Location: /courier-login.php');
exit();
