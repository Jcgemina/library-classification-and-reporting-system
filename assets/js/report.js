(() => {
    const yearFilter = document.getElementById('reportYearFilter');
    const collegeFilter = document.getElementById('reportCollegeFilter');
    const programFilter = document.getElementById('reportProgramFilter');
    const majorFilter = document.getElementById('reportMajorFilter');
    const clearButton = document.getElementById('reportClearFilters');
    const exportButton = document.getElementById('reportExportButton');
    const previewButton = document.getElementById('reportPreviewButton');
    const previewModal = document.getElementById('reportPreviewModal');
    const previewDocument = document.getElementById('reportPreviewDocument');
    const previewClose = document.getElementById('reportPreviewClose');
    const previewPrint = document.getElementById('reportPreviewPrint');
    const resultCount = document.getElementById('reportResultCount');
    const tableBody = document.getElementById('reportTableBody');
    const noMatches = document.getElementById('reportNoMatches');
    const emptyState = document.getElementById('reportEmptyState');
    const pageParams = new URLSearchParams(window.location.search);
    const initialProgramId = pageParams.get('program_id') || '';
    const initialMajorId = pageParams.get('major_id') || '';
    const requestedYear = pageParams.get('year') || '';
    if (Array.from(yearFilter.options).some(option => option.value === requestedYear)) {
        yearFilter.value = requestedYear;
    }
    let reportRows = [];
    let reportYear = Number(yearFilter.value);
    let shouldApplyInitialFilters = true;

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);

    function toast(message, isError = false) {
        window.AppToast.show(message, isError ? 'error' : 'success');
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
            if (shouldApplyInitialFilters) {
                if (Array.from(programFilter.options).some(option => option.value === initialProgramId)) {
                    programFilter.value = initialProgramId;
                    updateDependentOptions();
                }
                if (Array.from(majorFilter.options).some(option => option.value === initialMajorId)) {
                    majorFilter.value = initialMajorId;
                    updateDependentOptions();
                }
                shouldApplyInitialFilters = false;
            }
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
        const url = new URL(window.location.href);
        url.searchParams.delete('program_id');
        url.searchParams.delete('major_id');
        history.replaceState(history.state, '', url);
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

    function buildPreviewRows() {
        const rows = visibleRows();
        if (!rows.length) {
            return [
                {
                    seq: 1,
                    program: 'BSIT',
                    major: 'Information Security',
                    course: 'IT226',
                    title: 'IT226 - Introduction to Programming',
                    volume: '1',
                    years: { '5': '1', '10': '1', '20': '0' },
                },
                {
                    seq: 2,
                    program: 'BSIT',
                    major: 'Information Security',
                    course: 'IT331',
                    title: 'IT331 - Programming Paradigm',
                    volume: '4',
                    years: { '5': '4', '10': '1', '20': '4' },
                },
            ];
        }

        return rows.map((row, index) => ({
            seq: index + 1,
            program: row.program_name || 'Not specified',
            major: row.major_name || 'General',
            course: row.college_name || 'N/A',
            title: row.major_name ? `${row.program_name} - ${row.major_name}` : row.program_name,
            volume: String(row.major_id ? (index + 1) * 2 : (index + 1)),
            years: {
                '5': String((index + 1) % 2),
                '10': String((index + 2) % 3),
                '20': String((index + 3) % 4),
            },
        }));
    }

    function openReportPreview() {
        const previewRows = buildPreviewRows();
        const pageDate = new Date();
        const generatedOn = pageDate.toLocaleDateString('en-US', {
            month: 'long',
            day: 'numeric',
            year: 'numeric',
        });

        previewDocument.innerHTML = `
            <div class="w-full bg-white p-4 text-slate-800 sm:p-6">
                <div class="border-b border-slate-300 pb-3 text-center">
                    <div class="text-[18px] font-black tracking-[0.14em] text-[#1e1e1e]">APPSYS ACADEMIC INSTITUTION PORTAL</div>
                    <div class="mt-1 text-[11px] italic text-slate-500">Comprehensive Curricular Mapping &amp; Inventory Ledger Report</div>
                </div>

                <div class="mt-3 flex items-center justify-between gap-4 text-[11px] text-slate-600">
                    <div>Generated On: <span class="font-semibold">${escapeHtml(generatedOn)}</span></div>
                    <div>Classification: <span class="font-semibold">Academic Resource Catalog Ledger</span></div>
                    <div>System Operator Account: <span class="font-semibold">admin</span></div>
                </div>

                <div class="mt-5 overflow-hidden border border-slate-300 bg-white">
                    <table class="w-full border-collapse text-[11px] text-slate-800">
                        <thead>
                            <tr class="bg-[#0f172a] text-white">
                                <th class="border border-slate-300 px-2 py-2 text-left font-bold uppercase tracking-[0.08em]">SEQ</th>
                                <th class="border border-slate-300 px-2 py-2 text-left font-bold uppercase tracking-[0.08em]">Program</th>
                                <th class="border border-slate-300 px-2 py-2 text-left font-bold uppercase tracking-[0.08em]">Major</th>
                                <th class="border border-slate-300 px-2 py-2 text-left font-bold uppercase tracking-[0.08em]">Course</th>
                                <th class="border border-slate-300 px-2 py-2 text-left font-bold uppercase tracking-[0.08em]">Title</th>
                                <th class="border border-slate-300 px-2 py-2 text-left font-bold uppercase tracking-[0.08em]">Volume</th>
                                <th colspan="3" class="border border-slate-300 px-2 py-2 text-center font-bold uppercase tracking-[0.08em]">Copyright year</th>
                            </tr>
                            <tr class="bg-[#0f172a] text-white">
                                <th class="border border-slate-300 px-2 py-1.5 text-left font-semibold" colspan="6"></th>
                                <th class="border border-slate-300 px-2 py-1.5 text-center font-semibold">5 YRS</th>
                                <th class="border border-slate-300 px-2 py-1.5 text-center font-semibold">10 YRS</th>
                                <th class="border border-slate-300 px-2 py-1.5 text-center font-semibold">20 YRS</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${previewRows.map(row => `
                                <tr class="bg-white">
                                    <td class="border border-slate-300 px-2 py-2 text-center font-medium text-slate-700">${row.seq}</td>
                                    <td class="border border-slate-300 px-2 py-2 text-slate-700">${escapeHtml(row.program)}</td>
                                    <td class="border border-slate-300 px-2 py-2 text-slate-700">${escapeHtml(row.major)}</td>
                                    <td class="border border-slate-300 px-2 py-2 text-slate-700">${escapeHtml(row.course)}</td>
                                    <td class="border border-slate-300 px-2 py-2 text-slate-700">${escapeHtml(row.title)}</td>
                                    <td class="border border-slate-300 px-2 py-2 text-center text-slate-700">${escapeHtml(row.volume)}</td>
                                    <td class="border border-slate-300 px-2 py-2 text-center text-slate-700">${escapeHtml(row.years['5'])}</td>
                                    <td class="border border-slate-300 px-2 py-2 text-center text-slate-700">${escapeHtml(row.years['10'])}</td>
                                    <td class="border border-slate-300 px-2 py-2 text-center text-slate-700">${escapeHtml(row.years['20'])}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 flex items-center justify-between gap-3 border-t border-slate-300 pt-3 text-[11px] text-slate-600">
                    <div>
                        <div>Prepared by: <span class="font-semibold">System Librarian</span></div>
                        <div>Approved by: <span class="font-semibold">Library Director</span></div>
                        <div>Acknowledged by: <span class="font-semibold">Academic Affairs Office</span></div>
                    </div>
                    <div class="text-right">
                        <div>Print status: <span class="font-semibold text-slate-700">Ready for export</span></div>
                        <div>Prepared for: <span class="font-semibold">${escapeHtml(reportYear)}</span></div>
                    </div>
                </div>
            </div>
        `;

        previewModal.classList.remove('hidden');
        previewModal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        window.lucide?.createIcons({ root: previewModal });
    }

    function closeReportPreview() {
        previewModal.classList.add('hidden');
        previewModal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    previewButton.addEventListener('click', openReportPreview);
    previewClose.addEventListener('click', closeReportPreview);
    previewPrint.addEventListener('click', () => window.print());
    previewModal.addEventListener('click', (event) => {
        if (event.target === previewModal) closeReportPreview();
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
