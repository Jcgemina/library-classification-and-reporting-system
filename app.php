<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$csrfToken = csrfToken();

$userRole = strtolower($_SESSION['role'] ?? 'librarian');
$adminPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'preferences', 'account', 'sign_in_activity', 'user', 'logs', 'configure', 'backup'];
$staffPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'preferences', 'account', 'sign_in_activity'];
$restrictedPages = ['user', 'logs', 'configure', 'backup'];

$allowedPages = $userRole === 'admin' ? $adminPages : $staffPages;
$pageParam = $_GET['page'] ?? 'dashboard';

if ($userRole !== 'admin' && in_array($pageParam, $restrictedPages, true)) {
    header('Location: app.php?page=dashboard');
    exit;
}

$_GET['page'] = in_array($pageParam, $allowedPages, true) ? $pageParam : 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LCARS - Dashboard</title>
<link rel="icon" href="assets/brand/digital/favicon.ico" sizes="48x48">
<link rel="icon" type="image/svg+xml" href="assets/brand/digital/favicon.svg">
<link rel="manifest" href="assets/brand/digital/site.webmanifest">
<link rel="apple-touch-icon" href="assets/brand/digital/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/design-tokens.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/design-tokens.css'); ?>">
<link rel="stylesheet" href="assets/css/preferences.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/preferences.css'); ?>">
<link rel="stylesheet" href="assets/css/settings.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/settings.css'); ?>">
<link rel="stylesheet" href="assets/css/organization.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/organization.css'); ?>">
<link rel="stylesheet" href="assets/css/backup.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/backup.css'); ?>">
<style>
  html,
  body {
    font-family: var(--app-font-family);
    font-synthesis: none;
    text-rendering: optimizeLegibility;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }

  body .text-xs {
    font-size: 0.75rem;
    line-height: 1rem;
  }

  body .text-sm {
    font-size: 0.875rem;
    line-height: 1.25rem;
  }

  .app-page-skeleton,
  .app-page-skeleton-visual {
    display: grid;
    gap: 1.25rem;
  }

  .app-skeleton-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
  }

  .app-skeleton-heading,
  .app-skeleton-panel-heading,
  .app-skeleton-book-copy {
    display: grid;
    gap: 0.625rem;
  }

  .app-skeleton-actions,
  .app-skeleton-tabs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.625rem;
  }

  .app-skeleton-block {
    display: block;
    border-radius: 0.375rem;
    background: var(--app-color-border-strong);
    animation: app-skeleton-pulse 1.5s ease-in-out infinite;
  }

  .app-skeleton-title {
    width: min(14rem, 58vw);
    height: 1.75rem;
  }

  .app-skeleton-description {
    width: min(23rem, 76vw);
    height: 0.75rem;
  }

  .app-skeleton-short {
    width: 38%;
    height: 0.65rem;
  }

  .app-skeleton-medium {
    width: 65%;
    height: 0.75rem;
  }

  .app-skeleton-long {
    width: 88%;
    height: 0.75rem;
  }

  .app-skeleton-number {
    width: 4.5rem;
    height: 1.75rem;
  }

  .app-skeleton-action {
    width: 7rem;
    height: 2.5rem;
    border-radius: 0.5rem;
  }

  .app-skeleton-grid {
    display: grid;
    gap: 1rem;
  }

  .app-skeleton-grid-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .app-skeleton-grid-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .app-skeleton-grid-4 {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }

  .app-skeleton-panel {
    display: grid;
    align-content: start;
    gap: 1rem;
    min-width: 0;
    padding: 1rem;
    border: 1px solid var(--app-color-border);
    border-radius: 0.75rem;
    background: var(--app-color-surface);
  }

  .app-skeleton-panel-heading > .app-skeleton-block:first-child {
    width: 42%;
    height: 0.95rem;
  }

  .app-skeleton-panel-heading > .app-skeleton-block:last-child {
    width: 76%;
    height: 0.65rem;
  }

  .app-skeleton-metric-card {
    min-height: 6rem;
  }

  .app-skeleton-metric-card .app-skeleton-number {
    margin-top: 0.25rem;
  }

  .app-skeleton-toolbar {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.75rem;
  }

  .app-skeleton-filter {
    display: grid;
    min-width: 0;
    gap: 0.5rem;
  }

  .app-skeleton-filter > .app-skeleton-block:first-child {
    width: 42%;
    height: 0.65rem;
  }

  .app-skeleton-input {
    display: flex;
    align-items: center;
    height: 2.5rem;
    padding: 0 0.75rem;
    border: 1px solid var(--app-color-border);
    border-radius: 0.5rem;
    background: var(--app-color-surface);
  }

  .app-skeleton-input .app-skeleton-block {
    width: 62%;
    height: 0.65rem;
  }

  .app-skeleton-table-scroll {
    overflow-x: auto;
    border: 1px solid var(--app-color-border);
    border-radius: 0.75rem;
    background: var(--app-color-surface);
  }

  .app-skeleton-table {
    min-width: 34rem;
  }

  .app-skeleton-table-row {
    display: grid;
    grid-template-columns: repeat(var(--skeleton-columns), minmax(0, 1fr));
    align-items: center;
    gap: 1rem;
    min-height: 3.25rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--app-color-border);
  }

  .app-skeleton-table-row:last-child {
    border-bottom: 0;
  }

  .app-skeleton-table-row .app-skeleton-block {
    width: 72%;
    height: 0.7rem;
  }

  .app-skeleton-table-row .app-skeleton-block:first-child {
    width: 88%;
  }

  .app-skeleton-table-heading {
    background: var(--app-color-border);
  }

  .app-skeleton-table-heading .app-skeleton-block {
    width: 58%;
    height: 0.6rem;
  }

  .app-skeleton-form-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
  }

  .app-skeleton-form-field {
    display: grid;
    gap: 0.5rem;
  }

  .app-skeleton-form-field > .app-skeleton-block:first-child {
    width: 36%;
    height: 0.65rem;
  }

  .app-skeleton-form-field .app-skeleton-input {
    height: 2.5rem;
  }

  .app-skeleton-academic {
    display: grid;
    grid-template-columns: minmax(12rem, 0.7fr) minmax(0, 1.8fr);
    gap: 1rem;
  }

  .app-skeleton-academic-tree {
    min-height: 19rem;
  }

  .app-skeleton-academic-list {
    display: grid;
    gap: 0.75rem;
  }

  .app-skeleton-academic-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-height: 2.75rem;
    padding: 0.5rem;
    border-bottom: 1px solid var(--app-color-border);
  }

  .app-skeleton-avatar {
    width: 3rem;
    height: 3rem;
    flex: 0 0 auto;
    border-radius: 999px;
  }

  .app-skeleton-book-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
  }

  .app-skeleton-book-card {
    min-height: 10rem;
  }

  .app-skeleton-book-copy .app-skeleton-block:first-child {
    width: 75%;
    height: 0.9rem;
  }

  .app-skeleton-tabs .app-skeleton-action {
    width: 6rem;
  }

  @keyframes app-skeleton-pulse {
    0%, 100% { opacity: 0.68; }
    50% { opacity: 1; }
  }

  @media (max-width: 900px) {
    .app-skeleton-grid-4 {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .app-skeleton-book-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .app-skeleton-toolbar {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 600px) {
    .app-skeleton-header {
      align-items: stretch;
      flex-direction: column;
    }

    .app-skeleton-actions {
      justify-content: flex-end;
    }

    .app-skeleton-grid-2,
    .app-skeleton-grid-3,
    .app-skeleton-grid-4,
    .app-skeleton-book-grid,
    .app-skeleton-toolbar,
    .app-skeleton-form-row,
    .app-skeleton-academic {
      grid-template-columns: minmax(0, 1fr);
    }

    .app-skeleton-academic-tree {
      min-height: 10rem;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .app-skeleton-block {
      animation: none;
    }
  }

  html,
  body {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 transparent;
  }

  @media (min-width: 768px) and (max-width: 1440px) {
    #adminSidebar {
      width: 15rem;
    }

    #appMainShell {
      margin-left: 15rem;
    }

    #pageContent {
      padding: 1.25rem;
    }
  }

  @media (min-width: 768px) and (max-width: 1280px) {
    #pageContent {
      padding: 1.125rem;
    }
  }

  html::-webkit-scrollbar,
  body::-webkit-scrollbar {
    width: 10px;
    height: 10px;
  }

  html::-webkit-scrollbar-track,
  body::-webkit-scrollbar-track {
    background: transparent;
  }

  html::-webkit-scrollbar-thumb,
  body::-webkit-scrollbar-thumb {
    border: 3px solid transparent;
    border-radius: 999px;
    background: #cbd5e1;
    background-clip: padding-box;
  }
