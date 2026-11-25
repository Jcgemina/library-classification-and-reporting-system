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
$backupCsrfToken = $_SESSION['backup_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['backup_csrf'] = $backupCsrfToken;

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
    $referencePolicySourceInput = trim(requestString($_POST, 'reference_policy_source'));
    $referencePolicySource = $referencePolicySourceInput !== '' ? $referencePolicySourceInput : null;
    $referencePolicyEffectiveDateInput = trim(requestString($_POST, 'reference_policy_effective_date'));
    $referencePolicyEffectiveDate = $referencePolicyEffectiveDateInput !== '' ? $referencePolicyEffectiveDateInput : null;
    $referencePolicyConfirmed = requestString($_POST, 'reference_policy_confirmed') === '1';
    try {
        $currentConfiguration = $pdo->query(
            'SELECT reference_policy_direction, reference_policy_years, reference_policy_source, reference_policy_effective_date
             FROM library_configuration WHERE id = 1'
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
    $currentReferencePolicySource = $currentConfiguration['reference_policy_source'];
    $currentReferencePolicyEffectiveDate = $currentConfiguration['reference_policy_effective_date'];
    $referencePolicyChanged = $referencePolicyDirection !== $currentReferencePolicyDirection
        || $referencePolicyYears !== $currentReferencePolicyYears
        || $referencePolicySource !== $currentReferencePolicySource
        || $referencePolicyEffectiveDate !== $currentReferencePolicyEffectiveDate;

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
    $referencePolicySourceLength = $referencePolicySource !== null
        ? (function_exists('mb_strlen') ? mb_strlen($referencePolicySource, 'UTF-8') : strlen($referencePolicySource))
        : 0;
    if ($referencePolicyChanged && ($referencePolicySource === null || $referencePolicySourceLength > 255)) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Enter the title or reference for the approved policy, using no more than 255 characters.',
            'field' => 'reference_policy_source',
        ], 422);
    }
    $validPolicyEffectiveDate = $referencePolicyEffectiveDate !== null
        && isValidCalendarDate($referencePolicyEffectiveDate);
    if ($referencePolicyChanged && !$validPolicyEffectiveDate) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Enter the effective date shown in the approved reference-age policy.',
            'field' => 'reference_policy_effective_date',
        ], 422);
    }
    if ($referencePolicyChanged && !$referencePolicyConfirmed) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Confirm that the rule, source, and effective date match the institution’s approved policy.',
            'field' => 'reference_policy_confirmed',
        ], 422);
    }
    $policyChangedBy = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($referencePolicyChanged && ($policyChangedBy === false || $policyChangedBy < 1)) {
        configureJsonResponse([
            'success' => false,
            'message' => 'Your session could not be verified. Sign in again before changing the reference-age policy.',
        ], 401);
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

        if ($referencePolicyChanged) {
            $historyStatement = $pdo->prepare(
                'INSERT INTO reference_age_policy_versions (direction, years_threshold, policy_source, effective_date, changed_by)
                 VALUES (:direction, :years_threshold, :policy_source, :effective_date, :changed_by)'
            );
            $historyStatement->execute([
                ':direction' => $referencePolicyDirection,
                ':years_threshold' => $referencePolicyYears,
                ':policy_source' => $referencePolicySource,
                ':effective_date' => $referencePolicyEffectiveDate,
                ':changed_by' => $policyChangedBy,
            ]);
            $historyVersionStatement = $pdo->prepare(
                'SELECT v.direction, v.years_threshold, v.policy_source, v.effective_date, v.changed_at, u.full_name AS changed_by_name
                 FROM reference_age_policy_versions v
                 LEFT JOIN users u ON u.id = v.changed_by
                 WHERE v.id = :id'
            );
            $historyVersionStatement->execute([':id' => (int) $pdo->lastInsertId()]);
            $savedPolicyVersion = $historyVersionStatement->fetch(PDO::FETCH_ASSOC);
            if ($savedPolicyVersion === false) {
                throw new PDOException('The saved reference-age policy version could not be loaded.');
            }
        } else {
            $savedPolicyVersion = null;
        }

        $configurationStatement = $pdo->prepare(
            'INSERT INTO library_configuration (id, minimum_books_per_course, default_curriculum_year, institution_name, library_name, institution_address, contact_email, contact_phone, reference_policy_direction, reference_policy_years, reference_policy_source, reference_policy_effective_date)
             VALUES (1, :minimum_books_per_course, :default_curriculum_year, :institution_name, :library_name, :institution_address, :contact_email, :contact_phone, :reference_policy_direction, :reference_policy_years, :reference_policy_source, :reference_policy_effective_date)
             ON DUPLICATE KEY UPDATE
                minimum_books_per_course = VALUES(minimum_books_per_course),
                default_curriculum_year = VALUES(default_curriculum_year),
                institution_name = VALUES(institution_name),
                library_name = VALUES(library_name),
                institution_address = VALUES(institution_address),
                contact_email = VALUES(contact_email),
                contact_phone = VALUES(contact_phone),
                reference_policy_direction = VALUES(reference_policy_direction),
                reference_policy_years = VALUES(reference_policy_years),
                reference_policy_source = VALUES(reference_policy_source),
                reference_policy_effective_date = VALUES(reference_policy_effective_date)'
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
            ':reference_policy_source' => $referencePolicyDirection !== null ? $referencePolicySource : null,
            ':reference_policy_effective_date' => $referencePolicyDirection !== null ? $referencePolicyEffectiveDate : null,
        ]);
        $pdo->commit();

        configureJsonResponse([
            'success' => true,
            'message' => 'Settings saved.',
            'policy_version' => $savedPolicyVersion,
        ]);
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
$referencePolicySource = null;
$referencePolicyEffectiveDate = null;
$referencePolicyHistory = [];
$loadError = null;
if (!$pdo instanceof PDO) {
    $loadError = 'The database connection is unavailable. Settings cannot be loaded or changed.';
} else {
    try {
        $rangeRows = $pdo->query(
            'SELECT years_threshold FROM copyright_year_ranges WHERE is_active = 1 ORDER BY sort_order, years_threshold'
        )->fetchAll(PDO::FETCH_COLUMN);
        $configuration = $pdo->query(
            'SELECT minimum_books_per_course, default_curriculum_year, institution_name, library_name, institution_address,
                    contact_email, contact_phone, reference_policy_direction, reference_policy_years,
                    reference_policy_source, reference_policy_effective_date
             FROM library_configuration WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        $referencePolicyHistory = $pdo->query(
            'SELECT v.direction, v.years_threshold, v.policy_source, v.effective_date, v.changed_at, u.full_name AS changed_by_name
             FROM reference_age_policy_versions v
             LEFT JOIN users u ON u.id = v.changed_by
             ORDER BY v.changed_at DESC, v.id DESC LIMIT 10'
        )->fetchAll(PDO::FETCH_ASSOC);

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
        $referencePolicySource = $configuration['reference_policy_source'];
        $referencePolicyEffectiveDate = $configuration['reference_policy_effective_date'];
        if ($defaultCurriculumYear === '') {
            $defaultCurriculumYear = date('Y') . '-' . ((int) date('Y') + 1);
        }
    } catch (PDOException $exception) {
        error_log('Library configuration could not be loaded: ' . $exception->getMessage());
        $loadError = 'Settings could not be loaded. Check that the latest schema.sql has been applied.';
    }
}

$escapeConfigureValue = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$section = $_GET['section'] ?? '';
$section = in_array($section, ['rules', 'backup'], true) ? $section : 'institution';
$configureBreadcrumbLabel = $section === 'rules'
    ? 'Library Rules'
    : ($section === 'backup' ? 'Backup & Restore' : 'Institutional Profile');

?>

<div
    class="settings-screen"
    data-configure-page
    data-active-section="<?php echo in_array(($_GET['section'] ?? ''), ['rules', 'backup'], true) ? ($_GET['section'] ?? '') : 'institution'; ?>"
    data-csrf-token="<?php echo $escapeConfigureValue($csrfToken); ?>"
    data-backup-csrf-token="<?php echo $escapeConfigureValue($backupCsrfToken); ?>"
    data-saved-reference-policy-direction="<?php echo $escapeConfigureValue($referencePolicyDirection); ?>"
    data-saved-reference-policy-years="<?php echo $escapeConfigureValue($referencePolicyYears); ?>"
    data-saved-reference-policy-source="<?php echo $escapeConfigureValue($referencePolicySource); ?>"
    data-saved-reference-policy-effective-date="<?php echo $escapeConfigureValue($referencePolicyEffectiveDate); ?>"
>
    <header class="settings-page-heading">
        <div>
            <nav class="app-breadcrumbs" aria-label="Breadcrumb">
                <ol>
                    <li><a href="app.php?page=account">Settings</a></li>
                    <li aria-current="page"><span id="configureBreadcrumbCurrent"><?php echo $escapeConfigureValue($configureBreadcrumbLabel); ?></span></li>
                </ol>
            </nav>
            <h1 id="configurePageHeading"><?php
                $section = $_GET['section'] ?? '';
                if ($section === 'rules') {
                    echo 'Library Rules';
                } elseif ($section === 'backup') {
                    echo 'Backup & Restore';
                } else {
                    echo 'Institutional Profile';
                }
            ?></h1>
            <p class="page-description" id="configurePageDescription"><?php
                $section = $_GET['section'] ?? '';
                if ($section === 'rules') {
                    echo 'Set the reporting and coverage rules used by library reports and prospectus records.';
                } elseif ($section === 'backup') {
                    echo 'Create a ZIP backup of the library database and uploaded files, or restore them from a saved backup.';
                } else {
                    echo 'Manage the institution details shown in report previews.';
                }
            ?></p>
        </div>
        <span id="configureContextBadge" class="<?php echo ($_GET['section'] ?? '') === 'backup' ? 'backup-access-label' : 'settings-preview-chip'; ?>">
            <i data-lucide="<?php echo ($_GET['section'] ?? '') === 'backup' ? 'shield-check' : 'settings-2'; ?>" aria-hidden="true"></i>
            <?php echo ($_GET['section'] ?? '') === 'backup' ? 'Admin only' : 'Administrative settings'; ?>
        </span>
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
                    <p class="settings-section-copy">These names and contact details appear in the report header. All fields are optional.</p>
                </div>
                <div class="settings-fields">
                <div class="settings-field">
                    <label for="institutionName">Institution name</label>
                    <input
                        id="institutionName"
                        name="institution_name"
                        type="text"
                        maxlength="180"
                        aria-describedby="institutionNameHelp"
                        value="<?php echo $escapeConfigureValue($institutionName); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                    <span class="settings-field-help" id="institutionNameHelp">Shown as the main heading in report previews.</span>
                </div>
                <div class="settings-field">
                    <label for="libraryName">Library name</label>
                    <input
                        id="libraryName"
                        name="library_name"
                        type="text"
                        maxlength="180"
                        aria-describedby="libraryNameHelp"
                        value="<?php echo $escapeConfigureValue($libraryName); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                    <span class="settings-field-help" id="libraryNameHelp">Shown below the institution name in report previews.</span>
                </div>
                <div class="settings-field is-wide">
                    <label for="institutionAddress">Institution address</label>
                    <input
                        id="institutionAddress"
                        name="institution_address"
                        type="text"
                        maxlength="255"
                        aria-describedby="institutionAddressHelp"
                        value="<?php echo $escapeConfigureValue($institutionAddress); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                    <span class="settings-field-help" id="institutionAddressHelp">Shown with the contact details in report previews.</span>
                </div>
                <div class="settings-field">
                    <label for="contactEmail">Contact email</label>
                    <input
                        id="contactEmail"
                        name="contact_email"
                        type="email"
                        maxlength="254"
                        aria-describedby="contactEmailHelp"
                        value="<?php echo $escapeConfigureValue($contactEmail); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                    <span class="settings-field-help" id="contactEmailHelp">Shown with the address and phone in report previews.</span>
                </div>
                <div class="settings-field">
                    <label for="contactPhone">Contact phone</label>
                    <input
                        id="contactPhone"
                        name="contact_phone"
                        type="tel"
                        maxlength="40"
                        aria-describedby="contactPhoneHelp"
                        value="<?php echo $escapeConfigureValue($contactPhone); ?>"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                    <span class="settings-field-help" id="contactPhoneHelp">Shown with the address and email in report previews.</span>
                </div>
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
                <label for="referencePolicySource" class="settings-field">
                    Approved policy source
                    <input
                        id="referencePolicySource"
                        name="reference_policy_source"
                        type="text"
                        maxlength="255"
                        placeholder="Policy title or document reference"
                        value="<?php echo $escapeConfigureValue($referencePolicySource); ?>"
                        aria-describedby="referencePolicySourceHelp"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                    <span class="settings-field-help" id="referencePolicySourceHelp">Record the title or reference of the institution-approved policy; do not enter a rule from memory.</span>
                </label>
                <label for="referencePolicyEffectiveDate" class="settings-field">
                    Policy effective date
                    <input
                        id="referencePolicyEffectiveDate"
                        name="reference_policy_effective_date"
                        type="date"
                        value="<?php echo $escapeConfigureValue($referencePolicyEffectiveDate); ?>"
                        aria-describedby="referencePolicyEffectiveDateHelp"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                    <span class="settings-field-help" id="referencePolicyEffectiveDateHelp">Use the effective date stated by the policy. Existing rules without recorded metadata are not backfilled.</span>
                </label>
                <label for="referencePolicyConfirmed" class="settings-check-row">
                    <input
                        id="referencePolicyConfirmed"
                        name="reference_policy_confirmed"
                        type="checkbox"
                        value="1"
                        <?php echo $loadError !== null ? 'disabled' : ''; ?>
                    >
                    <span>I confirm the rule, source, and effective date match the approved institutional reference-age policy.</span>
                </label>
            </div>
            <div class="settings-policy-history" aria-labelledby="referencePolicyHistoryHeading">
                <h3 id="referencePolicyHistoryHeading" class="settings-section-title">Recorded policy changes</h3>
                <ul data-policy-history-list>
                    <?php foreach ($referencePolicyHistory as $policyVersion): ?>
                        <li>
                            <strong><?php echo $escapeConfigureValue($policyVersion['direction'] === 'within' ? 'Published within ' . $policyVersion['years_threshold'] . ' years' : 'At least ' . $policyVersion['years_threshold'] . ' years old'); ?></strong>
                            <span><?php echo $escapeConfigureValue($policyVersion['policy_source']); ?> · Effective <?php echo $escapeConfigureValue($policyVersion['effective_date']); ?></span>
                            <time datetime="<?php echo $escapeConfigureValue(str_replace(' ', 'T', (string) $policyVersion['changed_at'])); ?>">Recorded <?php echo $escapeConfigureValue($policyVersion['changed_at']); ?><?php echo $policyVersion['changed_by_name'] !== null ? ' by ' . $escapeConfigureValue($policyVersion['changed_by_name']) : ''; ?></time>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($referencePolicyHistory === []): ?>
                    <p class="settings-field-help" data-policy-history-empty>No policy changes have been recorded since version history was enabled.</p>
                <?php endif; ?>
            </div>
        </section>
        </div>

        <div id="backupRestoreSettings" class="settings-sheet scroll-mt-24" data-configure-group="backup">
            <section class="settings-section" aria-labelledby="backupCreateTitle">
                <div>
                    <h2 class="settings-section-title" id="backupCreateTitle">Create a backup</h2>
                    <p class="settings-section-copy">Save a copy of the library database and uploaded files.</p>
                </div>
                <div>
                    <div class="backup-scope-row"><i data-lucide="check" aria-hidden="true"></i><strong>Backup includes</strong><span class="backup-scope-tag">Database</span><span class="backup-scope-tag">Uploaded files</span></div>
                    <p class="settings-help" style="margin:8px 0 13px">The ZIP is saved in Backup history, where you can download it.</p>
                    <button type="button" class="button button-primary" id="createBackupButton"><i data-lucide="database-backup" aria-hidden="true"></i>Create backup</button>
                </div>
            </section>

            <section class="settings-section" aria-labelledby="backupRestoreTitle">
                <div>
                    <h2 class="settings-section-title" id="backupRestoreTitle">Restore from a backup</h2>
                    <p class="settings-section-copy">Upload a backup ZIP here, or restore one from Backup history.</p>
                </div>
                <div>
                    <label class="settings-field" for="backupRestoreFile">Backup ZIP file
                        <input id="backupRestoreFile" type="file" accept=".zip" aria-describedby="backupRestoreHelp">
                    </label>
                    <p class="settings-help" id="backupRestoreHelp" style="margin:8px 0 13px">Choose a ZIP made by this system. It must include the database and uploaded files.</p>
                    <p class="backup-selected-file" id="backupSelectedFile" hidden></p>
                    <button type="button" class="button button-danger" id="previewRestoreButton" disabled><i data-lucide="rotate-ccw" aria-hidden="true"></i>Check backup</button>
                </div>
            </section>

            <section class="settings-section" aria-labelledby="backupHistoryTitle">
                <div>
                    <h2 class="settings-section-title" id="backupHistoryTitle">Backup history</h2>
                    <p class="settings-section-copy">Download or restore a saved backup.</p>
                </div>
                <div class="backup-history-wrap">
                    <div class="backup-history-toolbar">
                        <p class="backup-history-summary" id="backupHistorySummary" role="status" aria-live="polite">Loading saved backups…</p>
                        <button type="button" class="button" id="viewAllBackupsButton" aria-haspopup="dialog" aria-controls="backupHistoryDialog">
                            <i data-lucide="archive" aria-hidden="true"></i>View all backups
                        </button>
                    </div>
                    <table class="backup-history">
                        <thead><tr><th scope="col">Created</th><th scope="col">Contents</th><th scope="col">Size</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                        <tbody id="backupHistoryRecentRows">
                            <tr><td class="backup-history-message" data-label="Backup history" colspan="5">Loading backups…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <p class="backup-status" id="backupActionStatus" role="status" aria-live="polite"></p>
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

<dialog class="prototype-dialog backup-restore-dialog" id="backupRestoreDialog" aria-labelledby="backupRestoreDialogTitle" aria-describedby="backupRestoreSelectionLabel backupRestoreSelection backupRestoreDialogDescription">
  <h2 id="backupRestoreDialogTitle">Restore this backup?</h2>
  <p class="backup-restore-selection">
    <span id="backupRestoreSelectionLabel">Selected backup:</span>
    <strong id="backupRestoreSelection"></strong>
  </p>
  <div class="backup-restore-warning" id="backupRestoreDialogDescription">
    <i data-lucide="triangle-alert" aria-hidden="true"></i>
    <span>This replaces the current database and uploaded files with the selected backup. You cannot undo this. Create a backup of the current data first if you need to keep it.</span>
  </div>
  <div class="dialog-actions backup-confirm-actions">
    <button type="button" class="button" id="cancelBackupRestore">Cancel</button>
    <button type="button" class="button button-danger" id="confirmBackupRestore">Restore backup</button>
  </div>
</dialog>

<dialog class="prototype-dialog backup-history-dialog" id="backupHistoryDialog" aria-labelledby="backupHistoryDialogTitle" aria-describedby="backupHistoryDialogSummary">
  <div class="backup-history-dialog-header">
    <div>
      <h2 id="backupHistoryDialogTitle">All backups</h2>
      <p class="backup-history-dialog-summary" id="backupHistoryDialogSummary" role="status" aria-live="polite">Loading saved backups…</p>
    </div>
    <button type="button" class="button backup-history-dialog-close" id="closeBackupHistoryDialog" aria-label="Close backup history">
      <i data-lucide="x" aria-hidden="true"></i>
    </button>
  </div>
  <div class="backup-history-wrap">
    <table class="backup-history">
      <thead><tr><th scope="col">Created</th><th scope="col">Contents</th><th scope="col">Size</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
      <tbody id="backupHistoryAllRows">
        <tr><td class="backup-history-message" data-label="Backup history" colspan="5">Loading backups…</td></tr>
      </tbody>
    </table>
  </div>
</dialog>

<script src="assets/js/configure.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/configure.js'); ?>"></script>
<script src="assets/js/backup.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/backup.js'); ?>"></script>
