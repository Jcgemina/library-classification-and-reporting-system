<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=configure');
    exit;
}

requireLogin();

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit;
}

function configureJsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

$csrfToken = $_SESSION['configure_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['configure_csrf'] = $csrfToken;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$pdo instanceof PDO) {
        configureJsonResponse(['success' => false, 'message' => 'The database connection is unavailable.'], 503);
    }

    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        configureJsonResponse(['success' => false, 'message' => 'Your session has expired. Reload Configure and try again.'], 403);
    }

    $copyrightRanges = array_map(
        static fn ($value) => filter_var($value, FILTER_VALIDATE_INT),
        [
            $_POST['copyright_range_1'] ?? null,
            $_POST['copyright_range_2'] ?? null,
            $_POST['copyright_range_3'] ?? null,
        ]
    );
    $minimumBooks = filter_var($_POST['minimum_books_per_course'] ?? null, FILTER_VALIDATE_INT);

    if (
        in_array(false, $copyrightRanges, true)
        || min($copyrightRanges) < 1
        || max($copyrightRanges) > 1000
        || $copyrightRanges[0] >= $copyrightRanges[1]
        || $copyrightRanges[1] >= $copyrightRanges[2]
    ) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Enter three unique copyright ranges in ascending order, from 1 to 1,000 years.',
        ], 422);
    }

    if ($minimumBooks === false || $minimumBooks < 1 || $minimumBooks > 65535) {
        configureJsonResponse([
            'success' => false,
            'message' => 'The minimum books per course must be between 1 and 65,535.',
        ], 422);
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM copyright_year_ranges');
        $rangeStatement = $pdo->prepare(
            'INSERT INTO copyright_year_ranges (years_threshold, sort_order) VALUES (:years_threshold, :sort_order)'
        );
        foreach ($copyrightRanges as $index => $yearsThreshold) {
            $rangeStatement->execute([
                ':years_threshold' => $yearsThreshold,
                ':sort_order' => $index + 1,
            ]);
        }

        $configurationStatement = $pdo->prepare(
            'INSERT INTO library_configuration (id, minimum_books_per_course)
             VALUES (1, :minimum_books_per_course)
             ON DUPLICATE KEY UPDATE minimum_books_per_course = VALUES(minimum_books_per_course)'
        );
        $configurationStatement->execute([':minimum_books_per_course' => $minimumBooks]);
        $pdo->commit();

        configureJsonResponse(['success' => true, 'message' => 'Settings saved.']);
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Library configuration could not be saved: ' . $exception->getMessage());
        configureJsonResponse([
            'success' => false,
            'message' => 'Settings could not be saved. Check that the latest schema.sql has been applied, then try again.',
        ], 500);
    }
}

$copyrightRanges = [5, 10, 20];
$minimumBooks = 1;
$loadError = null;
if (!$pdo instanceof PDO) {
    $loadError = 'The database connection is unavailable. Settings cannot be loaded or changed.';
} else {
    try {
        $rangeRows = $pdo->query(
            'SELECT years_threshold FROM copyright_year_ranges WHERE is_active = 1 ORDER BY sort_order, years_threshold'
        )->fetchAll(PDO::FETCH_COLUMN);
        $configuration = $pdo->query(
            'SELECT minimum_books_per_course FROM library_configuration WHERE id = 1'
        )->fetchColumn();

        if (count($rangeRows) !== 3 || $configuration === false) {
            throw new PDOException('Required library configuration records are missing.');
        }

        $copyrightRanges = array_map('intval', $rangeRows);
        $minimumBooks = (int) $configuration;
    } catch (PDOException $exception) {
        error_log('Library configuration could not be loaded: ' . $exception->getMessage());
        $loadError = 'Settings could not be loaded. Check that the latest schema.sql has been applied.';
    }
}

$escapeConfigureValue = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

?>

<div class="mx-auto max-w-4xl space-y-6 p-1 text-slate-900 sm:p-2" data-configure-page data-csrf-token="<?php echo $escapeConfigureValue($csrfToken); ?>">
    <header>
        <h2 class="text-2xl font-bold text-slate-900">Configure</h2>
        <p class="mt-1 text-sm text-slate-600">Set the copyright reporting windows and the minimum book coverage for each course.</p>
    </header>

    <?php if ($loadError !== null): ?>
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
            <?php echo $escapeConfigureValue($loadError); ?>
        </div>
    <?php endif; ?>

    <form id="configureForm" class="space-y-6" novalidate>
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="copyrightSettingsHeading">
            <div class="border-b border-slate-200 pb-4">
                <h3 id="copyrightSettingsHeading" class="text-lg font-bold text-slate-900">Copyright year ranges</h3>
                <p class="mt-1 text-sm text-slate-600">These rolling year windows are used for copyright counts on the dashboard and in Inventory. Keep them in ascending order.</p>
            </div>
            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <?php foreach ($copyrightRanges as $index => $yearsThreshold): ?>
                    <label for="copyrightRange<?php echo $index + 1; ?>" class="text-sm font-semibold text-slate-700">
                        Range <?php echo $index + 1; ?> (years)
                        <input
                            id="copyrightRange<?php echo $index + 1; ?>"
                            name="copyright_range_<?php echo $index + 1; ?>"
                            type="number"
                            min="1"
                            max="1000"
                            step="1"
                            value="<?php echo (int) $yearsThreshold; ?>"
                            required
                            <?php echo $loadError !== null ? 'disabled' : ''; ?>
                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 disabled:bg-slate-100"
                        >
                    </label>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="courseMinimumHeading">
            <div class="border-b border-slate-200 pb-4">
                <h3 id="courseMinimumHeading" class="text-lg font-bold text-slate-900">Minimum books per course</h3>
                <p class="mt-1 text-sm text-slate-600">A course meets this readiness requirement when at least this many active book titles are linked to it. Copies of the same title count once.</p>
            </div>
            <label for="minimumBooksPerCourse" class="mt-5 block max-w-sm text-sm font-semibold text-slate-700">
                Minimum active titles
                <input
                    id="minimumBooksPerCourse"
                    name="minimum_books_per_course"
                    type="number"
                    min="1"
                    max="65535"
                    step="1"
                    value="<?php echo (int) $minimumBooks; ?>"
                    required
                    <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 disabled:bg-slate-100"
                >
            </label>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p id="configureMessage" class="text-sm text-slate-600" role="status" aria-live="polite"></p>
            <button
                id="saveConfigureButton"
                type="submit"
                <?php echo $loadError !== null ? 'disabled' : ''; ?>
                class="inline-flex min-h-11 items-center justify-center rounded-lg bg-rose-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-400"
            >Save settings</button>
        </div>
    </form>
</div>

<script src="assets/js/configure.js"></script>
