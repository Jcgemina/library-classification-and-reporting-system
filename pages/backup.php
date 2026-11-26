<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_service.php';
require_once __DIR__ . '/../includes/backup_dump.php';

requireLogin();

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

function backupJsonResponse(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

function backupMysqlClient(string $name): ?string {
    $configuredPath = environmentValue(strtoupper($name) . '_PATH');
    if (is_string($configuredPath) && $configuredPath !== '' && is_file($configuredPath)) {
        return $configuredPath;
    }

    $binaryName = PHP_OS_FAMILY === 'Windows' ? $name . '.exe' : $name;
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) {
        if ($directory === '') {
            continue;
        }
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binaryName;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $roots = [dirname(__DIR__), dirname(PHP_BINARY)];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $roots[] = (string)$_SERVER['DOCUMENT_ROOT'];
        }
        foreach (array_unique($roots) as $root) {
            for ($depth = 0; $depth < 8; $depth++) {
                $standaloneCandidate = $root . DIRECTORY_SEPARATOR . 'MySQL'
                    . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binaryName;
                if (is_file($standaloneCandidate)) {
                    return $standaloneCandidate;
                }
                $pattern = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql'
                    . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binaryName;
                $candidates = glob($pattern);
                if ($candidates !== false && $candidates !== []) {
                    rsort($candidates, SORT_NATURAL);
                    return $candidates[0];
                }
                $parent = dirname($root);
                if ($parent === $root) {
                    break;
                }
                $root = $parent;
            }
        }
    }

    return null;
}

function backupMysqlOptionsFile(string $directory, string $dbUser, string $dbPass): string {
    global $DB_HOST, $DB_PORT;
    $escapeOption = static fn (string $value): string => '"' . str_replace(
        ["\\", '"', "\n", "\r", "\t"],
        ["\\\\", '\\"', '\\n', '\\r', '\\t'],
        $value
    ) . '"';
    $path = $directory . DIRECTORY_SEPARATOR . '.mysql-client.cnf';
    $contents = "[client]\n"
        . 'host=' . $escapeOption((string)$DB_HOST) . "\n"
        . 'port=' . $escapeOption((string)$DB_PORT) . "\n"
        . 'user=' . $escapeOption($dbUser) . "\n"
        . 'password=' . $escapeOption($dbPass) . "\n";
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Could not write temporary MySQL client settings.');
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($path, 0600);
    }
    return $path;
}

function backupRunMysql(array $command, ?string $inputFile = null, ?callable $toleratedErrors = null): void {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the MySQL client.');
    }

    if ($inputFile !== null) {
        $input = fopen($inputFile, 'rb');
        if ($input === false) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Could not read the database dump.');
        }
        $copied = stream_copy_to_stream($input, $pipes[0]);
        fclose($input);
        if ($copied === false) {
            proc_terminate($process);
        }
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $details = trim((string)$errors . "\n" . (string)$output);
    if ($toleratedErrors !== null) {
        if (!$toleratedErrors($details)) {
            throw new RuntimeException($details !== '' ? $details : 'The MySQL client returned an error.');
        }
        if ($details !== '') {
            error_log('Restore import completed with objects preserved: ' . $details);
        }
        return;
    }

    if ($exitCode !== 0 || (isset($copied) && $copied === false)) {
        throw new RuntimeException($details !== '' ? $details : 'The MySQL client returned an error.');
    }
}

function backupCopyDirectory(string $source, string $destination): void {
    if (is_link($source)) {
        throw new RuntimeException('Symbolic links in the uploads directory cannot be backed up.');
    }
    if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
        throw new RuntimeException('Could not create a temporary uploads directory.');
    }

    if (!is_dir($source)) {
        return;
    }

    foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) {
        $target = $destination . DIRECTORY_SEPARATOR . $entry->getFilename();
        if ($entry->isLink()) {
            throw new RuntimeException('Symbolic links in the uploads directory cannot be backed up.');
        }
        if ($entry->isDir()) {
            backupCopyDirectory($entry->getPathname(), $target);
        } elseif ($entry->isFile() && !copy($entry->getPathname(), $target)) {
            throw new RuntimeException('Could not copy an uploaded file into the backup.');
        }
    }
}

function backupRemovePath(string $path): void {
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Could not remove a temporary backup file.');
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        backupRemovePath($entry->getPathname());
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Could not remove a temporary backup directory.');
    }
}

