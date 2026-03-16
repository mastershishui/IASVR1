<?php
require_once 'includes/config.php';
if (isLoggedIn()) {
    logActivity('logout','Auth','User logged out');
    session_destroy();
}
header('Location: index.php');
exit;
