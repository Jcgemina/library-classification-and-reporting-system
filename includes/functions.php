<?php

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) require_once $composerAutoload;

const MAX_ATTEMPTS = 5;
const BASE_LOCKOUT_MINUTES = 5;
const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 128;
const PASSWORD_RESET_EMAIL_REQUEST_LIMIT = 1;
const PASSWORD_RESET_IP_REQUEST_LIMIT = 10;
const PASSWORD_RESET_RATE_WINDOW_SECONDS = 3600;

function validatePasswordStrength(string $password): ?string {
    $length = strlen($password);

    if ($length < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }

    if ($length > PASSWORD_MAX_LENGTH) {
        return 'Password must not exceed ' . PASSWORD_MAX_LENGTH . ' characters.';
    }

    if (!preg_match('/[A-Za-z]/', $password)) {
        return 'Password must contain at least one letter.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must contain at least one special character.';
    }

    return null;
}

function getClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function requestString(array $source, string $key, string $fallback = ''): string {
    $value = $source[$key] ?? null;
    return is_string($value) ? $value : $fallback;
}

function requestInt(array $source, string $key, int $fallback = 0): int {
    $value = $source[$key] ?? null;
    if (is_int($value)) {
        return $value;
    }
    if (!is_string($value)) {
        return $fallback;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT);
    return $validated === false ? $fallback : $validated;
}

function normalizeIsbn(string $isbn): string {
    return strtoupper(str_replace([' ', '-', "\t", "\r", "\n"], '', trim($isbn)));
}

function isValidIsbn(string $isbn): bool {
    $isbn = normalizeIsbn($isbn);
    if (preg_match('/\A[0-9]{9}[0-9X]\z/', $isbn) === 1) {
        $checksum = 0;
        for ($index = 0; $index < 10; $index++) {
            $digit = $isbn[$index] === 'X' ? 10 : (int) $isbn[$index];
            $checksum += $digit * (10 - $index);
        }
        return $checksum % 11 === 0;
    }

    if (preg_match('/\A(?:978|979)[0-9]{10}\z/', $isbn) !== 1) {
        return false;
    }

    $checksum = 0;
    for ($index = 0; $index < 13; $index++) {
        $digit = (int) $isbn[$index];
        $checksum += $digit * ($index % 2 === 0 ? 1 : 3);
    }
    return $checksum % 10 === 0;
}

function prospectusStorageDirectory(): string {
    $directory = environmentValue(
        'PROSPECTUS_UPLOAD_DIR',
        dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . basename(dirname(__DIR__)) . '-prospectuses'
    );
    $isAbsolute = PHP_OS_FAMILY === 'Windows'
        ? preg_match('/\A(?:[A-Za-z]:[\/\\\\]|\\\\\\\\)/', $directory) === 1
        : str_starts_with($directory, DIRECTORY_SEPARATOR);
    if (!$isAbsolute) {
        throw new RuntimeException('Prospectus storage must be an absolute path.');
    }

    $documentRoot = realpath(requestString($_SERVER, 'DOCUMENT_ROOT'));
    $parentPath = realpath(dirname($directory));
    if ($documentRoot === false || $parentPath === false) {
        throw new RuntimeException('Could not resolve prospectus storage parent or the web document root.');
    }

    $documentRootPrefix = rtrim($documentRoot, '/\\') . DIRECTORY_SEPARATOR;
    $parentPrefix = rtrim($parentPath, '/\\') . DIRECTORY_SEPARATOR;
    if (PHP_OS_FAMILY === 'Windows') {
        $documentRootPrefix = strtolower($documentRootPrefix);
        $parentPrefix = strtolower($parentPrefix);
    }
    if ($parentPrefix === $documentRootPrefix || str_starts_with($parentPrefix, $documentRootPrefix)) {
        throw new RuntimeException('Prospectus storage must be outside the web document root.');
    }

    if (!is_dir($directory)) {
        if (file_exists($directory) || is_link($directory) || !mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create prospectus storage.');
        }
    }

    $storagePath = realpath($directory);
    if ($storagePath === false) {
        throw new RuntimeException('Could not resolve prospectus storage.');
    }
    $storagePrefix = rtrim($storagePath, '/\\') . DIRECTORY_SEPARATOR;
    if (PHP_OS_FAMILY === 'Windows') {
        $storagePrefix = strtolower($storagePrefix);
    }
    if ($storagePrefix === $documentRootPrefix || str_starts_with($storagePrefix, $documentRootPrefix)) {
        throw new RuntimeException('Prospectus storage must be outside the web document root.');
    }
    if (PHP_OS_FAMILY !== 'Windows' && !chmod($storagePath, 0700)) {
        throw new RuntimeException('Could not secure prospectus storage permissions.');
    }

    return $storagePath;
}