function backupArchiveEntryPath(string $entryName): string {
    $normalized = str_replace('\\', '/', $entryName);
    $trimmed = rtrim($normalized, '/');
    if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1) {
        throw new RuntimeException('The backup contains an unsafe archive path.');
    }

    $segments = explode('/', $trimmed);
    if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        throw new RuntimeException('The backup contains an unsafe archive path.');
    }
    if ($segments[0] !== 'database.sql' && $segments[0] !== 'uploads') {
        throw new RuntimeException('The backup contains an unexpected file.');
    }
    if ($segments[0] === 'database.sql' && count($segments) !== 1) {
        throw new RuntimeException('The backup contains an invalid database dump path.');
    }

    return implode(DIRECTORY_SEPARATOR, $segments);
}

function backupInspectArchive(ZipArchive $zip): array {
    $hasDatabase = false;
    $hasUploads = false;
    $seen = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entryName = $zip->getNameIndex($index);
        if (!is_string($entryName)) {
            throw new RuntimeException('The backup contains an unreadable archive entry.');
        }
        backupArchiveEntryPath($entryName);
        $normalized = str_replace('\\', '/', rtrim($entryName, '/'));
        if (isset($seen[$normalized])) {
            throw new RuntimeException('The backup contains duplicate archive entries.');
        }
        $seen[$normalized] = true;

        if ($normalized === 'database.sql') {
            $stats = $zip->statIndex($index);
            $hasDatabase = is_array($stats) && ($stats['size'] ?? 0) > 0 && !str_ends_with($entryName, '/');
        } elseif (str_starts_with($normalized, 'uploads/')) {
            $hasUploads = true;
        } elseif ($normalized === 'uploads' && str_ends_with($entryName, '/')) {
            $hasUploads = true;
        }
    }

    if (!$hasDatabase) {
        throw new RuntimeException('Backup is missing a database dump.');
    }
    if (!$hasUploads) {
        throw new RuntimeException('Backup is missing its uploaded files directory.');
    }

    return ['has_database' => true, 'has_files' => true];
}

function backupExtractArchive(ZipArchive $zip, string $destination): void {
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entryName = $zip->getNameIndex($index);
        if (!is_string($entryName)) {
            throw new RuntimeException('The backup contains an unreadable archive entry.');
        }
        $relativePath = backupArchiveEntryPath($entryName);
        $target = $destination . DIRECTORY_SEPARATOR . $relativePath;

        if (str_ends_with(str_replace('\\', '/', $entryName), '/')) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new RuntimeException('Could not create a directory while extracting the backup.');
            }
            continue;
        }

        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Could not create a directory while extracting the backup.');
        }
        $input = $zip->getStream($entryName);
        $output = fopen($target, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Could not extract a file from the backup.');
        }
        $copied = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        if ($copied === false) {
            throw new RuntimeException('Could not extract a file from the backup.');
        }
    }
}

$csrfToken = $_SESSION['backup_csrf'] ?? '';

if (!$pdo instanceof PDO) {
    backupJsonResponse(['success' => false, 'message' => 'Database unavailable.'], 503);
}

$backupAction = requestString($_REQUEST, 'action');
$backupTimezone = new DateTimeZone('Asia/Manila');
$backupDir = environmentValue(
    'BACKUP_DIR',
    dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . basename(dirname(__DIR__)) . '-backups'
);
$documentRoot = realpath(requestString($_SERVER, 'DOCUMENT_ROOT'));
if ($documentRoot === false) {
    backupJsonResponse(['success' => false, 'message' => 'The web document root is unavailable.'], 503);
}

if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        backupJsonResponse(['success' => false, 'message' => 'Backup storage is unavailable.'], 503);
    }
}
if (PHP_OS_FAMILY !== 'Windows' && !chmod($backupDir, 0700)) {
    backupJsonResponse(['success' => false, 'message' => 'Backup storage permissions could not be secured.'], 503);
}

$backupRealPath = realpath($backupDir);
if ($backupRealPath === false) {
    backupJsonResponse(['success' => false, 'message' => 'Backup storage is unavailable.'], 503);
}
$backupPathPrefix = rtrim($backupRealPath, '/\\') . DIRECTORY_SEPARATOR;
$documentRootPrefix = rtrim($documentRoot, '/\\') . DIRECTORY_SEPARATOR;
if (PHP_OS_FAMILY === 'Windows') {
    $backupPathPrefix = strtolower($backupPathPrefix);
    $documentRootPrefix = strtolower($documentRootPrefix);
}
if ($backupPathPrefix === $documentRootPrefix || str_starts_with($backupPathPrefix, $documentRootPrefix)) {
    backupJsonResponse(['success' => false, 'message' => 'Backup storage must be outside the web document root.'], 503);
}

