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

function normalizeEmailType(string $emailType): string {
    return $emailType === 'reset' ? 'reset' : 'setup';
}

function queuePasswordSetupEmail(PDO $pdo, string $recipient, string $fullName, string $username, string $token, string $emailType = 'setup'): void {
    $emailType = normalizeEmailType($emailType);
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

    $emailType = normalizeEmailType($emailType);
    $safeName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $resetUrl = passwordResetUrl($token);
    $link = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

        $emailMeta = $emailType === 'reset'
            ? [
                'subject' => 'Reset your AppSys Library password',
                'eyebrow' => 'ACCOUNT SECURITY',
                'heading' => 'Reset your password',
                'intro' => 'We received a request to reset the password for your AppSys Library account.',
                'buttonLabel' => 'Reset password',
                'closing' => 'If you did not request a password reset, you can safely ignore this email.',
                'altBody' => "Hello {$fullName},\n\nWe received a request to reset your AppSys Library password. Reset it here: {$resetUrl}\n\nThis link expires in one hour. If you did not request a password reset, you can safely ignore this email.",
                'extraRow' => '',
            ]
            : [
                'subject' => 'Set up your AppSys Library account',
                'eyebrow' => 'WELCOME TO APPSYS LIBRARY',
                'heading' => 'Your account is ready',
                'intro' => 'An AppSys Library account has been created for you. Set a password to get started.',
                'buttonLabel' => 'Set your password',
                'closing' => 'For your security, this setup link expires in one hour.',
                'altBody' => "Hello {$fullName},\n\nYour AppSys Library account is ready. Username: {$username}\n\nSet your password here: {$resetUrl}\n\nFor your security, this setup link expires in one hour.",
                'extraRow' => <<<HTML
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;background:#f8fafc;border-left:4px solid #fbbf24;">
                        <tr><td style="padding:16px 18px;color:#334155;font-size:14px;line-height:1.5;"><strong>Username</strong><br>{$safeUsername}</td></tr>
                    </table>
HTML,
            ];

        $mail->Subject = $emailMeta['subject'];
        $mail->AltBody = $emailMeta['altBody'];
        $mail->Body = <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>{$emailMeta['heading']}</title></head>
<body style="margin:0;background:#f1f5f9;color:#1e293b;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">
                <tr><td style="background:#172554;padding:28px 40px;text-align:center;">
                    <div style="color:#fbbf24;font-size:12px;font-weight:bold;letter-spacing:2px;">APPSYS LIBRARY</div>
                    <div style="color:#dbeafe;font-size:12px;margin-top:8px;">{$emailMeta['eyebrow']}</div>
                </td></tr>
                <tr><td style="padding:40px;">
                    <div style="display:inline-block;background:#fef3c7;color:#92400e;border-radius:999px;padding:7px 12px;font-size:11px;font-weight:bold;letter-spacing:1px;">SECURE ACCOUNT LINK</div>
                    <h1 style="margin:22px 0 12px;color:#0f172a;font-size:30px;line-height:1.2;">{$emailMeta['heading']}</h1>
                    <p style="margin:0;color:#475569;font-size:16px;line-height:1.7;">Hello {$safeName},</p>
                    <p style="margin:8px 0 0;color:#475569;font-size:16px;line-height:1.7;">{$emailMeta['intro']}</p>
                    {$emailMeta['extraRow']}
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0 24px;"><tr><td style="border-radius:8px;background:#e11d48;">
                        <a href="{$link}" style="display:inline-block;padding:15px 24px;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">{$emailMeta['buttonLabel']}</a>
                    </td></tr></table>
                    <p style="margin:0;color:#64748b;font-size:13px;line-height:1.7;">{$emailMeta['closing']}</p>
                    <p style="margin:20px 0 0;color:#94a3b8;font-size:12px;line-height:1.6;">If the button does not work, copy and paste this link into your browser:<br><span style="word-break:break-all;color:#64748b;">{$link}</span></p>
                </td></tr>
                <tr><td style="border-top:1px solid #e2e8f0;padding:22px 40px;text-align:center;color:#94a3b8;font-size:12px;line-height:1.6;">This is an automated message from AppSys Library.<br>Please do not reply to this email.</td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
HTML;

        return $mail->send();
    } catch (\Throwable $exception) {
        error_log('SMTP email error: ' . $exception->getMessage());
        return false;
    }
}
