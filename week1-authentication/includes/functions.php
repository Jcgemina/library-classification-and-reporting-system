<?php
/**
 * Shared helper functions:
 *  - Login rate limiting (brute-force protection)
 *  - Authentication / access protection guard
 */

const MAX_ATTEMPTS         = 5;  // failed attempts allowed before each lockout stage
const BASE_LOCKOUT_MINUTES = 5;  // first lockout = 5 minutes, then doubles each stage
const MAX_LOCKOUT_MINUTES  = 30; // ceiling - the doubling can never lock someone out longer than this

function getClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Check if a username/IP combo is currently rate-limited.
 * Lockout is progressive: the FIRST lockout (after 5 failed attempts) is always
 * exactly 5 minutes. After that, since a new attempt can only ever happen once
 * the previous countdown has fully finished (the form is disabled/blocked while
 * locked), any single failed attempt after a countdown ends doubles the NEXT
 * lockout: 5 -> 10 -> 20 -> ... capped at MAX_LOCKOUT_MINUTES. A successful
 * login resets everything back to zero (clearAttempts()).
 * Returns the number of seconds remaining if locked, or 0 if not locked.
 */
function isRateLimited(PDO $pdo, string $username, string $ip): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS failed_count, MAX(attempted_at) AS last_attempt
         FROM login_attempts
         WHERE (username = :username OR ip_address = :ip)
           AND success = 0"
    );
    $stmt->bindValue(':username', $username);
    $stmt->bindValue(':ip', $ip);
    $stmt->execute();
    $row = $stmt->fetch();

    $failedCount = (int)($row['failed_count'] ?? 0);
    if ($failedCount < MAX_ATTEMPTS || empty($row['last_attempt'])) {
        return 0;
    }

    // Exactly 5 fails (the 5th one) = first lockout = 5 minutes, no doubling yet.
    // Every fail beyond that (the 6th, 7th, ...) can only happen after the
    // previous lockout already finished, so each one doubles the next lockout.
    $timesOverThreshold = $failedCount - MAX_ATTEMPTS; // 0 on the 5th fail, 1 on the 6th, ...
    $lockoutMinutes = BASE_LOCKOUT_MINUTES * (2 ** $timesOverThreshold);
    $lockoutMinutes = min($lockoutMinutes, MAX_LOCKOUT_MINUTES);

    $lastAttempt = strtotime($row['last_attempt']);
    $unlockAt = $lastAttempt + ($lockoutMinutes * 60);
    $remaining = $unlockAt - time();

    return $remaining > 0 ? $remaining : 0;
}

/**
 * Format a duration (in seconds) for display.
 * Under 60 minutes -> "12 minute(s)"
 * 60 minutes or more -> "1:05 (hours:minutes)"
 */
function formatLockoutDuration(int $seconds): string {
    $totalMinutes = (int)ceil($seconds / 60);

    if ($totalMinutes < 60) {
        return $totalMinutes . ' minute(s)';
    }

    $hours   = intdiv($totalMinutes, 60);
    $minutes = $totalMinutes % 60;
    return sprintf('%d:%02d (hours:minutes)', $hours, $minutes);
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
function setFlash(string $message, bool $isLockout = false): void {
    $_SESSION['flash_error']  = $message;
    $_SESSION['flash_locked'] = $isLockout;
}

/** Returns ['message' => string|null, 'locked' => bool] */
function getFlash(): array {
    if (!empty($_SESSION['flash_error'])) {
        $result = [
            'message' => $_SESSION['flash_error'],
            'locked'  => !empty($_SESSION['flash_locked']),
        ];
        unset($_SESSION['flash_error'], $_SESSION['flash_locked']);
        return $result;
    }
    return ['message' => null, 'locked' => false];
}
