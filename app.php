<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$userRole = strtolower($_SESSION['role'] ?? 'librarian');
$allPages = [
    'dashboard',
    'inventory',
    'organization',
    'report',
    'user',
    'logs',
];

$allowedPages = $userRole === 'admin'
    ? $allPages
    : ['dashboard', 'inventory', 'organization', 'report'];

$pageParam = $_GET['page'] ?? 'dashboard';

if ($userRole !== 'admin' && in_array($pageParam, ['user', 'logs'], true)) {
    header('Location: app.php?page=dashboard');
    exit;
}

$page = in_array($pageParam, $allowedPages, true) ? $pageParam : 'dashboard';
$currentPage = $page;
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

  <main id="pageContent" class="mx-auto w-full max-w-[1440px] min-w-0 overflow-x-hidden p-6"></main>

  <script>
    const currentUserRole = <?php echo json_encode($userRole, JSON_THROW_ON_ERROR); ?>;
    const allowedPages = currentUserRole === 'admin'
      ? ['dashboard', 'inventory', 'organization', 'report', 'user', 'logs']
      : ['dashboard', 'inventory', 'organization', 'report'];

    function getCurrentPageFromUrl() {
      const params = new URLSearchParams(window.location.search);
      const requestedPage = params.get('page');

      if (currentUserRole !== 'admin' && ['user', 'logs'].includes(requestedPage)) {
        return 'dashboard';
      }

      return allowedPages.includes(requestedPage) ? requestedPage : 'dashboard';
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

            link.classList.toggle('text-white', isActive);
            link.classList.toggle('text-slate-600', !isActive);
            link.classList.toggle('hover:text-slate-900', !isActive);

            link.classList.remove('text-slate-900');
            link.classList.remove('shadow-sm');
            link.classList.remove('hover:bg-slate-100');

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

          if (!highlight.classList.contains('is-ready')) {
            highlight.style.transition = 'none';
            highlight.style.width = activeLink.offsetWidth + 'px';
            highlight.style.transform = `translate3d(${offsetLeft}px, 0, 0)`;

            requestAnimationFrame(function () {
              highlight.classList.add('is-ready');
              highlight.style.transition = '';
            });
          } else {
            requestAnimationFrame(function () {
              highlight.style.width = activeLink.offsetWidth + 'px';
              highlight.style.transform = `translate3d(${offsetLeft}px, 0, 0)`;
            });
          }
        }
    }

    function loadPage(pageName, updateHistory = true) {
      const safePage = currentUserRole !== 'admin' && ['user', 'logs'].includes(pageName)
        ? 'dashboard'
        : allowedPages.includes(pageName) ? pageName : 'dashboard';

      if (updateHistory) {
        const url = new URL(window.location.href);
        url.searchParams.set('page', safePage);
        history.pushState({ page: safePage }, '', url);
      }

      fetch('pages/' + safePage + '.php', {
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
        const pageContainer = document.getElementById('pageContent');
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
        document.title = 'AppSys Library - ' + safePage.charAt(0).toUpperCase() + safePage.slice(1);
      })
      .catch(() => {
        document.getElementById('pageContent').innerHTML = '<div class="bg-white rounded-2xl shadow-md p-8"><h2 class="text-2xl font-bold text-slate-900 mb-2">Page unavailable</h2><p class="text-slate-500">The requested module could not be loaded.</p></div>';
      });
    }

    document.addEventListener('click', function (event) {
      const link = event.target.closest('[data-page]');
      if (!link) {
        return;
      }

      event.preventDefault();

      if (link.dataset.page === getCurrentPageFromUrl()) {
        return;
      }

      loadPage(link.dataset.page, true);
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
