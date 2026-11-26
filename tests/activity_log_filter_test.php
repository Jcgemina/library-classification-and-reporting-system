<?php
require_once __DIR__ . '/../includes/log_helpers.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE activity_logs (created_at TEXT, user_id INTEGER, action TEXT, description TEXT, ip_address TEXT)');
$pdo->exec('CREATE TABLE security_logs (created_at TEXT, user_id INTEGER, username TEXT, event_type TEXT, severity TEXT, description TEXT, ip_address TEXT)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)');
$pdo->exec("INSERT INTO users (id, full_name) VALUES (1, 'System Admin')");
$pdo->exec("INSERT INTO activity_logs (created_at, user_id, action, description, ip_address) VALUES ('2026-11-25 11:57:00', 1, 'delete_user', 'Deleted a test user', '::1')");
$pdo->exec("INSERT INTO security_logs (created_at, user_id, username, event_type, severity, description, ip_address) VALUES ('2026-11-25 11:57:00', 1, 'admin', 'user_deleted', 'warning', 'Deleted a test user', '::1')");

$assertMatchCount = static function (string $alias, string $search, int $expected, string $severity = '') use ($pdo): void {
    $table = $alias === 'a' ? 'activity_logs' : 'security_logs';
    [$where, $params] = logFilterParts($alias, $search, null, $severity, '2026-11-25 00:00:00', '2026-11-26 00:00:00');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} {$alias} LEFT JOIN users u ON u.id = {$alias}.user_id WHERE {$where}");
    $stmt->execute($params);
    $actual = (int) $stmt->fetchColumn();

    if ($actual !== $expected) {
        throw new RuntimeException("Search '{$search}' on {$table} expected {$expected} match(es), received {$actual}.");
    }
};

$assertMatchCount('a', 'Delete User', 1);
$assertMatchCount('a', 'delete_user', 1);
$assertMatchCount('a', 'test user', 1);
$assertMatchCount('a', 'no matching entry', 0);
$assertMatchCount('s', 'User Deleted', 1, 'warning');
$assertMatchCount('s', 'user_deleted', 1);

echo "Audit-log search tests passed.\n";