if (empty($backupAction)) {
    backupJsonResponse(['success' => false, 'message' => 'No action specified.'], 400);
}

// List backups (GET)
if ($backupAction === 'list') {
    try {
        $backups = [];
        if (is_dir($backupDir)) {
            $files = glob($backupDir . DIRECTORY_SEPARATOR . 'backup_*.zip') ?: [];
            usort($files, static function (string $left, string $right): int {
                $leftModified = filemtime($left);
                $rightModified = filemtime($right);
                return ($rightModified === false ? 0 : $rightModified) <=> ($leftModified === false ? 0 : $leftModified);
            });
            foreach ($files as $file) {
                $baseName = basename($file);
                if (preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(?:_[a-f0-9]{8})?\.zip$/', $baseName) === 1) {
                    $timestamp = filemtime($file);
                    $fileSize = filesize($file);
                    if ($timestamp === false || $fileSize === false) {
                        continue;
                    }
                    $size = $fileSize > 1048576 ? round($fileSize / 1048576, 1) . ' MB' : round($fileSize / 1024, 1) . ' KB';

                    $backups[] = [
                        'filename' => $baseName,
                        'created' => (new DateTimeImmutable('@' . $timestamp))
                            ->setTimezone($backupTimezone)
                            ->format('M d, Y · H:i'),
                        'size' => $size,
                        'status' => 'Complete',
                        'contents' => 'Database + uploaded files'
                    ];
                }
            }
        }
        backupJsonResponse(['success' => true, 'backups' => $backups]);
    } catch (Throwable $e) {
        error_log('Backup listing failed: ' . $e->getMessage());
        backupJsonResponse(['success' => false, 'message' => 'Backup history could not be loaded. Refresh the page to try again.'], 500);
    }
}

