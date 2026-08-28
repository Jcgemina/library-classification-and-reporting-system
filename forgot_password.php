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
<body class="flex min-h-screen items-center justify-center bg-slate-200 p-6">
  <main class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-2xl">
    <h1 class="text-2xl font-bold text-slate-900">Reset your password</h1>
    <p class="mt-2 text-sm text-slate-500">Enter your account email and we will send a secure reset link.</p>
    <?php if ($error): ?><div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($message): ?><div class="mt-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="mt-6 space-y-4">
      <label class="block text-sm font-semibold text-slate-700">Email address<input type="email" name="email" required autocomplete="email" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-3 outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"></label>
      <button class="w-full rounded-lg bg-rose-600 px-4 py-3 text-sm font-bold text-white hover:bg-rose-700">Send reset link</button>
    </form>
    <a href="login.php" class="mt-5 block text-center text-sm font-semibold text-rose-600 hover:underline">Back to login</a>
  </main>
</body>
</html>
