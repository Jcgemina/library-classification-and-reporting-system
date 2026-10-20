(() => {
    const page = document.querySelector('[data-reference-analyzer]');
    if (!page) {
        return;
    }

    const filters = page.querySelector('[data-reference-filters]');
    const booksPanel = page.querySelector('[data-reference-books-panel]');
    const overview = page.querySelector('[data-reference-overview]');
    const detailView = page.querySelector('[data-book-detail-view]');
    const booksBody = page.querySelector('[data-reference-books]');
    const errorNode = page.querySelector('[data-reference-error]');
    const resultCount = page.querySelector('[data-reference-result-count]');
    const policyNote = page.querySelector('[data-reference-policy-note]');
    const collegeFilter = page.querySelector('[data-filter-college]');
    const programFilter = page.querySelector('[data-filter-program]');
    const courseFilter = page.querySelector('[data-filter-course]');
    const state = { colleges: [], programs: [], courses: [], activeBookId: null, requestNumber: 0 };
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[character]));
    const $ = (selector, root = document) => root.querySelector(selector);

    async function request(action, params = {}) {
        const query = new URLSearchParams({ action, ...params });
        const response = await fetch(`pages/reference_analyzer.php?${query}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        let result;
        try {
            result = await response.json();
        } catch {
            throw new Error('The server returned an unexpected response. Reload Reference Analyzer and try again.');
        }
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Reference Analyzer request failed.');
        }
        return result;
    }

    function fillSelect(select, options, placeholder, parentId = '') {
        const previous = select.value;
        const filtered = options.filter((option) => !parentId || String(option.parentId) === String(parentId));
        select.innerHTML = `<option value="">${placeholder}</option>${filtered.map((option) =>
            `<option value="${escapeHtml(option.id)}">${escapeHtml(option.label)}</option>`
        ).join('')}`;
        if (filtered.some((option) => String(option.id) === previous)) {
            select.value = previous;
        }
    }

    function syncAcademicFilters() {
        const collegeId = collegeFilter.value;
        fillSelect(programFilter, state.programs.map((program) => ({
            id: program.id,
            parentId: program.college_id,
            label: program.name
        })), 'All programs', collegeId);

        const programId = programFilter.value;
        fillSelect(courseFilter, state.courses.map((course) => ({
            id: course.id,
            parentId: course.program_id,
            label: `${course.code} — ${course.name}`
        })), 'All courses', programId);
    }

    function statusBadge(assessment) {
        const classes = {
            meets_policy: 'bg-emerald-100 text-emerald-900',
            outside_policy: 'bg-rose-100 text-rose-900',
            needs_verification: 'bg-amber-100 text-amber-950',
            missing_publication_data: 'bg-slate-100 text-slate-800'
        };
        return `<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${classes[assessment.status] || classes.needs_verification}">${escapeHtml(assessment.label)}</span>`;
    }

    function renderBooks(books) {
        if (!books.length) {
            booksBody.innerHTML = '<tr><td colspan="7" class="px-4 py-12 text-center"><p class="font-semibold text-slate-800">No matching books</p><p class="mt-1 text-sm text-slate-600">Adjust or clear your filters to see inventory titles.</p></td></tr>';
            return;
        }
        booksBody.innerHTML = books.map((book) => `
            <tr class="align-top hover:bg-slate-50">
                <th scope="row" class="max-w-[22rem] px-4 py-4 font-semibold text-slate-900">${escapeHtml(book.title)}</th>
                <td class="max-w-[15rem] px-4 py-4 text-slate-700">${escapeHtml(book.author || 'Not recorded')}</td>
                <td class="whitespace-nowrap px-4 py-4 tabular-nums text-slate-800">${book.publication_year ?? 'Not recorded'}</td>
                <td class="px-4 py-4 text-slate-600">Not recorded</td>
                <td class="px-4 py-4 text-center tabular-nums text-slate-800">${book.linked_course_count}</td>
                <td class="px-4 py-4">${statusBadge(book.assessment)}</td>
                <td class="px-4 py-4 text-right"><button type="button" data-book-detail="${book.book_id}" class="inline-flex min-h-9 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2"><i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>Details</button></td>
            </tr>
        `).join('');
        lucide.createIcons({ nodes: [booksBody] });
    }

    function showError(message) {
        errorNode.textContent = message;
        errorNode.classList.remove('hidden');
        booksBody.innerHTML = '<tr><td colspan="7" class="px-4 py-12 text-center text-sm text-slate-700">Books could not be loaded. Check the message above and retry.</td></tr>';
        resultCount.textContent = 'Unable to load books.';
    }

    async function loadBooks() {
        const requestNumber = ++state.requestNumber;
        errorNode.classList.add('hidden');
        resultCount.textContent = 'Loading books…';
        const params = Object.fromEntries(new FormData(filters).entries());
        try {
            const result = await request('list', params);
            if (requestNumber !== state.requestNumber) {
                return;
            }
            renderBooks(result.books);
            Object.entries(result.summary).forEach(([key, value]) => {
                const target = page.querySelector(`[data-summary="${key}"]`);
                if (target) {
                    target.textContent = String(value);
                }
            });
            resultCount.textContent = `${result.summary.total} book${result.summary.total === 1 ? '' : 's'} analyzed with current filters`;
            const policy = result.policy;
            policyNote.textContent = policy.direction
                ? `Configured reference-age policy: ${policy.direction === 'within' ? 'published within' : 'at least'} ${policy.years} years. Assessments use publication year and do not indicate copyright expiration.`
                : 'Reference-age policy is not configured. Books with a recorded publication year are marked Needs Verification until an administrator confirms the institutional rule in Library Settings.';
        } catch (error) {
            if (requestNumber === state.requestNumber) {
                showError(error.message);
            }
        }
    }

    function returnToBookList() {
        booksPanel.classList.remove('hidden');
        overview.classList.remove('hidden');
        detailView.classList.add('hidden');
        detailView.innerHTML = '';
        const previousBookId = state.activeBookId;
        state.activeBookId = null;
        if (previousBookId !== null) {
            page.querySelector(`[data-book-detail="${previousBookId}"]`)?.focus();
        }
    }

    function bookDetailContent(book) {
        const assessment = book.assessment;
        const ageText = assessment.age === null ? 'Not available' : `${assessment.age} year${assessment.age === 1 ? '' : 's'}`;
        const thresholdText = assessment.threshold === null
            ? 'Not configured'
            : `${assessment.threshold} years (${assessment.policy_direction === 'within' ? 'maximum age' : 'minimum age'})`;
        const courses = book.courses.length
            ? `<ul class="mt-3 divide-y divide-slate-200 rounded-lg border border-slate-200">${book.courses.map((course) => `
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-900">${escapeHtml(course.code)} — ${escapeHtml(course.name)}</p>
                        <p class="mt-1 text-xs leading-5 text-slate-600">${escapeHtml(course.college_name || 'College not assigned')} · ${escapeHtml(course.program_name || 'Program not assigned')} · ${escapeHtml(course.status)}</p>
                    </div>
                    <a href="app.php?page=course&course_id=${encodeURIComponent(course.id)}" class="inline-flex w-fit shrink-0 items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">Open course<i data-lucide="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i></a>
                </li>
            `).join('')}</ul>`
            : '<p class="mt-3 rounded-lg bg-slate-50 px-4 py-4 text-sm text-slate-600">No courses currently reference this book.</p>';
        return `
            <div class="space-y-6">
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <button type="button" data-book-back class="mb-5 inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2"><i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>Back to books</button>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0"><h3 id="referenceBookDetailTitle" class="text-2xl font-bold text-slate-900">${escapeHtml(book.title)}</h3><p class="mt-1 text-sm text-slate-600">by ${escapeHtml(book.author || 'Author not recorded')}</p></div>
                        ${statusBadge(assessment)}
                    </div>
                </section>
                <div class="grid gap-6 xl:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="referenceBookInformationHeading">
                    <h4 id="referenceBookInformationHeading" class="text-sm font-bold text-slate-900">Book information</h4>
                    <dl class="mt-3 divide-y divide-slate-200 rounded-lg border border-slate-200">
                        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Title</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeHtml(book.title)}</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Author(s)</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeHtml(book.author || 'Not recorded')}</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">ISBN</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeHtml(book.isbn || 'Not recorded')}</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Publisher</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeHtml(book.publisher || 'Not recorded')}</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Publication year</dt><dd class="text-right text-sm font-medium text-slate-900">${book.publication_year ?? 'Not recorded'}</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Edition</dt><dd class="text-right text-sm font-medium text-slate-900">Not recorded in catalog</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Copyright year</dt><dd class="text-right text-sm font-medium text-slate-900">${book.copyright_year ?? 'Not recorded'}</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Copies</dt><dd class="text-right text-sm font-medium text-slate-900">${book.copy_count}</dd></div>
                        <div class="flex flex-col gap-1 px-4 py-3"><dt class="text-sm text-slate-600">Description</dt><dd class="text-sm leading-5 text-slate-900">${escapeHtml(book.description || 'Not recorded')}</dd></div>
                    </dl>
                </section>
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="referencePolicyAssessmentHeading">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h4 id="referencePolicyAssessmentHeading" class="text-sm font-bold text-slate-900">Reference policy assessment</h4>${statusBadge(assessment)}</div>
                    <dl class="mt-3 grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-2">
                        <div><dt class="text-xs font-semibold uppercase text-slate-600">Publication year</dt><dd class="mt-1 text-sm font-medium text-slate-900">${book.publication_year ?? 'Not recorded'}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-600">Calculated age</dt><dd class="mt-1 text-sm font-medium text-slate-900">${ageText}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase text-slate-600">Applicable threshold</dt><dd class="mt-1 text-sm font-medium text-slate-900">${thresholdText}</dd></div>
                    </dl>
                    <p class="mt-3 text-sm leading-5 text-slate-700">${escapeHtml(assessment.explanation)}</p>
                </section>
                </div>
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="linkedCoursesHeading"><div class="flex items-center justify-between gap-3"><h4 id="linkedCoursesHeading" class="text-sm font-bold text-slate-900">Linked courses</h4><span class="text-xs text-slate-600">${book.courses.length} course${book.courses.length === 1 ? '' : 's'}</span></div>${courses}</section>
                <section class="flex flex-wrap justify-end gap-2 rounded-xl border border-slate-200 bg-white p-4 shadow-sm" aria-label="Book actions">
                <button type="button" data-refresh-book="${book.book_id}" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-500"><i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>Recalculate</button>
                <a href="app.php?page=inventory&book_id=${encodeURIComponent(book.book_id)}&inventory_action=view" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-500"><i data-lucide="external-link" class="h-4 w-4" aria-hidden="true"></i>Open inventory</a>
                ${page.dataset.canManage === 'true' ? `
                    <a href="app.php?page=inventory&book_id=${encodeURIComponent(book.book_id)}&inventory_action=edit" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2"><i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i>Edit book</a>
                    <a href="app.php?page=inventory&book_id=${encodeURIComponent(book.book_id)}&inventory_action=manage-references" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500">Manage links</a>
                ` : ''}
                </section>
            </div>
        `;
    }

    async function openBookDetail(bookId) {
        state.activeBookId = Number(bookId);
        booksPanel.classList.add('hidden');
        overview.classList.add('hidden');
        detailView.classList.remove('hidden');
        detailView.innerHTML = '<div class="rounded-xl border border-slate-200 bg-white px-5 py-12 text-center text-sm text-slate-600" role="status">Loading book details…</div>';
        try {
            const result = await request('book_detail', { book_id: String(bookId) });
            if (state.activeBookId !== Number(bookId)) {
                return;
            }
            detailView.innerHTML = bookDetailContent(result.book);
            lucide.createIcons({ nodes: [detailView] });
        } catch (error) {
            if (state.activeBookId !== Number(bookId)) {
                return;
            }
            detailView.innerHTML = `<section class="rounded-xl border border-rose-200 bg-white p-5" aria-labelledby="bookDetailErrorTitle"><button type="button" data-book-back class="mb-5 inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-500"><i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>Back to books</button><h3 id="bookDetailErrorTitle" class="font-bold text-slate-900">Book details unavailable</h3><p class="mt-2 text-sm text-rose-800" role="alert">${escapeHtml(error.message)}</p><button type="button" data-refresh-book="${bookId}" class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">Retry</button></section>`;
            lucide.createIcons({ nodes: [detailView] });
        }
    }

    page.addEventListener('click', (event) => {
        const backButton = event.target.closest('[data-book-back]');
        if (backButton) {
            returnToBookList();
            return;
        }
        const refreshBookButton = event.target.closest('[data-refresh-book]');
        if (refreshBookButton) {
            openBookDetail(refreshBookButton.dataset.refreshBook);
            return;
        }
        const button = event.target.closest('[data-book-detail]');
        if (button) {
            openBookDetail(button.dataset.bookDetail);
            return;
        }
        const refreshButton = event.target.closest('[data-reference-refresh]');
        if (refreshButton) {
            loadBooks();
            if (state.activeBookId !== null) {
                openBookDetail(state.activeBookId);
            }
        }
    });

    let searchTimer;
    filters.addEventListener('input', (event) => {
        if (event.target.name === 'search') {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(loadBooks, 250);
        } else if (event.target.name === 'publication_year') {
            loadBooks();
        }
    });
    filters.addEventListener('change', (event) => {
        if (event.target === collegeFilter) {
            programFilter.value = '';
            syncAcademicFilters();
        } else if (event.target === programFilter) {
            courseFilter.value = '';
            syncAcademicFilters();
        }
        loadBooks();
    });

    async function initialize() {
        try {
            const result = await request('options');
            state.colleges = result.colleges;
            state.programs = result.programs;
            state.courses = result.courses;
            fillSelect(collegeFilter, state.colleges.map((item) => ({ id: item.id, label: item.name })), 'All colleges');
            syncAcademicFilters();
            await loadBooks();
        } catch (error) {
            showError(error.message);
        }
    }

    initialize();
})();
