<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// If the DB connection failed in config/db.php, show the error to the user instead of crashing
if (!$pdo) {
    setFlash($dbConnectionError);
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

// --- Non-functional: Login rate limiting (progressive lockout) ---
$lockSeconds = isRateLimited($pdo, $username, $ip);
if ($lockSeconds > 0) {
    $formattedTime = formatLockoutDuration($lockSeconds);
    setFlash("Too many failed attempts. Please try again in {$formattedTime}.", true);
    header('Location: login.php');
    exit;
}

// --- Functional: Credential validation ---
$stmt = $pdo->prepare(
    "SELECT id, username, password, full_name, role, is_active
     FROM users WHERE username = :username LIMIT 1"
);
$stmt->execute([':username' => $username]);
$user = $stmt->fetch();

// --- Non-functional: Secure password handling (password_verify against bcrypt hash) ---
if (!$user || !password_verify($password, $user['password']) || (int)$user['is_active'] !== 1) {
    recordAttempt($pdo, $username, $ip, false);

    // Functional: generic feedback for invalid credentials (don't reveal which field was wrong)
    setFlash('Invalid username or password.');
    header('Location: login.php');
    exit;
}

// Success: reset failed attempts, log the successful attempt
recordAttempt($pdo, $username, $ip, true);
clearAttempts($pdo, $username, $ip);

// Prevent session fixation
session_regenerate_id(true);

// --- Functional: Connect successful login to the system interface ---
$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];

if (!empty($_POST['remember'])) {
    // Optional "remember this device" - simple long-lived cookie holding a random token
    // (for production, pair this with a separate remember_tokens table)
    setcookie('remember_device', bin2hex(random_bytes(16)), time() + (30 * 24 * 60 * 60), '/', '', false, true);
}

// --- Functional: Provide access to the main system after successful authentication ---
header('Location: dashboard.php');
exit;
