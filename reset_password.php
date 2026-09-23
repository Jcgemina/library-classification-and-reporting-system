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
    } elseif (($passwordError = validatePasswordStrength($password)) !== null) {
      $error = $passwordError;
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
<script src="https://unpkg.com/lucide@latest"></script>
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

        <div>
          <label class="block text-sm font-semibold text-slate-700">New password</label>
          <div class="relative mt-2">
            <input type="password" id="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" class="w-full rounded-xl border-2 border-slate-300 px-3 py-3 pr-11 outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100">
            <button id="passwordToggle" type="button" class="absolute inset-y-0 right-3 flex items-center text-slate-400 transition hover:text-slate-700" aria-label="Show password" aria-pressed="false" aria-controls="password" onclick="togglePasswordVisibility('password', 'passwordToggle', 'passwordToggleIcon')">
              <i data-lucide="eye" id="passwordToggleIcon" class="h-5 w-5"></i>
            </button>
          </div>
          <div id="passwordRequirements" class="mt-2 hidden rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600" aria-live="polite">
            <p class="mb-2 font-semibold text-slate-700">Password must include:</p>
            <ul class="space-y-1">
              <li data-requirement="length" class="flex items-center gap-2"><span class="inline-block h-2 w-2 rounded-full bg-slate-300"></span><span>8-128 characters</span></li>
              <li data-requirement="letter" class="flex items-center gap-2"><span class="inline-block h-2 w-2 rounded-full bg-slate-300"></span><span>At least one letter</span></li>
              <li data-requirement="number" class="flex items-center gap-2"><span class="inline-block h-2 w-2 rounded-full bg-slate-300"></span><span>At least one number</span></li>
              <li data-requirement="special" class="flex items-center gap-2"><span class="inline-block h-2 w-2 rounded-full bg-slate-300"></span><span>At least one special character</span></li>
            </ul>
          </div>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700">Confirm password</label>
          <div class="relative mt-2">
            <input type="password" id="password_confirmation" name="password_confirmation" required minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" class="w-full rounded-xl border-2 border-slate-300 px-3 py-3 pr-11 outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100">
            <button id="confirmationToggle" type="button" class="absolute inset-y-0 right-3 flex items-center text-slate-400 transition hover:text-slate-700" aria-label="Show password" aria-pressed="false" aria-controls="password_confirmation" onclick="togglePasswordVisibility('password_confirmation', 'confirmationToggle', 'confirmationToggleIcon')">
              <i data-lucide="eye" id="confirmationToggleIcon" class="h-5 w-5"></i>
            </button>
          </div>
        </div>

        <button class="w-full rounded-lg bg-rose-600 px-4 py-3 text-sm font-bold text-white hover:bg-rose-700">Update password</button>
      </form>
    <?php endif; ?>
    <?php if (!$userId && !$success): ?><a href="forgot_password.php" class="mt-5 block text-center text-sm font-semibold text-rose-600 hover:underline">Request a new reset link</a><?php endif; ?>
    <a href="login.php" class="mt-3 block text-center text-sm font-semibold text-slate-500 hover:underline">Back to login</a>
  </main>

  <script>
    function togglePasswordVisibility(inputId, buttonId, iconId) {
      const input = document.getElementById(inputId);
      const button = document.getElementById(buttonId);
      const icon = document.getElementById(iconId);

      if (!input || !button || !icon) {
        return;
      }

      const shouldShow = input.type === 'password';
      input.type = shouldShow ? 'text' : 'password';
      button.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
      button.setAttribute('aria-pressed', String(shouldShow));
      icon.setAttribute('data-lucide', shouldShow ? 'eye-off' : 'eye');
      lucide.createIcons();
    }

    function updatePasswordRequirements() {
      const input = document.getElementById('password');
      const requirements = document.getElementById('passwordRequirements');
      if (!input || !requirements) {
        return;
      }

      const value = input.value;
      const rules = [
        { key: 'length', valid: value.length >= 8 && value.length <= 128 },
        { key: 'letter', valid: /[A-Za-z]/.test(value) },
        { key: 'number', valid: /[0-9]/.test(value) },
        { key: 'special', valid: /[^A-Za-z0-9]/.test(value) }
      ];

      requirements.hidden = value.length === 0 && document.activeElement !== input;

      rules.forEach(({ key, valid }) => {
        const item = requirements.querySelector('[data-requirement="' + key + '"]');
        if (!item) return;

        const dot = item.querySelector('span');
        item.classList.toggle('text-emerald-600', valid);
        item.classList.toggle('text-slate-600', !valid);
        dot.classList.toggle('bg-emerald-500', valid);
        dot.classList.toggle('bg-slate-300', !valid);
      });
    }

    const passwordInput = document.getElementById('password');
    if (passwordInput) {
      passwordInput.addEventListener('input', updatePasswordRequirements);
      passwordInput.addEventListener('focus', updatePasswordRequirements);
      passwordInput.addEventListener('blur', updatePasswordRequirements);
    }

    lucide.createIcons();
    updatePasswordRequirements();
  </script>
</body>
</html>
