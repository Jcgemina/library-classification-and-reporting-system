function initializeLogs() {
  const logsRoot = document.getElementById('logsContent');
  if (!logsRoot) return;

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
        return entry[0] !== 'action' && entry[0] !== 'description';
      }).map(function (entry) {
        return '<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">' + escapeHtml(entry[0]) + '</dt><dd class="mt-1 break-words font-medium text-slate-900">' + escapeHtml(entry[1]) + '</dd></div>';
      }).join('') + '<div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Description</dt><dd class="mt-1 text-slate-900">' + escapeHtml(detail.description) + '</dd></div>';
      detailDialog.showModal();
    });
  });

  detailDialog.querySelector('[data-log-detail-close]').addEventListener('click', function () {
    detailDialog.close();
  });

  detailDialog.addEventListener('click', function (event) {
    if (event.target === detailDialog) detailDialog.close();
  });

  const activateLogTab = function (tabName) {
    const security = tabName === 'security';
    const activityPanel = document.getElementById('activityLogPanel');
    const securityPanel = document.getElementById('securityLogPanel');
    activityPanel.classList.toggle('is-active', !security);
    securityPanel.classList.toggle('is-active', security);
    activityPanel.hidden = security;
    securityPanel.hidden = !security;
    const formTab = logsRoot.querySelector('[data-log-filter-form="' + tabName + '"] input[name="log_tab"]');
    if (formTab) formTab.value = tabName;
    logsRoot.querySelectorAll('[data-log-tab]').forEach(function (item) {
      const selected = item.dataset.logTab === tabName;
      item.classList.toggle('bg-slate-900', selected);
      item.classList.toggle('text-white', selected);
      item.classList.toggle('text-slate-600', !selected);
      item.classList.toggle('hover:bg-slate-100', !selected);
      item.setAttribute('aria-selected', selected ? 'true' : 'false');
      item.setAttribute('tabindex', selected ? '0' : '-1');
    });
    logsRoot.querySelectorAll('#' + tabName + 'LogPanel a').forEach(function (link) {
      if (link.dataset.logReset) return;
      const linkUrl = new URL(link.href, window.location.href);
      if (link.href.includes('logs_export.php')) {
        linkUrl.searchParams.set('log_tab', tabName);
        link.href = linkUrl.toString();
        return;
      }
      if (linkUrl.searchParams.get('page') !== 'logs') return;
      linkUrl.searchParams.set('log_tab', tabName);
      link.href = linkUrl.toString();
    });
  };

  logsRoot.addEventListener('click', function (event) {
    const tab = event.target.closest('[data-log-tab]');
    if (!tab) return;
    activateLogTab(tab.dataset.logTab);
    const tabUrl = new URL(window.location.href);
    tabUrl.searchParams.set('log_tab', tab.dataset.logTab);
    history.replaceState({page: 'logs'}, '', tabUrl);
  });

  logsRoot.querySelector('[role="tablist"]').addEventListener('keydown', function (event) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const tabs = [...this.querySelectorAll('[role="tab"]')];
    const current = tabs.findIndex(function (tab) { return tab.getAttribute('aria-selected') === 'true'; });
    const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    event.preventDefault();
    tabs[nextIndex].focus();
    tabs[nextIndex].click();
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
}

initializeLogs();
