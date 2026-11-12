<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=reference_analyzer');
    exit;
}

requireLogin();

function referenceAnalyzerJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$role = strtolower((string) ($_SESSION['role'] ?? ''));
if (!in_array($role, ['admin', 'librarian'], true)) {
    if (isset($_GET['action'])) {
        referenceAnalyzerJson(['success' => false, 'message' => 'Reference Analyzer access required.'], 403);
    }
    http_response_code(403);
    exit('Reference Analyzer access required.');
}

if (isset($_GET['action'])) {
    if (!$pdo instanceof PDO) {
        referenceAnalyzerJson(['success' => false, 'message' => 'The database is unavailable.'], 503);
    }

    try {
        $policy = getReferenceAgePolicy($pdo);
        $action = requestString($_GET, 'action');

        if ($action === 'options') {
            $colleges = $pdo->query("SELECT id, name FROM colleges WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            $programs = $pdo->query("SELECT id, college_id, name FROM programs WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            $courses = $pdo->query('SELECT id, program_id, code, name, status FROM courses ORDER BY code, name')->fetchAll(PDO::FETCH_ASSOC);
            referenceAnalyzerJson(['success' => true, 'colleges' => $colleges, 'programs' => $programs, 'courses' => $courses, 'policy' => $policy]);
        }

        if ($action === 'list') {
            $search = trim(requestString($_GET, 'search'));
            $status = requestString($_GET, 'status', 'all');
            $collegeInput = trim(requestString($_GET, 'college_id'));
            $programInput = trim(requestString($_GET, 'program_id'));
            $courseInput = trim(requestString($_GET, 'course_id'));
            $collegeId = $collegeInput === '' ? 0 : filter_var($collegeInput, FILTER_VALIDATE_INT);
            $programId = $programInput === '' ? 0 : filter_var($programInput, FILTER_VALIDATE_INT);
            $courseId = $courseInput === '' ? 0 : filter_var($courseInput, FILTER_VALIDATE_INT);

            if (!in_array($status, ['all', 'meets_policy', 'outside_policy', 'needs_verification', 'missing_publication_data'], true)) {
                referenceAnalyzerJson(['success' => false, 'message' => 'Choose a valid policy status.'], 422);
            }
            foreach ([$collegeId, $programId, $courseId] as $filterId) {
                if ($filterId === false || $filterId < 0) {
                    referenceAnalyzerJson(['success' => false, 'message' => 'Choose a valid academic filter.'], 422);
                }
            }

            $conditions = ['b.deleted_at IS NULL'];
            $parameters = [];
            if ($search !== '') {
                $conditions[] = '(b.title LIKE :title_search OR b.author LIKE :author_search)';
                $parameters[':title_search'] = '%' . substr($search, 0, 255) . '%';
                $parameters[':author_search'] = '%' . substr($search, 0, 180) . '%';
            }
            if ($collegeId > 0 || $programId > 0 || $courseId > 0) {
                $academicConditions = ['bc_filter.book_id = b.book_id'];
                if ($collegeId > 0) {
                    $academicConditions[] = 'col_filter.id = :college_id';
                    $parameters[':college_id'] = $collegeId;
                }
                if ($programId > 0) {
                    $academicConditions[] = 'p_filter.id = :program_id';
                    $parameters[':program_id'] = $programId;
                }
                if ($courseId > 0) {
                    $academicConditions[] = 'c_filter.id = :course_id';
                    $parameters[':course_id'] = $courseId;
                }
                $conditions[] = 'EXISTS (
                    SELECT 1 FROM book_courses bc_filter
                    INNER JOIN courses c_filter ON c_filter.id = bc_filter.course_id
                    LEFT JOIN programs p_filter ON p_filter.id = c_filter.program_id
                    LEFT JOIN colleges col_filter ON col_filter.id = p_filter.college_id
                    WHERE ' . implode(' AND ', $academicConditions) . '
                )';
            }

            $statement = $pdo->prepare(
                'SELECT b.book_id, b.title, b.author, b.isbn, b.publisher, b.publication_year, b.copyright_year, b.description,
                        COUNT(DISTINCT bc.course_id) AS linked_course_count
                 FROM books b
                 LEFT JOIN book_courses bc ON bc.book_id = b.book_id
                 WHERE ' . implode(' AND ', $conditions) . '
                 GROUP BY b.book_id
                 ORDER BY b.title, b.book_id'
            );
            $statement->execute($parameters);
            $books = [];
            $summary = [
                'total' => 0,
                'meets_policy' => 0,
                'outside_policy' => 0,
                'needs_verification' => 0,
                'missing_publication_data' => 0,
            ];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $book) {
                $assessment = assessReferenceAge(
                    $book['publication_year'] !== null ? (int) $book['publication_year'] : null,
                    $policy
                );
                if ($status !== 'all' && $assessment['status'] !== $status) {
                    continue;
                }

                $book['book_id'] = (int) $book['book_id'];
                $book['publication_year'] = $book['publication_year'] !== null ? (int) $book['publication_year'] : null;
                $book['copyright_year'] = $book['copyright_year'] !== null ? (int) $book['copyright_year'] : null;
                $book['linked_course_count'] = (int) $book['linked_course_count'];
                $book['assessment'] = $assessment;
                $books[] = $book;
                $summary['total']++;
                $summary[$assessment['status']]++;
            }

            referenceAnalyzerJson(['success' => true, 'books' => $books, 'summary' => $summary, 'policy' => $policy]);
        }

        if ($action === 'book_detail') {
            $bookId = filter_var($_GET['book_id'] ?? null, FILTER_VALIDATE_INT);
            if ($bookId === false || $bookId < 1) {
                referenceAnalyzerJson(['success' => false, 'message' => 'Choose a valid book.'], 422);
            }

            $bookStatement = $pdo->prepare(
                'SELECT b.book_id, b.isbn, b.title, b.author, b.publisher, b.publication_year, b.copyright_year, b.description,
                        COALESCE(copies.copy_count, 0) AS copy_count
                 FROM books b
                 LEFT JOIN (SELECT book_id, COUNT(*) AS copy_count FROM book_copies GROUP BY book_id) copies ON copies.book_id = b.book_id
                 WHERE b.book_id = :book_id AND b.deleted_at IS NULL
                 LIMIT 1'
            );
            $bookStatement->execute([':book_id' => $bookId]);
            $book = $bookStatement->fetch(PDO::FETCH_ASSOC);
            if ($book === false) {
                referenceAnalyzerJson(['success' => false, 'message' => 'This book is unavailable or archived.'], 404);
            }

            $courseStatement = $pdo->prepare(
                'SELECT c.id, c.code, c.name, c.status, col.name AS college_name, p.name AS program_name, m.name AS major_name
                 FROM book_courses bc
                 INNER JOIN courses c ON c.id = bc.course_id
                 LEFT JOIN programs p ON p.id = c.program_id
                 LEFT JOIN colleges col ON col.id = p.college_id
                 LEFT JOIN majors m ON m.id = c.major_id
                 WHERE bc.book_id = :book_id
                 ORDER BY c.code, c.name'
            );
            $courseStatement->execute([':book_id' => $bookId]);
            $book['book_id'] = (int) $book['book_id'];
            $book['publication_year'] = $book['publication_year'] !== null ? (int) $book['publication_year'] : null;
            $book['copyright_year'] = $book['copyright_year'] !== null ? (int) $book['copyright_year'] : null;
            $book['copy_count'] = (int) $book['copy_count'];
            $book['assessment'] = assessReferenceAge($book['publication_year'], $policy);
            $book['courses'] = array_map(static function (array $course): array {
                $course['id'] = (int) $course['id'];
                return $course;
            }, $courseStatement->fetchAll(PDO::FETCH_ASSOC));

            referenceAnalyzerJson(['success' => true, 'book' => $book, 'policy' => $policy]);
        }

        referenceAnalyzerJson(['success' => false, 'message' => 'Unknown Reference Analyzer action.'], 400);
    } catch (PDOException $exception) {
        error_log('Reference Analyzer database request failed: ' . $exception->getMessage());
        referenceAnalyzerJson([
            'success' => false,
            'message' => 'Reference Analyzer data is unavailable. Apply migration 003_reference_age_policy.sql and confirm the library tables are current.',
        ], 503);
    }
}

