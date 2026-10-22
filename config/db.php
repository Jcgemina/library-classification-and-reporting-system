<?php
$envFile = dirname(__DIR__) . '/.env';
$environment = [];
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read the environment configuration file.');
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$name, $value] = $parts;
        $name = trim($name);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
            $environment[$name] = trim($value, " \t\r\n\"");
        }
    }
}
$getConfig = static function (string $key, string $default = '') use ($environment): string {
    $value = getenv($key);
    return $value !== false ? $value : ($environment[$key] ?? $default);
};

$DB_HOST = $getConfig('DB_HOST', 'localhost');
$DB_NAME = $getConfig('DB_NAME');
$DB_USER = $getConfig('DB_USER');
$DB_PASS = $getConfig('DB_PASS');

const MAIL_FROM = 'no-reply@library.local';

$pdo = null;
$dbConnectionError = null;

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // use real prepared statements
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection error: ' . $e->getMessage());
    $dbConnectionError = 'Sorry, the system is temporarily unavailable. Please try again later.';
}

if ($pdo === null && $dbConnectionError === null) {
    throw new RuntimeException('Database initialization did not produce a connection or an error.');
}
