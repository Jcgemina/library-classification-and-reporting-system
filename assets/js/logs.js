function initializeLogs() {
  const logsRoot = document.getElementById('logsContent');
  if (!logsRoot) return;
  window.lucide?.createIcons({ root: logsRoot });

  document.getElementById('logDetailDialog')?.remove();
  const detailDialog = document.createElement('dialog');
  detailDialog.id = 'logDetailDialog';
  detailDialog.className = 'w-[min(92vw,38rem)] rounded-2xl border border-slate-200 p-0 shadow-2xl backdrop:bg-slate-900/50';
  detailDialog.innerHTML = '<div class="border-b border-slate-100 px-5 py-4"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Audit detail</p><h3 class="mt-1 text-lg font-bold text-slate-900" data-log-detail-title>Event detail</h3></div><button type="button" aria-label="Close event details" data-log-detail-close class="rounded-lg p-1 text-2xl leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-700">&times;</button></div></div><dl class="grid gap-3 px-5 py-5 text-sm sm:grid-cols-2" data-log-detail-fields></dl>';
  document.body.appendChild(detailDialog);

  const escapeHtml = function (value) {
    return String(value).replace(/[&<>'"]/g, function (character) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[character];
    });
  };

  logsRoot.querySelectorAll('[data-log-detail]').forEach(function (button) {
    button.addEventListener('click', function () {
      const detail = JSON.parse(button.dataset.logDetail);
      detailDialog.querySelector('[data-log-detail-title]').textContent = detail.action;
      detailDialog.querySelector('[data-log-detail-fields]').innerHTML = Object.entries(detail).filter(function (entry) {
        return entry[0] !== 'action';
      }).map(function (entry) {
        return '<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">' + escapeHtml(entry[0]) + '</dt><dd class="mt-1 break-words font-medium text-slate-900">' + escapeHtml(entry[1]) + '</dd></div>';
      }).join('');
      detailDialog.showModal();
    });
  });

  detailDialog.querySelector('[data-log-detail-close]').addEventListener('click', function () {
    detailDialog.close();
  });

  detailDialog.addEventListener('click', function (event) {
    if (event.target === detailDialog) detailDialog.close();
  });

  const refreshLogs = function (url) {
    logsRoot.setAttribute('aria-busy', 'true');
    fetch('pages/logs.php' + url.search, {
      method: 'GET',
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
      .then(function (response) {
        if (!response.ok) throw new Error('Logs refresh failed');
        return response.text();
      })
      .then(function (html) {
        const fragment = document.createElement('div');
        fragment.innerHTML = html;
        const nextRoot = fragment.querySelector('#logsContent');
        if (!nextRoot) throw new Error('Logs fragment is missing');
        logsRoot.replaceWith(nextRoot);
        initializeLogs();
      })
      .catch(function () {
        logsRoot.removeAttribute('aria-busy');
        const error = document.createElement('p');
        error.className = 'mt-3 text-sm font-medium text-red-700';
        error.textContent = 'The filters could not be applied. Please try again.';
        logsRoot.querySelector('[data-log-filter-form="' + new URL(window.location.href).searchParams.get('log_tab') + '"]')?.appendChild(error);
      });
  };

  logsRoot.querySelectorAll('[data-log-filter-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      const url = new URL(window.location.href);
      url.search = '';
      url.searchParams.set('page', 'logs');
      new FormData(this).forEach(function (value, key) {
        if (value !== '') url.searchParams.set(key, value);
      });
      url.searchParams.set('per_page', String(window.AppPreferences?.get().rowsPerPage || 10));
      url.searchParams.delete('activity_page');
      url.searchParams.delete('security_page');
      history.pushState({page: 'logs'}, '', url);
      refreshLogs(url);
    });
  });

  logsRoot.addEventListener('click', function (event) {
    const link = event.target.closest('a');
    if (!link || link.href.includes('logs_export.php')) return;
    const resetType = link.dataset.logReset;
    if (resetType) {
      event.preventDefault();
      const url = new URL(window.location.href);
      ['search', 'start_date', 'end_date', 'user_id', 'severity', resetType + '_search', resetType + '_start_date', resetType + '_end_date', resetType + '_user_id', resetType + '_severity', resetType + '_page'].forEach(function (key) {
        url.searchParams.delete(key);
      });
      url.searchParams.set('page', 'logs');
      url.searchParams.set('log_tab', resetType);
      history.pushState({page: 'logs'}, '', url);
      refreshLogs(url);
      return;
    }
    const url = new URL(link.href, window.location.href);
    if (url.searchParams.get('page') !== 'logs') return;
    event.preventDefault();
    history.pushState({page: 'logs'}, '', url);
    refreshLogs(url);
  });

  const preferredPageSize = window.AppPreferences?.get().rowsPerPage || 10;
  const currentUrl = new URL(window.location.href);
  if (Number(currentUrl.searchParams.get('per_page')) !== preferredPageSize) {
    currentUrl.searchParams.set('per_page', String(preferredPageSize));
    currentUrl.searchParams.delete('activity_page');
    currentUrl.searchParams.delete('security_page');
    history.replaceState({page: 'logs'}, '', currentUrl);
    refreshLogs(currentUrl);
  }
}

initializeLogs();
