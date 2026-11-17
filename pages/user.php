<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_service.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=user');
    exit;
}

requireLogin();

$userRole = strtolower($_SESSION['role'] ?? 'librarian');
if ($userRole !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

if (!hasValidCsrfToken()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Reload Users and try again.']);
    exit;
}

if (!$pdo) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database unavailable.']);
    exit;
}

ensureUserModuleSchema($pdo);
seedUserModuleAccounts($pdo);

$action = requestString($_GET, 'action', requestString($_POST, 'action'));

if ($action !== '') {
    if (in_array($action, ['save', 'delete', 'toggle_status'], true)) {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Use POST for this action.']);
            exit;
        }
    }

    if ($action === 'list') {
    $search = trim(requestString($_GET, 'search'));
    $listPayload = getUserListPayload($pdo, $search);
        header('Content-Type: application/json');
    echo json_encode(['success' => true, 'librarians' => $listPayload['librarians'], 'stats' => $listPayload['stats']]);
        exit;
    }

    if ($action === 'save') {
    $idInput = requestString($_POST, 'id');
    $id = $idInput !== '' ? requestInt($_POST, 'id') : null;
    $fullName = normalizeUserFullName(requestString($_POST, 'fullName'));
    $email = trim(requestString($_POST, 'email'));
    $username = trim(requestString($_POST, 'username'));
    $password = requestString($_POST, 'password');
    $passwordConfirmation = requestString($_POST, 'passwordConfirmation');
    $role = strtolower(trim(requestString($_POST, 'role')));
    $adminPassword = requestString($_POST, 'admin_password');

    if ($password !== $passwordConfirmation) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'The passwords do not match.']);
        exit;
    }

    $result = saveUserRecord($pdo, $id, $fullName, $email, $username, $password, $role, (int) ($_SESSION['user_id'] ?? 0), $adminPassword);
    header('Content-Type: application/json');
    echo json_encode($result);
        exit;
    }

    if ($action === 'delete') {
        $id = requestInt($_POST, 'id', requestInt($_GET, 'id'));
        $adminPassword = requestString($_POST, 'admin_password');

    $result = deleteUserRecord($pdo, $id, (int) ($_SESSION['user_id'] ?? 0), $adminPassword);
        header('Content-Type: application/json');
    echo json_encode($result);
        exit;
    }

      if ($action === 'toggle_status') {
        $id = requestInt($_POST, 'id');
        $adminPassword = requestString($_POST, 'admin_password');

    $result = toggleUserStatusRecord($pdo, $id, (int) ($_SESSION['user_id'] ?? 0), $adminPassword);
        header('Content-Type: application/json');
    echo json_encode($result);
        exit;
      }
}
?>

<div class="live-users-page">
  <section class="users-heading" aria-labelledby="usersTitle">
    <div>
      <h1 id="usersTitle">Users</h1>
      <p class="page-description">Manage librarian accounts and their access to library administration.</p>
    </div>
    <div class="users-heading-actions">
      <button type="button" id="addLibrarianBtn" class="users-button users-button-primary">
        <i data-lucide="user-plus" aria-hidden="true"></i>
        Add user
      </button>
    </div>
  </section>

  <div id="bulkActionBar" class="users-bulk-bar hidden" role="status" aria-live="polite">
    <div class="users-selection-summary">
      <i data-lucide="check-square" aria-hidden="true"></i>
      <span id="selectedCountLabel">0 selected</span>
    </div>
    <div class="users-bulk-actions">
      <button type="button" id="bulkDeleteBtn" class="users-button users-button-danger" disabled>
        <i data-lucide="trash-2" aria-hidden="true"></i>
        <span id="bulkDeleteBtnLabel">Delete selected</span>
      </button>
      <button type="button" id="clearSelectionBtn" class="users-button">Clear selection</button>
    </div>
  </div>

  <section class="users-summary" aria-label="Account summary">
    <div class="users-summary-item"><strong data-stat="total">0</strong><span>Accounts</span></div>
    <div class="users-summary-item"><strong data-stat="active">0</strong><span>Active</span></div>
    <div class="users-summary-item"><strong data-stat="admins">0</strong><span>Admin access</span></div>
    <div class="users-summary-item users-result-summary" id="librarianResultSummary" aria-live="polite"></div>
  </section>

  <section class="users-directory" aria-labelledby="usersDirectoryTitle">
    <div class="users-directory-heading">
      <div>
        <h2 id="usersDirectoryTitle">Librarian directory</h2>
        <p>Review profiles, roles, and account status.</p>
      </div>
      <div class="users-filters">
        <label class="sr-only" for="librarianSearch">Search accounts</label>
        <input id="librarianSearch" type="search" placeholder="Search name, email, or username" autocomplete="off">
        <label class="sr-only" for="librarianRoleFilter">Filter by role</label>
        <select id="librarianRoleFilter">
          <option value="all">All roles</option>
          <option value="librarian">Librarians</option>
          <option value="admin">Admins</option>
        </select>
        <label class="sr-only" for="librarianStatusFilter">Filter by status</label>
        <select id="librarianStatusFilter">
          <option value="all">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
    </div>
    <div class="users-table-wrap">
      <table class="users-table">
        <thead>
          <tr>
            <th scope="col">Person</th>
            <th scope="col">Username</th>
            <th scope="col">Role</th>
            <th scope="col">Status</th>
            <th scope="col">Joined</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody id="librarianList">
          <tr><td class="users-empty" colspan="6">Loading accounts…</td></tr>
        </tbody>
      </table>
    </div>
    <div id="librarianPagination" class="users-pagination" aria-label="User list pages"></div>
  </section>
