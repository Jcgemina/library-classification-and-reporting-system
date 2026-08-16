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

/** Clear failed attempts after a successful login. */
function clearAttempts(PDO $pdo, string $username, string $ip): void {
    $stmt = $pdo->prepare(
        "DELETE FROM login_attempts WHERE username = :username OR ip_address = :ip"
    );
    $stmt->execute([':username' => $username, ':ip' => $ip]);
}

/** Authentication access protection: guard pages that require login. */
function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
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
