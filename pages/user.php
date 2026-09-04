<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

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
        $sql = "SELECT id, username, full_name, email, role, is_active, created_at FROM users WHERE role IN ('admin', 'librarian')";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (full_name LIKE :search_full_name OR username LIKE :search_username OR role LIKE :search_role)";
            $params[':search_full_name'] = '%' . $search . '%';
            $params[':search_username'] = '%' . $search . '%';
            $params[':search_role'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $librarians = $stmt->fetchAll();
        $normalized = array_map(static function (array $user): array {
            return [
                'id' => (int) $user['id'],
                'fullName' => $user['full_name'],
                'username' => $user['username'],
                'email' => $user['email'] ?? '',
                'role' => strtolower((string) $user['role']),
                'status' => ((int) $user['is_active'] === 1) ? 'active' : 'inactive',
                'createdAt' => $user['created_at'],
                'joinedAt' => date('M j, Y', strtotime($user['created_at'])),
            ];
        }, $librarians);

        $stats = [
            'total' => count($normalized),
            'active' => count(array_filter($normalized, static fn($user) => $user['status'] === 'active')),
            'admins' => count(array_filter($normalized, static fn($user) => $user['role'] === 'admin')),
        ];

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'librarians' => $normalized, 'stats' => $stats]);
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

        $adminStmt = $pdo->prepare('SELECT id, username, password FROM users WHERE id = :id AND role = \'admin\' AND is_active = 1 LIMIT 1');
        $adminStmt->execute([':id' => (int) ($_SESSION['user_id'] ?? 0)]);
        $admin = $adminStmt->fetch();

        if (!$admin || $adminPassword === '' || !password_verify($adminPassword, $admin['password'])) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'The administrator password is incorrect.']);
            exit;
        }

        if ($fullName === '' || $username === '' || $email === '') {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Full name, email, and username are required.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
            exit;
        }

        if (!in_array($role, ['librarian', 'admin'], true)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please select a valid role.']);
            exit;
        }

        $duplicateSql = 'SELECT id, username, full_name FROM users WHERE (LOWER(username) = LOWER(:username) OR LOWER(full_name) = LOWER(:full_name))';
        $duplicateParams = [':username' => $username, ':full_name' => $fullName];
        if ($id !== null) {
            $duplicateSql .= ' AND id != :id';
            $duplicateParams[':id'] = $id;
        }
        $duplicateStmt = $pdo->prepare($duplicateSql . ' LIMIT 1');
        $duplicateStmt->execute($duplicateParams);
        $duplicateUser = $duplicateStmt->fetch();

        if ($duplicateUser) {
            if (strcasecmp((string) $duplicateUser['username'], $username) === 0) {
                $message = 'That username is already in use.';
            } else {
                $message = 'That full name is already in use.';
            }

            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }

        if ($id !== null) {
            $existing = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
            $existing->execute([':id' => $id]);
            $existingUser = $existing->fetch();
            $hashedPassword = $existingUser['password'] ?? null;

            if ($password !== '') {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            }

            $stmt = $pdo->prepare(
                'UPDATE users SET username = :username, password = :password, full_name = :full_name, email = :email, role = :role WHERE id = :id'
            );
            $stmt->execute([
                ':username' => $username,
                ':password' => $hashedPassword,
                ':full_name' => $fullName,
                ':email' => $email,
                ':role' => $role,
                ':id' => $id,
            ]);

            $message = 'Librarian updated successfully.';
        } else {
            $temporaryPassword = $password !== '' ? $password : bin2hex(random_bytes(16));
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password, full_name, email, role, is_active) VALUES (:username, :password, :full_name, :email, :role, 1)'
            );
            $stmt->execute([
                ':username' => $username,
                ':password' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                ':full_name' => $fullName,
                ':email' => $email,
                ':role' => $role,
            ]);

            $id = (int) $pdo->lastInsertId();
            $message = 'Librarian added successfully.';

            if ($password === '') {
                $resetToken = createPasswordResetToken($pdo, $id);
                queuePasswordSetupEmail($pdo, $email, $fullName, $username, $resetToken);
            }
        }

        $emailQueued = false;
        if ($id !== null && !isset($existingUser) && $password === '') {
            $emailQueued = true;
        }

        $selectStmt = $pdo->prepare('SELECT id, username, full_name, email, role, is_active, created_at FROM users WHERE id = :id LIMIT 1');
        $selectStmt->execute([':id' => $id]);
        $user = $selectStmt->fetch();

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'emailQueued' => $emailQueued,
            'librarian' => [
                'id' => (int) $user['id'],
                'fullName' => $user['full_name'],
                'username' => $user['username'],
                'email' => $user['email'] ?? '',
                'role' => strtolower((string) $user['role']),
                'status' => ((int) $user['is_active'] === 1) ? 'active' : 'inactive',
                'createdAt' => $user['created_at'],
                'joinedAt' => date('M j, Y', strtotime($user['created_at'])),
            ],
        ]);
        exit;
    }

    if ($action === 'delete') {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
        $adminPassword = (string) ($_POST['admin_password'] ?? '');

        if ($id <= 0) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid librarian ID.']);
            exit;
        }

        $adminStmt = $pdo->prepare('SELECT id, username, password FROM users WHERE id = :id AND role = \'admin\' AND is_active = 1 LIMIT 1');
        $adminStmt->execute([':id' => (int) ($_SESSION['user_id'] ?? 0)]);
        $admin = $adminStmt->fetch();

        if (!$admin || $adminPassword === '' || !password_verify($adminPassword, $admin['password'])) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'The administrator password is incorrect.']);
            exit;
        }

        $targetStmt = $pdo->prepare('SELECT id, username, full_name FROM users WHERE id = :id AND role IN (\'admin\', \'librarian\') LIMIT 1');
        $targetStmt->execute([':id' => $id]);
        $target = $targetStmt->fetch();

        if (!$target) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Librarian not found.']);
            exit;
        }

        $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = :id AND role IN (\'admin\', \'librarian\')');
        $deleteStmt->execute([':id' => $id]);
        recordSecurityLog($pdo, (int) $admin['id'], $admin['username'], 'user_deleted', 'warning', 'Deleted user ' . $target['username'] . ' (' . $target['full_name'] . ').', getClientIp());
        recordActivityLog($pdo, (int) $admin['id'], 'delete_user', 'Deleted user ' . $target['username'] . '.');

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'deletedId' => $id,
            'message' => 'Librarian deleted successfully.'
        ]);
        exit;
    }

      if ($action === 'toggle_status') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adminPassword = (string) ($_POST['admin_password'] ?? '');

        if ($id <= 0) {
          http_response_code(422);
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'message' => 'Invalid librarian ID.']);
          exit;
        }

        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
          http_response_code(422);
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'message' => 'You cannot change your own account status.']);
          exit;
        }

        $adminStmt = $pdo->prepare('SELECT id, username, password FROM users WHERE id = :id AND role = \'admin\' AND is_active = 1 LIMIT 1');
        $adminStmt->execute([':id' => (int) ($_SESSION['user_id'] ?? 0)]);
        $admin = $adminStmt->fetch();

        if (!$admin || $adminPassword === '' || !password_verify($adminPassword, $admin['password'])) {
          http_response_code(403);
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'message' => 'The administrator password is incorrect.']);
          exit;
        }

        $targetStmt = $pdo->prepare('SELECT id, username, full_name, is_active FROM users WHERE id = :id AND role IN (\'admin\', \'librarian\') LIMIT 1');
        $targetStmt->execute([':id' => $id]);
        $target = $targetStmt->fetch();

        if (!$target) {
          http_response_code(404);
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'message' => 'Librarian not found.']);
          exit;
        }

        $newStatus = (int) $target['is_active'] === 1 ? 0 : 1;
        $statusStmt = $pdo->prepare('UPDATE users SET is_active = :is_active WHERE id = :id');
        $statusStmt->execute([':is_active' => $newStatus, ':id' => $id]);
        $statusLabel = $newStatus === 1 ? 'activated' : 'deactivated';

        recordSecurityLog($pdo, (int) $admin['id'], $admin['username'], 'user_status_changed', 'warning', ucfirst($statusLabel) . ' user ' . $target['username'] . ' (' . $target['full_name'] . ').', getClientIp());
        recordActivityLog($pdo, (int) $admin['id'], 'change_user_status', ucfirst($statusLabel) . ' user ' . $target['username'] . '.', 'user', $id);

        header('Content-Type: application/json');
        echo json_encode([
          'success' => true,
          'status' => $newStatus === 1 ? 'active' : 'inactive',
          'message' => 'Librarian ' . $statusLabel . ' successfully.'
        ]);
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
        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-100 text-rose-700">
          <i data-lucide="users" class="h-5 w-5"></i>
        </div>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md">
      <p class="text-sm font-medium text-slate-500">Active Accounts</p>
      <div class="mt-3 flex items-center justify-between">
        <h3 class="text-3xl font-bold text-slate-900" data-stat="active">0</h3>
        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
          <i data-lucide="shield-check" class="h-5 w-5"></i>
        </div>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md">
      <p class="text-sm font-medium text-slate-500">Admin Access</p>
      <div class="mt-3 flex items-center justify-between">
        <h3 class="text-3xl font-bold text-slate-900" data-stat="admins">0</h3>
        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
          <i data-lucide="key-round" class="h-5 w-5"></i>
        </div>
      </div>
    </div>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-md">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div>
        <h3 class="text-lg font-bold text-slate-900">Librarian Directory</h3>
        <p class="text-sm text-slate-500">Review staff profiles and update access rights.</p>
      </div>

      <div class="flex w-full flex-col gap-2 sm:flex-row md:w-auto">
        <div class="relative w-full sm:w-56">
          <i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
          <input
            id="librarianSearch"
            type="text"
            placeholder="Search librarian"
            aria-label="Search librarian accounts"
            class="w-full rounded-xl border-2 border-slate-300 bg-slate-50 py-2.5 pl-9 pr-3 text-sm text-slate-700 outline-none transition focus:border-[#4B5694] focus:bg-white focus:ring-2 focus:ring-[#4B5694]/20"
          />
        </div>
        <label class="sr-only" for="librarianStatusFilter">Filter by account status</label>
        <select
          id="librarianStatusFilter"
          class="w-full rounded-xl border-2 border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-[#4B5694] focus:bg-white focus:ring-2 focus:ring-[#4B5694]/20 sm:w-36"
        >
          <option value="all">All statuses</option>
          <option value="active">Active only</option>
          <option value="inactive">Inactive only</option>
        </select>
        <label class="sr-only" for="librarianRoleFilter">Filter by account role</label>
        <select
          id="librarianRoleFilter"
          class="w-full rounded-xl border-2 border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-[#4B5694] focus:bg-white focus:ring-2 focus:ring-[#4B5694]/20 sm:w-36"
        >
          <option value="all">All roles</option>
          <option value="librarian">Librarians</option>
          <option value="admin">Admins</option>
        </select>
      </div>
    </div>

    <p id="librarianResultSummary" class="mt-4 text-xs font-medium text-slate-500" aria-live="polite"></p>

    <div id="librarianList" class="mt-6 space-y-3"></div>
    <div id="librarianPagination" class="mt-6 flex flex-wrap items-center justify-center gap-2"></div>
  </div>
