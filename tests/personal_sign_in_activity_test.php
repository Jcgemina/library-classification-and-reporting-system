<?php
require_once __DIR__ . '/../includes/functions.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('The personal sign-in activity test requires the PDO SQLite driver.');
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(
    'CREATE TABLE security_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        event_type TEXT NOT NULL,
        ip_address TEXT,
        created_at TEXT NOT NULL
    )'
);

$insert = $pdo->prepare(
    'INSERT INTO security_logs (user_id, event_type, ip_address, created_at)
     VALUES (:user_id, :event_type, :ip_address, :created_at)'
);
for ($event = 0; $event < 12; $event++) {
    $insert->execute([
        ':user_id' => 7,
        ':event_type' => $event === 0 ? 'login_failed' : 'login_success',
        ':ip_address' => '192.0.2.' . ($event + 1),
        ':created_at' => sprintf('2026-11-%02d 12:00:00', 24 - $event),
    ]);
}
$insert->execute([
    ':user_id' => 8,
    ':event_type' => 'login_success',
    ':ip_address' => '198.51.100.8',
    ':created_at' => '2026-11-25 12:00:00',
]);
$insert->execute([
    ':user_id' => 7,
    ':event_type' => 'logout',
    ':ip_address' => '192.0.2.99',
    ':created_at' => '2026-11-26 12:00:00',
]);

$events = getRecentSignInEvents($pdo, 7);
if (count($events) !== 10) {
    throw new RuntimeException('Sign-in activity should return no more than the 10 most recent events.');
}
if ($events[0]['ip_address'] !== '192.0.2.1' || $events[9]['ip_address'] !== '192.0.2.10') {
    throw new RuntimeException('Sign-in activity should be ordered newest-first and exclude events outside the 10-event window.');
}
if (count(array_filter($events, static fn(array $event): bool => $event['event_type'] === 'login_failed')) !== 1) {
    throw new RuntimeException('Failed sign-in attempts should be included in the personal activity history.');
}
if (array_filter($events, static fn(array $event): bool => $event['ip_address'] === '198.51.100.8' || $event['event_type'] === 'logout')) {
    throw new RuntimeException('Personal sign-in activity must exclude other users and non-sign-in events.');
}

try {
    getRecentSignInEvents($pdo, 0);
    throw new RuntimeException('A missing user ID must not be used to retrieve sign-in activity.');
} catch (InvalidArgumentException $exception) {
    // Expected: history must always be scoped to an authenticated user.
}

echo "Personal sign-in activity tests passed.\n";
