<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Prevent the browser from serving a cached/stale copy of this page
// (this matters most for the back button — see the pageshow listener
// below for the other half of that fix).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Functional task: if already logged in, go straight to the main system
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$flash = getFlash();
$errorMessage = $flash['message'];

// --- Non-functional: lockout state must always reflect real elapsed time ---
// Recompute the lockout status fresh from the database on every single load
// of this page (first visit, reload, back/forward navigation, or a copied
// link opened in a new tab). This is intentional: it's what makes the
// "too many attempts" timer survive reloads/back-button instead of
// resetting, since it never depends on the one-time flash message, only on
// the real timestamps stored in login_attempts.
// Only the IP is known before a username is typed, so this locks the
// device/browser as a whole, independent of which username gets entered.
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
</head>
<body class="min-h-screen flex items-center justify-center bg-gradient-to-b from-gray-50 to-gray-100 p-6">

  <div class="w-full max-w-md">

    <!-- Logo + Title -->
    <div class="flex flex-col items-center mb-6">
      <div class="w-16 h-16 bg-white rounded-xl shadow flex items-center justify-center mb-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
        </svg>
      </div>
      <h1 class="text-2xl font-bold text-slate-900">AppSys Library</h1>
      <p class="text-sm text-slate-500">Librarian Management Portal</p>
    </div>

    <!-- Login Card -->
    <div class="bg-white rounded-2xl shadow-md p-8">
      <h2 class="text-xl font-bold text-slate-900 text-center">Welcome Back</h2>
      <p class="text-sm text-slate-500 text-center mt-1 mb-6">Please enter your credentials to continue.</p>

      <?php if ($isLocked || $errorMessage): ?>
        <div id="lockBanner" class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
          <?php if ($isLocked): ?>
            Too many failed attempts. Please try again in
            <span id="lockTimer" class="font-semibold"><?php echo htmlspecialchars(formatLockoutDuration($lockSeconds), ENT_QUOTES, 'UTF-8'); ?></span>.
          <?php else: ?>
            <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form action="auth.php" method="POST" class="space-y-4">

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1 tracking-wide">USERNAME</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-3 flex items-center text-slate-400">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
              </svg>
            </span>
            <input type="text" name="username" required autofocus <?php echo $isLocked ? 'disabled' : ''; ?>
              class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-slate-800 focus:border-transparent <?php echo $isLocked ? 'bg-slate-100 cursor-not-allowed text-slate-400' : ''; ?>"
              placeholder="Enter username">
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1 tracking-wide">PASSWORD</label>
          <div class="relative">
            <span class="absolute inset-y-0 left-3 flex items-center text-slate-400">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
              </svg>
            </span>
            <input type="password" name="password" id="password" required <?php echo $isLocked ? 'disabled' : ''; ?>
              class="w-full pl-10 pr-10 py-2.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-slate-800 focus:border-transparent <?php echo $isLocked ? 'bg-slate-100 cursor-not-allowed text-slate-400' : ''; ?>"
              placeholder="Enter password">
            <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-3 flex items-center text-slate-400">
              <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
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
// Exact seconds remaining, computed server-side just now from real
// login_attempts timestamps (see login.php). This is a duration, not a
// clock time, so client/server clock differences don't matter here.
const LOCK_SECONDS = <?php echo (int)$lockSeconds; ?>;

(function () {
  const timerEl = document.getElementById('lockTimer');
  let remaining = LOCK_SECONDS;

  function formatClock(totalSeconds) {
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    const mm = String(m).padStart(2, '0');
    const ss = String(s).padStart(2, '0');
    return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
  }

  if (remaining > 0 && timerEl) {
    timerEl.textContent = formatClock(remaining);

    const tick = setInterval(function () {
      remaining -= 1;

      if (remaining <= 0) {
        clearInterval(tick);
        // Don't just re-enable the form client-side: reload so the server
        // re-checks the real elapsed time. This is also what correctly
        // picks up the next lockout stage (5 -> 10 -> 20 min, etc.) if
        // more failed attempts happened elsewhere (another tab/device).
        location.reload();
        return;
      }

      timerEl.textContent = formatClock(remaining);
    }, 1000);
  }

  // Back/forward navigation can restore this page from the browser's
  // bfcache instead of issuing a fresh request, which would leave a
  // frozen/stale timer on screen. Force a real reload in that case so the
  // lockout state is always re-synced with the server.
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
</script>
</body>
</html>
