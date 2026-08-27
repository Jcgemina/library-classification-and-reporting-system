<?php

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) require_once $composerAutoload;

const MAX_ATTEMPTS = 5;
const BASE_LOCKOUT_MINUTES = 5;

function getClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function isRateLimited(PDO $pdo, string $username, string $ip): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS failed_count, UNIX_TIMESTAMP(MAX(attempted_at)) AS last_attempt FROM login_attempts WHERE (username = :username OR ip_address = :ip) AND success = 0');
    $stmt->execute([':username' => $username, ':ip' => $ip]);
    $row = $stmt->fetch();
    $failedCount = (int)($row['failed_count'] ?? 0);
    $lastAttempt = (int)($row['last_attempt'] ?? 0);
    if ($failedCount < MAX_ATTEMPTS || $lastAttempt <= 0) return 0;
    $remaining = ($lastAttempt + BASE_LOCKOUT_MINUTES * 60) - time();
    if ($remaining <= 0) {
        $delete = $pdo->prepare('DELETE FROM login_attempts WHERE (username = :username OR ip_address = :ip) AND success = 0');
        $delete->execute([':username' => $username, ':ip' => $ip]);
        return 0;
    }
    return $remaining;
}

function formatLockoutDuration(int $seconds): string {
    $minutes = (int)ceil($seconds / 60);
    return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
}

function getFailedAttemptCount(PDO $pdo, string $username, string $ip): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE (username = :username OR ip_address = :ip) AND success = 0');
    $stmt->execute([':username' => $username, ':ip' => $ip]);
    return (int)$stmt->fetchColumn();
}

function recordAttempt(PDO $pdo, string $username, string $ip, bool $success): void {
    $stmt = $pdo->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (:username, :ip, :success)');
    $stmt->execute([':username' => $username, ':ip' => $ip, ':success' => $success ? 1 : 0]);
}

function recordSecurityLog(PDO $pdo, ?int $userId, ?string $username, string $eventType, string $severity, string $description, string $ip): void {
    $stmt = $pdo->prepare('INSERT INTO security_logs (user_id, username, event_type, severity, description, ip_address, user_agent) VALUES (:user_id, :username, :event_type, :severity, :description, :ip_address, :user_agent)');
    $stmt->execute([':user_id' => $userId, ':username' => $username, ':event_type' => $eventType, ':severity' => $severity, ':description' => $description, ':ip_address' => $ip, ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
}

function hasRecentSecurityLog(PDO $pdo, string $ip, string $eventType, int $seconds): bool {
    $seconds = max(1, $seconds);
    $stmt = $pdo->prepare("SELECT id FROM security_logs WHERE ip_address = :ip AND event_type = :event_type AND created_at >= DATE_SUB(NOW(), INTERVAL {$seconds} SECOND) LIMIT 1");
    $stmt->execute([':ip' => $ip, ':event_type' => $eventType]);
    return (bool)$stmt->fetchColumn();
}

function countSecurityLogs(PDO $pdo, string $ip, string $eventType): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM security_logs WHERE ip_address = :ip AND event_type = :eventType');
    $stmt->execute([':ip' => $ip, ':eventType' => $eventType]);
    return (int)$stmt->fetchColumn();
}

function recordActivityLog(PDO $pdo, ?int $userId, string $action, string $description, ?string $entityType = null, ?int $entityId = null): void {
    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, ip_address) VALUES (:user_id, :action, :description, :entity_type, :entity_id, :ip_address)');
    $stmt->execute([':user_id' => $userId, ':action' => $action, ':description' => $description, ':entity_type' => $entityType, ':entity_id' => $entityId, ':ip_address' => getClientIp()]);
}

function clearAttempts(PDO $pdo, string $username, string $ip): void {
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username OR ip_address = :ip');
    $stmt->execute([':username' => $username, ':ip' => $ip]);
}

function requireLogin(): void {
    if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function setFlash(string $message, bool $isLockout = false, ?int $attemptCount = null): void {
    $_SESSION['flash_error'] = $message;
    $_SESSION['flash_locked'] = $isLockout;
    $_SESSION['flash_attempts'] = $attemptCount;
}

function getFlash(): array {
    if (!empty($_SESSION['flash_error'])) {
        $result = ['message' => $_SESSION['flash_error'], 'locked' => !empty($_SESSION['flash_locked']), 'attempts' => $_SESSION['flash_attempts'] ?? null];
        unset($_SESSION['flash_error'], $_SESSION['flash_locked'], $_SESSION['flash_attempts']);
        return $result;
    }
    return ['message' => null, 'locked' => false, 'attempts' => null];
}

function environmentValue(string $key, string $fallback = ''): string {
    $value = getenv($key);
    if ($value !== false && $value !== '') return $value;
    static $env = null;
    if ($env === null) {
        $env = [];
        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$name, $setting] = explode('=', $line, 2);
                $env[trim($name)] = trim($setting, " \t\r\n\"");
            }
        }
    }
    return $env[$key] ?? $fallback;
}

