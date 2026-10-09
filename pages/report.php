<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
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
                $updatedMonths[$quarter] = $updatedValue ? substr((string) $updatedValue, 0, 7) : '';
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
        'year' => $year,
    ];
};

$action = (string) ($_GET['action'] ?? '');
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$escapeReportValue = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
?>

<div class="space-y-5 p-1 text-slate-900 sm:p-2">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Detailed Reports</h2>
            <p class="mt-1 text-sm text-slate-500">Program and major verification progress by quarter and academic year.</p>
        </div>
    </header>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-label="Program verification report">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-4">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <label class="sr-only" for="reportCollegeFilter">Filter by college</label>
                    <select id="reportCollegeFilter" class="min-w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                        <option value="">All colleges</option>
                    </select>
                    <span class="hidden text-slate-300 sm:inline" aria-hidden="true">›</span>
                    <label class="sr-only" for="reportProgramFilter">Filter by program</label>
                    <select id="reportProgramFilter" class="min-w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                        <option value="">All programs</option>
                    </select>
                    <span class="hidden text-slate-300 sm:inline" aria-hidden="true">›</span>
                    <label class="sr-only" for="reportMajorFilter">Filter by major</label>
                    <select id="reportMajorFilter" class="min-w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                        <option value="">All majors</option>
                    </select>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="sr-only" for="reportYearFilter">Academic year</label>
                    <select id="reportYearFilter" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                        <?php $currentYear = (int) date('Y'); ?>
                        <?php for ($year = $currentYear - 3; $year <= $currentYear + 1; $year++): ?>
                            <option value="<?php echo $year; ?>" <?php echo $year === $currentYear ? 'selected' : ''; ?>><?php echo $year; ?></option>
                        <?php endfor; ?>
                    </select>
                    <span id="reportResultCount" class="mr-1 text-xs text-slate-500" aria-live="polite">Loading report…</span>
                    <button type="button" id="reportClearFilters" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-200 disabled:cursor-not-allowed disabled:opacity-50" disabled>
                        Clear filters
                    </button>
                    <button type="button" id="reportExportButton" class="inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300" disabled>
                        <i data-lucide="file-down" class="h-4 w-4" aria-hidden="true"></i>
                        Export System Report
                    </button>
                </div>
            </div>
        </div>

        <div class="flex items-start gap-2.5 border-b border-sky-100 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            <i data-lucide="info" class="mt-0.5 h-4 w-4 flex-shrink-0 text-sky-700" aria-hidden="true"></i>
            <p>Choose the target quarter, record whether it is complete, and set the latest update month. Changes are saved to the database.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[780px] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        <th scope="col" class="w-16 px-4 py-3 font-semibold">No.</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Program / Major</th>
                        <th scope="col" class="w-36 px-4 py-3 text-center font-semibold">Target Q</th>
                        <th scope="col" class="w-32 px-4 py-3 text-center font-semibold">Actual</th>
                        <th scope="col" class="w-48 px-4 py-3 font-semibold">Updated as of</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody" class="divide-y divide-slate-100">
                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">Loading report data…</td></tr>
                </tbody>
            </table>
        </div>
        <div id="reportNoMatches" class="hidden px-6 py-10 text-center text-sm text-slate-500" role="status">
            No academic records match these filters. Clear filters to see all active records.
        </div>
        <div id="reportEmptyState" class="hidden px-6 py-14 text-center">
            <i data-lucide="folder-search" class="mx-auto h-8 w-8 text-slate-400" aria-hidden="true"></i>
            <h3 class="mt-3 text-sm font-semibold text-slate-800">No active academic programs</h3>
            <p class="mt-1 text-sm text-slate-500">Active programs will appear here once they are added to the academic catalog.</p>
        </div>
    </section>
</div>
<div id="reportToastContainer" class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3" aria-live="polite"></div>

