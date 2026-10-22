<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if ($pdo !== null && !empty($_SESSION['user_id'])) {
	recordSecurityLog($pdo, (int)$_SESSION['user_id'], $_SESSION['username'] ?? null, 'logout', 'info', 'User logged out.', getClientIp());
	recordActivityLog($pdo, (int)$_SESSION['user_id'], 'logout', 'User signed out of the library system.');
}

$_SESSION = [];
$useSessionCookies = ini_get('session.use_cookies');
if ($useSessionCookies !== false && $useSessionCookies !== '' && $useSessionCookies !== '0') {
	$params = session_get_cookie_params();
	$sessionName = session_name();
	if ($sessionName === false) {
		throw new RuntimeException('Unable to determine the session cookie name.');
	}
	$pathParam = $params['path'] ?? null;
	$domainParam = $params['domain'] ?? null;
	$sameSiteParam = $params['samesite'] ?? null;
	$cookiePath = is_string($pathParam) ? $pathParam : '/';
	$cookieDomain = is_string($domainParam) ? $domainParam : '';
	$sameSite = is_string($sameSiteParam) ? $sameSiteParam : 'Lax';
	$cookieParts = [
		rawurlencode($sessionName) . '=',
		'Expires=' . gmdate('D, d M Y H:i:s', time() - 3600) . ' GMT',
		'Max-Age=0',
		'Path=' . $cookiePath,
		'HttpOnly',
		'SameSite=' . $sameSite,
	];
	if ($cookieDomain !== '') {
		$cookieParts[] = 'Domain=' . $cookieDomain;
	}
	if ($params['secure']) {
		$cookieParts[] = 'Secure';
	}
	header('Set-Cookie: ' . implode('; ', $cookieParts), false);
}
session_destroy();
header('Location: login.php');
exit;