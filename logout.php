<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if ($pdo && !empty($_SESSION['user_id'])) {
	recordSecurityLog($pdo, (int)$_SESSION['user_id'], $_SESSION['username'] ?? null, 'logout', 'info', 'User logged out.', getClientIp());
	recordActivityLog($pdo, (int)$_SESSION['user_id'], 'logout', 'User signed out of the library system.');
}

$_SESSION = [];
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');
header('Location: login.php');
exit;