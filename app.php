<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$userRole = strtolower($_SESSION['role'] ?? 'librarian');
$adminPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'preferences', 'account', 'user', 'logs', 'configure'];
$staffPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'preferences', 'account'];
$restrictedPages = ['user', 'logs', 'configure'];

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
<title>AppSys Library - Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/preferences.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/preferences.css'); ?>">
<link rel="stylesheet" href="assets/css/settings.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/settings.css'); ?>">
<link rel="stylesheet" href="assets/css/organization.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/organization.css'); ?>">
<style>
  html,
  body {
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    font-synthesis: none;
    text-rendering: optimizeLegibility;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }

  body .text-xs {
    font-size: 0.8125rem;
    line-height: 1.25rem;
  }

  body .text-sm {
    font-size: 0.9375rem;
    line-height: 1.5rem;
  }

  html,
  body {
    scrollbar-width: none;
  }

  @media (min-width: 768px) and (max-width: 1440px) {
    html {
      font-size: 15px;
    }

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
    html {
      font-size: 14.5px;
    }

    #pageContent {
      padding: 1.125rem;
    }
  }

  html::-webkit-scrollbar,
  body::-webkit-scrollbar {
    display: none;
  }
</style>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="assets/css/catalog-reports.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/catalog-reports.css'); ?>">
<link rel="stylesheet" href="assets/css/user.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/user.css'); ?>">
<script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet" href="assets/css/navigation.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/navigation.css'); ?>">
<script>
  window.appUserPreferenceId = <?php echo json_encode((string) ($_SESSION['user_id'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="assets/js/preferences.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/js/preferences.js'); ?>"></script>
</head>
<body class="min-h-screen overflow-x-hidden bg-white">

  <?php require_once __DIR__ . '/includes/nav.php'; ?>
  <div id="appToastContainer" class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3" role="region" aria-label="Notifications"></div>
  <script src="assets/js/toast.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/js/toast.js'); ?>"></script>

  <script>
    lucide.createIcons();
  </script>

  <div id="appMainShell" data-admin-shell="<?php echo $userRole === 'admin' ? 'true' : 'false'; ?>" class="md:ml-[248px]">
    <main id="pageContent" class="mx-auto w-full max-w-[1600px] min-w-0 overflow-x-hidden p-6" aria-busy="true"></main>
  </div>

  <script>
    const currentUserRole = <?php echo json_encode($userRole, JSON_THROW_ON_ERROR); ?>;
    const adminPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'preferences', 'account', 'user', 'logs', 'configure'];
    const staffPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'preferences', 'account'];
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
        const settingsPages = ['account', 'preferences', 'configure'];
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
            ? (urlParams.get('section') === 'rules' ? 'rules' : 'institution')
            : activePage;
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
      const activeSection = section === 'rules' ? 'rules' : 'institution';
      const configurePage = document.querySelector('[data-configure-page]');
      if (!configurePage) {
        return;
      }

      configurePage.dataset.activeSection = activeSection;
      document.getElementById('configurePageHeading').textContent = activeSection === 'rules'
        ? 'Library Rules'
        : 'Institutional Profile';
      document.getElementById('configurePageDescription').textContent = activeSection === 'rules'
        ? 'Set the reporting and coverage rules used by library reports and prospectus records.'
        : 'Manage the institution details shown in report previews.';
    }

    function getPageQueryParams(pageName, params) {
      const requestedParams = new URLSearchParams(params);
      requestedParams.delete('page');

      if (pageName === 'logs') {
        return requestedParams;
      }

      if (pageName === 'configure') {
        const section = requestedParams.get('section');
        return section === 'rules' || section === 'institution'
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
        user: 'User Management',
        logs: 'Logs',
        configure: requestedSection === 'rules' ? 'Library Rules' : 'Institutional Profile',
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

      const pageQuery = preservedParams.toString() ? '?' + preservedParams.toString() : '';

      const pageFile = safePage === 'academics' ? 'organization' : safePage;
      pageContainer.setAttribute('aria-busy', 'true');
      pageContainer.innerHTML = `<div class="rounded-2xl bg-white p-8 text-sm text-slate-600" role="status">Loading ${pageLabel}…</div>`;

      fetch('pages/' + pageFile + '.php' + pageQuery, {
        method: 'GET',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
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
        lucide.createIcons();

        const scripts = [...pageContainer.querySelectorAll('script')];
        scripts.forEach((oldScript) => {
          const newScript = document.createElement('script');
          Array.from(oldScript.attributes).forEach((attr) => {
            newScript.setAttribute(attr.name, attr.value);
          });
          newScript.textContent = oldScript.textContent;
          oldScript.replaceWith(newScript);
        });

        updateActiveTab(safePage);
        scrollToRouteAnchor(targetHash, updateHistory ? 'smooth' : 'auto');
        const pageTitle = safePage === 'preferences'
          ? 'Preferences'
          : safePage === 'account'
          ? 'My Account'
          : safePage === 'configure'
          ? (requestedSection === 'rules' ? 'Library Rules' : 'Institutional Profile')
          : safePage === 'reference_analyzer'
            ? 'Reference Analyzer'
            : safePage.charAt(0).toUpperCase() + safePage.slice(1);
        document.title = 'AppSys Library - ' + pageTitle;
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
        document.title = 'AppSys Library - ' + (preservedParams.get('section') === 'rules' ? 'Library Rules' : 'Institutional Profile');
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
        document.title = 'AppSys Library - ' + (new URLSearchParams(window.location.search).get('section') === 'rules' ? 'Library Rules' : 'Institutional Profile');
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
</body>
</html>
