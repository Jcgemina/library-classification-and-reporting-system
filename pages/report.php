<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=report');
    exit;
}

requireLogin();

$loadProgressData = static function (PDO $pdo, int $year): array {
    $programs = $pdo->query(
        'SELECT p.id AS program_id, p.college_id, p.name AS program_name, col.name AS college_name
         FROM programs p
         INNER JOIN colleges col ON col.id = p.college_id
         WHERE p.status = \'active\' AND col.status = \'active\'
         ORDER BY col.name, p.name'
    )->fetchAll(PDO::FETCH_ASSOC);

    $majorsByProgram = [];
    $majors = $pdo->query(
        'SELECT m.id AS major_id, m.program_id, m.name AS major_name
         FROM majors m
         INNER JOIN programs p ON p.id = m.program_id
         INNER JOIN colleges col ON col.id = p.college_id
         WHERE m.status = \'active\' AND p.status = \'active\' AND col.status = \'active\'
         ORDER BY m.name'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($majors as $major) {
        $majorsByProgram[(int) $major['program_id']][] = $major;
    }

    $progressStatement = $pdo->prepare('SELECT * FROM program_progress WHERE progress_year = :year');
    $progressStatement->execute([':year' => $year]);
    $progressByScope = [];
    foreach ($progressStatement->fetchAll(PDO::FETCH_ASSOC) as $progress) {
        $key = (int) $progress['program_id'] . ':' . (int) ($progress['major_id'] ?? 0);
        $progressByScope[$key] = $progress;
    }

    $institutionProfile = $pdo->query(
        'SELECT institution_name, library_name, institution_address, contact_email, contact_phone
         FROM library_configuration WHERE id = 1'
    )->fetch(PDO::FETCH_ASSOC);
    if ($institutionProfile === false) {
        throw new PDOException('The library configuration record is missing.');
    }

    $rows = [];
    $colleges = [];
    $programOptions = [];
    foreach ($programs as $program) {
        $programId = (int) $program['program_id'];
        $collegeId = (int) $program['college_id'];
        $colleges[$collegeId] = $program['college_name'];
        $programOptions[$programId] = [
            'college_id' => $collegeId,
            'name' => $program['program_name'],
        ];

        $programMajors = $majorsByProgram[$programId] ?? [];
        $scopes = $programMajors !== []
            ? $programMajors
            : [['major_id' => 0, 'major_name' => '']];
        foreach ($scopes as $scope) {
            $majorId = (int) ($scope['major_id'] ?? 0);
            $progress = $progressByScope[$programId . ':' . $majorId] ?? [];
            $updatedMonths = [];
            $actuals = [];

            foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $quarter) {
                $quarterKey = strtolower($quarter);
                $updatedValue = $progress[$quarterKey . '_updated_month'] ?? null;
                $updatedMonths[$quarter] = $updatedValue !== null && $updatedValue !== '' ? substr((string) $updatedValue, 0, 7) : '';
                $actuals[$quarter] = (bool) ($progress[$quarterKey . '_actual'] ?? false);
            }

            $targetQuarter = $progress['target_quarter'] ?? 'Q1';
            $rows[] = [
                'college_id' => $collegeId,
                'college_name' => $program['college_name'],
                'program_id' => $programId,
                'program_name' => $program['program_name'],
                'major_id' => $majorId,
                'major_name' => $scope['major_name'],
                'target_quarter' => $targetQuarter,
                'actuals' => $actuals,
                'updated_months' => $updatedMonths,
                'updated_at' => $progress['updated_at'] ?? null,
            ];
        }
    }

    return [
        'rows' => $rows,
        'colleges' => $colleges,
        'programs' => $programOptions,
        'institution_profile' => $institutionProfile,
        'year' => $year,
    ];
};