</div>

<div id="librarianModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm">
  <div class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
      <div>
        <h3 id="modalTitle" class="text-xl font-bold text-slate-900">Add User</h3>
        <p class="text-sm text-slate-500">Create or update access details.</p>
      </div>
      <button type="button" data-close-modal class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" aria-label="Close form">
        <i data-lucide="x" class="h-4 w-4"></i>
      </button>
    </div>

    <form id="librarianForm" class="space-y-5 p-6">
      <input type="hidden" id="librarianId" name="id" />

      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label for="fullName" class="mb-1.5 block text-sm font-medium text-slate-700">Full Name</label>
          <input id="fullName" name="fullName" type="text" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100" />
        </div>

        <div>
          <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700">Email Address</label>
          <input id="email" name="email" type="email" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100" />
        </div>

        <div>
          <label for="username" class="mb-1.5 block text-sm font-medium text-slate-700">Username</label>
          <input id="username" name="username" type="text" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100" placeholder="Enter username" />
          <p class="mt-1 text-xs text-slate-500">Use the account username you want the user to sign in with.</p>
        </div>

        <div>
          <label for="password" class="mb-1.5 block text-sm font-medium text-slate-700">Password</label>
          <div class="relative">
            <input id="password" name="password" type="password" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 pr-10 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100" placeholder="Type new password or leave blank for setup email" />
            <button type="button" id="togglePassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition" aria-label="Show or hide password">
              <i data-lucide="eye" class="h-4 w-4"></i>
            </button>
          </div>
          <p class="mt-1 text-xs text-slate-500">Leave blank for a secure setup link when creating a new account; keep the current password when editing.</p>
        </div>

        <div>
          <label for="role" class="mb-1.5 block text-sm font-medium text-slate-700">Role</label>
          <select id="role" name="role" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-rose-300 focus:bg-white focus:ring-2 focus:ring-rose-100">
            <option value="librarian" selected>Librarian</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>

      <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
        <button type="button" data-close-modal class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
          Cancel
        </button>
        <button type="submit" id="saveLibrarianBtn" class="rounded-xl bg-rose-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-70">
          Save User
        </button>
      </div>
    </form>
  </div>
