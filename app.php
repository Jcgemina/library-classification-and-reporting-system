<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$userRole = strtolower($_SESSION['role'] ?? 'librarian');
$adminPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'user', 'logs', 'configure'];
$staffPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report'];
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
<style>
  html,
  body {
    scrollbar-width: none;
  }

  html::-webkit-scrollbar,
  body::-webkit-scrollbar {
    display: none;
  }
</style>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen overflow-x-hidden bg-[#dfdfdf]">

  <?php require_once __DIR__ . '/includes/nav.php'; ?>

  <script>
    lucide.createIcons();
  </script>

  <div class="<?php echo $userRole === 'admin' ? 'md:ml-64' : ''; ?>">
    <main id="pageContent" class="mx-auto w-full max-w-[1440px] min-w-0 overflow-x-hidden p-6" aria-busy="true"></main>
  </div>

  <script>
    const currentUserRole = <?php echo json_encode($userRole, JSON_THROW_ON_ERROR); ?>;
    const adminPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report', 'user', 'logs', 'configure'];
    const staffPages = ['dashboard', 'inventory', 'academics', 'course', 'reference_analyzer', 'report'];
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

    function updateActiveTab(activePage) {
        const navLinks = document.querySelectorAll('[data-page]');
        const highlight = document.querySelector('.nav-highlight');
        const navTrack = document.querySelector('.nav-track');

        const activeLink = [...navLinks].find(
            link => link.dataset.page === activePage
        );

        navLinks.forEach(link => {
            const isActive = link.dataset.page === activePage;
            const isMobileLink = link.closest('#mobileMenu');
            const isAdminSidebarLink = link.closest('#adminSidebar');

            link.classList.toggle('text-white', isActive);
            link.classList.toggle('text-slate-600', !isActive);
            link.classList.toggle('hover:text-slate-900', !isActive);
            link.classList.toggle('bg-rose-700', isActive && (isMobileLink || isAdminSidebarLink));

            link.classList.remove('text-slate-900');
            link.classList.toggle('shadow-sm', isActive && Boolean(isAdminSidebarLink));
            link.classList.toggle('hover:bg-slate-100', !isActive);

            if (isActive) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });

        if (highlight && activeLink && navTrack) {
            const trackRect = navTrack.getBoundingClientRect();
            const linkRect = activeLink.getBoundingClientRect();
            const offsetLeft = linkRect.left - trackRect.left;
            const offsetTop = linkRect.top - trackRect.top;

          if (!highlight.classList.contains('is-ready')) {
            highlight.style.transition = 'none';
            highlight.style.width = activeLink.offsetWidth + 'px';
            highlight.style.height = linkRect.height + 'px';
            highlight.style.top = offsetTop + 'px';
            highlight.style.transform = `translate3d(${offsetLeft}px, 0, 0)`;

            requestAnimationFrame(function () {
              highlight.classList.add('is-ready');
              highlight.style.transition = '';
            });
          } else {
            requestAnimationFrame(function () {
              highlight.style.width = activeLink.offsetWidth + 'px';
              highlight.style.height = linkRect.height + 'px';
              highlight.style.top = offsetTop + 'px';
              highlight.style.transform = `translate3d(${offsetLeft}px, 0, 0)`;
            });
          }
        }
    }

    function getPageQueryParams(pageName, params) {
      const requestedParams = new URLSearchParams(params);
      requestedParams.delete('page');

      if (pageName === 'logs') {
        return requestedParams;
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

    function loadPage(pageName, updateHistory = true, requestedParams = new URLSearchParams(window.location.search)) {
      const safePage = normalizePage(pageName);
      const pageLabels = {
        dashboard: 'Dashboard',
        inventory: 'Inventory',
        academics: 'Academic Management',
        course: 'Courses',
        reference_analyzer: 'Reference Analyzer',
        report: 'Report',
        user: 'User Management',
        logs: 'Logs',
        configure: 'Library Settings',
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
        const pageTitle = safePage === 'configure'
          ? 'Library Settings'
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
      if (!link) {
        return;
      }

      event.preventDefault();

      if (link.dataset.page === getCurrentPageFromUrl()) {
        return;
      }

      const targetUrl = new URL(link.href, window.location.href);
      loadPage(link.dataset.page, true, targetUrl.searchParams);
    });

    window.addEventListener('popstate', function () {
      loadPage(getCurrentPageFromUrl(), false);
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
