<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_service.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=user');
    exit;
}

requireLogin();

if (!$pdo) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database unavailable.']);
    exit;
}

// Ensure email column exists
try {
    $pdo->query("SELECT email FROM users LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100)");
}

$seedCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'librarian')")->fetchColumn();
if ((int) $seedCount === 0) {
    $seedAccounts = [
        ['username' => 'admin', 'password' => '123', 'full_name' => 'System Admin', 'email' => 'admin@library.com', 'role' => 'admin'],
        ['username' => 'librarian1', 'password' => '123', 'full_name' => 'Maria Santos', 'email' => 'maria@library.com', 'role' => 'librarian'],
        ['username' => 'librarian2', 'password' => '123', 'full_name' => 'John Medina', 'email' => 'john@library.com', 'role' => 'librarian'],
        ['username' => 'librarian3', 'password' => '123', 'full_name' => 'Angela Cruz', 'email' => 'angela@library.com', 'role' => 'librarian'],
    ];

    $seedStmt = $pdo->prepare(
        "INSERT INTO users (username, password, full_name, email, role, is_active) VALUES (:username, :password, :full_name, :email, :role, 1)"
    );

    foreach ($seedAccounts as $account) {
        $seedStmt->execute([
            ':username' => $account['username'],
            ':password' => password_hash($account['password'], PASSWORD_DEFAULT),
            ':full_name' => $account['full_name'],
            ':email' => $account['email'],
            ':role' => $account['role'],
        ]);
    }
}