</div>

<div id="deleteConfirmModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm">
  <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
    <div class="flex items-start gap-4">
      <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600">
        <i data-lucide="trash-2" class="h-5 w-5"></i>
      </div>
      <div>
        <h3 class="text-lg font-bold text-slate-900">Delete librarian?</h3>
        <p id="deleteConfirmMessage" class="mt-1 text-sm text-slate-600"></p>
        <p class="mt-2 text-sm font-semibold text-red-600">This action cannot be undone.</p>
      </div>
    </div>
    <label for="adminDeletePassword" class="mt-5 block text-sm font-semibold text-slate-700">Administrator password</label>
    <input type="password" id="adminDeletePassword" autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password">
    <div class="mt-6 flex justify-end gap-3">
      <button type="button" id="cancelDeleteBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
      <button type="button" id="confirmDeleteBtn" class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700">
        <i data-lucide="trash-2" class="h-4 w-4"></i>
        Delete
      </button>
    </div>
  </div>
</div>

<div id="saveConfirmModal" class="fixed inset-0 z-[65] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm">
  <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
    <div class="flex items-start gap-4">
      <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
        <i data-lucide="shield-check" class="h-5 w-5"></i>
      </div>
      <div>
        <h3 class="text-lg font-bold text-slate-900">Confirm admin access</h3>
        <p id="saveConfirmMessage" class="mt-1 text-sm text-slate-600">Please confirm the administrator password to save this user change.</p>
      </div>
    </div>
    <label for="adminSavePassword" class="mt-5 block text-sm font-semibold text-slate-700">Administrator password</label>
    <input type="password" id="adminSavePassword" autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password">
    <div class="mt-6 flex justify-end gap-3">
      <button type="button" id="cancelSaveBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
      <button type="button" id="confirmSaveBtn" class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700">
        <i data-lucide="check" class="h-4 w-4"></i>
        Confirm Save
      </button>
    </div>
  </div>
</div>

<div id="statusConfirmModal" class="fixed inset-0 z-[65] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm">
  <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
    <div class="flex items-start gap-4">
      <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700">
        <i data-lucide="shield-check" class="h-5 w-5"></i>
      </div>
      <div>
        <h3 id="statusConfirmTitle" class="text-lg font-bold text-slate-900">Change account status?</h3>
        <p id="statusConfirmMessage" class="mt-1 text-sm text-slate-600"></p>
      </div>
    </div>
    <label for="adminStatusPassword" class="mt-5 block text-sm font-semibold text-slate-700">Administrator password</label>
    <input type="password" id="adminStatusPassword" autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password">
    <div class="mt-6 flex justify-end gap-3">
      <button type="button" id="cancelStatusBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
      <button type="button" id="confirmStatusBtn" class="inline-flex items-center gap-2 rounded-xl bg-sky-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-sky-800">
        <i data-lucide="shield-check" class="h-4 w-4"></i>
        Confirm change
      </button>
    </div>
  </div>
