<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=organization');
    exit;
}
requireLogin();

function organizationJson(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action !== null) {
    if (!$pdo) organizationJson(['success' => false, 'message' => 'Database unavailable.'], 503);
    if ($action !== 'list' && strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
        organizationJson(['success' => false, 'message' => 'Admin access required.'], 403);
    }

    try {
        if ($action === 'records') {
            $records = [];
            $queries = [
                ['table' => 'colleges', 'type' => 'University / College', 'parentColumn' => null],
                ['table' => 'departments', 'type' => 'Department', 'parentColumn' => 'college_id'],
                ['table' => 'programs', 'type' => 'Program', 'parentColumn' => 'department_id'],
                ['table' => 'majors', 'type' => 'Major', 'parentColumn' => 'program_id'],
            ];
            foreach ($queries as $query) {
                $parentSelect = $query['parentColumn'] ? ', ' . $query['parentColumn'] : '';
                $rows = $pdo->query("SELECT id, name, code, status, created_at{$parentSelect} FROM {$query['table']} ORDER BY name")->fetchAll();
                foreach ($rows as $row) {
                    $records[] = [
                        'id' => (int)$row['id'], 'name' => $row['name'], 'code' => $row['code'] ?? '',
                        'type' => $query['type'], 'entity' => rtrim($query['table'], 's'), 'parentId' => $query['parentColumn'] ? (int)$row[$query['parentColumn']] : null,
                        'status' => $row['status'] ?? 'active', 'createdAt' => $row['created_at'],
                    ];
                }
            }
            organizationJson(['success' => true, 'records' => $records]);
        }

        if ($action === 'list') {
            $colleges = $pdo->query('SELECT id, name, code, status FROM colleges ORDER BY name')->fetchAll();
            foreach ($colleges as &$college) {
                $stmt = $pdo->prepare('SELECT id, name, code, status FROM departments WHERE college_id = :id ORDER BY name');
                $stmt->execute([':id' => $college['id']]);
                $college['departments'] = $stmt->fetchAll();
                foreach ($college['departments'] as &$department) {
                    $stmt = $pdo->prepare('SELECT id, name, code, status FROM programs WHERE department_id = :id ORDER BY name');
                    $stmt->execute([':id' => $department['id']]);
                    $department['programs'] = $stmt->fetchAll();
                    foreach ($department['programs'] as &$program) {
                        $stmt = $pdo->prepare('SELECT id, name, code, status FROM majors WHERE program_id = :id ORDER BY name');
                        $stmt->execute([':id' => $program['id']]);
                        $program['majors'] = $stmt->fetchAll();
                        $stmt = $pdo->prepare('SELECT id, major_id, code, name, year_level FROM courses WHERE program_id = :id ORDER BY code');
                        $stmt->execute([':id' => $program['id']]);
                        $program['courses'] = $stmt->fetchAll();
                        $stmt = $pdo->prepare('SELECT file_name, file_path FROM program_prospectuses WHERE program_id = :id LIMIT 1');
                        $stmt->execute([':id' => $program['id']]);
                        $program['prospectus'] = $stmt->fetch() ?: null;
                    }
                }
            }
            unset($college, $department, $program);
            $counts = [];
            foreach (['colleges', 'departments', 'programs', 'majors', 'courses'] as $table) {
                $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            }
            organizationJson(['success' => true, 'colleges' => $colleges, 'counts' => $counts]);
        }

        if ($action === 'upload_prospectus') {
            $programId = (int)($_POST['program_id'] ?? 0);
            $file = $_FILES['prospectus'] ?? null;
            if (!$programId || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024 || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
                organizationJson(['success' => false, 'message' => 'Please select a PDF prospectus no larger than 10 MB.'], 422);
            }
            $directory = __DIR__ . '/../uploads/prospectuses';
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) organizationJson(['success' => false, 'message' => 'Upload directory is unavailable.'], 500);
            $storedName = bin2hex(random_bytes(12)) . '.pdf';
            if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $storedName)) organizationJson(['success' => false, 'message' => 'The prospectus could not be uploaded.'], 500);
            $stmt = $pdo->prepare('INSERT INTO program_prospectuses (program_id, file_name, file_path) VALUES (:program_id, :file_name, :file_path) ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_path = VALUES(file_path), uploaded_at = CURRENT_TIMESTAMP');
            $stmt->execute([':program_id' => $programId, ':file_name' => basename($file['name']), ':file_path' => 'uploads/prospectuses/' . $storedName]);
            organizationJson(['success' => true, 'message' => 'Prospectus uploaded successfully.']);
        }

        if ($action === 'create_hierarchy') {
            $hierarchy = json_decode((string)($_POST['hierarchy'] ?? ''), true);
            $collegeName = trim((string)($hierarchy['name'] ?? ''));
            if ($collegeName === '') organizationJson(['success' => false, 'message' => 'College name is required.'], 422);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO colleges (name) VALUES (:name)');
                $stmt->execute([':name' => $collegeName]);
                $collegeId = (int)$pdo->lastInsertId();

                foreach (($hierarchy['departments'] ?? []) as $department) {
                    $departmentName = trim((string)($department['name'] ?? ''));
                    if ($departmentName === '') continue;
                    $stmt = $pdo->prepare('INSERT INTO departments (college_id, name) VALUES (:college_id, :name)');
                    $stmt->execute([':college_id' => $collegeId, ':name' => $departmentName]);
                    $departmentId = (int)$pdo->lastInsertId();

                    foreach (($department['programs'] ?? []) as $program) {
                        $programName = trim((string)($program['name'] ?? ''));
                        if ($programName === '') continue;
                        $stmt = $pdo->prepare('INSERT INTO programs (department_id, name) VALUES (:department_id, :name)');
                        $stmt->execute([':department_id' => $departmentId, ':name' => $programName]);
                        $programId = (int)$pdo->lastInsertId();

                        foreach (($program['majors'] ?? []) as $major) {
                            $majorName = trim((string)($major['name'] ?? ''));
                            if ($majorName === '') continue;
                            $stmt = $pdo->prepare('INSERT INTO majors (program_id, name) VALUES (:program_id, :name)');
                            $stmt->execute([':program_id' => $programId, ':name' => $majorName]);
                            $majorId = (int)$pdo->lastInsertId();
                            foreach (($major['courses'] ?? []) as $course) {
                                $courseName = trim((string)($course['name'] ?? ''));
                                $courseCode = strtoupper(trim((string)($course['code'] ?? '')));
                                if ($courseName === '' || $courseCode === '') continue;
                                $stmt = $pdo->prepare('INSERT INTO courses (program_id, major_id, code, name, year_level) VALUES (:program_id, :major_id, :code, :name, :year_level)');
                                $stmt->bindValue(':program_id', $programId, PDO::PARAM_INT);
                                $stmt->bindValue(':major_id', $majorId, PDO::PARAM_INT);
                                $stmt->bindValue(':code', $courseCode);
                                $stmt->bindValue(':name', $courseName);
                                $stmt->bindValue(':year_level', ($course['year_level'] ?? '') !== '' ? (int)$course['year_level'] : null, ($course['year_level'] ?? '') !== '' ? PDO::PARAM_INT : PDO::PARAM_NULL);
                                $stmt->execute();
                            }
                        }

                        foreach (($program['courses'] ?? []) as $course) {
                            $courseName = trim((string)($course['name'] ?? ''));
                            $courseCode = strtoupper(trim((string)($course['code'] ?? '')));
                            if ($courseName === '' || $courseCode === '') continue;
                            $stmt = $pdo->prepare('INSERT INTO courses (program_id, code, name, year_level) VALUES (:program_id, :code, :name, :year_level)');
                            $stmt->bindValue(':program_id', $programId, PDO::PARAM_INT);
                            $stmt->bindValue(':code', $courseCode);
                            $stmt->bindValue(':name', $courseName);
                            $stmt->bindValue(':year_level', ($course['year_level'] ?? '') !== '' ? (int)$course['year_level'] : null, ($course['year_level'] ?? '') !== '' ? PDO::PARAM_INT : PDO::PARAM_NULL);
                            $stmt->execute();
                        }
                    }
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
            organizationJson(['success' => true, 'message' => 'College hierarchy created successfully.']);
        }

        $entityActions = [
            'college' => ['table' => 'colleges', 'parent' => null],
            'department' => ['table' => 'departments', 'parent' => 'college_id'],
            'program' => ['table' => 'programs', 'parent' => 'department_id'],
            'major' => ['table' => 'majors', 'parent' => 'program_id'],
        ];
        $entity = preg_replace('/^(add|edit|delete|archive)_/', '', $action);
        $operation = substr($action, 0, strpos($action, '_'));
        if ($operation === 'delete') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $passwordStmt->execute([':id' => (int)$_SESSION['user_id']]);
            $userPassword = (string)($passwordStmt->fetchColumn() ?: '');
            if ($currentPassword === '' || $userPassword === '' || !password_verify($currentPassword, $userPassword)) {
                organizationJson(['success' => false, 'message' => 'The current password is incorrect. Nothing was deleted.'], 403);
            }
        }
        if ($entity === 'course') {
            $table = 'courses';
            $id = (int)($_POST['id'] ?? 0);
            if ($operation === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM courses WHERE id = :id');
                $stmt->execute([':id' => $id]);
            } else {
                $name = trim((string)($_POST['name'] ?? ''));
                $code = strtoupper(trim((string)($_POST['organization_code'] ?? $_POST['code'] ?? '')));
                $programId = (int)($_POST['program_id'] ?? $_POST['parent_id'] ?? 0);
                $majorId = (int)($_POST['major_id'] ?? 0) ?: null;
                if ($name === '' || $code === '' || !$programId) organizationJson(['success' => false, 'message' => 'Course name, code, and program are required.'], 422);
                if ($operation === 'add') {
                    $stmt = $pdo->prepare('INSERT INTO courses (program_id, major_id, code, name, year_level) VALUES (:program_id, :major_id, :code, :name, :year_level)');
                } else {
                    $stmt = $pdo->prepare('UPDATE courses SET program_id = :program_id, major_id = :major_id, code = :code, name = :name, year_level = :year_level WHERE id = :id');
                    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                }
                $stmt->bindValue(':program_id', $programId, PDO::PARAM_INT);
                $stmt->bindValue(':major_id', $majorId, $majorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':code', $code);
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':year_level', ($_POST['year_level'] ?? '') !== '' ? (int)$_POST['year_level'] : null, ($_POST['year_level'] ?? '') !== '' ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmt->execute();
            }
        } elseif (isset($entityActions[$entity])) {
            $config = $entityActions[$entity];
            $id = (int)($_POST['id'] ?? 0);
            if ($operation === 'archive') {
                $stmt = $pdo->prepare("UPDATE {$config['table']} SET status = CASE WHEN status = 'archived' THEN 'active' ELSE 'archived' END WHERE id = :id");
                $stmt->execute([':id' => $id]);
                organizationJson(['success' => true, 'message' => 'Organization status updated successfully.']);
            }
            if ($operation === 'delete') {
                $dependentQueries = [
                    'college' => 'SELECT COUNT(*) FROM departments WHERE college_id = :id',
                    'department' => 'SELECT COUNT(*) FROM programs WHERE department_id = :id',
                    'program' => 'SELECT COUNT(*) FROM majors WHERE program_id = :id',
                    'major' => 'SELECT COUNT(*) FROM courses WHERE major_id = :id',
                ];
                $dependencyStmt = $pdo->prepare($dependentQueries[$entity]);
                $dependencyStmt->execute([':id' => $id]);
                $dependencyCount = (int)$dependencyStmt->fetchColumn();
                if ($entity === 'program') {
                    $courseStmt = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE program_id = :id');
                    $courseStmt->execute([':id' => $id]);
                    $dependencyCount += (int)$courseStmt->fetchColumn();
                    $prospectusStmt = $pdo->prepare('SELECT COUNT(*) FROM program_prospectuses WHERE program_id = :id');
                    $prospectusStmt->execute([':id' => $id]);
                    $dependencyCount += (int)$prospectusStmt->fetchColumn();
                }
                if ($dependencyCount > 0) {
                    organizationJson(['success' => false, 'message' => 'This organization is still used by related records. Archive it instead or remove its dependent records first.'], 409);
                }
                $stmt = $pdo->prepare("DELETE FROM {$config['table']} WHERE id = :id");
                $stmt->execute([':id' => $id]);
            } else {
                $name = trim((string)($_POST['name'] ?? ''));
                $code = strtoupper(trim((string)($_POST['code'] ?? '')));
                $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
                if ($name === '') organizationJson(['success' => false, 'message' => 'A name is required.'], 422);
                if ($operation === 'add') {
                    $sql = "INSERT INTO {$config['table']} (" . ($config['parent'] ? $config['parent'] . ', ' : '') . "name, code, status) VALUES (" . ($config['parent'] ? ':parent_id, ' : '') . ':name, :code, :status)';
                } else {
                    $sql = "UPDATE {$config['table']} SET " . ($config['parent'] ? $config['parent'] . ' = :parent_id, ' : '') . "name = :name, code = :code, status = :status WHERE id = :id";
                }
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':code', $code !== '' ? $code : null, $code !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':status', $status);
                if ($config['parent']) $stmt->bindValue(':parent_id', (int)($_POST['parent_id'] ?? 0), PDO::PARAM_INT);
                if ($operation === 'edit') $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
            }
        } else {
            organizationJson(['success' => false, 'message' => 'Unknown organization action.'], 400);
        }
        organizationJson(['success' => true, 'message' => 'Organization updated successfully.']);
    } catch (PDOException $exception) {
        organizationJson(['success' => false, 'message' => (($exception->errorInfo[1] ?? 0) === 1062) ? 'That name or course code already exists at this level.' : 'Unable to update the organization.'], 409);
    }
}
?>
<div class="space-y-8">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div><h2 class="text-3xl font-bold text-slate-900">Organization Management</h2><p class="mt-1 text-sm text-slate-500">Manage colleges, departments, programs, majors, and courses.</p></div>
        <div class="flex flex-wrap gap-3">
            <?php foreach (['colleges','departments','programs','majors','courses'] as $count): ?><div class="flex flex-col items-center border-b-2 border-rose-<?= $count === 'colleges' ? '600' : '300' ?> px-4 pb-2"><span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"><?= ucfirst($count) ?></span><span data-count="<?= $count ?>" class="mt-1 text-2xl font-bold text-slate-900">0</span></div><?php endforeach; ?>
        </div>
  </div>
    <div class="space-y-6">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2"><i data-lucide="building-2" class="h-5 w-5 text-rose-600"></i><div><h3 class="text-xl font-bold text-slate-900">Institutional Structure</h3><p class="text-sm text-slate-500">Start with a college, then add its departments, programs, majors, and courses.</p></div></div>
                    <div class="flex items-center gap-3"><span id="organizationStatus" class="text-xs text-slate-600" aria-live="polite">Loading organization...</span><button type="button" id="retryOrganizationBtn" class="hidden text-xs font-semibold text-rose-700 underline underline-offset-2">Retry</button><button type="button" id="addCollegeBtn" class="inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rose-300 focus:ring-offset-2 active:scale-95"><i data-lucide="plus" class="h-4 w-4"></i> Add College</button></div>
                </div>
                <div id="organizationTree" class="space-y-2"></div>
        </section>
        <div class="grid grid-cols-12 items-start gap-6">
            <details class="col-span-12 rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-8">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 [&::-webkit-details-marker]:hidden">
                    <div><h3 class="text-xl font-bold text-slate-900">Browse all records</h3><p class="text-sm text-slate-500">Search, filter, archive, and maintain stored organization information.</p></div>
                    <i data-lucide="chevron-down" class="h-5 w-5 shrink-0 text-slate-500 transition-transform"></i>
                </summary>
                <div class="mt-5">
                <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div class="flex flex-col gap-2 sm:flex-row"><input id="organizationSearch" type="search" placeholder="Search organizations" class="rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><select id="organizationStatusFilter" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="all">All statuses</option><option value="active">Active</option><option value="archived">Archived</option></select><select id="organizationTypeFilter" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="all">All types</option><option value="college">University / College</option><option value="department">Department</option><option value="program">Program</option><option value="major">Major</option></select></div>
                </div>
                <div class="overflow-x-auto"><table class="w-full min-w-[700px] text-left text-sm"><thead class="border-y border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-3">Organization Name</th><th class="px-3 py-3">Code</th><th class="px-3 py-3">Type</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Actions</th></tr></thead><tbody id="organizationRecordsBody" class="divide-y divide-slate-100"></tbody></table></div>
                </div>
            </details>
            </section>
            <section class="col-span-12 flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-4">
                <div class="mb-5 flex items-center gap-2"><i data-lucide="file-text" class="h-5 w-5 text-rose-600"></i><h3 class="font-bold text-slate-900">Course Prospectus</h3></div>
                <div id="prospectusList" class="max-h-[420px] flex-1 space-y-3 overflow-y-auto pr-1"></div>
                <form id="prospectusForm" class="mt-4 min-w-0 space-y-2">
                    <select name="program_id" id="prospectusProgram" required class="h-10 w-full min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm"><option value="">Select a program</option></select>
                    <input type="file" name="prospectus" accept="application/pdf,.pdf" required class="block h-auto w-full min-w-0 max-w-full rounded-xl border-2 border-dashed border-rose-300 bg-rose-50 p-3 text-xs text-slate-700">
                    <button type="submit" class="w-full rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700">Upload PDF</button>
                </form>
            </section>
        </div>
    </div>
