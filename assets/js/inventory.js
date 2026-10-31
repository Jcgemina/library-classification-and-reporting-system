(() => {
  const inventoryPageData = JSON.parse(document.getElementById('inventoryPageData').textContent);
  const inventoryBooks = inventoryPageData.books;
  const aiCourseConfigured = inventoryPageData.aiCourseConfigured;
  const addBookButton = document.querySelector('[data-inventory-add-open]');
  const addBookDialog = document.getElementById('inventoryAddBookModal');
  const addBookForm = document.querySelector('[data-inventory-add-form]');
  const addBookCloseButton = document.querySelector('[data-inventory-add-close]');
  const addBookCancelButton = document.querySelector('[data-inventory-add-cancel]');
  const formMessage = document.querySelector('[data-inventory-form-message]');
  const formTitle = document.querySelector('[data-book-form-title]');
  const formSubmitLabel = document.querySelector('[data-book-form-submit] span');
  const archiveOpenButton = document.querySelector('[data-inventory-archive-open]');
  const archiveCloseButton = document.querySelector('[data-inventory-archive-close]');
  const archiveModal = document.getElementById('inventoryArchiveModal');
  const archiveSearchInput = document.querySelector('[data-inventory-archive-search]');
  const archiveBookRows = [...document.querySelectorAll('[data-inventory-archive-row]')];
  const archiveNoMatches = document.querySelector('[data-inventory-archive-no-matches]');
  const bookActionsMenu = document.getElementById('inventoryBookActionsMenu');
  const bookActionsTitle = document.querySelector('[data-book-actions-title]');
  let bookActionsTrigger = null;
  const inventoryListView = document.querySelector('[data-inventory-list-view]');
  const inventoryBookDetail = document.querySelector('[data-inventory-book-detail]');
  let activeBookInfoId = null;
  let inventoryListScrollPosition = 0;
  const aiCourseModal = document.getElementById('inventoryAiCourseModal');
  const aiCourseGenerateButton = document.querySelector('[data-ai-course-generate]');
  const aiCourseStatus = document.querySelector('[data-ai-course-status]');
  const aiCourseError = document.querySelector('[data-ai-course-error]');
  const aiCourseResults = document.querySelector('[data-ai-course-results]');
  const aiCourseEmpty = document.querySelector('[data-ai-course-empty]');
  const aiCourseSetup = document.querySelector('[data-ai-course-setup]');
  const aiCourseDescriptionWarning = document.querySelector('[data-ai-course-description-warning]');
  const addReferenceModal = document.getElementById('inventoryAddReferenceModal');
  const referenceForm = document.querySelector('[data-reference-form]');
  const referenceCloseButton = document.querySelector('[data-reference-close]');
  const referenceCancelButton = document.querySelector('[data-reference-cancel]');
  const referenceMessage = document.querySelector('[data-reference-message]');
  const manageReferencesModal = document.getElementById('inventoryManageReferencesModal');
  const manageReferencesCloseButton = document.querySelector('[data-manage-references-close]');
  const referenceList = document.querySelector('[data-reference-list]');
  const managedBookTitle = document.querySelector('[data-managed-book-title]');
  const referenceEditModal = document.getElementById('inventoryReferenceEditModal');
  const referenceEditForm = document.querySelector('[data-reference-edit-form]');
  const referenceEditCopy = document.querySelector('[data-reference-edit-copy]');
  const referenceEditSelect = referenceEditForm.elements.new_course_id;
  const referenceEditError = document.querySelector('[data-reference-edit-error]');
  const referenceEditSubmit = document.querySelector('[data-reference-edit-submit]');
  const referenceEditCancel = document.querySelector('[data-reference-edit-cancel]');
  const referenceRemoveModal = document.getElementById('inventoryReferenceRemoveModal');
  const referenceRemoveForm = document.querySelector('[data-reference-remove-form]');
  const referenceRemoveCopy = document.querySelector('[data-reference-remove-copy]');
  const referenceRemoveError = document.querySelector('[data-reference-remove-error]');
  const referenceRemoveSubmit = document.querySelector('[data-reference-remove-submit]');
  const referenceRemoveCancel = document.querySelector('[data-reference-remove-cancel]');
  const deleteBookModal = document.getElementById('inventoryDeleteBookModal');
  const deleteBookForm = document.querySelector('[data-delete-book-form]');
  const deleteBookCopy = document.querySelector('[data-delete-book-copy]');
  const deleteBookError = document.querySelector('[data-delete-book-error]');
  const deleteBookSubmit = document.querySelector('[data-delete-book-submit]');
  const deleteBookCancel = document.querySelector('[data-delete-book-cancel]');
  const inventoryPage = document.getElementById('inventoryPage');
  let managedBookId = null;
  let activeAiBookId = null;
  let activeAiRun = null;
  let aiPollTimer = null;

  function closeDialog(dialog) {
    if (dialog?.open) dialog.close();
  }

  function escapeInventoryHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    })[character]);
  }

  function showInventoryBookInfo(book) {
    activeBookInfoId = String(book.book_id);
    inventoryListScrollPosition = window.scrollY;
    const courseReferences = book.course_references.length
      ? `<ul class="mt-3 divide-y divide-slate-200 rounded-lg border border-slate-200">${book.course_references.map((reference) => `
          <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
              <p class="font-semibold text-slate-900">${escapeInventoryHtml(reference.code)} — ${escapeInventoryHtml(reference.name)}</p>
              <p class="mt-1 text-xs leading-5 text-slate-600">${escapeInventoryHtml(reference.college_name || 'College not assigned')} · ${escapeInventoryHtml(reference.program_name || 'Program not assigned')} · ${escapeInventoryHtml(reference.status || 'Status unavailable')}</p>
            </div>
            <a href="app.php?page=course&course_id=${encodeURIComponent(reference.course_id)}" class="inline-flex w-fit shrink-0 items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">Open course<i data-lucide="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i></a>
          </li>
        `).join('')}</ul>`
      : '<p class="mt-3 rounded-lg bg-slate-50 px-4 py-4 text-sm text-slate-600">No courses currently reference this book.</p>';
    inventoryBookDetail.innerHTML = `
      <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <button type="button" data-inventory-book-back class="mb-5 inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2"><i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>Back to books</button>
        <div class="min-w-0"><h3 id="inventoryBookDetailTitle" class="text-2xl font-bold text-slate-900">${escapeInventoryHtml(book.title)}</h3><p class="mt-1 text-sm text-slate-600">by ${escapeInventoryHtml(book.author || 'Author not recorded')}</p></div>
      </section>
      <div class="grid gap-6 xl:grid-cols-2">
      <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="inventoryBookInformationHeading">
          <h4 id="inventoryBookInformationHeading" class="text-sm font-bold text-slate-900">Book information</h4>
          <dl class="mt-3 divide-y divide-slate-200 rounded-lg border border-slate-200">
            <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Title</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeInventoryHtml(book.title)}</dd></div>
            <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Author(s)</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeInventoryHtml(book.author || 'Not recorded')}</dd></div>
            <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">ISBN</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeInventoryHtml(book.isbn || 'Not recorded')}</dd></div>
            <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Publisher</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeInventoryHtml(book.publisher || 'Not recorded')}</dd></div>
            <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Publication year</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeInventoryHtml(book.publication_year || 'Not recorded')}</dd></div>
            <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Edition</dt><dd class="text-right text-sm font-medium text-slate-900">Not recorded in catalog</dd></div>
            <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Copyright year</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeInventoryHtml(book.copyright_year || book.publication_year || 'Not recorded')}</dd></div>
            <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-600">Copies</dt><dd class="text-right text-sm font-medium text-slate-900">${escapeInventoryHtml(book.copy_count)}</dd></div>
            <div class="flex flex-col gap-1 px-4 py-3"><dt class="text-sm text-slate-600">Description</dt><dd class="text-sm leading-5 text-slate-900">${escapeInventoryHtml(book.description || 'Not recorded')}</dd></div>
          </dl>
      </section>
      <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="inventoryBookCoursesHeading">
        <div class="flex items-center justify-between gap-3"><h4 id="inventoryBookCoursesHeading" class="text-sm font-bold text-slate-900">Linked courses</h4><span class="text-xs text-slate-600">${book.course_references.length} course${book.course_references.length === 1 ? '' : 's'}</span></div>
        ${courseReferences}
      </section>
      </div>
      <section class="flex flex-wrap justify-end gap-2 rounded-xl border border-slate-200 bg-white p-4 shadow-sm" aria-label="Book actions">
        <a href="app.php?page=inventory&book_id=${encodeURIComponent(book.book_id)}&inventory_action=edit" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2"><i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i>Edit book</a>
        <a href="app.php?page=inventory&book_id=${encodeURIComponent(book.book_id)}&inventory_action=manage-references" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500">Manage links</a>
      </section>
    `;
    inventoryListView.classList.add('hidden');
    inventoryBookDetail.classList.remove('hidden');
    window.scrollTo(0, 0);
    window.lucide?.createIcons({ nodes: [inventoryBookDetail] });
  }

  function returnToInventoryList() {
    inventoryBookDetail.classList.add('hidden');
    inventoryListView.classList.remove('hidden');
    inventoryBookDetail.replaceChildren();
    const previousBookId = activeBookInfoId;
    activeBookInfoId = null;
    window.scrollTo(0, inventoryListScrollPosition);
    if (previousBookId) {
      document.querySelector(`[data-book-actions-open][data-book-id="${CSS.escape(previousBookId)}"]`)?.focus();
    }
  }

  addBookButton?.addEventListener('click', () => {
    addBookForm.reset();
    addBookForm.elements.action.value = 'create_book';
    addBookForm.elements.book_id.value = '';
    formTitle.textContent = 'Add Book';
    formSubmitLabel.textContent = 'Save Book';
    formMessage.hidden = true;
    addBookDialog.showModal();
  });
  addBookCloseButton?.addEventListener('click', () => closeDialog(addBookDialog));
  addBookCancelButton?.addEventListener('click', () => closeDialog(addBookDialog));
  archiveOpenButton?.addEventListener('click', () => archiveModal.showModal());
  archiveCloseButton?.addEventListener('click', () => closeDialog(archiveModal));
  function filterArchivedBooks() {
    const query = archiveSearchInput.value.trim().toLocaleLowerCase();
    let visibleRows = 0;
    archiveBookRows.forEach((row) => {
      const fullTitle = row.querySelector('td p[title]')?.getAttribute('title') || '';
      const matches = `${fullTitle} ${row.textContent}`.toLocaleLowerCase().includes(query);
      row.hidden = !matches;
      if (matches) visibleRows += 1;
    });
    if (archiveNoMatches) archiveNoMatches.hidden = query === '' || visibleRows > 0;
  }
  archiveSearchInput?.addEventListener('input', filterArchivedBooks);
  archiveModal?.addEventListener('close', () => {
    archiveSearchInput.value = '';
    filterArchivedBooks();
  });
  document.querySelectorAll('[data-ai-course-close]').forEach((button) => {
    button.addEventListener('click', () => closeDialog(aiCourseModal));
  });
  aiCourseModal?.addEventListener('close', () => window.clearTimeout(aiPollTimer));
  aiCourseModal?.addEventListener('click', (event) => {
    if (event.target === aiCourseModal) closeDialog(aiCourseModal);
  });
  referenceCloseButton?.addEventListener('click', () => closeDialog(addReferenceModal));
  referenceCancelButton?.addEventListener('click', () => closeDialog(addReferenceModal));
  manageReferencesCloseButton?.addEventListener('click', () => closeDialog(manageReferencesModal));
  referenceEditCancel?.addEventListener('click', () => closeDialog(referenceEditModal));
  referenceRemoveCancel?.addEventListener('click', () => closeDialog(referenceRemoveModal));
  deleteBookCancel?.addEventListener('click', () => closeDialog(deleteBookModal));
  bookActionsMenu?.addEventListener('toggle', (event) => {
    if (event.newState === 'closed') {
      bookActionsTrigger?.setAttribute('aria-expanded', 'false');
      bookActionsTrigger = null;
    }
  });
  archiveModal?.addEventListener('click', (event) => {
    if (event.target === archiveModal) closeDialog(archiveModal);
  });

  async function postInventoryAction(formData) {
    const response = await fetch('pages/inventory.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData,
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Unable to complete this action.');
    window.location.reload();
  }

  async function submitInventoryForm(event, form, submitButton, errorElement, fallbackMessage = '') {
    event.preventDefault();
    submitButton.disabled = true;
    errorElement.hidden = true;

    try {
      await postInventoryAction(new FormData(form));
    } catch (error) {
      errorElement.textContent = error.message || fallbackMessage;
      errorElement.hidden = false;
      submitButton.disabled = false;
    }
  }

  function showInventoryToast(message) {
    window.AppToast.show(message, 'info');
  }

  function formatCourseReferences(references) {
    return references.length
      ? references.map((reference) => `${reference.code} - ${reference.name}`).join(', ')
      : 'No courses linked yet';
  }

  function resetAiCoursePanel(book) {
    window.clearTimeout(aiPollTimer);
    activeAiBookId = Number(book.book_id);
    activeAiRun = null;
    document.querySelector('[data-ai-course-book-title]').textContent = book.title;
    document.querySelector('[data-ai-course-book-author]').textContent = `by ${book.author}`;
    document.querySelector('[data-ai-course-book-description]').textContent = book.description || 'No description provided.';
    const missingDescription = !book.description?.trim();
    aiCourseDescriptionWarning.hidden = !missingDescription;
    aiCourseDescriptionWarning.classList.toggle('flex', missingDescription);
    document.querySelector('[data-ai-course-references]').textContent = formatCourseReferences(book.course_references);
    aiCourseStatus.hidden = true;
    aiCourseError.hidden = true;
    aiCourseResults.hidden = true;
    aiCourseResults.replaceChildren();
    aiCourseEmpty.hidden = true;
    aiCourseSetup.hidden = aiCourseConfigured;
    aiCourseGenerateButton.disabled = !aiCourseConfigured;
    aiCourseGenerateButton.querySelector('span').textContent = 'Review suggested courses';
    aiCourseModal.showModal();
  }

  async function requestAiCourseJson(action, method = 'GET', values = {}) {
    const options = {
      method,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    };
    let url = `pages/ai_course.php?action=${encodeURIComponent(action)}`;
    if (method === 'POST') {
      values.csrf_token = addBookForm.elements.csrf_token.value;
      options.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      options.body = new URLSearchParams(values);
    } else {
      url += `&${new URLSearchParams(values)}`;
    }

    const response = await fetch(url, options);
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error('The server returned an unexpected response. Reload Inventory and try again.');
    }
    if (!response.ok || !result.success) {
      throw new Error(result.message || 'Unable to complete the AI course action.');
    }
    return result;
  }

  function showAiCourseError(message) {
    aiCourseStatus.hidden = true;
    aiCourseError.textContent = message;
    aiCourseError.hidden = false;
    aiCourseGenerateButton.disabled = !aiCourseConfigured;
    aiCourseGenerateButton.querySelector('span').textContent = 'Review again';
  }

  function renderAiCourseRun(run) {
    activeAiRun = run;
    aiCourseStatus.hidden = true;
    aiCourseError.hidden = true;
    aiCourseResults.replaceChildren();
    const suggestions = Array.isArray(run.suggestions) ? run.suggestions : [];
    aiCourseResults.hidden = suggestions.length === 0;
    aiCourseEmpty.hidden = suggestions.length > 0;

    suggestions.forEach((suggestion) => {
      const row = document.createElement('article');
      row.dataset.aiSuggestionRow = '';
      row.className = 'flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-3';

      const copy = document.createElement('div');
      copy.className = 'min-w-0 flex-1';
      const title = document.createElement('p');
      title.className = 'text-sm font-semibold text-slate-900';
      title.textContent = `${suggestion.code} - ${suggestion.name}`;
      const score = document.createElement('p');
      score.className = 'mt-1 text-xs text-slate-600';
      score.textContent = `Similarity score ${Number(suggestion.score).toFixed(3)}`;
      const reason = document.createElement('p');
      reason.id = `aiSuggestionReason-${run.id}-${suggestion.course_id}`;
      reason.hidden = true;
      reason.className = 'mt-2 text-sm leading-5 text-slate-700';
      reason.textContent = suggestion.reason || 'The relevance check found a direct subject overlap.';
      copy.append(title, score, reason);

      const actions = document.createElement('div');
      actions.className = 'flex items-center gap-1';
      if (suggestion.decision === 'pending') {
        const whyButton = document.createElement('button');
        whyButton.type = 'button';
        whyButton.dataset.aiSuggestionWhy = '';
        whyButton.dataset.reasonId = reason.id;
        whyButton.setAttribute('aria-controls', reason.id);
        whyButton.setAttribute('aria-expanded', 'false');
        whyButton.className = 'inline-flex h-9 items-center gap-1 rounded-md px-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400';
        whyButton.innerHTML = '<i data-lucide="circle-help" class="h-4 w-4"></i><span>Why?</span>';
        actions.append(whyButton);

        [['approve', 'check', 'Approve reference', 'text-emerald-800 hover:bg-emerald-50'], ['dismiss', 'x', 'Decline suggestion', 'text-slate-600 hover:bg-slate-100']].forEach(([action, icon, label, classes]) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.dataset.aiSuggestionAction = action;
          button.dataset.runId = run.id;
          button.dataset.courseId = suggestion.course_id;
          button.setAttribute('aria-label', label);
          button.title = label;
          button.className = `flex h-9 w-9 items-center justify-center rounded-md ${classes} focus:outline-none focus:ring-2 focus:ring-slate-400 disabled:opacity-50`;
          button.innerHTML = `<i data-lucide="${icon}" class="h-4 w-4"></i>`;
          actions.append(button);
        });
      } else {
        const reviewed = document.createElement('span');
        reviewed.className = `text-xs font-semibold ${suggestion.decision === 'approved' ? 'text-emerald-800' : 'text-slate-500'}`;
        reviewed.textContent = suggestion.decision === 'approved' ? 'Approved' : 'Declined';
        actions.append(reviewed);
      }
      row.append(copy, actions);
      aiCourseResults.append(row);
    });
    window.lucide?.createIcons({ nodes: [aiCourseResults] });
    aiCourseGenerateButton.disabled = !aiCourseConfigured;
    aiCourseGenerateButton.querySelector('span').textContent = 'Review again';
  }

  function updateBookCourseReferences(book) {
    const references = book.course_references;
    document.querySelector('[data-ai-course-references]').textContent = formatCourseReferences(references);

    const actionsButton = [...document.querySelectorAll('[data-book-actions-open]')]
      .find((button) => button.dataset.bookId === String(book.book_id));
    const referenceCell = actionsButton?.closest('tr')?.cells[2];
    if (!referenceCell) return;
    referenceCell.replaceChildren();
    if (!references.length) {
      const empty = document.createElement('span');
      empty.className = 'text-xs text-slate-500';
      empty.textContent = 'No courses linked yet';
      referenceCell.append(empty);
      return;
    }

    const referenceList = document.createElement('div');
    referenceList.className = 'flex flex-wrap items-center gap-1';
    references.forEach((reference) => {
      const item = document.createElement('span');
      item.className = 'inline-flex max-w-full items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-800';
      const code = document.createElement('span');
      code.className = 'flex-shrink-0 font-semibold';
      code.textContent = reference.code;
      const name = document.createElement('span');
      name.className = 'min-w-0';
      name.textContent = reference.name;
      item.append(code, name);
      referenceList.append(item);
    });
    referenceCell.append(referenceList);
  }

  async function pollAiCourseJob(jobId, attempt = 0) {
    if (!aiCourseModal.isConnected || !aiCourseModal.open || activeAiBookId === null) return;
    if (attempt >= 120) {
      aiCourseError.hidden = true;
      aiCourseStatus.textContent = 'This request is still waiting for the worker. You can close this panel and check again later; the queued job will continue.';
      aiCourseStatus.hidden = false;
      aiCourseGenerateButton.disabled = !aiCourseConfigured;
      aiCourseGenerateButton.querySelector('span').textContent = 'Check status';
      return;
    }
    try {
      const result = await requestAiCourseJson('job_status', 'GET', { job_id: jobId });
      if (result.state === 'complete') {
        renderAiCourseRun(result.run);
        return;
      }
      if (result.state === 'stale') {
        showAiCourseError(result.message || 'Catalog information changed. Generate fresh suggestions.');
        return;
      }
      if (result.state === 'failed' || result.state === 'cancelled') {
        showAiCourseError(result.message || 'Suggestions could not be generated.');
        return;
      }
      aiCourseStatus.textContent = result.state === 'processing'
        ? 'Checking course relevance…'
        : result.state === 'retrying'
          ? result.message
          : 'Waiting for the AI worker…';
      aiCourseStatus.hidden = false;
      aiPollTimer = window.setTimeout(() => pollAiCourseJob(jobId, attempt + 1), Math.min(4000, 1200 + attempt * 150));
    } catch (error) {
      showAiCourseError(error.message);
    }
  }

  aiCourseGenerateButton?.addEventListener('click', async () => {
    if (activeAiBookId === null || !aiCourseConfigured) return;
    aiCourseGenerateButton.disabled = true;
    aiCourseGenerateButton.querySelector('span').textContent = 'Queueing…';
    aiCourseError.hidden = true;
    aiCourseEmpty.hidden = true;
    aiCourseStatus.textContent = 'Preparing suggested courses…';
    aiCourseStatus.hidden = false;
    try {
      const result = await requestAiCourseJson('request_suggestions', 'POST', { book_id: String(activeAiBookId) });
      if (result.state === 'complete') {
        renderAiCourseRun(result.run);
        return;
      }
      aiCourseGenerateButton.querySelector('span').textContent = 'Reviewing…';
      pollAiCourseJob(result.job_id);
    } catch (error) {
      showAiCourseError(error.message);
    }
  });

  aiCourseResults?.addEventListener('click', async (event) => {
    const whyButton = event.target.closest('[data-ai-suggestion-why]');
    if (whyButton) {
      const reason = document.getElementById(whyButton.dataset.reasonId);
      if (!reason) return;
      reason.hidden = !reason.hidden;
      whyButton.setAttribute('aria-expanded', String(!reason.hidden));
      whyButton.querySelector('span').textContent = reason.hidden ? 'Why?' : 'Hide reason';
      return;
    }

    const button = event.target.closest('[data-ai-suggestion-action]');
    if (!button) return;
    button.disabled = true;
    try {
      const result = await requestAiCourseJson(button.dataset.aiSuggestionAction, 'POST', {
        run_id: button.dataset.runId,
        course_id: button.dataset.courseId,
      });
      const suggestion = activeAiRun?.suggestions.find((item) => item.course_id === Number(button.dataset.courseId));
      if (suggestion) {
        suggestion.decision = button.dataset.aiSuggestionAction === 'approve' ? 'approved' : 'dismissed';
      }
      if (button.dataset.aiSuggestionAction === 'approve') {
        const book = inventoryBooks[activeAiBookId];
        if (book && suggestion && !book.course_references.some((reference) => reference.course_id === suggestion.course_id)) {
          book.course_references.push({
            course_id: suggestion.course_id,
            code: suggestion.code,
            name: suggestion.name,
          });
        }
        if (book) updateBookCourseReferences(book);
        renderAiCourseRun(activeAiRun);
        aiCourseStatus.textContent = result.message;
        aiCourseStatus.hidden = false;
        return;
      }
      renderAiCourseRun(activeAiRun);
      aiCourseStatus.textContent = result.message;
      aiCourseStatus.hidden = false;
    } catch (error) {
      button.disabled = false;
      showAiCourseError(error.message);
    }
  });

  function openReferenceEdit(book, reference) {
    referenceEditForm.reset();
    referenceEditForm.elements.book_id.value = book.book_id;
    referenceEditForm.elements.course_id.value = reference.course_id;
    referenceEditError.hidden = true;
    referenceEditCopy.textContent = `Replace the course linked to "${book.title}" from ${reference.code} - ${reference.name}.`;
    [...referenceEditSelect.options].forEach((option) => {
      option.disabled = option.value === String(reference.course_id);
    });
    referenceEditModal.showModal();
  }

  function openReferenceRemoval(book, reference) {
    referenceRemoveForm.reset();
    referenceRemoveForm.elements.book_id.value = book.book_id;
    referenceRemoveForm.elements.course_id.value = reference.course_id;
    referenceRemoveError.hidden = true;
    referenceRemoveCopy.textContent = `Remove ${reference.code} - ${reference.name} from "${book.title}"? The book and its other references will remain.`;
    referenceRemoveModal.showModal();
  }

  function openManageReferences(book) {
    if (!book.course_references.length) {
      showInventoryToast('No course references yet. Add a reference before editing or removing one.');
      return;
    }

    managedBookId = book.book_id;
    managedBookTitle.textContent = book.title;
    referenceList.replaceChildren();
    book.course_references.forEach((reference) => {
      const row = document.createElement('div');
      row.className = 'flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-3';
      const title = document.createElement('p');
      title.className = 'min-w-0 flex-1 text-sm font-medium text-slate-800';
      title.textContent = `${reference.code} - ${reference.name}`;
      const actions = document.createElement('div');
      actions.className = 'flex items-center gap-1';

      [['edit', 'pencil', 'Edit reference', 'text-sky-700 hover:bg-sky-50'], ['remove', 'unlink', 'Remove reference', 'text-rose-700 hover:bg-rose-50']].forEach(([action, icon, label, classes]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.referenceManagerAction = action;
        button.dataset.courseId = reference.course_id;
        button.setAttribute('aria-label', label);
        button.title = label;
        button.className = `flex h-8 w-8 items-center justify-center rounded-md ${classes} focus:outline-none focus:ring-2 focus:ring-slate-400`;
        button.innerHTML = `<i data-lucide="${icon}" class="h-4 w-4"></i>`;
        actions.append(button);
      });

      row.append(title, actions);
      referenceList.append(row);
    });
    window.lucide?.createIcons({ nodes: [referenceList] });
    manageReferencesModal.showModal();
  }

  inventoryPage?.addEventListener('click', async (event) => {
    const inventoryBackButton = event.target.closest('[data-inventory-book-back]');
    if (inventoryBackButton) {
      returnToInventoryList();
      return;
    }

    const actionsOpenButton = event.target.closest('[data-book-actions-open]');
    if (actionsOpenButton) {
      const book = inventoryBooks[actionsOpenButton.dataset.bookId];
      if (!book) return;
      if (bookActionsMenu.matches(':popover-open')) bookActionsMenu.hidePopover();
      bookActionsTrigger = actionsOpenButton;
      bookActionsTrigger.setAttribute('aria-expanded', 'true');
      bookActionsTitle.textContent = book.title;
      bookActionsMenu.querySelectorAll('[data-book-action]').forEach((button) => {
        button.dataset.bookId = book.book_id;
      });
      bookActionsMenu.showPopover();
      const triggerRect = actionsOpenButton.getBoundingClientRect();
      const menuRect = bookActionsMenu.getBoundingClientRect();
      const edge = 8;
      const gap = 6;
      const belowTop = triggerRect.bottom + gap;
      const top = belowTop + menuRect.height <= window.innerHeight - edge
        ? belowTop
        : Math.max(edge, triggerRect.top - menuRect.height - gap);
      const left = Math.max(edge, Math.min(triggerRect.right - menuRect.width, window.innerWidth - menuRect.width - edge));
      bookActionsMenu.style.top = `${top}px`;
      bookActionsMenu.style.left = `${left}px`;
      return;
    }

    const actionButton = event.target.closest('[data-book-action]');
    if (!actionButton) return;
    if (bookActionsMenu.matches(':popover-open')) bookActionsMenu.hidePopover();

    if (actionButton.dataset.bookAction === 'view') {
      const book = inventoryBooks[actionButton.dataset.bookId];
      if (book) showInventoryBookInfo(book);
      return;
    }

    if (actionButton.dataset.bookAction === 'restore') {
      const formData = new FormData();
      formData.set('action', 'restore_book');
      formData.set('book_id', actionButton.dataset.bookId);
      formData.set('csrf_token', addBookForm.elements.csrf_token.value);
      try {
        await postInventoryAction(formData);
      } catch (error) {
        window.alert(error.message);
      }
      return;
    }

    const book = inventoryBooks[actionButton.dataset.bookId];
    if (!book) return;

    if (actionButton.dataset.bookAction === 'ai-course') {
      resetAiCoursePanel(book);
      return;
    }

    if (actionButton.dataset.bookAction === 'edit') {
      addBookForm.reset();
      addBookForm.elements.action.value = 'update_book';
      addBookForm.elements.book_id.value = book.book_id;
      addBookForm.elements.title.value = book.title;
      addBookForm.elements.author.value = book.author;
      addBookForm.elements.isbn.value = book.isbn || '';
      addBookForm.elements.publisher.value = book.publisher || '';
      addBookForm.elements.publication_year.value = book.publication_year || '';
      addBookForm.elements.copyright_year.value = book.copyright_year || '';
      addBookForm.elements.copy_count.value = book.copy_count;
      addBookForm.elements.description.value = book.description || '';
      formTitle.textContent = 'Edit Book';
      formSubmitLabel.textContent = 'Save Changes';
      formMessage.hidden = true;
      addBookDialog.showModal();
      return;
    }

    if (actionButton.dataset.bookAction === 'reference') {
      referenceForm.reset();
      referenceForm.elements.book_id.value = book.book_id;
      referenceMessage.hidden = true;
      addReferenceModal.showModal();
      return;
    }

    if (actionButton.dataset.bookAction === 'manage-references') {
      openManageReferences(book);
      return;
    }

    if (actionButton.dataset.bookAction === 'delete') {
      deleteBookForm.reset();
      deleteBookForm.elements.book_id.value = book.book_id;
      deleteBookCopy.textContent = `Move "${book.title}" to the archive? Its course references will also be removed.`;
      deleteBookError.hidden = true;
      deleteBookModal.showModal();
    }
  });

  referenceList?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-reference-manager-action]');
    if (!button) return;
    const book = inventoryBooks[managedBookId];
    const reference = book?.course_references.find((item) => item.course_id === Number(button.dataset.courseId));
    if (!book || !reference) return;
    if (button.dataset.referenceManagerAction === 'edit') {
      openReferenceEdit(book, reference);
    } else {
      openReferenceRemoval(book, reference);
    }
  });

  referenceEditForm?.addEventListener('submit', async (event) => {
    await submitInventoryForm(event, referenceEditForm, referenceEditSubmit, referenceEditError);
  });

  referenceRemoveForm?.addEventListener('submit', async (event) => {
    await submitInventoryForm(event, referenceRemoveForm, referenceRemoveSubmit, referenceRemoveError);
  });

  addBookForm?.addEventListener('submit', async (event) => {
    const submitButton = addBookForm.querySelector('button[type="submit"]');
    await submitInventoryForm(event, addBookForm, submitButton, formMessage, 'Unable to save this book. Please try again.');
  });

  referenceForm?.addEventListener('submit', async (event) => {
    const submitButton = referenceForm.querySelector('button[type="submit"]');
    await submitInventoryForm(event, referenceForm, submitButton, referenceMessage);
  });

  deleteBookForm?.addEventListener('submit', async (event) => {
    await submitInventoryForm(event, deleteBookForm, deleteBookSubmit, deleteBookError);
  });

  const inventoryQuery = new URLSearchParams(window.location.search);
  const requestedBookId = inventoryQuery.get('book_id');
  const requestedInventoryAction = inventoryQuery.get('inventory_action');
  const validInventoryActions = ['view', 'edit', 'reference', 'manage-references'];
  if (requestedBookId && inventoryBooks[requestedBookId] && validInventoryActions.includes(requestedInventoryAction)) {
    const bookActionTrigger = [...document.querySelectorAll('[data-book-actions-open]')]
      .find((button) => button.dataset.bookId === requestedBookId);
    if (bookActionTrigger) {
      bookActionTrigger.click();
      bookActionsMenu.querySelector(`[data-book-action="${requestedInventoryAction}"]`)?.click();
    }
  }
})();
