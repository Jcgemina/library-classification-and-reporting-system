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
    "SELECT username, full_name, email, role FROM users
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
$accountInitials = strtoupper(substr((string) $account['full_name'], 0, 1));
$accountEmail = trim((string) ($account['email'] ?? ''));
?>
<div class="settings-screen" data-account-page>
  <header class="settings-page-heading">
    <div>
      <h1>My Account</h1>
      <p class="page-description">Update the sign-in details for your account.</p>
    </div>
    <span class="settings-preview-chip"><i data-lucide="user-round" aria-hidden="true"></i>Personal settings</span>
  </header>

  <section class="settings-profile-band" aria-labelledby="accountIdentityHeading">
    <span class="settings-profile-avatar" aria-hidden="true"><?= $escapeAccount($accountInitials) ?></span>
    <div>
      <h2 class="settings-profile-name" id="accountIdentityHeading"><?= $escapeAccount((string) $account['full_name']) ?></h2>
      <p class="settings-profile-meta"><span class="capitalize"><?= $escapeAccount((string) $account['role']) ?></span> account</p>
    </div>
    <div class="settings-profile-username">
      Username
      <strong id="accountCurrentUsername"><?= $escapeAccount((string) $account['username']) ?></strong>
    </div>
  </section>

  <div class="settings-sheet">
    <section class="settings-section" aria-labelledby="usernameHeading">
      <div>
        <h2 class="settings-section-title" id="usernameHeading">Username</h2>
        <p class="settings-section-copy">Change the name you use to sign in. Your current password is needed to confirm the change.</p>
      </div>
      <form class="account-update-form account-form" data-account-action="update_username">
        <input type="hidden" name="csrf_token" value="<?= $escapeAccount($csrfToken) ?>">
        <label class="settings-field" for="accountNewUsername">New username
          <input id="accountNewUsername" name="new_username" type="text" maxlength="50" autocomplete="username" required value="<?= $escapeAccount((string) $account['username']) ?>">
        </label>
        <label class="settings-field" for="accountUsernameCurrentPassword">Current password
          <input id="accountUsernameCurrentPassword" name="current_password" type="password" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="current-password" required>
        </label>
        <div class="account-form-footer">
          <button type="submit" class="account-submit settings-button settings-button-primary">Save username</button>
          <span class="account-form-status settings-status" role="status" aria-live="polite"></span>
        </div>
      </form>
    </section>

    <section class="settings-section" aria-labelledby="passwordHeading">
      <div>
        <h2 class="settings-section-title" id="passwordHeading">Password</h2>
        <p class="settings-section-copy">Use a unique password. Confirm your current password before setting a new one.</p>
      </div>
      <form class="account-update-form account-form" data-account-action="update_password">
        <input type="hidden" name="csrf_token" value="<?= $escapeAccount($csrfToken) ?>">
        <label class="settings-field" for="accountPasswordCurrent">Current password
          <input id="accountPasswordCurrent" name="current_password" type="password" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="current-password" required>
        </label>
        <label class="settings-field" for="accountNewPassword">New password
          <input id="accountNewPassword" name="new_password" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" required aria-describedby="accountPasswordRequirements">
        </label>
        <label class="settings-field" for="accountPasswordConfirmation">Confirm new password
          <input id="accountPasswordConfirmation" name="password_confirmation" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" required>
        </label>
        <p class="settings-help" id="accountPasswordRequirements">Use 8–128 characters, including at least one letter, one number, and one special character.</p>
        <div class="account-form-footer">
          <button type="submit" class="account-submit settings-button settings-button-primary">Save password</button>
          <span class="account-form-status settings-status" role="status" aria-live="polite"></span>
        </div>
      </form>
    </section>

    <section class="settings-section" aria-labelledby="accountEmailHeading">
      <div>
        <h2 class="settings-section-title" id="accountEmailHeading">Account email</h2>
        <p class="settings-section-copy">Used for account setup and password recovery. Contact an administrator if this address needs to change.</p>
      </div>
      <div class="settings-inline-status">
        <i data-lucide="<?= $accountEmail !== '' ? 'mail-check' : 'mail-x' ?>" aria-hidden="true"></i>
        <span><?= $accountEmail !== '' ? $escapeAccount($accountEmail) : 'No email address is set for this account.' ?></span>
      </div>
    </section>

    <section class="settings-section" aria-labelledby="signInHistoryHeading">
      <div>
        <h2 class="settings-section-title" id="signInHistoryHeading">Sign-in activity</h2>
        <p class="settings-section-copy">Sign-in events are recorded for auditing, but personal sign-in history is not displayed on this page.</p>
      </div>
      <?php if (strtolower((string) $account['role']) === 'admin'): ?>
        <a class="settings-link" href="app.php?page=logs&amp;log_tab=security">Review security logs</a>
      <?php else: ?>
        <div class="settings-inline-status">
          <i data-lucide="shield-check" aria-hidden="true"></i>
          <span>Security logs are available to administrators.</span>
        </div>
      <?php endif; ?>
    </section>

    <section class="settings-section" aria-labelledby="additionalSecurityHeading">
      <div>
        <h2 class="settings-section-title" id="additionalSecurityHeading">Additional security</h2>
        <p class="settings-section-copy">These safeguards need system support before they can be managed here.</p>
      </div>
      <div class="settings-future-list">
        <div class="settings-future-row"><span>Two-step verification</span><span class="settings-unavailable">Not available</span></div>
        <div class="settings-future-row"><span>Manage active sessions</span><span class="settings-unavailable">Not available</span></div>
      </div>
    </section>
  </div>
</div>
<script src="assets/js/account.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/account.js') ?>"></script>
