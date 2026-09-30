<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_course_service.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=inventory');
    exit;
}

requireLogin();
header('Content-Type: application/json; charset=UTF-8');

function aiCourseApiResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!$pdo instanceof PDO) {
    aiCourseApiResponse(['success' => false, 'message' => 'The database is unavailable.'], 503);
}

if (!in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'librarian'], true)) {
    aiCourseApiResponse(['success' => false, 'message' => 'You do not have permission to manage AI course suggestions.'], 403);
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (in_array($action, ['request_suggestions', 'approve', 'dismiss'], true)) {
    if ($method !== 'POST') {
        aiCourseApiResponse(['success' => false, 'message' => 'Use POST for this action.'], 405);
    }
    $expectedToken = (string)($_SESSION['inventory_csrf'] ?? '');
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
        aiCourseApiResponse(['success' => false, 'message' => 'Your session has expired. Reload Inventory and try again.'], 403);
    }
}

try {
    if ($action === 'request_suggestions') {
        $config = aiCourseAssertConfigured();
        $bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
        if ($bookId === false || $bookId < 1) {
            aiCourseApiResponse(['success' => false, 'message' => 'Choose a valid book.'], 422);
        }
        if (!aiCourseFetchBook($pdo, $bookId)) {
            aiCourseApiResponse(['success' => false, 'message' => 'This book is unavailable for suggestions.'], 404);
        }

        $currentRun = aiCourseFindCurrentRun($pdo, $bookId, $config);
        if ($currentRun !== null) {
            aiCourseApiResponse(['success' => true, 'state' => 'complete', 'run' => $currentRun]);
        }

        $userId = (int)$_SESSION['user_id'];
        $requestKey = hash('sha256', 'suggest_courses:' . $bookId . ':' . $userId);
        $pending = $pdo->prepare("SELECT id, status FROM ai_jobs WHERE request_key = :request_key LIMIT 1");
        $pending->execute([':request_key' => $requestKey]);
        $pendingJob = $pending->fetch();
        if ($pendingJob) {
            aiCourseApiResponse(['success' => true, 'state' => $pendingJob['status'], 'job_id' => (int)$pendingJob['id']], 202);
        }

        $rateLimit = $pdo->prepare("SELECT COUNT(*) FROM ai_jobs WHERE requested_by = :user_id AND job_type = 'suggest_courses' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $rateLimit->execute([':user_id' => $userId]);
        if ((int)$rateLimit->fetchColumn() >= $config['requests_per_hour']) {
            aiCourseApiResponse(['success' => false, 'message' => 'You have reached the hourly suggestion limit. Please try again later.'], 429);
        }

        $jobId = aiCourseEnqueueSuggestion($pdo, $bookId, $userId);
        aiCourseApiResponse(['success' => true, 'state' => 'queued', 'job_id' => $jobId], 202);
    }

    if ($action === 'job_status') {
        $jobId = filter_var($_GET['job_id'] ?? null, FILTER_VALIDATE_INT);
        if ($jobId === false || $jobId < 1) {
            aiCourseApiResponse(['success' => false, 'message' => 'Choose a valid job.'], 422);
        }
        $stmt = $pdo->prepare('SELECT id, book_id, requested_by, result_run_id, status, attempts, last_error FROM ai_jobs WHERE id = :id AND requested_by = :user_id');
        $stmt->execute([':id' => $jobId, ':user_id' => (int)$_SESSION['user_id']]);
        $job = $stmt->fetch();
        if (!$job) {
            aiCourseApiResponse(['success' => false, 'message' => 'This suggestion request is unavailable.'], 404);
        }

        if ($job['status'] === 'completed' && $job['result_run_id'] !== null) {
            $config = aiCourseConfig();
            $run = aiCourseFindCurrentRun($pdo, (int)$job['book_id'], $config);
            if ($run === null || (int)$run['id'] !== (int)$job['result_run_id']) {
                aiCourseApiResponse(['success' => true, 'state' => 'stale', 'message' => 'Book or course information changed. Generate fresh suggestions.']);
            }
            aiCourseApiResponse(['success' => true, 'state' => 'complete', 'run' => $run]);
        }
        if (in_array($job['status'], ['failed', 'cancelled'], true)) {
            aiCourseApiResponse([
                'success' => true,
                'state' => $job['status'],
                'message' => aiCoursePublicJobError($job['last_error']),
            ]);
        }
        if ($job['status'] === 'queued' && (int)$job['attempts'] > 0) {
            $retryMessage = aiCoursePublicJobError($job['last_error']);
            aiCourseApiResponse(['success' => true, 'state' => 'retrying', 'message' => $retryMessage]);
        }
        aiCourseApiResponse(['success' => true, 'state' => $job['status']]);
    }

    if ($action === 'approve' || $action === 'dismiss') {
        $runId = filter_var($_POST['run_id'] ?? null, FILTER_VALIDATE_INT);
        $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);
        if ($runId === false || $runId < 1 || $courseId === false || $courseId < 1) {
            aiCourseApiResponse(['success' => false, 'message' => 'Choose a valid suggestion.'], 422);
        }

        $run = aiCourseLoadRun($pdo, $runId);
        if (!$run) {
            aiCourseApiResponse(['success' => false, 'message' => 'This suggestion is no longer available.'], 404);
        }
        $config = aiCourseConfig();
        if ($config['embedding_model'] === '') {
            aiCourseApiResponse(['success' => false, 'message' => 'AI course suggestions are not configured correctly. Contact an administrator.'], 503);
        }
        $currentRun = aiCourseFindCurrentRun($pdo, (int)$run['book_id'], $config);
        if ($currentRun === null || (int)$currentRun['id'] !== $runId) {
            aiCourseApiResponse(['success' => false, 'message' => 'Book or course information changed. Generate fresh suggestions before reviewing.'], 409);
        }

        $pdo->beginTransaction();
        try {
            $candidateStmt = $pdo->prepare('SELECT s.decision, s.relevance_label, r.book_id FROM book_course_suggestions s INNER JOIN book_course_suggestion_runs r ON r.id = s.run_id INNER JOIN books b ON b.book_id = r.book_id AND b.deleted_at IS NULL INNER JOIN courses c ON c.id = s.course_id AND c.status = \'active\' WHERE s.run_id = :run_id AND s.course_id = :course_id AND s.relevance_label = \'relevant\' FOR UPDATE');
            $candidateStmt->execute([':run_id' => $runId, ':course_id' => $courseId]);
            $candidate = $candidateStmt->fetch();
            if (!$candidate) {
                $pdo->rollBack();
                aiCourseApiResponse(['success' => false, 'message' => 'This book or course is no longer active.'], 409);
            }

            if ($action === 'approve') {
                $linked = $pdo->prepare('SELECT 1 FROM book_courses WHERE book_id = :book_id AND course_id = :course_id');
                $linked->execute([':book_id' => $candidate['book_id'], ':course_id' => $courseId]);
                if (!$linked->fetchColumn()) {
                    $insert = $pdo->prepare('INSERT INTO book_courses (book_id, course_id) VALUES (:book_id, :course_id)');
                    $insert->execute([':book_id' => $candidate['book_id'], ':course_id' => $courseId]);
                }
                $decision = 'approved';
            } else {
                $decision = 'dismissed';
            }

            if ($candidate['decision'] === 'pending') {
                $update = $pdo->prepare('UPDATE book_course_suggestions SET decision = :decision, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE run_id = :run_id AND course_id = :course_id AND decision = \'pending\'');
                $update->execute([
                    ':decision' => $decision,
                    ':reviewed_by' => (int)$_SESSION['user_id'],
                    ':run_id' => $runId,
                    ':course_id' => $courseId,
                ]);
            } elseif ($candidate['decision'] !== $decision) {
                $pdo->rollBack();
                aiCourseApiResponse(['success' => false, 'message' => 'This suggestion has already been reviewed.'], 409);
            }

            if ($action === 'approve') {
                recordActivityLog($pdo, (int)$_SESSION['user_id'], 'ai_course_approved', 'Approved an AI course suggestion.', 'book', (int)$candidate['book_id']);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        aiCourseApiResponse(['success' => true, 'message' => $action === 'approve' ? 'Course reference approved.' : 'Suggestion dismissed.']);
    }

    aiCourseApiResponse(['success' => false, 'message' => 'Unknown AI course action.'], 400);
} catch (AiCourseException $exception) {
    $status = in_array($exception->errorKey, ['feature_disabled', 'configuration_missing', 'curl_unavailable'], true) ? 503 : 422;
    aiCourseApiResponse(['success' => false, 'message' => $exception->getMessage()], $status);
} catch (Throwable $exception) {
    error_log('AI course endpoint failed: ' . $exception::class);
    aiCourseApiResponse(['success' => false, 'message' => 'Unable to complete the AI course action. Please try again.'], 500);
}
