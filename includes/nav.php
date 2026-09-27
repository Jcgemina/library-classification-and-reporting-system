<?php
if (!isset($currentPage)) {
    $currentPage = $_GET['page'] ?? 'dashboard';
}

$userRole = strtolower($_SESSION['role'] ?? 'librarian');
$isValidatedUser = !empty($_SESSION['authenticated']) && !empty($_SESSION['user_id']);
$isAdmin = $isValidatedUser && $userRole === 'admin';
$navItems = [
    ['label' => 'Dashboard', 'page' => 'dashboard', 'href' => 'app.php?page=dashboard', 'icon' => 'layout-dashboard'],
    ['label' => 'Inventory', 'page' => 'inventory', 'href' => 'app.php?page=inventory', 'icon' => 'box'],
    ['label' => 'Academics', 'page' => 'academics', 'href' => 'app.php?page=academics', 'icon' => 'building-2'],
    ['label' => 'Courses', 'page' => 'course', 'href' => 'app.php?page=course', 'icon' => 'book-open'],
    ['label' => 'Report', 'page' => 'report', 'href' => 'app.php?page=report', 'icon' => 'file-bar-chart'],
    ['label' => 'User', 'page' => 'user', 'href' => 'app.php?page=user', 'icon' => 'users'],
    ['label' => 'Logs', 'page' => 'logs', 'href' => 'app.php?page=logs', 'icon' => 'scroll-text'],
];

if (!$isValidatedUser || $userRole !== 'admin') {
    $navItems = array_values(array_filter($navItems, static function ($item) {
        return $item['page'] !== 'user';
    }));
}

if (!$isValidatedUser || $userRole !== 'admin') {
  $navItems = array_values(array_filter($navItems, static function ($item) {
    return $item['page'] !== 'logs';
  }));
}

if (!$isValidatedUser) {
  $navItems = [];
}

$profileInitials = strtoupper(substr($_SESSION['full_name'] ?? 'L', 0, 1));
?>
<style>
  .nav-track {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
  }

  .nav-highlight {
    position: absolute;
    top: 0.15rem;
    left: 0;
    height: calc(100% - 0.3rem);
    border-radius: 0.75rem;
    background: #f43f5e;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
    opacity: 0;
    will-change: transform, width;
    transition: transform 0.42s cubic-bezier(0.22, 1, 0.36, 1), width 0.42s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.2s ease;
    z-index: 0;
  }

  .nav-highlight.is-ready {
    opacity: 1;
  }

  [data-page] {
    position: relative;
    z-index: 1;
    transition: background-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
  }

  .nav-label {
    display: inline;
  }

  .admin-sidebar {
    transition: transform 0.24s ease;
  }

  #logoutConfirmDialog::backdrop {
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(2px);
  }

  @media (max-width: 1024px) {
    .nav-label {
      display: none;
    }
  }

  @media (max-width: 768px) {
    .nav-track {
      display: none;
    }

    .mobile-nav-menu {
      position: fixed;
      top: 52px;
      right: 0;
      left: 0;
      z-index: 39;
      max-height: calc(100vh - 52px);
      overflow-y: auto;
      box-shadow: 0 12px 24px rgba(15, 23, 42, 0.16);
    }
  }
</style>

