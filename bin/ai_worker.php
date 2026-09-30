<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ai_course_service.php';

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}

try {
    $config = aiCourseAssertConfigured();
} catch (AiCourseException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

$batchSize = max(1, min(25, (int)environmentValue('AI_COURSE_WORKER_BATCH_SIZE', '5')));
$processed = 0;

while ($processed < $batchSize) {
    try {
        $job = aiCourseClaimJob($pdo);
    } catch (Throwable $exception) {
        error_log('AI course worker could not claim a job: ' . $exception::class);
        exit(1);
    }

    if (!$job) {
        break;
    }

    $processed++;
    try {
        aiCourseProcessJob($pdo, $job, $config);
        fwrite(STDOUT, 'Processed AI job ' . (int)$job['id'] . PHP_EOL);
    } catch (AiCourseException $exception) {
        aiCourseRecordJobFailure($pdo, $job, $exception, $config);
        error_log('AI course job ' . (int)$job['id'] . ' failed: ' . $exception->errorKey);
    } catch (Throwable $exception) {
        $safeFailure = new AiCourseException('internal_error', 'AI job failed unexpectedly.', true);
        aiCourseRecordJobFailure($pdo, $job, $safeFailure, $config);
        error_log('AI course job ' . (int)$job['id'] . ' failed with ' . $exception::class);
    }
}

fwrite(STDOUT, 'AI worker finished; jobs processed: ' . $processed . PHP_EOL);
