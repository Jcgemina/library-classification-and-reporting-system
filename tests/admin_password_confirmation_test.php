<?php
require_once __DIR__ . '/../includes/user_service.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('The administrator password confirmation test requires the PDO SQLite driver.');
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(
    'CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        role TEXT NOT NULL,
        is_active INTEGER NOT NULL,
        password TEXT NOT NULL
    )'
);

$insert = $pdo->prepare('INSERT INTO users (id, role, is_active, password) VALUES (?, ?, ?, ?)');
$insert->execute([1, 'admin', 1, password_hash('correct-password', PASSWORD_DEFAULT)]);
$insert->execute([2, 'librarian', 1, password_hash('librarian-password', PASSWORD_DEFAULT)]);
$insert->execute([3, 'admin', 0, password_hash('inactive-password', PASSWORD_DEFAULT)]);

if (!userHasAdminPassword($pdo, 1, 'correct-password')) {
    throw new RuntimeException('An active administrator should be able to confirm their password.');
}
foreach ([
    [1, 'incorrect-password'],
    [1, ''],
    [2, 'librarian-password'],
    [3, 'inactive-password'],
] as [$userId, $password]) {
    if (userHasAdminPassword($pdo, $userId, $password)) {
        throw new RuntimeException('Only the correct password for the active administrator should be accepted.');
    }
}

echo "Administrator password confirmation tests passed.\n";
