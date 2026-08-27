<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!$pdo) exit("Database unavailable.\n");

$jobs = $pdo->query("SELECT id, recipient, full_name, username, reset_token, email_type FROM email_queue WHERE sent_at IS NULL AND attempts < 5 AND available_at <= NOW() ORDER BY id LIMIT 10")->fetchAll();

foreach ($jobs as $job) {
    $claim = $pdo->prepare('UPDATE email_queue SET attempts = attempts + 1, available_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = :id AND sent_at IS NULL AND attempts < 5');
    $claim->execute([':id' => $job['id']]);
    if ($claim->rowCount() !== 1) continue;

    $sent = sendPasswordSetupEmail($job['recipient'], $job['full_name'], $job['username'], $job['reset_token'], $job['email_type']);
    if ($sent) {
        $stmt = $pdo->prepare('UPDATE email_queue SET sent_at = NOW(), last_error = NULL WHERE id = :id');
        $stmt->execute([':id' => $job['id']]);
        echo "Sent job {$job['id']}\n";
    } else {
        $stmt = $pdo->prepare("UPDATE email_queue SET last_error = 'SMTP delivery failed' WHERE id = :id");
        $stmt->execute([':id' => $job['id']]);
        echo "Retry scheduled for job {$job['id']}\n";
    }
}