$action = requestString($_GET, 'action');
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pdo instanceof PDO) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'The database connection is unavailable.']);
        exit;
    }

    if ($action === 'data') {
        $year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT);
        if ($year === false || $year < 2000 || $year > 2200) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Choose a valid report year.']);
            exit;
        }

        try {
            echo json_encode(['success' => true, 'data' => $loadProgressData($pdo, $year)], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (PDOException $exception) {
            error_log('Report progress load failed: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Report progress could not be loaded. Apply the latest schema.sql, then try again.']);
        }
        exit;
    }

    if ($action === 'save') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
            exit;
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'The report update must contain valid JSON.']);
            exit;
        }
        $programId = filter_var($payload['program_id'] ?? null, FILTER_VALIDATE_INT);
        $majorValue = $payload['major_id'] ?? null;
        $majorId = $majorValue === null || $majorValue === '' || $majorValue === 0 || $majorValue === '0'
            ? null
            : filter_var($majorValue, FILTER_VALIDATE_INT);
        $year = filter_var($payload['year'] ?? null, FILTER_VALIDATE_INT);
        $quarter = strtoupper((string) ($payload['quarter'] ?? ''));
        $field = (string) ($payload['field'] ?? '');
        $value = $payload['value'] ?? null;

        if ($programId === false || $programId < 1 ||
            ($majorId !== null && ($majorId === false || $majorId < 1)) ||
            $year === false || $year < 2000 || $year > 2200 ||
            !in_array($quarter, ['Q1', 'Q2', 'Q3', 'Q4'], true) ||
            !in_array($field, ['target', 'actual', 'updated_as_of'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'The report update contains invalid values.']);
            exit;
        }
        if ($field === 'actual' && !in_array($value, [0, 1, '0', '1', false, true], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Choose a valid completion status.']);
            exit;
        }
        if ($field === 'updated_as_of' && $value !== null && $value !== '' &&
            (!is_string($value) || preg_match('/^(20\d{2}|21\d{2}|2200)-(0[1-9]|1[0-2])$/', $value) !== 1)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Choose a valid update month.']);
            exit;
        }

        try {
            $scopeStatement = $pdo->prepare(
                'SELECT p.id
                 FROM programs p
                 INNER JOIN colleges col ON col.id = p.college_id AND col.status = \'active\'
                 WHERE p.id = :program_id AND p.status = \'active\''
            );
            $scopeStatement->execute([':program_id' => $programId]);
            if (!$scopeStatement->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'The selected program is no longer active.']);
                exit;
            }
            if ($majorId !== null) {
                $majorStatement = $pdo->prepare(
                    'SELECT id FROM majors WHERE id = :major_id AND program_id = :program_id AND status = \'active\''
                );
                $majorStatement->execute([':major_id' => $majorId, ':program_id' => $programId]);
                if (!$majorStatement->fetchColumn()) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'The selected major is not active for this program.']);
                    exit;
                }
            }

            $pdo->beginTransaction();
            $insertStatement = $pdo->prepare(
                'INSERT INTO program_progress (program_id, major_id, major_scope_id, progress_year)
                 VALUES (:program_id, :major_id, :major_scope_id, :progress_year)
                 ON DUPLICATE KEY UPDATE progress_id = LAST_INSERT_ID(progress_id)'
            );
            $insertStatement->execute([
                ':program_id' => $programId,
                ':major_id' => $majorId,
                ':major_scope_id' => $majorId ?? 0,
                ':progress_year' => $year,
            ]);

            $quarterKey = strtolower($quarter);
            if ($field === 'target') {
                $column = 'target_quarter';
                $updateValue = $quarter;
            } elseif ($field === 'actual') {
                $column = $quarterKey . '_actual';
                $updateValue = (int) (bool) $value;
            } else {
                $column = $quarterKey . '_updated_month';
                $updateValue = $value === null || $value === '' ? null : (string) $value . '-01';
            }
            $updateStatement = $pdo->prepare(
                "UPDATE program_progress
                 SET {$column} = :value
                 WHERE program_id = :program_id AND major_id <=> :major_id AND progress_year = :progress_year"
            );
            $updateStatement->execute([
                ':value' => $updateValue,
                ':program_id' => $programId,
                ':major_id' => $majorId,
                ':progress_year' => $year,
            ]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Report progress saved.']);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Report progress save failed: ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Report progress could not be saved. Apply the latest schema.sql, then try again.']);
        }
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Unknown report action.']);
    exit;
}

?>