<?php if (!$isAdmin): ?>
<nav class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-[0_6px_20px_rgba(15,23,42,0.20)]">
  <div class="relative w-full px-2 md:px-6 py-2 md:py-4 flex items-center justify-between gap-3">

    <div class="flex min-w-0 items-center gap-1.5 md:gap-3">
    <!-- Mobile menu button (left) -->
    <button
      type="button"
      id="mobileMenuButton"
      class="md:hidden flex items-center justify-center
          w-9 h-9 rounded-lg text-slate-700 hover:bg-slate-100
          transition-colors flex-shrink-0"
      aria-label="Open navigation menu"
      aria-expanded="false"
    >
      <i data-lucide="menu" class="w-5 h-5"></i>
    </button>

    <!-- Logo section (left) -->
    <div class="flex items-center gap-1.5 md:gap-3 flex-shrink-0 min-w-0">
      <div class="w-8 h-8 md:w-10 md:h-10 rounded-lg md:rounded-xl bg-white flex items-center justify-center shadow-sm flex-shrink-0 overflow-hidden">
        <img src="assets/images/library-system-logo.png" alt="AppSys Library logo" class="h-full w-full scale-[2.5] object-contain">
      </div>

      <div class="leading-tight min-w-0">
        <h1 class="text-xs sm:text-sm md:text-lg font-bold text-slate-900 whitespace-nowrap truncate">AppSys Library</h1>
        <p class="text-[7px] sm:text-[8px] md:text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500 whitespace-nowrap truncate">Librarian Management Portal</p>
      </div>
    </div>
    </div>

    <!-- Navigation track (center) - hidden on mobile -->
    <div class="nav-track hidden md:flex items-center gap-0.5 lg:gap-3 text-xs lg:text-sm font-medium">
      <div class="nav-highlight"></div>
      <?php foreach ($navItems as $item): ?>
        <?php $isActive = $item['page'] === $currentPage; ?>
        <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
          data-page="<?php echo htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8'); ?>"
          class="nav-item relative flex items-center justify-center gap-1 lg:gap-2 px-2 lg:px-4 py-1.5 md:py-2 rounded-lg whitespace-nowrap
                  <?php echo $isActive ? 'text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'; ?>"
          <?php echo $isActive ? 'aria-current="page"' : ''; ?>>
          <i data-lucide="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>" class="w-4 h-4 flex-shrink-0"></i>
          <span class="nav-label"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- User menu (right) -->
    <div class="relative flex items-center justify-end flex-shrink-0">
      <div class="relative group">
        <button
            type="button"
            class="w-8 h-8 md:w-11 md:h-11 rounded-full bg-rose-200 text-rose-800 border-2 border-rose-400 flex items-center justify-center font-semibold shadow-sm text-xs md:text-sm hover:bg-rose-300 hover:border-rose-500 transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-rose-200"
            aria-label="User menu"
        >
            <?php echo htmlspecialchars($profileInitials, ENT_QUOTES, 'UTF-8'); ?>
        </button>

        <div class="absolute right-0 top-full mt-2 w-44 bg-white border border-slate-200 rounded-xl shadow-lg opacity-0 invisible group-hover:visible group-hover:opacity-100 transition-all duration-200 ease-in-out z-10">
          <div class="px-4 py-3 border-b border-slate-100">
            <p class="font-semibold text-slate-900 text-sm"><?php echo htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="text-xs text-slate-500"><?php echo htmlspecialchars($_SESSION['role'], ENT_QUOTES, 'UTF-8'); ?></p>
          </div>
          <a href="#" class="block px-4 py-2 text-sm text-slate-700 rounded-lg mx-1 hover:bg-slate-900 hover:text-white transition-colors duration-200 ease-in-out">Settings</a>
          <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 rounded-lg mx-1 hover:bg-slate-900 hover:text-red-600 transition-colors duration-200 ease-in-out">Logout</a>
        </div>
      </div>
    </div>
  </div>
</nav>

<!-- Mobile navigation menu -->
<div id="mobileMenu" class="mobile-nav-menu hidden md:hidden border-t border-slate-200 bg-white px-3 py-3">
  <div class="flex flex-col gap-1">
    <?php foreach ($navItems as $item): ?>
      <?php $isActive = $item['page'] === $currentPage; ?>
      <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
        data-page="<?php echo htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8'); ?>"
        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium <?php echo $isActive ? 'bg-rose-500 text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'; ?>">
        <i data-lucide="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>" class="w-4 h-4"></i>
        <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-sm md:hidden">
  <button
    type="button"
    id="adminSidebarToggle"
    class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-rose-300"
    aria-label="Open navigation menu"
    aria-expanded="false"
    aria-controls="adminSidebar"
  >
    <i data-lucide="menu" class="h-5 w-5"></i>
  </button>
  <span class="text-sm font-semibold text-slate-900">AppSys Library</span>
  <span class="w-9" aria-hidden="true"></span>
</div>

<div id="adminSidebarBackdrop" class="fixed inset-0 z-40 hidden bg-slate-950/40 md:hidden" aria-hidden="true"></div>
<aside id="adminSidebar" class="admin-sidebar fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white shadow-xl md:translate-x-0 md:shadow-none" aria-label="Admin navigation">
  <div class="flex h-[76px] items-center gap-3 border-b border-slate-200 px-5">
    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white shadow-sm">
      <img src="assets/images/library-system-logo.png" alt="" class="h-full w-full scale-[2.5] object-contain">
    </div>
    <div class="min-w-0 leading-tight">
      <h1 class="truncate text-sm font-bold text-slate-900">AppSys Library</h1>
      <p class="truncate text-[9px] font-semibold uppercase tracking-[0.12em] text-slate-500">Admin Portal</p>
    </div>
  </div>

  <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-5" aria-label="Main navigation">
    <?php foreach ($navItems as $item): ?>
      <?php $isActive = $item['page'] === $currentPage; ?>
      <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
        data-page="<?php echo htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8'); ?>"
        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors <?php echo $isActive ? 'bg-rose-500 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'; ?>"
        <?php echo $isActive ? 'aria-current="page"' : ''; ?>>
        <i data-lucide="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>" class="h-4 w-4 flex-shrink-0"></i>
        <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="border-t border-slate-200 p-4">
    <p class="truncate text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="mt-2 flex items-center justify-between gap-3">
      <span class="text-xs capitalize text-slate-500"><?php echo htmlspecialchars($_SESSION['role'] ?? 'admin', ENT_QUOTES, 'UTF-8'); ?></span>
      <a href="logout.php" class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-300">
        <i data-lucide="log-out" class="h-3.5 w-3.5"></i>
        <span>Logout</span>
      </a>
    </div>
  </div>
