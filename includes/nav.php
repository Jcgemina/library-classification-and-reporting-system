<?php
$currentPage = $_GET['page'] ?? 'dashboard';
$settingsPages = ['account', 'preferences', 'configure'];
$isActiveSettings = in_array($currentPage, $settingsPages, true);
$activeSettingsSubpage = $currentPage === 'configure'
    ? (($_GET['section'] ?? '') === 'rules' ? 'rules' : 'institution')
    : $currentPage;
$settingsExpanded = $isActiveSettings;
$activeLogTab = ($_GET['log_tab'] ?? '') === 'security' ? 'security' : 'activity';
$logsExpanded = $currentPage === 'logs';

$userRole = strtolower($_SESSION['role'] ?? 'librarian');
$isValidatedUser = !empty($_SESSION['authenticated']) && !empty($_SESSION['user_id']);
$isAdmin = $isValidatedUser && $userRole === 'admin';
$settingsItems = [
    ['label' => 'My Account', 'group' => 'Personal', 'page' => 'account', 'subpage' => 'account', 'href' => 'app.php?page=account', 'icon' => 'user-round'],
    ['label' => 'Preferences', 'group' => 'Personal', 'page' => 'preferences', 'subpage' => 'preferences', 'href' => 'app.php?page=preferences', 'icon' => 'sliders-horizontal'],
    ['label' => 'Institutional Profile', 'group' => 'Administration', 'page' => 'configure', 'subpage' => 'institution', 'href' => 'app.php?page=configure&section=institution#institutionProfileSettings', 'icon' => 'building-2'],
    ['label' => 'Library Rules', 'group' => 'Administration', 'page' => 'configure', 'subpage' => 'rules', 'href' => 'app.php?page=configure&section=rules#libraryRulesSettings', 'icon' => 'list-checks'],
    ['label' => 'Backup & Restore', 'group' => 'Administration', 'page' => 'configure', 'subpage' => 'backup', 'href' => 'app.php?page=configure&section=backup#backupRestoreSettings', 'icon' => 'database-backup'],
];
if (!$isAdmin) {
    $settingsItems = array_values(array_filter($settingsItems, static function ($item) {
        return $item['page'] !== 'configure';
    }));
}
$navSections = [
    'overview' => 'Overview',
    'operations' => 'Catalog & Reporting',
    'academics' => 'Academic Structure',
    'administration' => 'Administration',
];
$navItems = [
    ['label' => 'Dashboard', 'page' => 'dashboard', 'section' => 'overview', 'href' => 'app.php?page=dashboard', 'icon' => 'layout-dashboard'],
    ['label' => 'Inventory', 'page' => 'inventory', 'section' => 'operations', 'href' => 'app.php?page=inventory', 'icon' => 'box'],
    ['label' => 'Reports', 'page' => 'report', 'section' => 'operations', 'href' => 'app.php?page=report', 'icon' => 'file-bar-chart'],
    ['label' => 'Academic Structure', 'page' => 'academics', 'section' => 'academics', 'href' => 'app.php?page=academics', 'icon' => 'building-2'],
    ['label' => 'Course Catalog', 'page' => 'course', 'section' => 'academics', 'href' => 'app.php?page=course', 'icon' => 'book-open'],
    ['label' => 'Reference Age', 'page' => 'reference_analyzer', 'section' => 'academics', 'href' => 'app.php?page=reference_analyzer', 'icon' => 'scan-search'],
    ['label' => 'Users', 'page' => 'user', 'section' => 'administration', 'href' => 'app.php?page=user', 'icon' => 'users'],
    ['label' => 'Logs', 'page' => 'logs', 'section' => 'administration', 'href' => 'app.php?page=logs', 'icon' => 'scroll-text', 'isLogs' => true],
    ['label' => 'Settings', 'page' => 'account', 'section' => 'administration', 'href' => 'app.php?page=account', 'icon' => 'settings-2', 'isSettings' => true],
];

