(() => {
  const state = {
    allLibrarians: [],
    selectedIds: new Set(),
    editingId: null,
    searchTimer: null,
    pendingDeleteIds: [],
    pendingSavePayload: null,
    pendingStatusId: null,
    currentPage: 1,
    pageSize: 5,
    isDeleting: false,
    lastFocusedElement: null,
    activeDrawerUserId: null,
  };

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (match) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[match]));
  }

  function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    const isError = type === 'error';
    const duration = 3000;

    toast.className = `pointer-events-auto relative flex items-start gap-3 overflow-hidden rounded-xl border px-4 py-3 pb-4 text-sm shadow-lg ${isError ? 'border-red-300 bg-red-50 text-red-800' : 'border-green-300 bg-green-50 text-green-800'}`;
    toast.innerHTML = `
      <i data-lucide="${isError ? 'circle-alert' : 'circle-check'}" class="mt-0.5 h-4 w-4 flex-shrink-0"></i>
      <span class="flex-1">${esc(message)}</span>
      <button type="button" class="text-current opacity-60 transition hover:opacity-100" aria-label="Dismiss notification">
        <i data-lucide="x" class="h-4 w-4"></i>
      </button>
      <span class="absolute bottom-0 left-0 h-1 w-full origin-left ${isError ? 'bg-red-500' : 'bg-green-500'}" data-toast-progress></span>
    `;

    container.appendChild(toast);
    lucide.createIcons();

    const progress = toast.querySelector('[data-toast-progress]');
    requestAnimationFrame(() => {
      progress.style.transition = `width ${duration}ms linear`;
      progress.style.width = '0%';
    });

    const dismiss = () => toast.remove();
    toast.querySelector('button').addEventListener('click', dismiss);
    setTimeout(dismiss, duration);
  }

  function updateStats() {
    const total = state.allLibrarians.length;
    const active = state.allLibrarians.filter((user) => user.status === 'active').length;
    const admins = state.allLibrarians.filter((user) => user.role === 'admin').length;

    const totalEl = document.querySelector('[data-stat="total"]');
    const activeEl = document.querySelector('[data-stat="active"]');
    const adminEl = document.querySelector('[data-stat="admins"]');

    if (totalEl) totalEl.textContent = total;
    if (activeEl) activeEl.textContent = active;
    if (adminEl) adminEl.textContent = admins;
  }

  function setBulkDeleteState() {
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkDeleteBtnLabel = document.getElementById('bulkDeleteBtnLabel');
    const bulkActionBar = document.getElementById('bulkActionBar');
    const selectedCountLabel = document.getElementById('selectedCountLabel');
    const hasSelection = state.selectedIds.size > 0;

    if (bulkActionBar) {
      bulkActionBar.classList.toggle('hidden', !hasSelection);
      bulkActionBar.classList.toggle('flex', hasSelection);
    }

    if (selectedCountLabel) {
      selectedCountLabel.textContent = `${state.selectedIds.size} selected`;
    }

    if (bulkDeleteBtn) {
      bulkDeleteBtn.disabled = !hasSelection;
      bulkDeleteBtn.classList.toggle('opacity-60', !hasSelection);
      bulkDeleteBtn.classList.toggle('cursor-not-allowed', !hasSelection);
    }

    if (bulkDeleteBtnLabel) {
      bulkDeleteBtnLabel.textContent = hasSelection ? `Delete selected (${state.selectedIds.size})` : 'Delete selected';
    }

    document.querySelectorAll('[data-action="delete"][data-id]').forEach((button) => {
      const isSelected = state.selectedIds.has(Number(button.dataset.id));
      button.disabled = isSelected;
      button.classList.toggle('opacity-60', isSelected);
      button.classList.toggle('cursor-not-allowed', isSelected);
      button.classList.toggle('pointer-events-none', isSelected);
    });
  }

  function clearSelection() {
    state.selectedIds.clear();
    document.querySelectorAll('[data-select-id]').forEach((checkbox) => {
      checkbox.checked = false;
    });
    setBulkDeleteState();
  }

  function capitalizeFullName(fullName) {
    return String(fullName || '').toLowerCase().replace(/\b\w/g, (character) => character.toUpperCase());
  }

  function generateUsername(fullName) {
    if (!fullName) return '';
    const parts = fullName.trim().split(/\s+/);
    if (parts.length === 0) return '';

    const initials = parts.slice(0, -1).map((part) => part[0].toLowerCase()).join('');
    const lastName = parts[parts.length - 1].toLowerCase();
    return initials + lastName;
  }

  function getPasswordError(password) {
    if (password.length < 8) return 'Password must be at least 8 characters.';
    if (password.length > 128) return 'Password must not exceed 128 characters.';
    if (!/[A-Za-z]/.test(password)) return 'Password must contain at least one letter.';
    if (!/[0-9]/.test(password)) return 'Password must contain at least one number.';
    if (!/[^A-Za-z0-9]/.test(password)) return 'Password must contain at least one special character.';
    return null;
  }

  function renderPagination(totalPages) {
    const pagination = document.getElementById('librarianPagination');
    if (!pagination || totalPages <= 1) {
      if (pagination) pagination.innerHTML = '';
      return;
    }

    const pageButtons = Array.from({ length: totalPages }, (_, index) => {
      const page = index + 1;
      const isCurrent = page === state.currentPage;
      return `
        <button type="button" data-page-number="${page}" aria-label="Go to page ${page}" aria-current="${isCurrent ? 'page' : 'false'}"
          class="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold transition ${isCurrent ? 'bg-[#4B5694] text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-100'}">
          ${page}
        </button>
      `;
    }).join('');

    pagination.innerHTML = `
      <button type="button" data-page-number="${state.currentPage - 1}" aria-label="Previous page" ${state.currentPage === 1 ? 'disabled' : ''}
        class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">
        <i data-lucide="chevron-left" class="h-4 w-4"></i>
      </button>
      ${pageButtons}
      <button type="button" data-page-number="${state.currentPage + 1}" aria-label="Next page" ${state.currentPage === totalPages ? 'disabled' : ''}
        class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">
        <i data-lucide="chevron-right" class="h-4 w-4"></i>
      </button>
    `;

    lucide.createIcons();
    pagination.querySelectorAll('[data-page-number]').forEach((button) => {
      button.addEventListener('click', () => {
        if (button.disabled) return;
        state.currentPage = Number(button.dataset.pageNumber);
        renderLibrarians();
      });
    });
  }

  function bindRowActions() {
    document.querySelectorAll('[data-action="view"]').forEach((button) => {
      button.addEventListener('click', () => {
        const librarian = state.allLibrarians.find((item) => item.id === Number(button.dataset.id));
        if (librarian) openUserDetail(librarian);
      });
    });

    document.querySelectorAll('[data-action="edit"]').forEach((button) => {
      button.addEventListener('click', () => {
        const librarian = state.allLibrarians.find((item) => item.id === Number(button.dataset.id));
        if (librarian) openModal('edit', librarian);
      });
    });

    document.querySelectorAll('[data-action="delete"]').forEach((button) => {
      button.addEventListener('click', () => {
        const id = Number(button.dataset.id);
        removeLibrarian(id);
      });
    });

    document.querySelectorAll('[data-action="toggle-status"]').forEach((button) => {
      button.addEventListener('click', () => {
        const librarian = state.allLibrarians.find((item) => item.id === Number(button.dataset.id));
        if (librarian) openStatusConfirmation(librarian);
      });
    });

    document.querySelectorAll('[data-select-id]').forEach((check) => {
      check.addEventListener('change', (event) => {
        const id = Number(event.target.dataset.selectId);
        if (event.target.checked) {
          state.selectedIds.add(id);
        } else {
          state.selectedIds.delete(id);
        }

        setBulkDeleteState();
      });
    });
  }

  function renderLibrarians() {
    const list = document.getElementById('librarianList');
    const pagination = document.getElementById('librarianPagination');
    const searchValue = document.getElementById('librarianSearch')?.value.trim().toLowerCase() || '';
    const statusValue = document.getElementById('librarianStatusFilter')?.value || 'all';
    const roleValue = document.getElementById('librarianRoleFilter')?.value || 'all';
    const resultSummary = document.getElementById('librarianResultSummary');

    const visibleLibrarians = state.allLibrarians.filter((librarian) => {
      const matchesStatus = statusValue === 'all' || librarian.status === statusValue;
      const matchesRole = roleValue === 'all' || librarian.role === roleValue;
      if (!matchesStatus || !matchesRole) return false;
      if (!searchValue) return true;

      return (
        librarian.fullName.toLowerCase().includes(searchValue) ||
        librarian.username.toLowerCase().includes(searchValue) ||
        librarian.role.toLowerCase().includes(searchValue)
      );
    });

    if (resultSummary) {
      resultSummary.textContent = `Showing ${visibleLibrarians.length} of ${state.allLibrarians.length} account${state.allLibrarians.length === 1 ? '' : 's'}`;
    }

    if (!visibleLibrarians.length) {
      list.innerHTML = `
        <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center">
          <p class="text-base font-semibold text-slate-700">No accounts found</p>
          <p class="mt-1 text-sm text-slate-500">Try a different search or status filter.</p>
        </div>
      `;
      pagination.innerHTML = '';
      updateStats();
      return;
    }

    const totalPages = Math.ceil(visibleLibrarians.length / state.pageSize);
    state.currentPage = Math.min(state.currentPage, totalPages);
    const pageStart = (state.currentPage - 1) * state.pageSize;
    const paginatedLibrarians = visibleLibrarians.slice(pageStart, pageStart + state.pageSize);

    list.innerHTML = paginatedLibrarians.map((librarian) => {
      const isSelected = state.selectedIds.has(librarian.id);
      const isAdmin = librarian.role === 'admin';
      const isActive = librarian.status === 'active';
      const initials = String(librarian.fullName || '').split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase();

      return `
        <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 shadow-sm md:flex-row md:items-center md:justify-between">
          <div class="flex items-center gap-3">
            <input type="checkbox" data-select-id="${librarian.id}" class="h-4 w-4 rounded border-slate-300 text-rose-500 focus:ring-rose-200" ${isSelected ? 'checked' : ''} />

            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-rose-100 font-bold text-rose-700">
              ${esc(initials || 'L')}
            </div>

            <div class="min-w-0">
              <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-400">Account</p>
              <div class="flex items-center gap-2">
                <p class="font-semibold text-slate-900">${esc(librarian.fullName)}</p>
                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold ${isAdmin ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}">
                  ${esc(librarian.role)}
                </span>
              </div>
              <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-500">
                <span><span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Username</span>@${esc(librarian.username)}</span>
                <span><span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Joined</span>${esc(librarian.joinedAt || 'Recently')}</span>
              </div>
            </div>
          </div>

          <div class="w-full md:w-56 md:flex-shrink-0">
            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-400">Primary actions</p>
            <div class="mt-1.5 grid grid-cols-2 gap-2">
              <button type="button" data-action="view" data-id="${librarian.id}" aria-label="View ${esc(librarian.fullName)}" class="inline-flex h-9 w-full items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 text-xs font-semibold text-slate-700 transition hover:border-sky-200 hover:text-sky-700">
                <i data-lucide="eye" class="h-3.5 w-3.5"></i>
                View
              </button>

              <button type="button" data-action="edit" data-id="${librarian.id}" aria-label="Edit ${esc(librarian.fullName)}" class="inline-flex h-9 w-full items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 text-xs font-semibold text-slate-700 transition hover:border-rose-200 hover:text-rose-700">
                <i data-lucide="pencil-line" class="h-3.5 w-3.5"></i>
                Edit
              </button>

              <button type="button" data-action="toggle-status" data-id="${librarian.id}" aria-label="${isActive ? 'Deactivate' : 'Activate'} ${esc(librarian.fullName)}" class="inline-flex h-9 w-full items-center justify-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-2 text-xs font-semibold text-sky-700 transition hover:bg-sky-100">
                <i data-lucide="${isActive ? 'user-round-x' : 'user-round-check'}" class="h-3.5 w-3.5"></i>
                ${isActive ? 'Disable' : 'Enable'}
              </button>

              <button type="button" data-action="delete" data-id="${librarian.id}" aria-label="Delete ${esc(librarian.fullName)}" class="inline-flex h-9 w-full items-center justify-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-2 text-xs font-semibold text-red-600 transition hover:bg-red-100 ${isSelected ? 'opacity-60 cursor-not-allowed' : ''}" ${isSelected ? 'disabled' : ''}>
                <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                Delete
              </button>
            </div>
          </div>
        </div>
      `;
    }).join('');

    lucide.createIcons();
    bindRowActions();
    renderPagination(totalPages);
    updateStats();
  }

  function openUserDetail(librarian) {
    state.lastFocusedElement = document.activeElement;
    state.activeDrawerUserId = librarian.id;

    const initials = String(librarian.fullName || '').split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase();
    document.getElementById('detailInitials').textContent = initials || 'L';
    document.getElementById('detailFullName').textContent = librarian.fullName;
    document.getElementById('detailUsername').textContent = `@${librarian.username}`;
    document.getElementById('detailEmail').textContent = librarian.email || 'No email recorded';
    document.getElementById('detailRole').textContent = librarian.role;
    document.getElementById('detailStatus').textContent = librarian.status;
    document.getElementById('detailJoined').textContent = librarian.joinedAt || 'Recently';

    const drawer = document.getElementById('userDetailDrawer');
    const drawerStatusAction = document.getElementById('drawerStatusAction');
    const isActive = librarian.status === 'active';
    drawerStatusAction.textContent = isActive ? 'Disable' : 'Enable';
    drawerStatusAction.className = `rounded-xl border px-3 py-2 text-sm font-semibold transition ${isActive ? 'border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-100' : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'}`;

    drawer.classList.remove('hidden');
    drawer.setAttribute('aria-hidden', 'false');
    drawer.querySelector('aside').focus();
  }

  function closeUserDetail() {
    const drawer = document.getElementById('userDetailDrawer');
    drawer.classList.add('hidden');
    drawer.setAttribute('aria-hidden', 'true');
    state.activeDrawerUserId = null;
    if (state.lastFocusedElement && typeof state.lastFocusedElement.focus === 'function') {
      state.lastFocusedElement.focus();
    }
  }

  function openModal(mode, librarian = null) {
    const modal = document.getElementById('librarianModal');
    const form = document.getElementById('librarianForm');
    const title = document.getElementById('modalTitle');

    state.lastFocusedElement = document.activeElement;
    state.editingId = librarian ? librarian.id : null;
    title.textContent = mode === 'edit' ? 'Edit Librarian' : 'Add Librarian';

    form.reset();
    document.getElementById('librarianId').value = librarian ? librarian.id : '';
    document.getElementById('fullName').value = librarian ? capitalizeFullName(librarian.fullName) : '';
    document.getElementById('email').value = librarian ? librarian.email : '';
    document.getElementById('role').value = librarian ? librarian.role : 'librarian';

    const generatedUsername = librarian ? librarian.username : generateUsername(document.getElementById('fullName').value);
    document.getElementById('username').value = generatedUsername;
    document.getElementById('password').value = '';

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => document.getElementById('fullName').focus(), 50);
  }

  function closeModal() {
    const modal = document.getElementById('librarianModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('librarianForm').reset();
    state.editingId = null;
    if (state.lastFocusedElement && typeof state.lastFocusedElement.focus === 'function') {
      state.lastFocusedElement.focus();
    }
  }

  function fetchLibrarians(search = '') {
    const params = new URLSearchParams({ action: 'list' });

    return fetch('pages/user.php?' + params.toString(), {
      method: 'GET',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then((response) => response.json())
      .then((result) => {
        if (!result.success) {
          throw new Error(result.message || 'Unable to load librarians.');
        }

        state.allLibrarians = result.librarians || [];
        state.currentPage = 1;
        updateStats();
        renderLibrarians();
        setBulkDeleteState();
      })
      .catch((error) => {
        const list = document.getElementById('librarianList');
        list.innerHTML = `
          <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            ${esc(error.message || 'Unable to load librarians.')}
          </div>
        `;
      });
  }

  function removeLibrarian(id) {
    const librarian = state.allLibrarians.find((item) => item.id === id);
    openDeleteConfirmation([id], `Delete ${librarian ? librarian.fullName : 'this librarian'}?`);
  }

  function handleBulkDelete() {
    if (!state.selectedIds.size) return;
    openDeleteConfirmation([...state.selectedIds], `Delete ${state.selectedIds.size} selected librarian(s)?`);
  }

  function closeDeleteConfirmation() {
    const modal = document.getElementById('deleteConfirmModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('adminDeletePassword').value = '';
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    if (confirmDeleteBtn) {
      confirmDeleteBtn.disabled = false;
      confirmDeleteBtn.innerHTML = `
        <i data-lucide="trash-2" class="h-4 w-4"></i>
        Delete
      `;
      lucide.createIcons();
    }
    state.pendingDeleteIds = [];
    state.isDeleting = false;
    if (state.lastFocusedElement && typeof state.lastFocusedElement.focus === 'function') {
      state.lastFocusedElement.focus();
    }
  }

  function openDeleteConfirmation(ids, message) {
    state.lastFocusedElement = document.activeElement;
    state.pendingDeleteIds = ids;
    const selectedNames = ids
      .map((id) => state.allLibrarians.find((user) => user.id === id)?.fullName)
      .filter(Boolean)
      .slice(0, 3)
      .join(', ');
    const nameSummary = selectedNames ? ` ${selectedNames}${ids.length > 3 ? ' and more' : ''}` : '';
    document.getElementById('deleteConfirmMessage').textContent = `${message}${nameSummary}. This removes their access immediately.`;
    document.getElementById('adminDeletePassword').value = '';
    const modal = document.getElementById('deleteConfirmModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('adminDeletePassword').focus();
  }

  function confirmPendingDelete() {
    const selected = [...state.pendingDeleteIds];
    if (!selected.length || state.isDeleting) return;

    const adminPassword = document.getElementById('adminDeletePassword').value;
    if (!adminPassword) {
      showToast('Enter the administrator password to continue.', 'error');
      document.getElementById('adminDeletePassword').focus();
      return;
    }

    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    confirmDeleteBtn.disabled = true;
    confirmDeleteBtn.innerHTML = `
      <i data-lucide="loader-circle" class="h-4 w-4 animate-spin"></i>
      Deleting...
    `;
    lucide.createIcons();
    state.isDeleting = true;

    const remainingIds = [...selected];
    const successfulIds = [];
    const failedIds = [];
    const failedMessages = [];

    const deleteNext = () => {
      const id = remainingIds.shift();
      if (id === undefined) {
        if (successfulIds.length > 0 && failedIds.length === 0) {
          showToast(successfulIds.length === 1 ? 'Librarian deleted successfully.' : `${successfulIds.length} librarians deleted successfully.`);
        } else if (successfulIds.length > 0 && failedIds.length > 0) {
          showToast(`${successfulIds.length} deleted, ${failedIds.length} failed.`, 'error');
        } else {
          showToast(failedMessages[0] || 'Unable to delete selected librarians.', 'error');
        }

        fetchLibrarians(document.getElementById('librarianSearch').value.trim())
          .finally(() => {
            closeDeleteConfirmation();
          });
        return;
      }

      const formData = new URLSearchParams({ action: 'delete', id: String(id), admin_password: adminPassword });
      fetch('pages/user.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData.toString()
      })
        .then((response) => response.json())
        .then((result) => {
          if (!result.success) {
            failedIds.push(id);
            failedMessages.push(result.message || 'Unable to delete selected librarians.');
            return;
          }

          successfulIds.push(id);
        })
        .catch(() => {
          failedIds.push(id);
          failedMessages.push('Unable to delete selected librarians.');
        })
        .finally(() => {
          deleteNext();
        });
    };

    deleteNext();
  }

  function closeSaveConfirmation() {
    const modal = document.getElementById('saveConfirmModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('adminSavePassword').value = '';
    state.pendingSavePayload = null;
    if (state.lastFocusedElement && typeof state.lastFocusedElement.focus === 'function') {
      state.lastFocusedElement.focus();
    }
  }

  function closeStatusConfirmation() {
    const modal = document.getElementById('statusConfirmModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('adminStatusPassword').value = '';
    state.pendingStatusId = null;
    if (state.lastFocusedElement && typeof state.lastFocusedElement.focus === 'function') {
      state.lastFocusedElement.focus();
    }
  }

  function openStatusConfirmation(librarian) {
    state.lastFocusedElement = document.activeElement;
    state.pendingStatusId = librarian.id;
    const nextStatus = librarian.status === 'active' ? 'deactivate' : 'activate';
    const statusAction = nextStatus === 'deactivate' ? 'Deactivate' : 'Activate';
    document.getElementById('statusConfirmTitle').textContent = `${statusAction} account?`;
    document.getElementById('statusConfirmMessage').textContent = `${statusAction} ${librarian.fullName}'s access to AppSys Library. ${nextStatus === 'deactivate' ? 'They will no longer be able to sign in until re-enabled.' : 'They will regain sign-in access immediately.'}`;
    document.getElementById('adminStatusPassword').value = '';
    const modal = document.getElementById('statusConfirmModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('adminStatusPassword').focus();
  }

  function confirmPendingStatus() {
    if (!state.pendingStatusId) return;

    const adminPassword = document.getElementById('adminStatusPassword').value;
    if (!adminPassword) {
      showToast('Enter the administrator password to continue.', 'error');
      document.getElementById('adminStatusPassword').focus();
      return;
    }

    const id = state.pendingStatusId;
    closeStatusConfirmation();
    const payload = new URLSearchParams({ action: 'toggle_status', id: String(id), admin_password: adminPassword });

    fetch('pages/user.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: payload.toString()
    })
      .then((response) => response.json())
      .then((result) => {
        if (!result.success) throw new Error(result.message || 'Unable to change account status.');
        showToast(result.message);
        return fetchLibrarians(document.getElementById('librarianSearch').value.trim());
      })
      .catch((error) => showToast(error.message || 'Unable to change account status.', 'error'));
  }

  function openSaveConfirmation(payload) {
    state.lastFocusedElement = document.activeElement;
    state.pendingSavePayload = payload;
    document.getElementById('saveConfirmMessage').textContent = 'Please confirm the administrator password to save this user change.';
    document.getElementById('adminSavePassword').value = '';
    const modal = document.getElementById('saveConfirmModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('adminSavePassword').focus();
  }

  function confirmPendingSave() {
    if (!state.pendingSavePayload) return;

    const adminPassword = document.getElementById('adminSavePassword').value.trim();
    if (!adminPassword) {
      showToast('Enter the administrator password to continue.', 'error');
      document.getElementById('adminSavePassword').focus();
      return;
    }

    const payload = new URLSearchParams(state.pendingSavePayload.toString());
    payload.set('admin_password', adminPassword);
    closeSaveConfirmation();

    const saveButton = document.getElementById('saveLibrarianBtn');
    const defaultSaveButtonContent = saveButton.innerHTML;
    saveButton.disabled = true;
    saveButton.setAttribute('aria-busy', 'true');
    saveButton.innerHTML = '<i data-lucide="loader-circle" class="mr-2 inline-block h-4 w-4 animate-spin align-[-2px]"></i>Saving...';
    lucide.createIcons();

    fetch('pages/user.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: payload.toString()
    })
      .then((response) => response.json())
      .then((result) => {
        if (!result.success) {
          throw new Error(result.message || 'Unable to save librarian.');
        }

        closeModal();
        saveButton.disabled = false;
        saveButton.removeAttribute('aria-busy');
        saveButton.innerHTML = defaultSaveButtonContent;
        const saveMessage = result.emailQueued === true
          ? `${result.message} Password reset link queued for delivery.`
          : result.message;
        showToast(saveMessage);
        return fetchLibrarians(document.getElementById('librarianSearch').value.trim());
      })
      .then(() => {
        document.getElementById('bulkDeleteBtn').disabled = true;
      })
      .catch((error) => {
        saveButton.disabled = false;
        saveButton.removeAttribute('aria-busy');
        saveButton.innerHTML = defaultSaveButtonContent;
        lucide.createIcons();
        showToast(error.message || 'Unable to save librarian.', 'error');
      });
  }

  document.getElementById('addLibrarianBtn').addEventListener('click', () => openModal('add'));
  document.getElementById('bulkDeleteBtn').addEventListener('click', handleBulkDelete);
  document.getElementById('clearSelectionBtn').addEventListener('click', clearSelection);
  document.getElementById('drawerEditAction').addEventListener('click', () => {
    const librarian = state.allLibrarians.find((item) => item.id === state.activeDrawerUserId);
    if (librarian) {
      closeUserDetail();
      openModal('edit', librarian);
    }
  });
  document.getElementById('drawerStatusAction').addEventListener('click', () => {
    const librarian = state.allLibrarians.find((item) => item.id === state.activeDrawerUserId);
    if (librarian) {
      closeUserDetail();
      openStatusConfirmation(librarian);
    }
  });
  document.getElementById('drawerDeleteAction').addEventListener('click', () => {
    const librarian = state.allLibrarians.find((item) => item.id === state.activeDrawerUserId);
    if (librarian) {
      closeUserDetail();
      removeLibrarian(librarian.id);
    }
  });
  document.getElementById('cancelDeleteBtn').addEventListener('click', closeDeleteConfirmation);
  document.getElementById('confirmDeleteBtn').addEventListener('click', confirmPendingDelete);
  document.getElementById('cancelSaveBtn').addEventListener('click', closeSaveConfirmation);
  document.getElementById('confirmSaveBtn').addEventListener('click', confirmPendingSave);
  document.getElementById('cancelStatusBtn').addEventListener('click', closeStatusConfirmation);
  document.getElementById('confirmStatusBtn').addEventListener('click', confirmPendingStatus);
  document.getElementById('saveConfirmModal').addEventListener('click', (event) => {
    if (event.target.id === 'saveConfirmModal') closeSaveConfirmation();
  });
  document.getElementById('deleteConfirmModal').addEventListener('click', (event) => {
    if (event.target.id === 'deleteConfirmModal') closeDeleteConfirmation();
  });
  document.getElementById('statusConfirmModal').addEventListener('click', (event) => {
    if (event.target.id === 'statusConfirmModal') closeStatusConfirmation();
  });
  document.querySelectorAll('[data-close-drawer]').forEach((button) => {
    button.addEventListener('click', closeUserDetail);
  });

  document.getElementById('librarianSearch').addEventListener('input', function () {
    clearTimeout(state.searchTimer);
    state.searchTimer = setTimeout(() => {
      state.currentPage = 1;
      fetchLibrarians(this.value.trim());
    }, 250);
  });

  document.getElementById('librarianStatusFilter').addEventListener('change', function () {
    state.currentPage = 1;
    renderLibrarians();
  });

  document.getElementById('librarianRoleFilter').addEventListener('change', function () {
    state.currentPage = 1;
    renderLibrarians();
  });

  document.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    const drawer = document.getElementById('userDetailDrawer');
    if (!drawer.classList.contains('hidden')) {
      closeUserDetail();
      return;
    }

    const modal = document.getElementById('librarianModal');
    if (!modal.classList.contains('hidden')) {
      closeModal();
      return;
    }

    const deleteModal = document.getElementById('deleteConfirmModal');
    if (!deleteModal.classList.contains('hidden')) {
      closeDeleteConfirmation();
      return;
    }

    const saveModal = document.getElementById('saveConfirmModal');
    if (!saveModal.classList.contains('hidden')) {
      closeSaveConfirmation();
      return;
    }

    const statusModal = document.getElementById('statusConfirmModal');
    if (!statusModal.classList.contains('hidden')) {
      closeStatusConfirmation();
    }
  });

  document.getElementById('librarianModal').addEventListener('click', (event) => {
    if (event.target.id === 'librarianModal') {
      closeModal();
    }
  });

  document.getElementById('togglePassword').addEventListener('click', (event) => {
    event.preventDefault();
    const passwordInput = document.getElementById('password');
    const toggleBtn = event.currentTarget;
    const icon = toggleBtn.querySelector('i');

    if (passwordInput.type === 'password') {
      passwordInput.type = 'text';
      icon.setAttribute('data-lucide', 'eye-off');
    } else {
      passwordInput.type = 'password';
      icon.setAttribute('data-lucide', 'eye');
    }

    lucide.createIcons();
  });

  document.getElementById('librarianForm').addEventListener('submit', (event) => {
    event.preventDefault();

    const formData = new FormData(event.target);
    const payload = new URLSearchParams({ action: 'save' });

    payload.set('fullName', capitalizeFullName(String(formData.get('fullName') || '')));
    payload.set('email', String(formData.get('email') || '').trim());
    payload.set('username', String(formData.get('username') || '').trim());
    const password = String(formData.get('password') || '');
    payload.set('password', password);
    payload.set('role', String(formData.get('role') || ''));

    if (!payload.get('role')) {
      showToast('Please select a role.', 'error');
      return;
    }

    if (password !== '') {
      const passwordError = getPasswordError(password);
      if (passwordError) {
        showToast(passwordError, 'error');
        document.getElementById('password').focus();
        return;
      }
    }

    const editingId = document.getElementById('librarianId').value;
    const normalizedFullName = payload.get('fullName').toLowerCase();
    const normalizedUsername = payload.get('username').toLowerCase();
    const duplicate = state.allLibrarians.find((librarian) => {
      if (editingId && librarian.id === Number(editingId)) return false;
      return librarian.fullName.toLowerCase() === normalizedFullName || librarian.username.toLowerCase() === normalizedUsername;
    });

    if (duplicate) {
      const message = duplicate.fullName.toLowerCase() === normalizedFullName
        ? 'That full name is already in use.'
        : 'That username is already in use.';
      showToast(message, 'error');
      return;
    }

    if (editingId) {
      payload.set('id', editingId);
    }

    openSaveConfirmation(payload);
  });

  document.getElementById('bulkDeleteBtn').disabled = true;
  document.getElementById('bulkDeleteBtn').classList.add('opacity-60', 'cursor-not-allowed');
  setBulkDeleteState();
  fetchLibrarians();
})();
