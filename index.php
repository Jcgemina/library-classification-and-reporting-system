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
$hasLoginMessage = $isLocked || $errorMessage !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5f5f4">
  <title>AppSys Library - Login</title>
  <link rel="icon" href="assets/brand/digital/favicon.ico" sizes="48x48">
  <link rel="icon" type="image/svg+xml" href="assets/brand/digital/favicon.svg">
  <link rel="manifest" href="assets/brand/digital/site.webmanifest">
  <link rel="apple-touch-icon" href="assets/brand/digital/apple-touch-icon.png">
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    :root {
      color-scheme: light;
      --canvas: #f5f5f4;
      --surface: #fff;
      --ink: #0f172a;
      --muted: #475569;
      --line: #e2e8f0;
      --line-strong: #cbd5e1;
      --rose: #be123c;
      --rose-dark: #9f1239;
      --rose-soft: #fff1f2;
      --red-soft: #fef2f2;
      font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      font-synthesis: none;
      text-rendering: optimizeLegibility;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    * { box-sizing: border-box; }

    body {
      display: grid;
      min-width: 320px;
      min-height: 100vh;
      place-items: center;
      margin: 0;
      padding: 32px;
      background: var(--canvas);
      color: var(--ink);
      font-size: 14px;
      line-height: 1.5;
    }

    button, input { font: inherit; }
    button, a, input { -webkit-tap-highlight-color: transparent; }
    button:focus-visible, a:focus-visible, input:focus-visible {
      outline: 3px solid rgba(190, 18, 60, .3);
      outline-offset: 2px;
    }

    ::selection { background: #ffe4e6; color: var(--ink); }
    input { caret-color: var(--rose); }

    .login-layout { width: min(100%, 1080px); }
    .login-frame {
      display: grid;
      min-height: 620px;
      grid-template-columns: minmax(310px, .82fr) minmax(0, 1fr);
      overflow: hidden;
      border: 1px solid #dedfe2;
      border-radius: 18px;
      background: var(--surface);
      box-shadow: 0 18px 50px rgba(15, 23, 42, .08);
    }

    .brand-panel {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: clamp(30px, 5vw, 58px);
      background:
        radial-gradient(ellipse at 18% 82%, rgba(190, 18, 60, .07), transparent 44%),
        #fff8f8;
    }
    .brand-lockup { display: flex; align-items: center; gap: 15px; }
    .brand-logo-wrap {
      display: grid;
      width: 58px;
      height: 58px;
      flex: 0 0 auto;
      place-items: center;
      overflow: hidden;
      border: 1px solid #f1d8dd;
      border-radius: 14px;
      background: white;
    }
    .brand-logo { width: 100%; height: 100%; object-fit: contain; }
    .brand-name { font-size: 19px; font-weight: 750; letter-spacing: -.035em; }
    .brand-subtitle {
      margin-top: 2px;
      color: #64707b;
      font-size: 10px;
      font-weight: 650;
      letter-spacing: .13em;
      text-transform: uppercase;
    }
    .brand-message { max-width: 400px; margin: auto 0; padding: 62px 0; }
    .brand-message h1 {
      max-width: 390px;
      margin: 0;
      font-size: clamp(32px, 4vw, 43px);
      font-weight: 730;
      letter-spacing: -.045em;
      line-height: 1.12;
    }
    .brand-message > p { max-width: 350px; margin: 16px 0 0; color: #475569; font-size: 14px; line-height: 1.7; }
    .portal-label {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 26px;
      padding: 8px 11px;
      border: 1px solid #f1d8dd;
      border-radius: 8px;
      background: rgba(255,255,255,.72);
      color: #56333c;
      font-size: 11px;
      font-weight: 600;
    }
    .portal-label [data-lucide] { width: 15px; height: 15px; color: var(--rose-dark); }
    .brand-footer { display: flex; align-items: center; gap: 8px; color: #58636f; font-size: 11px; }
    .brand-footer [data-lucide] { width: 15px; height: 15px; color: var(--rose-dark); }

    .form-panel {
      display: grid;
      align-items: center;
      padding: clamp(30px, 6vw, 72px);
    }
    .form-content { width: min(100%, 390px); margin: 0 auto; }
    .form-heading { margin-bottom: 28px; }
    .form-heading h2 { margin: 0; font-size: 26px; font-weight: 720; letter-spacing: -.035em; }
    .form-heading p { margin: 6px 0 0; color: var(--muted); font-size: 13px; }

    .notice {
      display: flex;
      gap: 10px;
      margin: 0 0 18px;
      padding: 12px 13px;
      border: 1px solid #fecaca;
      border-radius: 9px;
      background: var(--red-soft);
      color: #991b1b;
      font-size: 12px;
    }
    .notice [data-lucide] { width: 17px; height: 17px; flex: 0 0 auto; margin-top: 1px; }
    .notice strong { display: block; margin-bottom: 2px; color: #7f1d1d; font-size: 12px; }
    .notice p { margin: 0; line-height: 1.5; }
    .notice a { color: #7f1d1d; font-weight: 650; text-decoration: underline; text-underline-offset: 2px; }
    .notice-detail { display: block; margin-top: 4px; }

    .login-form { display: grid; gap: 17px; }
    .field { display: grid; gap: 6px; color: #34404b; font-size: 12px; font-weight: 650; }
    .field-control { position: relative; }
    .field-control > [data-lucide] {
      position: absolute;
      top: 50%;
      left: 13px;
      width: 17px;
      height: 17px;
      color: #77828c;
      pointer-events: none;
      transform: translateY(-50%);
    }
    .field input {
      width: 100%;
      height: 46px;
      padding: 0 44px 0 41px;
      border: 1px solid var(--line-strong);
      border-radius: 9px;
      background: white;
      color: var(--ink);
      font-size: 13px;
      transition: border-color .16s ease, box-shadow .16s ease;
    }
    .field input::placeholder { color: #64707b; }
    .field input:hover { border-color: #94a3b8; }
    .field input:focus { border-color: var(--rose); box-shadow: 0 0 0 3px rgba(190, 18, 60, .1); outline: none; }
    .field input:disabled { border-color: var(--line); background: #f1f3f5; color: #64707b; cursor: not-allowed; }
    .password-toggle {
      position: absolute;
      top: 50%;
      right: 7px;
      display: grid;
      width: 34px;
      height: 34px;
      place-items: center;
      border: 0;
      border-radius: 7px;
      background: transparent;
      color: #596672;
      cursor: pointer;
      transform: translateY(-50%);
    }
    .password-toggle:hover { background: #f1f3f5; color: var(--ink); }
    .password-toggle [data-lucide] { width: 17px; height: 17px; }
    .password-toggle:disabled { cursor: not-allowed; }
    .form-options { display: flex; justify-content: flex-end; margin-top: -6px; }
    .forgot-link { color: var(--rose-dark); font-size: 12px; font-weight: 650; text-decoration: none; }
    .forgot-link:hover { text-decoration: underline; text-underline-offset: 3px; }
    .sign-in-button {
      display: flex;
      min-height: 46px;
      align-items: center;
      justify-content: center;
      gap: 9px;
      margin-top: 2px;
      border: 1px solid var(--rose);
      border-radius: 9px;
      background: var(--rose);
      color: white;
      font-size: 13px;
      font-weight: 680;
      cursor: pointer;
      transition: background-color .16s ease, border-color .16s ease;
    }
    .sign-in-button:hover { border-color: var(--rose-dark); background: var(--rose-dark); }
    .sign-in-button [data-lucide] { width: 16px; height: 16px; }
    .sign-in-button:disabled { border-color: #e2e8f0; background: #e2e8f0; color: #64707b; cursor: not-allowed; }

    @media (max-width: 760px) {
      body { display: block; padding: 18px 14px 28px; }
      .login-layout { width: min(100%, 520px); margin: 0 auto; }
      .login-frame { min-height: 0; grid-template-columns: minmax(0, 1fr); border-radius: 14px; }
      .brand-panel { gap: 26px; padding: 23px 22px; }
      .brand-logo-wrap { width: 48px; height: 48px; border-radius: 12px; }
      .brand-name { font-size: 17px; }
      .brand-message { padding: 0; }
      .brand-message h1 { max-width: none; font-size: 29px; }
      .brand-message > p { margin-top: 8px; font-size: 12px; }
      .portal-label { margin-top: 14px; }
      .brand-footer { display: none; }
      .form-panel { padding: 27px 22px 24px; }
      .form-heading { margin-bottom: 22px; }
      .form-heading h2 { font-size: 23px; }
    }

    @media (max-width: 360px) {
      body { padding-right: 10px; padding-left: 10px; }
      .brand-panel, .form-panel { padding-right: 17px; padding-left: 17px; }
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; animation-duration: .01ms !important; }
    }
  </style>
</head>
<body>
  <main class="login-layout">
    <div class="login-frame">
      <section class="brand-panel" aria-label="AppSys Library information">
        <div class="brand-lockup">
          <span class="brand-logo-wrap">
            <img class="brand-logo" src="assets/images/library-system-logo.png?v=2" alt="AppSys Library logo">
          </span>
          <div>
            <div class="brand-name">AppSys Library</div>
            <div class="brand-subtitle">University Library</div>
          </div>
        </div>

        <div class="brand-message">
          <h1>Welcome back.</h1>
          <p>Sign in to continue to the library operations portal.</p>
          <div class="portal-label">
            <i data-lucide="landmark" aria-hidden="true"></i>
            <span>Librarian Management Portal</span>
          </div>
        </div>

        <div class="brand-footer">
          <i data-lucide="shield-check" aria-hidden="true"></i>
          <span>For authorized library staff</span>
        </div>
      </section>

      <section class="form-panel" aria-labelledby="loginHeading">
        <div class="form-content">
          <header class="form-heading">
            <h2 id="loginHeading">Sign in</h2>
            <p>Enter your account details to continue.</p>
          </header>

          <?php if ($hasLoginMessage): ?>
            <div class="notice" id="loginNotice" role="alert" aria-live="assertive" tabindex="-1">
              <i data-lucide="circle-alert" aria-hidden="true"></i>
              <div>
                <?php if ($isLocked): ?>
                  <strong>Sign-in temporarily paused</strong>
                  <p>
                    Sign-in is temporarily paused after repeated failed attempts. Please try again in
                    <span id="lockTimer"><?php echo htmlspecialchars(formatLockoutDuration($lockSeconds), ENT_QUOTES, 'UTF-8'); ?></span>.
                    <a href="forgot_password.php">Reset your password</a>
                  </p>
                <?php else: ?>
                  <strong>Unable to sign in</strong>
                  <p><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
                  <?php if (is_int($flash['attempts']) && $flash['attempts'] > 0): ?>
                    <span class="notice-detail">
                      Failed attempts: <?php echo htmlspecialchars((string)$flash['attempts'], ENT_QUOTES, 'UTF-8'); ?>/<?php echo MAX_ATTEMPTS; ?>
                    </span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <form class="login-form" action="auth.php" method="POST">
            <label class="field" for="username">
              <span>Username</span>
              <span class="field-control">
                <i data-lucide="user-round" aria-hidden="true"></i>
                <input
                  id="username"
                  name="username"
                  type="text"
                  autocomplete="username"
                  placeholder="Enter your username"
                  required
                  autofocus
                  <?php echo $hasLoginMessage ? 'aria-describedby="loginNotice"' : ''; ?>
                  <?php echo $isLocked ? 'disabled' : ''; ?>>
              </span>
            </label>

            <label class="field" for="password">
              <span>Password</span>
              <span class="field-control">
                <i data-lucide="lock-keyhole" aria-hidden="true"></i>
                <input
                  id="password"
                  name="password"
                  type="password"
                  autocomplete="current-password"
                  placeholder="Enter your password"
                  required
                  <?php echo $hasLoginMessage ? 'aria-describedby="loginNotice"' : ''; ?>
                  <?php echo $isLocked ? 'disabled' : ''; ?>>
                <button
                  class="password-toggle"
                  id="passwordToggle"
                  type="button"
                  aria-label="Show password"
                  aria-pressed="false"
                  aria-controls="password"
                  onclick="togglePassword()"
                  <?php echo $isLocked ? 'disabled' : ''; ?>>
                  <i data-lucide="eye" aria-hidden="true"></i>
                </button>
              </span>
            </label>

            <div class="form-options">
              <a class="forgot-link" href="forgot_password.php">Forgot password?</a>
            </div>

            <button class="sign-in-button" id="signInBtn" type="submit" <?php echo $isLocked ? 'disabled' : ''; ?>>
              <span>Sign in</span>
              <i data-lucide="arrow-right" aria-hidden="true"></i>
            </button>
          </form>
        </div>
      </section>
    </div>
  </main>

  <script>
    const SERVER_TIME_MS = <?php echo time() * 1000; ?>;
    const LOCK_SECONDS = <?php echo $lockSeconds; ?>;
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
      const toggle = document.getElementById('passwordToggle');
      const type = input.type === 'password' ? 'text' : 'password';
      const isVisible = type === 'text';

      input.type = type;
      toggle.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
      toggle.setAttribute('aria-pressed', String(isVisible));
      toggle.innerHTML = `<i data-lucide="${isVisible ? 'eye-off' : 'eye'}" aria-hidden="true"></i>`;
      lucide.createIcons();
    }

    lucide.createIcons();

    const loginMessage = document.getElementById('loginNotice');
    if (loginMessage) {
      loginMessage.focus();
    }
  </script>
</body>
</html>