function createPasswordResetToken(PDO $pdo, int $userId): string {
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id OR expires_at < NOW()')->execute([':user_id' => $userId]);
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
    $stmt->execute([':user_id' => $userId, ':token_hash' => hash('sha256', $token)]);
    return $token;
}

function getPasswordResetUserId(PDO $pdo, string $token): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $stmt = $pdo->prepare(
        'SELECT user_id FROM password_reset_tokens
         WHERE token_hash = :token_hash
           AND expires_at > NOW()
           AND used_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $userId = $stmt->fetchColumn();

    return $userId === false ? null : (int)$userId;
}

function queuePasswordSetupEmail(PDO $pdo, string $recipient, string $fullName, string $username, string $token, string $emailType = 'setup'): void {
    $emailType = $emailType === 'reset' ? 'reset' : 'setup';
    $stmt = $pdo->prepare('INSERT INTO email_queue (recipient, full_name, username, reset_token, email_type) VALUES (:recipient, :full_name, :username, :reset_token, :email_type)');
    $stmt->execute([
        ':recipient' => $recipient,
        ':full_name' => $fullName,
        ':username' => $username,
        ':reset_token' => $token,
        ':email_type' => $emailType,
    ]);
}

function passwordResetUrl(string $token): string {
    $baseUrl = rtrim(environmentValue('APP_BASE_URL', 'http://localhost/library-classification-and-reporting-system'), '/');
    return $baseUrl . '/reset_password.php?token=' . urlencode($token);
}

function sendPasswordSetupEmail(string $recipient, string $fullName, string $username, string $token, string $emailType = 'setup'): bool {
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return false;
    $emailType = $emailType === 'reset' ? 'reset' : 'setup';
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $link = htmlspecialchars(passwordResetUrl($token), ENT_QUOTES, 'UTF-8');
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = environmentValue('SMTP_HOST', 'smtp.gmail.com');
        $mail->Port = (int)environmentValue('SMTP_PORT', '587');
        $mail->SMTPAuth = true;
        $mail->Username = environmentValue('SMTP_USERNAME');
        $mail->Password = environmentValue('SMTP_PASSWORD');
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom(environmentValue('SMTP_FROM_EMAIL', $mail->Username), environmentValue('SMTP_FROM_NAME', 'AppSys Library'));
        $mail->addAddress($recipient, $fullName);
        $mail->isHTML(true);
        if ($emailType === 'reset') {
            $mail->Subject = 'Reset your AppSys Library password';
            $mail->Body = "<html><body style=\"font-family:Arial,sans-serif;color:#1e293b\"><h2>Password reset request</h2><p>Hello {$safeName}, we received a request to reset your AppSys Library password.</p><p><a href=\"{$link}\" style=\"display:inline-block;background:#be123c;color:#fff;padding:12px 18px;text-decoration:none;border-radius:6px\">Reset your password</a></p><p>This link expires in one hour. If you did not request this, you can ignore this email.</p></body></html>";
            $mail->AltBody = "Hello {$fullName}, reset your AppSys Library password here: " . passwordResetUrl($token) . " This link expires in one hour. If you did not request this, you can ignore this email.";
        } else {
            $mail->Subject = 'Set up your AppSys Library account';
            $mail->Body = "<html><body style=\"font-family:Arial,sans-serif;color:#1e293b\"><h2>Welcome to AppSys Library</h2><p>Hello {$safeName}, your account has been created.</p><p><strong>Username:</strong> {$safeUsername}</p><p><a href=\"{$link}\" style=\"display:inline-block;background:#be123c;color:#fff;padding:12px 18px;text-decoration:none;border-radius:6px\">Set your password</a></p><p>This link expires in one hour.</p></body></html>";
            $mail->AltBody = "Hello {$fullName}, set your AppSys Library password here: " . passwordResetUrl($token);
        }
        return $mail->send();
    } catch (\Throwable $exception) {
        error_log('SMTP email error: ' . $exception->getMessage());
        return false;
    }
}
