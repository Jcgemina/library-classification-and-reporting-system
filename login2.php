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
  <title>Sign in — AppSys Library</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Libre+Baskerville:ital,wght@0,700;1,700&display=swap');
    body { font-family: 'DM Sans', sans-serif; }
    .display-face { font-family: 'Libre Baskerville', Georgia, serif; }
    .soft-surface { box-shadow: 0 22px 54px rgba(7, 17, 42, .14); }
    ::selection { background: #d8deea; color: #0c1630; }
  </style>
</head>
<body class="min-h-screen bg-[#fbfaf7] text-[#17233b]">
  <main class="min-h-screen grid lg:grid-cols-[1.05fr_.95fr]">

    <!-- Brand column: hidden on compact screens to keep sign-in focused. -->
    <section class="relative hidden min-h-screen overflow-hidden bg-[#0c1630] px-[clamp(2rem,4.4vw,4.5rem)] py-[clamp(2rem,4.4vw,4.5rem)] text-white lg:flex lg:flex-col lg:justify-between">
      <div class="absolute -bottom-64 -right-40 h-[520px] w-[520px] rounded-full border border-white/10"></div>
      <div class="absolute right-16 top-16 h-24 w-24 rounded-full border border-[#dba6b2]/20"></div>

      <a href="#" class="relative z-10 inline-flex w-fit items-center gap-3 font-bold tracking-[-.025em]" aria-label="AppSys Library home">
        <span class="grid h-11 w-11 place-items-center rounded-2xl border border-white/30 bg-white/10">
          <i data-lucide="book-open" class="h-6 w-6"></i>
        </span>
        AppSys Library
      </a>

      <div class="relative z-10 my-auto py-16">
        <h1 class="display-face max-w-[13ch] text-[clamp(2.75rem,4vw,3.8rem)] font-semibold leading-[1.1] tracking-[-.045em]">Where every collection finds its order.</h1>
        <p class="mt-6 max-w-[43ch] text-[16px] leading-[1.7] text-[#c4cee0]">Your library’s daily work, connected in one calm and capable workspace.</p>
        <div class="mt-8 flex flex-wrap gap-2 text-sm text-[#dce3ef]">
          <span class="rounded-full bg-white/10 px-3.5 py-2">Collections</span>
          <span class="rounded-full bg-white/10 px-3.5 py-2">Circulation</span>
          <span class="rounded-full bg-white/10 px-3.5 py-2">Community</span>
        </div>
      </div>

      <div class="relative z-10 w-full max-w-[540px] rounded-[24px] border border-white/10 bg-[#17243f] p-6 shadow-[0_22px_54px_rgba(0,0,0,.26)]" aria-hidden="true">
        <div class="flex items-center justify-between border-b border-white/15 pb-4 text-xs font-semibold uppercase tracking-[.08em] text-[#d5dcec]">
          <span>Today’s collection</span><span class="text-[#f0abb8]">18,426 titles</span>
        </div>
        <div class="flex h-28 items-end gap-[7px] pt-5">
          <i class="h-[70%] flex-1 rounded-t-md bg-[#b52a46]"></i><i class="h-[90%] flex-1 rounded-t-md bg-[#e0b854]"></i><i class="h-[59%] flex-1 rounded-t-md bg-[#5c9a9b]"></i><i class="h-[81%] flex-1 rounded-t-md bg-[#e6dfcf]"></i><i class="h-[66%] flex-1 rounded-t-md bg-[#b52a46]"></i><i class="h-[96%] flex-1 rounded-t-md bg-[#e0b854]"></i><i class="h-[74%] flex-1 rounded-t-md bg-[#5c9a9b]"></i><i class="h-[55%] flex-1 rounded-t-md bg-[#e6dfcf]"></i><i class="h-[85%] flex-1 rounded-t-md bg-[#b52a46]"></i>
        </div>
      </div>
      <p class="relative z-10 mt-5 text-[13px] text-[#aab8d1]">Secure staff access <span class="mx-3 text-[#6e7f9c]">•</span> Library management portal</p>
    </section>

    <!-- Login column -->
    <section class="grid min-h-screen place-items-center bg-[#fffdfa] px-6 py-8 sm:px-12">
      <div class="w-full max-w-[410px]">
        <a href="#" class="mb-12 inline-flex items-center gap-3 text-base font-bold tracking-[-.025em] lg:hidden" aria-label="AppSys Library home">
          <span class="grid h-11 w-11 place-items-center rounded-2xl border border-[#17233b]/25 bg-[#17233b] text-white"><i data-lucide="book-open" class="h-6 w-6"></i></span>
          AppSys Library
        </a>

        <header class="mb-9">
          <h2 class="display-face text-[clamp(2.25rem,3vw,2.75rem)] font-semibold leading-[1.12] tracking-[-.045em]">Welcome back.</h2>
          <p class="mt-3 text-[15px] leading-relaxed text-[#647082]">Sign in with your staff credentials to continue.</p>
        </header>

        <?php if ($isLocked || $errorMessage): ?>
          <div id="lockBanner" class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            <?php if ($isLocked): ?>
              Too many failed attempts. Please try again in <span id="lockTimer" class="font-semibold"><?php echo htmlspecialchars(formatLockoutDuration($lockSeconds), ENT_QUOTES, 'UTF-8'); ?></span>.
            <?php else: ?>
              <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
              <?php if ($flash['attempts'] && $flash['attempts'] > 0): ?>
                <br><span class="mt-2 block text-xs">Failed attempts: <?php echo htmlspecialchars($flash['attempts'], ENT_QUOTES, 'UTF-8'); ?>/<?php echo htmlspecialchars(MAX_ATTEMPTS, ENT_QUOTES, 'UTF-8'); ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <form action="auth.php" method="POST" class="space-y-5">
          <div>
            <label for="username" class="mb-2 block text-xs font-bold uppercase tracking-[.07em] text-[#17233b]">Username</label>
            <div class="relative">
              <input type="text" id="username" name="username" required autofocus autocomplete="username" <?php echo $isLocked ? 'disabled' : ''; ?> placeholder="Enter your username"
                class="h-14 w-full rounded-2xl border border-[#bbc4cd] bg-white px-4 pr-12 text-[15px] text-[#17233b] placeholder:text-[#8994a2] transition hover:border-[#7d8995] focus:border-[#2d6999] focus:outline-none focus:ring-4 focus:ring-[#d7e6f2] disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400">
              <i data-lucide="user-round" class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 text-[#68788b]"></i>
            </div>
          </div>

          <div>
            <label for="password" class="mb-2 block text-xs font-bold uppercase tracking-[.07em] text-[#17233b]">Password</label>
            <div class="relative">
              <input type="password" id="password" name="password" required autocomplete="current-password" <?php echo $isLocked ? 'disabled' : ''; ?> placeholder="Enter your password"
                class="h-14 w-full rounded-2xl border border-[#bbc4cd] bg-white px-4 pr-12 text-[15px] text-[#17233b] placeholder:text-[#8994a2] transition hover:border-[#7d8995] focus:border-[#2d6999] focus:outline-none focus:ring-4 focus:ring-[#d7e6f2] disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400">
              <button type="button" onclick="togglePassword()" class="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center text-[#68788b] hover:text-[#17233b] focus:outline-none focus:ring-2 focus:ring-[#2d6999]" aria-label="Show or hide password"><i data-lucide="eye" id="eyeIcon" class="h-5 w-5"></i></button>
            </div>
          </div>

          <div class="flex items-center justify-between gap-4 pt-1 text-sm">
            <label class="flex cursor-pointer items-center gap-2 text-[#405066]"><input type="checkbox" name="remember" class="h-4 w-4 rounded border-slate-300 accent-[#a71e3b]"> Remember this device</label>
            <a href="forgot_password.php" class="font-bold text-[#a71e3b] underline decoration-1 underline-offset-4 hover:text-[#83162f]">Forgot password?</a>
          </div>

          <button type="submit" id="signInBtn" <?php echo $isLocked ? 'disabled' : ''; ?> class="mt-1 flex h-14 w-full items-center justify-center gap-2 rounded-2xl border border-[#a71e3b] bg-[#a71e3b] text-[15px] font-bold text-white shadow-[0_12px_24px_rgba(167,30,59,.18)] transition hover:-translate-y-px hover:bg-[#83162f] focus:outline-none focus:ring-4 focus:ring-[#efd1d8] disabled:cursor-not-allowed disabled:border-slate-300 disabled:bg-slate-300 disabled:text-slate-500">
            Sign in <i data-lucide="arrow-right" class="h-[18px] w-[18px]"></i>
          </button>
        </form>

        <div class="mt-7 rounded-2xl bg-[#f3f2ed] px-4 py-3 text-center text-[13px] text-[#637084]"><p class="flex items-center justify-center gap-2"><i data-lucide="shield-check" class="h-4 w-4 text-[#a71e3b]"></i>Your connection is encrypted and protected.</p><p class="mt-1.5">Need help signing in? Contact your system administrator.</p></div>
      </div>
    </section>
  </main>

<script>
const SERVER_TIME_MS = <?php echo time() * 1000; ?>;
const LOCK_SECONDS = <?php echo (int)$lockSeconds; ?>;
const UNLOCK_TIME_MS = SERVER_TIME_MS + (LOCK_SECONDS * 1000);

(function () {
  const timerEl = document.getElementById('lockTimer');
  let tick = null;
  function formatClock(totalSeconds) {
    const m = Math.floor(totalSeconds / 60), s = totalSeconds % 60;
    return m > 0 ? `${m} minute${m !== 1 ? 's' : ''} ${s} second${s !== 1 ? 's' : ''}` : `${s} second${s !== 1 ? 's' : ''}`;
  }
  function updateTimer() {
    const remaining = Math.ceil((UNLOCK_TIME_MS - Date.now()) / 1000);
    if (remaining < 1) { if (tick) clearInterval(tick); location.reload(); return; }
    if (timerEl) timerEl.textContent = formatClock(remaining);
  }
  if (LOCK_SECONDS > 0 && timerEl) { updateTimer(); tick = setInterval(updateTimer, 250); document.addEventListener('visibilitychange', () => { if (!document.hidden) updateTimer(); }); }
  window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
})();

function togglePassword() {
  const input = document.getElementById('password');
  input.type = input.type === 'password' ? 'text' : 'password';
}
lucide.createIcons();
</script>
</body>
</html>