function isValidProspectusPdf(string $path): bool {
    if (!is_file($path) || !is_readable($path) || !class_exists(finfo::class)) {
        return false;
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    if ($fileInfo->file($path) !== 'application/pdf') {
        return false;
    }

    $fileSize = filesize($path);
    if ($fileSize === false || $fileSize < 10) {
        return false;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $signature = fread($handle, 5);
    fseek($handle, max(0, $fileSize - 1024));
    $trailer = fread($handle, 1024);
    fclose($handle);

    return $signature === '%PDF-' && is_string($trailer) && str_contains($trailer, '%%EOF');
}

function csrfToken(): string {
    $token = $_SESSION['csrf_token'] ?? null;
    if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }

    return $token;
}

function hasValidCsrfToken(): bool {
    $submittedToken = requestString($_SERVER, 'HTTP_X_CSRF_TOKEN');
    if ($submittedToken === '') {
        $submittedToken = requestString($_POST, 'csrf_token');
    }

    return $submittedToken !== '' && hash_equals(csrfToken(), $submittedToken);
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
    $stmt->execute([':user_id' => $userId, ':username' => $username, ':event_type' => $eventType, ':severity' => $severity, ':description' => $description, ':ip_address' => $ip, ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
}

function getRecentSignInEvents(PDO $pdo, int $userId): array {
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user ID is required to retrieve sign-in activity.');
    }

    $stmt = $pdo->prepare(
        "SELECT event_type, ip_address, created_at
         FROM security_logs
         WHERE user_id = :user_id
           AND event_type IN ('login_success', 'login_failed', 'login_blocked')
         ORDER BY created_at DESC, id DESC
         LIMIT 10"
    );
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchAll();
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

function refreshAuthenticatedSession(PDO $pdo): bool {
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, role
         FROM users
         WHERE id = :id AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user) ||
        !is_string($user['username'] ?? null) ||
        !is_string($user['full_name'] ?? null) ||
        !is_string($user['role'] ?? null)) {
        return false;
    }

    $role = strtolower(trim($user['role']));
    if (!in_array($role, ['admin', 'librarian'], true)) {
        return false;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $role;

    return true;
}

function requireLogin(): void {
    if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }

    global $pdo;
    if (!$pdo instanceof PDO) {
        http_response_code(503);
        exit('Authentication is temporarily unavailable. Please try again later.');
    }

    try {
        if (refreshAuthenticatedSession($pdo)) {
            return;
        }
    } catch (PDOException $exception) {
        error_log('Authenticated session validation failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('Authentication is temporarily unavailable. Please try again later.');
    }

    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: index.php');
    exit;
}

function setFlash(string $message, bool $isLockout = false, ?int $attemptCount = null): void {
    $_SESSION['flash_error'] = $message;
    $_SESSION['flash_locked'] = $isLockout;
    $_SESSION['flash_attempts'] = $attemptCount;
}

/**
 * @return array{message: ?string, locked: bool, attempts: ?int}
 */
function getFlash(): array {
    if (!empty($_SESSION['flash_error'])) {
        $message = $_SESSION['flash_error'];
        $attempts = $_SESSION['flash_attempts'] ?? null;
        $result = [
            'message' => is_string($message) ? $message : null,
            'locked' => !empty($_SESSION['flash_locked']),
            'attempts' => is_int($attempts) ? $attempts : null,
        ];
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
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                throw new RuntimeException('Unable to read the environment configuration file.');
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                $parts = explode('=', $line, 2);
                if (count($parts) !== 2) continue;
                [$name, $setting] = $parts;
                $env[trim($name)] = trim($setting, " \t\r\n\"");
            }
        }
    }
    return $env[$key] ?? $fallback;
}

function isValidCalendarDate(string $date): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $matches) !== 1) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

function createPasswordResetToken(PDO $pdo, int $userId): string {
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id OR expires_at < NOW()')->execute([':user_id' => $userId]);
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
    $stmt->execute([':user_id' => $userId, ':token_hash' => hash('sha256', $token)]);
    return $token;
}

