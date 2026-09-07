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
      <h2 class="text-xl font-bold text-slate-900 text-center">Set your password</h2>
      <p class="text-sm text-slate-500 text-center mt-1 mb-6">Choose a new password for your AppSys Library account.</p>
      <?php if ($error): ?><div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <?php if ($success): ?><div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <?php if ($userId): ?>
        <form method="post" class="space-y-4">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
          <label class="block text-xs font-semibold tracking-wide text-slate-600">NEW PASSWORD<input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Enter a new password" class="mt-1.5 w-full rounded-xl border-2 border-slate-300 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition-all duration-200 placeholder:text-slate-400 hover:border-slate-400 focus:border-slate-800 focus:ring-2 focus:ring-slate-200"></label>
          <label class="block text-xs font-semibold tracking-wide text-slate-600">CONFIRM PASSWORD<input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" placeholder="Re-enter your new password" class="mt-1.5 w-full rounded-xl border-2 border-slate-300 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition-all duration-200 placeholder:text-slate-400 hover:border-slate-400 focus:border-slate-800 focus:ring-2 focus:ring-slate-200"></label>
          <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-300">Update password</button>
        </form>
      <?php endif; ?>
      <?php if (!$userId && !$success): ?><a href="forgot_password.php" class="mt-5 block text-center text-sm font-semibold text-rose-600 hover:underline focus:outline-none focus:ring-2 focus:ring-rose-200">Request a new reset link</a><?php endif; ?>
      <a href="login.php" class="mt-3 block text-center text-sm font-semibold text-slate-500 hover:underline focus:outline-none focus:ring-2 focus:ring-slate-200">Back to login</a>
    </main>

    <div class="flex items-center justify-center gap-4 text-xs text-slate-400 mt-5">
      <span>Encrypted Connection</span><span aria-hidden="true">•</span><span>System Support</span>
    </div>
  </div>
</body>
</html>