</style>
<link rel="stylesheet" href="assets/css/app.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/app.css'); ?>">
<link rel="stylesheet" href="assets/css/inventory.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/inventory.css'); ?>">
<link rel="stylesheet" href="assets/css/catalog-reports.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/catalog-reports.css'); ?>">
<link rel="stylesheet" href="assets/css/user.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/user.css'); ?>">
<script id="lucideLibrary" src="assets/vendor/lucide/lucide.min.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/vendor/lucide/lucide.min.js'); ?>" defer></script>
<script>
  window.renderIcons = function () {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons();
    }
  };
  document.getElementById('lucideLibrary').addEventListener('load', function () {
    window.lucide.createIcons();
  });
</script>
<link rel="stylesheet" href="assets/css/navigation.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/navigation.css'); ?>">
<link rel="stylesheet" href="assets/css/dark-theme.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/dark-theme.css'); ?>">
<script>
  window.appUserPreferenceId = <?php echo json_encode((string) ($_SESSION['user_id'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  window.appCsrfToken = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="assets/js/preferences.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/js/preferences.js'); ?>"></script>
</head>
<body class="min-h-screen overflow-x-hidden bg-white">

  <?php require_once __DIR__ . '/includes/nav.php'; ?>
  <div id="appToastContainer" class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3" role="region" aria-label="Notifications"></div>
  <script src="assets/js/toast.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/js/toast.js'); ?>"></script>

  <script>
    window.renderIcons();
  </script>

  <div id="appMainShell" data-admin-shell="<?php echo $userRole === 'admin' ? 'true' : 'false'; ?>" class="md:ml-[248px]">
    <main id="pageContent" class="mx-auto w-full max-w-[1600px] min-w-0 overflow-x-hidden p-6" aria-busy="true"></main>
  </div>

  <script>
    const currentUserRole = <?php echo json_encode($userRole, JSON_THROW_ON_ERROR); ?>;
    const adminPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'preferences', 'account', 'sign_in_activity', 'user', 'logs', 'configure'];
    const staffPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'preferences', 'account', 'sign_in_activity'];
    const restrictedPages = ['user', 'logs', 'configure'];
    const allowedPages = currentUserRole === 'admin' ? adminPages : staffPages;

    function normalizePage(pageName) {
      if (currentUserRole !== 'admin' && restrictedPages.includes(pageName)) {
        return 'dashboard';
      }

      return allowedPages.includes(pageName) ? pageName : 'dashboard';
    }

    function getCurrentPageFromUrl() {
      const params = new URLSearchParams(window.location.search);
      const requestedPage = params.get('page');
      return normalizePage(requestedPage);
    }

    function urlLogTab() {
      return new URLSearchParams(window.location.search).get('log_tab') === 'security' ? 'security' : 'activity';
    }

    function updateActiveTab(activePage) {
        const settingsPages = ['account', 'sign_in_activity', 'preferences', 'configure'];
        const settingsActive = settingsPages.includes(activePage);
        const activeNavPage = settingsActive ? 'account' : activePage;
        const activeLogTab = urlLogTab();
        const navLinks = document.querySelectorAll('[data-page]:not([data-settings-child])');
        const highlight = document.querySelector('.nav-highlight');
        const navTrack = document.querySelector('.nav-track');

        const isCurrentNavLink = (link) => {
            if (link.hasAttribute('data-logs-parent')) {
                return activePage === 'logs';
            }
            if (link.hasAttribute('data-log-tab')) {
                return activePage === 'logs' && link.dataset.logTab === activeLogTab;
            }
            return link.dataset.page === activeNavPage;
        };
        const activeLink = [...navLinks].find(isCurrentNavLink);

        navLinks.forEach(link => {
            const isActive = isCurrentNavLink(link);
            const isMobileLink = link.closest('#mobileMenu');
            const isAdminSidebarLink = link.closest('#adminSidebar');
            const isSidebarLink = Boolean(isMobileLink || isAdminSidebarLink);

            link.classList.toggle('text-white', isActive && !isSidebarLink);
            link.classList.toggle('text-slate-600', !isActive || isSidebarLink);
            link.classList.toggle('hover:text-slate-900', !isActive);
            link.classList.toggle('bg-rose-700', isActive && !isSidebarLink);

            link.classList.remove('text-slate-900');
            link.classList.toggle('shadow-sm', isActive && !isAdminSidebarLink && !isMobileLink);
            link.classList.toggle('hover:bg-slate-100', !isActive);

            if (isActive) {
                link.setAttribute('aria-current', link.hasAttribute('data-settings-parent') || link.hasAttribute('data-logs-parent') ? 'location' : 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });

        const urlParams = new URLSearchParams(window.location.search);
        const activeSettingsSubpage = activePage === 'configure'
            ? (urlParams.get('section') === 'rules' ? 'rules' : urlParams.get('section') === 'backup' ? 'backup' : 'institution')
            : activePage === 'sign_in_activity' ? 'account' : activePage;
        document.querySelectorAll('[data-settings-child]').forEach(link => {
            const isActive = settingsActive && link.dataset.settingsChild === activeSettingsSubpage;
            link.classList.remove('bg-rose-50', 'text-rose-800', 'font-semibold');
            link.classList.add('text-slate-600', 'hover:bg-slate-100');

            if (isActive) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });

        document.querySelectorAll('[data-settings-menu-content]').forEach(menu => {
            menu.hidden = !settingsActive;
        });
        document.querySelectorAll('[data-settings-toggle]').forEach(toggle => {
            toggle.setAttribute('aria-expanded', settingsActive ? 'true' : 'false');
            toggle.setAttribute('aria-label', settingsActive ? 'Collapse Settings submenu' : 'Expand Settings submenu');
        });
        document.querySelectorAll('.settings-nav-row').forEach(row => {
            row.classList.toggle('is-current', settingsActive);
        });

        document.querySelectorAll('[data-logs-menu-content]').forEach(menu => {
            menu.hidden = activePage !== 'logs';
        });
        document.querySelectorAll('[data-logs-toggle]').forEach(toggle => {
            toggle.setAttribute('aria-expanded', activePage === 'logs' ? 'true' : 'false');
            toggle.setAttribute('aria-label', activePage === 'logs' ? 'Collapse Logs submenu' : 'Expand Logs submenu');
        });

        if (highlight && activeLink && navTrack) {
            const highlightTarget = activeLink.hasAttribute('data-settings-parent')
                ? activeLink.parentElement
                : activeLink;
            const trackRect = navTrack.getBoundingClientRect();
            const linkRect = highlightTarget.getBoundingClientRect();
            const offsetLeft = linkRect.left - trackRect.left;
            const offsetTop = linkRect.top - trackRect.top;

          if (!highlight.classList.contains('is-ready')) {
            highlight.style.transition = 'none';
            highlight.style.width = highlightTarget.offsetWidth + 'px';
            highlight.style.height = linkRect.height + 'px';
            highlight.style.top = offsetTop + 'px';
            highlight.style.transform = `translate3d(${offsetLeft}px, 0, 0)`;

            requestAnimationFrame(function () {
              highlight.classList.add('is-ready');
              highlight.style.transition = '';
            });
          } else {
            requestAnimationFrame(function () {
              highlight.style.width = highlightTarget.offsetWidth + 'px';
              highlight.style.height = linkRect.height + 'px';
              highlight.style.top = offsetTop + 'px';
              highlight.style.transform = `translate3d(${offsetLeft}px, 0, 0)`;
            });
          }

        }
    }

    function skeletonBlock(size) {
      return `<span class="app-skeleton-block app-skeleton-${size}"></span>`;
    }

    function skeletonHeader(actionCount = 0) {
      const actions = actionCount
        ? `<div class="app-skeleton-actions">${Array.from({ length: actionCount }, () => skeletonBlock('action')).join('')}</div>`
        : '';

      return `<div class="app-skeleton-header"><div class="app-skeleton-heading">${skeletonBlock('title')}${skeletonBlock('description')}</div>${actions}</div>`;
    }

    function skeletonMetricCards(count) {
      return `<div class="app-skeleton-grid app-skeleton-grid-${count}">${Array.from({ length: count }, () => `<div class="app-skeleton-panel app-skeleton-metric-card">${skeletonBlock('short')}${skeletonBlock('number')}${skeletonBlock('medium')}</div>`).join('')}</div>`;
    }

    function skeletonToolbar(fieldCount) {
      const fields = Array.from({ length: fieldCount }, () => `<div class="app-skeleton-filter">${skeletonBlock('short')}<div class="app-skeleton-input">${skeletonBlock('medium')}</div></div>`).join('');
      return `<div class="app-skeleton-toolbar">${fields}</div>`;
    }

    function skeletonTable(columnCount, rowCount) {
      const rows = Array.from({ length: rowCount + 1 }, (_, rowIndex) => {
        const cells = Array.from({ length: columnCount }, () => skeletonBlock('medium')).join('');
        const headingClass = rowIndex === 0 ? ' app-skeleton-table-heading' : '';
        return `<div class="app-skeleton-table-row${headingClass}" style="--skeleton-columns:${columnCount}">${cells}</div>`;
      }).join('');

      return `<div class="app-skeleton-table-scroll"><div class="app-skeleton-table">${rows}</div></div>`;
    }

    function skeletonPanel(lineCount = 3) {
      const lines = Array.from({ length: lineCount }, (_, index) => skeletonBlock(index % 2 === 0 ? 'long' : 'medium')).join('');
      return `<section class="app-skeleton-panel"><div class="app-skeleton-panel-heading">${skeletonBlock('medium')}${skeletonBlock('long')}</div>${lines}</section>`;
    }

    function skeletonFormPanel(rowCount = 2) {
      const rows = Array.from({ length: rowCount }, () => `<div class="app-skeleton-form-row">${[0, 1].map(() => `<div class="app-skeleton-form-field">${skeletonBlock('short')}<div class="app-skeleton-input">${skeletonBlock('medium')}</div></div>`).join('')}</div>`).join('');
      return `<section class="app-skeleton-panel"><div class="app-skeleton-panel-heading">${skeletonBlock('medium')}${skeletonBlock('long')}</div>${rows}<div class="app-skeleton-actions">${skeletonBlock('action')}</div></section>`;
    }

    function skeletonBookCards(count = 6) {
      const cards = Array.from({ length: count }, () => `<article class="app-skeleton-panel app-skeleton-book-card"><div class="app-skeleton-book-copy">${skeletonBlock('medium')}${skeletonBlock('short')}</div><div class="app-skeleton-grid app-skeleton-grid-2">${skeletonBlock('short')}${skeletonBlock('medium')}${skeletonBlock('short')}${skeletonBlock('medium')}</div></article>`).join('');
      return `<div class="app-skeleton-book-grid">${cards}</div>`;
    }

    function renderPageSkeleton(pageName, requestedParams) {
      const configureSection = requestedParams.get('section') === 'rules'
        ? 'rules'
        : requestedParams.get('section') === 'backup' ? 'backup' : 'institution';
      const logTab = requestedParams.get('log_tab') === 'security' ? 'security' : 'activity';
      const pageTitle = pageName === 'configure'
        ? configureSection === 'rules' ? 'Library Rules' : configureSection === 'backup' ? 'Backup & Restore' : 'Institutional Profile'
        : pageName === 'logs'
          ? logTab === 'security' ? 'Security logs' : 'Activity logs'
          : ({
            dashboard: 'Dashboard',
            inventory: 'Inventory',
            academics: 'Academic Structure',
            course: 'Course Catalog',
            reference_analyzer: 'Reference Age',
            report: 'Reports',
            preferences: 'Preferences',
            account: 'My Account',
            sign_in_activity: 'Sign-in activity',
            user: 'Users',
          }[pageName] || 'Page');

      let content;
      switch (pageName) {
        case 'dashboard':
          content = `${skeletonMetricCards(4)}<div class="app-skeleton-grid app-skeleton-grid-2">${skeletonPanel(4)}${skeletonPanel(4)}</div>${skeletonPanel(3)}`;
          break;
        case 'inventory':
          content = `${skeletonToolbar(3)}${skeletonBookCards()}`;
          break;
        case 'report':
          content = `${skeletonToolbar(4)}${skeletonTable(5, 6)}`;
          break;
        case 'academics':
          content = `<div class="app-skeleton-academic"><section class="app-skeleton-panel app-skeleton-academic-tree">${skeletonBlock('medium')}${skeletonToolbar(1)}<div class="app-skeleton-academic-list">${Array.from({ length: 5 }, () => `<div class="app-skeleton-academic-item">${skeletonBlock('avatar')}${skeletonBlock('medium')}</div>`).join('')}</div></section><section class="app-skeleton-panel"><div class="app-skeleton-tabs">${Array.from({ length: 3 }, () => skeletonBlock('action')).join('')}</div>${skeletonToolbar(2)}${skeletonTable(4, 5)}</section></div>`;
          break;
        case 'course':
          content = `${skeletonMetricCards(3)}${skeletonToolbar(3)}${skeletonTable(5, 5)}`;
          break;
        case 'reference_analyzer':
          content = `${skeletonMetricCards(3)}${skeletonToolbar(3)}${skeletonTable(5, 5)}`;
          break;
        case 'preferences':
          content = `<div class="app-skeleton-grid app-skeleton-grid-2">${skeletonFormPanel(2)}${skeletonFormPanel(2)}</div>`;
          break;
        case 'account':
          content = `<section class="app-skeleton-panel"><div class="app-skeleton-actions">${skeletonBlock('avatar')}<div class="app-skeleton-heading">${skeletonBlock('medium')}${skeletonBlock('short')}</div></div></section><div class="app-skeleton-grid app-skeleton-grid-2">${skeletonFormPanel(2)}${skeletonFormPanel(2)}</div>${skeletonPanel(3)}`;
          break;
        case 'sign_in_activity':
          content = skeletonPanel(1) + skeletonTable(4, 5);
          break;
        case 'user':
          content = `${skeletonMetricCards(3)}${skeletonToolbar(3)}${skeletonTable(5, 5)}`;
          break;
        case 'logs':
          content = `<div class="app-skeleton-tabs">${skeletonBlock('action')}${skeletonBlock('action')}</div>${skeletonToolbar(4)}${skeletonTable(5, 5)}`;
          break;
        case 'configure':
          if (configureSection === 'backup') {
            content = `<div class="app-skeleton-grid app-skeleton-grid-2">${skeletonFormPanel(1)}${skeletonFormPanel(1)}</div>${skeletonPanel(2)}${skeletonTable(4, 3)}`;
          } else if (configureSection === 'rules') {
            content = `<div class="app-skeleton-grid app-skeleton-grid-2">${skeletonFormPanel(2)}${skeletonFormPanel(2)}</div>${skeletonFormPanel(2)}${skeletonPanel(3)}`;
          } else {
            content = skeletonFormPanel(3);
          }
          break;
        default:
          content = `${skeletonToolbar(3)}${skeletonTable(4, 5)}`;
      }

      const actionCount = {
        dashboard: 1,
        inventory: 2,
        academics: 2,
        course: 2,
        reference_analyzer: 1,
        user: 1,
      }[pageName] || 0;

      return `<section class="app-page-skeleton" role="status"><span class="sr-only">Loading ${pageTitle}…</span><div class="app-page-skeleton-visual" aria-hidden="true">${skeletonHeader(actionCount)}${content}</div></section>`;
    }

    function scrollToRouteAnchor(hash, behavior = 'auto') {
      if (!hash) {
        return;
      }

      const target = document.getElementById(hash.slice(1));
      const pageContainer = document.getElementById('pageContent');
      if (target && pageContainer.contains(target)) {
        requestAnimationFrame(() => target.scrollIntoView({ behavior, block: 'start' }));
      }
    }

    function updateConfigureSection(section) {
      const activeSection = section === 'rules' ? 'rules' : section === 'backup' ? 'backup' : 'institution';
      const configurePage = document.querySelector('[data-configure-page]');
      if (!configurePage) {
        return;
      }

      configurePage.dataset.activeSection = activeSection;
      document.getElementById('configurePageHeading').textContent = activeSection === 'rules'
        ? 'Library Rules'
        : activeSection === 'backup'
        ? 'Backup & Restore'
        : 'Institutional Profile';
      document.getElementById('configurePageDescription').textContent = activeSection === 'rules'
        ? 'Set the reporting and coverage rules used by library reports and prospectus records.'
        : activeSection === 'backup'
        ? 'Create a full system backup or restore the library from a verified backup file.'
        : 'Manage the institution details shown in report previews.';
      const breadcrumbCurrent = document.getElementById('configureBreadcrumbCurrent');
      if (breadcrumbCurrent) {
        breadcrumbCurrent.textContent = activeSection === 'rules'
          ? 'Library Rules'
          : activeSection === 'backup'
          ? 'Backup & Restore'
          : 'Institutional Profile';
      }
      const contextBadge = document.getElementById('configureContextBadge');
      if (contextBadge) {
        const isBackup = activeSection === 'backup';
        contextBadge.classList.toggle('backup-access-label', isBackup);
        contextBadge.classList.toggle('settings-preview-chip', !isBackup);
        const icon = document.createElement('i');
        icon.setAttribute('data-lucide', isBackup ? 'shield-check' : 'settings-2');
        icon.setAttribute('aria-hidden', 'true');
        contextBadge.replaceChildren(icon, document.createTextNode(isBackup ? 'Admin only' : 'Administrative settings'));
        window.renderIcons();
      }
    }

    function getPageQueryParams(pageName, params) {
      const requestedParams = new URLSearchParams(params);
      requestedParams.delete('page');

      if (pageName === 'logs') {
        return requestedParams;
      }

      if (pageName === 'configure') {
        const section = requestedParams.get('section');
        return section === 'rules' || section === 'institution' || section === 'backup'
          ? new URLSearchParams([['section', section]])
          : new URLSearchParams();
      }

      if (pageName === 'inventory') {
        return new URLSearchParams(
          [...requestedParams].filter(([key]) => ['book_id', 'inventory_action'].includes(key))
        );
      }

      if (pageName === 'report') {
        const reportParams = new URLSearchParams();
        const programId = requestedParams.get('program_id');
        const majorId = requestedParams.get('major_id');
        const year = requestedParams.get('year');

        if (programId && /^[1-9]\d*$/.test(programId)) {
          reportParams.set('program_id', programId);
        }
        if (majorId && /^[1-9]\d*$/.test(majorId)) {
          reportParams.set('major_id', majorId);
        }
        if (year && /^(20\d{2}|21\d{2}|2200)$/.test(year)) {
          reportParams.set('year', year);
        }

        return reportParams;
      }

      return new URLSearchParams();
    }

    let activePageRequestId = 0;

    function loadPage(pageName, updateHistory = true, requestedParams = new URLSearchParams(window.location.search), targetHash = window.location.hash) {
      const safePage = normalizePage(pageName);
      const requestedSection = requestedParams.get('section');
      const pageLabels = {
        dashboard: 'Dashboard',
        inventory: 'Inventory',
        academics: 'Academic Management',
        course: 'Courses',
        reference_analyzer: 'Reference Analyzer',
        report: 'Report',
        preferences: 'Preferences',
        account: 'My Account',
        sign_in_activity: 'Sign-in activity',
        user: 'User Management',
        logs: 'Logs',
        configure: requestedSection === 'rules' ? 'Library Rules' : requestedSection === 'backup' ? 'Backup & Restore' : 'Institutional Profile',
      };
      const pageLabel = pageLabels[safePage] || 'requested';
      const requestId = ++activePageRequestId;
      const pageContainer = document.getElementById('pageContent');
      const preservedParams = getPageQueryParams(safePage, requestedParams);

      if (updateHistory) {
        const url = new URL(window.location.href);
        url.searchParams.set('page', safePage);
        [...url.searchParams.keys()].forEach((key) => {
          if (key !== 'page') {
            url.searchParams.delete(key);
          }
        });
        preservedParams.forEach((value, key) => url.searchParams.set(key, value));
        url.hash = targetHash;
        history.pushState({ page: safePage }, '', url);
      }

      updateActiveTab(safePage);

      const pageQuery = preservedParams.toString() ? '?' + preservedParams.toString() : '';

      const pageFile = safePage === 'academics' ? 'organization' : safePage;
      pageContainer.setAttribute('aria-busy', 'true');
      pageContainer.innerHTML = renderPageSkeleton(safePage, preservedParams);

      fetch('pages/' + pageFile + '.php' + pageQuery, {
        method: 'GET',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': window.appCsrfToken
        }
      })
      .then((response) => {
        if (!response.ok) {
          throw new Error('Page load failed');
        }
        return response.text();
      })
      .then((html) => {
        if (requestId !== activePageRequestId) {
          return;
        }

        pageContainer.innerHTML = html;
        window.renderIcons();

        const scripts = [...pageContainer.querySelectorAll('script')];
        scripts.forEach((oldScript) => {
          const newScript = document.createElement('script');
          Array.from(oldScript.attributes).forEach((attr) => {
            newScript.setAttribute(attr.name, attr.value);
          });
          newScript.textContent = oldScript.textContent;
          oldScript.replaceWith(newScript);
        });

        scrollToRouteAnchor(targetHash, updateHistory ? 'smooth' : 'auto');
        const pageTitle = safePage === 'preferences'
          ? 'Preferences'
          : safePage === 'account'
          ? 'My Account'
          : safePage === 'sign_in_activity'
          ? 'Sign-in activity'
          : safePage === 'configure'
          ? (requestedSection === 'rules' ? 'Library Rules' : requestedSection === 'backup' ? 'Backup & Restore' : 'Institutional Profile')
          : safePage === 'reference_analyzer'
            ? 'Reference Analyzer'
            : safePage.charAt(0).toUpperCase() + safePage.slice(1);
        document.title = 'LCARS - ' + pageTitle;
      })
      .catch(() => {
        if (requestId !== activePageRequestId) {
          return;
        }

        pageContainer.innerHTML = `<div class="rounded-2xl bg-white p-8 shadow-md" role="alert"><h2 class="mb-2 text-2xl font-bold text-slate-900">${pageLabel} could not be loaded</h2><p class="text-slate-600">Try again. If the problem continues, contact your administrator.</p><button type="button" data-retry-page="${safePage}" class="mt-5 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-300">Try again</button></div>`;
      })
      .finally(() => {
        if (requestId === activePageRequestId) {
          pageContainer.setAttribute('aria-busy', 'false');
        }
      });
    }

    document.addEventListener('click', function (event) {
      const retryButton = event.target.closest('[data-retry-page]');
      if (retryButton) {
        event.preventDefault();
        loadPage(retryButton.dataset.retryPage, false);
        return;
      }

      const link = event.target.closest('[data-page]');
      if (!(link instanceof HTMLAnchorElement)) {
        return;
      }

      event.preventDefault();

      const targetUrl = new URL(link.href, window.location.href);
      const currentUrl = new URL(window.location.href);
      if (link.dataset.page === 'configure' && getCurrentPageFromUrl() === 'configure') {
        const preservedParams = getPageQueryParams('configure', targetUrl.searchParams);
        const sectionUrl = new URL(currentUrl);
        [...sectionUrl.searchParams.keys()].forEach(key => {
          if (key !== 'page') {
            sectionUrl.searchParams.delete(key);
          }
        });
        sectionUrl.searchParams.set('page', 'configure');
        preservedParams.forEach((value, key) => sectionUrl.searchParams.set(key, value));
        sectionUrl.hash = targetUrl.hash;
        history.pushState({ page: 'configure' }, '', sectionUrl);
        updateConfigureSection(preservedParams.get('section'));
        updateActiveTab('configure');
        document.title = 'LCARS - ' + (preservedParams.get('section') === 'rules' ? 'Library Rules' : preservedParams.get('section') === 'backup' ? 'Backup & Restore' : 'Institutional Profile');
        scrollToRouteAnchor(targetUrl.hash, 'smooth');
        return;
      }

      const samePage = link.dataset.page === getCurrentPageFromUrl()
        && targetUrl.searchParams.get('section') === currentUrl.searchParams.get('section')
        && (link.dataset.page !== 'logs' || targetUrl.searchParams.get('log_tab') === currentUrl.searchParams.get('log_tab'))
        && targetUrl.hash === currentUrl.hash;
      if (samePage) {
        if (link.hasAttribute('data-settings-parent')) {
          document.querySelectorAll('[data-settings-menu-content]').forEach(menu => { menu.hidden = false; });
          document.querySelectorAll('[data-settings-toggle]').forEach(toggle => {
            toggle.setAttribute('aria-expanded', 'true');
            toggle.setAttribute('aria-label', 'Collapse Settings submenu');
          });
        }
        return;
      }

      loadPage(link.dataset.page, true, targetUrl.searchParams, targetUrl.hash);
    });

    window.addEventListener('popstate', function () {
      const activePage = getCurrentPageFromUrl();
      if (activePage === 'configure' && document.querySelector('[data-configure-page]')) {
        updateConfigureSection(new URLSearchParams(window.location.search).get('section'));
        updateActiveTab(activePage);
        document.title = 'LCARS - ' + (new URLSearchParams(window.location.search).get('section') === 'rules' ? 'Library Rules' : new URLSearchParams(window.location.search).get('section') === 'backup' ? 'Backup & Restore' : 'Institutional Profile');
        scrollToRouteAnchor(window.location.hash);
        return;
      }

      loadPage(activePage, false);
    });

    // Add resize listener to recalculate highlight position on responsive layout changes
    let resizeTimeout;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(function () {
        updateActiveTab(getCurrentPageFromUrl());
      }, 250);
    });

    loadPage(getCurrentPageFromUrl(), false);
  </script>
  <script>
    (() => {
      const dialogTriggers = new WeakMap();

      document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[aria-haspopup="dialog"], [aria-controls]');
        if (!trigger) return;
        const controlledId = trigger.getAttribute('aria-controls');
        const dialog = controlledId ? document.getElementById(controlledId) : null;
        if (dialog instanceof HTMLDialogElement) {
          dialogTriggers.set(dialog, trigger);
        }
      }, true);

      document.querySelectorAll('dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
          if (event.target === dialog && dialog.open) {
            dialog.close();
          }
        });

        dialog.addEventListener('close', () => {
          const trigger = dialogTriggers.get(dialog);
          if (trigger && document.contains(trigger)) {
            trigger.focus();
          }
        });
      });
    })();
  </script>
</body>
</html>