function allowPasswordResetRequest(PDO $pdo, string $email, string $ip): bool {
    $now = time();
    $requestedAt = gmdate('Y-m-d H:i:s', $now);
    $windowStart = gmdate('Y-m-d H:i:s', $now - PASSWORD_RESET_RATE_WINDOW_SECONDS);
    $ipHash = hash('sha256', $ip);
    $emailHash = hash('sha256', strtolower(trim($email)));

    $pdo->prepare('DELETE FROM password_reset_requests WHERE requested_at < :retention_cutoff')
        ->execute([':retention_cutoff' => gmdate('Y-m-d H:i:s', $now - 86400)]);

    $ipCount = $pdo->prepare(
        'SELECT COUNT(*) FROM password_reset_requests
         WHERE ip_hash = :ip_hash AND requested_at >= :window_start'
    );
    $ipCount->execute([':ip_hash' => $ipHash, ':window_start' => $windowStart]);
    if ((int) $ipCount->fetchColumn() >= PASSWORD_RESET_IP_REQUEST_LIMIT) {
        return false;
    }

    $emailCount = $pdo->prepare(
        'SELECT COUNT(*) FROM password_reset_requests
         WHERE email_hash = :email_hash AND requested_at >= :window_start'
    );
    $emailCount->execute([':email_hash' => $emailHash, ':window_start' => $windowStart]);
    if ((int) $emailCount->fetchColumn() >= PASSWORD_RESET_EMAIL_REQUEST_LIMIT) {
        return false;
    }

    $record = $pdo->prepare(
        'INSERT INTO password_reset_requests (email_hash, ip_hash, requested_at)
         VALUES (:email_hash, :ip_hash, :requested_at)'
    );
    $record->execute([
        ':email_hash' => $emailHash,
        ':ip_hash' => $ipHash,
        ':requested_at' => $requestedAt,
    ]);

    return true;
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
        $mail->setFrom(environmentValue('SMTP_FROM_EMAIL', $mail->Username), environmentValue('SMTP_FROM_NAME', 'LCARS'));
        $mail->addAddress($recipient, $fullName);
        $mail->isHTML(true);

        $emailMeta = $emailType === 'reset'
            ? [
                'subject' => 'Reset your LCARS password',
                'eyebrow' => 'ACCOUNT SECURITY',
                'heading' => 'Reset your password',
                'intro' => 'We received a request to reset the password for your LCARS account.',
                'buttonLabel' => 'Reset password',
                'closing' => 'If you did not request a password reset, you can safely ignore this email.',
                'altBody' => "Hello {$fullName},\n\nWe received a request to reset your LCARS password. Reset it here: {$resetUrl}\n\nThis link expires in one hour. If you did not request a password reset, you can safely ignore this email.",
                'extraRow' => '',
            ]
            : [
                'subject' => 'Set up your LCARS account',
                'eyebrow' => 'WELCOME TO LCARS',
                'heading' => 'Your account is ready',
                'intro' => 'An LCARS account has been created for you. Set a password to get started.',
                'buttonLabel' => 'Set your password',
                'closing' => 'For your security, this setup link expires in one hour.',
                'altBody' => "Hello {$fullName},\n\nYour LCARS account is ready. Username: {$username}\n\nSet your password here: {$resetUrl}\n\nFor your security, this setup link expires in one hour.",
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
                    <div style="color:#fbbf24;font-size:12px;font-weight:bold;letter-spacing:2px;">LCARS</div>
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
                <tr><td style="border-top:1px solid #e2e8f0;padding:22px 40px;text-align:center;color:#94a3b8;font-size:12px;line-height:1.6;">This is an automated message from LCARS.<br>Please do not reply to this email.</td></tr>
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

function getCopyrightYearMetrics(PDO $pdo): array {
    try {
        $stmt = $pdo->query('CALL sp_get_copyright_year_metrics()');
        try {
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $stmt->closeCursor();
        }
    } catch (PDOException $exception) {
        $mysqlErrorCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
        if (!in_array($mysqlErrorCode, [1305, 1370], true)) {
            throw $exception;
        }

        error_log(
            'Copyright-year metrics procedure is unavailable to the application account; '
            . 'using the equivalent query until schema privileges are updated. MySQL error '
            . $mysqlErrorCode . '.'
        );
    }

    $stmt = $pdo->query(
        'SELECT r.range_id, r.years_threshold, COUNT(b.book_id) AS title_count
         FROM copyright_year_ranges r
         LEFT JOIN books b
           ON b.deleted_at IS NULL
                    AND COALESCE(b.copyright_year, b.publication_year) BETWEEN YEAR(CURRENT_DATE) - r.years_threshold AND YEAR(CURRENT_DATE)
         WHERE r.is_active = 1
         GROUP BY r.range_id, r.years_threshold, r.sort_order
         ORDER BY r.sort_order, r.years_threshold'
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getReferenceAgePolicy(PDO $pdo): array
{
    $configuration = $pdo->query(
        'SELECT reference_policy_direction, reference_policy_years, reference_policy_source, reference_policy_effective_date
         FROM library_configuration WHERE id = 1'
    )->fetch(PDO::FETCH_ASSOC);

    if ($configuration === false) {
        throw new PDOException('Reference-age policy settings are unavailable.');
    }

    $direction = $configuration['reference_policy_direction'];
    $years = $configuration['reference_policy_years'];
    if (!in_array($direction, ['within', 'at_least'], true) || $years === null || (int) $years < 1) {
        return [
            'direction' => null,
            'years' => null,
            'source' => null,
            'effective_date' => null,
        ];
    }

    return [
        'direction' => $direction,
        'years' => (int) $years,
        'source' => $configuration['reference_policy_source'],
        'effective_date' => $configuration['reference_policy_effective_date'],
    ];
}

function assessReferenceAge(?int $publicationYear, array $policy, ?int $currentYear = null): array
{
    $currentYear ??= (int) date('Y');
    if ($publicationYear === null) {
        return [
            'status' => 'missing_publication_data',
            'label' => 'Missing Publication Data',
            'age' => null,
            'threshold' => $policy['years'] ?? null,
            'policy_direction' => $policy['direction'] ?? null,
            'explanation' => 'A publication year is not recorded, so the reference age cannot be assessed.',
        ];
    }

    if ($publicationYear < 1450 || $publicationYear > $currentYear) {
        return [
            'status' => 'needs_verification',
            'label' => 'Needs Verification',
            'age' => null,
            'threshold' => $policy['years'] ?? null,
            'policy_direction' => $policy['direction'] ?? null,
            'explanation' => 'The recorded publication year is outside the valid range and should be checked.',
        ];
    }

    $age = $currentYear - $publicationYear;
    $direction = $policy['direction'] ?? null;
    $threshold = $policy['years'] ?? null;
    if (!in_array($direction, ['within', 'at_least'], true) || !is_int($threshold) || $threshold < 1) {
        return [
            'status' => 'needs_verification',
            'label' => 'Needs Verification',
            'age' => $age,
            'threshold' => null,
            'policy_direction' => null,
            'explanation' => 'The institution’s reference-age policy has not been configured.',
        ];
    }

    $meetsPolicy = $direction === 'within' ? $age <= $threshold : $age >= $threshold;
    $yearsUntilOutsidePolicy = $threshold + 1 - $age;
    $approachingPolicyLimit = $direction === 'within'
        && $meetsPolicy
        && $yearsUntilOutsidePolicy >= 1
        && $yearsUntilOutsidePolicy <= 2;
    $policyDescription = $direction === 'within'
        ? "published within the last {$threshold} years"
        : "at least {$threshold} years old";
    $status = $approachingPolicyLimit
        ? 'approaching_policy_limit'
        : ($meetsPolicy ? 'meets_policy' : 'outside_policy');
    $label = $approachingPolicyLimit
        ? 'Approaching Policy Limit'
        : ($meetsPolicy ? 'Meets Policy' : 'Outside Policy');
    $explanation = $approachingPolicyLimit
        ? "The book currently meets policy but is expected to fall outside it in {$yearsUntilOutsidePolicy} " . ($yearsUntilOutsidePolicy === 1 ? 'year' : 'years') . '. Review it before then.'
        : ($meetsPolicy
            ? "The book is {$policyDescription}, as required by the configured reference-age policy."
            : "The book is not {$policyDescription}, as required by the configured reference-age policy.");

    return [
        'status' => $status,
        'label' => $label,
        'age' => $age,
        'threshold' => $threshold,
        'policy_direction' => $direction,
        'explanation' => $explanation,
    ];
}
