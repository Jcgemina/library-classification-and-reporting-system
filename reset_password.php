<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = null;
$success = null;
$userId = null;

if ($pdo) $userId = getPasswordResetUserId($pdo, $token);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$userId) {
  $error = 'This reset link is missing, invalid, or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    if (!$pdo || !$userId) {
        $error = 'This reset link is invalid or has expired.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmation) {
        $error = 'Passwords do not match.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $stmt->execute([':password' => password_hash($password, PASSWORD_DEFAULT), ':id' => (int)$userId]);
            $stmt = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE token_hash = :token_hash');
            $stmt->execute([':token_hash' => hash('sha256', $token)]);
            $pdo->commit();
            $success = 'Your password has been updated. You can now sign in.';
            $userId = null;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Unable to update your password. Please request a new link.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set Password - AppSys Library</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-200 p-6">
  <main class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-2xl">
    <h1 class="text-2xl font-bold text-slate-900">Set your password</h1>
    <p class="mt-2 text-sm text-slate-500">Choose a new password for your AppSys Library account.</p>
    <?php if ($error): ?><div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mt-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($userId): ?>
      <form method="post" class="mt-6 space-y-4">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <label class="block text-sm font-semibold text-slate-700">New password<input type="password" name="password" required minlength="8" autocomplete="new-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-3 outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"></label>
        <label class="block text-sm font-semibold text-slate-700">Confirm password<input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-3 outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"></label>
        <button class="w-full rounded-lg bg-rose-600 px-4 py-3 text-sm font-bold text-white hover:bg-rose-700">Update password</button>
      </form>
    <?php endif; ?>
    <?php if (!$userId && !$success): ?><a href="forgot_password.php" class="mt-5 block text-center text-sm font-semibold text-rose-600 hover:underline">Request a new reset link</a><?php endif; ?>
    <a href="login.php" class="mt-3 block text-center text-sm font-semibold text-slate-500 hover:underline">Back to login</a>
  </main>
</body>
</html>
