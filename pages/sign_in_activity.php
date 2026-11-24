<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=sign_in_activity');
    exit;
}

requireLogin();

if (!$pdo instanceof PDO) {
    http_response_code(503);
    exit('The database connection is unavailable. Please try again later.');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userStatement = $pdo->prepare(
    "SELECT id FROM users
     WHERE id = :id AND is_active = 1 AND role IN ('admin', 'librarian')
     LIMIT 1"
);
$userStatement->execute([':id' => $userId]);
if ($userId < 1 || $userStatement->fetchColumn() === false) {
    http_response_code(403);
    exit('This account is unavailable. Contact an administrator.');
}

$signInHistory = [];
$signInHistoryError = false;
try {
    $signInHistory = getRecentSignInEvents($pdo, $userId);
} catch (PDOException $exception) {
    error_log('Account sign-in activity retrieval failed: ' . $exception->getMessage());
    $signInHistoryError = true;
}

$escapeSignInActivity = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="settings-screen" data-sign-in-activity-page>
  <header class="settings-page-heading">
    <div>
      <nav class="app-breadcrumbs" aria-label="Breadcrumb">
        <ol>
          <li><a href="app.php?page=account">My Account</a></li>
          <li aria-current="page"><span>Sign-in activity</span></li>
        </ol>
      </nav>
      <h1>Sign-in activity</h1>
      <p class="page-description">Review up to 10 recent sign-in attempts associated with your account.</p>
    </div>
    <span class="settings-preview-chip"><i data-lucide="shield-check" aria-hidden="true"></i>Personal security</span>
  </header>

  <div class="settings-sheet">
    <section class="settings-section" aria-labelledby="recentSignInsHeading">
      <div>
        <h2 class="settings-section-title" id="recentSignInsHeading">Recent sign-ins</h2>
        <p class="settings-section-copy">Unsuccessful attempts are shown when we can associate them with your account.</p>
      </div>
      <div class="account-signin-content">
        <?php if ($signInHistoryError): ?>
          <p class="account-signin-message" role="alert">Sign-in activity could not be loaded. Reload this page to try again.</p>
        <?php elseif (!$signInHistory): ?>
          <p class="settings-inline-status">No sign-in activity is recorded for this account yet.</p>
        <?php else: ?>
          <div class="account-signin-table-shell" role="region" aria-label="Recent sign-in activity" tabindex="0">
            <table class="account-signin-table">
              <caption class="sr-only">Up to 10 recent sign-in attempts associated with your account</caption>
              <thead>
                <tr>
                  <th scope="col">Date and time</th>
                  <th scope="col">Result</th>
                  <th scope="col">IP address</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($signInHistory as $signInEvent):
                  $eventType = (string) ($signInEvent['event_type'] ?? '');
                  $eventLabel = match ($eventType) {
                      'login_success' => 'Successful sign-in',
                      'login_failed' => 'Unsuccessful attempt',
                      'login_blocked' => 'Temporarily blocked',
                      default => 'Sign-in event',
                  };
                  $eventClass = $eventType === 'login_success' ? 'is-success' : 'is-warning';
                  $eventDate = new DateTimeImmutable((string) $signInEvent['created_at']);
                  $eventIp = trim((string) ($signInEvent['ip_address'] ?? ''));
                ?>
                  <tr>
                    <td class="account-signin-time"><time datetime="<?= $escapeSignInActivity($eventDate->format(DateTimeInterface::ATOM)) ?>"><?= $escapeSignInActivity($eventDate->format('M j, Y g:i A')) ?></time></td>
                    <td><span class="account-signin-status <?= $eventClass ?>"><?= $eventLabel ?></span></td>
                    <td class="account-signin-ip"><?= $eventIp !== '' ? $escapeSignInActivity($eventIp) : 'Unavailable' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php if (strtolower((string) ($_SESSION['role'] ?? '')) === 'admin'): ?>
          <a class="settings-link" href="app.php?page=logs&amp;log_tab=security" data-page="logs">Review security logs</a>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
