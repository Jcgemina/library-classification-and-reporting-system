<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$allowedPages = [
    'dashboard',
    'inventory',
    'organization',
    'report',
    'user',
];

$pageParam = $_GET['page'] ?? 'dashboard';
$page = in_array($pageParam, $allowedPages, true) ? $pageParam : 'dashboard';
$currentPage = $page;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AppSys Library - Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-gray-50">

  <?php require_once __DIR__ . '/includes/nav.php'; ?>

  <script>
    lucide.createIcons();
  </script>

  <main id="pageContent" class="max-w-4xl mx-auto mt-10 p-6"></main>

  <script>
    const allowedPages = ['dashboard', 'inventory', 'organization', 'report', 'user'];

    function getCurrentPageFromUrl() {
      const params = new URLSearchParams(window.location.search);
      const requestedPage = params.get('page');
      return allowedPages.includes(requestedPage) ? requestedPage : 'dashboard';
    }

    function updateActiveTab(activePage) {
        const navLinks = document.querySelectorAll('[data-page]');
        const highlight = document.querySelector('.nav-highlight');

        const activeLink = [...navLinks].find(
            link => link.dataset.page === activePage
        );

        navLinks.forEach(link => {
            const isActive = link.dataset.page === activePage;

            link.classList.toggle('text-white', isActive);
            link.classList.toggle('text-slate-600', !isActive);
            link.classList.toggle('hover:text-slate-900', !isActive);

            link.classList.remove('shadow-sm');
            link.classList.remove('hover:bg-slate-100');

            if (isActive) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });

        if (highlight && activeLink) {
            highlight.style.width = activeLink.offsetWidth + 'px';
            highlight.style.transform =
                `translateX(${activeLink.offsetLeft}px)`;
        }
    }

    function loadPage(pageName, updateHistory = true) {
      const safePage = allowedPages.includes(pageName) ? pageName : 'dashboard';

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
        document.getElementById('pageContent').innerHTML = html;
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
      loadPage(link.dataset.page, true);
    });

    window.addEventListener('popstate', function () {
      loadPage(getCurrentPageFromUrl(), false);
    });

    loadPage(getCurrentPageFromUrl(), false);
  </script>
</body>
</html>
