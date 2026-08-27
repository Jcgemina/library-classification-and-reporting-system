<?php

const MAX_ATTEMPTS         = 5;
const BASE_LOCKOUT_MINUTES = 5; 

function getClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function isRateLimited(PDO $pdo, string $username, string $ip): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS failed_count,
                UNIX_TIMESTAMP(MAX(attempted_at)) AS last_attempt
         FROM login_attempts
         WHERE (username = :username OR ip_address = :ip)
           AND success = 0"
    );

    $stmt->bindValue(':username', $username);
    $stmt->bindValue(':ip', $ip);
    $stmt->execute();

    $row = $stmt->fetch();

    $failedCount = (int)($row['failed_count'] ?? 0);
    $lastAttempt = (int)($row['last_attempt'] ?? 0);

    if ($failedCount < MAX_ATTEMPTS || $lastAttempt <= 0) {
        return 0;
    }

    $unlockAt = $lastAttempt + (BASE_LOCKOUT_MINUTES * 60);
    $remaining = $unlockAt - time();

    // Lockout has expired — reset the failed attempts
    if ($remaining <= 0) {
        $delete = $pdo->prepare(
            "DELETE FROM login_attempts
             WHERE (username = :username OR ip_address = :ip)
               AND success = 0"
        );

        $delete->bindValue(':username', $username);
        $delete->bindValue(':ip', $ip);
        $delete->execute();

        return 0;
    }

    return $remaining;
}

function formatLockoutDuration(int $seconds): string {
    $totalMinutes = (int)ceil($seconds / 60);
    return $totalMinutes . ($totalMinutes === 1 ? ' minute' : ' minutes');
}

/** Get the current count of failed login attempts for a username/IP. */
function getFailedAttemptCount(PDO $pdo, string $username, string $ip): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS failed_count
         FROM login_attempts
         WHERE (username = :username OR ip_address = :ip)
           AND success = 0"
    );
    
    $stmt->bindValue(':username', $username);
    $stmt->bindValue(':ip', $ip);
    $stmt->execute();
    
    $row = $stmt->fetch();
    return (int)($row['failed_count'] ?? 0);
}

/** Record a login attempt (successful or failed) for auditing + rate limiting. */
function recordAttempt(PDO $pdo, string $username, string $ip, bool $success): void {
    $stmt = $pdo->prepare(
        "INSERT INTO login_attempts (username, ip_address, success) VALUES (:username, :ip, :success)"
    );
    $stmt->execute([
        ':username' => $username,
        ':ip'       => $ip,
        ':success'  => $success ? 1 : 0,
    ]);
}

function recordSecurityLog(PDO $pdo, ?int $userId, ?string $username, string $eventType, string $severity, string $description, string $ip): void {
    $stmt = $pdo->prepare(
        'INSERT INTO security_logs (user_id, username, event_type, severity, description, ip_address, user_agent)
         VALUES (:user_id, :username, :event_type, :severity, :description, :ip_address, :user_agent)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':username' => $username,
        ':event_type' => $eventType,
        ':severity' => $severity,
        ':description' => $description,
        ':ip_address' => $ip,
        ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

function hasRecentSecurityLog(PDO $pdo, string $ip, string $eventType, int $seconds): bool {
    $seconds = max(1, $seconds);
    $stmt = $pdo->prepare(
        "SELECT id FROM security_logs
         WHERE ip_address = :ip AND event_type = :event_type
           AND created_at >= DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)
         LIMIT 1"
    );
    $stmt->execute([':ip' => $ip, ':event_type' => $eventType]);
    return (bool) $stmt->fetchColumn();
}

function countSecurityLogs(PDO $pdo, string $ip, string $eventType): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM security_logs WHERE ip_address = :ip AND event_type = :event_type'
    );
    $stmt->execute([':ip' => $ip, ':event_type' => $eventType]);
    return (int) $stmt->fetchColumn();
}

function recordActivityLog(PDO $pdo, ?int $userId, string $action, string $description, ?string $entityType = null, ?int $entityId = null): void {
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, ip_address)
         VALUES (:user_id, :action, :description, :entity_type, :entity_id, :ip_address)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':action' => $action,
        ':description' => $description,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':ip_address' => getClientIp(),
    ]);
}

/** Clear failed attempts after a successful login. */
function clearAttempts(PDO $pdo, string $username, string $ip): void {
    $stmt = $pdo->prepare(
        "DELETE FROM login_attempts WHERE username = :username OR ip_address = :ip"
    );
    $stmt->execute([':username' => $username, ':ip' => $ip]);
}

/** Authentication access protection: guard pages that require login. */
function requireLogin(): void {
    if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

/** Flash message helpers (session-based, so no data is exposed in the URL). */
function setFlash(string $message, bool $isLockout = false, ?int $attemptCount = null): void {
    $_SESSION['flash_error']  = $message;
    $_SESSION['flash_locked'] = $isLockout;
    $_SESSION['flash_attempts'] = $attemptCount;
}

/** Returns ['message' => string|null, 'locked' => bool, 'attempts' => int|null] */
function getFlash(): array {
    if (!empty($_SESSION['flash_error'])) {
        $result = [
            'message' => $_SESSION['flash_error'],
            'locked'  => !empty($_SESSION['flash_locked']),
            'attempts' => $_SESSION['flash_attempts'] ?? null,
        ];
        unset($_SESSION['flash_error'], $_SESSION['flash_locked'], $_SESSION['flash_attempts']);
        return $result;
    }
    return ['message' => null, 'locked' => false, 'attempts' => null];
}

function sendAccountDetailsEmail(string $recipient, string $fullName, string $username, string $password, string $role): bool {
    $subject = 'Your AppSys Library account details';
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safeRole = htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8');
    $message = "<html><body style=\"font-family:Arial,sans-serif;color:#1e293b\">"
        . "<h2>Welcome to AppSys Library</h2>"
        . "<p>Hello {$safeName}, your account has been created.</p>"
        . "<p><strong>Username:</strong> {$safeUsername}<br>"
        . "<strong>Password:</strong> " . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . "<br>"
        . "<strong>Role:</strong> {$safeRole}</p>"
        . "<p>Please sign in and change your password after your first login.</p></body></html>";
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . (defined('MAIL_FROM') ? MAIL_FROM : 'no-reply@library.local'),
    ];

    return mail($recipient, $subject, $message, implode("\r\n", $headers));
}
