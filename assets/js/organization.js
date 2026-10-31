(() => {
    const tree = document.getElementById('organizationTree');
    const modal = document.getElementById('organizationModal');
    const form = document.getElementById('organizationForm');
    const prospectusRecordForm = document.getElementById('prospectusRecordForm');
    const prospectusRecordsBody = document.getElementById('prospectusRecordsBody');
    const retiredTitleField = document.getElementById('prospectusRecordTitle');
    retiredTitleField?.closest('label')?.remove();
    if (!document.getElementById('prospectusRecordTitle')) {
        const titleCompatibilityField = document.createElement('input');
        titleCompatibilityField.type = 'hidden';
        titleCompatibilityField.id = 'prospectusRecordTitle';
        titleCompatibilityField.name = 'retired_title';
        prospectusRecordForm.append(titleCompatibilityField);
    }
    let prospectusData = { prospectuses: [], courses: [], programs: [], majors: [], availableCourses: [], defaultCurriculumYear: '' };
    let selectedProspectusId = 0;
    const organizationStatus = document.getElementById('organizationStatus');
    const retryOrganizationBtn = document.getElementById('retryOrganizationBtn');
    const applyInputOutline = container => container.querySelectorAll('input:not([type="hidden"])').forEach(input => input.classList.add('border-2', 'border-slate-300', 'outline-none', 'focus:border-rose-600', 'focus:ring-2', 'focus:ring-rose-100'));
    applyInputOutline(form);
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const setOrganizationStatus = (message, error = false) => {
        organizationStatus.textContent = message;
        organizationStatus.classList.toggle('text-red-700', error);
        organizationStatus.classList.toggle('text-slate-600', !error);
        retryOrganizationBtn.classList.toggle('hidden', !error);
    };
    const updateContextHint = (text = '') => {
        const hint = document.getElementById('organizationContextHint');
        if (!hint) return;
        hint.textContent = text;
        hint.classList.toggle('hidden', !text);
    };
    const defaultHierarchyStatus = 'Use the hierarchy to add programs and majors.';
    function toast(message, error = false) {
        window.AppToast.show(message, error ? 'error' : 'success');
    }
  let organizationData = { colleges: [] };
  function closeDialog(dialog) { dialog?.classList.add('hidden'); dialog?.classList.remove('flex'); }
  function openDialog(dialog) { dialog?.classList.remove('hidden'); dialog?.classList.add('flex'); }
  let prospectusDataLoaded = false;
  let prospectusDataRequest = null;
  function loadProspectusData(forceRefresh = false) {
      if (prospectusDataLoaded && !forceRefresh) return Promise.resolve(prospectusData);
      if (prospectusDataRequest !== null) return prospectusDataRequest;

      prospectusDataRequest = fetch('pages/organization.php?action=prospectus_data', { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(response => response.json()).then(result => {
          if (!result.success) throw new Error(result.message);
          prospectusData = result;
          prospectusDataLoaded = true;
          renderProspectusRecords();
          return prospectusData;
      }).finally(() => {
          prospectusDataRequest = null;
      });
  }
  function refreshLoadedProspectusData() {
      if (!prospectusDataLoaded) return;
      loadProspectusData(true).catch(error => {
          document.getElementById('prospectusStatus').textContent = error.message;
          toast(`Prospectuses could not be loaded. ${error.message}`, true);
      });
  }
  function renderProspectusRecords() {
      const search = document.getElementById('prospectusSearch').value.trim().toLowerCase();
      const rows = prospectusData.prospectuses.filter(item => `${item.program_name} ${item.major_name || ''} ${item.curriculum_year}`.toLowerCase().includes(search));
      document.getElementById('prospectusRecordsBody').innerHTML = rows.length ? rows.map(item => {
          const displayName = `${item.program_name}${item.major_name ? ` (${item.major_name})` : ''} ${item.curriculum_year}`;
          const pdfActions = item.pdf_file_path ? `<a href="${esc(item.pdf_file_path)}" target="_blank" rel="noopener" aria-label="View ${esc(displayName)} PDF" title="View PDF" class="mx-auto flex h-8 w-fit items-center gap-1 rounded-md border border-slate-200 bg-white px-2 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400"><i data-lucide="file-text" class="h-3.5 w-3.5"></i><span>View PDF</span></a>` : '<span class="text-xs text-slate-400">No PDF</span>';
          return `<tr class="border-b border-slate-100 hover:bg-slate-50"><td class="px-4 py-3 font-semibold text-slate-900">${esc(item.program_name)}</td><td class="px-4 py-3 text-slate-600">${esc(item.major_name || 'General curriculum')}</td><td class="px-4 py-3 text-slate-600">${esc(item.curriculum_year)}</td><td class="px-4 py-3">${pdfActions}</td><td class="px-4 py-3"><div class="flex justify-end gap-1"><button type="button" data-row-replace="${item.id}" aria-label="Replace ${esc(displayName)} PDF" title="Replace PDF" class="inline-flex h-8 items-center gap-1 whitespace-nowrap rounded-md border border-sky-200 bg-white px-2 text-[11px] font-semibold text-sky-700 hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-400"><i data-lucide="upload" class="h-3.5 w-3.5"></i><span>Replace PDF</span></button><input type="file" accept="application/pdf,.pdf" data-row-file="${item.id}" class="hidden"><button type="button" data-delete-prospectus-record="${item.id}" data-name="${esc(displayName)}" aria-label="Delete ${esc(displayName)}" title="Delete record" class="inline-flex h-8 items-center gap-1 whitespace-nowrap rounded-md border border-rose-200 bg-white px-2 text-[11px] font-semibold text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i><span>Delete</span></button></div></td></tr>`;
      }).join('') : '<tr><td colspan="5" class="px-4 py-8 text-center text-sm italic text-slate-400">No prospectus records match your search.</td></tr>';
      lucide.createIcons();
      document.getElementById('prospectusStatus').textContent = `${rows.length} prospectus${rows.length === 1 ? '' : 'es'}`;
  }
    function fillProspectusPrograms(selected = '') { document.getElementById('prospectusRecordProgram').innerHTML = '<option value="">Select a program</option>' + prospectusData.programs.map(item => `<option value="${item.id}" ${Number(item.id) === Number(selected) ? 'selected' : ''}>${esc(item.name)}</option>`).join(''); }
  function fillProspectusMajors(programId, selected = '') { document.getElementById('prospectusRecordMajor').innerHTML = '<option value="">All majors / general curriculum</option>' + prospectusData.majors.filter(item => Number(item.program_id) === Number(programId)).map(item => `<option value="${item.id}" ${Number(item.id) === Number(selected) ? 'selected' : ''}>${esc(item.name)}</option>`).join(''); }
  const parentOptions = (type, selected) => {
      const options = [];
      organizationData.colleges.forEach(college => {
          if (type === 'program') options.push({ id: college.id, name: college.name });
          (college.programs || []).forEach(program => {
              if (type === 'major' || type === 'course') options.push({ id: program.id, name: `${college.name} / ${program.name}` });
          });
      });
      return '<option value="">Select parent</option>' + options.map(option => `<option value="${option.id}" ${Number(option.id) === Number(selected) ? 'selected' : ''}>${esc(option.name)}</option>`).join('');
  };
  const openForm = (action, title, id = '', parent = '', item = {}, major = '') => {
      form.reset();
      document.getElementById('organizationAction').value = action;
      document.getElementById('organizationId').value = id;
      document.getElementById('organizationParent').value = parent;
      document.getElementById('organizationMajor').value = major || item.major_id || '';
      document.getElementById('organizationModalTitle').textContent = title;
      const entity = action.replace(/^(add|edit)_/, '');
      const isEdit = action.startsWith('edit_');
      const isCreate = action.startsWith('add_');
    const needsCode = entity === 'course';
      const entityLabel = {
        college: 'college',
        program: 'program',
        major: 'major',
        course: 'course'
      }[entity] || 'record';

      const courseFields = document.getElementById('courseFields');
      courseFields.classList.toggle('hidden', !action.endsWith('course'));
      document.getElementById('organizationCodeLabel').classList.toggle('hidden', !needsCode);
      let descriptionField = form.querySelector('[name="description"]');
      if (!descriptionField) {
          const descriptionLabel = document.createElement('label');
          descriptionLabel.className = 'col-span-full text-sm font-medium';
          descriptionLabel.innerHTML = 'Description<textarea name="description" maxlength="500" rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></textarea>';
          courseFields.append(descriptionLabel);
          descriptionField = descriptionLabel.querySelector('textarea');
      }
      descriptionField.value = item.description || '';
      document.getElementById('organizationStatusLabel').classList.toggle('hidden', action.endsWith('course') || isCreate);
      document.getElementById('organizationName').value = item.name || '';
      document.getElementById('organizationCode').value = item.code || '';
      document.getElementById('organizationRecordStatus').value = item.status || 'active';
    if (item.code) form.code.value = item.code;
    if (item.description) form.description.value = item.description;

      const contextMap = {
        add_college: 'Create a new college and start building its programs below it.',
        add_program: 'Add a program under the selected college. This keeps the academic path clear and easy to audit.',
        add_major: 'Add a major under the selected program. Majors sit inside a program and can have course lists of their own.',
        add_course: 'Add a course under the selected program or major. Keep the code and year level consistent with the academic record.',
        edit_college: 'Update this college. Keep the name aligned with the institution’s structure.',
        edit_program: 'Update this program. Confirm the college before saving.',
        edit_major: 'Update this major. Keep the program relationship intact and avoid duplicate names.',
        edit_course: 'Update this course. The program and major assignment decide where it lives in the hierarchy.'
      };
      updateContextHint(contextMap[action] || `Manage this ${entityLabel} in context.`);

      const parentSelect = document.getElementById('parentSelect');
      const parentLabel = document.getElementById('parentSelectLabel');
      parentLabel.classList.toggle('hidden', !isEdit || entity === 'college');
      if (isEdit && entity !== 'college') {
          parentSelect.innerHTML = parentOptions(entity, parent);
          parentSelect.onchange = () => { document.getElementById('organizationParent').value = parentSelect.value; };
      } else if (isCreate && entity !== 'college') {
          parentSelect.innerHTML = parentOptions(entity, parent);
          parentSelect.value = String(parent || '');
          document.getElementById('organizationParent').value = parent || '';
          parentLabel.classList.remove('hidden');
      }

      const majorLabel = document.getElementById('majorSelectLabel');
      majorLabel.classList.toggle('hidden', !isEdit || entity !== 'course');
      if (isEdit && entity === 'course') {
          const program = organizationData.colleges.flatMap(c => c.programs || []).find(p => Number(p.id) === Number(parent));
          document.getElementById('majorSelect').innerHTML = '<option value="">No major</option>' + (program?.majors || []).map(majorItem => `<option value="${majorItem.id}" ${Number(majorItem.id) === Number(item.major_id) ? 'selected' : ''}>${esc(majorItem.name)}</option>`).join('');
          document.getElementById('majorSelect').onchange = () => { document.getElementById('organizationMajor').value = document.getElementById('majorSelect').value; };
      } else if (isCreate && entity === 'course') {
          const program = organizationData.colleges.flatMap(c => c.programs || []).find(p => Number(p.id) === Number(parent));
          document.getElementById('majorSelect').innerHTML = '<option value="">No major</option>' + (program?.majors || []).map(majorItem => `<option value="${majorItem.id}" ${majorItem.id === major ? 'selected' : ''}>${esc(majorItem.name)}</option>`).join('');
          document.getElementById('majorSelect').value = String(major || '');
          document.getElementById('organizationMajor').value = major || '';
          majorLabel.classList.remove('hidden');
      }
      modal.classList.remove('hidden'); modal.classList.add('flex'); document.getElementById('organizationName').focus();
  };
    const controls = (type, item, parent) => {
        const label = type.charAt(0).toUpperCase() + type.slice(1);
        return `<span class="flex flex-wrap items-center gap-1"><button type="button" data-edit="${type}" data-id="${item.id}" data-parent="${parent}" data-item='${esc(JSON.stringify(item))}' aria-label="Edit ${esc(label)} ${esc(item.name)}" title="Edit ${esc(label)}" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:border-rose-500 hover:bg-rose-600 hover:text-white focus:outline-none focus:ring-2 focus:ring-rose-300"><i data-lucide="pencil" class="h-3 w-3"></i><span>Edit ${esc(label)}</span></button><button type="button" data-delete="${type}" data-id="${item.id}" data-name="${esc(item.name)}" aria-label="Delete ${esc(label)} ${esc(item.name)}" title="Delete ${esc(label)}" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-semibold text-red-600 transition hover:border-red-500 hover:bg-red-600 hover:text-white focus:outline-none focus:ring-2 focus:ring-red-300"><i data-lucide="trash-2" class="h-3 w-3"></i><span>Delete ${esc(label)}</span></button></span>`;
    };
    const add = (action, title, parent, major = '') => action === 'add_course' ? '' : `<button type="button" data-add="${action}" data-title="${title}" data-parent="${parent}" data-major="${major}" title="${title}" aria-label="${title}" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:border-rose-500 hover:bg-rose-600 hover:text-white focus:outline-none focus:ring-2 focus:ring-rose-300"><i data-lucide="plus" class="h-3 w-3"></i><span>${title}</span></button>`;
        const countLabel = (count, singular) => `${count} ${singular}${count === 1 ? '' : 's'}`;
    let hierarchyBuilder;
    function createHierarchyBuilder() {
        if (hierarchyBuilder) return hierarchyBuilder;
        hierarchyBuilder = document.createElement('div');
        hierarchyBuilder.id = 'hierarchyBuilder';
        hierarchyBuilder.className = 'mt-5 hidden min-w-0 space-y-3';
        hierarchyBuilder.innerHTML = '<label class="block text-sm font-medium">College name<input data-field="college-name" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"></label><div data-programs class="space-y-3 border-l border-rose-200 pl-3"></div><button type="button" data-builder-add="program" class="text-sm font-semibold text-rose-600">+ Add program</button>';
        applyInputOutline(hierarchyBuilder);
        form.querySelector('#organizationMajor').after(hierarchyBuilder);
        hierarchyBuilder.addEventListener('click', event => {
            const action = event.target.closest('[data-builder-add]')?.dataset.builderAdd;
            const remove = event.target.closest('[data-builder-remove]');

            if (remove)
                remove.closest('[data-builder-item]')?.remove();

            if (action === 'program')
                addProgram(hierarchyBuilder);

            if (action === 'major')
                addMajor(event.target.closest('[data-builder-item]'));

            if (action === 'course')
                addCourse(event.target.closest('[data-builder-item]'));

            applyInputOutline(hierarchyBuilder);
        });
        return hierarchyBuilder;
    }
    function addProgram(builder) {
        if (!builder) return;
        const program = document.createElement('div');
        program.dataset.builderItem = 'program';
        program.className = 'rounded-lg border-l border-rose-200 bg-white p-3';
        program.innerHTML = '<p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Program under College</p><div class="flex gap-2"><input data-field="name" placeholder="Program name" required class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="px-2 text-sm text-red-500">Remove</button></div><div data-majors class="mt-3 space-y-2 border-l border-rose-200 pl-3"></div><button type="button" data-builder-add="major" class="mt-2 text-xs font-semibold text-rose-600">+ Add major</button>';
        builder.querySelector('[data-programs]').append(program);
    }
    function addMajor(program) {
        if (!program) return;
        const major = document.createElement('div');
        major.dataset.builderItem = 'major';
        major.className = 'min-w-0 rounded-lg border-l border-rose-200 p-2';
        major.innerHTML = '<p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Major under Program</p><div class="flex gap-2"><input data-field="name" placeholder="Major name" required class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="px-2 text-sm text-red-500">Remove</button></div>';
        program.querySelector('[data-majors]').append(major);
    }
    function addCourse(program) {
        if (!program) return;
        const course = document.createElement('div');
        course.dataset.builderItem = 'course';
        course.className = 'grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-[minmax(5rem,7rem)_minmax(0,1fr)]';
        const owner = program.dataset.builderItem === 'major' ? 'Major' : 'Program';
        course.innerHTML = `<p class="col-span-full text-[10px] font-semibold uppercase tracking-wide text-slate-500">Course under ${owner}</p><input data-field="code" placeholder="Code" required class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><input data-field="name" placeholder="Course name" required class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100 sm:col-span-2"><textarea data-field="description" placeholder="Course description (optional)" maxlength="500" rows="2" class="col-span-full min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"></textarea><button type="button" data-builder-remove class="justify-self-start px-2 text-sm text-red-500 sm:justify-self-end">Remove</button>`;
        program.querySelector('[data-courses]').append(course);
    }
    function openHierarchyForm() {
        const builder = createHierarchyBuilder();
        form.reset();
        document.getElementById('organizationAction').value = 'create_hierarchy';
        document.getElementById('organizationModalTitle').textContent = 'Add Academic Hierarchy';
        document.getElementById('courseFields').classList.add('hidden');
        document.getElementById('organizationName').required = false;
        document.getElementById('organizationName').closest('label').classList.add('hidden');
        document.getElementById('organizationCodeLabel').classList.add('hidden');
        document.getElementById('organizationStatusLabel').classList.add('hidden');
        updateContextHint('Create a full academic hierarchy in one flow: start with the academic unit, then add programs and majors before saving.');
        builder.classList.remove('hidden');
        builder.querySelector('[data-field="college-name"]').required = true;
        builder.querySelector('[data-programs]').innerHTML = '';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        builder.querySelector('[data-field="college-name"]').focus();
    }
    function closeHierarchyForm() {
        if (!hierarchyBuilder) return;
        hierarchyBuilder.classList.add('hidden');
        hierarchyBuilder.querySelector('[data-field="college-name"]').required = false;
        document.getElementById('organizationName').required = true;
        document.getElementById('organizationName').closest('label').classList.remove('hidden');
        document.getElementById('organizationCodeLabel').classList.remove('hidden');
        document.getElementById('organizationStatusLabel').classList.remove('hidden');
    }
    function collectHierarchy() {
        const builder = createHierarchyBuilder();
        return {
            name: builder.querySelector('[data-field="college-name"]').value.trim(),
            programs: [...builder.querySelectorAll('[data-programs] > [data-builder-item]')].map(program => ({
                    name: program.querySelector(':scope > div [data-field="name"]').value.trim(),
                    majors: [...program.querySelectorAll(':scope > [data-majors] > [data-builder-item]')].map(major => ({
                        name: major.querySelector(':scope > div [data-field="name"]').value.trim(),
                        courses: [...major.querySelectorAll(':scope > [data-courses] > [data-builder-item]')].map(course => ({
                            code: course.querySelector('[data-field="code"]').value.trim(),
                            name: course.querySelector('[data-field="name"]').value.trim(),
                            description: course.querySelector('[data-field="description"]').value.trim(),
                        })),
                    })),
                    courses: [...program.querySelectorAll(':scope > [data-courses] > [data-builder-item]')].map(course => ({
                        code: course.querySelector('[data-field="code"]').value.trim(),
                        name: course.querySelector('[data-field="name"]').value.trim(),
                        description: course.querySelector('[data-field="description"]').value.trim(),
                    })),
            })),
        };
    }
    let deleteDialog;
    function openDeleteDialog(type, id, name = 'this organization') {
        if (!deleteDialog) {
            deleteDialog = document.createElement('div');
            deleteDialog.className = 'fixed inset-0 z-[80] hidden items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm';
            deleteDialog.innerHTML = `<form class="w-full max-w-md rounded-2xl border border-red-200 bg-white p-6 shadow-2xl"><div class="flex items-start gap-3"><div class="rounded-full bg-red-100 p-2 text-red-600"><i data-lucide="triangle-alert" class="h-5 w-5"></i></div><div><h3 class="text-lg font-bold text-slate-900">Confirm deletion</h3><p class="mt-1 text-sm text-slate-500">Are you sure you want to delete this hierarchy entry? This may affect related records.</p></div></div><label class="mt-5 block text-sm font-semibold text-slate-700">Current password<input type="password" data-delete-password autocomplete="current-password" required class="mt-2 w-full rounded-lg border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-red-500 focus:ring-2 focus:ring-red-100" placeholder="Enter your password"></label><div class="mt-6 flex justify-end gap-3"><button type="button" data-delete-cancel class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Cancel</button><button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-300">Delete</button></div></form>`;
            document.body.append(deleteDialog);
            lucide.createIcons();
            deleteDialog.querySelector('[data-delete-cancel]').onclick = () => closeDeleteDialog();
            deleteDialog.querySelector('form').onsubmit = event => {
                event.preventDefault();
                const password = deleteDialog.querySelector('[data-delete-password]').value;
                const submitBtn = deleteDialog.querySelector('button[type="submit"]');
                setBusy(submitBtn, true, 'Deleting...');
                submit({ action: `delete_${deleteDialog.dataset.type}`, id: deleteDialog.dataset.id, current_password: password })
                    .then(() => closeDeleteDialog())
                    .catch(() => {})
                    .finally(() => setBusy(submitBtn, false));
            };
        }
        deleteDialog.dataset.type = type;
        deleteDialog.dataset.id = id;
        deleteDialog.querySelector('p').textContent = type === 'prospectus_record'
            ? `Delete prospectus for "${name}"? This permanently removes the prospectus record.`
            : `Delete "${name}"? This permanently removes the organization if it has no related records.`;
        deleteDialog.querySelector('[data-delete-password]').value = '';
        deleteDialog.classList.remove('hidden');
        deleteDialog.classList.add('flex');
        deleteDialog.querySelector('[data-delete-password]').focus();
    }
    function closeDeleteDialog() {
        deleteDialog?.classList.add('hidden');
        deleteDialog?.classList.remove('flex');
    }
    const renderDirectCollege = college => {
        const programs = college.programs || [];
        const programMarkup = programs.map(program => `<div class="border-l-2 border-rose-200 pl-4">
            <div class="flex cursor-pointer items-center gap-2" data-program-toggle="${program.id}">
                <i data-lucide="chevron-right" class="program-chevron h-4 w-4 shrink-0 text-rose-600 transition-transform"></i>
                <i data-lucide="book" class="h-3.5 w-3.5 text-rose-600"></i>
                <strong class="text-sm text-slate-700">${esc(program.name)}</strong>
                <span class="ml-auto flex flex-wrap gap-1">${controls('program', program, college.id)}${add('add_major', 'Add major', program.id)}</span>
            </div>
            <div id="program-${program.id}" class="program-body max-h-0 overflow-hidden pl-7 opacity-0 transition-all duration-200 ease-in-out"></div>
        </div>`).join('');

        return `<article class="college-row group overflow-hidden rounded-xl border border-transparent bg-slate-50 transition-all hover:border-rose-200"><div class="flex cursor-pointer items-center gap-3 p-4" data-college-toggle="${college.id}"><i data-lucide="chevron-right" class="college-chevron h-5 w-5 shrink-0 text-rose-600 transition-transform"></i><i data-lucide="building-2" class="h-5 w-5 shrink-0 text-rose-600"></i><div class="min-w-0 flex-1"><h4 class="truncate text-sm font-bold text-slate-800">${esc(college.name)}</h4><p class="text-[10px] uppercase tracking-wide text-slate-500">${countLabel(programs.length, 'Program')}</p></div><div class="flex flex-wrap items-center gap-1">${controls('college', college)}${add('add_program', 'Add program', college.id)}</div></div><div id="college-${college.id}" class="college-body max-h-0 overflow-hidden px-4 opacity-0 transition-all duration-300 ease-in-out"><div class="space-y-2 border-t border-slate-200 pb-4 pl-2 pt-2">${programMarkup || '<p class="text-xs italic text-slate-400">No programs yet</p>'}</div></div></article>`;
    };

    const renderProgramMajors = program => {
        const majors = program.majors || [];
        const majorMarkup = majors.map(major => `<div class="mt-2 flex items-center gap-2 text-sm font-semibold text-slate-700"><i data-lucide="tag" class="h-3.5 w-3.5 text-rose-600"></i><span>${esc(major.name)}</span><span class="ml-auto">${controls('major', major, program.id)}</span></div>`).join('');
        return majorMarkup || '<p class="py-2 text-xs italic text-slate-400">No majors yet</p>';
    };

    function render(data) {
        organizationData = data;
        const expandedColleges = new Set([...tree.querySelectorAll('.college-body:not(.max-h-0)')].map(body => body.id.replace('college-', '')));
        const expandedPrograms = new Set([...tree.querySelectorAll('.program-body:not(.max-h-0)')].map(body => body.id.replace('program-', '')));

        Object.entries(data.counts || {}).forEach(([key, value]) => {
            const el = document.querySelector(`[data-count="${key}"]`);
            if (el) el.textContent = value;
        });

        const colleges = Array.isArray(data.colleges) ? data.colleges : [];
        tree.innerHTML = colleges.length
            ? colleges.map(renderDirectCollege).join('')
            : '<div class="flex flex-col items-center justify-center gap-2 py-12 text-slate-500"><i data-lucide="building-2" class="h-10 w-10 text-slate-400"></i><p class="text-sm">No colleges yet. Click <strong>Add College</strong> to get started.</p></div>';
        tree.querySelectorAll('.college-body, .program-body').forEach(body => {
            body.style.maxHeight = '0px';
        });

        expandedColleges.forEach(collegeId => {
            const body = document.getElementById(`college-${collegeId}`);
            const toggle = tree.querySelector(`[data-college-toggle="${collegeId}"]`);
            if (body) {
                body.dataset.open = 'true';
                body.classList.remove('max-h-0', 'opacity-0');
                body.classList.add('opacity-100');
                body.style.maxHeight = '2000px';
            }
            const chevron = toggle?.querySelector('.college-chevron');
            if (chevron) {
                chevron.dataset.open = 'true';
                chevron.style.transform = 'rotate(90deg)';
            }
        });
        expandedPrograms.forEach(programId => {
            const program = data.colleges.flatMap(college => college.programs || []).find(item => String(item.id) === String(programId));
            const body = document.getElementById(`program-${programId}`);
            const toggle = tree.querySelector(`[data-program-toggle="${programId}"]`);
            if (program && body) body.innerHTML = renderProgramMajors(program);
            if (body) {
                body.dataset.open = 'true';
                body.classList.remove('max-h-0', 'opacity-0');
                body.classList.add('opacity-100');
                body.style.maxHeight = '1000px';
            }
            const chevron = toggle?.querySelector('.program-chevron');
            if (chevron) {
                chevron.dataset.open = 'true';
                chevron.style.transform = 'rotate(90deg)';
            }
        });
        tree.querySelectorAll('.college-chevron').forEach((icon) => {
            const collegeId = icon.closest('[data-college-toggle]')?.dataset.collegeToggle;
            const body = document.getElementById(`college-${collegeId}`);
            const isOpen = body?.dataset.open === 'true';
            icon.dataset.open = String(Boolean(isOpen));
            icon.style.transform = isOpen ? 'rotate(90deg)' : 'rotate(0deg)';
        });
        tree.querySelectorAll('.program-chevron').forEach((icon) => {
            const programId = icon.closest('[data-program-toggle]')?.dataset.programToggle;
            const body = document.getElementById(`program-${programId}`);
            const isOpen = body?.dataset.open === 'true';
            icon.dataset.open = String(Boolean(isOpen));
            icon.style.transform = isOpen ? 'rotate(90deg)' : 'rotate(0deg)';
        });
        lucide.createIcons();

        setOrganizationStatus('Organization updated');
    }
    let organizationRecords = [];
    function renderRecords() {
            const search = document.getElementById('organizationSearch').value.trim().toLowerCase();
            const status = document.getElementById('organizationStatusFilter').value;
            const type = document.getElementById('organizationTypeFilter').value;
            const rows = organizationRecords.filter(record => {
                    return (!search || `${record.name} ${record.code} ${record.type}`.toLowerCase().includes(search)) &&
                            (status === 'all' || record.status === status) && (type === 'all' || record.entity === type);
            });
            document.getElementById('organizationRecordsBody').innerHTML = rows.length ? rows.map(record => {
                        const recordLabel = `${record.type} ${record.name}`;
                        const archiveLabel = record.status === 'archived' ? 'Restore' : 'Archive';
                        const archiveIcon = record.status === 'archived' ? 'rotate-ccw' : 'archive';
                        const archiveClasses = record.status === 'archived' ? 'border-emerald-200 text-emerald-700 hover:bg-emerald-50 focus:ring-emerald-400' : 'border-amber-200 text-amber-700 hover:bg-amber-50 focus:ring-amber-400';
                        return `<tr class="hover:bg-slate-50"><td class="px-3 py-3 font-semibold text-slate-800">${esc(record.name)}</td><td class="px-3 py-3 text-slate-500">${esc(record.type)}</td><td class="px-3 py-3"><span class="rounded-full px-2 py-1 text-xs font-semibold ${record.status === 'archived' ? 'bg-slate-200 text-slate-600' : 'bg-emerald-100 text-emerald-700'}">${esc(record.status)}</span></td><td class="px-3 py-3"><div class="flex justify-end gap-1"><button type="button" data-record-edit="${record.entity}" data-id="${record.id}" aria-label="Edit ${esc(recordLabel)}" title="Edit" class="inline-flex h-8 items-center gap-1 whitespace-nowrap rounded-md border border-sky-200 bg-white px-2 text-[11px] font-semibold text-sky-700 hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-400"><i data-lucide="pencil" class="h-3.5 w-3.5"></i><span>Edit</span></button><button type="button" data-record-archive="${record.entity}" data-id="${record.id}" aria-label="${archiveLabel} ${esc(recordLabel)}" title="${archiveLabel}" class="inline-flex h-8 items-center gap-1 whitespace-nowrap rounded-md border bg-white px-2 text-[11px] font-semibold ${archiveClasses} focus:outline-none focus:ring-2"><i data-lucide="${archiveIcon}" class="h-3.5 w-3.5"></i><span>${archiveLabel}</span></button><button type="button" data-record-delete="${record.entity}" data-id="${record.id}" aria-label="Delete ${esc(recordLabel)}" title="Delete" class="inline-flex h-8 items-center gap-1 whitespace-nowrap rounded-md border border-rose-200 bg-white px-2 text-[11px] font-semibold text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i><span>Delete</span></button></div></td></tr>`;
                    }).join('') : '<tr><td colspan="4" class="px-3 py-8 text-center text-sm italic text-slate-400">No organization records match your search.</td></tr>';
            lucide.createIcons();
    }
    let organizationLoadToken = 0;
    let organizationDataLoaded = false;
    let organizationDataRequest = null;
    function load(forceReload = false) {
        if (organizationDataLoaded && !forceReload) return Promise.resolve(organizationData);
        if (organizationDataRequest !== null) return organizationDataRequest;

        const requestToken = ++organizationLoadToken;
        const request = fetch(
            'pages/organization.php?action=list',
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        ).then(response => response.json())
            .then(result => {
                if (requestToken !== organizationLoadToken) return;
                if (!result.success) throw new Error(result.message);
                render(result);
                organizationRecords = result.records || [];
                organizationDataLoaded = true;
                renderRecords();
                setOrganizationStatus(defaultHierarchyStatus);
                return result;
            })
            .catch(error => {
                if (requestToken !== organizationLoadToken) return;
                setOrganizationStatus(
                    'Organization could not be loaded',
                    true
                );

                toast(
                    `Organization could not be loaded. ${error.message}`,
                    true
                );
            });
        const trackedRequest = request.finally(() => {
            if (organizationDataRequest === trackedRequest) {
                organizationDataRequest = null;
            }
        });
        organizationDataRequest = trackedRequest;
        return trackedRequest;
    }
    document.getElementById('addCollegeBtn').onclick = openHierarchyForm;
    tree.onclick = event => {
        const programToggle = event.target.closest('[data-program-toggle]');
        const toggle = event.target.closest('[data-college-toggle]');
        const addButton = event.target.closest('[data-add]');
        const edit = event.target.closest('[data-edit]');
        const del = event.target.closest('[data-delete]');

        if (programToggle && !addButton && !edit && !del) {
            const programId = programToggle.dataset.programToggle;
            const body = document.getElementById(`program-${programId}`);
            if (body) {
                const isOpen = body.dataset.open === 'true';
                const nextOpen = !isOpen;
                if (!isOpen && !body.dataset.loaded) {
                    const program = organizationData.colleges
                        .flatMap(college => college.programs || [])
                        .find(item => String(item.id) === String(programId));
                    if (program) {
                        body.innerHTML = renderProgramMajors(program);
                        body.dataset.loaded = '1';
                        lucide.createIcons({ root: body });
                    }
                }
                const chevron = programToggle.querySelector('.program-chevron');
                body.dataset.open = String(nextOpen);
                body.classList.toggle('max-h-0', !nextOpen);
                body.style.maxHeight = nextOpen ? '1000px' : '0px';
                body.classList.toggle('opacity-0', !nextOpen);
                body.classList.toggle('opacity-100', nextOpen);
                if (chevron) {
                    chevron.dataset.open = String(nextOpen);
                    chevron.style.transform = nextOpen ? 'rotate(90deg)' : 'rotate(0deg)';
                }
            }
        }
        if (toggle && !addButton && !edit && !del) {
            const body = document.getElementById(`college-${toggle.dataset.collegeToggle}`);
            const chevron = toggle.querySelector('.college-chevron');
            if (body) {
                const isOpen = body.dataset.open === 'true';
                const nextOpen = !isOpen;
                body.dataset.open = String(nextOpen);
                body.classList.toggle('max-h-0', !nextOpen);
                body.style.maxHeight = nextOpen ? '2000px' : '0px';
                body.classList.toggle('opacity-0', !nextOpen);
                body.classList.toggle('opacity-100', nextOpen);
                if (chevron) {
                    chevron.dataset.open = String(nextOpen);
                    chevron.style.transform = nextOpen ? 'rotate(90deg)' : 'rotate(0deg)';
                }
            }
        }
        if (addButton) { closeHierarchyForm(); openForm(addButton.dataset.add, `Add ${addButton.dataset.title.replace('Add ', '')}`, '', addButton.dataset.parent, {}, addButton.dataset.major); }
        if (edit) { closeHierarchyForm(); openForm(`edit_${edit.dataset.edit}`, `Edit ${edit.dataset.edit}`, edit.dataset.id, edit.dataset.parent, JSON.parse(edit.dataset.item)); }
        if (del) openDeleteDialog(del.dataset.delete, del.dataset.id, del.dataset.name);
    };
    document.getElementById('organizationSearch').oninput = renderRecords;
    document.getElementById('organizationStatusFilter').onchange = renderRecords;
    document.getElementById('organizationTypeFilter').onchange = renderRecords;
    document.getElementById('organizationRecordsBody').onclick = event => {
            const edit = event.target.closest('[data-record-edit]');
            const archive = event.target.closest('[data-record-archive]');
            const del = event.target.closest('[data-record-delete]');
            const record = organizationRecords.find(item => Number(item.id) === Number((edit || archive || del)?.dataset.id) && item.entity === (edit || archive || del)?.dataset[(edit ? 'recordEdit' : archive ? 'recordArchive' : 'recordDelete')]);
            if (edit && record) { closeHierarchyForm(); openForm(`edit_${record.entity}`, `Edit ${record.type}`, record.id, record.parentId, record); }
            if (archive && record) {
                const archiveAction = record.status === 'archived' ? 'restore' : 'archive';
                if (archiveAction === 'archive' && !window.confirm(`Archive "${record.name}"? It will remain available in archived records.`)) return;
                submit({ action: `archive_${archive.dataset.recordArchive}`, id: archive.dataset.id });
            }
            if (del) openDeleteDialog(del.dataset.recordDelete, del.dataset.id, record?.name);
    };
  const closeOrganizationModal = () => {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      updateContextHint('');
  };
  document.getElementById('closeOrganizationModal').onclick = closeOrganizationModal;
  modal.addEventListener('click', event => { if (event.target === modal) closeOrganizationModal(); });
  document.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
          closeOrganizationModal();
          closeDeleteDialog();
      }
  });
    const setBusy = (button, busy, busyLabel = 'Working...') => {
      if (!button) return;
      if (busy) {
                    button.dataset.originalMarkup = button.innerHTML;
          button.textContent = busyLabel;
          button.disabled = true;
          button.classList.add('cursor-wait', 'opacity-70');
      } else {
          if (button.dataset.originalMarkup !== undefined) {
              button.innerHTML = button.dataset.originalMarkup;
              lucide.createIcons({ nodes: [button] });
              delete button.dataset.originalMarkup;
          }
          button.disabled = false;
          button.classList.remove('cursor-wait', 'opacity-70');
      }
  };
  const submit = data => {
      const submitButton = form.querySelector('button[type="submit"]');
      const submittedAction = data instanceof FormData ? data.get('action') : data.action;
      setBusy(submitButton, true, 'Saving...');
      return fetch('pages/organization.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: data instanceof FormData ? new URLSearchParams(data) : new URLSearchParams(data)})
          .then(r => r.json())
          .then(result => {
              if (!result.success) throw new Error(result.message);
              closeOrganizationModal();
              toast(result.message);
              if (submittedAction !== 'delete_prospectus_record' && organizationDataLoaded) {
                  load(true);
              }
              refreshLoadedProspectusData();
              return result;
          })
          .catch(e => { toast(e.message, true); throw e; })
          .finally(() => setBusy(submitButton, false));
  };
    form.onsubmit = e => {
        e.preventDefault();
        if (!form.reportValidity()) return;
        if (document.getElementById('organizationAction').value === 'create_hierarchy') {
            submit({ action: 'create_hierarchy', hierarchy: JSON.stringify(collectHierarchy()) });
            return;
        }
        submit(new FormData(form));
    };
    retryOrganizationBtn.onclick = () => load(true);
        document.getElementById('prospectusSearch').oninput = renderProspectusRecords;
        document.getElementById('prospectusRecordProgram').onchange = event => fillProspectusMajors(event.target.value);
        document.getElementById('addProspectusBtn').onclick = () => { prospectusRecordForm.reset(); document.getElementById('prospectusRecordId').value = ''; document.getElementById('prospectusRecordFile').required = true; document.getElementById('prospectusRecordYear').value = prospectusData.defaultCurriculumYear; fillProspectusPrograms(); fillProspectusMajors(''); document.getElementById('prospectusModalTitle').textContent = 'Upload Prospectus'; openDialog(document.getElementById('prospectusModal')); };
        document.getElementById('closeProspectusModal').onclick = () => closeDialog(document.getElementById('prospectusModal'));
        const uploadProspectusFile = (prospectusId, file, button) => {
            if (!file) return;
            const data = new FormData();
            data.append('action', 'upload_prospectus');
            data.append('prospectus_id', prospectusId);
            data.append('prospectus', file);
            setBusy(button, true, 'Uploading...');
            fetch('pages/organization.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: data })
                .then(response => response.json()).then(result => { if (!result.success) throw new Error(result.message); toast(result.message); return loadProspectusData(true); }).catch(error => toast(error.message, true)).finally(() => setBusy(button, false));
        };
        prospectusRecordsBody.addEventListener('click', event => {
            const replaceButton = event.target.closest('[data-row-replace]');
            if (replaceButton) {
                replaceButton.closest('tr')?.querySelector(`[data-row-file="${replaceButton.dataset.rowReplace}"]`)?.click();
                return;
            }
            const deleteRecordButton = event.target.closest('[data-delete-prospectus-record]');
            if (deleteRecordButton) {
                const prospectusId = deleteRecordButton.dataset.deleteProspectusRecord;
                const name = deleteRecordButton.dataset.name || 'this prospectus';
                openDeleteDialog('prospectus_record', prospectusId, name);
                return;
            }
            const removeButton = event.target.closest('[data-delete-prospectus-pdf]');
            if (removeButton && window.confirm('Remove this prospectus PDF?')) {
                fetch('pages/organization.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'delete_prospectus_document', prospectus_id: removeButton.dataset.deleteProspectusPdf }) })
                    .then(response => response.json()).then(result => { if (!result.success) throw new Error(result.message); toast(result.message); return loadProspectusData(true); }).catch(error => toast(error.message, true));
            }
        });
            prospectusRecordsBody.addEventListener('change', event => {
                const fileInput = event.target.closest('input[data-row-file]');
                if (!fileInput) return;
                uploadProspectusFile(fileInput.dataset.rowFile, fileInput.files[0], fileInput.closest('tr')?.querySelector('[data-row-replace]'));
            });
        prospectusRecordsBody.addEventListener('submit', event => {
            const uploadForm = event.target.closest('[data-row-upload]');
            if (!uploadForm) return;
            event.preventDefault();
            const file = uploadForm.querySelector('[data-row-file]').files[0];
            if (!file) return;
            const data = new FormData();
            data.append('action', 'upload_prospectus');
            data.append('prospectus_id', uploadForm.dataset.rowUpload);
            data.append('prospectus', file);
            const button = uploadForm.querySelector('button[type="submit"]');
            setBusy(button, true, 'Uploading...');
            fetch('pages/organization.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: data })
                .then(response => response.json()).then(result => { if (!result.success) throw new Error(result.message); toast(result.message); return loadProspectusData(true); }).catch(error => toast(error.message, true)).finally(() => setBusy(button, false));
        });
        prospectusRecordForm.onsubmit = event => {
            event.preventDefault();
            if (!prospectusRecordForm.reportValidity()) return;
            const data = new FormData(prospectusRecordForm);
            const file = document.getElementById('prospectusRecordFile').files[0];
            data.append('action', 'save_prospectus');
            fetch('pages/organization.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: data })
                .then(response => response.json())
                .then(result => {
                    if (!result.success) throw new Error(result.message);
                    selectedProspectusId = Number(result.id);
                    if (!file) return result;
                    const upload = new FormData();
                    upload.append('action', 'upload_prospectus');
                    upload.append('prospectus_id', result.id);
                    upload.append('prospectus', file);
                    return fetch('pages/organization.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: upload }).then(response => response.json()).then(uploadResult => { if (!uploadResult.success) throw new Error(uploadResult.message); return uploadResult; });
                })
                .then(result => { closeDialog(document.getElementById('prospectusModal')); toast(result.message); return loadProspectusData(true); })
                .catch(error => toast(error.message, true));
        };

    // Academics Tabbing UI logic
    const tabNav = document.getElementById('academicsTabNav');
    if (tabNav) {
        const tabButtons = tabNav.querySelectorAll('.academics-tab');
        const tabPanels = document.querySelectorAll('.tab-panel');

        function activateTab(tabName) {
            tabButtons.forEach(btn => {
                const isSelected = btn.dataset.tab === tabName;
                btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                if (isSelected) {
                    btn.className = 'academics-tab inline-flex items-center gap-1.5 rounded-md bg-rose-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-rose-700';
                } else {
                    btn.className = 'academics-tab inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold text-slate-600 transition-all hover:bg-slate-100 hover:text-slate-900';
                }
            });

            tabPanels.forEach(panel => {
                const isTarget = panel.id === `${tabName}Panel` || (tabName === 'secondary' && panel.id === 'secondaryViewPanel');
                panel.classList.toggle('hidden', !isTarget);
            });

            try {
                sessionStorage.setItem('academics_active_tab', tabName);
            } catch (e) {}

            if (tabName === 'prospectus') {
                loadProspectusData().catch(error => {
                    document.getElementById('prospectusStatus').textContent = error.message;
                    toast(`Prospectuses could not be loaded. ${error.message}`, true);
                });
            } else {
                load();
            }

        }

        tabButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                activateTab(btn.dataset.tab);
            });
        });

        let savedTab = '';
        try {
            savedTab = sessionStorage.getItem('academics_active_tab') || '';
        } catch (e) {}

        const hashTab = window.location.hash.replace('#', '');
        const initialTab = ['hierarchy', 'secondary', 'prospectus'].includes(hashTab) ? hashTab : (['hierarchy', 'secondary', 'prospectus'].includes(savedTab) ? savedTab : 'hierarchy');
        activateTab(initialTab);
    }

})();
