<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// If the DB connection failed in config/db.php, show the error to the user instead of crashing
if (!$pdo) {
    setFlash($dbConnectionError ?? 'Database connection error.');
    header('Location: login.php');
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$ip       = getClientIp();

// Basic input validation
if ($username === '' || $password === '') {
    setFlash('Please enter both username and password.');
    header('Location: login.php');
    exit;
}

// Rate limiting check
$lockSeconds = isRateLimited($pdo, $username, $ip);
if ($lockSeconds > 0) {
    $formattedTime = formatLockoutDuration($lockSeconds);
    if (!hasRecentSecurityLog($pdo, $ip, 'login_blocked', BASE_LOCKOUT_MINUTES * 60)) {
        $previousBlocks = countSecurityLogs($pdo, $ip, 'login_blocked');
        $severity = $previousBlocks > 0 ? 'critical' : 'warning';
        recordSecurityLog($pdo, null, $username, 'login_blocked', $severity, 'Login blocked after repeated failed attempts from this IP.', $ip);
    }
    setFlash("Too many failed attempts. Please try again in {$formattedTime}.", true);
    header('Location: login.php');
    exit;
}

// Validate credentials
$stmt = $pdo->prepare(
    "SELECT id, username, password, full_name, role, is_active
     FROM users WHERE username = :username LIMIT 1"
);
$stmt->execute([':username' => $username]);
$user = $stmt->fetch();

// Validate password and user status
if (!$user || !password_verify($password, $user['password']) || (int)$user['is_active'] !== 1) {
    recordAttempt($pdo, $username, $ip, false);
    $failedCount = getFailedAttemptCount($pdo, $username, $ip);
    $message = 'Invalid username or password.';
    if ($failedCount >= 1 && $failedCount < MAX_ATTEMPTS) {
        $message .= " Attempt {$failedCount} of " . MAX_ATTEMPTS . '.';
    }
    
    setFlash($message, false, $failedCount);
    header('Location: login.php');
    exit;
}

// Success: reset failed attempts, log the successful attempt
recordAttempt($pdo, $username, $ip, true);
recordSecurityLog($pdo, (int)$user['id'], $username, 'login_success', 'info', 'Successful login.', $ip);
recordActivityLog($pdo, (int)$user['id'], 'login', 'User signed in to the library system.');
clearAttempts($pdo, $username, $ip);

// Prevent session fixation
session_regenerate_id(true);

// Login successful - set session variables
$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];
$_SESSION['authenticated'] = true;

if (!empty($_POST['remember'])) {
    // Remember this device
    setcookie('remember_device', bin2hex(random_bytes(16)), time() + (30 * 24 * 60 * 60), '/', '', false, true);
}

// --- Functional: Provide access to the main system after successful authentication ---
header('Location: app.php?page=dashboard');
exit;
