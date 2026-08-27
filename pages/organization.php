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
        if ($action === 'list') {
            $colleges = $pdo->query('SELECT id, name FROM colleges ORDER BY name')->fetchAll();
            foreach ($colleges as &$college) {
                $stmt = $pdo->prepare('SELECT id, name FROM departments WHERE college_id = :id ORDER BY name');
                $stmt->execute([':id' => $college['id']]);
                $college['departments'] = $stmt->fetchAll();
                foreach ($college['departments'] as &$department) {
                    $stmt = $pdo->prepare('SELECT id, name FROM programs WHERE department_id = :id ORDER BY name');
                    $stmt->execute([':id' => $department['id']]);
                    $department['programs'] = $stmt->fetchAll();
                    foreach ($department['programs'] as &$program) {
                        $stmt = $pdo->prepare('SELECT id, name FROM majors WHERE program_id = :id ORDER BY name');
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
        $entity = preg_replace('/^(add|edit|delete)_/', '', $action);
        $operation = substr($action, 0, strpos($action, '_'));
        if ($entity === 'course') {
            $table = 'courses';
            $id = (int)($_POST['id'] ?? 0);
            if ($operation === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM courses WHERE id = :id');
                $stmt->execute([':id' => $id]);
            } else {
                $name = trim((string)($_POST['name'] ?? ''));
                $code = strtoupper(trim((string)($_POST['code'] ?? '')));
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
            if ($operation === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM {$config['table']} WHERE id = :id");
                $stmt->execute([':id' => $id]);
            } else {
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') organizationJson(['success' => false, 'message' => 'A name is required.'], 422);
                if ($operation === 'add') {
                    $sql = "INSERT INTO {$config['table']} (" . ($config['parent'] ? $config['parent'] . ', ' : '') . "name) VALUES (" . ($config['parent'] ? ':parent_id, ' : '') . ':name)';
                } else {
                    $sql = "UPDATE {$config['table']} SET name = :name WHERE id = :id";
                }
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':name', $name);
                if ($operation === 'add' && $config['parent']) $stmt->bindValue(':parent_id', (int)($_POST['parent_id'] ?? 0), PDO::PARAM_INT);
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
        <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-rose-600">Academic directory</p><h2 class="mt-1 text-3xl font-bold text-slate-900">Organization Management</h2><p class="mt-1 text-sm text-slate-500">Manage academic hierarchy: colleges, departments, programs, majors, and courses.</p></div>
        <div class="flex flex-wrap gap-3">
            <?php foreach (['colleges','departments','programs','majors','courses'] as $count): ?><div class="flex flex-col items-center border-b-2 border-rose-<?= $count === 'colleges' ? '600' : '300' ?> px-4 pb-2"><span class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"><?= ucfirst($count) ?></span><span data-count="<?= $count ?>" class="mt-1 text-2xl font-bold text-slate-900">0</span></div><?php endforeach; ?>
        </div>
  </div>
    <div class="grid grid-cols-12 gap-6">
        <div class="col-span-12 space-y-4 lg:col-span-8">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2"><i data-lucide="building-2" class="h-5 w-5 text-rose-600"></i><h3 class="text-xl font-bold text-slate-900">Institutional Structure</h3></div>
                    <div class="flex items-center gap-3"><span id="organizationStatus" class="text-xs text-slate-400">Loading...</span><button type="button" id="addCollegeBtn" class="inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700"><i data-lucide="plus" class="h-4 w-4"></i> Add College</button></div>
                </div>
                <div id="organizationTree" class="space-y-2"></div>
            </section>
        </div>
        <div class="col-span-12 lg:col-span-4">
            <section class="flex h-full min-w-0 flex-col rounded-xl border-t-4 border-rose-600 bg-white p-5 shadow-sm">
                <div class="mb-5 flex items-center gap-2"><i data-lucide="file-text" class="h-5 w-5 text-rose-600"></i><h3 class="font-bold text-slate-900">Course Prospectus</h3></div>
                <div id="prospectusList" class="max-h-[420px] flex-1 space-y-3 overflow-y-auto pr-1"></div>
                <form id="prospectusForm" class="mt-4 min-w-0 space-y-2">
                    <select name="program_id" id="prospectusProgram" required class="h-10 w-full min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm"><option value="">Select a program</option></select>
                    <input type="file" name="prospectus" accept="application/pdf,.pdf" required class="block h-auto w-full min-w-0 max-w-full rounded-xl border-2 border-dashed border-rose-300 bg-rose-50 p-3 text-xs text-slate-600">
                    <button class="w-full rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700">Upload PDF</button>
                </form>
            </section>
    </div>
</div>
<div id="organizationModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"><form id="organizationForm" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><h3 id="organizationModalTitle" class="text-xl font-bold">Add College</h3><button type="button" id="closeOrganizationModal" class="text-slate-400" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button></div><input type="hidden" id="organizationAction" name="action"><input type="hidden" id="organizationId" name="id"><input type="hidden" id="organizationParent" name="parent_id"><input type="hidden" id="organizationMajor" name="major_id"><div id="courseFields" class="mt-5 hidden grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium">Course code<input name="code" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><label class="text-sm font-medium">Year level<input name="year_level" type="number" min="1" max="8" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label></div><label class="mt-5 block text-sm font-medium">Name<input id="organizationName" name="name" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><button class="mt-5 w-full rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white">Save</button></form></div>
<div id="toastContainer" class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3"></div>
<script>
(() => {
    const tree = document.getElementById('organizationTree');
    const modal = document.getElementById('organizationModal');
    const form = document.getElementById('organizationForm');
    const prospectusList = document.getElementById('prospectusList');
    const prospectusForm = document.getElementById('prospectusForm');
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
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
  const openForm = (action, title, id = '', parent = '', item = {}, major = '') => { form.reset(); document.getElementById('organizationAction').value = action; document.getElementById('organizationId').value = id; document.getElementById('organizationParent').value = parent; document.getElementById('organizationMajor').value = major || item.major_id || ''; document.getElementById('organizationModalTitle').textContent = title; document.getElementById('courseFields').classList.toggle('hidden', !action.endsWith('course')); document.getElementById('organizationName').value = item.name || ''; if (item.code) form.code.value = item.code; if (item.year_level) form.year_level.value = item.year_level; modal.classList.remove('hidden'); modal.classList.add('flex'); document.getElementById('organizationName').focus(); };
  const controls = (type, item, parent) => `<span class="flex items-center gap-2"><button type="button" data-edit="${type}" data-id="${item.id}" data-parent="${parent}" data-item='${esc(JSON.stringify(item))}' class="text-xs font-semibold text-slate-500 hover:text-rose-600">Edit</button><button type="button" data-delete="${type}" data-id="${item.id}" class="text-xs font-semibold text-red-500 hover:text-red-700">Delete</button></span>`;
  const add = (action, title, parent, major = '') => `<button type="button" data-add="${action}" data-title="${title}" data-parent="${parent}" data-major="${major}" class="text-xs font-semibold text-rose-600">+ ${title}</button>`;
    let hierarchyBuilder;
    function createHierarchyBuilder() {
        if (hierarchyBuilder) return hierarchyBuilder;
        hierarchyBuilder = document.createElement('div');
        hierarchyBuilder.id = 'hierarchyBuilder';
        hierarchyBuilder.className = 'mt-5 hidden min-w-0 space-y-3';
        hierarchyBuilder.innerHTML = '<label class="block text-sm font-medium">College name<input data-field="college-name" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"></label><div data-departments class="space-y-3 border-l-2 border-rose-200 pl-3"></div><button type="button" data-builder-add="department" class="text-sm font-semibold text-rose-600">+ Add department</button>';
        form.querySelector('#organizationMajor').after(hierarchyBuilder);
        hierarchyBuilder.addEventListener('click', event => {
            const action = event.target.closest('[data-builder-add]')?.dataset.builderAdd;
            const remove = event.target.closest('[data-builder-remove]');
            if (remove) remove.closest('[data-builder-item]')?.remove();
            if (action === 'department') addDepartment();
            if (action === 'program') addProgram(event.target.closest('[data-builder-item]'));
            if (action === 'major') addMajor(event.target.closest('[data-builder-item]'));
            if (action === 'course') addCourse(event.target.closest('[data-builder-item]'));
        });
        return hierarchyBuilder;
    }
    function addDepartment() {
        const department = document.createElement('div');
        department.dataset.builderItem = 'department';
        department.className = 'rounded-lg border border-slate-200 bg-slate-50 p-3';
        department.innerHTML = '<p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Department under College</p><div class="flex gap-2"><input data-field="name" placeholder="Department name" required class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="px-2 text-sm text-red-500">Remove</button></div><div data-programs class="mt-3 space-y-3 border-l-2 border-slate-200 pl-3"></div><button type="button" data-builder-add="program" class="mt-3 text-xs font-semibold text-rose-600">+ Add program</button>';
        hierarchyBuilder.querySelector('[data-departments]').append(department);
    }
    function addProgram(department) {
        if (!department) return;
        const program = document.createElement('div');
        program.dataset.builderItem = 'program';
        program.className = 'rounded-lg border-l-2 border-rose-200 bg-white p-3';
        program.innerHTML = '<p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Program under Department</p><div class="flex gap-2"><input data-field="name" placeholder="Program name" required class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="px-2 text-sm text-red-500">Remove</button></div><div data-majors class="mt-3 space-y-2 border-l-2 border-rose-200 pl-3"></div><button type="button" data-builder-add="major" class="mt-2 text-xs font-semibold text-rose-600">+ Add major</button><div data-courses class="mt-3 space-y-2"></div><button type="button" data-builder-add="course" class="mt-2 text-xs font-semibold text-rose-600">+ Add course to program</button>';
        department.querySelector('[data-programs]').append(program);
    }
    function addMajor(program) {
        if (!program) return;
        const major = document.createElement('div');
        major.dataset.builderItem = 'major';
        major.className = 'min-w-0 rounded-lg border-l-2 border-rose-200 p-2';
        major.innerHTML = '<p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Major under Program</p><div class="flex gap-2"><input data-field="name" placeholder="Major name" required class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="px-2 text-sm text-red-500">Remove</button></div><div data-courses class="mt-2 space-y-2 border-l-2 border-rose-200 pl-3"></div><button type="button" data-builder-add="course" class="mt-2 text-xs font-semibold text-rose-600">+ Add course to major</button>';
        program.querySelector('[data-majors]').append(major);
    }
    function addCourse(program) {
        if (!program) return;
        const course = document.createElement('div');
        course.dataset.builderItem = 'course';
        course.className = 'grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-[minmax(0,auto)_minmax(0,1fr)_minmax(4rem,auto)_auto]';
        const owner = program.dataset.builderItem === 'major' ? 'Major' : 'Program';
        course.innerHTML = `<p class="col-span-full text-[10px] font-semibold uppercase tracking-wide text-slate-500">Course under ${owner}</p><input data-field="code" placeholder="Code" required class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><input data-field="name" placeholder="Course name" required class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><input data-field="year_level" type="number" min="1" max="8" placeholder="Year" class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><button type="button" data-builder-remove class="px-2 text-sm text-red-500">Remove</button>`;
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
        builder.classList.remove('hidden');
        builder.querySelector('[data-departments]').innerHTML = '';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        builder.querySelector('[data-field="college-name"]').focus();
    }
    function closeHierarchyForm() {
        if (!hierarchyBuilder) return;
        hierarchyBuilder.classList.add('hidden');
        document.getElementById('organizationName').required = true;
        document.getElementById('organizationName').closest('label').classList.remove('hidden');
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
    const renderCollege = college => {
        const departments = college.departments.map(department => {
            const programs = department.programs.map(program => {
                const majors = program.majors.map(major => {
                    const majorCourses = program.courses.filter(course => Number(course.major_id) === Number(major.id));
                    return `
                    <div class="mt-2">
                        <div class="group flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <i data-lucide="tag" class="h-3.5 w-3.5 shrink-0 text-rose-600"></i>
                            ${esc(major.name)}
                            <span class="ml-auto flex items-center gap-1 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100">${controls('major', major, program.id)}${add('add_course', 'Add course', program.id, major.id)}</span>
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
                    <div class="mt-2 border-l-2 border-rose-200 pl-5">
                        <div class="group flex items-center gap-2">
                            <i data-lucide="book" class="h-3.5 w-3.5 shrink-0 text-rose-600"></i>
                            <span class="text-sm font-semibold text-slate-700">${esc(program.name)}</span>
                            <span class="ml-auto flex items-center gap-1 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100">${controls('program', program, department.id)}${add('add_major', 'Add major', program.id)}${add('add_course', 'Add course', program.id)}</span>
                        </div>
                        <div class="mt-1 space-y-1 pl-4">${majors || '<p class="text-xs italic text-slate-400">No majors yet</p>'}${courses}</div>
                    </div>`;
            }).join('');

            return `
                <div class="mt-2 border-l-2 border-slate-300 pl-4">
                    <div class="group flex items-center gap-2">
                        <i data-lucide="layers" class="h-3.5 w-3.5 shrink-0 text-slate-500"></i>
                        <span class="text-sm font-semibold text-slate-700">${esc(department.name)}</span>
                        <span class="ml-auto flex items-center gap-1 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100">${controls('department', department, college.id)}${add('add_program', 'Add program', department.id)}</span>
                    </div>
                    ${programs || '<p class="mt-1 pl-4 text-xs italic text-slate-400">No programs yet</p>'}
                </div>`;
        }).join('');

        return `
            <article class="college-row group overflow-hidden rounded-xl border border-transparent bg-slate-50 transition-all hover:border-rose-200">
                <div class="flex cursor-pointer items-center gap-3 p-4" data-college-toggle="${college.id}">
                    <i data-lucide="chevron-right" class="college-chevron h-5 w-5 shrink-0 text-rose-600 transition-transform"></i>
                    <i data-lucide="building-2" class="h-5 w-5 shrink-0 text-rose-600"></i>
                    <div class="min-w-0 flex-1"><h4 class="truncate text-sm font-bold text-slate-800">${esc(college.name)}</h4><p class="text-[10px] uppercase tracking-wide text-slate-500">${departments.length} Department${departments.length === 1 ? '' : 's'}</p></div>
                    <div class="flex items-center gap-1 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100">${controls('college', college)}${add('add_department', 'Add department', college.id)}</div>
                </div>
                <div id="college-${college.id}" class="college-body hidden px-4"><div class="space-y-1 border-t border-slate-200 pb-4 pl-2 pt-2">${departments || '<p class="text-xs italic text-slate-400">No departments yet</p>'}</div></div>
            </article>`;
    };
  function render(data) {
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
    lucide.createIcons(); document.getElementById('organizationStatus').textContent = 'Updated just now';
  }
    function load() {
        fetch('pages/organization.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.json())
            .then(result => {
                if (!result.success) throw new Error(result.message);
                render(result);
            })
            .catch(error => toast(error.message, true));
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
            body?.classList.toggle('hidden');
            chevron?.classList.toggle('rotate-90');
        }
        if (addButton) { closeHierarchyForm(); openForm(addButton.dataset.add, `Add ${addButton.dataset.title.replace('Add ', '')}`, '', addButton.dataset.parent, {}, addButton.dataset.major); }
        if (edit) { closeHierarchyForm(); openForm(`edit_${edit.dataset.edit}`, `Edit ${edit.dataset.edit}`, edit.dataset.id, edit.dataset.parent, JSON.parse(edit.dataset.item)); }
        if (del && confirm(`Delete this ${del.dataset.delete}? This may also delete its children.`)) submit({ action: `delete_${del.dataset.delete}`, id: del.dataset.id });
    };
  document.getElementById('closeOrganizationModal').onclick = () => modal.classList.add('hidden');
  const submit = data => fetch('pages/organization.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: data instanceof FormData ? new URLSearchParams(data) : new URLSearchParams(data)}).then(r => r.json()).then(result => { if (!result.success) throw new Error(result.message); modal.classList.add('hidden'); toast(result.message); load(); }).catch(e => toast(e.message, true));
    form.onsubmit = e => {
        e.preventDefault();
        if (document.getElementById('organizationAction').value === 'create_hierarchy') {
            submit({ action: 'create_hierarchy', hierarchy: JSON.stringify(collectHierarchy()) });
            return;
        }
        submit(new FormData(form));
    };
    prospectusForm.onsubmit = event => {
        event.preventDefault();
        const data = new FormData(prospectusForm);
        data.append('action', 'upload_prospectus');

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
            .catch(error => toast(error.message, true));
    };
  load();
})();
</script>
