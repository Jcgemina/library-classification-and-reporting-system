<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=report');
    exit;
}

requireLogin();

$reportRows = [];
$reportColleges = [];
$reportPrograms = [];
$reportLoadError = null;

if (!$pdo instanceof PDO) {
    $reportLoadError = 'The database connection is unavailable. Please try again later.';
} else {
    try {
        $programs = $pdo->query(
            'SELECT p.id, p.college_id, p.name, c.name AS college_name
            FROM programs p
            INNER JOIN colleges c ON c.id = p.college_id
            WHERE p.status = \'active\' AND c.status = \'active\'
            ORDER BY c.name, p.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $majorsByProgram = [];
        $majors = $pdo->query(
            'SELECT m.id, m.program_id, m.name
            FROM majors m
            INNER JOIN programs p ON p.id = m.program_id
            INNER JOIN colleges c ON c.id = p.college_id
            WHERE m.status = \'active\' AND p.status = \'active\' AND c.status = \'active\'
            ORDER BY m.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($majors as $major) {
            $majorsByProgram[(int) $major['program_id']][] = $major;
        }

        foreach ($programs as $program) {
            $programId = (int) $program['id'];
            $collegeId = (int) $program['college_id'];
            $reportColleges[$collegeId] = $program['college_name'];
            $reportPrograms[$programId] = [
                'college_id' => $collegeId,
                'college_name' => $program['college_name'],
                'name' => $program['name'],
            ];

            $reportRows[] = [
                'college_id' => $collegeId,
                'college_name' => $program['college_name'],
                'program_id' => $programId,
                'program_name' => $program['name'],
                'major_id' => 0,
                'major_name' => '',
                'is_program' => true,
            ];

            foreach ($majorsByProgram[$programId] ?? [] as $major) {
                $reportRows[] = [
                    'college_id' => $collegeId,
                    'college_name' => $program['college_name'],
                    'program_id' => $programId,
                    'program_name' => $program['name'],
                    'major_id' => (int) $major['id'],
                    'major_name' => $major['name'],
                    'is_program' => false,
                ];
            }
        }
    } catch (PDOException $exception) {
        error_log('Report academic records unavailable: ' . $exception->getMessage());
        $reportLoadError = 'We could not load academic records. Please try again later.';
        $reportRows = [];
        $reportColleges = [];
        $reportPrograms = [];
    }
}

$escapeReportValue = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
?>

<div class="space-y-5 p-1 text-slate-900 sm:p-2">
    <header>
        <h2 class="text-2xl font-bold text-slate-900">Detailed Reports</h2>
        <p class="mt-1 text-sm text-slate-500">Program verification progress by quarter and academic year.</p>
    </header>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-label="Program verification report">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <label class="sr-only" for="reportCollegeFilter">Filter by college</label>
                <select id="reportCollegeFilter" class="min-w-44 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                    <option value="">All colleges</option>
                    <?php foreach ($reportColleges as $collegeId => $collegeName): ?>
                        <option value="<?php echo (int) $collegeId; ?>"><?php echo $escapeReportValue($collegeName); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hidden text-slate-300 sm:inline" aria-hidden="true">›</span>

                <label class="sr-only" for="reportProgramFilter">Filter by program</label>
                <select id="reportProgramFilter" class="min-w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                    <option value="">All programs</option>
                    <?php foreach ($reportPrograms as $programId => $program): ?>
                        <option value="<?php echo (int) $programId; ?>" data-college="<?php echo (int) $program['college_id']; ?>"><?php echo $escapeReportValue($program['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hidden text-slate-300 sm:inline" aria-hidden="true">›</span>

                <label class="sr-only" for="reportMajorFilter">Filter by major</label>
                <select id="reportMajorFilter" class="min-w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100">
                    <option value="">All majors</option>
                    <?php foreach ($reportRows as $row): ?>
                        <?php if (!$row['is_program']): ?>
                            <option value="<?php echo (int) $row['major_id']; ?>" data-program="<?php echo (int) $row['program_id']; ?>"><?php echo $escapeReportValue($row['major_name']); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span id="reportResultCount" class="mr-1 text-xs text-slate-500" aria-live="polite"><?php echo count($reportRows); ?> records</span>
                <button type="button" id="reportClearFilters" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-200 disabled:cursor-not-allowed disabled:opacity-50" disabled>
                    Clear filters
                </button>
                <button type="button" id="reportExportButton" class="inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300" <?php echo $reportRows === [] ? 'disabled' : ''; ?>>
                    <i data-lucide="file-down" class="h-4 w-4" aria-hidden="true"></i>
                    Export System Report
                </button>
            </div>
        </div>

        <div class="flex items-start gap-2.5 border-b border-sky-100 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            <i data-lucide="info" class="mt-0.5 h-4 w-4 flex-shrink-0 text-sky-700" aria-hidden="true"></i>
            <p>Showing active academic programs and majors. Quarterly verification records are not tracked in the system yet.</p>
        </div>

        <?php if ($reportLoadError !== null): ?>
            <div class="m-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
                <?php echo $escapeReportValue($reportLoadError); ?>
            </div>
        <?php elseif ($reportRows === []): ?>
            <div class="px-6 py-14 text-center">
                <i data-lucide="folder-search" class="mx-auto h-8 w-8 text-slate-400" aria-hidden="true"></i>
                <h3 class="mt-3 text-sm font-semibold text-slate-800">No active academic programs</h3>
                <p class="mt-1 text-sm text-slate-500">Active programs will appear here once they are added to the academic catalog.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[780px] text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                        <tr>
                            <th scope="col" class="w-16 px-4 py-3 font-semibold">No.</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Program / Major</th>
                            <th scope="col" class="w-36 px-4 py-3 font-semibold">Target Q</th>
                            <th scope="col" class="w-36 px-4 py-3 font-semibold">Actual</th>
                            <th scope="col" class="w-44 px-4 py-3 font-semibold">Updated as of</th>
                        </tr>
                    </thead>
                    <tbody id="reportTableBody" class="divide-y divide-slate-100">
                        <?php foreach ($reportRows as $index => $row): ?>
                            <tr
                                data-report-row
                                data-college="<?php echo (int) $row['college_id']; ?>"
                                data-program="<?php echo (int) $row['program_id']; ?>"
                                data-major="<?php echo (int) $row['major_id']; ?>"
                                data-college-name="<?php echo $escapeReportValue($row['college_name']); ?>"
                                data-program-name="<?php echo $escapeReportValue($row['program_name']); ?>"
                                data-major-name="<?php echo $escapeReportValue($row['major_name']); ?>"
                            >
                                <td class="px-4 py-3 align-top tabular-nums text-slate-500"><?php echo $index + 1; ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($row['is_program']): ?>
                                        <p class="font-semibold text-slate-900"><?php echo $escapeReportValue($row['program_name']); ?></p>
                                        <p class="mt-0.5 text-xs text-slate-500"><?php echo $escapeReportValue($row['college_name']); ?> <span aria-hidden="true">›</span> Program</p>
                                    <?php else: ?>
                                        <p class="pl-5 text-slate-700"><?php echo $escapeReportValue($row['major_name']); ?></p>
                                        <p class="mt-0.5 pl-5 text-xs text-slate-500"><?php echo $escapeReportValue($row['college_name']); ?> <span aria-hidden="true">›</span> <?php echo $escapeReportValue($row['program_name']); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-slate-400">&mdash;</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600">Not tracked</span>
                                </td>
                                <td class="px-4 py-3 text-slate-400">&mdash;</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="reportNoMatches" class="hidden px-6 py-10 text-center text-sm text-slate-500" role="status">
                No academic records match these filters. Clear filters to see all active records.
            </div>
        <?php endif; ?>
    </section>
</div>

<?php if ($reportLoadError === null && $reportRows !== []): ?>
<script>
(() => {
    const collegeFilter = document.getElementById('reportCollegeFilter');
    const programFilter = document.getElementById('reportProgramFilter');
    const majorFilter = document.getElementById('reportMajorFilter');
    const clearButton = document.getElementById('reportClearFilters');
    const exportButton = document.getElementById('reportExportButton');
    const resultCount = document.getElementById('reportResultCount');
    const tableBody = document.getElementById('reportTableBody');
    const noMatches = document.getElementById('reportNoMatches');
    const rows = [...tableBody.querySelectorAll('[data-report-row]')];

    function updateDependentOptions() {
        const collegeId = collegeFilter.value;

        [...programFilter.options].forEach(option => {
            option.hidden = Boolean(option.value && collegeId && option.dataset.college !== collegeId);
        });
        if (programFilter.selectedOptions[0]?.hidden) {
            programFilter.value = '';
        }

        const selectedProgramId = programFilter.value;
        [...majorFilter.options].forEach(option => {
            option.hidden = Boolean(option.value && selectedProgramId && option.dataset.program !== selectedProgramId);
        });
        if (majorFilter.selectedOptions[0]?.hidden) {
            majorFilter.value = '';
        }
    }

    function applyFilters() {
        const collegeId = collegeFilter.value;
        const programId = programFilter.value;
        const majorId = majorFilter.value;
        let visibleCount = 0;

        rows.forEach(row => {
            const isVisible =
                (!collegeId || row.dataset.college === collegeId) &&
                (!programId || row.dataset.program === programId) &&
                (!majorId || row.dataset.major === majorId);
            row.hidden = !isVisible;
            if (isVisible) {
                visibleCount += 1;
            }
        });

        resultCount.textContent = `${visibleCount} ${visibleCount === 1 ? 'record' : 'records'}`;
        noMatches.classList.toggle('hidden', visibleCount !== 0);
        clearButton.disabled = !collegeId && !programId && !majorId;
        exportButton.disabled = visibleCount === 0;
    }

    collegeFilter.addEventListener('change', () => {
        programFilter.value = '';
        majorFilter.value = '';
        updateDependentOptions();
        applyFilters();
    });
    programFilter.addEventListener('change', () => {
        majorFilter.value = '';
        updateDependentOptions();
        applyFilters();
    });
    majorFilter.addEventListener('change', applyFilters);
    clearButton.addEventListener('click', () => {
        collegeFilter.value = '';
        programFilter.value = '';
        majorFilter.value = '';
        updateDependentOptions();
        applyFilters();
        collegeFilter.focus();
    });

    exportButton.addEventListener('click', () => {
        const csvRows = [['College', 'Program', 'Major', 'Quarterly verification status']];
        rows.filter(row => !row.hidden).forEach(row => {
            csvRows.push([
                row.dataset.collegeName,
                row.dataset.programName,
                row.dataset.majorName,
                'Not tracked',
            ]);
        });

        const escapeCsvCell = value => {
            const safeValue = /^[=+\-@\t\r]/.test(value) ? `'${value}` : value;
            return `"${safeValue.replaceAll('"', '""')}"`;
        };
        const csv = '\uFEFF' + csvRows.map(row => row.map(escapeCsvCell).join(',')).join('\r\n');
        const file = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = `appsys-program-report-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    updateDependentOptions();
})();
</script>
<?php endif; ?>
