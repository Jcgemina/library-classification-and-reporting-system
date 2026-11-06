<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=account');
    exit;
}

requireLogin();

function accountJsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

$csrfToken = $_SESSION['account_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['account_csrf'] = $csrfToken;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$pdo instanceof PDO) {
        accountJsonResponse(['success' => false, 'message' => 'The database connection is unavailable. Please try again later.'], 503);
    }

    if (!hash_equals($csrfToken, requestString($_POST, 'csrf_token'))) {
        accountJsonResponse(['success' => false, 'message' => 'Your session has expired. Reload My Account and try again.'], 403);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        accountJsonResponse(['success' => false, 'message' => 'Your session has expired. Sign in again to continue.'], 401);
    }

    $userStatement = $pdo->prepare(
        "SELECT id, username, password FROM users
         WHERE id = :id AND is_active = 1 AND role IN ('admin', 'librarian')
         LIMIT 1"
    );
    $userStatement->execute([':id' => $userId]);
    $user = $userStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user) || !is_string($user['password'] ?? null)) {
        accountJsonResponse(['success' => false, 'message' => 'This account is unavailable. Contact an administrator.'], 403);
    }

    $currentPassword = requestString($_POST, 'current_password');
    if ($currentPassword === '' || !password_verify($currentPassword, $user['password'])) {
        accountJsonResponse(['success' => false, 'message' => 'The current password is incorrect. No changes were made.'], 403);
    }

    $action = requestString($_POST, 'action');
    $newUsername = trim(requestString($_POST, 'new_username'));
    $newPassword = requestString($_POST, 'new_password');
    $passwordConfirmation = requestString($_POST, 'password_confirmation');

    if ($action === 'update_username') {
        if ($newUsername === '' || preg_match('//u', $newUsername) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $newUsername) === 1) {
            accountJsonResponse(['success' => false, 'message' => 'Enter a username containing 1 to 50 characters.'], 422);
        }
        $usernameLength = function_exists('mb_strlen') ? mb_strlen($newUsername, 'UTF-8') : strlen($newUsername);
        if ($usernameLength > 50) {
            accountJsonResponse(['success' => false, 'message' => 'Enter a username containing 1 to 50 characters.'], 422);
        }

        if ($newUsername === (string) $user['username']) {
            accountJsonResponse([
                'success' => true,
                'message' => 'Your username is already set to that value.',
                'username' => $newUsername,
                'csrfToken' => $csrfToken,
            ]);
        }

        try {
            $duplicateStatement = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
            $duplicateStatement->execute([':username' => $newUsername, ':id' => $userId]);
            if ($duplicateStatement->fetchColumn() !== false) {
                accountJsonResponse(['success' => false, 'message' => 'That username is already in use. Choose another username.'], 409);
            }

            $pdo->beginTransaction();
            $updateStatement = $pdo->prepare('UPDATE users SET username = :username WHERE id = :id');
            $updateStatement->execute([':username' => $newUsername, ':id' => $userId]);
            recordActivityLog($pdo, $userId, 'change_username', 'Changed account username.', 'user', $userId);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorInfo = $exception->errorInfo ?? [];
            if (($errorInfo[0] ?? $exception->getCode()) === '23000' && (int) ($errorInfo[1] ?? 0) === 1062) {
                accountJsonResponse(['success' => false, 'message' => 'That username is already in use. Choose another username.'], 409);
            }
            error_log('Account username update failed: ' . $exception->getMessage());
            accountJsonResponse(['success' => false, 'message' => 'Unable to update your username right now. Please try again.'], 500);
        }

        $_SESSION['username'] = $newUsername;
        $_SESSION['account_csrf'] = bin2hex(random_bytes(32));
        accountJsonResponse([
            'success' => true,
            'message' => 'Your username has been updated.',
            'username' => $newUsername,
            'csrfToken' => $_SESSION['account_csrf'],
        ]);
    }

    if ($action === 'update_password') {
        if (($passwordError = validatePasswordStrength($newPassword)) !== null) {
            accountJsonResponse(['success' => false, 'message' => $passwordError], 422);
        }
        if ($newPassword !== $passwordConfirmation) {
            accountJsonResponse(['success' => false, 'message' => 'The new password and confirmation do not match.'], 422);
        }
        if (password_verify($newPassword, $user['password'])) {
            accountJsonResponse(['success' => false, 'message' => 'Choose a password different from your current password.'], 422);
        }

        try {
            $pdo->beginTransaction();
            $updateStatement = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $updateStatement->execute([
                ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => $userId,
            ]);
            $tokenStatement = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id AND used_at IS NULL');
            $tokenStatement->execute([':user_id' => $userId]);
            recordActivityLog($pdo, $userId, 'change_password', 'Changed account password.', 'user', $userId);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Account password update failed: ' . $exception->getMessage());
            accountJsonResponse(['success' => false, 'message' => 'Unable to update your password right now. Please try again.'], 500);
        }

        session_regenerate_id(true);
        $_SESSION['account_csrf'] = bin2hex(random_bytes(32));
        accountJsonResponse([
            'success' => true,
            'message' => 'Your password has been updated.',
            'csrfToken' => $_SESSION['account_csrf'],
        ]);
    }

    accountJsonResponse(['success' => false, 'message' => 'Choose a valid account update.'], 422);
}