</aside>
<?php endif; ?>

<dialog id="logoutConfirmDialog" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-left text-slate-900 shadow-2xl" aria-labelledby="logoutConfirmTitle" aria-describedby="logoutConfirmMessage">
  <div class="flex items-start gap-4">
    <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-700">
      <i data-lucide="log-out" class="h-5 w-5"></i>
    </div>
    <div class="min-w-0">
      <h2 id="logoutConfirmTitle" class="text-lg font-bold text-slate-900">Log out of AppSys Library?</h2>
      <p id="logoutConfirmMessage" class="mt-1 text-sm text-slate-600">You will need to sign in again to access the portal.</p>
    </div>
  </div>
  <div class="mt-6 flex justify-end gap-3">
    <button type="button" id="cancelLogoutButton" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-300">Cancel</button>
    <button type="button" id="confirmLogoutButton" class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-300">
      <i data-lucide="log-out" class="h-4 w-4"></i>
      <span>Log out</span>
    </button>
  </div>
</dialog>

<script>
document.getElementById('mobileMenuButton')?.addEventListener('click', function () {
    const menu = document.getElementById('mobileMenu');
    const icon = this.querySelector('[data-lucide]');
    
    menu.classList.toggle('hidden');
    this.setAttribute('aria-expanded', menu.classList.contains('hidden') ? 'false' : 'true');
    
    if (menu.classList.contains('hidden')) {
        icon.setAttribute('data-lucide', 'menu');
    } else {
        icon.setAttribute('data-lucide', 'x');
    }
    
    lucide.createIcons();
});

// Close menu when a link is clicked
document.querySelectorAll('#mobileMenu a').forEach(link => {
    link.addEventListener('click', function () {
        const menu = document.getElementById('mobileMenu');
        const button = document.getElementById('mobileMenuButton');
        const icon = button.querySelector('[data-lucide]');
        
        menu.classList.add('hidden');
        button.setAttribute('aria-expanded', 'false');
        icon.setAttribute('data-lucide', 'menu');
        lucide.createIcons();
    });
});

const adminSidebar = document.getElementById('adminSidebar');
const adminSidebarToggle = document.getElementById('adminSidebarToggle');
const adminSidebarBackdrop = document.getElementById('adminSidebarBackdrop');

function setAdminSidebarOpen(isOpen) {
  if (!adminSidebar || !adminSidebarToggle || !adminSidebarBackdrop) {
    return;
  }

  adminSidebar.classList.toggle('-translate-x-full', !isOpen);
  adminSidebarBackdrop.classList.toggle('hidden', !isOpen);
  adminSidebarToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  adminSidebarToggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
  const icon = adminSidebarToggle.querySelector('[data-lucide]');
  icon.setAttribute('data-lucide', isOpen ? 'x' : 'menu');
  lucide.createIcons();
}

adminSidebarToggle?.addEventListener('click', function () {
  setAdminSidebarOpen(this.getAttribute('aria-expanded') !== 'true');
});
adminSidebarBackdrop?.addEventListener('click', function () {
  setAdminSidebarOpen(false);
});
document.querySelectorAll('#adminSidebar a[data-page]').forEach(link => {
  link.addEventListener('click', function () {
    setAdminSidebarOpen(false);
  });
});

const logoutDialog = document.getElementById('logoutConfirmDialog');
const cancelLogoutButton = document.getElementById('cancelLogoutButton');
const confirmLogoutButton = document.getElementById('confirmLogoutButton');
let logoutDestination = 'logout.php';
let logoutTrigger = null;

document.querySelectorAll('a[href="logout.php"]').forEach(link => {
  link.addEventListener('click', function (event) {
    event.preventDefault();
    logoutDestination = this.href;
    logoutTrigger = this;
    logoutDialog.showModal();
    cancelLogoutButton.focus();
  });
});

cancelLogoutButton.addEventListener('click', () => logoutDialog.close());
confirmLogoutButton.addEventListener('click', () => window.location.assign(logoutDestination));
logoutDialog.addEventListener('click', event => {
  if (event.target === logoutDialog) {
    logoutDialog.close();
  }
});
logoutDialog.addEventListener('close', () => {
  logoutTrigger?.focus();
  logoutTrigger = null;
});
</script>