// Download backup (GET)
if ($backupAction === 'download') {
    try {
        $filename = basename(requestString($_GET, 'file'));
        if (preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(?:_[a-f0-9]{8})?\.zip$/', $filename) !== 1) {
            backupJsonResponse(['success' => false, 'message' => 'Backup not found.'], 404);
        }
        $file = $backupDir . DIRECTORY_SEPARATOR . $filename;
        $backupRealPath = realpath($backupDir);
        $fileRealPath = realpath($file);

        if ($backupRealPath === false || $fileRealPath === false || !str_starts_with($fileRealPath, $backupRealPath . DIRECTORY_SEPARATOR)) {
            backupJsonResponse(['success' => false, 'message' => 'Backup not found.'], 404);
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    } catch (Throwable $e) {
        error_log('Backup download failed: ' . $e->getMessage());
        backupJsonResponse(['success' => false, 'message' => 'Download failed.'], 500);
    }
}

// All POST actions require CSRF token
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($csrfToken === '' || !hash_equals($csrfToken, requestString($_POST, 'csrf_token'))) {
        backupJsonResponse(['success' => false, 'message' => 'Session expired.'], 403);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($backupAction, ['create', 'restore'], true)) {
    try {
        $adminUserId = (int) ($_SESSION['user_id'] ?? 0);
        $adminPassword = requestString($_POST, 'admin_password');
        if (!userHasAdminPassword($pdo, $adminUserId, $adminPassword)) {
            backupJsonResponse(['success' => false, 'message' => 'The administrator password is incorrect. No changes were made.'], 403);
        }
    } catch (PDOException $e) {
        error_log('Backup administrator verification failed: ' . $e->getMessage());
        backupJsonResponse(['success' => false, 'message' => 'Administrator access could not be verified. Try again later.'], 503);
    }
}

// Create backup (POST)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $backupAction === 'create') {
    $tempDir = null;
    $backupFile = null;
    $archiveTempFile = null;
    try {
        if (!class_exists(ZipArchive::class)) {
            backupJsonResponse(['success' => false, 'message' => 'Backups require the PHP ZIP extension. Enable it and try again.'], 503);
        }
        if (empty($DB_NAME) || empty($BACKUP_DB_USER) || !isset($DB_HOST, $DB_PORT, $BACKUP_DB_PASS)) {
            throw new RuntimeException('Database connection settings are incomplete.');
        }
        $mysqlDump = backupMysqlClient('mysqldump');
        if ($mysqlDump === null) {
            throw new RuntimeException('Could not find mysqldump. Add it to PATH or configure MYSQLDUMP_PATH.');
        }

        $backupCreatedAt = new DateTimeImmutable('now', $backupTimezone);
        $timestamp = $backupCreatedAt->format('Y-m-d_H-i-s');
        $backupName = 'backup_' . $timestamp . '_' . bin2hex(random_bytes(4)) . '.zip';
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . $backupName;
        $archiveTempFile = $backupDir . DIRECTORY_SEPARATOR . '.creating_' . bin2hex(random_bytes(8)) . '.zip';
        $tempDir = $backupDir . DIRECTORY_SEPARATOR . '.library-backup-' . bin2hex(random_bytes(8));
        if (!mkdir($tempDir, 0700, true)) {
            throw new RuntimeException('Could not create a temporary backup directory.');
        }

        $dbDump = $tempDir . DIRECTORY_SEPARATOR . 'database.sql';
        $mysqlOptions = backupMysqlOptionsFile($tempDir, $BACKUP_DB_USER, $BACKUP_DB_PASS);
        backupRunMysql([
            $mysqlDump,
            '--defaults-extra-file=' . $mysqlOptions,
            '--no-tablespaces',
            '--single-transaction',
            '--routines',
            '--events',
            '--result-file=' . $dbDump,
            $DB_NAME,
        ]);
        if (!unlink($mysqlOptions)) {
            throw new RuntimeException('Could not remove temporary MySQL client settings.');
        }
        if (!is_file($dbDump) || filesize($dbDump) === 0) {
            throw new RuntimeException('mysqldump did not create a database dump.');
        }
        // Keep the dump free of system-account DEFINER clauses so it can be
        // restored by the application's restore account later.
        $normalizedDefiners = backupStripDefiners($dbDump);
        if ($normalizedDefiners > 0) {
            error_log('Backup normalized ' . $normalizedDefiners . ' DEFINER clause(s) in the database dump.');
        }

        $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
        $filesBackup = $tempDir . DIRECTORY_SEPARATOR . 'uploads';
        backupCopyDirectory($uploadDir, $filesBackup);
        backupCopyDirectory(
            prospectusStorageDirectory(),
            $filesBackup . DIRECTORY_SEPARATOR . 'prospectuses'
        );

        $zip = new ZipArchive();
        if ($zip->open($archiveTempFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create the backup archive.');
        }
        if (!$zip->addFile($dbDump, 'database.sql') || !$zip->addEmptyDir('uploads')) {
            $zip->close();
            throw new RuntimeException('Could not add the database or uploads directory to the backup.');
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getPathname();
                if ($filePath === $dbDump) {
                    continue;
                }
                $relativePath = str_replace('\\', '/', substr($filePath, strlen($tempDir) + 1));
                if (!$zip->addFile($filePath, $relativePath)) {
                    $zip->close();
                    throw new RuntimeException('Could not add an uploaded file to the backup.');
                }
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('Could not finish writing the backup archive.');
        }
        if (!rename($archiveTempFile, $backupFile)) {
            throw new RuntimeException('Could not move the completed backup into backup storage.');
        }
        $archiveTempFile = null;

        $fileSize = filesize($backupFile);
        if ($fileSize === false) {
            throw new RuntimeException('Could not read the backup archive size.');
        }
        $size = $fileSize > 1048576 ? round($fileSize / 1048576, 1) . ' MB' : round($fileSize / 1024, 1) . ' KB';

        $response = [
            'success' => true,
            'message' => 'Backup created successfully.',
            'backup' => [
                'filename' => basename($backupFile),
                'created' => $backupCreatedAt->format('M d, Y · H:i'),
                'size' => $size,
                'status' => 'Complete'
            ]
        ];
        backupRemovePath($tempDir);
        $tempDir = null;
        backupJsonResponse($response);
    } catch (Throwable $e) {
        if (is_string($tempDir) && is_dir($tempDir)) {
            try {
                backupRemovePath($tempDir);
            } catch (Throwable $cleanupError) {
                error_log('Backup temporary directory cleanup failed: ' . $cleanupError->getMessage());
            }
        }
        if (is_string($backupFile) && is_file($backupFile)) {
            unlink($backupFile);
        }
        if (is_string($archiveTempFile) && is_file($archiveTempFile)) {
            unlink($archiveTempFile);
        }
        error_log('Backup creation failed: ' . $e->getMessage());
        $errorDetails = $e->getMessage();
        $authenticationFailure = preg_match('/(?:\b1045\b|access denied for user)/i', $errorDetails) === 1;
        $message = $authenticationFailure
            ? 'The backup account could not sign in to the database. Ask your system administrator to check the account name, password, and allowed connection address.'
            : (stripos($errorDetails, 'denied') !== false
                ? 'The backup account cannot read all database tables and views. Ask your system administrator to check its database access.'
                : 'The backup could not be created. Ask your system administrator to check the server logs.');
        backupJsonResponse(['success' => false, 'message' => $message], 500);
    }
}

// Validate restore file (POST)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $backupAction === 'validate') {
    try {
        if (!class_exists(ZipArchive::class)) {
            backupJsonResponse(['success' => false, 'message' => 'The server cannot open ZIP backups right now. Ask your system administrator to enable ZIP support.'], 503);
        }
        $uploadError = $_FILES['restore_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            if ($uploadError === UPLOAD_ERR_NO_FILE) {
                $uploadMessage = 'Choose a backup ZIP first.';
            } elseif (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $uploadMessage = 'This ZIP is too large to upload. Ask your system administrator to increase the upload limit.';
            } else {
                $uploadMessage = 'The backup ZIP could not be uploaded. Try again or ask your system administrator for help.';
            }
            backupJsonResponse(['success' => false, 'message' => $uploadMessage], 422);
        }

        $tmpFile = $_FILES['restore_file']['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            backupJsonResponse(['success' => false, 'message' => 'This is not a valid ZIP file. Choose a backup ZIP created by this system.'], 422);
        }
        $contents = backupInspectArchive($zip);
        $zip->close();

        $storeName = '.pending_restore_' . bin2hex(random_bytes(16)) . '.zip';
        $storeFile = $backupDir . DIRECTORY_SEPARATOR . $storeName;
        if (!move_uploaded_file($tmpFile, $storeFile)) {
            throw new RuntimeException('Could not save the validated backup for restore.');
        }

        backupJsonResponse([
            'success' => true,
            'restore_id' => $storeName,
            'has_database' => $contents['has_database'],
            'has_files' => $contents['has_files'],
            'message' => 'Backup validated successfully.'
        ]);
    } catch (Throwable $e) {
        error_log('Restore validation failed: ' . $e->getMessage());
        backupJsonResponse(['success' => false, 'message' => 'This ZIP cannot be used to restore the library. Choose a complete backup created by this system.'], 422);
    }
}