<div class="live-report-page space-y-5">
    <header class="reports-heading">
        <div>
            <h1>Detailed Reports</h1>
            <p class="page-description">Program and major verification progress by quarter and academic year.</p>
        </div>
    </header>

    <div id="reportPreviewModal" class="report-preview-modal hidden" role="dialog" aria-modal="true" aria-labelledby="reportPreviewTitle">
        <div class="report-preview-shell">
            <div class="report-preview-toolbar flex items-center justify-between gap-3 border-b border-slate-200 bg-[#f7f7f7] px-4 py-3">
                <div class="flex items-center gap-2 text-[13px] font-medium text-slate-600">
                    <i data-lucide="file-text" class="h-4 w-4 text-slate-700" aria-hidden="true"></i>
                    <span id="reportPreviewTitle">Print preview · Verify the report before printing.</span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="reportPreviewPrint" class="live-button live-button-primary">
                        <i data-lucide="printer" class="h-4 w-4" aria-hidden="true"></i>
                        Print
                    </button>
                    <button type="button" id="reportPreviewClose" class="live-button">
                        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
                        Close
                    </button>
                </div>
            </div>
            <div class="report-preview-scroll overflow-auto p-5">
                <div id="reportPreviewDocument" class="mx-auto max-w-[1120px] bg-white p-0 shadow-[0_18px_48px_rgba(17,24,39,0.12)]"></div>
            </div>
        </div>
    </div>

    <section class="report-workspace" aria-label="Program verification report">
        <div class="report-toolbar">
            <div class="report-filters">
                <label class="report-filter-field" for="reportCollegeFilter">College
                    <select id="reportCollegeFilter" class="report-filter">
                        <option value="">All colleges</option>
                    </select>
                </label>
                <label class="report-filter-field" for="reportProgramFilter">Program
                    <select id="reportProgramFilter" class="report-filter">
                        <option value="">All programs</option>
                    </select>
                </label>
                <label class="report-filter-field" for="reportMajorFilter">Major
                    <select id="reportMajorFilter" class="report-filter">
                        <option value="">All majors</option>
                    </select>
                </label>
                <label class="report-filter-field report-year-field" for="reportYearFilter">Academic year
                    <select id="reportYearFilter" class="report-filter">
                        <?php $currentYear = (int) date('Y'); ?>
                        <?php for ($year = $currentYear - 3; $year <= $currentYear + 1; $year++): ?>
                            <option value="<?php echo $year; ?>" <?php echo $year === $currentYear ? 'selected' : ''; ?>><?php echo $year; ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
            </div>
            <div class="report-actions">
                <span id="reportResultCount" class="report-result-count" aria-live="polite">Loading report…</span>
                <button type="button" id="reportClearFilters" class="live-button" disabled>Clear filters</button>
                <button type="button" id="reportExportButton" class="live-button live-button-primary report-export-button" aria-haspopup="true" aria-expanded="false" aria-controls="reportExportMenu" disabled>
                    <i data-lucide="file-down" aria-hidden="true"></i>Export report<i data-lucide="chevron-down" aria-hidden="true"></i>
                </button>
                <div class="report-export-menu" id="reportExportMenu" popover="auto" role="menu" aria-label="Export report options">
                    <button type="button" role="menuitem" class="report-export-option" data-report-export="print"><i data-lucide="printer" aria-hidden="true"></i><span>Print</span></button>
                    <button type="button" role="menuitem" class="report-export-option" data-report-export="xls"><i data-lucide="file-spreadsheet" aria-hidden="true"></i><span>Excel workbook (.xls)</span></button>
                    <button type="button" role="menuitem" class="report-export-option" data-report-export="csv"><i data-lucide="file-text" aria-hidden="true"></i><span>CSV file (.csv)</span></button>
                </div>
            </div>
        </div>

        <div class="report-guidance">
            <i data-lucide="info" aria-hidden="true"></i>
            <p>Choose the target quarter, record whether it is complete, and set the latest update month. Changes are saved to the database.</p>
        </div>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th scope="col" class="report-number-column">No.</th>
                        <th scope="col">Program / Major</th>
                        <th scope="col" class="report-quarter-column">Target Q</th>
                        <th scope="col" class="report-actual-column">Actual</th>
                        <th scope="col" class="report-updated-column">Updated as of</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody">
                    <tr><td colspan="5" class="report-empty">Loading report data…</td></tr>
                </tbody>
            </table>
        </div>
        <div id="reportNoMatches" class="report-empty hidden" role="status">
            No academic records match these filters. Clear filters to see all active records.
        </div>
        <div id="reportEmptyState" class="report-empty hidden">
            <i data-lucide="folder-search" aria-hidden="true"></i>
            <strong>No active academic programs</strong>
            <span>Active programs will appear here once they are added to the academic catalog.</span>
        </div>
    </section>
</div>
<script src="assets/js/report.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/report.js'); ?>"></script>
