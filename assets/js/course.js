(() => {
    const coursePage = document.querySelector('[data-course-page]');
    if (!coursePage) {
        return;
    }

    const admin = coursePage.dataset.courseAdmin === 'true';
    const state = { courses: [], colleges: [], programs: [], majors: [] };

    const $ = (id) => document.getElementById(id);
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));

    const toast = (message, error = false) => {
        const container = $('courseToastContainer');
        const node = document.createElement('div');
        const duration = 3000;

        node.className = `pointer-events-auto relative flex items-start gap-3 overflow-hidden rounded-xl border px-4 py-3 pb-4 text-sm shadow-lg ${error ? 'border-red-300 bg-red-50 text-red-800' : 'border-green-300 bg-green-50 text-green-800'}`;
        node.innerHTML = `
            <i data-lucide="${error ? 'circle-alert' : 'circle-check'}" class="mt-0.5 h-4 w-4 flex-shrink-0"></i>
            <span class="flex-1">${esc(message)}</span>
            <button type="button" class="text-current opacity-60 transition hover:opacity-100" aria-label="Dismiss notification">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
            <span class="absolute bottom-0 left-0 h-1 w-full origin-left ${error ? 'bg-red-500' : 'bg-green-500'}" data-toast-progress></span>
        `;

        container.appendChild(node);
        lucide.createIcons();

        const progress = node.querySelector('[data-toast-progress]');
        requestAnimationFrame(() => {
            progress.style.transition = `width ${duration}ms linear`;
            progress.style.width = '0%';
        });

        const dismiss = () => node.remove();
        node.querySelector('button').addEventListener('click', dismiss);
        setTimeout(dismiss, duration);
    };

    const request = async (url, options = {}) => {
        const rawOptions = {
            ...options,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' }),
                ...(options.headers ?? {})
            }
        };

        const response = await fetch(`pages/course.php${url}`, rawOptions);
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Request failed.');
        }

        return data;
    };

    function fillSelect(select, items, emptyLabel, selected = '') {
        if (!select) {
            return;
        }

        select.innerHTML = `<option value="">${emptyLabel}</option>` + items
            .map((item) => `<option value="${item.id}" ${String(item.id) === String(selected) ? 'selected' : ''}>${esc(item.name)}</option>`)
            .join('');
    }

    function syncCourseProgramOptions() {
        const collegeId = $('courseCollege')?.value ?? '';
        const selectedProgram = $('courseProgram')?.value ?? '';
        const programs = state.programs.filter((program) => !collegeId || String(program.college_id) === String(collegeId));
        const validProgram = programs.some((program) => String(program.id) === String(selectedProgram)) ? selectedProgram : '';

        fillSelect($('courseProgram'), programs, 'All programs', validProgram);
    }

    function fillFormOptions(course = {}) {
        fillSelect(
            $('formCollege'),
            state.colleges.filter((item) => item.status === 'active'),
            'No college link',
            course.collegeId || ''
        );

        fillSelect(
            $('formProgram'),
            state.programs.filter(
                (item) => item.status === 'active' && (!course.collegeId || String(item.college_id) === String(course.collegeId))
            ),
            'No program link',
            course.programId || ''
        );

        fillSelect(
            $('formMajor'),
            state.majors.filter(
                (item) => item.status === 'active' && (!course.programId || String(item.program_id) === String(course.programId))
            ),
            'No major link',
            course.majorId || ''
        );
    }

    function openModal(course = null) {
        $('courseModalTitle').textContent = course ? 'Edit Course' : 'Add Course';
        $('courseId').value = course?.id || '';
        $('courseCode').value = course?.code || '';
        $('courseName').value = course?.name || '';
        $('courseType').value = course?.type || 'Major';
        $('courseYear').value = course?.yearLevel ?? '';
        $('courseDescription').value = course?.description || '';
        $('formStatus').value = course?.status || 'active';

        fillFormOptions(course || {});
        $('formCollege').value = course?.collegeId || '';

        $('courseModal').classList.remove('hidden');
        $('courseModal').classList.add('flex');
    }

    function closeModal() {
        $('courseModal').classList.add('hidden');
        $('courseModal').classList.remove('flex');
    }

    function closeCourseDetails() {
        $('courseDetailsModal')?.remove();
    }

    function openCourseDetails(course) {
        closeCourseDetails();

        const modal = document.createElement('div');
        modal.id = 'courseDetailsModal';
        modal.className = 'fixed inset-0 z-[55]';
        modal.innerHTML = `
            <button type="button" data-close-course-details class="absolute inset-0 bg-slate-900/30 backdrop-blur-sm" aria-label="Close course details"></button>
            <aside class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="courseDetailsTitle">
                <div class="flex items-start justify-between border-b border-slate-200 px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rose-600">Course record</p>
                        <h3 id="courseDetailsTitle" class="mt-1 text-xl font-bold text-slate-900">${esc(course.code)} - ${esc(course.name)}</h3>
                    </div>
                    <button type="button" data-close-course-details class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" aria-label="Close course details">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-6">
                    <p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">${esc(course.description || 'No description provided.')}</p>

                    <dl class="mt-6 divide-y divide-slate-100 rounded-xl border border-slate-200">
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Type</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.type)}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Year level</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${course.yearLevel ?? 'Not set'}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">College</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.collegeName || 'Unassigned')}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Program</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.programName || 'Unassigned')}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Major</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.majorName || 'Unassigned')}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Status</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.status)}</dd>
                        </div>
                    </dl>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
                    <button type="button" data-close-course-details class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Close</button>
                    ${admin ? `<button type="button" data-details-delete="${course.id}" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-100">Delete</button>` : ''}
                </div>
            </aside>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        modal.querySelectorAll('[data-close-course-details]').forEach((button) => {
            button.addEventListener('click', closeCourseDetails);
        });

        modal.querySelector('[data-details-delete]')?.addEventListener('click', () => openCourseDelete(course));
    }

    function closeCourseDelete() {
        $('courseDeleteModal')?.remove();
    }

    function openCourseDelete(course) {
        closeCourseDelete();

        const modal = document.createElement('div');
        modal.id = 'courseDeleteModal';
        modal.className = 'fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm';
        modal.innerHTML = `
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600">
                        <i data-lucide="trash-2" class="h-5 w-5"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Delete course?</h3>
                        <p class="mt-1 text-sm text-slate-600">Delete ${esc(course.code)} - ${esc(course.name)} permanently?</p>
                        <p class="mt-2 text-sm font-semibold text-red-600">This action cannot be undone.</p>
                    </div>
                </div>

                <label class="mt-5 block text-sm font-semibold text-slate-700">
                    Current password
                    <input type="password" data-course-delete-password autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password">
                </label>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" data-course-delete-cancel class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
                    <button type="button" data-course-delete-confirm class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700">
                        <i data-lucide="trash-2" class="h-4 w-4"></i>
                        Delete
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        modal.querySelector('[data-course-delete-cancel]').onclick = closeCourseDelete;
        modal.querySelector('[data-course-delete-password]').focus();
        modal.querySelector('[data-course-delete-confirm]').onclick = async () => {
            const password = modal.querySelector('[data-course-delete-password]').value;
            if (!password) {
                toast('Enter your current password to continue.', true);
                return;
            }

            const confirmButton = modal.querySelector('[data-course-delete-confirm]');
            confirmButton.disabled = true;
            confirmButton.innerHTML = '<i data-lucide="loader-circle" class="h-4 w-4 animate-spin"></i>Deleting...';
            lucide.createIcons();

            try {
                const data = await request('', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'delete', id: course.id, current_password: password })
                });

                closeCourseDelete();
                closeCourseDetails();
                toast(data.message);
                await loadCourses();
            } catch (error) {
                confirmButton.disabled = false;
                confirmButton.innerHTML = '<i data-lucide="trash-2" class="h-4 w-4"></i>Delete';
                lucide.createIcons();
                toast(error.message, true);
            }
        };
    }

    function render() {
        if (!$('courseBody')) {
            return;
        }

        $('courseBody').innerHTML = state.courses.length
            ? state.courses.map((course) => `
                <tr class="align-top hover:bg-slate-50">
                    <td class="px-3 py-4">
                        <div class="font-bold text-slate-900">${esc(course.code)}</div>
                    </td>
                    <td class="px-3 py-4 text-slate-600">
                        <div class="font-medium text-slate-800">${esc(course.name)}</div>
                    </td>
                    <td class="px-3 py-4 text-slate-600">
                        <span>${esc(course.type)}</span>
                        ${course.yearLevel ? `<div class="mt-1 text-xs text-slate-500">Year ${course.yearLevel}</div>` : ''}
                    </td>
                    <td class="px-3 py-4">
                        ${course.programName
                            ? `<div class="font-medium text-slate-800">${esc(course.programName)}</div><div class="text-xs text-slate-500">${esc(course.collegeName)}${course.majorName ? ` / ${esc(course.majorName)}` : ''}</div>`
                            : '<span class="text-xs italic text-slate-400">Unassigned</span>'}
                    </td>
                    <td class="px-3 py-4">
                        <span class="rounded-full px-2.5 py-1 text-xs font-bold ${course.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}">${esc(course.status)}</span>
                    </td>
                    <td class="px-3 py-4 text-right">
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="button" data-view="${course.id}" class="inline-flex items-center gap-1.5 rounded-md border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs font-semibold text-sky-700 hover:bg-sky-100">View</button>
                            ${admin ? `
                                <button type="button" data-edit="${course.id}" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:border-rose-400 hover:text-rose-700">Edit</button>
                                <button type="button" data-toggle="${course.id}" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700">${course.status === 'active' ? 'Deactivate' : 'Activate'}</button>
                                <button type="button" data-unlink="${course.id}" class="rounded-md border border-amber-300 px-2.5 py-1.5 text-xs font-semibold text-amber-700">Unlink</button>
                                <button type="button" data-delete="${course.id}" class="rounded-md border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-600">Delete</button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `).join('')
            : `
                <tr>
                    <td colspan="5" class="px-3 py-10 text-center text-sm text-slate-500">No courses found.</td>
                </tr>
            `;
    }

    const params = () => {
        const query = new URLSearchParams({
            action: 'list',
            search: $('courseSearch').value,
            status: $('courseStatus').value,
            college_id: $('courseCollege').value,
            program_id: $('courseProgram').value
        });

        return `?${query}`;
    };

    let latestCourseRequest = 0;

    async function loadCourses() {
        syncCourseProgramOptions();
        const requestId = ++latestCourseRequest;

        try {
            const data = await request(params());
            if (requestId !== latestCourseRequest) {
                return;
            }

            state.courses = data.courses;
            $('totalCount').textContent = data.counts.total;
            $('activeCount').textContent = data.counts.active;
            $('inactiveCount').textContent = data.counts.inactive;
            render();
            $('courseStatusMessage').textContent = `${state.courses.length} shown`;
        } catch (error) {
            if (requestId !== latestCourseRequest) {
                return;
            }

            $('courseStatusMessage').textContent = error.message;
            toast(error.message, true);
        }
    }

    async function loadOptions() {
        const data = await request('?action=options');
        state.colleges = data.colleges;
        state.programs = data.programs;
        state.majors = data.majors;

        fillSelect($('courseCollege'), state.colleges, 'All colleges');
        fillSelect($('courseProgram'), state.programs, 'All programs');
    }

    $('courseSearch').addEventListener('input', loadCourses);
    ['courseCollege', 'courseProgram', 'courseStatus'].forEach((id) => $(id)?.addEventListener('change', loadCourses));
    $('addCourseBtn')?.addEventListener('click', () => openModal());
    $('closeCourseModal').addEventListener('click', closeModal);
    $('cancelCourseModal').addEventListener('click', closeModal);

    $('formCollege').addEventListener('change', () => {
        fillSelect(
            $('formProgram'),
            state.programs.filter((item) => !$('formCollege').value || String(item.college_id) === $('formCollege').value),
            'No program link'
        );
        fillSelect($('formMajor'), [], 'No major link');
    });

    $('formProgram').addEventListener('change', () => {
        fillSelect(
            $('formMajor'),
            state.majors.filter((item) => String(item.program_id) === $('formProgram').value),
            'No major link'
        );
    });

    $('courseForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = new FormData(event.target);
        form.append('action', 'save');

        try {
            const data = await request('', { method: 'POST', body: form });
            closeModal();
            toast(data.message);
            await loadCourses();
        } catch (error) {
            toast(error.message, true);
        }
    });

    $('courseBody').addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        if (!button) {
            return;
        }

        const id = Number(button.dataset.edit || button.dataset.toggle || button.dataset.unlink || button.dataset.delete || button.dataset.view || button.dataset.detailsDelete || 0);
        const course = state.courses.find((item) => item.id === id);

        try {
            if (button.dataset.view) {
                if (course) {
                    openCourseDetails(course);
                }
                return;
            }

            if (button.dataset.edit) {
                if (course) {
                    openModal(course);
                }
                return;
            }

            if (button.dataset.toggle) {
                const data = await request('', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'toggle_status', id })
                });
                toast(data.message);
                await loadCourses();
                return;
            }

            if (button.dataset.unlink && confirm('Remove this course from its program and major? The course record will remain.')) {
                const data = await request('', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'unlink', id })
                });
                toast(data.message);
                await loadCourses();
                return;
            }

            if (button.dataset.delete) {
                if (course) {
                    openCourseDelete(course);
                }
            }
        } catch (error) {
            toast(error.message, true);
        }
    });

    $('courseBody').addEventListener('click', (event) => {
        const deleteButton = event.target.closest('[data-details-delete]');
        if (!deleteButton) {
            return;
        }

        const course = state.courses.find((item) => item.id === Number(deleteButton.dataset.detailsDelete));
        if (course) {
            openCourseDelete(course);
        }
    });

    loadOptions()
        .then(loadCourses)
        .catch((error) => toast(error.message, true));
})();