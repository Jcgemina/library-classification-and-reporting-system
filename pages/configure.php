<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
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

    if (!hash_equals($csrfToken, requestString($_POST, 'csrf_token'))) {
        configureJsonResponse(['success' => false, 'message' => 'Your session has expired. Reload Library Settings and try again.'], 403);
    }

    $copyrightRanges = array_map(
        static fn (mixed $value): int|false => filter_var($value, FILTER_VALIDATE_INT),
        [
            $_POST['copyright_range_1'] ?? null,
            $_POST['copyright_range_2'] ?? null,
            $_POST['copyright_range_3'] ?? null,
        ]
    );
    $minimumBooks = filter_var($_POST['minimum_books_per_course'] ?? null, FILTER_VALIDATE_INT);
    $defaultCurriculumYear = trim(requestString($_POST, 'default_curriculum_year'));
    $referencePolicyDirectionInput = trim(requestString($_POST, 'reference_policy_direction'));
    $referencePolicyYearsInput = trim(requestString($_POST, 'reference_policy_years'));
    $referencePolicyDirection = $referencePolicyDirectionInput !== '' ? $referencePolicyDirectionInput : null;
    $referencePolicyYears = $referencePolicyYearsInput !== '' ? filter_var($referencePolicyYearsInput, FILTER_VALIDATE_INT) : null;
    $referencePolicyConfirmed = requestString($_POST, 'reference_policy_confirmed') === '1';
    try {
        $currentConfiguration = $pdo->query(
            'SELECT reference_policy_direction, reference_policy_years FROM library_configuration WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        error_log('Library configuration could not be checked before saving: ' . $exception->getMessage());
        configureJsonResponse([
            'success' => false,
            'message' => 'Settings could not be checked before saving. Check that the latest schema.sql has been applied.',
        ], 503);
    }

    if ($currentConfiguration === false) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Settings could not be checked before saving. Check that the latest schema.sql has been applied.',
        ], 503);
    }

    $currentReferencePolicyDirection = $currentConfiguration['reference_policy_direction'];
    $currentReferencePolicyYears = $currentConfiguration['reference_policy_years'] !== null
        ? (int) $currentConfiguration['reference_policy_years']
        : null;
    $referencePolicyChanged = $referencePolicyDirection !== $currentReferencePolicyDirection
        || $referencePolicyYears !== $currentReferencePolicyYears;

    foreach ($copyrightRanges as $index => $yearsThreshold) {
        if ($yearsThreshold === false || $yearsThreshold < 1 || $yearsThreshold > 1000) {
            configureJsonResponse([
                'success' => false,
                'message' => 'Enter a whole-number copyright threshold from 1 to 1,000 years.',
                'field' => 'copyright_range_' . ($index + 1),
            ], 422);
        }
    }

    if ($copyrightRanges[0] >= $copyrightRanges[1]) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Enter three unique copyright ranges in ascending order, from 1 to 1,000 years.',
            'field' => 'copyright_range_2',
        ], 422);
    }
    if ($copyrightRanges[1] >= $copyrightRanges[2]) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Enter three unique copyright ranges in ascending order, from 1 to 1,000 years.',
            'field' => 'copyright_range_3',
        ], 422);
    }

    if ($minimumBooks === false || $minimumBooks < 1 || $minimumBooks > 65535) {
        configureJsonResponse([
            'success' => false,
            'message' => 'The minimum books per course must be between 1 and 65,535.',
            'field' => 'minimum_books_per_course',
        ], 422);
    }
    if ($defaultCurriculumYear === '' || strlen($defaultCurriculumYear) > 30) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Enter a default curriculum year using no more than 30 characters.',
            'field' => 'default_curriculum_year',
        ], 422);
    }
    if (preg_match('/^[0-9]{4}-[0-9]{4}$/D', $defaultCurriculumYear) !== 1) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Enter the curriculum year as two four-digit years separated by a hyphen, for example 2026-2027.',
            'field' => 'default_curriculum_year',
        ], 422);
    }
    if ($referencePolicyChanged && !in_array($referencePolicyDirection, ['within', 'at_least'], true)) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Choose an approved reference-age rule before changing the reference policy.',
            'field' => 'reference_policy_direction',
        ], 422);
    }
    if ($referencePolicyChanged && ($referencePolicyYears === false || $referencePolicyYears === null || $referencePolicyYears < 1 || $referencePolicyYears > 1000)) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Enter the threshold from the approved reference-age rule, from 1 to 1,000 years.',
            'field' => 'reference_policy_years',
        ], 422);
    }
    if ($referencePolicyChanged && !$referencePolicyConfirmed) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Confirm that the changed reference-age rule and threshold match the institution’s approved policy.',
            'field' => 'reference_policy_confirmed',
        ], 422);
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM copyright_year_ranges');
        $rangeStatement = $pdo->prepare(
            'INSERT INTO copyright_year_ranges (years_threshold, sort_order) VALUES (:years_threshold, :sort_order)'
        );
        $sortOrder = 1;
        foreach ($copyrightRanges as $yearsThreshold) {
            $rangeStatement->execute([
                ':years_threshold' => $yearsThreshold,
                ':sort_order' => $sortOrder++,
            ]);
        }

        $configurationStatement = $pdo->prepare(
            'INSERT INTO library_configuration (id, minimum_books_per_course, default_curriculum_year, reference_policy_direction, reference_policy_years)
             VALUES (1, :minimum_books_per_course, :default_curriculum_year, :reference_policy_direction, :reference_policy_years)
             ON DUPLICATE KEY UPDATE
                minimum_books_per_course = VALUES(minimum_books_per_course),
                default_curriculum_year = VALUES(default_curriculum_year),
                reference_policy_direction = VALUES(reference_policy_direction),
                reference_policy_years = VALUES(reference_policy_years)'
        );
        $configurationStatement->execute([
            ':minimum_books_per_course' => $minimumBooks,
            ':default_curriculum_year' => $defaultCurriculumYear,
            ':reference_policy_direction' => $referencePolicyDirection,
            ':reference_policy_years' => $referencePolicyDirection !== null ? $referencePolicyYears : null,
        ]);
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
$defaultCurriculumYear = date('Y') . '-' . ((int) date('Y') + 1);
$referencePolicyDirection = null;
$referencePolicyYears = null;
$loadError = null;
if (!$pdo instanceof PDO) {
    $loadError = 'The database connection is unavailable. Settings cannot be loaded or changed.';
} else {
    try {
        $rangeRows = $pdo->query(
            'SELECT years_threshold FROM copyright_year_ranges WHERE is_active = 1 ORDER BY sort_order, years_threshold'
        )->fetchAll(PDO::FETCH_COLUMN);
        $configuration = $pdo->query(
            'SELECT minimum_books_per_course, default_curriculum_year, reference_policy_direction, reference_policy_years FROM library_configuration WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);

        if (count($rangeRows) !== 3 || $configuration === false) {
            throw new PDOException('Required library configuration records are missing.');
        }

        $copyrightRanges = array_map('intval', $rangeRows);
        $minimumBooks = (int) $configuration['minimum_books_per_course'];
        $defaultCurriculumYear = (string) $configuration['default_curriculum_year'];
        $referencePolicyDirection = $configuration['reference_policy_direction'];
        $referencePolicyYears = $configuration['reference_policy_years'] !== null
            ? (int) $configuration['reference_policy_years']
            : null;
        if ($defaultCurriculumYear === '') {
            $defaultCurriculumYear = date('Y') . '-' . ((int) date('Y') + 1);
        }
    } catch (PDOException $exception) {
        error_log('Library configuration could not be loaded: ' . $exception->getMessage());
        $loadError = 'Settings could not be loaded. Check that the latest schema.sql has been applied.';
    }
}

$escapeConfigureValue = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

?>

<div
    class="mx-auto w-full max-w-6xl space-y-6 p-1 text-slate-900 sm:p-2"
    data-configure-page
    data-csrf-token="<?php echo $escapeConfigureValue($csrfToken); ?>"
    data-saved-reference-policy-direction="<?php echo $escapeConfigureValue($referencePolicyDirection); ?>"
    data-saved-reference-policy-years="<?php echo $escapeConfigureValue($referencePolicyYears); ?>"
>
    <header>
        <h2 class="text-2xl font-bold text-slate-900">Library Settings</h2>
        <p class="mt-1 text-sm text-slate-600">Set reporting windows, reference-age policy, course book coverage, and the default curriculum year for new prospectus records.</p>
    </header>

    <?php if ($loadError !== null): ?>
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
            <?php echo $escapeConfigureValue($loadError); ?>
        </div>
    <?php endif; ?>

    <form id="configureForm" class="grid gap-6 lg:grid-cols-2" novalidate>
        <section class="h-full rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 lg:col-span-2" aria-labelledby="copyrightSettingsHeading">
            <div class="border-b border-slate-200 pb-4">
                <h3 id="copyrightSettingsHeading" class="text-lg font-bold text-slate-900">Copyright year ranges</h3>
                <p class="mt-1 text-sm leading-6 text-slate-600">Each threshold counts titles dated from the current year back through that many years, using the copyright year or publication year when no copyright year is recorded. The windows overlap: a title in the shortest window also appears in every longer window. Keep the thresholds in ascending order.</p>
            </div>
            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <?php foreach (array_values($copyrightRanges) as $index => $yearsThreshold): ?>
                    <label for="copyrightRange<?php echo $index + 1; ?>" class="text-sm font-semibold text-slate-700">
                        Within this many years — window <?php echo $index + 1; ?>
                        <input
                            id="copyrightRange<?php echo $index + 1; ?>"
                            name="copyright_range_<?php echo $index + 1; ?>"
                            type="number"
                            min="1"
                            max="1000"
                            step="1"
                            value="<?php echo $yearsThreshold; ?>"
                            required
                            <?php echo $loadError !== null ? 'disabled' : ''; ?>
                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 disabled:bg-slate-100"
                        >
                    </label>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="h-full rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="curriculumYearHeading">
            <div class="border-b border-slate-200 pb-4">
                <h3 id="curriculumYearHeading" class="text-lg font-bold text-slate-900">Default prospectus curriculum year</h3>
                <p id="curriculumYearHelp" class="mt-1 text-sm leading-6 text-slate-600">Prefills the curriculum year when an admin creates a new prospectus. Use two four-digit years separated by a hyphen, for example 2026-2027. Admins can still change it for each prospectus.</p>
            </div>
            <label for="defaultCurriculumYear" class="mt-5 block max-w-sm text-sm font-semibold text-slate-700">
                Initial curriculum year
                <input
                    id="defaultCurriculumYear"
                    name="default_curriculum_year"
                    type="text"
                    maxlength="9"
                    pattern="[0-9]{4}-[0-9]{4}"
                    title="Enter two four-digit years separated by a hyphen, for example 2026-2027."
                    aria-describedby="curriculumYearHelp"
                    value="<?php echo $escapeConfigureValue($defaultCurriculumYear); ?>"
                    required
                    <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 disabled:bg-slate-100"
                >
            </label>
        </section>

        <section class="h-full rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="courseMinimumHeading">
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
                    value="<?php echo $minimumBooks; ?>"
                    required
                    <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 disabled:bg-slate-100"
                >
            </label>
        </section>

        <section class="h-full rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 lg:col-span-2" aria-labelledby="referencePolicyHeading">
            <div class="border-b border-slate-200 pb-4">
                <h3 id="referencePolicyHeading" class="text-lg font-bold text-slate-900">Reference-age policy</h3>
                <p class="mt-1 text-sm leading-6 text-slate-600">Choose the institution’s approved reference recency rule. This is separate from copyright reporting and does not determine legal copyright status. You can save other settings while this remains unconfigured; confirm the approved rule whenever you set or change it.</p>
            </div>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <label for="referencePolicyDirection" class="text-sm font-semibold text-slate-700">
                    Policy direction
                    <select
                        id="referencePolicyDirection"
                        name="reference_policy_direction"
                        aria-describedby="referencePolicyHelp"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                        class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 disabled:bg-slate-100"
                    >
                        <option value="" <?php echo $referencePolicyDirection === null ? 'selected' : ''; ?>>Select the approved rule</option>
                        <option value="within" <?php echo $referencePolicyDirection === 'within' ? 'selected' : ''; ?>>Published within the last threshold years</option>
                        <option value="at_least" <?php echo $referencePolicyDirection === 'at_least' ? 'selected' : ''; ?>>At least the threshold years old</option>
                    </select>
                </label>
                <label for="referencePolicyYears" class="text-sm font-semibold text-slate-700">
                    Threshold (years)
                    <input
                        id="referencePolicyYears"
                        name="reference_policy_years"
                        type="number"
                        min="1"
                        max="1000"
                        step="1"
                        value="<?php echo $referencePolicyYears ?? ''; ?>"
                        aria-describedby="referencePolicyHelp"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                        class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 disabled:bg-slate-100"
                    >
                </label>
            </div>
            <p id="referencePolicyHelp" class="mt-4 text-sm leading-6 text-slate-600">“Published within” means no older than the threshold; “at least” means the title is that many years old or older. The threshold must come from the institution’s approved policy.</p>
            <label for="referencePolicyConfirmed" class="mt-4 flex items-start gap-3 text-sm font-medium leading-6 text-slate-700">
                <input
                    id="referencePolicyConfirmed"
                    name="reference_policy_confirmed"
                    type="checkbox"
                    value="1"
                    <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    class="mt-1 h-4 w-4 flex-shrink-0 rounded border-slate-300 text-rose-600 focus:ring-rose-500 disabled:cursor-not-allowed"
                >
                <span>I confirm this new or changed rule and threshold match an approved institutional reference-age policy.</span>
            </label>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between lg:col-span-2">
            <p
                id="configureMessage"
                class="text-sm leading-6 text-slate-600"
                role="status"
                aria-live="polite"
            ><?php echo $loadError === null
                ? ($referencePolicyDirection === null
                    ? 'Reference-age policy is not configured. You can save other settings or configure it when the approved policy is available.'
                    : 'No unsaved changes.')
                : ''; ?></p>
            <div class="flex flex-col-reverse gap-3 sm:flex-row">
                <button
                    id="discardConfigureButton"
                    type="button"
                    disabled
                    class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >Discard changes</button>
                <button
                    id="saveConfigureButton"
                    type="submit"
                    disabled
                    <?php echo $loadError !== null ? 'data-readonly="true"' : ''; ?>
                    class="inline-flex min-h-11 items-center justify-center rounded-lg bg-rose-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-400"
                >Save settings</button>
            </div>
        </div>
    </form>
</div>
<script src="assets/js/configure.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/configure.js'); ?>"></script>