$csrfToken = $_SESSION['inventory_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['inventory_csrf'] = $csrfToken;
?>

<div class="live-reference-page space-y-6" data-reference-analyzer data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" data-can-manage="<?php echo in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['admin', 'librarian'], true) ? 'true' : 'false'; ?>">
    <header class="reference-heading">
        <div>
            <h1>Reference Analyzer</h1>
            <p class="page-description">Review inventory titles, their linked courses, and how publication years compare with the library’s reference-age policy.</p>
        </div>
        <button type="button" data-reference-refresh class="live-button">
            <i data-lucide="refresh-cw" aria-hidden="true"></i><span data-refresh-label>Refresh assessment</span>
        </button>
    </header>

    <div class="reference-overview" data-reference-overview>
        <section aria-labelledby="referenceOverviewHeading">
            <div class="reference-section-heading">
                <h2 id="referenceOverviewHeading">Assessment overview</h2>
                <p>Counts update with the current filters.</p>
            </div>
            <div class="reference-summary" aria-label="Reference policy assessment counts">
                <div class="reference-summary-item"><div class="reference-summary-label">Matching books</div><div class="reference-summary-value" data-summary="total">—</div></div>
                <div class="reference-summary-item is-meets"><div class="reference-summary-label">Meets policy</div><div class="reference-summary-value" data-summary="meets_policy">—</div></div>
                <div class="reference-summary-item is-outside"><div class="reference-summary-label">Outside policy</div><div class="reference-summary-value" data-summary="outside_policy">—</div></div>
                <div class="reference-summary-item is-review"><div class="reference-summary-label">Needs verification</div><div class="reference-summary-value" data-summary="needs_verification">—</div></div>
                <div class="reference-summary-item is-missing"><div class="reference-summary-label">Missing publication data</div><div class="reference-summary-value" data-summary="missing_publication_data">—</div></div>
            </div>
        </section>

        <p class="reference-policy-note" data-reference-policy-note role="status" aria-live="polite">Loading the configured reference-age policy…</p>
        <p class="reference-error hidden" data-reference-error role="alert"></p>
    </div>

    <section class="reference-results-panel" data-reference-books-panel aria-label="Analyzed books">
        <div class="reference-results-heading">
            <div><h2>Analyzed books</h2><p>Search inventory titles and review their policy status.</p></div>
        </div>
        <form data-reference-filters>
            <div class="reference-filter-grid">
            <label class="reference-filter-field is-search">Title or author
                <input name="search" type="search" placeholder="Search inventory titles…" class="reference-filter-control">
            </label>
            <label class="reference-filter-field">Policy status
                <select name="status" class="reference-filter-control">
                    <option value="all">All statuses</option><option value="meets_policy">Meets Policy</option><option value="outside_policy">Outside Policy</option><option value="needs_verification">Needs Verification</option><option value="missing_publication_data">Missing Publication Data</option>
                </select>
            </label>
            <label class="reference-filter-field">College
                <select name="college_id" data-filter-college class="reference-filter-control"><option value="">All colleges</option></select>
            </label>
            <label class="reference-filter-field">Program
                <select name="program_id" data-filter-program class="reference-filter-control"><option value="">All programs</option></select>
            </label>
            <label class="reference-filter-field sm:col-span-2 xl:col-span-2">Course
                <select name="course_id" data-filter-course class="reference-filter-control"><option value="">All courses</option></select>
            </label>
            </div>
            <div class="reference-filter-tools">
                <p class="reference-result-count" data-reference-result-count aria-live="polite">Loading books…</p>
                <button type="button" data-reference-clear-filters class="live-button" disabled>Clear filters</button>
            </div>
        </form>
        <div class="reference-table-wrap">
            <table class="reference-table">
                <thead>
                    <tr><th scope="col">Book</th><th scope="col">Author</th><th scope="col">Publication year</th><th scope="col">Linked courses</th><th scope="col">Reference policy</th><th scope="col" aria-label="Details"></th></tr>
                </thead>
                <tbody data-reference-books>
                    <tr><td colspan="6" class="reference-empty">Loading books…</td></tr>
                </tbody>
            </table>
        </div>
    </section>
    <section class="reference-detail-view hidden" data-book-detail-view aria-label="Book details" tabindex="-1"></section>
</div>
<script src="assets/js/reference_analyzer.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/reference_analyzer.js'); ?>"></script>
