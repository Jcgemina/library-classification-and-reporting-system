<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!empty($_SESSION['authenticated']) && !empty($_SESSION['user_id'])) {
    header('Location: app.php?page=dashboard');
    exit;
}

$flash = getFlash();
$errorMessage = $flash['message'];
$ip = getClientIp();
$lockSeconds = $pdo ? isRateLimited($pdo, '', $ip) : 0;
$isLocked = $lockSeconds > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BaryoHub | Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Cormorant+Garamond:wght@600;700&display=swap');

    body {
      font-family: 'Inter', sans-serif;
      background: #f3f0ee;
    }

    .soft-card {
      box-shadow: 0 32px 80px rgba(17, 24, 39, 0.12);
    }

    .nav-link {
      transition: opacity 0.2s ease;
    }

    .nav-link:hover {
      opacity: 0.8;
    }
  </style>
</head>
<body class="min-h-screen overflow-x-hidden bg-[#f2f0ee] text-[#1f2d49]">
  <header class="bg-[#0a1d38] text-white shadow-[0_2px_0_rgba(255,255,255,0.05)]">
    <div class="mx-auto flex max-w-[1280px] items-center justify-between px-5 py-4">
      <div class="flex items-center gap-3">
        <div class="overflow-hidden rounded-full border border-white/30 bg-white/5 p-1.5">
          <img src="assets/images/library-system-logo.png" alt="AppSys Library logo" class="h-7 w-7 object-contain" />
        </div>
        <div>
          <div class="text-[1.8rem] font-black leading-none tracking-[-0.04em]">AppSys</div>
          <div class="text-[0.65rem] font-medium text-slate-300">Library Management Portal</div>
        </div>
      </div>
    </div>
  </header>

  <main class="relative flex min-h-[calc(100vh-168px)] items-center justify-center px-4 py-8">
    <button type="button" aria-label="Previous" class="hidden h-14 w-14 items-center justify-center rounded-full bg-white/80 text-[#18304d] shadow-lg ring-1 ring-black/5 backdrop-blur-sm transition hover:-translate-y-0.5 lg:flex">
      <i data-lucide="chevron-left" class="h-7 w-7"></i>
    </button>

    <div class="mx-auto w-full max-w-[980px] px-0 lg:px-8">
      <div class="mb-6 flex justify-start">
        <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white/70 px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-white">
          <i data-lucide="arrow-left" class="h-4 w-4"></i>
          Back
        </button>
      </div>

      <div class="soft-card mx-auto grid overflow-hidden rounded-[22px] bg-[#f5f5f4] shadow-[0_30px_70px_rgba(16,24,40,0.18)] lg:grid-cols-[1.05fr_1.2fr]">
        <section class="relative overflow-hidden bg-[#0d1a35] px-10 py-10 text-white lg:px-12 lg:py-12">
          <div class="absolute -bottom-24 -left-20 h-64 w-64 rounded-full bg-[#3d7a8b]/25 blur-3xl"></div>
          <div class="absolute -bottom-12 right-4 h-28 w-28 rounded-full bg-[#d4b266]/20 blur-2xl"></div>

          <div class="relative z-10 flex h-full flex-col items-center justify-center text-center">
            <div class="mb-7 flex h-28 w-28 items-center justify-center rounded-full border-[3px] border-[#e6d39a] bg-[#0e1e3a] shadow-[0_0_0_10px_rgba(255,255,255,0.03)] p-3">
              <img src="assets/images/library-system-logo.png" alt="AppSys Library logo" class="h-full w-full object-contain" />
            </div>

            <h1 class="text-4xl font-bold tracking-[-0.04em] text-white">Welcome back</h1>
            <p class="mt-3 max-w-[24rem] text-base leading-relaxed text-slate-300">
              AppSys Library, your library operations, online.
            </p>

            <ul class="mt-8 w-full max-w-[23rem] space-y-4 text-left text-sm text-slate-100">
              <li class="flex items-center gap-3">
                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#233454] text-[#f0d895] ring-1 ring-[#f0d895]/30">
                  <i data-lucide="file-text" class="h-4 w-4"></i>
                </span>
                <span>Request library documents online</span>
              </li>
              <li class="flex items-center gap-3">
                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#233454] text-[#f0d895] ring-1 ring-[#f0d895]/30">
                  <i data-lucide="list-checks" class="h-4 w-4"></i>
                </span>
                <span>Track the status of every transaction</span>
              </li>
              <li class="flex items-center gap-3">
                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#233454] text-[#f0d895] ring-1 ring-[#f0d895]/30">
                  <i data-lucide="bell" class="h-4 w-4"></i>
                </span>
                <span>Get notified when your request is ready</span>
              </li>
            </ul>
          </div>
        </section>

        <section class="bg-[#f4f4f3] px-6 py-8 sm:px-10 sm:py-10 lg:px-12 lg:py-12">
          <div class="mx-auto max-w-[420px]">
            <h2 class="text-5xl font-bold tracking-[-0.06em] text-[#1e2a3d]">Login</h2>
            <div class="mt-3 h-1 w-20 rounded-full bg-[#0d1a35]"></div>

            <p class="mt-5 text-base text-slate-500">Log in to view your document requests and profile.</p>

            <?php if ($isLocked || $errorMessage !== null): ?>
              <div id="lockBanner" class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                <?php if ($isLocked): ?>
                  Sign-in is temporarily paused after repeated failed attempts. Please try again in
                  <span id="lockTimer" class="font-semibold"><?php echo htmlspecialchars(formatLockoutDuration($lockSeconds), ENT_QUOTES, 'UTF-8'); ?></span>.
                <?php else: ?>
                  <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                  <?php if (is_int($flash['attempts']) && $flash['attempts'] > 0): ?>
                    <span class="mt-2 block text-xs">Failed attempts: <?php echo htmlspecialchars((string)$flash['attempts'], ENT_QUOTES, 'UTF-8'); ?>/<?php echo MAX_ATTEMPTS; ?></span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <form action="auth.php" method="POST" class="mt-7 space-y-5">
              <div>
                <label for="username" class="mb-2 block text-[0.9rem] font-semibold text-slate-700">Email Address <span class="text-red-500">*</span></label>
                <input
                  type="text"
                  name="username"
                  id="username"
                  required
                  autofocus
                  autocomplete="username"
                  placeholder=""
                  <?php echo $isLocked ? 'disabled' : ''; ?>
                  class="h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-base text-slate-800 shadow-sm outline-none transition focus:border-[#1f3c6d] focus:ring-2 focus:ring-[#dfeaf7] disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                >
              </div>

              <div>
                <label for="password" class="mb-2 block text-[0.9rem] font-semibold text-slate-700">Password <span class="text-red-500">*</span></label>
                <div class="relative">
                  <input
                    type="password"
                    name="password"
                    id="password"
                    required
                    autocomplete="current-password"
                    <?php echo $isLocked ? 'disabled' : ''; ?>
                    class="h-12 w-full rounded-xl border border-slate-300 bg-white px-4 pr-12 text-base text-slate-800 shadow-sm outline-none transition focus:border-[#1f3c6d] focus:ring-2 focus:ring-[#dfeaf7] disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400"
                  >
                  <button type="button" onclick="togglePassword()" aria-label="Show or hide password" class="absolute inset-y-0 right-3 flex items-center text-slate-500 hover:text-slate-700 focus:outline-none">
                    <i data-lucide="eye" id="eyeIcon" class="h-4 w-4"></i>
                  </button>
                </div>
              </div>

              <button type="submit" id="signInBtn" <?php echo $isLocked ? 'disabled' : ''; ?> class="mt-2 flex h-12 w-full items-center justify-center rounded-xl bg-[#0d1a35] text-lg font-bold text-white transition hover:bg-[#15294c] focus:outline-none focus:ring-4 focus:ring-[#dfeaf7] disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500">
                Log In
              </button>
            </form>

            <p class="mt-8 text-center text-base text-slate-600">
              Don't have an account?
              <a href="register.php" class="font-semibold text-[#0d1a35] underline underline-offset-2">Register as Resident</a>
            </p>
          </div>
        </section>
      </div>
    </div>

    <button type="button" aria-label="Next" class="hidden h-14 w-14 items-center justify-center rounded-full bg-white/80 text-[#18304d] shadow-lg ring-1 ring-black/5 backdrop-blur-sm transition hover:-translate-y-0.5 lg:flex">
      <i data-lucide="chevron-right" class="h-7 w-7"></i>
    </button>
  </main>

  <footer class="bg-[#0d1a35] text-white">
    <div class="mx-auto flex max-w-[1280px] flex-col gap-4 px-5 py-6 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="text-lg font-bold">Barangay Sample, Sample City, Philippines</div>
        <div class="text-sm text-slate-300">Barangay Management System</div>
      </div>
      <div class="flex flex-wrap items-center gap-6 text-sm text-slate-200">
        <a href="#" class="hover:text-white">Terms & Conditions</a>
        <a href="#" class="hover:text-white">Privacy Notice</a>
      </div>
    </div>

    <div class="border-t border-white/10 bg-[#0b1730] py-3">
      <div class="mx-auto max-w-[1280px] px-5 text-center text-sm text-slate-300 sm:text-left">
        &copy; 2026 BaryoHub. Barangay Management System.
      </div>
    </div>
  </footer>

  <script>
    const SERVER_TIME_MS = <?php echo time() * 1000; ?>;
    const LOCK_SECONDS = <?php echo $lockSeconds; ?>;
    const UNLOCK_TIME_MS = SERVER_TIME_MS + (LOCK_SECONDS * 1000);

    (function () {
      const timerEl = document.getElementById('lockTimer');
      let tick = null;

      function formatClock(totalSeconds) {
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return minutes > 0
          ? `${minutes} minute${minutes !== 1 ? 's' : ''} ${seconds} second${seconds !== 1 ? 's' : ''}`
          : `${seconds} second${seconds !== 1 ? 's' : ''}`;
      }

      function updateTimer() {
        const remaining = Math.ceil((UNLOCK_TIME_MS - Date.now()) / 1000);
        if (remaining < 1) {
          if (tick) clearInterval(tick);
          location.reload();
          return;
        }
        if (timerEl) {
          timerEl.textContent = formatClock(remaining);
        }
      }

      if (LOCK_SECONDS > 0 && timerEl) {
        updateTimer();
        tick = setInterval(updateTimer, 250);
        document.addEventListener('visibilitychange', () => {
          if (!document.hidden) updateTimer();
        });
      }

      window.addEventListener('pageshow', event => {
        if (event.persisted) location.reload();
      });
    })();

    function togglePassword() {
      const input = document.getElementById('password');
      const icon = document.getElementById('eyeIcon');
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
      if (window.lucide) {
        window.lucide.createIcons();
      }
    }

    if (window.lucide) {
      window.lucide.createIcons();
    }
  </script>
</body>
</html>