$userRole = strtolower($_SESSION['role'] ?? 'librarian');
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action !== null) {
    if ($userRole !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Admin access required.']);
        exit;
    }

    if ($action === 'list') {
    $search = trim((string)($_GET['search'] ?? ''));
    $listPayload = getUserListPayload($pdo, $search);
        header('Content-Type: application/json');
    echo json_encode(['success' => true, 'librarians' => $listPayload['librarians'], 'stats' => $listPayload['stats']]);
        exit;
    }

    if ($action === 'save') {
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
    $fullName = trim((string)($_POST['fullName'] ?? ''));
    $fullName = ucwords(strtolower($fullName));
    $email = trim((string)($_POST['email'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role = strtolower(trim((string)($_POST['role'] ?? '')));
    $adminPassword = (string)($_POST['admin_password'] ?? '');

    $result = saveUserRecord($pdo, $id, $fullName, $email, $username, $password, $role, (int) ($_SESSION['user_id'] ?? 0), $adminPassword);
    header('Content-Type: application/json');
    echo json_encode($result);
        exit;
    }

    if ($action === 'delete') {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
        $adminPassword = (string) ($_POST['admin_password'] ?? '');

    $result = deleteUserRecord($pdo, $id, (int) ($_SESSION['user_id'] ?? 0), $adminPassword);
        header('Content-Type: application/json');
    echo json_encode($result);
        exit;
    }

      if ($action === 'toggle_status') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adminPassword = (string) ($_POST['admin_password'] ?? '');

    $result = toggleUserStatusRecord($pdo, $id, (int) ($_SESSION['user_id'] ?? 0), $adminPassword);
        header('Content-Type: application/json');
    echo json_encode($result);
        exit;
      }
}
?>

<div class="space-y-6">
  <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
    <div>
      <h2 class="text-2xl font-bold text-slate-900">User Module</h2>
      <p class="text-sm text-slate-500 mt-1">
        Manage librarian accounts, permissions, and secure access.
      </p>
    </div>

    <div class="flex items-center gap-3">
       <button
        type="button"
        id="bulkDeleteBtn"
        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-60">
        <i data-lucide="trash-2" class="w-4 h-4"></i>
        <span id="bulkDeleteBtnLabel">Delete selected</span>
      </button>
      
      <button
        type="button"
        id="addLibrarianBtn"
        class="inline-flex items-center gap-2 rounded-xl bg-[#f43f5e] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-900">
        <i data-lucide="user-plus" class="w-4 h-4"></i>
        Add Librarian
      </button>
    </div>
  </div>

  <div id="bulkActionBar" class="hidden items-center justify-between rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
    <div class="flex items-center gap-2 font-semibold">
      <i data-lucide="check-square" class="h-4 w-4"></i>
      <span id="selectedCountLabel">0 selected</span>
    </div>
    <button type="button" id="clearSelectionBtn" class="text-sm font-semibold text-rose-700 underline-offset-2 hover:underline">
      Clear selection
    </button>
  </div>

  <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md">
      <p class="text-sm font-medium text-slate-500">Total Librarians</p>
      <div class="mt-3 flex items-center justify-between">
        <h3 class="text-3xl font-bold text-slate-900" data-stat="total">0</h3>
        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-100 text-rose-700"><i data-lucide="users" class="h-5 w-5"></i></div>
      </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md">
      <p class="text-sm font-medium text-slate-500">Active Accounts</p>
      <div class="mt-3 flex items-center justify-between">
        <h3 class="text-3xl font-bold text-slate-900" data-stat="active">0</h3>
        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700"><i data-lucide="shield-check" class="h-5 w-5"></i></div>
      </div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md">
      <p class="text-sm font-medium text-slate-500">Admin Access</p>
      <div class="mt-3 flex items-center justify-between">
        <h3 class="text-3xl font-bold text-slate-900" data-stat="admins">0</h3>
        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-amber-700"><i data-lucide="key-round" class="h-5 w-5"></i></div>
      </div>
    </div>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-md">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div><h3 class="text-lg font-bold text-slate-900">Librarian Directory</h3><p class="text-sm text-slate-500">Review staff profiles and update access rights.</p></div>
      <div class="flex w-full flex-col gap-2 sm:flex-row md:w-auto">
        <div class="relative w-full sm:w-56"><i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i><input id="librarianSearch" type="text" placeholder="Search librarian" aria-label="Search librarian accounts" class="w-full rounded-xl border-2 border-slate-300 bg-slate-50 py-2.5 pl-9 pr-3 text-sm text-slate-700 outline-none transition focus:border-[#4B5694] focus:bg-white focus:ring-2 focus:ring-[#4B5694]/20" /></div>
        <label class="sr-only" for="librarianStatusFilter">Filter by account status</label>
        <select id="librarianStatusFilter" class="w-full rounded-xl border-2 border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-[#4B5694] focus:bg-white focus:ring-2 focus:ring-[#4B5694]/20 sm:w-36"><option value="all">All statuses</option><option value="active">Active only</option><option value="inactive">Inactive only</option></select>
        <label class="sr-only" for="librarianRoleFilter">Filter by account role</label>
        <select id="librarianRoleFilter" class="w-full rounded-xl border-2 border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-[#4B5694] focus:bg-white focus:ring-2 focus:ring-[#4B5694]/20 sm:w-36"><option value="all">All roles</option><option value="librarian">Librarians</option><option value="admin">Admins</option></select>
      </div>
    </div>
    <p id="librarianResultSummary" class="mt-4 text-xs font-medium text-slate-500" aria-live="polite"></p>
    <div id="librarianList" class="mt-6 space-y-3"></div>
    <div id="librarianPagination" class="mt-6 flex flex-wrap items-center justify-center gap-2"></div>
  </div>
</div>

<div id="librarianModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" aria-hidden="true">
  <div class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="modalTitle" tabindex="-1">
    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><div><h3 id="modalTitle" class="text-xl font-bold text-slate-900">Add User</h3><p class="text-sm text-slate-500">Create or update access details.</p></div><button type="button" data-close-modal class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" aria-label="Close form"><i data-lucide="x" class="h-4 w-4"></i></button></div>
    <form id="librarianForm" class="space-y-5 p-6"><input type="hidden" id="librarianId" name="id" />
      <div class="grid gap-4 md:grid-cols-2">
        <div><label for="fullName" class="mb-1.5 block text-sm font-medium text-slate-700">Full Name</label><input id="fullName" name="fullName" type="text" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100" /></div>
        <div><label for="email" class="mb-1.5 block text-sm font-medium text-slate-700">Email Address</label><input id="email" name="email" type="email" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100" /></div>
        <div><label for="username" class="mb-1.5 block text-sm font-medium text-slate-700">Username</label><input id="username" name="username" type="text" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100" placeholder="Enter username" /><p class="mt-1 text-xs text-slate-500">Use the account username you want the user to sign in with.</p></div>
        <div><label for="password" class="mb-1.5 block text-sm font-medium text-slate-700">Password</label><div class="relative"><input id="password" name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 pr-10 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100" placeholder="Type new password or leave blank for setup email" /><button type="button" id="togglePassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition" aria-label="Show or hide password"><i data-lucide="eye" class="h-4 w-4"></i></button></div><p class="mt-1 text-xs text-slate-500">Use <?= PASSWORD_MIN_LENGTH ?>-<?= PASSWORD_MAX_LENGTH ?> characters with a letter, number, and special character. Leave blank for a setup link or to keep the current password.</p></div>
        <div><label for="role" class="mb-1.5 block text-sm font-medium text-slate-700">Role</label><select id="role" name="role" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100"><option value="librarian" selected>Librarian</option><option value="admin">Admin</option></select></div>
      </div>
      <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4"><button type="button" data-close-modal class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button><button type="submit" id="saveLibrarianBtn" class="rounded-xl bg-rose-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-70">Save User</button></div>
    </form>
  </div>
</div>

<div id="deleteConfirmModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" aria-hidden="true"><div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="deleteConfirmTitle" tabindex="-1"><div class="flex items-start gap-4"><div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600"><i data-lucide="trash-2" class="h-5 w-5"></i></div><div><h3 id="deleteConfirmTitle" class="text-lg font-bold text-slate-900">Delete librarian?</h3><p id="deleteConfirmMessage" class="mt-1 text-sm text-slate-600"></p><p class="mt-2 text-sm font-semibold text-red-600">This action cannot be undone.</p></div></div><label for="adminDeletePassword" class="mt-5 block text-sm font-semibold text-slate-700">Administrator password</label><input type="password" id="adminDeletePassword" autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password"><div class="mt-6 flex justify-end gap-3"><button type="button" id="cancelDeleteBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button><button type="button" id="confirmDeleteBtn" class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700"><i data-lucide="trash-2" class="h-4 w-4"></i>Delete</button></div></div></div>
<div id="saveConfirmModal" class="fixed inset-0 z-[65] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" aria-hidden="true"><div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="saveConfirmTitle" tabindex="-1"><div class="flex items-start gap-4"><div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600"><i data-lucide="shield-check" class="h-5 w-5"></i></div><div><h3 id="saveConfirmTitle" class="text-lg font-bold text-slate-900">Confirm admin access</h3><p id="saveConfirmMessage" class="mt-1 text-sm text-slate-600">Please confirm the administrator password to save this user change.</p></div></div><label for="adminSavePassword" class="mt-5 block text-sm font-semibold text-slate-700">Administrator password</label><input type="password" id="adminSavePassword" autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password"><div class="mt-6 flex justify-end gap-3"><button type="button" id="cancelSaveBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button><button type="button" id="confirmSaveBtn" class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700"><i data-lucide="check" class="h-4 w-4"></i>Confirm Save</button></div></div></div>
<div id="statusConfirmModal" class="fixed inset-0 z-[65] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" aria-hidden="true"><div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="statusConfirmTitle" tabindex="-1"><div class="flex items-start gap-4"><div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700"><i data-lucide="shield-check" class="h-5 w-5"></i></div><div><h3 id="statusConfirmTitle" class="text-lg font-bold text-slate-900">Change account status?</h3><p id="statusConfirmMessage" class="mt-1 text-sm text-slate-600"></p></div></div><label for="adminStatusPassword" class="mt-5 block text-sm font-semibold text-slate-700">Administrator password</label><input type="password" id="adminStatusPassword" autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password"><div class="mt-6 flex justify-end gap-3"><button type="button" id="cancelStatusBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button><button type="button" id="confirmStatusBtn" class="inline-flex items-center gap-2 rounded-xl bg-sky-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-sky-800"><i data-lucide="shield-check" class="h-4 w-4"></i>Confirm change</button></div></div></div>
<div id="userDetailDrawer" class="fixed inset-0 z-[55] hidden" aria-hidden="true"><button type="button" data-close-drawer class="absolute inset-0 bg-slate-900/30 backdrop-blur-sm" aria-label="Close user details"></button><aside class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl" aria-labelledby="detailDrawerTitle" role="dialog" aria-modal="true" tabindex="-1"><div class="flex items-start justify-between border-b border-slate-200 px-6 py-5"><div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-700">Account profile</p><h3 id="detailDrawerTitle" class="mt-1 text-xl font-bold text-slate-900">User details</h3></div><button type="button" data-close-drawer class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" aria-label="Close user details"><i data-lucide="x" class="h-4 w-4"></i></button></div><div class="flex-1 overflow-y-auto px-6 py-6"><div class="flex items-center gap-4"><div id="detailInitials" class="flex h-14 w-14 items-center justify-center rounded-xl bg-rose-100 text-lg font-bold text-rose-700">L</div><div><h4 id="detailFullName" class="text-lg font-bold text-slate-900"></h4><p id="detailUsername" class="text-sm text-slate-500"></p></div></div><dl class="mt-8 divide-y divide-slate-100 rounded-xl border border-slate-200"><div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Email</dt><dd id="detailEmail" class="text-right text-sm font-medium text-slate-800"></dd></div><div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Role</dt><dd id="detailRole" class="text-right text-sm font-medium capitalize text-slate-800"></dd></div><div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Status</dt><dd id="detailStatus" class="text-right text-sm font-medium capitalize text-slate-800"></dd></div><div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Joined</dt><dd id="detailJoined" class="text-right text-sm font-medium text-slate-800"></dd></div></dl></div><div class="border-t border-slate-200 bg-slate-50 px-6 py-4"><div class="flex flex-wrap justify-end gap-2"><button type="button" id="drawerEditAction" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Edit</button><button type="button" id="drawerStatusAction" class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700 transition hover:bg-sky-100">Activate</button><button type="button" id="drawerDeleteAction" class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-100">Delete</button></div></div></aside></div>
<div id="toastContainer" class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3"></div>

<script src="assets/js/user.js"></script>