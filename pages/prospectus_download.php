<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if (!in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['admin', 'librarian'], true)) {
    http_response_code(403);
    exit('Academic access required.');
}

$prospectusId = requestInt($_GET, 'id');
if ($prospectusId < 1 || !$pdo instanceof PDO) {
    http_response_code($prospectusId < 1 ? 404 : 503);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT pd.file_path
     FROM prospectus_documents pd
     INNER JOIN prospectuses p ON p.id = pd.prospectus_id
     WHERE p.id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $prospectusId]);
$storedPath = $stmt->fetchColumn();
$storedName = is_string($storedPath) ? basename(str_replace('\\', '/', $storedPath)) : '';
if (preg_match('/\A[a-f0-9]{24}\.pdf\z/', $storedName) !== 1) {
    http_response_code(404);
    exit;
}

try {
    $storageDirectory = prospectusStorageDirectory();
} catch (RuntimeException $exception) {
    error_log('Prospectus storage unavailable: ' . $exception->getMessage());
    http_response_code(503);
    exit('Prospectus storage is unavailable. Please try again later.');
}

$storagePrefix = rtrim($storageDirectory, '/\\') . DIRECTORY_SEPARATOR;
$filePath = realpath($storageDirectory . DIRECTORY_SEPARATOR . $storedName);
if ($filePath === false || !str_starts_with($filePath, $storagePrefix) || !isValidProspectusPdf($filePath)) {
    http_response_code(404);
    exit;
}

$fileSize = filesize($filePath);
if ($fileSize === false) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="prospectus-' . $prospectusId . '.pdf"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cross-Origin-Resource-Policy: same-origin');
readfile($filePath);