if (!$isValidatedUser || $userRole !== 'admin') {
    $adminPages = ['user', 'logs', 'configure'];
    $navItems = array_values(array_filter($navItems, static function ($item) use ($adminPages) {
        return !in_array($item['page'], $adminPages, true);
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
    gap: 0.75rem;
  }

  .nav-section {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.125rem;
  }

  .nav-section + .nav-section {
    border-left: 1px solid #e2e8f0;
    padding-left: 0.75rem;
  }

  .nav-section-label {
    display: flex;
    width: 100%;
    align-items: center;
    gap: 0.5rem;
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    line-height: 1;
    text-transform: uppercase;
    white-space: nowrap;
  }

  .nav-section-label::before,
  .nav-section-label::after {
    flex: 1;
    border-top: 1px solid #cbd5e1;
    content: "";
  }

  .nav-section-links {
    display: flex;
    align-items: center;
    gap: 0.125rem;
  }

  .nav-highlight {
    position: absolute;
    top: 0;
    left: 0;
    height: 0;
    border-radius: 0.75rem;
    background: #be123c;
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

  .settings-nav-item {
    position: relative;
  }

  .nav-section-links > .settings-nav-item {
    display: flex;
    align-items: center;
  }

  .settings-nav-toggle[aria-expanded="true"] [data-settings-chevron] {
    transform: rotate(180deg);
  }

  .settings-submenu {
    display: grid;
    gap: 0.25rem;
  }

  .settings-submenu[hidden] {
    display: none;
  }

  .settings-submenu-desktop {
    position: absolute;
    top: calc(100% + 0.5rem);
    right: 0;
    z-index: 60;
    min-width: 13rem;
    padding: 0.375rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    background: #fff;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.16);
  }

  .settings-submenu-link {
    display: flex;
    min-height: 2.25rem;
    align-items: center;
    gap: 0.625rem;
    border-radius: 0.5rem;
    padding: 0.5rem 0.625rem;
    color: #475569;
    font-size: 0.875rem;
    font-weight: 500;
    transition: background-color 0.18s ease, color 0.18s ease;
  }

  .settings-submenu-link:hover {
    background: #f1f5f9;
    color: #0f172a;
  }

  #adminSidebar nav > a[data-page],
  #adminSidebar nav .settings-nav-row > [data-settings-parent] {
    font-size: 0.9375rem;
    line-height: 1.5rem;
  }

  html[data-app-text-size="large"] #adminSidebar .settings-submenu-link,
  html[data-app-text-size="large"] #mobileMenu .settings-submenu-link {
    font-size: 0.9375rem;
  }

  .settings-submenu-link [data-lucide] {
    width: 1rem;
    height: 1rem;
    flex-shrink: 0;
  }

  .settings-submenu-mobile {
    margin: 0.125rem 0 0.5rem 1rem;
    border-left: 1px solid #cbd5e1;
    padding-left: 0.625rem;
  }

  .settings-submenu-sidebar {
    margin: 0 0 0.5rem 1rem;
    border-left: 1px solid #cbd5e1;
    padding-left: 0.625rem;
  }

  .settings-submenu-group-label {
    margin: 0.25rem 0.625rem 0.125rem;
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .settings-submenu-mobile .settings-submenu-link,
  .settings-submenu-sidebar .settings-submenu-link {
    position: relative;
  }

  .settings-submenu-mobile .settings-submenu-link::before,
  .settings-submenu-sidebar .settings-submenu-link::before {
    position: absolute;
    top: 50%;
    left: -0.625rem;
    width: 0.625rem;
    border-top: 1px solid #cbd5e1;
    content: "";
  }

  #adminSidebar a[data-page]:not([data-settings-child])[aria-current="page"],
  #adminSidebar [data-settings-parent][aria-current="location"],
  #mobileMenu a[data-page]:not([data-settings-child])[aria-current="page"],
  #mobileMenu [data-settings-parent][aria-current="location"] {
    background: #f7f4f4;
    box-shadow: inset 0 0 18px rgba(190, 18, 60, 0.08);
    color: #30272a;
    font-weight: 650;
  }

  #adminSidebar a[data-page]:not([data-settings-child]):not([data-log-tab])[aria-current="page"]::before,
  #adminSidebar [data-settings-parent][aria-current="location"]::before,
  #mobileMenu a[data-page]:not([data-settings-child]):not([data-log-tab])[aria-current="page"]::before,
  #mobileMenu [data-settings-parent][aria-current="location"]::before {
    position: absolute;
    top: 5px;
    bottom: 5px;
    left: -7px;
    width: 3px;
    border-radius: 3px;
    background: #be123c;
    content: "";
  }

  #adminSidebar a[data-page]:not([data-settings-child])[aria-current="page"] [data-lucide],
  #adminSidebar [data-settings-parent][aria-current="location"] [data-lucide],
  #mobileMenu a[data-page]:not([data-settings-child])[aria-current="page"] [data-lucide],
  #mobileMenu [data-settings-parent][aria-current="location"] [data-lucide] {
    color: #9f1239;
  }

  .settings-nav-row {
    display: flex;
    align-items: center;
    gap: 0.125rem;
  }

  .settings-nav-row > [data-settings-parent] {
    flex: 1;
  }

  .settings-nav-row > .settings-nav-toggle {
    flex-shrink: 0;
  }

  .settings-nav-item > .settings-nav-row.is-current:not([data-settings-layout="desktop"]) {
    border-radius: 0.5rem;
    background: #f7f4f4;
    box-shadow: inset 0 0 18px rgba(190, 18, 60, 0.08);
    color: #30272a;
  }

  .settings-nav-item > .settings-nav-row.is-current > [data-settings-parent] {
    color: #30272a;
  }

  .admin-sidebar {
    transition: transform 0.24s ease;
  }

  #logoutConfirmDialog::backdrop {
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(2px);
  }

  @media (min-width: 768px) and (max-width: 1024px) {
    .nav-track {
      position: relative;
      left: auto;
      flex: 1;
      min-width: 0;
      justify-content: flex-start;
      overflow-x: hidden;
      overscroll-behavior-x: none;
      scrollbar-width: thin;
      transform: none;
    }

    .nav-section {
      flex-shrink: 0;
    }

    .nav-section + .nav-section {
      padding-left: 0.5rem;
    }

    .nav-item {
      padding-inline: 0.5rem;
    }

    .settings-submenu-desktop {
      position: fixed;
      top: 5rem;
      right: 1rem;
    }
  }

  @media (max-width: 767px) {
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
<aside id="adminSidebar" class="admin-sidebar fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white shadow-xl md:translate-x-0 md:shadow-none" aria-label="<?php echo $isAdmin ? 'Admin navigation' : 'Library navigation'; ?>">
  <div class="flex h-[76px] items-center gap-3 border-b border-slate-200 px-5">
    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white shadow-sm">
      <img src="assets/images/library-system-logo.png?v=2" alt="" class="h-full w-full object-contain">
    </div>
    <div class="min-w-0 leading-tight">
      <h1 class="truncate text-base font-bold text-slate-900">AppSys Library</h1>
      <p class="truncate text-xs font-semibold uppercase tracking-[0.12em] text-slate-500"><?php echo $isAdmin ? 'Admin Portal' : 'Librarian Portal'; ?></p>
    </div>
  </div>

  <nav class="flex-1 overflow-y-auto px-3 py-5" aria-label="Main navigation">
    <?php $activeSection = null; ?>
    <?php foreach ($navItems as $item): ?>
      <?php if ($activeSection !== $item['section']): ?>
        <?php $activeSection = $item['section']; ?>
        <p class="nav-section-label <?php echo $activeSection === 'overview' ? '' : 'mt-5'; ?> mb-2 px-3 text-xs font-bold tracking-[0.12em]"><?php echo htmlspecialchars($navSections[$activeSection], ENT_QUOTES, 'UTF-8'); ?></p>
      <?php endif; ?>
      <?php $isActive = !empty($item['isSettings']) ? $isActiveSettings : (!empty($item['isLogs']) ? $logsExpanded : $item['page'] === $currentPage); ?>
      <?php if (!empty($item['isLogs'])): ?>
        <div class="logs-nav-item">
          <button type="button" data-page="logs" data-logs-parent data-logs-toggle data-logs-menu="adminLogsMenu" aria-controls="adminLogsMenu" aria-expanded="<?php echo $logsExpanded ? 'true' : 'false'; ?>" aria-label="<?php echo $logsExpanded ? 'Collapse Logs submenu' : 'Expand Logs submenu'; ?>" class="logs-toggle <?php echo $isActive ? 'is-current' : ''; ?>" <?php echo $isActive ? 'aria-current="location"' : ''; ?>>
            <i data-lucide="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            <i class="logs-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
          </button>
          <div id="adminLogsMenu" class="logs-menu" data-logs-menu-content <?php echo $logsExpanded ? '' : 'hidden'; ?>>
            <a href="app.php?page=logs&amp;log_tab=activity" data-page="logs" data-log-tab="activity" class="settings-submenu-link logs-link" <?php echo $isActive && $activeLogTab === 'activity' ? 'aria-current="page"' : ''; ?>><i data-lucide="activity" aria-hidden="true"></i><span>Activity logs</span></a>
            <a href="app.php?page=logs&amp;log_tab=security" data-page="logs" data-log-tab="security" class="settings-submenu-link logs-link" <?php echo $isActive && $activeLogTab === 'security' ? 'aria-current="page"' : ''; ?>><i data-lucide="shield-check" aria-hidden="true"></i><span>Security logs</span></a>
          </div>
        </div>
      <?php elseif (!empty($item['isSettings'])): ?>
        <div class="settings-nav-item">
          <div class="settings-nav-row <?php echo $isActive ? 'is-current' : ''; ?>">
            <button type="button"
              data-page="<?php echo htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8'); ?>"
              data-settings-parent="true"
              data-settings-toggle
              data-settings-menu="adminSettingsMenu"
              aria-controls="adminSettingsMenu"
              aria-expanded="<?php echo $settingsExpanded ? 'true' : 'false'; ?>"
              aria-label="<?php echo $settingsExpanded ? 'Collapse Settings submenu' : 'Expand Settings submenu'; ?>"
              class="settings-nav-toggle mb-1 flex w-full items-center justify-start gap-3 rounded-lg px-3 py-2.5 text-base font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-rose-300 <?php echo $isActive ? 'text-slate-900' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'; ?>"
              <?php echo $isActive ? 'aria-current="location"' : ''; ?>>
              <i data-lucide="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>" class="h-4 w-4 flex-shrink-0"></i>
              <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
              <i data-lucide="chevron-down" data-settings-chevron class="ml-auto h-4 w-4 transition-transform" aria-hidden="true"></i>
            </button>
          </div>
          <div id="adminSettingsMenu" class="settings-submenu settings-submenu-sidebar" data-settings-menu-content <?php echo $settingsExpanded ? '' : 'hidden'; ?>>
            <?php $activeSettingsGroup = null; ?>
            <?php foreach ($settingsItems as $settingsItem): ?>
              <?php if ($activeSettingsGroup !== $settingsItem['group']): ?>
                <?php $activeSettingsGroup = $settingsItem['group']; ?>
                <p class="settings-submenu-group-label"><?php echo htmlspecialchars($activeSettingsGroup, ENT_QUOTES, 'UTF-8'); ?></p>
              <?php endif; ?>
              <?php $isSettingsItemActive = $settingsItem['subpage'] === $activeSettingsSubpage; ?>
              <a href="<?php echo htmlspecialchars($settingsItem['href'], ENT_QUOTES, 'UTF-8'); ?>"
                data-page="<?php echo htmlspecialchars($settingsItem['page'], ENT_QUOTES, 'UTF-8'); ?>"
                data-settings-child="<?php echo htmlspecialchars($settingsItem['subpage'], ENT_QUOTES, 'UTF-8'); ?>"
                class="settings-submenu-link"
                <?php echo $isSettingsItemActive ? 'aria-current="page"' : ''; ?>>
                <i data-lucide="<?php echo htmlspecialchars($settingsItem['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($settingsItem['label'], ENT_QUOTES, 'UTF-8'); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
      <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
        data-page="<?php echo htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8'); ?>"
        class="mb-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-base font-medium transition-colors <?php echo $isActive ? 'bg-rose-700 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'; ?>"
        <?php echo $isActive ? 'aria-current="page"' : ''; ?>>
        <i data-lucide="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>" class="h-4 w-4 flex-shrink-0"></i>
        <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="border-t border-slate-200 p-4">
    <p class="truncate text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="mt-2 flex items-center justify-between gap-3">
      <span class="text-sm capitalize text-slate-500"><?php echo htmlspecialchars($_SESSION['role'] ?? 'admin', ENT_QUOTES, 'UTF-8'); ?></span>
        <button type="button" data-logout-trigger class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-300">
        <i data-lucide="log-out" class="h-3.5 w-3.5"></i>
        <span>Logout</span>
      </button>
    </div>
  </div>
</aside>

<form id="logoutForm" action="logout.php" method="post" hidden>
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
</form>

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
document.querySelectorAll('[data-settings-toggle]').forEach(toggle => {
    toggle.addEventListener('click', function () {
        const menu = document.getElementById(this.dataset.settingsMenu);
        const isOpen = menu.hidden;
        menu.hidden = !isOpen;
        this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        this.setAttribute('aria-label', isOpen ? 'Collapse Settings submenu' : 'Expand Settings submenu');
    });
});

document.querySelectorAll('[data-logs-toggle]').forEach(toggle => {
    toggle.addEventListener('click', function () {
        const menu = document.getElementById(this.dataset.logsMenu);
        const isOpen = menu.hidden;
        menu.hidden = !isOpen;
        this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        this.setAttribute('aria-label', isOpen ? 'Collapse Logs submenu' : 'Expand Logs submenu');
    });
});

const adminSidebar = document.getElementById('adminSidebar');
const adminSidebarToggle = document.getElementById('adminSidebarToggle');
const adminSidebarBackdrop = document.getElementById('adminSidebarBackdrop');

function setAdminSidebarOpen(isOpen) {
  if (!adminSidebar || !adminSidebarToggle || !adminSidebarBackdrop) {
    return;
  }

  adminSidebar.classList.toggle('is-open', isOpen);
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
document.querySelectorAll('#adminSidebar a[data-page]:not([data-settings-parent])').forEach(link => {
  link.addEventListener('click', function () {
    setAdminSidebarOpen(false);
  });
});

const logoutDialog = document.getElementById('logoutConfirmDialog');
const cancelLogoutButton = document.getElementById('cancelLogoutButton');
const confirmLogoutButton = document.getElementById('confirmLogoutButton');
const logoutForm = document.getElementById('logoutForm');
let logoutTrigger = null;

document.querySelectorAll('[data-logout-trigger]').forEach(button => {
  button.addEventListener('click', function () {
    logoutTrigger = this;
    logoutDialog.showModal();
    cancelLogoutButton.focus();
  });
});

cancelLogoutButton.addEventListener('click', () => logoutDialog.close());
confirmLogoutButton.addEventListener('click', () => logoutForm.requestSubmit());
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