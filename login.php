<?php
session_start();
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
<title>AppSys Library - Login</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-slate-200 p-6">

  <div class="w-full max-w-md">

    <!-- Logo + Title -->
    <div class="flex flex-col items-center mb-6">
      <div class="bg-white rounded-2xl border border-slate-200 shadow-[0_18px_45px_rgba(15,23,40,0.22)] p-3 overflow-hidden">
        <img src="assets/images/library-system-logo.png" alt="AppSys Library logo" class="h-24 w-24 scale-[2.5] object-contain">
      </div>
      <h1 class=" mt-4 text-2xl font-bold text-slate-900">AppSys Library</h1>
      <p class="text-sm text-slate-500">Librarian Management Portal</p>
    </div>

    <!-- Login Card -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-[0_22px_60px_rgba(15,23,42,0.50)] p-8">
      <h2 class="text-xl font-bold text-slate-900 text-center">Welcome Back</h2>
      <p class="text-sm text-slate-500 text-center mt-1 mb-6">Please enter your credentials to continue.</p>

      <?php if ($isLocked || $errorMessage): ?>
        <div id="lockBanner" class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
          <?php if ($isLocked): ?>
            Too many failed attempts. Please try again in
            <span id="lockTimer" class="font-semibold"><?php echo htmlspecialchars(formatLockoutDuration($lockSeconds), ENT_QUOTES, 'UTF-8'); ?></span>.
          <?php else: ?>
            <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($flash['attempts'] && $flash['attempts'] > 0): ?>
              <br><span class="text-xs mt-2 block">Failed attempts: <?php echo htmlspecialchars($flash['attempts'], ENT_QUOTES, 'UTF-8'); ?>/<?php echo htmlspecialchars(MAX_ATTEMPTS, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form action="auth.php" method="POST" class="space-y-4">

      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1.5 tracking-wide">
          USERNAME
        </label>

        <div class="relative">
          <span class="absolute inset-y-0 left-3.5 flex items-center text-slate-400 pointer-events-none">
            <i data-lucide="user" class="w-5 h-5"></i>
          </span>

          <input
            type="text"
            name="username"
            required
            autofocus
            <?php echo $isLocked ? 'disabled' : ''; ?>
            class="w-full pl-11 pr-4 py-3
                  border-2 border-slate-300
                  bg-white
                  rounded-xl
                  text-sm text-slate-700
                  shadow-sm
                  placeholder:text-slate-400
                  transition-all duration-200
                  hover:border-slate-400
                  focus:outline-none
                  focus:ring-2
                  focus:ring-slate-200
                  focus:border-slate-800
                  <?php echo $isLocked
                    ? 'bg-slate-100 cursor-not-allowed text-slate-400 border-slate-200 shadow-none'
                    : ''; ?>"
            placeholder="Enter username">
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1.5 tracking-wide">
          PASSWORD
        </label>

        <div class="relative">
          <span class="absolute inset-y-0 left-3.5 flex items-center text-slate-400 pointer-events-none">
            <i data-lucide="lock" class="w-5 h-5"></i>
          </span>

          <input
            type="password"
            name="password"
            id="password"
            required
            <?php echo $isLocked ? 'disabled' : ''; ?>
            class="w-full pl-11 pr-11 py-3
                  border-2 border-slate-300
                  bg-white
                  rounded-xl
                  text-sm text-slate-700
                  shadow-sm
                  placeholder:text-slate-400
                  transition-all duration-200
                  hover:border-slate-400
                  focus:outline-none
                  focus:ring-2
                  focus:ring-slate-200
                  focus:border-slate-800
                  <?php echo $isLocked
                    ? 'bg-slate-100 cursor-not-allowed text-slate-400 border-slate-200 shadow-none'
                    : ''; ?>"
            placeholder="Enter password">

          <button
            type="button"
            onclick="togglePassword()"
            class="absolute inset-y-0 right-3.5 flex items-center text-slate-400 hover:text-slate-700 transition-colors">

            <i data-lucide="eye" id="eyeIcon" class="w-5 h-5"></i>
          </button>
        </div>
      </div>

        <div class="flex items-center justify-between text-sm pt-1">
          <label class="flex items-center gap-2 text-slate-600">
            <input type="checkbox" name="remember" class="rounded border-slate-300">
            Remember this device
          </label>
          <a href="#" class="text-rose-600 font-medium hover:underline">Forgot Password?</a>
        </div>

        <button type="submit" id="signInBtn" <?php echo $isLocked ? 'disabled' : ''; ?>
          class="w-full flex items-center justify-center gap-2 font-semibold py-2.5 rounded-lg transition
          <?php echo $isLocked
            ? 'bg-slate-300 text-slate-500 cursor-not-allowed'
            : 'bg-slate-900 hover:bg-slate-800 text-white'; ?>">
          Sign In
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l3 3m0 0l-3 3m3-3H3" />
          </svg>
        </button>
      </form>
    </div>

    <!-- Footer -->
    <div class="flex items-center justify-center gap-4 text-xs text-slate-400 mt-5">
      <span class="flex items-center gap-1">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 4.556-3.24 8.354-7.542 9.216a1 1 0 01-.914 0C8.24 20.354 5 16.556 5 12V6.75a1 1 0 01.55-.894l6.5-3.25a1 1 0 01.9 0l6.5 3.25A1 1 0 0121 6.75V12z" />
        </svg>
        Encrypted Connection
      </span>
      <span>•</span>
      <span class="flex items-center gap-1">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
        </svg>
        System Support
      </span>
    </div>

  </div>

<script>
// Server-side time captured when page was rendered (in milliseconds)
const SERVER_TIME_MS = <?php echo time() * 1000; ?>;
// Exact seconds remaining from server calculation
const LOCK_SECONDS = <?php echo (int)$lockSeconds; ?>;
// Calculate unlock time on client
const UNLOCK_TIME_MS = SERVER_TIME_MS + (LOCK_SECONDS * 1000);

(function () {
  const timerEl = document.getElementById('lockTimer');
  let tick = null;

  function formatClock(totalSeconds) {
    const m = Math.floor(totalSeconds / 60);
    const s = totalSeconds % 60;

    if (m > 0) {
      return `${m} minute${m !== 1 ? 's' : ''} ${s} second${s !== 1 ? 's' : ''}`;
    }

    return `${s} second${s !== 1 ? 's' : ''}`;
  }

  function updateTimer() {
    const now = Date.now();
    const remaining = Math.ceil((UNLOCK_TIME_MS - now) / 1000);

    if (remaining < 1) {
      if (tick) {
        clearInterval(tick);
        tick = null;
      }
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

    // Handle tab visibility changes - browser may throttle timers when tab is inactive
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        updateTimer();
      }
    });
  }

  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      location.reload();
    }
  });
})();

function togglePassword() {
  const input = document.getElementById('password');
  const type = input.type === 'password' ? 'text' : 'password';
  input.type = type;
}

lucide.createIcons();
</script>
</body>
</html>