</div>

<div id="userDetailDrawer" class="fixed inset-0 z-[55] hidden" aria-hidden="true">
  <button type="button" data-close-drawer class="absolute inset-0 bg-slate-900/30 backdrop-blur-sm" aria-label="Close user details"></button>
  <aside class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl" aria-labelledby="detailDrawerTitle">
    <div class="flex items-start justify-between border-b border-slate-200 px-6 py-5">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-700">Account profile</p>
        <h3 id="detailDrawerTitle" class="mt-1 text-xl font-bold text-slate-900">User details</h3>
      </div>
      <button type="button" data-close-drawer class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" aria-label="Close user details">
        <i data-lucide="x" class="h-4 w-4"></i>
      </button>
    </div>
    <div class="flex-1 overflow-y-auto px-6 py-6">
      <div class="flex items-center gap-4">
        <div id="detailInitials" class="flex h-14 w-14 items-center justify-center rounded-xl bg-rose-100 text-lg font-bold text-rose-700">L</div>
        <div>
          <h4 id="detailFullName" class="text-lg font-bold text-slate-900"></h4>
          <p id="detailUsername" class="text-sm text-slate-500"></p>
        </div>
      </div>
      <dl class="mt-8 divide-y divide-slate-100 rounded-xl border border-slate-200">
        <div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Email</dt><dd id="detailEmail" class="text-right text-sm font-medium text-slate-800"></dd></div>
        <div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Role</dt><dd id="detailRole" class="text-right text-sm font-medium capitalize text-slate-800"></dd></div>
        <div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Status</dt><dd id="detailStatus" class="text-right text-sm font-medium capitalize text-slate-800"></dd></div>
        <div class="flex items-center justify-between gap-4 px-4 py-3"><dt class="text-sm text-slate-500">Joined</dt><dd id="detailJoined" class="text-right text-sm font-medium text-slate-800"></dd></div>
      </dl>
    </div>
  </aside>
</div>

<div id="toastContainer" class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3"></div>

