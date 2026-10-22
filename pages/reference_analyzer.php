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
            $yearInput = trim(requestString($_GET, 'publication_year'));
            $status = requestString($_GET, 'status', 'all');
            $collegeInput = trim(requestString($_GET, 'college_id'));
            $programInput = trim(requestString($_GET, 'program_id'));
            $courseInput = trim(requestString($_GET, 'course_id'));
            $collegeId = $collegeInput === '' ? 0 : filter_var($collegeInput, FILTER_VALIDATE_INT);
            $programId = $programInput === '' ? 0 : filter_var($programInput, FILTER_VALIDATE_INT);
            $courseId = $courseInput === '' ? 0 : filter_var($courseInput, FILTER_VALIDATE_INT);

            if ($yearInput !== '' && (filter_var($yearInput, FILTER_VALIDATE_INT) === false || (int) $yearInput < 1450 || (int) $yearInput > (int) date('Y'))) {
                referenceAnalyzerJson(['success' => false, 'message' => 'Enter a valid publication year.'], 422);
            }
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
            if ($yearInput !== '') {
                $conditions[] = 'b.publication_year = :publication_year';
                $parameters[':publication_year'] = (int) $yearInput;
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
                $book['edition'] = null;
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
            $book['edition'] = null;
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

<div class="space-y-6 p-1 text-slate-900 sm:p-2" data-reference-analyzer data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" data-can-manage="<?php echo in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['admin', 'librarian'], true) ? 'true' : 'false'; ?>">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Reference Analyzer</h2>
            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">Review inventory titles, their linked courses, and their assessment against the institution’s configured reference-age policy.</p>
        </div>
        <button type="button" data-reference-refresh class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">
            <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>Refresh assessment
        </button>
    </header>

    <div class="space-y-6" data-reference-overview>
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-5">
            <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Books analyzed</p><p class="mt-2 text-2xl font-bold tabular-nums text-slate-900" data-summary="total">—</p></section>
            <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Meets policy</p><p class="mt-2 text-2xl font-bold tabular-nums text-emerald-900" data-summary="meets_policy">—</p></section>
            <section class="rounded-xl border border-rose-200 bg-rose-50 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-rose-800">Outside policy</p><p class="mt-2 text-2xl font-bold tabular-nums text-rose-900" data-summary="outside_policy">—</p></section>
            <section class="rounded-xl border border-amber-200 bg-amber-50 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-amber-900">Needs verification</p><p class="mt-2 text-2xl font-bold tabular-nums text-amber-950" data-summary="needs_verification">—</p></section>
            <section class="col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4 xl:col-span-1"><p class="text-xs font-semibold uppercase tracking-wide text-slate-700">Missing publication data</p><p class="mt-2 text-2xl font-bold tabular-nums text-slate-900" data-summary="missing_publication_data">—</p></section>
        </div>

        <p class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm leading-5 text-slate-700" data-reference-policy-note role="status" aria-live="polite">Loading the configured reference-age policy…</p>
        <p class="hidden rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" data-reference-error role="alert"></p>
    </div>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" data-reference-books-panel aria-label="Analyzed books">
        <form class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 xl:grid-cols-6" data-reference-filters>
            <label class="text-xs font-semibold text-slate-700 xl:col-span-2">Title or author
                <input name="search" type="search" placeholder="Search inventory…" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
            </label>
            <label class="text-xs font-semibold text-slate-700">Publication year
                <input name="publication_year" type="number" min="1450" max="<?php echo (int) date('Y'); ?>" placeholder="Any year" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-normal text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
            </label>
            <label class="text-xs font-semibold text-slate-700">Policy status
                <select name="status" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100">
                    <option value="all">All statuses</option><option value="meets_policy">Meets Policy</option><option value="outside_policy">Outside Policy</option><option value="needs_verification">Needs Verification</option><option value="missing_publication_data">Missing Publication Data</option>
                </select>
            </label>
            <label class="text-xs font-semibold text-slate-700">College
                <select name="college_id" data-filter-college class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"><option value="">All colleges</option></select>
            </label>
            <label class="text-xs font-semibold text-slate-700">Program
                <select name="program_id" data-filter-program class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"><option value="">All programs</option></select>
            </label>
            <label class="text-xs font-semibold text-slate-700 sm:col-span-2 xl:col-span-2">Course
                <select name="course_id" data-filter-course class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"><option value="">All courses</option></select>
            </label>
            <p class="self-end pb-2 text-xs text-slate-500 sm:col-span-2 xl:col-span-4" data-reference-result-count aria-live="polite">Loading books…</p>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-600">
                    <tr><th scope="col" class="px-4 py-3">Book</th><th scope="col" class="px-4 py-3">Author</th><th scope="col" class="px-4 py-3">Publication year</th><th scope="col" class="px-4 py-3">Edition</th><th scope="col" class="px-4 py-3 text-center">Linked courses</th><th scope="col" class="px-4 py-3">Reference policy</th><th scope="col" class="px-4 py-3 text-right">Details</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100" data-reference-books>
                    <tr><td colspan="7" class="px-4 py-12 text-center text-sm text-slate-600">Loading books…</td></tr>
                </tbody>
            </table>
        </div>
    </section>
    <section class="hidden" data-book-detail-view aria-live="polite" aria-label="Book details"></section>
</div>
<div data-reference-toast class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3" role="status" aria-live="polite"></div>
<script src="assets/js/reference_analyzer.js?v=20261020-2"></script>
