<?php

function userJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function ensureUserModuleSchema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT email FROM users LIMIT 1');
    } catch (Exception $exception) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email VARCHAR(100)');
    }
}

function seedUserModuleAccounts(PDO $pdo): void
{
    $seedCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'librarian')")->fetchColumn();
    if ((int) $seedCount !== 0) {
        return;
    }

    $seedAccounts = [
        ['username' => 'admin', 'password' => '123', 'full_name' => 'System Admin', 'email' => 'admin@library.com', 'role' => 'admin'],
        ['username' => 'librarian1', 'password' => '123', 'full_name' => 'Maria Santos', 'email' => 'maria@library.com', 'role' => 'librarian'],
        ['username' => 'librarian2', 'password' => '123', 'full_name' => 'John Medina', 'email' => 'john@library.com', 'role' => 'librarian'],
        ['username' => 'librarian3', 'password' => '123', 'full_name' => 'Angela Cruz', 'email' => 'angela@library.com', 'role' => 'librarian'],
    ];

    $seedStmt = $pdo->prepare(
        'INSERT INTO users (username, password, full_name, email, role, is_active) VALUES (:username, :password, :full_name, :email, :role, 1)'
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

function normalizeUserRow(array $user): array
{
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
}

function getUserListPayload(PDO $pdo, string $search = ''): array
{
    $sql = "SELECT id, username, full_name, email, role, is_active, created_at FROM users WHERE role IN ('admin', 'librarian')";
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (full_name LIKE :search_full_name OR username LIKE :search_username OR role LIKE :search_role)';
        $params[':search_full_name'] = '%' . $search . '%';
        $params[':search_username'] = '%' . $search . '%';
        $params[':search_role'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $users = array_map('normalizeUserRow', $stmt->fetchAll());

    return [
        'librarians' => $users,
        'stats' => [
            'total' => count($users),
            'active' => count(array_filter($users, static fn (array $user): bool => $user['status'] === 'active')),
            'admins' => count(array_filter($users, static fn (array $user): bool => $user['role'] === 'admin')),
        ],
    ];
}

function userHasAdminPassword(PDO $pdo, int $adminUserId, string $plainPassword): bool
{
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id AND role = \'admin\' AND is_active = 1 LIMIT 1');
    $stmt->execute([':id' => $adminUserId]);
    $admin = $stmt->fetch();

    return $admin !== false && $plainPassword !== '' && password_verify($plainPassword, (string) $admin['password']);
}

function findDuplicateUser(PDO $pdo, ?int $ignoreId, string $username, string $fullName): ?array
{
    $duplicateSql = 'SELECT id, username, full_name FROM users WHERE (LOWER(username) = LOWER(:username) OR LOWER(full_name) = LOWER(:full_name))';
    $duplicateParams = [':username' => $username, ':full_name' => $fullName];

    if ($ignoreId !== null) {
        $duplicateSql .= ' AND id != :id';
        $duplicateParams[':id'] = $ignoreId;
    }

    $stmt = $pdo->prepare($duplicateSql . ' LIMIT 1');
    $stmt->execute($duplicateParams);

    return $stmt->fetch() ?: null;
}

function saveUserRecord(PDO $pdo, ?int $id, string $fullName, string $email, string $username, string $password, string $role, int $adminUserId, string $adminPassword): array
{
    if (!userHasAdminPassword($pdo, $adminUserId, $adminPassword)) {
        userJson(['success' => false, 'message' => 'The administrator password is incorrect.'], 403);
    }

    if ($fullName === '' || $username === '' || $email === '') {
        userJson(['success' => false, 'message' => 'Full name, email, and username are required.'], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        userJson(['success' => false, 'message' => 'Please provide a valid email address.'], 422);
    }

    if (!in_array($role, ['librarian', 'admin'], true)) {
        userJson(['success' => false, 'message' => 'Please select a valid role.'], 422);
    }

    $duplicateUser = findDuplicateUser($pdo, $id, $username, $fullName);
    if ($duplicateUser) {
        $message = strcasecmp((string) $duplicateUser['username'], $username) === 0
            ? 'That username is already in use.'
            : 'That full name is already in use.';
        userJson(['success' => false, 'message' => $message], 409);
    }

    if ($id !== null) {
        $existing = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
        $existing->execute([':id' => $id]);
        $existingUser = $existing->fetch();
        $hashedPassword = $existingUser['password'] ?? null;

        if ($password !== '') {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        }

        $stmt = $pdo->prepare('UPDATE users SET username = :username, password = :password, full_name = :full_name, email = :email, role = :role WHERE id = :id');
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
        $stmt = $pdo->prepare('INSERT INTO users (username, password, full_name, email, role, is_active) VALUES (:username, :password, :full_name, :email, :role, 1)');
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

    $emailQueued = $id !== null && !isset($existingUser) && $password === '';

    $selectStmt = $pdo->prepare('SELECT id, username, full_name, email, role, is_active, created_at FROM users WHERE id = :id LIMIT 1');
    $selectStmt->execute([':id' => $id]);
    $user = $selectStmt->fetch();

    return [
        'success' => true,
        'message' => $message,
        'emailQueued' => $emailQueued,
        'librarian' => normalizeUserRow($user),
    ];
}

function deleteUserRecord(PDO $pdo, int $id, int $adminUserId, string $adminPassword): array
{
    if (!userHasAdminPassword($pdo, $adminUserId, $adminPassword)) {
        userJson(['success' => false, 'message' => 'The administrator password is incorrect.'], 403);
    }

    $targetStmt = $pdo->prepare('SELECT id, username, full_name FROM users WHERE id = :id AND role IN (\'admin\', \'librarian\') LIMIT 1');
    $targetStmt->execute([':id' => $id]);
    $target = $targetStmt->fetch();

    if (!$target) {
        userJson(['success' => false, 'message' => 'Librarian not found.'], 404);
    }

    $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = :id AND role IN (\'admin\', \'librarian\')');
    $deleteStmt->execute([':id' => $id]);

    $adminStmt = $pdo->prepare('SELECT id, username FROM users WHERE id = :id AND role = \'admin\' AND is_active = 1 LIMIT 1');
    $adminStmt->execute([':id' => $adminUserId]);
    $admin = $adminStmt->fetch();

    if ($admin) {
        recordSecurityLog($pdo, (int) $admin['id'], $admin['username'], 'user_deleted', 'warning', 'Deleted user ' . $target['username'] . ' (' . $target['full_name'] . ').', getClientIp());
        recordActivityLog($pdo, (int) $admin['id'], 'delete_user', 'Deleted user ' . $target['username'] . '.');
    }

    return ['success' => true, 'deletedId' => $id, 'message' => 'Librarian deleted successfully.'];
}

function toggleUserStatusRecord(PDO $pdo, int $id, int $adminUserId, string $adminPassword): array
{
    if (!userHasAdminPassword($pdo, $adminUserId, $adminPassword)) {
        userJson(['success' => false, 'message' => 'The administrator password is incorrect.'], 403);
    }

    if ($id === $adminUserId) {
        userJson(['success' => false, 'message' => 'You cannot change your own account status.'], 422);
    }

    $targetStmt = $pdo->prepare('SELECT id, username, full_name, is_active FROM users WHERE id = :id AND role IN (\'admin\', \'librarian\') LIMIT 1');
    $targetStmt->execute([':id' => $id]);
    $target = $targetStmt->fetch();

    if (!$target) {
        userJson(['success' => false, 'message' => 'Librarian not found.'], 404);
    }

    $newStatus = (int) $target['is_active'] === 1 ? 0 : 1;
    $statusStmt = $pdo->prepare('UPDATE users SET is_active = :is_active WHERE id = :id');
    $statusStmt->execute([':is_active' => $newStatus, ':id' => $id]);
    $statusLabel = $newStatus === 1 ? 'activated' : 'deactivated';

    $adminStmt = $pdo->prepare('SELECT id, username FROM users WHERE id = :id AND role = \'admin\' AND is_active = 1 LIMIT 1');
    $adminStmt->execute([':id' => $adminUserId]);
    $admin = $adminStmt->fetch();

    if ($admin) {
        recordSecurityLog($pdo, (int) $admin['id'], $admin['username'], 'user_status_changed', 'warning', ucfirst($statusLabel) . ' user ' . $target['username'] . ' (' . $target['full_name'] . ').', getClientIp());
        recordActivityLog($pdo, (int) $admin['id'], 'change_user_status', ucfirst($statusLabel) . ' user ' . $target['username'] . '.', 'user', $id);
    }

    return [
        'success' => true,
        'status' => $newStatus === 1 ? 'active' : 'inactive',
        'message' => 'Librarian ' . $statusLabel . ' successfully.',
    ];
}