<script>
(() => {
  const state = {
    allLibrarians: [],
    selectedIds: new Set(),
    editingId: null,
    searchTimer: null,
    pendingDeleteIds: [],
    pendingSavePayload: null,
    pendingStatusId: null,
    currentPage: 1,
    pageSize: 5,
  };

  function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    const isError = type === 'error';
    const duration = 3000;
    toast.className = `pointer-events-auto relative flex items-start gap-3 overflow-hidden rounded-xl border px-4 py-3 pb-4 text-sm shadow-lg ${isError ? 'border-red-300 bg-red-50 text-red-800' : 'border-green-300 bg-green-50 text-green-800'}`;
    toast.innerHTML = `
      <i data-lucide="${isError ? 'circle-alert' : 'circle-check'}" class="mt-0.5 h-4 w-4 flex-shrink-0"></i>
      <span class="flex-1">${esc(message)}</span>
      <button type="button" class="text-current opacity-60 transition hover:opacity-100" aria-label="Dismiss notification">
        <i data-lucide="x" class="h-4 w-4"></i>
      </button>
      <span class="absolute bottom-0 left-0 h-1 w-full origin-left ${isError ? 'bg-red-500' : 'bg-green-500'}" data-toast-progress></span>
    `;
    container.appendChild(toast);
    lucide.createIcons();

    const progress = toast.querySelector('[data-toast-progress]');
    requestAnimationFrame(() => {
      progress.style.transition = `width ${duration}ms linear`;
      progress.style.width = '0%';
    });

    const dismiss = () => toast.remove();
    toast.querySelector('button').addEventListener('click', dismiss);
    setTimeout(dismiss, duration);
  }

  function updateStats() {
    const total = state.allLibrarians.length;
    const active = state.allLibrarians.filter(user => user.status === 'active').length;
    const admins = state.allLibrarians.filter(user => user.role === 'admin').length;

    const totalEl = document.querySelector('[data-stat="total"]');
    const activeEl = document.querySelector('[data-stat="active"]');
    const adminEl = document.querySelector('[data-stat="admins"]');

    if (totalEl) totalEl.textContent = total;
    if (activeEl) activeEl.textContent = active;
    if (adminEl) adminEl.textContent = admins;
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, match => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[match]));
  }

  function setBulkDeleteState() {
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkDeleteBtnLabel = document.getElementById('bulkDeleteBtnLabel');
    const bulkActionBar = document.getElementById('bulkActionBar');
    const selectedCountLabel = document.getElementById('selectedCountLabel');
    const hasSelection = state.selectedIds.size > 0;

    if (bulkActionBar) {
      bulkActionBar.classList.toggle('hidden', !hasSelection);
      bulkActionBar.classList.toggle('flex', hasSelection);
    }

    if (selectedCountLabel) {
      selectedCountLabel.textContent = `${state.selectedIds.size} selected`;
    }

    if (bulkDeleteBtn) {
      bulkDeleteBtn.disabled = !hasSelection;
      bulkDeleteBtn.classList.toggle('opacity-60', !hasSelection);
      bulkDeleteBtn.classList.toggle('cursor-not-allowed', !hasSelection);
    }

    if (bulkDeleteBtnLabel) {
      bulkDeleteBtnLabel.textContent = hasSelection ? `Delete selected (${state.selectedIds.size})` : 'Delete selected';
    }

    document.querySelectorAll('[data-action="delete"][data-id]').forEach(button => {
      const isSelected = state.selectedIds.has(Number(button.dataset.id));
      button.disabled = isSelected;
      button.classList.toggle('opacity-60', isSelected);
      button.classList.toggle('cursor-not-allowed', isSelected);
      button.classList.toggle('pointer-events-none', isSelected);
    });
  }

  function clearSelection() {
    state.selectedIds.clear();
    document.querySelectorAll('[data-select-id]').forEach(checkbox => {
      checkbox.checked = false;
    });
    setBulkDeleteState();
  }

  function renderLibrarians() {
    const list = document.getElementById('librarianList');
    const pagination = document.getElementById('librarianPagination');
    const searchValue = document.getElementById('librarianSearch')?.value.trim().toLowerCase() || '';
    const statusValue = document.getElementById('librarianStatusFilter')?.value || 'all';
    const roleValue = document.getElementById('librarianRoleFilter')?.value || 'all';
    const resultSummary = document.getElementById('librarianResultSummary');

    const visibleLibrarians = state.allLibrarians.filter(librarian => {
      const matchesStatus = statusValue === 'all' || librarian.status === statusValue;
      const matchesRole = roleValue === 'all' || librarian.role === roleValue;
      if (!matchesRole) return false;
      if (!matchesStatus) return false;
      if (!searchValue) return true;

      return (
        librarian.fullName.toLowerCase().includes(searchValue) ||
        librarian.username.toLowerCase().includes(searchValue) ||
        librarian.role.toLowerCase().includes(searchValue)
      );
    });

    if (resultSummary) {
      resultSummary.textContent = `Showing ${visibleLibrarians.length} of ${state.allLibrarians.length} account${state.allLibrarians.length === 1 ? '' : 's'}`;
    }

    if (!visibleLibrarians.length) {
      list.innerHTML = `
        <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center">
          <p class="text-base font-semibold text-slate-700">No accounts found</p>
          <p class="mt-1 text-sm text-slate-500">Try a different search or status filter.</p>
        </div>
      `;
      pagination.innerHTML = '';
      updateStats();
      return;
    }

    const totalPages = Math.ceil(visibleLibrarians.length / state.pageSize);
    state.currentPage = Math.min(state.currentPage, totalPages);
    const pageStart = (state.currentPage - 1) * state.pageSize;
    const paginatedLibrarians = visibleLibrarians.slice(pageStart, pageStart + state.pageSize);

    list.innerHTML = paginatedLibrarians.map(librarian => {
      const isSelected = state.selectedIds.has(librarian.id);
      const isAdmin = librarian.role === 'admin';
      const isActive = librarian.status === 'active';
      const initials = String(librarian.fullName || '')
        .split(' ')
        .map(part => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

      return `
        <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 shadow-sm md:flex-row md:items-center md:justify-between">
          <div class="flex items-center gap-3">
            <input type="checkbox" data-select-id="${librarian.id}" class="h-4 w-4 rounded border-slate-300 text-rose-500 focus:ring-rose-200" ${isSelected ? 'checked' : ''} />

            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-rose-100 font-bold text-rose-700">
              ${esc(initials || 'L')}
            </div>

            <div class="min-w-0">
              <p class="mb-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-400">Account</p>
              <div class="flex items-center gap-2">
                <p class="font-semibold text-slate-900">${esc(librarian.fullName)}</p>
                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold ${isAdmin ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}">
                  ${esc(librarian.role)}
                </span>
              </div>
              <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-500">
                <span><span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Username</span>@${esc(librarian.username)}</span>
                <span><span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Joined</span>${esc(librarian.joinedAt || 'Recently')}</span>
              </div>
            </div>
          </div>

          <div class="flex flex-col gap-1.5 md:flex-shrink-0">
            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-400">Actions</p>
            <div class="flex flex-wrap items-center gap-2">
            <button type="button" data-action="edit" data-id="${librarian.id}" class="inline-flex h-9 w-28 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-rose-200 hover:text-rose-700">
              <i data-lucide="pencil-line" class="h-3.5 w-3.5"></i>
              Edit
            </button>

            <button type="button" data-action="view" data-id="${librarian.id}" class="inline-flex h-9 w-28 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-sky-200 hover:text-sky-700">
              <i data-lucide="eye" class="h-3.5 w-3.5"></i>
              View
            </button>

            <button type="button" data-action="toggle-status" data-id="${librarian.id}" class="inline-flex h-9 w-28 items-center justify-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-700 transition hover:bg-sky-100">
              <i data-lucide="${isActive ? 'user-round-x' : 'user-round-check'}" class="h-3.5 w-3.5"></i>
              ${isActive ? 'Deactivate' : 'Activate'}
            </button>

            <button type="button" data-action="delete" data-id="${librarian.id}" class="inline-flex h-9 w-28 items-center justify-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-600 transition hover:bg-red-100 ${isSelected ? 'opacity-60 cursor-not-allowed' : ''}" ${isSelected ? 'disabled' : ''}>
              <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
              Delete
            </button>
            </div>
          </div>
        </div>
      `;
    }).join('');

    lucide.createIcons();
    bindRowActions();
    renderPagination(totalPages);
    updateStats();
  }

  function renderPagination(totalPages) {
    const pagination = document.getElementById('librarianPagination');
    if (!pagination || totalPages <= 1) {
      if (pagination) pagination.innerHTML = '';
      return;
    }

    const pageButtons = Array.from({ length: totalPages }, (_, index) => {
      const page = index + 1;
      const isCurrent = page === state.currentPage;
      return `
        <button type="button" data-page-number="${page}" aria-label="Go to page ${page}" aria-current="${isCurrent ? 'page' : 'false'}"
          class="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-semibold transition ${isCurrent ? 'bg-[#4B5694] text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-100'}">
          ${page}
        </button>
      `;
    }).join('');

    pagination.innerHTML = `
      <button type="button" data-page-number="${state.currentPage - 1}" aria-label="Previous page" ${state.currentPage === 1 ? 'disabled' : ''}
        class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">
        <i data-lucide="chevron-left" class="h-4 w-4"></i>
      </button>
      ${pageButtons}
      <button type="button" data-page-number="${state.currentPage + 1}" aria-label="Next page" ${state.currentPage === totalPages ? 'disabled' : ''}
        class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">
        <i data-lucide="chevron-right" class="h-4 w-4"></i>
      </button>
    `;

    lucide.createIcons();
    pagination.querySelectorAll('[data-page-number]').forEach(button => {
      button.addEventListener('click', () => {
        if (button.disabled) return;
        state.currentPage = Number(button.dataset.pageNumber);
        renderLibrarians();
      });
    });
  }

  function bindRowActions() {
    document.querySelectorAll('[data-action="view"]').forEach(button => {
      button.addEventListener('click', () => {
        const librarian = state.allLibrarians.find(item => item.id === Number(button.dataset.id));
        if (librarian) openUserDetail(librarian);
      });
    });

    document.querySelectorAll('[data-action="edit"]').forEach(button => {
      button.addEventListener('click', () => {
        const id = Number(button.dataset.id);
        const librarian = state.allLibrarians.find(item => item.id === id);
        openModal('edit', librarian);
      });
    });

    document.querySelectorAll('[data-action="delete"]').forEach(button => {
      button.addEventListener('click', () => {
        const id = Number(button.dataset.id);
        removeLibrarian(id);
      });
    });

    document.querySelectorAll('[data-action="toggle-status"]').forEach(button => {
      button.addEventListener('click', () => {
        const id = Number(button.dataset.id);
        const librarian = state.allLibrarians.find(item => item.id === id);
        if (librarian) openStatusConfirmation(librarian);
      });
    });

    document.querySelectorAll('[data-select-id]').forEach(check => {
      check.addEventListener('change', event => {
        const id = Number(event.target.dataset.selectId);
        if (event.target.checked) {
          state.selectedIds.add(id);
        } else {
          state.selectedIds.delete(id);
        }

        setBulkDeleteState();
      });
    });
  }

  function openUserDetail(librarian) {
    const initials = String(librarian.fullName || '')
      .split(' ')
      .map(part => part[0])
      .slice(0, 2)
      .join('')
      .toUpperCase();
    document.getElementById('detailInitials').textContent = initials || 'L';
    document.getElementById('detailFullName').textContent = librarian.fullName;
    document.getElementById('detailUsername').textContent = `@${librarian.username}`;
    document.getElementById('detailEmail').textContent = librarian.email || 'No email recorded';
    document.getElementById('detailRole').textContent = librarian.role;
    document.getElementById('detailStatus').textContent = librarian.status;
    document.getElementById('detailJoined').textContent = librarian.joinedAt || 'Recently';

    const drawer = document.getElementById('userDetailDrawer');
    drawer.classList.remove('hidden');
    drawer.setAttribute('aria-hidden', 'false');
  }

  function closeUserDetail() {
    const drawer = document.getElementById('userDetailDrawer');
    drawer.classList.add('hidden');
    drawer.setAttribute('aria-hidden', 'true');
  }

  function generateUsername(fullName) {
    if (!fullName) return '';
    const parts = fullName.trim().split(/\s+/);
    if (parts.length === 0) return '';
    
    // Take first letter of each name except the last, then full last name
    const initials = parts.slice(0, -1).map(p => p[0].toLowerCase()).join('');
    const lastName = parts[parts.length - 1].toLowerCase();
    
    return initials + lastName;
  }

  function capitalizeFullName(fullName) {
    return String(fullName || '')
      .toLowerCase()
      .replace(/\b\w/g, character => character.toUpperCase());
  }

  function openModal(mode, librarian = null) {
    const modal = document.getElementById('librarianModal');
    const form = document.getElementById('librarianForm');
    const title = document.getElementById('modalTitle');

    state.editingId = librarian ? librarian.id : null;
    title.textContent = mode === 'edit' ? 'Edit Librarian' : 'Add Librarian';

    form.reset();
    document.getElementById('librarianId').value = librarian ? librarian.id : '';
    document.getElementById('fullName').value = librarian ? capitalizeFullName(librarian.fullName) : '';
    document.getElementById('email').value = librarian ? librarian.email : '';
    document.getElementById('role').value = librarian ? librarian.role : 'librarian';

    const fullNameValue = librarian ? capitalizeFullName(librarian.fullName) : '';
    const generatedUsername = generateUsername(fullNameValue);
    document.getElementById('username').value = librarian ? librarian.username : generatedUsername;

    document.getElementById('password').value = '';

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => {
      document.getElementById('fullName').focus();
    }, 50);
  }

  function closeModal() {
    const modal = document.getElementById('librarianModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.getElementById('librarianForm').reset();
    state.editingId = null;
  }

  function fetchLibrarians(search = '') {
    const params = new URLSearchParams({ action: 'list' });

    return fetch('pages/user.php?' + params.toString(), {
      method: 'GET',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(response => response.json())
      .then(result => {
        if (!result.success) {
          throw new Error(result.message || 'Unable to load librarians.');
        }

        state.allLibrarians = result.librarians || [];
        state.currentPage = 1;
        updateStats();
        renderLibrarians();
        setBulkDeleteState();
      })
      .catch(error => {
        const list = document.getElementById('librarianList');
        list.innerHTML = `
          <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            ${esc(error.message || 'Unable to load librarians.')}
          </div>
        `;
      });
  }

  function removeLibrarian(id) {
    const librarian = state.allLibrarians.find(item => item.id === id);
    openDeleteConfirmation([id], `Delete ${librarian ? librarian.fullName : 'this librarian'}?`);
  }

  function handleBulkDelete() {
    if (!state.selectedIds.size) return;

    const selected = [...state.selectedIds];
    openDeleteConfirmation(selected, `Delete ${selected.length} selected librarian(s)?`);
  }

  function closeDeleteConfirmation() {
    const modal = document.getElementById('deleteConfirmModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.getElementById('adminDeletePassword').value = '';
    state.pendingDeleteIds = [];
  }

  function openDeleteConfirmation(ids, message) {
    state.pendingDeleteIds = ids;
    document.getElementById('deleteConfirmMessage').textContent = message;
    document.getElementById('adminDeletePassword').value = '';
    const modal = document.getElementById('deleteConfirmModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.getElementById('adminDeletePassword').focus();
  }

  function confirmPendingDelete() {
    const selected = [...state.pendingDeleteIds];
    if (!selected.length) return;
    const adminPassword = document.getElementById('adminDeletePassword').value;
    if (!adminPassword) {
      showToast('Enter the administrator password to continue.', 'error');
      document.getElementById('adminDeletePassword').focus();
      return;
    }
    closeDeleteConfirmation();

    const deleteRequests = selected.map(id => {
      const formData = new URLSearchParams({ action: 'delete', id: String(id), admin_password: adminPassword });
      return fetch('pages/user.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData.toString()
      }).then(response => response.json());
    });

    Promise.all(deleteRequests)
      .then(results => {
        const failedResult = results.find(result => !result.success);
        if (failedResult) {
          throw new Error(failedResult.message || 'One or more librarians could not be deleted.');
        }

        state.selectedIds.clear();
        return fetchLibrarians(document.getElementById('librarianSearch').value.trim());
      })
      .then(() => {
        setBulkDeleteState();
        showToast(selected.length === 1 ? 'Librarian deleted successfully.' : `${selected.length} librarians deleted successfully.`);
      })
      .catch(error => {
        showToast(error.message || 'Unable to delete selected librarians.', 'error');
      });
  }

  function closeSaveConfirmation() {
    const modal = document.getElementById('saveConfirmModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.getElementById('adminSavePassword').value = '';
    state.pendingSavePayload = null;
  }

  function closeStatusConfirmation() {
    const modal = document.getElementById('statusConfirmModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.getElementById('adminStatusPassword').value = '';
    state.pendingStatusId = null;
  }

  function openStatusConfirmation(librarian) {
    state.pendingStatusId = librarian.id;
    const nextStatus = librarian.status === 'active' ? 'deactivate' : 'activate';
    document.getElementById('statusConfirmTitle').textContent = `${nextStatus[0].toUpperCase()}${nextStatus.slice(1)} account?`;
    document.getElementById('statusConfirmMessage').textContent = `${nextStatus[0].toUpperCase()}${nextStatus.slice(1)} ${librarian.fullName}'s access to AppSys Library.`;
    document.getElementById('adminStatusPassword').value = '';
    const modal = document.getElementById('statusConfirmModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.getElementById('adminStatusPassword').focus();
  }

  function confirmPendingStatus() {
    if (!state.pendingStatusId) return;

    const adminPassword = document.getElementById('adminStatusPassword').value;
    if (!adminPassword) {
      showToast('Enter the administrator password to continue.', 'error');
      document.getElementById('adminStatusPassword').focus();
      return;
    }

    const id = state.pendingStatusId;
    closeStatusConfirmation();
    const payload = new URLSearchParams({ action: 'toggle_status', id: String(id), admin_password: adminPassword });

    fetch('pages/user.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: payload.toString()
    })
      .then(response => response.json())
      .then(result => {
        if (!result.success) throw new Error(result.message || 'Unable to change account status.');
        showToast(result.message);
        return fetchLibrarians(document.getElementById('librarianSearch').value.trim());
      })
      .catch(error => showToast(error.message || 'Unable to change account status.', 'error'));
  }

  function openSaveConfirmation(payload) {
    state.pendingSavePayload = payload;
    document.getElementById('saveConfirmMessage').textContent = 'Please confirm the administrator password to save this user change.';
    document.getElementById('adminSavePassword').value = '';
    const modal = document.getElementById('saveConfirmModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.getElementById('adminSavePassword').focus();
  }

  function confirmPendingSave() {
    if (!state.pendingSavePayload) return;

    const adminPassword = document.getElementById('adminSavePassword').value.trim();
    if (!adminPassword) {
      showToast('Enter the administrator password to continue.', 'error');
      document.getElementById('adminSavePassword').focus();
      return;
    }

    const payload = new URLSearchParams(state.pendingSavePayload.toString());
    payload.set('admin_password', adminPassword);
    closeSaveConfirmation();

    const saveButton = document.getElementById('saveLibrarianBtn');
    const defaultSaveButtonContent = saveButton.innerHTML;
    saveButton.disabled = true;
    saveButton.setAttribute('aria-busy', 'true');
    saveButton.innerHTML = `<i data-lucide="loader-circle" class="mr-2 inline-block h-4 w-4 animate-spin align-[-2px]"></i>Saving...`;
    lucide.createIcons();

    fetch('pages/user.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: payload.toString()
    })
      .then(response => response.json())
      .then(result => {
        if (!result.success) {
          throw new Error(result.message || 'Unable to save librarian.');
        }

        closeModal();
        saveButton.disabled = false;
        saveButton.removeAttribute('aria-busy');
        saveButton.innerHTML = defaultSaveButtonContent;
        const saveMessage = result.emailQueued === true
          ? `${result.message} Password reset link queued for delivery.`
          : result.message;
        showToast(saveMessage);
        return fetchLibrarians(document.getElementById('librarianSearch').value.trim());
      })
      .then(() => {
        document.getElementById('bulkDeleteBtn').disabled = true;
      })
      .catch(error => {
        saveButton.disabled = false;
        saveButton.removeAttribute('aria-busy');
        saveButton.innerHTML = defaultSaveButtonContent;
        lucide.createIcons();
        showToast(error.message || 'Unable to save librarian.', 'error');
      });
  }

  document.getElementById('addLibrarianBtn').addEventListener('click', () => openModal('add'));
  document.getElementById('bulkDeleteBtn').addEventListener('click', handleBulkDelete);
  document.getElementById('clearSelectionBtn').addEventListener('click', clearSelection);
  document.getElementById('cancelDeleteBtn').addEventListener('click', closeDeleteConfirmation);
  document.getElementById('confirmDeleteBtn').addEventListener('click', confirmPendingDelete);
  document.getElementById('cancelSaveBtn').addEventListener('click', closeSaveConfirmation);
  document.getElementById('confirmSaveBtn').addEventListener('click', confirmPendingSave);
  document.getElementById('cancelStatusBtn').addEventListener('click', closeStatusConfirmation);
  document.getElementById('confirmStatusBtn').addEventListener('click', confirmPendingStatus);
  document.getElementById('saveConfirmModal').addEventListener('click', event => {
    if (event.target.id === 'saveConfirmModal') closeSaveConfirmation();
  });
  document.getElementById('deleteConfirmModal').addEventListener('click', event => {
    if (event.target.id === 'deleteConfirmModal') closeDeleteConfirmation();
  });
  document.getElementById('statusConfirmModal').addEventListener('click', event => {
    if (event.target.id === 'statusConfirmModal') closeStatusConfirmation();
  });
  document.querySelectorAll('[data-close-drawer]').forEach(button => {
    button.addEventListener('click', closeUserDetail);
  });

  document.getElementById('librarianSearch').addEventListener('input', function () {
    clearTimeout(state.searchTimer);
    state.searchTimer = setTimeout(() => {
      state.currentPage = 1;
      fetchLibrarians(this.value.trim());
    }, 250);
  });

  document.getElementById('librarianStatusFilter').addEventListener('change', function () {
    state.currentPage = 1;
    renderLibrarians();
  });

  document.getElementById('librarianRoleFilter').addEventListener('change', function () {
    state.currentPage = 1;
    renderLibrarians();
  });

  document.querySelectorAll('[data-close-modal]').forEach(button => {
    button.addEventListener('click', closeModal);
  });

  document.getElementById('librarianModal').addEventListener('click', event => {
    if (event.target.id === 'librarianModal') {
      closeModal();
    }
  });

  document.getElementById('togglePassword').addEventListener('click', function (e) {
    e.preventDefault();
    const passwordInput = document.getElementById('password');
    const toggleBtn = this;
    const icon = toggleBtn.querySelector('i');
    
    if (passwordInput.type === 'password') {
      passwordInput.type = 'text';
      icon.setAttribute('data-lucide', 'eye-off');
    } else {
      passwordInput.type = 'password';
      icon.setAttribute('data-lucide', 'eye');
    }
    
    lucide.createIcons();
  });

  document.getElementById('librarianForm').addEventListener('submit', function (event) {
    event.preventDefault();

    const formData = new FormData(event.target);
    const payload = new URLSearchParams({ action: 'save' });

    payload.set('fullName', capitalizeFullName(String(formData.get('fullName') || '')));
    payload.set('email', String(formData.get('email') || '').trim());
    payload.set('username', String(formData.get('username') || '').trim());
    payload.set('password', String(formData.get('password') || '').trim());
    payload.set('role', String(formData.get('role') || ''));

    if (!payload.get('role')) {
      showToast('Please select a role.', 'error');
      return;
    }

    const editingId = document.getElementById('librarianId').value;
    const normalizedFullName = payload.get('fullName').toLowerCase();
    const normalizedUsername = payload.get('username').toLowerCase();
    const duplicate = state.allLibrarians.find(librarian => {
      if (editingId && librarian.id === Number(editingId)) return false;

      return librarian.fullName.toLowerCase() === normalizedFullName ||
        librarian.username.toLowerCase() === normalizedUsername;
    });

    if (duplicate) {
      const message = duplicate.fullName.toLowerCase() === normalizedFullName
        ? 'That full name is already in use.'
        : 'That username is already in use.';
      showToast(message, 'error');
      return;
    }

    if (editingId) {
      payload.set('id', editingId);
    }

    openSaveConfirmation(payload);
  });

  document.getElementById('bulkDeleteBtn').disabled = true;
  document.getElementById('bulkDeleteBtn').classList.add('opacity-60', 'cursor-not-allowed');
  setBulkDeleteState();
  fetchLibrarians();
})();
</script>
