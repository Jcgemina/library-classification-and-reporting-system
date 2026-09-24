<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$message = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($pdo) {
        $stmt = $pdo->prepare('SELECT id, full_name, username, email FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        if ($user) {
            $token = createPasswordResetToken($pdo, (int)$user['id']);
            $fullName = (string)$user['full_name'];
            $username = (string)$user['username'];
            queuePasswordSetupEmail($pdo, $email, $fullName, $username, $token, 'reset');
            if (filter_var(environmentValue('DIRECT_PASSWORD_RESET_EMAIL', 'false'), FILTER_VALIDATE_BOOLEAN)) {
                if (!sendPasswordSetupEmail($email, $fullName, $username, $token, 'reset')) {
                    error_log('Direct password reset email failed for queued recipient.');
                }
            }
        }
        $message = 'If an active account uses that email, a password reset link has been sent.';
    } else {
        $message = 'If an active account uses that email, a password reset link has been sent.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - AppSys Library</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-slate-200 p-6">
    <div class="w-full max-w-md">
        <div class="flex flex-col items-center mb-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-[0_18px_45px_rgba(15,23,40,0.22)] p-3 overflow-hidden">
                <img src="assets/images/library-system-logo.png" alt="AppSys Library logo" class="h-24 w-24 scale-[2.5] object-contain">
            </div>
            <h1 class="mt-4 text-2xl font-bold text-slate-900">AppSys Library</h1>
            <p class="text-sm text-slate-500">Librarian Management Portal</p>
        </div>

        <main class="bg-white rounded-2xl border border-slate-200 shadow-[0_22px_60px_rgba(15,23,42,0.50)] p-8">
            <h2 class="text-xl font-bold text-slate-900 text-center">Reset your password</h2>
            <p class="text-sm text-slate-500 text-center mt-1 mb-6">Enter your account email and we will send a secure reset link.</p>
            <?php if ($error): ?><div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($message): ?><div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <form method="post" class="space-y-4">
                <label class="block text-xs font-semibold tracking-wide text-slate-600">EMAIL ADDRESS<input type="email" name="email" required autocomplete="email" placeholder="Enter your account email" class="mt-1.5 w-full rounded-xl border-2 border-slate-300 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition-all duration-200 placeholder:text-slate-400 hover:border-slate-400 focus:border-slate-800 focus:ring-2 focus:ring-slate-200"></label>
                <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-300">Send reset link</button>
            </form>
            <a href="login.php" class="mt-5 block text-center text-sm font-semibold text-rose-600 hover:underline focus:outline-none focus:ring-2 focus:ring-rose-200">Back to login</a>
        </main>

        <div class="flex items-center justify-center gap-4 text-xs text-slate-400 mt-5">
            <span>Encrypted Connection</span><span aria-hidden="true">•</span><span>System Support</span>
        </div>
    </div>
</body>
</html>