<script>
(() => {
    const yearFilter = document.getElementById('reportYearFilter');
    const collegeFilter = document.getElementById('reportCollegeFilter');
    const programFilter = document.getElementById('reportProgramFilter');
    const majorFilter = document.getElementById('reportMajorFilter');
    const clearButton = document.getElementById('reportClearFilters');
    const exportButton = document.getElementById('reportExportButton');
    const resultCount = document.getElementById('reportResultCount');
    const toastContainer = document.getElementById('reportToastContainer');
    const tableBody = document.getElementById('reportTableBody');
    const noMatches = document.getElementById('reportNoMatches');
    const emptyState = document.getElementById('reportEmptyState');
    let reportRows = [];
    let reportYear = Number(yearFilter.value);

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);

    function toast(message, isError = false) {
        const duration = 3000;
        const notification = document.createElement('div');
        notification.className = `pointer-events-auto relative flex items-start gap-3 overflow-hidden rounded-xl border px-4 py-3 pb-4 text-sm shadow-lg ${isError ? 'border-red-300 bg-red-50 text-red-800' : 'border-green-300 bg-green-50 text-green-800'}`;
        notification.setAttribute('role', isError ? 'alert' : 'status');

        const icon = document.createElement('i');
        icon.dataset.lucide = isError ? 'circle-alert' : 'circle-check';
        icon.className = 'mt-0.5 h-4 w-4 flex-shrink-0';
        icon.setAttribute('aria-hidden', 'true');

        const text = document.createElement('span');
        text.className = 'flex-1';
        text.textContent = message;

        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'text-current opacity-60 transition hover:opacity-100';
        dismiss.setAttribute('aria-label', 'Dismiss notification');
        dismiss.addEventListener('click', () => notification.remove());
        const dismissIcon = document.createElement('i');
        dismissIcon.dataset.lucide = 'x';
        dismissIcon.className = 'h-4 w-4';
        dismiss.append(dismissIcon);

        const progress = document.createElement('span');
        progress.className = `absolute bottom-0 left-0 h-1 w-full origin-left ${isError ? 'bg-red-500' : 'bg-green-500'}`;
        progress.dataset.toastProgress = '';

        notification.append(icon, text, dismiss, progress);
        toastContainer.appendChild(notification);
        window.lucide?.createIcons({ root: notification });
        requestAnimationFrame(() => {
            progress.style.transition = `width ${duration}ms linear`;
            progress.style.width = '0%';
        });
        setTimeout(() => notification.remove(), duration);
    }

    function refreshFilterOptions() {
        const selectedCollege = collegeFilter.value;
        const selectedProgram = programFilter.value;
        const selectedMajor = majorFilter.value;
        const colleges = new Map();
        const programs = new Map();
        const majors = new Map();

        reportRows.forEach(row => {
            colleges.set(String(row.college_id), row.college_name);
            programs.set(String(row.program_id), { name: row.program_name, collegeId: String(row.college_id) });
            if (row.major_id) {
                majors.set(String(row.major_id), { name: row.major_name, programId: String(row.program_id) });
            }
        });

        collegeFilter.innerHTML = '<option value="">All colleges</option>' +
            [...colleges].map(([id, name]) => `<option value="${escapeHtml(id)}">${escapeHtml(name)}</option>`).join('');
        collegeFilter.value = colleges.has(selectedCollege) ? selectedCollege : '';

        programFilter.innerHTML = '<option value="">All programs</option>' +
            [...programs].map(([id, item]) => `<option value="${escapeHtml(id)}" data-college="${escapeHtml(item.collegeId)}">${escapeHtml(item.name)}</option>`).join('');
        programFilter.value = programs.has(selectedProgram) ? selectedProgram : '';

        majorFilter.innerHTML = '<option value="">All majors</option>' +
            [...majors].map(([id, item]) => `<option value="${escapeHtml(id)}" data-program="${escapeHtml(item.programId)}">${escapeHtml(item.name)}</option>`).join('');
        majorFilter.value = majors.has(selectedMajor) ? selectedMajor : '';
        updateDependentOptions();
    }

    function updateDependentOptions() {
        const collegeId = collegeFilter.value;
        [...programFilter.options].forEach(option => {
            option.hidden = Boolean(option.value && collegeId && option.dataset.college !== collegeId);
        });
        if (programFilter.selectedOptions[0]?.hidden) programFilter.value = '';

        const programId = programFilter.value;
        [...majorFilter.options].forEach(option => {
            option.hidden = Boolean(option.value && programId && option.dataset.program !== programId);
        });
        if (majorFilter.selectedOptions[0]?.hidden) majorFilter.value = '';
    }

    function visibleRows() {
        return reportRows.filter(row =>
            (!collegeFilter.value || String(row.college_id) === collegeFilter.value) &&
            (!programFilter.value || String(row.program_id) === programFilter.value) &&
            (!majorFilter.value || String(row.major_id) === majorFilter.value)
        );
    }

    function renderRows() {
        const rows = visibleRows();
        resultCount.textContent = `${rows.length} ${rows.length === 1 ? 'record' : 'records'}`;
        clearButton.disabled = !collegeFilter.value && !programFilter.value && !majorFilter.value;
        exportButton.disabled = rows.length === 0;
        noMatches.classList.toggle('hidden', rows.length !== 0 || reportRows.length === 0);
        emptyState.classList.toggle('hidden', reportRows.length !== 0);
        tableBody.classList.toggle('hidden', reportRows.length === 0);

        tableBody.innerHTML = rows.map((row, index) => {
            const target = row.target_quarter || 'Q1';
            const isProgram = !row.major_id;
            const isFirstRowForProgram = index === 0 || rows[index - 1].program_id !== row.program_id;
            const majorNumber = rows
                .slice(0, index + 1)
                .filter(item => item.program_id === row.program_id && item.major_id)
                .length;
            const isCompleted = Boolean(row.actuals[target]);
            const updatedMonth = row.updated_months[target] || '';
            const scopeId = row.major_id || '';

            return `<tr class="transition-colors hover:bg-slate-50" data-report-row data-program="${row.program_id}" data-major="${scopeId}" data-college="${row.college_id}">
                <td class="px-4 py-3 text-sm text-slate-500">${isProgram ? index + 1 : ''}</td>
                <td class="px-4 py-3">
                    ${isProgram
                        ? `<p class="text-sm font-semibold text-slate-900">${escapeHtml(row.program_name)}</p><p class="text-[11px] text-slate-500">${escapeHtml(row.college_name)}</p>`
                        : `${isFirstRowForProgram ? `<p class="text-sm font-semibold text-slate-900">${escapeHtml(row.program_name)}</p>` : ''}
                           <p class="pl-6 text-sm text-slate-700">${majorNumber}. ${escapeHtml(row.major_name)}</p>
                           <p class="text-[11px] text-slate-500">${escapeHtml(row.college_name)}</p>`}
                </td>
                <td class="px-4 py-3 text-center">
                    <label class="sr-only" for="target-${row.program_id}-${scopeId}">Target quarter for ${escapeHtml(row.major_name || row.program_name)}</label>
                    <select id="target-${row.program_id}-${scopeId}" data-report-field="target" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100">
                        ${['Q1', 'Q2', 'Q3', 'Q4'].map(quarter => `<option value="${quarter}" ${target === quarter ? 'selected' : ''}>${quarter}</option>`).join('')}
                    </select>
                </td>
                <td class="px-4 py-3 text-center">
                    <input type="checkbox" data-report-field="actual" aria-label="${target} complete for ${escapeHtml(row.major_name || row.program_name)}" ${isCompleted ? 'checked' : ''} class="h-4 w-4 cursor-pointer accent-rose-600">
                </td>
                <td class="px-4 py-3">
                    <label class="sr-only" for="updated-${row.program_id}-${scopeId}">Update month for ${escapeHtml(row.major_name || row.program_name)}</label>
                    <input id="updated-${row.program_id}-${scopeId}" type="month" data-report-field="updated_as_of" value="${escapeHtml(updatedMonth)}" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-700 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100">
                </td>
            </tr>`;
        }).join('');
    }

    async function loadReport(year) {
        reportYear = Number(year);
        tableBody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">Loading report data…</td></tr>';
        try {
            const response = await fetch(`pages/report.php?action=data&year=${encodeURIComponent(reportYear)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Report data could not be loaded.');
            }
            reportRows = result.data.rows || [];
            refreshFilterOptions();
            renderRows();
            return true;
        } catch (error) {
            tableBody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-sm text-rose-700">Report data could not be loaded.</td></tr>';
            resultCount.textContent = 'Unavailable';
            exportButton.disabled = true;
            toast(error.message, true);
            return false;
        }
    }

    async function saveProgress(rowElement, field, value, reload = true) {
        const payload = {
            program_id: Number(rowElement.dataset.program),
            major_id: rowElement.dataset.major ? Number(rowElement.dataset.major) : null,
            year: reportYear,
            quarter: rowElement.querySelector('[data-report-field="target"]').value,
            field,
            value,
        };
        try {
            const response = await fetch('pages/report.php?action=save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Report progress could not be saved.');
            }
            if (!reload) return true;
            const loaded = await loadReport(reportYear);
            if (loaded) toast('Progress saved.');
            return loaded;
        } catch (error) {
            toast(error.message, true);
            return false;
        }
    }

    collegeFilter.addEventListener('change', () => {
        programFilter.value = '';
        majorFilter.value = '';
        updateDependentOptions();
        renderRows();
    });
    programFilter.addEventListener('change', () => {
        majorFilter.value = '';
        updateDependentOptions();
        renderRows();
    });
    majorFilter.addEventListener('change', renderRows);
    yearFilter.addEventListener('change', () => loadReport(yearFilter.value));
    clearButton.addEventListener('click', () => {
        collegeFilter.value = '';
        programFilter.value = '';
        majorFilter.value = '';
        updateDependentOptions();
        renderRows();
        collegeFilter.focus();
    });

    tableBody.addEventListener('change', event => {
        const control = event.target.closest('[data-report-field]');
        const row = control?.closest('[data-report-row]');
        if (!control || !row) return;
        const field = control.dataset.reportField;
        const value = field === 'actual' ? Number(control.checked) : control.value;
        if (field === 'actual' && control.checked) {
            const now = new Date();
            const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
            const monthInput = row.querySelector('[data-report-field="updated_as_of"]');
            monthInput.value = currentMonth;
            saveProgress(row, 'updated_as_of', currentMonth, false)
                .then(saved => saved && saveProgress(row, 'actual', 1, false))
                .then(saved => saved && loadReport(reportYear))
                .then(loaded => loaded && toast('Progress saved.'));
            return;
        }
        saveProgress(row, field, value);
    });

    exportButton.addEventListener('click', () => {
        const csvRows = [['College', 'Program', 'Major', 'Year', 'Target quarter', 'Actual', 'Updated as of']];
        visibleRows().forEach(row => {
            const target = row.target_quarter || 'Q1';
            csvRows.push([
                row.college_name,
                row.program_name,
                row.major_name,
                String(reportYear),
                target,
                row.actuals[target] ? 'Yes' : 'No',
                row.updated_months[target] || '',
            ]);
        });
        const escapeCsvCell = value => {
            const stringValue = String(value ?? '');
            const safeValue = /^[=+\-@\t\r]/.test(stringValue) ? `'${stringValue}` : stringValue;
            return `"${safeValue.replaceAll('"', '""')}"`;
        };
        const csv = '\uFEFF' + csvRows.map(row => row.map(escapeCsvCell).join(',')).join('\r\n');
        const file = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = `appsys-program-report-${reportYear}-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    loadReport(reportYear);
})();
</script>