</div>
<div id="organizationModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"><form id="organizationForm" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><h3 id="organizationModalTitle" class="text-xl font-bold">Add College</h3><button type="button" id="closeOrganizationModal" class="text-slate-400" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button></div><input type="hidden" id="organizationAction" name="action"><input type="hidden" id="organizationId" name="id"><input type="hidden" id="organizationParent" name="parent_id"><input type="hidden" id="organizationMajor" name="major_id"><label id="parentSelectLabel" class="mt-5 hidden text-sm font-medium">Parent organization<select id="parentSelect" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"></select></label><label id="majorSelectLabel" class="mt-3 hidden text-sm font-medium">Major<select id="majorSelect" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="">No major</option></select></label><div id="courseFields" class="mt-5 hidden grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium">Course code<input name="code" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><label class="text-sm font-medium">Year level<input name="year_level" type="number" min="1" max="8" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label></div><label id="organizationCodeLabel" class="mt-5 block text-sm font-medium">Organization code<input name="organization_code" id="organizationCode" maxlength="30" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><label id="organizationStatusLabel" class="mt-3 block text-sm font-medium">Status<select name="status" id="organizationRecordStatus" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="active">Active</option><option value="archived">Archived</option></select></label><label class="mt-3 block text-sm font-medium">Name<input id="organizationName" name="name" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><button class="mt-5 w-full rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white">Save</button></form></div>
<div id="toastContainer" class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3"></div>
<script>
(() => {
    const tree = document.getElementById('organizationTree');
    const modal = document.getElementById('organizationModal');
    const form = document.getElementById('organizationForm');
    const prospectusList = document.getElementById('prospectusList');
    const prospectusForm = document.getElementById('prospectusForm');
    const organizationStatus = document.getElementById('organizationStatus');
    const retryOrganizationBtn = document.getElementById('retryOrganizationBtn');
    const applyInputOutline = container => container.querySelectorAll('input:not([type="hidden"])').forEach(input => input.classList.add('border-2', 'border-slate-300', 'outline-none', 'focus:border-rose-600', 'focus:ring-2', 'focus:ring-rose-100'));
    applyInputOutline(form);
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const setOrganizationStatus = (message, error = false) => {
        organizationStatus.textContent = message;
        organizationStatus.classList.toggle('text-red-700', error);
        organizationStatus.classList.toggle('text-slate-600', !error);
        retryOrganizationBtn.classList.toggle('hidden', !error);
    };
    function toast(message, error = false) {
        const container = document.getElementById('toastContainer');
        const notification = document.createElement('div');
        const duration = 3000;

        notification.className = `pointer-events-auto relative flex items-start gap-3 overflow-hidden rounded-xl border px-4 py-3 pb-4 text-sm shadow-lg ${error ? 'border-red-300 bg-red-50 text-red-800' : 'border-green-300 bg-green-50 text-green-800'}`;
        notification.innerHTML = `
            <i data-lucide="${error ? 'circle-alert' : 'circle-check'}" class="mt-0.5 h-4 w-4 flex-shrink-0"></i>
            <span class="flex-1">${esc(message)}</span>
            <button type="button" class="text-current opacity-60 transition hover:opacity-100" aria-label="Dismiss notification">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
            <span class="absolute bottom-0 left-0 h-1 w-full origin-left ${error ? 'bg-red-500' : 'bg-green-500'}" data-toast-progress></span>
        `;
        container.appendChild(notification);
        lucide.createIcons();

        const progress = notification.querySelector('[data-toast-progress]');
        requestAnimationFrame(() => {
            progress.style.transition = `width ${duration}ms linear`;
            progress.style.width = '0%';
        });

        const dismiss = () => notification.remove();
        notification.querySelector('button').addEventListener('click', dismiss);
        setTimeout(dismiss, duration);
    }
  let organizationData = { colleges: [] };
  const parentOptions = (type, selected) => {
      const options = [];
      organizationData.colleges.forEach(college => {
          if (type === 'department') options.push({ id: college.id, name: college.name });
          (college.departments || []).forEach(department => {
              if (type === 'program') options.push({ id: department.id, name: `${college.name} / ${department.name}` });
              (department.programs || []).forEach(program => {
                  if (type === 'major' || type === 'course') options.push({ id: program.id, name: `${department.name} / ${program.name}` });
              });
          });
      });
      return '<option value="">Select parent</option>' + options.map(option => `<option value="${option.id}" ${Number(option.id) === Number(selected) ? 'selected' : ''}>${esc(option.name)}</option>`).join('');
  };
  const openForm = (action, title, id = '', parent = '', item = {}, major = '') => {
      form.reset();
      document.getElementById('organizationAction').value = action;
      document.getElementById('organizationId').value = id;
      document.getElementById('organizationParent').value = parent;
      document.getElementById('organizationMajor').value = major || item.major_id || '';
      document.getElementById('organizationModalTitle').textContent = title;
      document.getElementById('courseFields').classList.toggle('hidden', !action.endsWith('course'));
    document.getElementById('organizationCodeLabel').classList.toggle('hidden', action.endsWith('course'));
    document.getElementById('organizationStatusLabel').classList.toggle('hidden', action.endsWith('course'));
      document.getElementById('organizationName').value = item.name || '';
    document.getElementById('organizationCode').value = item.code || '';
    document.getElementById('organizationRecordStatus').value = item.status || 'active';
      if (item.code) form.code.value = item.code;
      if (item.year_level) form.year_level.value = item.year_level;
      const entity = action.replace(/^(add|edit)_/, '');
      const isEdit = action.startsWith('edit_');
      const parentSelect = document.getElementById('parentSelect');
      const parentLabel = document.getElementById('parentSelectLabel');
      parentLabel.classList.toggle('hidden', !isEdit || entity === 'college');
      if (isEdit && entity !== 'college') {
          parentSelect.innerHTML = parentOptions(entity, parent);
          parentSelect.onchange = () => { document.getElementById('organizationParent').value = parentSelect.value; };
      }
      const majorLabel = document.getElementById('majorSelectLabel');
      majorLabel.classList.toggle('hidden', !isEdit || entity !== 'course');
      if (isEdit && entity === 'course') {
          const program = organizationData.colleges.flatMap(c => c.departments || []).flatMap(d => d.programs || []).find(p => Number(p.id) === Number(parent));
          document.getElementById('majorSelect').innerHTML = '<option value="">No major</option>' + (program?.majors || []).map(majorItem => `<option value="${majorItem.id}" ${Number(majorItem.id) === Number(item.major_id) ? 'selected' : ''}>${esc(majorItem.name)}</option>`).join('');
          document.getElementById('majorSelect').onchange = () => { document.getElementById('organizationMajor').value = document.getElementById('majorSelect').value; };
      }
      modal.classList.remove('hidden'); modal.classList.add('flex'); document.getElementById('organizationName').focus();
  };
    const controls = (type, item, parent) => `<span class="flex flex-wrap items-center gap-1"><button type="button" data-edit="${type}" data-id="${item.id}" data-parent="${parent}" data-item='${esc(JSON.stringify(item))}' class="inline-flex whitespace-nowrap rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700 shadow-sm transition hover:border-rose-500 hover:bg-rose-600 hover:text-white hover:shadow focus:outline-none focus:ring-2 focus:ring-rose-200">Edit</button><button type="button" data-delete="${type}" data-id="${item.id}" data-name="${esc(item.name)}" class="inline-flex whitespace-nowrap rounded-md border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-semibold text-red-600 shadow-sm transition hover:border-red-500 hover:bg-red-600 hover:text-white hover:shadow focus:outline-none focus:ring-2 focus:ring-red-200">Delete</button></span>`;
    const add = (action, title, parent, major = '') => `<button type="button" data-add="${action}" data-title="${title}" data-parent="${parent}" data-major="${major}" class="inline-flex whitespace-nowrap rounded-md border border-rose-300 bg-white px-2 py-1 text-[11px] font-semibold text-rose-600 shadow-sm transition hover:border-rose-600 hover:bg-rose-600 hover:text-white hover:shadow focus:outline-none focus:ring-2 focus:ring-rose-200 active:scale-95">+ ${title}</button>`;
    let hierarchyBuilder;
    function createHierarchyBuilder() {
        if (hierarchyBuilder) return hierarchyBuilder;
        hierarchyBuilder = document.createElement('div');
        hierarchyBuilder.id = 'hierarchyBuilder';
        hierarchyBuilder.className = 'mt-5 hidden min-w-0 space-y-3';
        hierarchyBuilder.innerHTML = '<label class="block text-sm font-medium">College name<input data-field="college-name" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"></label><div data-departments class="space-y-3 border-l border-rose-200 pl-3"></div><button type="button" data-builder-add="department" class="text-sm font-semibold text-rose-600">+ Add department</button>';
        applyInputOutline(hierarchyBuilder);
        form.querySelector('#organizationMajor').after(hierarchyBuilder);
        hierarchyBuilder.addEventListener('click', event => {
            const action = event.target.closest('[data-builder-add]')?.dataset.builderAdd;
            const remove = event.target.closest('[data-builder-remove]');
            if (remove) remove.closest('[data-builder-item]')?.remove();
            if (action === 'department') addDepartment();
            if (action === 'program') addProgram(event.target.closest('[data-builder-item]'));
            if (action === 'major') addMajor(event.target.closest('[data-builder-item]'));
            if (action === 'course') addCourse(event.target.closest('[data-builder-item]'));
            applyInputOutline(hierarchyBuilder);
        });
        return hierarchyBuilder;
    }
    function addDepartment() {
        const department = document.createElement('div');
        department.dataset.builderItem = 'department';
        department.className = 'rounded-lg border border-slate-200 bg-slate-50 p-3';
        department.innerHTML = '<p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Department under College</p><div class="flex gap-2"><input data-field="name" placeholder="Department name" required class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="px-2 text-sm text-red-500">Remove</button></div><div data-programs class="mt-3 space-y-3 border-l border-slate-200 pl-3"></div><button type="button" data-builder-add="program" class="mt-3 text-xs font-semibold text-rose-600">+ Add program</button>';
        hierarchyBuilder.querySelector('[data-departments]').append(department);
    }
    function addProgram(department) {
        if (!department) return;
        const program = document.createElement('div');
        program.dataset.builderItem = 'program';
        program.className = 'rounded-lg border-l border-rose-200 bg-white p-3';
        program.innerHTML = '<p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Program under Department</p><div class="flex gap-2"><input data-field="name" placeholder="Program name" required class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="px-2 text-sm text-red-500">Remove</button></div><div data-majors class="mt-3 space-y-2 border-l border-rose-200 pl-3"></div><button type="button" data-builder-add="major" class="mt-2 text-xs font-semibold text-rose-600">+ Add major</button><div data-courses class="mt-3 space-y-2"></div><button type="button" data-builder-add="course" class="mt-2 text-xs font-semibold text-rose-600">+ Add course to program</button>';
        department.querySelector('[data-programs]').append(program);
    }
    function addMajor(program) {
        if (!program) return;
        const major = document.createElement('div');
        major.dataset.builderItem = 'major';
        major.className = 'min-w-0 rounded-lg border-l border-rose-200 p-2';
        major.innerHTML = '<p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Major under Program</p><div class="flex gap-2"><input data-field="name" placeholder="Major name" required class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="px-2 text-sm text-red-500">Remove</button></div><div data-courses class="mt-2 space-y-2 border-l border-rose-200 pl-3"></div><button type="button" data-builder-add="course" class="mt-2 text-xs font-semibold text-rose-600">+ Add course to major</button>';
        program.querySelector('[data-majors]').append(major);
    }
    function addCourse(program) {
        if (!program) return;
        const course = document.createElement('div');
        course.dataset.builderItem = 'course';
        course.className = 'grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-[minmax(5rem,7rem)_minmax(0,1fr)]';
        const owner = program.dataset.builderItem === 'major' ? 'Major' : 'Program';
        course.innerHTML = `<p class="col-span-full text-[10px] font-semibold uppercase tracking-wide text-slate-500">Course under ${owner}</p><input data-field="code" placeholder="Code" required class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><input data-field="name" placeholder="Course name" required class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100 sm:col-span-2"><input data-field="year_level" type="number" min="1" max="8" placeholder="Year" class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="justify-self-start px-2 text-sm text-red-500 sm:justify-self-end">Remove</button>`;
        program.querySelector('[data-courses]').append(course);
    }
    function openHierarchyForm() {
        const builder = createHierarchyBuilder();
        form.reset();
        document.getElementById('organizationAction').value = 'create_hierarchy';
        document.getElementById('organizationModalTitle').textContent = 'Add College Hierarchy';
        document.getElementById('courseFields').classList.add('hidden');
        document.getElementById('organizationName').required = false;
        document.getElementById('organizationName').closest('label').classList.add('hidden');
        document.getElementById('organizationCodeLabel').classList.add('hidden');
        document.getElementById('organizationStatusLabel').classList.add('hidden');
        builder.classList.remove('hidden');
        builder.querySelector('[data-field="college-name"]').required = true;
        builder.querySelector('[data-departments]').innerHTML = '';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        builder.querySelector('[data-field="college-name"]').focus();
    }
    function closeHierarchyForm() {
        if (!hierarchyBuilder) return;
        hierarchyBuilder.classList.add('hidden');
        hierarchyBuilder.querySelector('[data-field="college-name"]').required = false;
        document.getElementById('organizationName').required = true;
        document.getElementById('organizationName').closest('label').classList.remove('hidden');
        document.getElementById('organizationCodeLabel').classList.remove('hidden');
        document.getElementById('organizationStatusLabel').classList.remove('hidden');
    }
    function collectHierarchy() {
        const builder = createHierarchyBuilder();
        return {
            name: builder.querySelector('[data-field="college-name"]').value.trim(),
            departments: [...builder.querySelectorAll('[data-departments] > [data-builder-item]')].map(department => ({
                name: department.querySelector(':scope > div [data-field="name"]').value.trim(),
                programs: [...department.querySelectorAll(':scope > [data-programs] > [data-builder-item]')].map(program => ({
                    name: program.querySelector(':scope > div [data-field="name"]').value.trim(),
                    majors: [...program.querySelectorAll(':scope > [data-majors] > [data-builder-item]')].map(major => ({
                        name: major.querySelector(':scope > div [data-field="name"]').value.trim(),
                        courses: [...major.querySelectorAll(':scope > [data-courses] > [data-builder-item]')].map(course => ({
                            code: course.querySelector('[data-field="code"]').value.trim(),
                            name: course.querySelector('[data-field="name"]').value.trim(),
                            year_level: course.querySelector('[data-field="year_level"]').value,
                        })),
                    })),
                    courses: [...program.querySelectorAll(':scope > [data-courses] > [data-builder-item]')].map(course => ({
                        code: course.querySelector('[data-field="code"]').value.trim(),
                        name: course.querySelector('[data-field="name"]').value.trim(),
                        year_level: course.querySelector('[data-field="year_level"]').value,
                    })),
                })),
            })),
        };
    }
    let deleteDialog;
    function openDeleteDialog(type, id, name = 'this organization') {
        if (!deleteDialog) {
            deleteDialog = document.createElement('div');
            deleteDialog.className = 'fixed inset-0 z-[80] hidden items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm';
            deleteDialog.innerHTML = `<form class="w-full max-w-md rounded-2xl border border-red-200 bg-white p-6 shadow-2xl"><div class="flex items-start gap-3"><div class="rounded-full bg-red-100 p-2 text-red-600"><i data-lucide="triangle-alert" class="h-5 w-5"></i></div><div><h3 class="text-lg font-bold text-slate-900">Confirm deletion</h3><p class="mt-1 text-sm text-slate-500">Are you sure you want to delete this hierarchy entry? This may affect related records.</p></div></div><label class="mt-5 block text-sm font-semibold text-slate-700">Current password<input type="password" data-delete-password autocomplete="current-password" required class="mt-2 w-full rounded-lg border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-red-500 focus:ring-2 focus:ring-red-100" placeholder="Enter your password"></label><div class="mt-6 flex justify-end gap-3"><button type="button" data-delete-cancel class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Cancel</button><button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-300">Delete</button></div></form>`;
            document.body.append(deleteDialog);
            lucide.createIcons();
            deleteDialog.querySelector('[data-delete-cancel]').onclick = () => closeDeleteDialog();
            deleteDialog.querySelector('form').onsubmit = event => {
                event.preventDefault();
                const password = deleteDialog.querySelector('[data-delete-password]').value;
                submit({ action: `delete_${deleteDialog.dataset.type}`, id: deleteDialog.dataset.id, current_password: password });
                closeDeleteDialog();
            };
        }
        deleteDialog.dataset.type = type;
        deleteDialog.dataset.id = id;
        deleteDialog.querySelector('p').textContent = `Delete "${name}"? This permanently removes the organization if it has no related records.`;
        deleteDialog.querySelector('[data-delete-password]').value = '';
        deleteDialog.classList.remove('hidden');
        deleteDialog.classList.add('flex');
        deleteDialog.querySelector('[data-delete-password]').focus();
    }
    function closeDeleteDialog() {
        deleteDialog?.classList.add('hidden');
        deleteDialog?.classList.remove('flex');
    }
    const renderCollege = college => {
        const departmentItems = college.departments || [];
        const departments = departmentItems.map(department => {
            const programs = department.programs.map(program => {
                const majors = program.majors.map(major => {
                    const majorCourses = program.courses.filter(course => Number(course.major_id) === Number(major.id));
                    return `
                    <div class="mt-2">
                        <div class="group flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <i data-lucide="tag" class="h-3.5 w-3.5 shrink-0 text-rose-600"></i>
                            ${esc(major.name)}
                            <span class="ml-auto flex flex-wrap items-center gap-1">${controls('major', major, program.id)}${add('add_course', 'Add course', program.id, major.id)}</span>
                        </div>
                        <div class="mt-1 space-y-1 pl-4">${majorCourses.map(course => `
                            <div class="group flex items-center gap-2 py-0.5 text-xs text-slate-500">
                                <i data-lucide="book-open" class="h-3 w-3 shrink-0"></i>
                                <b class="rounded bg-rose-100 px-1 py-0.5 text-[10px] text-rose-700">${esc(course.code)}</b>
                                <span>${esc(course.name)}</span>
                                <span class="text-[10px] text-slate-400">Yr${course.year_level || '-'}</span>
                                <span class="ml-auto flex gap-1 opacity-0 transition-opacity group-hover:opacity-100">${controls('course', course, program.id)}</span>
                            </div>`).join('') || '<p class="text-xs italic text-slate-400">No courses yet</p>'}</div>
                    </div>`;
                }).join('');
                const courses = program.courses.filter(course => !course.major_id).map(course => `
                    <div class="group flex items-center gap-2 py-0.5 text-xs text-slate-500">
                        <i data-lucide="book-open" class="h-3 w-3 shrink-0"></i>
                        <b class="rounded bg-rose-100 px-1 py-0.5 text-[10px] text-rose-700">${esc(course.code)}</b>
                        <span>${esc(course.name)}</span>
                        <span class="text-[10px] text-slate-400">Yr${course.year_level || '-'} </span>
                        <span class="ml-auto flex gap-1 opacity-0 transition-opacity group-hover:opacity-100">${controls('course', course, program.id)}</span>
                    </div>`).join('');

                return `
                    <div class="mt-2 border-l border-rose-200 pl-5">
                        <div class="group flex items-center gap-2">
                            <i data-lucide="book" class="h-3.5 w-3.5 shrink-0 text-rose-600"></i>
                            <span class="text-sm font-semibold text-slate-700">${esc(program.name)}</span>
                            <span class="ml-auto flex flex-wrap items-center gap-1">${controls('program', program, department.id)}${add('add_major', 'Add major', program.id)}${add('add_course', 'Add course', program.id)}</span>
                        </div>
                        <div class="mt-1 space-y-1 pl-4">${majors || '<p class="text-xs italic text-slate-400">No majors yet</p>'}${courses}</div>
                    </div>`;
            }).join('');

            return `
                <div class="mt-2 border-l border-slate-300 pl-4">
                    <div class="group flex items-center gap-2">
                        <i data-lucide="layers" class="h-3.5 w-3.5 shrink-0 text-slate-500"></i>
                        <span class="text-sm font-semibold text-slate-700">${esc(department.name)}</span>
                        <span class="ml-auto flex flex-wrap items-center gap-1">${controls('department', department, college.id)}${add('add_program', 'Add program', department.id)}</span>
                    </div>
                    ${programs || '<p class="mt-1 pl-4 text-xs italic text-slate-400">No programs yet</p>'}
                </div>`;
        }).join('');

        return `
            <article class="college-row group overflow-hidden rounded-xl border border-transparent bg-slate-50 transition-all hover:border-rose-200">
                <div class="flex cursor-pointer items-center gap-3 p-4" data-college-toggle="${college.id}">
                    <i data-lucide="chevron-right" class="college-chevron h-5 w-5 shrink-0 text-rose-600 transition-transform"></i>
                    <i data-lucide="building-2" class="h-5 w-5 shrink-0 text-rose-600"></i>
                    <div class="min-w-0 flex-1"><h4 class="truncate text-sm font-bold text-slate-800">${esc(college.name)}</h4><p class="text-[10px] uppercase tracking-wide text-slate-500">${departmentItems.length} Department${departmentItems.length === 1 ? '' : 's'}</p></div>
                    <div class="flex flex-wrap items-center gap-1">${controls('college', college)}${add('add_department', 'Add department', college.id)}</div>
                </div>
                <div id="college-${college.id}" class="college-body max-h-0 overflow-hidden px-4 opacity-0 transition-all duration-300 ease-in-out"><div class="overflow-hidden space-y-1 border-t border-slate-200 pb-4 pl-2 pt-2">${departments || '<p class="text-xs italic text-slate-400">No departments yet</p>'}</div></div>
            </article>`;
    };
  function render(data) {
        organizationData = data;
        const expandedColleges = new Set([...tree.querySelectorAll('.college-body:not(.max-h-0)')].map(body => body.id.replace('college-', '')));
    Object.entries(data.counts).forEach(([key, value]) => { const el = document.querySelector(`[data-count="${key}"]`); if (el) el.textContent = value; });
    const programs = [];
    data.colleges.forEach(college => {
        college.departments.forEach(department => {
            department.programs.forEach(program => programs.push(program));
        });
    });
    document.getElementById('prospectusProgram').innerHTML = '<option value="">Select a program</option>' + programs.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
    prospectusList.innerHTML = programs.filter(p => p.prospectus).map(p => `<div class="min-w-0 flex items-center justify-between rounded-xl border border-slate-200 p-3"><div class="min-w-0"><p class="truncate font-semibold">${esc(p.name)}</p><p class="truncate text-xs text-slate-500">${esc(p.prospectus.file_name)}</p></div><a href="${esc(p.prospectus.file_path)}" target="_blank" rel="noopener" class="ml-2 shrink-0 text-xs font-semibold text-rose-600">View PDF</a></div>`).join('') || '<p class="text-sm italic text-slate-400">No prospectuses uploaded yet.</p>';
    tree.innerHTML = data.colleges.length ? data.colleges.map(c => `<article class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><div class="flex items-center justify-between gap-2"><div class="flex items-center gap-2"><i data-lucide="building-2" class="h-4 w-4 text-rose-600"></i><strong>${esc(c.name)}</strong><span class="text-xs text-slate-400">${c.departments.length} departments</span></div><div class="flex gap-3">${add('add_department','Add department',c.id)}${controls('college',c)}</div></div><div class="mt-3 space-y-2 border-l-2 border-rose-200 pl-4">${c.departments.map(d => `<div class="rounded-xl bg-white p-3"><div class="flex items-center justify-between gap-2"><strong class="text-slate-700">${esc(d.name)}</strong><div class="flex gap-3">${add('add_program','Add program',d.id)}${controls('department',d,c.id)}</div></div><div class="mt-2 space-y-2 pl-3">${d.programs.map(p => `<div class="rounded-lg border-l-2 border-slate-200 pl-3"><div class="flex items-center justify-between gap-2 text-sm"><strong>${esc(p.name)}</strong><div class="flex gap-3">${add('add_major','Add major',p.id)}${controls('program',p,d.id)}</div></div><div class="mt-2 space-y-1 pl-3">${p.majors.map(m => `<div class="flex items-center justify-between rounded bg-rose-50 px-2 py-1 text-xs"><span><i data-lucide="layers" class="mr-1 inline h-3 w-3 text-rose-600"></i>${esc(m.name)}</span><div class="flex gap-2">${add('add_course','Add course',p.id,m.id)}${controls('major',m,p.id)}</div></div>`).join('') || '<p class="text-xs italic text-slate-400">No majors yet</p>'}${p.courses.map(course => `<div class="flex items-center justify-between text-xs text-slate-500"><span><b class="mr-2 rounded bg-slate-100 px-1.5 py-0.5 text-rose-700">${esc(course.code)}</b>${esc(course.name)}</span>${controls('course',course,p.id)}</div>`).join('')}</div></div>`).join('')}</div></div>`).join('') || '<p class="text-sm italic text-slate-400">No departments yet</p>'}</div></article>`).join('') : '<div class="rounded-xl border border-dashed p-8 text-center text-sm text-slate-500">No colleges yet. Add the first college to build the hierarchy.</div>';
    tree.innerHTML = data.colleges.length
        ? data.colleges.map(renderCollege).join('')
        : '<div class="flex flex-col items-center justify-center gap-2 py-12 text-slate-500"><i data-lucide="building-2" class="h-10 w-10 text-slate-400"></i><p class="text-sm">No colleges yet. Click <strong>Add College</strong> to get started.</p></div>';
    lucide.createIcons();
    expandedColleges.forEach(collegeId => {
        const body = document.getElementById(`college-${collegeId}`);
        const toggle = tree.querySelector(`[data-college-toggle="${collegeId}"]`);
        body?.classList.remove('max-h-0', 'opacity-0');
        body?.classList.add('max-h-[2000px]', 'opacity-100');
        toggle?.querySelector('.college-chevron')?.classList.add('rotate-90');
    });
    setOrganizationStatus('Organization updated');
  }
    let organizationRecords = [];
    function renderRecords() {
            const search = document.getElementById('organizationSearch').value.trim().toLowerCase();
            const status = document.getElementById('organizationStatusFilter').value;
            const type = document.getElementById('organizationTypeFilter').value;
            const rows = organizationRecords.filter(record => {
                    return (!search || `${record.name} ${record.code} ${record.type}`.toLowerCase().includes(search)) &&
                            (status === 'all' || record.status === status) && (type === 'all' || record.entity === type);
            });
            document.getElementById('organizationRecordsBody').innerHTML = rows.length ? rows.map(record => `<tr class="hover:bg-slate-50"><td class="px-3 py-3 font-semibold text-slate-800">${esc(record.name)}</td><td class="px-3 py-3 text-slate-500">${esc(record.code || '-')}</td><td class="px-3 py-3 text-slate-500">${esc(record.type)}</td><td class="px-3 py-3"><span class="rounded-full px-2 py-1 text-xs font-semibold ${record.status === 'archived' ? 'bg-slate-200 text-slate-600' : 'bg-emerald-100 text-emerald-700'}">${esc(record.status)}</span></td><td class="px-3 py-3 text-right"><button type="button" data-record-edit="${record.entity}" data-id="${record.id}" class="mr-2 text-xs font-semibold text-rose-600 hover:underline">Edit</button><button type="button" data-record-archive="${record.entity}" data-id="${record.id}" class="mr-2 text-xs font-semibold text-slate-600 hover:underline">${record.status === 'archived' ? 'Restore' : 'Archive'}</button><button type="button" data-record-delete="${record.entity}" data-id="${record.id}" class="text-xs font-semibold text-red-600 hover:underline">Delete</button></td></tr>`).join('') : '<tr><td colspan="5" class="px-3 py-8 text-center text-sm italic text-slate-400">No organization records match your search.</td></tr>';
    }
    function loadRecords() {
            fetch('pages/organization.php?action=records', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(response => response.json()).then(result => { if (!result.success) throw new Error(result.message); organizationRecords = result.records || []; renderRecords(); }).catch(error => toast(`Records could not be loaded. ${error.message}`, true));
    }
    function load() {
        fetch('pages/organization.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.json())
            .then(result => {
                if (!result.success) throw new Error(result.message);
                render(result);
                loadRecords();
            })
            .catch(error => { setOrganizationStatus('Organization could not be loaded', true); toast(`Organization could not be loaded. ${error.message}`, true); });
    }
    document.getElementById('addCollegeBtn').onclick = openHierarchyForm;
    tree.onclick = event => {
        const toggle = event.target.closest('[data-college-toggle]');
        const addButton = event.target.closest('[data-add]');
        const edit = event.target.closest('[data-edit]');
        const del = event.target.closest('[data-delete]');

        if (toggle && !addButton && !edit && !del) {
            const body = document.getElementById(`college-${toggle.dataset.collegeToggle}`);
            const chevron = toggle.querySelector('.college-chevron');
            if (body) {
                const isOpening = body.classList.contains('max-h-0');
                body.classList.toggle('max-h-0', !isOpening);
                body.classList.toggle('max-h-[2000px]', isOpening);
                body.classList.toggle('opacity-0', !isOpening);
                body.classList.toggle('opacity-100', isOpening);
            }
            chevron?.classList.toggle('rotate-90');
        }
        if (addButton) { closeHierarchyForm(); openForm(addButton.dataset.add, `Add ${addButton.dataset.title.replace('Add ', '')}`, '', addButton.dataset.parent, {}, addButton.dataset.major); }
        if (edit) { closeHierarchyForm(); openForm(`edit_${edit.dataset.edit}`, `Edit ${edit.dataset.edit}`, edit.dataset.id, edit.dataset.parent, JSON.parse(edit.dataset.item)); }
        if (del) openDeleteDialog(del.dataset.delete, del.dataset.id, del.dataset.name);
    };
    document.getElementById('organizationSearch').oninput = renderRecords;
    document.getElementById('organizationStatusFilter').onchange = renderRecords;
    document.getElementById('organizationTypeFilter').onchange = renderRecords;
    document.getElementById('organizationRecordsBody').onclick = event => {
            const edit = event.target.closest('[data-record-edit]');
            const archive = event.target.closest('[data-record-archive]');
            const del = event.target.closest('[data-record-delete]');
            const record = organizationRecords.find(item => Number(item.id) === Number((edit || archive || del)?.dataset.id) && item.entity === (edit || archive || del)?.dataset[(edit ? 'recordEdit' : archive ? 'recordArchive' : 'recordDelete')]);
            if (edit && record) { closeHierarchyForm(); openForm(`edit_${record.entity}`, `Edit ${record.type}`, record.id, record.parentId, record); }
            if (archive && record) {
                const archiveAction = record.status === 'archived' ? 'restore' : 'archive';
                if (archiveAction === 'archive' && !window.confirm(`Archive "${record.name}"? It will remain available in archived records.`)) return;
                submit({ action: `archive_${archive.dataset.recordArchive}`, id: archive.dataset.id });
            }
            if (del) openDeleteDialog(del.dataset.recordDelete, del.dataset.id, record?.name);
    };
  const closeOrganizationModal = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
  document.getElementById('closeOrganizationModal').onclick = closeOrganizationModal;
  modal.addEventListener('click', event => { if (event.target === modal) closeOrganizationModal(); });
  document.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
          closeOrganizationModal();
          closeDeleteDialog();
      }
  });
  const setBusy = (button, busy, busyLabel = 'Working...') => {
      if (!button) return;
      if (busy) {
          button.dataset.originalLabel = button.textContent.trim();
          button.textContent = busyLabel;
          button.disabled = true;
          button.classList.add('cursor-wait', 'opacity-70');
      } else {
          button.textContent = button.dataset.originalLabel || button.textContent;
          button.disabled = false;
          button.classList.remove('cursor-wait', 'opacity-70');
      }
  };
  const submit = data => {
      const submitButton = form.querySelector('button[type="submit"]');
      setBusy(submitButton, true, 'Saving...');
      return fetch('pages/organization.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: data instanceof FormData ? new URLSearchParams(data) : new URLSearchParams(data)})
          .then(r => r.json())
          .then(result => { if (!result.success) throw new Error(result.message); closeOrganizationModal(); toast(result.message); load(); })
          .catch(e => toast(e.message, true))
          .finally(() => setBusy(submitButton, false));
  };
    form.onsubmit = e => {
        e.preventDefault();
        if (!form.reportValidity()) return;
        if (document.getElementById('organizationAction').value === 'create_hierarchy') {
            submit({ action: 'create_hierarchy', hierarchy: JSON.stringify(collectHierarchy()) });
            return;
        }
        submit(new FormData(form));
    };
    prospectusForm.onsubmit = event => {
        event.preventDefault();
        if (!prospectusForm.reportValidity()) return;
        const data = new FormData(prospectusForm);
        data.append('action', 'upload_prospectus');
        const uploadButton = prospectusForm.querySelector('button[type="submit"]');
        setBusy(uploadButton, true, 'Uploading...');

        fetch('pages/organization.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: data,
        })
            .then(response => response.json())
            .then(result => {
                if (!result.success) throw new Error(result.message);
                prospectusForm.reset();
                toast(result.message);
                load();
            })
                        .catch(error => toast(`Prospectus could not be uploaded. ${error.message}`, true))
                        .finally(() => setBusy(uploadButton, false));
    };
    retryOrganizationBtn.onclick = load;
  load();
})();
</script>