// Confirm restore (POST)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $backupAction === 'restore') {
    $tempDir = null;
    $stagingDir = null;
    $previousUploadDir = null;
    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $uploadsSwapped = false;
    $hadPreviousUploads = false;
    $preservedObjects = [];
    try {
        if (!class_exists(ZipArchive::class)) {
            backupJsonResponse(['success' => false, 'message' => 'The server cannot open ZIP backups right now. Ask your system administrator to enable ZIP support.'], 503);
        }
        if (empty($DB_NAME) || !isset($DB_HOST, $DB_PORT)) {
            throw new RuntimeException('Database connection settings are incomplete.');
        }
        if (empty($RESTORE_DB_USER) || !isset($RESTORE_DB_PASS)) {
            throw new RuntimeException('Restore database credentials are not configured. Set RESTORE_DB_USER and RESTORE_DB_PASS in .env.');
        }
        $mysqlClient = backupMysqlClient('mysql');
        if ($mysqlClient === null) {
            throw new RuntimeException('Could not find mysql. Add it to PATH or configure MYSQL_PATH.');
        }

        $restoreName = basename(requestString($_POST, 'restore_id'));
        if (preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(?:_[a-f0-9]{8})?\.zip$/', $restoreName) === 1) {
            $restoreFile = $backupDir . DIRECTORY_SEPARATOR . $restoreName;
        } elseif (preg_match('/^\.pending_restore_[a-f0-9]{32}\.zip$/', $restoreName) === 1) {
            $restoreFile = $backupDir . DIRECTORY_SEPARATOR . $restoreName;
        } else {
            backupJsonResponse(['success' => false, 'message' => 'Backup not found.'], 404);
        }
        $backupRealPath = realpath($backupDir);
        $restoreRealPath = realpath($restoreFile);
        if ($backupRealPath === false || $restoreRealPath === false || !str_starts_with($restoreRealPath, $backupRealPath . DIRECTORY_SEPARATOR)) {
            backupJsonResponse(['success' => false, 'message' => 'Restore session expired.'], 404);
        }

        $tempDir = $backupDir . DIRECTORY_SEPARATOR . '.library-restore-' . bin2hex(random_bytes(8));
        if (!mkdir($tempDir, 0700, true)) {
            throw new RuntimeException('Could not create a temporary restore directory.');
        }
        $zip = new ZipArchive();
        if ($zip->open($restoreRealPath) !== true) {
            throw new RuntimeException('The backup archive could not be opened.');
        }
        backupInspectArchive($zip);
        backupExtractArchive($zip, $tempDir);
        if (!$zip->close()) {
            throw new RuntimeException('The backup archive could not be closed cleanly.');
        }

        $dumpFile = $tempDir . DIRECTORY_SEPARATOR . 'database.sql';
        // Archives created before definers were normalized still contain
        // system-account DEFINER clauses, which MySQL 8 rejects with 1227.
        $normalizedDefiners = backupStripDefiners($dumpFile);
        if ($normalizedDefiners > 0) {
            error_log('Restore normalized ' . $normalizedDefiners . ' DEFINER clause(s) before import.');
        }

        // Views, routines, events and triggers created by another account (such
        // as root) cannot be dropped or replaced by the restore account, which
        // makes an otherwise valid archive fail on error 1227. Remove the ones
        // this archive recreates with the backup account, which already holds
        // the privileges needed, then import as the restore account so the
        // objects end up owned by it.
        $dumpObjects = backupDumpObjectNames($dumpFile);
        $restoreConnect = static function () use ($DB_HOST, $DB_PORT, $DB_NAME, $RESTORE_DB_USER, $RESTORE_DB_PASS): PDO {
            return new PDO(
                "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
                $RESTORE_DB_USER,
                $RESTORE_DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
        };
        try {
            $foreignObjects = backupForeignDefinerObjects($restoreConnect(), $DB_NAME);
        } catch (Throwable $preflightError) {
            $foreignObjects = [];
            error_log('Restore preflight skipped: ' . $preflightError->getMessage());
        }
        if ($foreignObjects !== []) {
            $recreated = [];
            foreach ($foreignObjects as $object) {
                if (in_array($object['name'], $dumpObjects[$object['type']] ?? [], true)) {
                    $recreated[$object['type']][] = $object['name'];
                }
            }
            if ($recreated !== [] && $BACKUP_DB_USER !== '') {
                try {
                    $adminConnection = new PDO(
                        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
                        $BACKUP_DB_USER,
                        $BACKUP_DB_PASS,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
                    );
                    $dropResult = backupDropDatabaseObjects($adminConnection, $DB_NAME, $recreated);
                    $adminConnection = null;
                    if ($dropResult['dropped'] !== []) {
                        error_log('Restore released foreign-owned objects before import: ' . implode(', ', $dropResult['dropped']));
                    }
                    if ($dropResult['failed'] !== []) {
                        error_log('Restore could not release objects before import: ' . implode('; ', $dropResult['failed']));
                    }
                    $foreignObjects = backupForeignDefinerObjects($restoreConnect(), $DB_NAME);
                } catch (Throwable $normalizationError) {
                    error_log('Restore ownership normalization skipped: ' . $normalizationError->getMessage());
                    $foreignObjects = backupForeignDefinerObjects($restoreConnect(), $DB_NAME);
                }
            }
            if ($foreignObjects !== []) {
                // The backup account cannot remove them, so the import keeps
                // them as they are and tolerates only their ownership errors.
                $preservedObjects = $foreignObjects;
                error_log('Restore will preserve objects owned by another account: '
                    . implode(', ', array_map(
                        static fn (array $object): string => $object['type'] . ' ' . $object['name'] . ' (' . $object['definer'] . ')',
                        $preservedObjects
                    )));
            }
        }

        $filesRestore = $tempDir . DIRECTORY_SEPARATOR . 'uploads';
        $restoredProspectuses = $filesRestore . DIRECTORY_SEPARATOR . 'prospectuses';
        if (is_dir($restoredProspectuses)) {
            backupCopyDirectory($restoredProspectuses, prospectusStorageDirectory());
            backupRemovePath($restoredProspectuses);
        }
        $stagingDir = dirname($uploadDir) . DIRECTORY_SEPARATOR . 'uploads.restore-' . bin2hex(random_bytes(8));
        backupCopyDirectory($filesRestore, $stagingDir);
        if (is_link($uploadDir) || (file_exists($uploadDir) && !is_dir($uploadDir))) {
            throw new RuntimeException('The uploads path is not a regular directory.');
        }
        $hadPreviousUploads = is_dir($uploadDir);
        if ($hadPreviousUploads) {
            $previousUploadDir = dirname($uploadDir) . DIRECTORY_SEPARATOR . 'uploads.previous-' . bin2hex(random_bytes(8));
            if (!rename($uploadDir, $previousUploadDir)) {
                throw new RuntimeException('Could not stage the current uploads before restoring.');
            }
        }
        if (!rename($stagingDir, $uploadDir)) {
            if ($hadPreviousUploads && is_string($previousUploadDir) && !rename($previousUploadDir, $uploadDir)) {
                throw new RuntimeException('Could not install restored uploads or put the current uploads back.');
            }
            throw new RuntimeException('Could not install the restored uploads.');
        }
        $stagingDir = null;
        $uploadsSwapped = true;

        try {
            $mysqlOptions = backupMysqlOptionsFile($tempDir, $RESTORE_DB_USER, $RESTORE_DB_PASS);
            $importCommand = [$mysqlClient, '--defaults-extra-file=' . $mysqlOptions];
            if ($preservedObjects !== []) {
                $importCommand[] = '--force';
            }
            $importCommand[] = $DB_NAME;
            $preservedNames = array_map(static fn (array $object): string => $object['name'], $preservedObjects);
            backupRunMysql(
                $importCommand,
                $dumpFile,
                $preservedObjects !== []
                    ? static fn (string $details): bool => backupOnlyOwnershipErrors($details, $preservedNames)
                    : null
            );
            if (!unlink($mysqlOptions)) {
                error_log('Temporary MySQL client settings cleanup failed after restore.');
            }
        } catch (Throwable $databaseError) {
            backupRemovePath($uploadDir);
            if ($hadPreviousUploads && is_string($previousUploadDir) && !rename($previousUploadDir, $uploadDir)) {
                throw new RuntimeException('Database restore failed and the previous uploads could not be put back.', 0, $databaseError);
            }
            $uploadsSwapped = false;
            throw new RuntimeException('Database restore failed. The previous uploads were restored.', 0, $databaseError);
        }

        if (is_string($previousUploadDir) && is_dir($previousUploadDir)) {
            try {
                backupRemovePath($previousUploadDir);
            } catch (Throwable $cleanupError) {
                error_log('Previous uploads cleanup failed: ' . $cleanupError->getMessage());
            }
        }
        if (is_dir($tempDir)) {
            try {
                backupRemovePath($tempDir);
            } catch (Throwable $cleanupError) {
                error_log('Restore temporary directory cleanup failed: ' . $cleanupError->getMessage());
            }
        }
        if (str_starts_with(basename($restoreRealPath), '.pending_restore_')) {
            if (!unlink($restoreRealPath)) {
                error_log('Validated restore file cleanup failed.');
            }
        }

        $restoreMessage = 'Restore complete.';
        if ($preservedObjects !== []) {
            $preservedNames = array_map(static fn (array $object): string => $object['name'], $preservedObjects);
            $restoreMessage .= ' These database objects are owned by another account and were left unchanged: '
                . implode(', ', array_slice($preservedNames, 0, 5))
                . (count($preservedNames) > 5 ? ' and ' . (count($preservedNames) - 5) . ' more' : '') . '.';
        }
        backupJsonResponse(['success' => true, 'message' => $restoreMessage . ' This page will reload shortly.']);
    } catch (Throwable $e) {
        if ($uploadsSwapped) {
            try {
                backupRemovePath($uploadDir);
                if ($hadPreviousUploads && is_string($previousUploadDir) && is_dir($previousUploadDir)) {
                    rename($previousUploadDir, $uploadDir);
                }
            } catch (Throwable $rollbackError) {
                error_log('Uploads rollback failed: ' . $rollbackError->getMessage());
            }
        }
        foreach ([$tempDir, $stagingDir] as $cleanupPath) {
            if (is_string($cleanupPath) && is_dir($cleanupPath)) {
                try {
                    backupRemovePath($cleanupPath);
                } catch (Throwable $cleanupError) {
                    error_log('Restore temporary cleanup failed: ' . $cleanupError->getMessage());
                }
            }
        }
        $errorDetails = $e->getMessage() . ' ' . ($e->getPrevious()?->getMessage() ?? '');
        error_log('Restore failed: ' . $errorDetails);

        $authenticationFailure = preg_match('/(?:\b1045\b|access denied for user)/i', $errorDetails) === 1;
        $routinePrivilegeFailure = preg_match('/(?:\b1419\b|SUPER privilege.*binary logging)/i', $errorDetails) === 1;
        $systemDefinerFailure = preg_match('/\bSYSTEM_USER\b/i', $errorDetails) === 1;
        $definerPrivilegeFailure = preg_match('/(?:\b1227\b|SET_ANY_DEFINER)/i', $errorDetails) === 1;
        $restoreCredentialsMissing = stripos($errorDetails, 'Restore database credentials are not configured.') !== false;
        $permissionFailure = stripos($errorDetails, 'denied') !== false
            || stripos($errorDetails, 'privilege') !== false;
        $uploadRollbackFailed = stripos($errorDetails, 'previous uploads could not be put back') !== false;
        if ($restoreCredentialsMissing) {
            $message = 'Restore database credentials are not configured. Set RESTORE_DB_USER and RESTORE_DB_PASS in the .env file before restoring.';
        } elseif ($authenticationFailure) {
            $message = $uploadRollbackFailed
                ? 'The restore account could not sign in to the database, and the uploaded files could not be returned to their previous state. Ask your system administrator to check the system before retrying.'
                : 'The restore account could not sign in to the database. The database was not restored, and the uploaded files were returned to their previous state. Ask your system administrator to check the account details before retrying.';
        } elseif ($systemDefinerFailure) {
            $message = $uploadRollbackFailed
                ? 'Restore stopped because the database contains views, routines or events owned by another MySQL account, which the restore account cannot replace. SYSTEM_USER must not be granted. The database may be partly changed, and uploaded files could not be fully restored. Ask your system administrator to drop those objects as a MySQL administrator before retrying.'
                : 'Restore stopped because the database contains views, routines or events owned by another MySQL account, which the restore account cannot replace. SYSTEM_USER must not be granted. The uploaded files were returned to their previous state, but the database may be partly changed. Ask your system administrator to drop those objects as a MySQL administrator, then retry.';
        } elseif ($definerPrivilegeFailure) {
            $message = $uploadRollbackFailed
                ? 'Restore stopped with MySQL error 1227 because the restore account cannot replace a database object owned by another account. The database may be partly changed, and uploaded files could not be fully restored. Ask your system administrator to check the system.'
                : 'Restore stopped with MySQL error 1227 because the restore account cannot replace a database object owned by another account. The uploaded files were returned to their previous state, but the database may be partly changed. Ask your system administrator to drop the object as a MySQL administrator, then retry.';
        } elseif ($routinePrivilegeFailure) {
            $message = $uploadRollbackFailed
                ? 'Restore stopped with MySQL error 1419 because binary logging blocks stored-function creation without administrator approval. The database may be partly changed, and uploaded files could not be fully restored. Do not retry; ask your system administrator to check the system.'
                : 'Restore stopped with MySQL error 1419 because binary logging blocks stored-function creation without administrator approval. The uploaded files were returned to their previous state, but the database may be partly changed. Do not retry; ask your system administrator to review the server settings and database before retrying.';
        } elseif ($permissionFailure) {
            $message = $uploadRollbackFailed
                ? 'Restore stopped because the database account is missing required access. The database may be partly changed, and the uploaded files could not be fully returned to their previous state. Do not retry; ask your system administrator to check the system.'
                : 'Restore stopped because the database account is missing required access. The uploaded files were returned to their previous state, but the database may be partly changed. Do not retry; ask your system administrator to check the database before retrying.';
        } else {
            $message = $uploadRollbackFailed
                ? 'Restore could not be completed. The uploaded files could not be fully returned to their previous state. Do not retry; ask your system administrator to check the system.'
                : 'Restore could not be completed. Ask your system administrator to check the backup file and server logs before retrying.';
        }
        backupJsonResponse(['success' => false, 'message' => $message], 500);
    }
}

backupJsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
