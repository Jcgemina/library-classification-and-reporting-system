<?php
require_once __DIR__ . '/config/db.php';

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

$accounts = [
    ['username' => 'admin', 'password' => '123', 'full_name' => 'System Admin', 'role' => 'admin'],
    ['username' => 'librarian1', 'password' => '123', 'full_name' => 'Maria Santos', 'role' => 'librarian'],
    ['username' => 'librarian2', 'password' => '123', 'full_name' => 'John Medina', 'role' => 'librarian'],
    ['username' => 'librarian3', 'password' => '123', 'full_name' => 'Angela Cruz', 'role' => 'librarian'],
];

$stmt = $pdo->prepare(
    "INSERT INTO users (username, password, full_name, role, is_active)
     VALUES (:username, :password, :full_name, :role, 1)
     ON DUPLICATE KEY UPDATE password = VALUES(password), full_name = VALUES(full_name), role = VALUES(role), is_active = VALUES(is_active)"
);

foreach ($accounts as $account) {
    $stmt->execute([
        ':username' => $account['username'],
        ':password' => password_hash($account['password'], PASSWORD_DEFAULT),
        ':full_name' => $account['full_name'],
        ':role' => $account['role'],
    ]);
}

echo "Database ready. Default accounts are loaded.\n";
foreach ($accounts as $account) {
    echo "Username: {$account['username']} | Password: {$account['password']} | Role: {$account['role']}\n";
}