if (!$pdo instanceof PDO) {
    http_response_code(503);
    exit('The database connection is unavailable. Please try again later.');
}

$userStatement = $pdo->prepare(
    "SELECT username, full_name, role FROM users
     WHERE id = :id AND is_active = 1 AND role IN ('admin', 'librarian')
     LIMIT 1"
);
$userStatement->execute([':id' => (int) ($_SESSION['user_id'] ?? 0)]);
$account = $userStatement->fetch(PDO::FETCH_ASSOC);
if (!is_array($account)) {
    http_response_code(403);
    exit('This account is unavailable. Contact an administrator.');
}

$escapeAccount = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="mx-auto w-full max-w-3xl space-y-6 p-1 text-slate-900 sm:p-2" data-account-page>
  <header>
    <h2 class="text-2xl font-bold text-slate-900">My Account</h2>
    <p class="mt-1 text-sm leading-6 text-slate-600">Update the sign-in details for your librarian account.</p>
  </header>

  <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="accountIdentityHeading">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h3 id="accountIdentityHeading" class="text-lg font-bold text-slate-900"><?= $escapeAccount((string) $account['full_name']) ?></h3>
        <p class="mt-1 text-sm text-slate-600"><span class="capitalize"><?= $escapeAccount((string) $account['role']) ?></span> account</p>
      </div>
      <div class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800">
        Username: <span id="accountCurrentUsername"><?= $escapeAccount((string) $account['username']) ?></span>
      </div>
    </div>
  </section>

  <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="usernameHeading">
    <div class="border-b border-slate-200 pb-4">
      <h3 id="usernameHeading" class="text-lg font-bold text-slate-900">Change username</h3>
      <p class="mt-1 text-sm leading-6 text-slate-600">You’ll use the new username the next time you sign in. Confirm your password to save this change.</p>
    </div>
    <form class="account-update-form mt-5 space-y-4" data-account-action="update_username">
      <input type="hidden" name="csrf_token" value="<?= $escapeAccount($csrfToken) ?>">
      <label class="block text-sm font-semibold text-slate-700" for="accountNewUsername">
        New username
        <input id="accountNewUsername" name="new_username" type="text" maxlength="50" autocomplete="username" required value="<?= $escapeAccount((string) $account['username']) ?>" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 sm:max-w-md">
      </label>
      <label class="block text-sm font-semibold text-slate-700" for="accountUsernameCurrentPassword">
        Current password
        <input id="accountUsernameCurrentPassword" name="current_password" type="password" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="current-password" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 sm:max-w-md">
      </label>
      <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="account-submit inline-flex min-h-10 items-center justify-center rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-300">Save username</button>
        <span class="account-form-status text-sm text-slate-600" role="status" aria-live="polite"></span>
      </div>
    </form>
  </section>

  <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="passwordHeading">
    <div class="border-b border-slate-200 pb-4">
      <h3 id="passwordHeading" class="text-lg font-bold text-slate-900">Change password</h3>
      <p class="mt-1 text-sm leading-6 text-slate-600">Choose a password you don’t use elsewhere. Your current password is required to confirm the change.</p>
    </div>
    <form class="account-update-form mt-5 space-y-4" data-account-action="update_password">
      <input type="hidden" name="csrf_token" value="<?= $escapeAccount($csrfToken) ?>">
      <label class="block text-sm font-semibold text-slate-700" for="accountPasswordCurrent">
        Current password
        <input id="accountPasswordCurrent" name="current_password" type="password" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="current-password" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 sm:max-w-md">
      </label>
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm font-semibold text-slate-700" for="accountNewPassword">
          New password
          <input id="accountNewPassword" name="new_password" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" required aria-describedby="accountPasswordRequirements" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
        </label>
        <label class="block text-sm font-semibold text-slate-700" for="accountPasswordConfirmation">
          Confirm new password
          <input id="accountPasswordConfirmation" name="password_confirmation" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
        </label>
      </div>
      <p id="accountPasswordRequirements" class="text-xs leading-5 text-slate-600">Use 8–128 characters, including at least one letter, one number, and one special character.</p>
      <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="account-submit inline-flex min-h-10 items-center justify-center rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-300">Save password</button>
        <span class="account-form-status text-sm text-slate-600" role="status" aria-live="polite"></span>
      </div>
    </form>
  </section>
</div>
<script src="assets/js/account.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/account.js') ?>"></script>
