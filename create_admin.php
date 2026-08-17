<?php
require_once __DIR__ . '/config/db.php';

if (!$pdo) {
    echo "Error: {$dbConnectionError}\n";
    exit(1);
}

$username = 'admin'; // change this before running in production
$plainPassword = '123'; // change this before running in production
$fullName = 'Admin';
$role = 'admin';

$hashed = password_hash($plainPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    "INSERT INTO users (username, password, full_name, role)
     VALUES (:username, :password, :full_name, :role)
     ON DUPLICATE KEY UPDATE password = :password2, full_name = :full_name2"
);
$stmt->execute([
    ':username'   => $username,
    ':password'   => $hashed,
    ':full_name'  => $fullName,
    ':role'        => $role,
    ':password2'  => $hashed,
    ':full_name2' => $fullName,
]);

echo "Account ready!\n";
echo "Username: {$username}\n";
echo "Password: {$plainPassword}\n";
