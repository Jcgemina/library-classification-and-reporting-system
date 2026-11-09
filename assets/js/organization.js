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
  let selectedCollegeId = null;
  let expandedTreeProgramIds = new Set();
  let renderedTreeCollegeId = null;
  let academicActionMenuId = 0;
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
      return prospectusDataRequest;
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
          const pdfStatus = item.pdf_file_path
              ? `<a href="${esc(item.pdf_file_path)}" target="_blank" rel="noopener" aria-label="View ${esc(displayName)} PDF" class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 hover:underline"><i data-lucide="file-check-2" class="h-3.5 w-3.5" aria-hidden="true"></i>PDF ready</a>`
              : '<span class="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-700"><i data-lucide="file-warning" class="h-3.5 w-3.5" aria-hidden="true"></i>PDF missing</span>';
          return `<article class="grid grid-cols-1 items-center gap-2 border-b border-slate-200 py-3 last:border-b-0 sm:grid-cols-[minmax(0,1.4fr)_minmax(110px,.75fr)_minmax(86px,.55fr)_auto_auto] sm:gap-3">
              <span class="text-xs font-semibold text-slate-900">${esc(item.program_name)}</span>
              <span class="text-[11px] text-slate-600">${esc(item.major_name || 'General curriculum')}</span>
              <span class="text-[11px] tabular-nums text-slate-700">${esc(item.curriculum_year)}</span>
              ${pdfStatus}
              <div class="flex items-center gap-1">
                <button type="button" data-row-replace="${item.id}" aria-label="Replace ${esc(displayName)} PDF" title="Replace PDF" class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-2 text-[10px] font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-400"><i data-lucide="upload" class="h-3.5 w-3.5" aria-hidden="true"></i><span>Replace</span></button>
                <input type="file" accept="application/pdf,.pdf" data-row-file="${item.id}" class="hidden">
                <button type="button" data-delete-prospectus-record="${item.id}" data-name="${esc(displayName)}" aria-label="Delete ${esc(displayName)}" title="Delete prospectus" class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-2 text-[10px] font-semibold text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400"><i data-lucide="trash-2" class="h-3.5 w-3.5" aria-hidden="true"></i><span>Delete</span></button>
              </div>
            </article>`;
      }).join('') : '<p class="py-8 text-center text-xs text-slate-600">No prospectuses match your search. Try another program or curriculum year.</p>';
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
    const hierarchyEditAction = (entity, item, parent = '') => `<button type="button" data-edit="${entity}" data-id="${Number(item.id)}" ${parent ? `data-parent="${Number(parent)}"` : ''} data-item='${esc(JSON.stringify(item))}' aria-label="Edit ${entity} ${esc(item.name)}" class="academic-row-action"><i data-lucide="pencil" class="h-3.5 w-3.5" aria-hidden="true"></i><span>Edit ${entity}</span></button>`;
    const hierarchyDeleteAction = (entity, item) => `<button type="button" data-delete="${entity}" data-id="${Number(item.id)}" data-name="${esc(item.name)}" aria-label="Delete ${entity} ${esc(item.name)}" class="academic-row-action is-delete"><i data-lucide="trash-2" class="h-3.5 w-3.5" aria-hidden="true"></i><span>Delete ${entity}</span></button>`;
    const academicMenuAddAction = (title, parent) => `<button type="button" data-add="add_major" data-title="${esc(title)}" data-parent="${Number(parent)}" data-major="" title="${esc(title)}" aria-label="${esc(title)}" class="academic-row-action"><i data-lucide="plus" class="h-3 w-3" aria-hidden="true"></i><span>${esc(title)}</span></button>`;
    const academicActionsDisclosure = (label, actionsMarkup) => {
        const menuId = `organization-actions-${++academicActionMenuId}`;
        return `<span class="academic-tree-action-anchor">
          <button type="button" class="academic-tree-action-trigger" data-academic-menu-trigger aria-label="${esc(label)}" aria-haspopup="true" aria-expanded="false" aria-controls="${menuId}"><i data-lucide="ellipsis" aria-hidden="true"></i><span>Actions</span></button>
          <div class="academic-action-menu-options" id="${menuId}" data-academic-action-menu popover="auto">${actionsMarkup}</div>
        </span>`;
    };
    const hierarchyControls = item => academicActionsDisclosure(
        'College actions',
        `${hierarchyEditAction('college', item)}${hierarchyDeleteAction('college', item)}`
    );
    const add = (action, title, parent, major = '') => action === 'add_course' ? '' : `<button type="button" data-add="${action}" data-title="${title}" data-parent="${parent}" data-major="${major}" title="${title}" aria-label="${title}" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700 transition hover:border-rose-500 hover:bg-rose-600 hover:text-white focus-visible:outline-none focus-visible:border-rose-300 focus-visible:bg-rose-100 focus-visible:text-rose-800"><i data-lucide="plus" class="h-3 w-3"></i><span>${title}</span></button>`;
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
        const programs = Array.isArray(college.programs) ? college.programs : [];
        const majorCount = programs.reduce((total, program) => total + (program.majors || []).length, 0);
        const statusLabel = status => {
            const value = String(status || 'active');
            return value.charAt(0).toUpperCase() + value.slice(1);
        };
        const programRows = programs.map(program => {
            const majors = program.majors || [];
            const programId = Number(program.id);
            const programExpanded = expandedTreeProgramIds.has(String(program.id));
            const programStatus = statusLabel(program.status);
            const programStatusClass = String(program.status).toLowerCase() === 'active'
                ? 'bg-emerald-100 text-emerald-800'
                : 'bg-slate-100 text-slate-700';
            const programToggle = majors.length
                ? `<button type="button" class="academic-tree-toggle" data-tree-toggle="${programId}" aria-controls="${majors.map(major => `academic-major-${Number(major.id)}`).join(' ')}" aria-expanded="${programExpanded}" aria-label="${programExpanded ? 'Collapse' : 'Expand'} ${esc(program.name)}"><i data-lucide="chevron-right" aria-hidden="true"></i></button>`
                : '<span class="academic-tree-toggle-spacer" aria-hidden="true"></span>';
            const programActions = academicActionsDisclosure(
                `Actions for ${program.name}`,
                `${academicMenuAddAction('Add major', program.id)}${hierarchyEditAction('program', program, college.id)}${hierarchyDeleteAction('program', program)}`
            );
            const programRow = `<tr data-program-row data-program-id="${programId}" id="academic-program-${programId}">
              <td class="academic-tree-name-cell"><div class="academic-tree-name">${programToggle}<span class="academic-tree-unit-name">${esc(program.name)}</span></div></td>
              <td class="academic-tree-type-cell">Program</td>
              <td><span class="academic-tree-status ${programStatusClass}">${esc(programStatus)}</span></td>
              <td class="academic-tree-actions-cell">${programActions}</td>
            </tr>`;
            const majorRows = majors.map(major => {
                const majorStatus = statusLabel(major.status);
                const majorStatusClass = String(major.status).toLowerCase() === 'active'
                    ? 'bg-emerald-100 text-emerald-800'
                    : 'bg-slate-100 text-slate-700';
                const majorActions = academicActionsDisclosure(
                    `Actions for ${major.name}`,
                    `${hierarchyEditAction('major', major, program.id)}${hierarchyDeleteAction('major', major)}`
                );
                return `<tr data-major-row data-parent-program="${programId}" data-major-id="${Number(major.id)}" id="academic-major-${Number(major.id)}">
                  <td class="academic-tree-name-cell"><div class="academic-tree-name is-major"><span class="academic-tree-toggle-spacer" aria-hidden="true"></span><span class="academic-tree-unit-name">${esc(major.name)}</span></div></td>
                  <td class="academic-tree-type-cell">Major</td>
                  <td><span class="academic-tree-status ${majorStatusClass}">${esc(majorStatus)}</span></td>
                  <td class="academic-tree-actions-cell">${majorActions}</td>
                </tr>`;
            }).join('');
            return programRow + majorRows;
        }).join('');
        const emptyMessage = programs.length
            ? 'No programs or majors match your search.'
            : 'No programs in this college yet. Add a program to build the hierarchy.';

        return `<header class="flex flex-col justify-between gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-start">
            <div class="min-w-0"><h3 class="break-words text-lg font-bold text-slate-900">${esc(college.name)}</h3><p class="mt-1 text-xs text-slate-600">Academic structure · ${esc(statusLabel(college.status))}</p></div>
            <div class="flex flex-wrap items-center gap-2">${add('add_program', 'Add program', college.id)}${hierarchyControls(college)}</div>
          </header>
          <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 py-3 text-[11px] text-slate-600" aria-label="Selected college totals">
            <span><strong class="mr-1 text-xs tabular-nums text-slate-900">${programs.length}</strong>${programs.length === 1 ? 'program' : 'programs'}</span>
            <span><strong class="mr-1 text-xs tabular-nums text-slate-900">${majorCount}</strong>${majorCount === 1 ? 'major' : 'majors'}</span>
          </div>
          <div class="academic-tree-toolbar">
            <label class="academic-tree-search"><i data-lucide="search" aria-hidden="true"></i><input id="organizationTreeSearch" type="search" placeholder="Search programs or majors" aria-label="Search programs and majors"></label>
            <span id="organizationTreeResultCount" class="text-[11px] text-slate-600" aria-live="polite"></span>
          </div>
          <div class="academic-tree-table-wrap">
            <table class="academic-tree-table" aria-label="Academic hierarchy">
              <thead><tr><th scope="col">Academic unit</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col" class="text-right">Actions</th></tr></thead>
              <tbody id="organizationTreeRows">${programRows}
                <tr id="organizationTreeEmptyRow" ${programs.length ? 'hidden' : ''}><td colspan="4"><p id="organizationTreeEmpty" class="py-6 text-center text-xs text-slate-600">${emptyMessage}</p></td></tr>
              </tbody>
            </table>
          </div>`;
    };

    function filterOrganizationTree() {
        const college = (organizationData.colleges || []).find(item => String(item.id) === String(selectedCollegeId));
        if (!college) return;
        const query = document.getElementById('organizationTreeSearch')?.value.trim().toLocaleLowerCase() || '';
        const rows = document.getElementById('organizationTreeRows');
        let visiblePrograms = 0;
        let visibleMajors = 0;
        const totalMajors = (college.programs || []).reduce((total, program) => total + (program.majors || []).length, 0);

        (college.programs || []).forEach(program => {
            const programId = String(program.id);
            const programRow = rows.querySelector(`[data-program-id="${CSS.escape(programId)}"]`);
            if (!programRow) return;
            const programMatches = String(program.name).toLocaleLowerCase().includes(query);
            const matchingMajors = (program.majors || []).filter(major => String(major.name).toLocaleLowerCase().includes(query));
            const showProgram = query === '' || programMatches || matchingMajors.length > 0;
            programRow.hidden = !showProgram;
            if (!showProgram) return;
            visiblePrograms++;

            const expanded = expandedTreeProgramIds.has(programId);
            const toggle = programRow.querySelector('[data-tree-toggle]');
            if (toggle) {
                toggle.setAttribute('aria-expanded', String(expanded));
                toggle.setAttribute('aria-label', `${expanded ? 'Collapse' : 'Expand'} ${program.name}`);
            }
            rows.querySelectorAll(`[data-major-row][data-parent-program="${CSS.escape(programId)}"]`).forEach(majorRow => {
                const majorMatches = majorRow.querySelector('.academic-tree-unit-name').textContent.toLocaleLowerCase().includes(query);
                const showMajor = expanded && (query === '' || programMatches || majorMatches);
                majorRow.hidden = !showMajor;
                if (showMajor) visibleMajors++;
            });
        });

        const emptyRow = document.getElementById('organizationTreeEmptyRow');
        const empty = document.getElementById('organizationTreeEmpty');
        emptyRow.hidden = visiblePrograms > 0;
        empty.textContent = query
            ? 'No programs or majors match your search.'
            : 'No programs in this college yet. Add a program to build the hierarchy.';
        document.getElementById('organizationTreeResultCount').textContent = query === ''
            ? `${visiblePrograms} ${visiblePrograms === 1 ? 'program' : 'programs'} · ${visibleMajors} of ${totalMajors} ${totalMajors === 1 ? 'major' : 'majors'} visible`
            : `${visiblePrograms} matching ${visiblePrograms === 1 ? 'program' : 'programs'} · ${visibleMajors} ${visibleMajors === 1 ? 'major' : 'majors'} shown`;
    }

    function renderSelectedCollege() {
        const college = (organizationData.colleges || []).find(item => String(item.id) === String(selectedCollegeId));
        if (!college) {
            renderedTreeCollegeId = null;
            expandedTreeProgramIds.clear();
            tree.innerHTML = '<div class="flex min-h-48 flex-col items-center justify-center text-center"><i data-lucide="building-2" class="h-8 w-8 text-slate-400" aria-hidden="true"></i><p class="mt-3 text-sm font-semibold text-slate-900">No colleges yet</p><p class="mt-1 text-xs text-slate-600">Add an academic unit to start building the structure.</p></div>';
            lucide.createIcons({ root: tree });
            return;
        }
        if (String(renderedTreeCollegeId) !== String(selectedCollegeId)) {
            expandedTreeProgramIds = new Set((college.programs || []).slice(0, 1).map(program => String(program.id)));
            renderedTreeCollegeId = String(selectedCollegeId);
        }
        tree.innerHTML = renderDirectCollege(college);
        filterOrganizationTree();
        lucide.createIcons({ root: tree });
    }

    function renderCollegeChoices() {
        const choices = document.getElementById('collegeChoices');
        const colleges = organizationData.colleges || [];
        choices.innerHTML = colleges.length ? colleges.map(college => {
            const programCount = (college.programs || []).length;
            const selected = String(college.id) === String(selectedCollegeId);
            return `<button type="button" class="flex min-h-[38px] w-auto min-w-max items-center justify-between gap-3 rounded-md px-2 text-left text-[11px] text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 md:w-full md:min-w-0 ${selected ? 'bg-rose-100 font-semibold text-rose-800' : ''}" data-academic-college="${Number(college.id)}" aria-pressed="${selected}" aria-label="${esc(college.name)}, ${programCount} ${programCount === 1 ? 'program' : 'programs'}">
                <span class="min-w-0 md:whitespace-normal">${esc(college.name)}</span><span class="shrink-0 text-[10px] tabular-nums text-slate-600">${programCount}</span>
              </button>`;
        }).join('') : '<p class="px-2 py-2 text-xs text-slate-600">No colleges have been added.</p>';
    }

    function render(data) {
        organizationData = data;
        const colleges = Array.isArray(data.colleges) ? data.colleges : [];
        if (!colleges.some(college => String(college.id) === String(selectedCollegeId))) {
            selectedCollegeId = (colleges.find(college => college.status === 'active') || colleges[0])?.id ?? null;
        }
        renderCollegeChoices();
        renderSelectedCollege();
        setOrganizationStatus('Organization updated');
    }

    let organizationRecords = [];
    function renderRecords() {
        const searchTerms = document.getElementById('organizationSearch').value.trim().toLowerCase().split(/\s+/).filter(Boolean);
        const status = document.getElementById('organizationStatusFilter').value;
        const type = document.getElementById('organizationTypeFilter').value;
        const parentNameFor = record => {
            if (record.parentId === null) return 'University';
            const parentEntity = record.entity === 'major' ? 'program' : 'college';
            return organizationRecords.find(item => item.entity === parentEntity && Number(item.id) === Number(record.parentId))?.name || 'Parent unavailable';
        };
        const rows = organizationRecords.filter(record => {
            const searchableText = `${record.name} ${record.code} ${record.type} ${parentNameFor(record)}`.toLowerCase();
            return searchTerms.every(term => searchableText.includes(term))
                && (status === 'all' || record.status === status)
                && (type === 'all' || record.entity === type);
        });
        document.getElementById('organizationRecordsBody').innerHTML = rows.length ? rows.map(record => {
            const recordLabel = `${record.type} ${record.name}`;
            const parent = parentNameFor(record);
            const archiveLabel = record.status === 'archived' ? 'Restore' : 'Archive';
            const archiveIcon = record.status === 'archived' ? 'rotate-ccw' : 'archive';
            const archiveClasses = record.status === 'archived' ? 'text-emerald-700 hover:bg-emerald-50' : 'text-amber-800 hover:bg-amber-50';
            const statusLabel = record.status === 'archived' ? 'Archived' : 'Active';
            return `<tr class="transition-colors hover:bg-slate-50">
                <td class="px-3 py-3"><div class="text-xs font-semibold text-slate-900">${esc(record.name)}</div><div class="mt-0.5 text-[10px] text-slate-600">${esc(parent)}</div></td>
                <td class="whitespace-nowrap px-3 py-3 text-[11px] text-slate-700">${esc(record.type)}</td>
                <td class="px-3 py-3"><span class="inline-flex rounded-md px-2 py-1 text-[10px] font-semibold ${record.status === 'archived' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-100 text-emerald-800'}">${statusLabel}</span></td>
                <td class="px-3 py-3"><div class="flex justify-end gap-1">
                  <button type="button" data-record-edit="${esc(record.entity)}" data-id="${Number(record.id)}" aria-label="Edit ${esc(recordLabel)}" title="Edit" class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-2 text-[10px] font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-400"><i data-lucide="pencil" class="h-3.5 w-3.5" aria-hidden="true"></i><span>Edit</span></button>
                  <button type="button" data-record-archive="${esc(record.entity)}" data-id="${Number(record.id)}" aria-label="${archiveLabel} ${esc(recordLabel)}" title="${archiveLabel}" class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-2 text-[10px] font-semibold ${archiveClasses} focus:outline-none focus:ring-2 focus:ring-rose-400"><i data-lucide="${archiveIcon}" class="h-3.5 w-3.5" aria-hidden="true"></i><span>${archiveLabel}</span></button>
                  <button type="button" data-record-delete="${esc(record.entity)}" data-id="${Number(record.id)}" aria-label="Delete ${esc(recordLabel)}" title="Delete" class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-2 text-[10px] font-semibold text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400"><i data-lucide="trash-2" class="h-3.5 w-3.5" aria-hidden="true"></i><span>Delete</span></button>
                </div></td>
              </tr>`;
        }).join('') : '<tr><td colspan="4" class="px-3 py-8 text-center text-xs text-slate-600">No units match your search. Try another name or filter.</td></tr>';
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
    function openOrganizationAddForm(button) {
        closeHierarchyForm();
        openForm(button.dataset.add, `Add ${button.dataset.title.replace('Add ', '')}`, '', button.dataset.parent, {}, button.dataset.major);
    }

    function openOrganizationEditForm(button) {
        closeHierarchyForm();
        openForm(`edit_${button.dataset.edit}`, `Edit ${button.dataset.edit}`, button.dataset.id, button.dataset.parent, JSON.parse(button.dataset.item));
    }

    function positionAcademicActionMenu(menu) {
        if (!menu.matches(':popover-open')) return;
        const trigger = menu.parentElement.querySelector('[data-academic-menu-trigger]');
        if (!trigger) return;
        const triggerRect = trigger.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();
        const left = Math.max(8, Math.min(triggerRect.right - menuRect.width, window.innerWidth - menuRect.width - 8));
        const below = triggerRect.bottom + 5;
        const top = below + menuRect.height <= window.innerHeight - 8
            ? below
            : Math.max(8, triggerRect.top - menuRect.height - 5);
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
    }

    function toggleAcademicActionMenu(trigger) {
        const menu = trigger.parentElement.querySelector('[data-academic-action-menu]');
        if (!menu) return;
        if (!menu.dataset.ariaSync) {
            menu.addEventListener('toggle', () => {
                trigger.setAttribute('aria-expanded', String(menu.matches(':popover-open')));
            });
            menu.dataset.ariaSync = 'true';
        }
        if (menu.matches(':popover-open')) {
            menu.hidePopover();
            trigger.setAttribute('aria-expanded', 'false');
            return;
        }
        document.querySelectorAll('[data-academic-action-menu]:popover-open').forEach(openMenu => openMenu.hidePopover());
        menu.showPopover();
        trigger.setAttribute('aria-expanded', 'true');
        positionAcademicActionMenu(menu);
    }

    function handleOrganizationTreeClick(event) {
        const treeToggle = event.target.closest('[data-tree-toggle]');
        if (treeToggle) {
            const programId = String(treeToggle.dataset.treeToggle);
            if (expandedTreeProgramIds.has(programId)) expandedTreeProgramIds.delete(programId);
            else expandedTreeProgramIds.add(programId);
            filterOrganizationTree();
            return;
        }

        const addButton = event.target.closest('[data-add]');
        const editButton = event.target.closest('[data-edit]');
        const deleteButton = event.target.closest('[data-delete]');
        const actionButton = addButton || editButton || deleteButton;
        actionButton?.closest('[data-academic-action-menu]')?.hidePopover();

        if (addButton) openOrganizationAddForm(addButton);
        if (editButton) openOrganizationEditForm(editButton);
        if (deleteButton) openDeleteDialog(deleteButton.dataset.delete, deleteButton.dataset.id, deleteButton.dataset.name);
    }

    tree.onclick = handleOrganizationTreeClick;
    tree.addEventListener('input', event => {
        if (event.target.id !== 'organizationTreeSearch') return;
        const query = event.target.value.trim().toLocaleLowerCase();
        const college = (organizationData.colleges || []).find(item => String(item.id) === String(selectedCollegeId));
        (college?.programs || []).forEach(program => {
            if (query && (program.majors || []).some(major => String(major.name).toLocaleLowerCase().includes(query))) {
                expandedTreeProgramIds.add(String(program.id));
            }
        });
        filterOrganizationTree();
    });
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-academic-menu-trigger]');
        if (trigger) toggleAcademicActionMenu(trigger);
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const menu = document.querySelector('[data-academic-action-menu]:popover-open');
        if (!menu) return;
        const trigger = menu.parentElement.querySelector('[data-academic-menu-trigger]');
        menu.hidePopover();
        trigger?.setAttribute('aria-expanded', 'false');
        trigger?.focus();
    });
    document.addEventListener('scroll', () => {
        document.querySelectorAll('[data-academic-action-menu]:popover-open').forEach(positionAcademicActionMenu);
    }, true);
    window.addEventListener('resize', () => {
        document.querySelectorAll('[data-academic-action-menu]:popover-open').forEach(positionAcademicActionMenu);
    });
    document.getElementById('collegeChoices').addEventListener('click', event => {
        const choice = event.target.closest('[data-academic-college]');
        if (!choice) return;
        selectedCollegeId = choice.dataset.academicCollege;
        renderCollegeChoices();
        renderSelectedCollege();
    });
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
                if (isSelected) btn.setAttribute('aria-current', 'page');
                else btn.removeAttribute('aria-current');
                btn.tabIndex = isSelected ? 0 : -1;
                if (isSelected) {
                    btn.className = 'academics-tab flex min-h-9 items-center gap-2 rounded-md bg-rose-100 px-2.5 text-left text-xs font-semibold text-rose-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500';
                } else {
                    btn.className = 'academics-tab flex min-h-9 items-center gap-2 rounded-md px-2.5 text-left text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500';
                }
            });

            tabPanels.forEach(panel => {
                const isTarget = panel.id === `${tabName}Panel` || (tabName === 'secondary' && panel.id === 'secondaryViewPanel');
                panel.classList.toggle('hidden', !isTarget);
            });
            document.getElementById('collegePicker').hidden = tabName !== 'hierarchy';
            document.getElementById('addCollegeBtn').hidden = tabName === 'prospectus';

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
            btn.addEventListener('keydown', (event) => {
                const currentIndex = [...tabButtons].indexOf(btn);
                let nextIndex = currentIndex;
                if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % tabButtons.length;
                else if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + tabButtons.length) % tabButtons.length;
                else if (event.key === 'Home') nextIndex = 0;
                else if (event.key === 'End') nextIndex = tabButtons.length - 1;
                else return;
                event.preventDefault();
                const nextTab = tabButtons[nextIndex];
                activateTab(nextTab.dataset.tab);
                nextTab.focus();
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