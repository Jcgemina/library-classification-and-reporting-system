<?php
/**
 * Database connection using PDO (as required — no mysqli).
 * Edit the credentials below to match your local MySQL setup.
 */

$DB_HOST = 'localhost';
$DB_NAME = 'appsys_library';
$DB_USER = 'root';
$DB_PASS = '';

// $pdo stays null and $dbConnectionError gets set if the connection fails.
// Pages that include this file should check `if (!$pdo)` and show
// $dbConnectionError to the user instead of letting the script die().
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
    // Non-functional requirement: don't leak DB details to the user
    error_log('DB connection error: ' . $e->getMessage());
    $dbConnectionError = 'Sorry, the system is temporarily unavailable. Please try again later.';
}
