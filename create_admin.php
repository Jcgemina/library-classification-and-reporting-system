<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

if (!$pdo) {
    echo "Error: {$dbConnectionError}\n";
    exit(1);
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            role VARCHAR(30) DEFAULT 'librarian',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username_time (username, attempted_at),
            INDEX idx_ip_time (ip_address, attempted_at)
        ) ENGINE=InnoDB
    ");
} catch (PDOException $e) {
    echo "Database bootstrap failed: " . $e->getMessage() . "\n";
    exit(1);
}

$defaultCredentialAccounts = $pdo->query(
    "SELECT id, username, password FROM users WHERE role IN ('admin', 'librarian')"
)->fetchAll();

$deactivateDefaultCredential = $pdo->prepare(
    'UPDATE users SET is_active = 0 WHERE id = :id AND role IN (\'admin\', \'librarian\')'
);
$deactivatedUsernames = [];

foreach ($defaultCredentialAccounts as $account) {
    $hash = $account['password'] ?? null;
    if (is_string($hash) && password_verify('123', $hash)) {
        $deactivateDefaultCredential->execute([':id' => (int) $account['id']]);
        $deactivatedUsernames[] = (string) $account['username'];
    }
}

$existingAdmin = $pdo->query(
    "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1"
)->fetch();

if ($existingAdmin !== false) {
    echo "Refusing to change credentials: an active administrator already exists.\n";
    exit(1);
}

$username = trim((string) (getenv('ADMIN_USERNAME') ?: 'admin'));
$fullName = trim((string) (getenv('ADMIN_FULL_NAME') ?: 'System Admin'));

if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
    echo "Invalid ADMIN_USERNAME. Use 3-50 letters, numbers, dots, underscores, or hyphens.\n";
    exit(1);
}

if ($fullName === '' || strlen($fullName) > 100) {
    echo "Invalid ADMIN_FULL_NAME. Use a name of 1-100 characters.\n";
    exit(1);
}

$existingUsername = $pdo->prepare('SELECT id, is_active FROM users WHERE username = :username LIMIT 1');
$existingUsername->execute([':username' => $username]);
$existingAccount = $existingUsername->fetch();
if ($existingAccount !== false && (int) $existingAccount['is_active'] === 1) {
    echo "Refusing to overwrite credentials: that username already exists.\n";
    exit(1);
}

$password = bin2hex(random_bytes(32)) . '!A9';
if ($existingAccount !== false) {
    $stmt = $pdo->prepare(
        'UPDATE users SET password = :password, full_name = :full_name, role = :role, is_active = 1 WHERE id = :id'
    );
    $stmt->execute([
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':full_name' => $fullName,
        ':role' => 'admin',
        ':id' => (int) $existingAccount['id'],
    ]);
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password, full_name, role, is_active)
         VALUES (:username, :password, :full_name, :role, 1)'
    );
    $stmt->execute([
        ':username' => $username,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':full_name' => $fullName,
        ':role' => 'admin',
    ]);
}

echo "Administrator created securely.\n";
if ($deactivatedUsernames !== []) {
    echo "Deactivated accounts using the known weak password: " . implode(', ', $deactivatedUsernames) . "\n";
}
echo "Username: {$username}\n";
echo "Password: {$password}\n";
echo "Rotate this password immediately after the first login.\n";
