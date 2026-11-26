<?php

require_once __DIR__ . '/functions.php';

function queueWorkersRunInBackground(bool $runAiWorker = true, bool $runMailWorker = true): void
{
    if (!function_exists('popen') || !function_exists('pclose')) {
        error_log('Queue workers could not be started: popen and pclose must be enabled.');
        return;
    }

    $phpBinary = environmentValue('QUEUE_WORKER_PHP_BINARY', PHP_BINARY);
    if (PHP_OS_FAMILY === 'Windows' && strtolower(basename($phpBinary)) === 'php-cgi.exe') {
        $cliBinary = dirname($phpBinary) . DIRECTORY_SEPARATOR . 'php.exe';
        if (is_file($cliBinary)) {
            $phpBinary = $cliBinary;
        }
    }

    if (!is_file($phpBinary)) {
        error_log('Queue workers could not be started: the PHP CLI binary was not found.');
        return;
    }

    $root = dirname(__DIR__);
    $workers = [];
    if ($runAiWorker) {
        $workers[] = 'bin/ai_worker.php';
    }
    if ($runMailWorker) {
        $workers[] = 'process_mail_queue.php';
    }

    foreach ($workers as $worker) {
        $scriptPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $worker);
        if (!is_file($scriptPath)) {
            error_log('Queue worker script was not found: ' . $worker);
            continue;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start "" /B ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' >NUL 2>&1';
        } else {
            $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' >/dev/null 2>&1 &';
        }

        try {
            $process = popen($command, 'r');
            if (!is_resource($process) || pclose($process) !== 0) {
                error_log('Queue worker launch failed: ' . $worker);
            }
        } catch (Throwable $exception) {
            error_log('Queue worker launch failed: ' . $worker . ' (' . $exception::class . ')');
        }
    }
}
