<?php
if (!isset($currentPage)) {
    $currentPage = $_GET['page'] ?? 'dashboard';
}

$userRole = strtolower($_SESSION['role'] ?? 'librarian');
$isValidatedUser = !empty($_SESSION['authenticated']) && !empty($_SESSION['user_id']);
$navItems = [
    ['label' => 'Dashboard', 'page' => 'dashboard', 'href' => 'app.php?page=dashboard', 'icon' => 'layout-dashboard'],
    ['label' => 'Inventory', 'page' => 'inventory', 'href' => 'app.php?page=inventory', 'icon' => 'box'],
    ['label' => 'Organization', 'page' => 'organization', 'href' => 'app.php?page=organization', 'icon' => 'building-2'],
    ['label' => 'Report', 'page' => 'report', 'href' => 'app.php?page=report', 'icon' => 'file-bar-chart'],
    ['label' => 'User', 'page' => 'user', 'href' => 'app.php?page=user', 'icon' => 'users'],
];

if (!$isValidatedUser || $userRole !== 'admin') {
    $navItems = array_values(array_filter($navItems, static function ($item) {
        return $item['page'] !== 'user';
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
    transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), width 0.28s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.2s ease;
    z-index: 0;
  }

  [data-page] {
    position: relative;
    z-index: 1;
    transition: background-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
  }

  .nav-label {
    display: inline;
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
  }
</style>

<nav class="sticky top-0 z-40 bg-white border-b border-slate-200 shadow-[0_6px_20px_rgba(15,23,42,0.20)]">
  <div class="relative w-full px-2 md:px-6 py-2 md:py-4 flex items-center justify-between gap-3">

    <!-- Logo section (left) -->
    <div class="flex items-center gap-1.5 md:gap-3 flex-shrink-0 min-w-0">
      <div class="w-8 h-8 md:w-10 md:h-10 rounded-lg md:rounded-xl bg-rose-100 text-rose-700 border-2 border-rose-300 flex items-center justify-center shadow-sm flex-shrink-0">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 md:w-5 md:h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
        </svg>
      </div>

      <div class="leading-tight min-w-0">
        <h1 class="text-xs sm:text-sm md:text-lg font-bold text-slate-900 whitespace-nowrap truncate">AppSys Library</h1>
        <p class="text-[7px] sm:text-[8px] md:text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-500 whitespace-nowrap truncate">Librarian Management Portal</p>
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

    <!-- Mobile menu button (hamburger) -->
    <button
        type="button"
        id="mobileMenuButton"
        class="md:hidden flex items-center justify-center
              w-9 h-9 rounded-lg text-slate-700 hover:bg-slate-100
              transition-colors flex-shrink-0"
        aria-label="Open navigation menu"
    >
        <i data-lucide="menu" class="w-5 h-5"></i>
    </button>

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
<div id="mobileMenu" class="hidden md:hidden border-t border-slate-200 bg-white px-3 py-3">
  <div class="flex flex-col gap-1">
    <?php foreach ($navItems as $item): ?>
      <?php $isActive = $item['page'] === $currentPage; ?>
      <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium <?php echo $isActive ? 'bg-rose-500 text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'; ?>">
        <i data-lucide="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>" class="w-4 h-4"></i>
        <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<script>
document.getElementById('mobileMenuButton')?.addEventListener('click', function () {
    const menu = document.getElementById('mobileMenu');
    const icon = this.querySelector('[data-lucide]');
    
    menu.classList.toggle('hidden');
    
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
        icon.setAttribute('data-lucide', 'menu');
        lucide.createIcons();
    });
});
</script>