</div>

<div id="librarianModal" class="users-modal-overlay fixed inset-0 z-50 hidden items-center justify-center p-4" aria-hidden="true">
  <div class="users-dialog" role="dialog" aria-modal="true" aria-labelledby="modalTitle" tabindex="-1">
    <div class="users-dialog-heading">
      <div>
        <h3 id="modalTitle">Add user</h3>
        <p>Create or update access details.</p>
      </div>
      <button type="button" data-close-modal class="users-dialog-close" aria-label="Close form">
        <i data-lucide="x" aria-hidden="true"></i>
      </button>
    </div>
    <form id="librarianForm">
      <input type="hidden" id="librarianId" name="id" />
      <div class="users-dialog-grid">
        <label class="users-dialog-field" for="fullName">
          Full name
          <input id="fullName" name="fullName" type="text" autocomplete="name" required />
        </label>
        <label class="users-dialog-field" for="email">
          Email address
          <input id="email" name="email" type="email" autocomplete="email" required />
        </label>
        <label class="users-dialog-field is-wide" for="role">
          Role
          <select id="role" name="role">
            <option value="librarian" selected>Librarian</option>
            <option value="admin">Admin</option>
          </select>
        </label>
      </div>
      <section class="users-credentials" aria-labelledby="usersCredentialsTitle">
        <div>
          <h4 class="users-credentials-heading" id="usersCredentialsTitle">Sign-in credentials</h4>
          <p class="users-credentials-copy">Set the username used to sign in. Existing passwords are never shown.</p>
        </div>
        <label class="users-dialog-field" for="username">
          Username
          <input id="username" name="username" type="text" autocomplete="username" aria-describedby="usernameHelp" />
          <span class="users-credential-help" id="usernameHelp">Use the account username you want the user to sign in with.</span>
        </label>
        <details class="users-password-disclosure" id="userPasswordOptions">
          <summary>Set a password (optional)</summary>
          <p class="users-credential-help">Use <?= PASSWORD_MIN_LENGTH ?>–<?= PASSWORD_MAX_LENGTH ?> characters with a letter, number, and special character. Leave blank to keep the current password or send a setup link.</p>
          <div class="users-password-fields">
            <div class="users-dialog-field">
              <label for="password">New password</label>
              <span class="users-password-control">
                <input id="password" name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" aria-describedby="passwordHelp" />
                <button type="button" class="users-password-toggle" data-password-toggle="password" aria-label="Show new password" aria-pressed="false">Show</button>
              </span>
            </div>
            <div class="users-dialog-field">
              <label for="passwordConfirmation">Confirm password</label>
              <span class="users-password-control">
                <input id="passwordConfirmation" name="passwordConfirmation" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" aria-describedby="passwordHelp" />
                <button type="button" class="users-password-toggle" data-password-toggle="passwordConfirmation" aria-label="Show password confirmation" aria-pressed="false">Show</button>
              </span>
            </div>
          </div>
          <span class="users-credential-help" id="passwordHelp">Retype the password to confirm it before saving.</span>
        </details>
      </section>
      <div class="users-dialog-actions">
        <div class="users-dialog-action-group">
          <button type="button" data-close-modal class="users-button users-dialog-button">Cancel</button>
          <button type="submit" id="saveLibrarianBtn" class="users-button users-button-primary users-dialog-button">Save user</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div id="deleteConfirmModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" aria-hidden="true"><div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="deleteConfirmTitle" tabindex="-1"><div class="flex items-start gap-4"><div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600"><i data-lucide="trash-2" class="h-5 w-5"></i></div><div><h3 id="deleteConfirmTitle" class="text-lg font-bold text-slate-900">Delete librarian?</h3><p id="deleteConfirmMessage" class="mt-1 text-sm text-slate-600"></p><p class="mt-2 text-sm font-semibold text-red-600">This action cannot be undone.</p></div></div><label for="adminDeletePassword" class="mt-5 block text-sm font-semibold text-slate-700">Administrator password</label><input type="password" id="adminDeletePassword" autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password"><div class="mt-6 flex justify-end gap-3"><button type="button" id="cancelDeleteBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button><button type="button" id="confirmDeleteBtn" class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700"><i data-lucide="trash-2" class="h-4 w-4"></i>Delete</button></div></div></div>
