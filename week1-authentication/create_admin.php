<?php
/**
 * Run this once from the command line to create a sample librarian account:
 *   php create_admin.php
 *
 * This makes sure the password is always properly hashed with password_hash()
 * instead of a hand-typed / copy-pasted hash.
 */

require_once __DIR__ . '/config/db.php';

if (!$pdo) {
    echo "Error: {$dbConnectionError}\n";
    exit(1);
}

$username = 'librarian1';
$plainPassword = 'Librarian123!'; // change this before running in production
$fullName = 'Juan Dela Cruz';

$hashed = password_hash($plainPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    "INSERT INTO users (username, password, full_name, role)
     VALUES (:username, :password, :full_name, 'librarian')
     ON DUPLICATE KEY UPDATE password = :password2, full_name = :full_name2"
);
$stmt->execute([
    ':username'   => $username,
    ':password'   => $hashed,
    ':full_name'  => $fullName,
    ':password2'  => $hashed,
    ':full_name2' => $fullName,
]);

echo "Account ready!\n";
echo "Username: {$username}\n";
echo "Password: {$plainPassword}\n";
