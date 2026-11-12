(() => {
    const yearFilter = document.getElementById('reportYearFilter');
    const collegeFilter = document.getElementById('reportCollegeFilter');
    const programFilter = document.getElementById('reportProgramFilter');
    const majorFilter = document.getElementById('reportMajorFilter');
    const clearButton = document.getElementById('reportClearFilters');
    const exportButton = document.getElementById('reportExportButton');
    const exportMenu = document.getElementById('reportExportMenu');
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
    let institutionProfile = {};
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

            return `<tr data-report-row data-program="${row.program_id}" data-major="${scopeId}" data-college="${row.college_id}">
                <td class="report-row-number">${isProgram ? index + 1 : ''}</td>
                <td>
                    ${isProgram
                        ? `<p class="report-scope-name">${escapeHtml(row.program_name)}</p><p class="report-college-name">${escapeHtml(row.college_name)}</p>`
                        : `${isFirstRowForProgram ? `<p class="report-scope-name">${escapeHtml(row.program_name)}</p>` : ''}
                           <p class="report-major-name">${majorNumber}. ${escapeHtml(row.major_name)}</p>
                           <p class="report-college-name">${escapeHtml(row.college_name)}</p>`}
                </td>
                <td class="text-center">
                    <label class="sr-only" for="target-${row.program_id}-${scopeId}">Target quarter for ${escapeHtml(row.major_name || row.program_name)}</label>
                    <select id="target-${row.program_id}-${scopeId}" data-report-field="target">
                        ${['Q1', 'Q2', 'Q3', 'Q4'].map(quarter => `<option value="${quarter}" ${target === quarter ? 'selected' : ''}>${quarter}</option>`).join('')}
                    </select>
                </td>
                <td class="text-center">
                    <input type="checkbox" data-report-field="actual" aria-label="${target} complete for ${escapeHtml(row.major_name || row.program_name)}" ${isCompleted ? 'checked' : ''}>
                </td>
                <td>
                    <label class="sr-only" for="updated-${row.program_id}-${scopeId}">Update month for ${escapeHtml(row.major_name || row.program_name)}</label>
                    <input id="updated-${row.program_id}-${scopeId}" type="month" data-report-field="updated_as_of" value="${escapeHtml(updatedMonth)}">
                </td>
            </tr>`;
        }).join('');
    }

    async function loadReport(year) {
        reportYear = Number(year);
        tableBody.innerHTML = '<tr><td colspan="5" class="report-empty">Loading report data…</td></tr>';
        try {
            const response = await fetch(`pages/report.php?action=data&year=${encodeURIComponent(reportYear)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Report data could not be loaded.');
            }
            reportRows = result.data.rows || [];
            institutionProfile = result.data.institution_profile || {};
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
            tableBody.innerHTML = '<tr><td colspan="5" class="report-empty">Report data could not be loaded.</td></tr>';
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

    function openReportPreview() {
        const rows = visibleRows();
        if (!rows.length) {
            return;
        }
        const pageDate = new Date();
        const generatedOn = pageDate.toLocaleDateString('en-US', {
            month: 'long',
            day: 'numeric',
            year: 'numeric',
        });
        const institutionName = institutionProfile.institution_name || 'AppSys Library';
        const libraryName = institutionProfile.library_name || 'Program and major verification progress';
        const profileDetails = [
            institutionProfile.institution_address,
            institutionProfile.contact_email,
            institutionProfile.contact_phone,
        ].filter(Boolean).join(' | ');

        previewDocument.innerHTML = `
            <article class="report-print-document">
                <header class="report-print-header">
                    <h2>${escapeHtml(institutionName)}</h2>
                    <p>${escapeHtml(libraryName)}</p>
                    ${profileDetails ? `<p>${escapeHtml(profileDetails)}</p>` : ''}
                    <h3>Program and Major Verification Progress</h3>
                    <p>Academic year ${escapeHtml(reportYear)}</p>
                </header>
                <div class="report-print-meta">
                    <span><strong>Generated On:</strong> ${escapeHtml(generatedOn)}</span>
                    <span><strong>Academic Year:</strong> ${escapeHtml(reportYear)}</span>
                    <span><strong>Records:</strong> ${rows.length}</span>
                </div>
                <table class="report-print-table">
                    <thead><tr><th>No.</th><th>College</th><th>Program</th><th>Major</th><th>Target Q</th><th>Actual</th><th>Updated as of</th></tr></thead>
                    <tbody>${rows.map((row, index) => {
                        const target = row.target_quarter || 'Q1';
                        return `<tr>
                            <td>${index + 1}</td>
                            <td>${escapeHtml(row.college_name || 'Not specified')}</td>
                            <td>${escapeHtml(row.program_name || 'Not specified')}</td>
                            <td>${escapeHtml(row.major_name || '—')}</td>
                            <td>${escapeHtml(target)}</td>
                            <td>${row.actuals[target] ? 'Complete' : 'Pending'}</td>
                            <td>${escapeHtml(row.updated_months[target] || '—')}</td>
                        </tr>`;
                    }).join('')}</tbody>
                </table>
            </article>
        `;

        previewModal.classList.remove('hidden');
        previewModal.classList.add('is-open');
        document.body.classList.add('overflow-hidden');
        window.lucide?.createIcons({ root: previewModal });
        previewPrint.focus();
    }

    function closeReportPreview() {
        previewModal.classList.add('hidden');
        previewModal.classList.remove('is-open');
        document.body.classList.remove('overflow-hidden');
        exportButton.focus();
    }

    previewClose.addEventListener('click', closeReportPreview);
    previewPrint.addEventListener('click', () => {
        const clearPrintState = () => document.body.classList.remove('report-printing');
        window.addEventListener('afterprint', clearPrintState, { once: true });
        document.body.classList.add('report-printing');
        window.print();
    });
    previewModal.addEventListener('click', (event) => {
        if (event.target === previewModal) closeReportPreview();
    });
    previewModal.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeReportPreview();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [...previewModal.querySelectorAll('button:not(:disabled)')];
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    function currentExportRows() {
        return [
            ['College', 'Program', 'Major', 'Year', 'Target quarter', 'Actual', 'Updated as of'],
            ...visibleRows().map(row => {
                const target = row.target_quarter || 'Q1';
                return [
                    row.college_name || '',
                    row.program_name || '',
                    row.major_name || '',
                    String(reportYear),
                    target,
                    row.actuals[target] ? 'Yes' : 'No',
                    row.updated_months[target] || '',
                ];
            }),
        ];
    }

    function downloadFile(content, mimeType, filename) {
        const file = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    function exportCsv() {
        const csvRows = currentExportRows();
        const escapeCsvCell = value => {
            const stringValue = String(value ?? '');
            const safeValue = /^[=+\-@\t\r]/.test(stringValue) ? `'${stringValue}` : stringValue;
            return `"${safeValue.replaceAll('"', '""')}"`;
        };
        const csv = '\uFEFF' + csvRows.map(row => row.map(escapeCsvCell).join(',')).join('\r\n');
        downloadFile(csv, 'text/csv;charset=utf-8', `appsys-program-report-${reportYear}-${new Date().toISOString().slice(0, 10)}.csv`);
    }

    function exportExcel() {
        const xmlEscape = value => String(value ?? '')
            .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
            .replace(/[&<>"']/g, character => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&apos;',
            })[character]);
        const worksheetRows = currentExportRows().map(row => `<Row>${row.map(value =>
            `<Cell><Data ss:Type="String">${xmlEscape(value)}</Data></Cell>`
        ).join('')}</Row>`).join('');
        const workbook = `<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Worksheet ss:Name="Program Report"><Table>${worksheetRows}</Table></Worksheet>
</Workbook>`;
        downloadFile(workbook, 'application/vnd.ms-excel;charset=utf-8', `appsys-program-report-${reportYear}-${new Date().toISOString().slice(0, 10)}.xls`);
    }

    function positionExportMenu() {
        if (!exportMenu.matches(':popover-open')) {
            return;
        }

        const triggerRect = exportButton.getBoundingClientRect();
        const menuRect = exportMenu.getBoundingClientRect();
        const left = Math.max(8, Math.min(triggerRect.right - menuRect.width, window.innerWidth - menuRect.width - 8));
        const below = triggerRect.bottom + 5;
        const top = below + menuRect.height <= window.innerHeight - 8
            ? below
            : Math.max(8, triggerRect.top - menuRect.height - 5);
        exportMenu.style.left = `${left}px`;
        exportMenu.style.top = `${top}px`;
    }

    exportButton.addEventListener('click', () => {
        if (exportMenu.matches(':popover-open')) {
            exportMenu.hidePopover();
        } else {
            exportMenu.showPopover();
        }
    });
    exportMenu.addEventListener('toggle', () => {
        const isOpen = exportMenu.matches(':popover-open');
        exportButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (isOpen) {
            positionExportMenu();
            window.addEventListener('resize', positionExportMenu);
            window.addEventListener('scroll', positionExportMenu, true);
        } else {
            window.removeEventListener('resize', positionExportMenu);
            window.removeEventListener('scroll', positionExportMenu, true);
        }
    });
    exportMenu.addEventListener('click', event => {
        const option = event.target.closest('[data-report-export]');
        if (!option) return;
        exportMenu.hidePopover();
        switch (option.dataset.reportExport) {
            case 'print':
                openReportPreview();
                break;
            case 'xls':
                exportExcel();
                break;
            case 'csv':
                exportCsv();
                break;
        }
    });

    loadReport(reportYear);
})();