<div id="saveConfirmModal" class="fixed inset-0 z-[65] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" aria-hidden="true"><div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="saveConfirmTitle" tabindex="-1"><div class="flex items-start gap-4"><div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600"><i data-lucide="shield-check" class="h-5 w-5"></i></div><div><h3 id="saveConfirmTitle" class="text-lg font-bold text-slate-900">Confirm admin access</h3><p id="saveConfirmMessage" class="mt-1 text-sm text-slate-600">Please confirm the administrator password to save this user change.</p></div></div><label for="adminSavePassword" class="mt-5 block text-sm font-semibold text-slate-700">Administrator password</label><input type="password" id="adminSavePassword" autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password"><div class="mt-6 flex justify-end gap-3"><button type="button" id="cancelSaveBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button><button type="button" id="confirmSaveBtn" class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700"><i data-lucide="check" class="h-4 w-4"></i>Confirm Save</button></div></div></div>
<div id="statusConfirmModal" class="fixed inset-0 z-[65] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" aria-hidden="true"><div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="statusConfirmTitle" tabindex="-1"><div class="flex items-start gap-4"><div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700"><i data-lucide="shield-check" class="h-5 w-5"></i></div><div><h3 id="statusConfirmTitle" class="text-lg font-bold text-slate-900">Change account status?</h3><p id="statusConfirmMessage" class="mt-1 text-sm text-slate-600"></p></div></div><label for="adminStatusPassword" class="mt-5 block text-sm font-semibold text-slate-700">Administrator password</label><input type="password" id="adminStatusPassword" autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password"><div class="mt-6 flex justify-end gap-3"><button type="button" id="cancelStatusBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button><button type="button" id="confirmStatusBtn" class="inline-flex items-center gap-2 rounded-xl bg-sky-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-sky-800"><i data-lucide="shield-check" class="h-4 w-4"></i>Confirm change</button></div></div></div>
<div id="userDetailDrawer" class="fixed inset-0 z-[55] hidden" aria-hidden="true"><button type="button" data-close-drawer class="absolute inset-0 bg-slate-900/30 backdrop-blur-sm" aria-label="Close user details"></button><aside class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl" aria-labelledby="detailDrawerTitle" role="dialog" aria-modal="true" tabindex="-1"><div class="flex items-start justify-between border-b border-slate-200 px-6 py-5"><div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-700">Account profile</p><h3 id="detailDrawerTitle" class="mt-1 text-xl font-bold text-slate-900">User details</h3></div><button type="button" data-close-drawer class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" aria-label="Close user details"><i data-lucide="x" class="h-4 w-4"></i></button></div><div class="flex-1 overflow-y-auto px-6 py-6"><div class="flex items-center gap-4"><div id="detailInitials" class="flex h-14 w-14 items-center justify-center rounded-xl bg-rose-100 text-lg font-bold text-rose-700">L</div><div><h4 id="detailFullName" class="text-lg font-bold text-slate-900"></h4><p id="detailUsername" class="text-sm text-slate-500"></p></div></div><dl class="mt-8 divide-y divide-slate-100 rounded-xl border border-slate-200"><div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Email</dt><dd id="detailEmail" class="text-right text-sm font-medium text-slate-800"></dd></div><div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Role</dt><dd id="detailRole" class="text-right text-sm font-medium capitalize text-slate-800"></dd></div><div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Status</dt><dd id="detailStatus" class="text-right text-sm font-medium capitalize text-slate-800"></dd></div><div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Joined</dt><dd id="detailJoined" class="text-right text-sm font-medium text-slate-800"></dd></div></dl></div><div class="border-t border-slate-200 bg-slate-50 px-6 py-4"><div class="flex flex-wrap justify-end gap-2"><button type="button" id="drawerEditAction" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Edit</button><button type="button" id="drawerStatusAction" class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700 transition hover:bg-sky-100">Activate</button><button type="button" id="drawerDeleteAction" class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-100">Delete</button></div></div></aside></div>

<script src="assets/js/user.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/user.js'); ?>"></script>