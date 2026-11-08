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
    $institutionName = trim(requestString($_POST, 'institution_name'));
    $libraryName = trim(requestString($_POST, 'library_name'));
    $institutionAddress = trim(requestString($_POST, 'institution_address'));
    $contactEmail = trim(requestString($_POST, 'contact_email'));
    $contactPhone = trim(requestString($_POST, 'contact_phone'));
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
    $profileFields = [
        'institution_name' => [$institutionName, 180],
        'library_name' => [$libraryName, 180],
        'institution_address' => [$institutionAddress, 255],
        'contact_email' => [$contactEmail, 254],
        'contact_phone' => [$contactPhone, 40],
    ];
    foreach ($profileFields as $fieldName => [$fieldValue, $maximumLength]) {
        $fieldLength = function_exists('mb_strlen') ? mb_strlen($fieldValue, 'UTF-8') : strlen($fieldValue);
        if ($fieldLength > $maximumLength) {
            configureJsonResponse([
                'success' => false,
                'message' => 'This profile field exceeds its maximum length of ' . $maximumLength . ' characters.',
                'field' => $fieldName,
            ], 422);
        }
    }
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Enter a valid contact email address.',
            'field' => 'contact_email',
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
            'INSERT INTO library_configuration (id, minimum_books_per_course, default_curriculum_year, institution_name, library_name, institution_address, contact_email, contact_phone, reference_policy_direction, reference_policy_years)
             VALUES (1, :minimum_books_per_course, :default_curriculum_year, :institution_name, :library_name, :institution_address, :contact_email, :contact_phone, :reference_policy_direction, :reference_policy_years)
             ON DUPLICATE KEY UPDATE
                minimum_books_per_course = VALUES(minimum_books_per_course),
                default_curriculum_year = VALUES(default_curriculum_year),
                institution_name = VALUES(institution_name),
                library_name = VALUES(library_name),
                institution_address = VALUES(institution_address),
                contact_email = VALUES(contact_email),
                contact_phone = VALUES(contact_phone),
                reference_policy_direction = VALUES(reference_policy_direction),
                reference_policy_years = VALUES(reference_policy_years)'
        );
        $configurationStatement->execute([
            ':minimum_books_per_course' => $minimumBooks,
            ':default_curriculum_year' => $defaultCurriculumYear,
            ':institution_name' => $institutionName,
            ':library_name' => $libraryName,
            ':institution_address' => $institutionAddress,
            ':contact_email' => $contactEmail,
            ':contact_phone' => $contactPhone,
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
$institutionName = '';
$libraryName = '';
$institutionAddress = '';
$contactEmail = '';
$contactPhone = '';
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
            'SELECT minimum_books_per_course, default_curriculum_year, institution_name, library_name, institution_address, contact_email, contact_phone, reference_policy_direction, reference_policy_years FROM library_configuration WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);

        if (count($rangeRows) !== 3 || $configuration === false) {
            throw new PDOException('Required library configuration records are missing.');
        }

        $copyrightRanges = array_map('intval', $rangeRows);
        $minimumBooks = (int) $configuration['minimum_books_per_course'];
        $defaultCurriculumYear = (string) $configuration['default_curriculum_year'];
        $institutionName = (string) $configuration['institution_name'];
        $libraryName = (string) $configuration['library_name'];
        $institutionAddress = (string) $configuration['institution_address'];
        $contactEmail = (string) $configuration['contact_email'];
        $contactPhone = (string) $configuration['contact_phone'];
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
    class="settings-screen"
    data-configure-page
    data-active-section="<?php echo ($_GET['section'] ?? '') === 'rules' ? 'rules' : 'institution'; ?>"
    data-csrf-token="<?php echo $escapeConfigureValue($csrfToken); ?>"
    data-saved-reference-policy-direction="<?php echo $escapeConfigureValue($referencePolicyDirection); ?>"
    data-saved-reference-policy-years="<?php echo $escapeConfigureValue($referencePolicyYears); ?>"
>
    <header class="settings-page-heading">
        <div>
            <h1 id="configurePageHeading"><?php echo ($_GET['section'] ?? '') === 'rules' ? 'Library Rules' : 'Institutional Profile'; ?></h1>
            <p class="page-description" id="configurePageDescription"><?php echo ($_GET['section'] ?? '') === 'rules'
                ? 'Set the reporting and coverage rules used by library reports and prospectus records.'
                : 'Manage the institution details shown in report previews.'; ?></p>
        </div>
        <span class="settings-preview-chip"><i data-lucide="settings-2" aria-hidden="true"></i>Administrative settings</span>
    </header>

    <?php if ($loadError !== null): ?>
        <div class="settings-alert" role="alert">
            <?php echo $escapeConfigureValue($loadError); ?>
        </div>
    <?php endif; ?>

    <form id="configureForm" class="configure-form" novalidate>
        <div id="institutionProfileSettings" class="settings-sheet scroll-mt-24" data-configure-group="institution">
            <section class="settings-section" aria-labelledby="institutionProfileHeading">
                <div>
                    <h2 class="settings-section-title" id="institutionProfileHeading">Institution profile</h2>
                    <p class="settings-section-copy">Add your institution and library details for identification in report previews. All fields are optional.</p>
                </div>
                <div class="settings-fields">
                <label for="institutionName" class="settings-field">
                    Institution name
                    <input
                        id="institutionName"
                        name="institution_name"
                        type="text"
                        maxlength="180"
                        value="<?php echo $escapeConfigureValue($institutionName); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                </label>
                <label for="libraryName" class="settings-field">
                    Library name
                    <input
                        id="libraryName"
                        name="library_name"
                        type="text"
                        maxlength="180"
                        value="<?php echo $escapeConfigureValue($libraryName); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                </label>
                <label for="institutionAddress" class="settings-field is-wide">
                    Address
                    <input
                        id="institutionAddress"
                        name="institution_address"
                        type="text"
                        maxlength="255"
                        value="<?php echo $escapeConfigureValue($institutionAddress); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                </label>
                <label for="contactEmail" class="settings-field">
                    Contact email
                    <input
                        id="contactEmail"
                        name="contact_email"
                        type="email"
                        maxlength="254"
                        value="<?php echo $escapeConfigureValue($contactEmail); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                </label>
                <label for="contactPhone" class="settings-field">
                    Contact phone
                    <input
                        id="contactPhone"
                        name="contact_phone"
                        type="tel"
                        maxlength="40"
                        value="<?php echo $escapeConfigureValue($contactPhone); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                </label>
            </div>
            </section>
        </div>

        <div id="libraryRulesSettings" class="settings-sheet scroll-mt-24" data-configure-group="rules">
            <section class="settings-section" aria-labelledby="copyrightSettingsHeading">
                <div>
                    <h2 class="settings-section-title" id="copyrightSettingsHeading">Copyright year ranges</h2>
                    <p class="settings-section-copy">Each threshold counts titles dated from the current year back through that many years, using the copyright year or publication year when no copyright year is recorded. The windows overlap: a title in the shortest window also appears in every longer window. Keep the thresholds in ascending order.</p>
                </div>
                <div class="settings-range-fields">
                <?php foreach (array_values($copyrightRanges) as $index => $yearsThreshold): ?>
                    <label for="copyrightRange<?php echo $index + 1; ?>" class="settings-range-item">
                        Window <?php echo $index + 1; ?> — years
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
                        >
                    </label>
                <?php endforeach; ?>
            </div>
            </section>

        <section class="settings-section" aria-labelledby="curriculumYearHeading">
            <div>
                <h2 class="settings-section-title" id="curriculumYearHeading">Default prospectus curriculum year</h2>
                <p class="settings-section-copy" id="curriculumYearHelp">Prefills the curriculum year when an admin creates a new prospectus. Use two four-digit years separated by a hyphen, for example 2026-2027. Admins can still change it for each prospectus.</p>
            </div>
            <label for="defaultCurriculumYear" class="settings-field">
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
                >
            </label>
        </section>

        <section class="settings-section" aria-labelledby="courseMinimumHeading">
            <div>
                <h2 class="settings-section-title" id="courseMinimumHeading">Minimum books per course</h2>
                <p class="settings-section-copy">A course meets this readiness requirement when at least this many active book titles are linked to it. Copies of the same title count once.</p>
            </div>
            <label for="minimumBooksPerCourse" class="settings-field">
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
                >
            </label>
        </section>

        <section class="settings-section" aria-labelledby="referencePolicyHeading">
            <div>
                <h2 class="settings-section-title" id="referencePolicyHeading">Reference-age policy</h2>
                <p class="settings-section-copy">Choose the institution’s approved reference recency rule. This is separate from copyright reporting and does not determine legal copyright status. You can save other settings while this remains unconfigured; confirm the approved rule whenever you set or change it.</p>
            </div>
            <div class="settings-fields">
                <label for="referencePolicyDirection" class="settings-field">
                    Policy direction
                    <select
                        id="referencePolicyDirection"
                        name="reference_policy_direction"
                        aria-describedby="referencePolicyHelp"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                        <option value="" <?php echo $referencePolicyDirection === null ? 'selected' : ''; ?>>Select the approved rule</option>
                        <option value="within" <?php echo $referencePolicyDirection === 'within' ? 'selected' : ''; ?>>Published within the last threshold years</option>
                        <option value="at_least" <?php echo $referencePolicyDirection === 'at_least' ? 'selected' : ''; ?>>At least the threshold years old</option>
                    </select>
                </label>
                <label for="referencePolicyYears" class="settings-field">
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
                    >
                </label>
                <p id="referencePolicyHelp" class="settings-help">“Published within” means no older than the threshold; “at least” means the title is that many years old or older. The threshold must come from the institution’s approved policy.</p>
                <label for="referencePolicyConfirmed" class="settings-check-row">
                    <input
                        id="referencePolicyConfirmed"
                        name="reference_policy_confirmed"
                        type="checkbox"
                        value="1"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                    <span>I confirm this new or changed rule and threshold match an approved institutional reference-age policy.</span>
                </label>
            </div>
        </section>
        </div>

        <div class="settings-actions">
            <p
                id="configureMessage"
                class="settings-status"
                role="status"
                aria-live="polite"
            ><?php echo $loadError === null
                ? ($referencePolicyDirection === null
                    ? 'Reference-age policy is not configured. You can save other settings or configure it when the approved policy is available.'
                    : 'No unsaved changes.')
                : ''; ?></p>
                <button
                    id="discardConfigureButton"
                    type="button"
                    disabled
                    class="settings-button"
                >Discard changes</button>
                <button
                    id="saveConfigureButton"
                    type="submit"
                    disabled
                    <?php echo $loadError !== null ? 'data-readonly="true"' : ''; ?>
                    class="settings-button settings-button-primary"
                >Save settings</button>
        </div>
    </form>
</div>
<script src="assets/js/configure.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/configure.js'); ?>"></script>
