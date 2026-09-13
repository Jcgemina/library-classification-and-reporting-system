<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=course');
    exit;
}
requireLogin();

function courseJson(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (!$pdo) courseJson(['success' => false, 'message' => 'Database unavailable.'], 503);

try {
    // Keep existing installations compatible with the course management fields.
    foreach ([
        "ALTER TABLE courses MODIFY COLUMN program_id INT DEFAULT NULL",
        "ALTER TABLE courses ADD COLUMN units TINYINT UNSIGNED DEFAULT NULL AFTER description",
        "ALTER TABLE courses ADD COLUMN type VARCHAR(30) NOT NULL DEFAULT 'Major' AFTER units",
        "ALTER TABLE courses ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER type",
    ] as $upgrade) {
        try { $pdo->exec($upgrade); } catch (PDOException $ignored) { }
    }
} catch (Throwable $exception) {
    courseJson(['success' => false, 'message' => 'Course storage is unavailable.'], 503);
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action !== null) {
    $isAdmin = strtolower((string)($_SESSION['role'] ?? '')) === 'admin';
    if ($action !== 'list' && !$isAdmin) courseJson(['success' => false, 'message' => 'Admin access required.'], 403);

    try {
        if ($action === 'list') {
            $search = trim((string)($_GET['search'] ?? ''));
            $status = (string)($_GET['status'] ?? 'all');
            $collegeId = (int)($_GET['college_id'] ?? 0);
            $programId = (int)($_GET['program_id'] ?? 0);
            $where = [];
            $params = [];
            if ($search !== '') {
                $where[] = '(c.code LIKE :search_code OR c.name LIKE :search_name OR c.description LIKE :search_description OR p.name LIKE :search_program OR m.name LIKE :search_major)';
                $params[':search_code'] = "%{$search}%";
                $params[':search_name'] = "%{$search}%";
                $params[':search_description'] = "%{$search}%";
                $params[':search_program'] = "%{$search}%";
                $params[':search_major'] = "%{$search}%";
            }
            if (in_array($status, ['active', 'inactive'], true)) { $where[] = 'c.status = :status'; $params[':status'] = $status; }
            if ($collegeId > 0) { $where[] = 'p.college_id = :college_id'; $params[':college_id'] = $collegeId; }
            if ($programId > 0) { $where[] = 'c.program_id = :program_id'; $params[':program_id'] = $programId; }
            $sql = 'SELECT c.id, c.code, c.name, c.description, c.units, c.type, c.status, c.year_level, c.program_id, c.major_id, p.name AS program_name, col.id AS college_id, col.name AS college_name, m.name AS major_name FROM courses c LEFT JOIN programs p ON p.id = c.program_id LEFT JOIN colleges col ON col.id = p.college_id LEFT JOIN majors m ON m.id = c.major_id';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY c.code, c.name';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $courses = array_map(static function (array $course): array {
                return [
                    'id' => (int)$course['id'], 'code' => $course['code'], 'name' => $course['name'],
                    'description' => $course['description'] ?? '', 'units' => $course['units'] !== null ? (int)$course['units'] : null,
                    'type' => $course['type'], 'status' => $course['status'], 'yearLevel' => $course['year_level'] !== null ? (int)$course['year_level'] : null,
                    'programId' => $course['program_id'] !== null ? (int)$course['program_id'] : null, 'majorId' => $course['major_id'] !== null ? (int)$course['major_id'] : null,
                    'collegeId' => $course['college_id'] !== null ? (int)$course['college_id'] : null, 'collegeName' => $course['college_name'] ?? '',
                    'programName' => $course['program_name'] ?? '', 'majorName' => $course['major_name'] ?? '',
                ];
            }, $stmt->fetchAll());
            $counts = ['total' => (int)$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(), 'active' => (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'active'")->fetchColumn(), 'inactive' => (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'inactive'")->fetchColumn()];
            courseJson(['success' => true, 'courses' => $courses, 'counts' => $counts]);
        }

        if ($action === 'options') {
            $colleges = $pdo->query('SELECT id, name, status FROM colleges ORDER BY name')->fetchAll();
            $programs = $pdo->query('SELECT id, college_id, name, status FROM programs ORDER BY name')->fetchAll();
            $majors = $pdo->query('SELECT id, program_id, name, status FROM majors ORDER BY name')->fetchAll();
            courseJson(['success' => true, 'colleges' => $colleges, 'programs' => $programs, 'majors' => $majors]);
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'delete') {
            $passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $passwordStmt->execute([':id' => (int)$_SESSION['user_id']]);
            if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$passwordStmt->fetchColumn())) courseJson(['success' => false, 'message' => 'The current password is incorrect. Nothing was deleted.'], 403);
            $stmt = $pdo->prepare('DELETE FROM courses WHERE id = :id');
            $stmt->execute([':id' => $id]);
            courseJson(['success' => true, 'message' => 'Course deleted successfully.']);
        }

        if ($action === 'unlink') {
            $stmt = $pdo->prepare('UPDATE courses SET program_id = NULL, major_id = NULL WHERE id = :id');
            $stmt->execute([':id' => $id]);
            courseJson(['success' => true, 'message' => 'Course organization link removed.']);
        }

        if ($action === 'toggle_status') {
            $stmt = $pdo->prepare("UPDATE courses SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :id");
            $stmt->execute([':id' => $id]);
            courseJson(['success' => true, 'message' => 'Course status updated.']);
        }

        if ($action === 'save') {
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $programId = (int)($_POST['program_id'] ?? 0) ?: null;
            $majorId = (int)($_POST['major_id'] ?? 0) ?: null;
            $units = ($_POST['units'] ?? '') !== '' ? max(0, min(255, (int)$_POST['units'])) : null;
            $yearLevel = ($_POST['year_level'] ?? '') !== '' ? max(1, min(8, (int)$_POST['year_level'])) : null;
            $type = trim((string)($_POST['type'] ?? 'Major'));
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $description = trim((string)($_POST['description'] ?? ''));
            if ($code === '' || $name === '') courseJson(['success' => false, 'message' => 'Course code and name are required.'], 422);
            if ($majorId !== null && $programId === null) courseJson(['success' => false, 'message' => 'A major requires a program assignment.'], 422);
            if ($programId !== null) {
                $programCheck = $pdo->prepare('SELECT id FROM programs WHERE id = :id LIMIT 1'); $programCheck->execute([':id' => $programId]);
                if (!$programCheck->fetchColumn()) courseJson(['success' => false, 'message' => 'The selected program does not exist.'], 422);
            }
            if ($majorId !== null) {
                $majorCheck = $pdo->prepare('SELECT id FROM majors WHERE id = :id AND program_id = :program_id LIMIT 1'); $majorCheck->execute([':id' => $majorId, ':program_id' => $programId]);
                if (!$majorCheck->fetchColumn()) courseJson(['success' => false, 'message' => 'The selected major does not belong to that program.'], 422);
            }
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE courses SET program_id = :program_id, major_id = :major_id, code = :code, name = :name, description = :description, units = :units, type = :type, status = :status, year_level = :year_level WHERE id = :id');
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            } else {
                $stmt = $pdo->prepare('INSERT INTO courses (program_id, major_id, code, name, description, units, type, status, year_level) VALUES (:program_id, :major_id, :code, :name, :description, :units, :type, :status, :year_level)');
            }
            $stmt->bindValue(':program_id', $programId, $programId === null ? PDO::PARAM_NULL : PDO::PARAM_INT); $stmt->bindValue(':major_id', $majorId, $majorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT); $stmt->bindValue(':code', $code); $stmt->bindValue(':name', $name); $stmt->bindValue(':description', $description !== '' ? $description : null, $description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL); $stmt->bindValue(':units', $units, $units === null ? PDO::PARAM_NULL : PDO::PARAM_INT); $stmt->bindValue(':type', $type !== '' ? $type : 'Major'); $stmt->bindValue(':status', $status); $stmt->bindValue(':year_level', $yearLevel, $yearLevel === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();
            courseJson(['success' => true, 'message' => $id > 0 ? 'Course updated successfully.' : 'Course added successfully.']);
        }
        courseJson(['success' => false, 'message' => 'Unknown course action.'], 400);
    } catch (PDOException $exception) {
        courseJson(['success' => false, 'message' => (($exception->errorInfo[1] ?? 0) === 1062) ? 'That course code is already assigned to this program.' : 'Unable to update the course.'], 409);
    }
}
?>
<div class="space-y-6">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-rose-600">Academic catalog</p><h2 class="mt-1 text-3xl font-bold text-slate-900">Course Management</h2><p class="mt-1 text-sm text-slate-500">Maintain course records and their links to colleges, programs, and majors.</p></div>
    <?php if (strtolower((string)($_SESSION['role'] ?? '')) === 'admin'): ?><button type="button" id="addCourseBtn" class="inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700"><i data-lucide="plus" class="h-4 w-4"></i> Add Course</button><?php endif; ?>
  </div>
  <div class="grid grid-cols-1 gap-3 sm:grid-cols-3"><div class="rounded-xl border border-slate-200 bg-white p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total courses</p><p id="totalCount" class="mt-1 text-2xl font-bold text-slate-900">0</p></div><div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Active</p><p id="activeCount" class="mt-1 text-2xl font-bold text-emerald-900">0</p></div><div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Inactive</p><p id="inactiveCount" class="mt-1 text-2xl font-bold text-slate-700">0</p></div></div>
  <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between"><div class="flex flex-1 flex-col gap-2 sm:flex-row"><input id="courseSearch" type="search" placeholder="Search code, name, or description" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"><select id="courseCollege" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="">All colleges</option></select><select id="courseProgram" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="">All programs</option></select><select id="courseStatus" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="all">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div><span id="courseStatusMessage" class="text-xs text-slate-500" aria-live="polite">Loading courses...</span></div>
    <div class="overflow-x-auto"><table class="w-full min-w-[1050px] text-left text-sm"><thead class="border-y border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-3">Course</th><th class="px-3 py-3">Details</th><th class="px-3 py-3">Organization link</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Actions</th></tr></thead><tbody id="courseBody" class="divide-y divide-slate-100"></tbody></table></div>
  </section>
</div>
<div id="courseModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"><form id="courseForm" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><div><p class="text-xs font-bold uppercase tracking-wide text-rose-600">Course record</p><h3 id="courseModalTitle" class="text-xl font-bold text-slate-900">Add Course</h3></div><button type="button" id="closeCourseModal" class="text-slate-400" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button></div><input type="hidden" name="id" id="courseId"><div class="mt-5 grid gap-4 sm:grid-cols-2"><label class="text-sm font-semibold text-slate-700">Course code<input name="code" id="courseCode" maxlength="30" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm font-semibold text-slate-700">Course name<input name="name" id="courseName" maxlength="180" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm font-semibold text-slate-700">Units<input name="units" id="courseUnits" type="number" min="0" max="255" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm font-semibold text-slate-700">Type<select name="type" id="courseType" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option>Major</option><option>Minor</option><option>Elective</option><option>General Education</option></select></label><label class="text-sm font-semibold text-slate-700">College<select id="formCollege" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="">No college link</option></select></label><label class="text-sm font-semibold text-slate-700">Program<select name="program_id" id="formProgram" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="">No program link</option></select></label><label class="text-sm font-semibold text-slate-700">Major<select name="major_id" id="formMajor" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="">No major link</option></select></label><label class="text-sm font-semibold text-slate-700">Year level<input name="year_level" id="courseYear" type="number" min="1" max="8" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm font-semibold text-slate-700">Status<select name="status" id="formStatus" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="active">Active</option><option value="inactive">Inactive</option></select></label></div><label class="mt-4 block text-sm font-semibold text-slate-700">Description<textarea name="description" id="courseDescription" maxlength="500" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></textarea></label><div class="mt-6 flex justify-end gap-3"><button type="button" id="cancelCourseModal" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button><button type="submit" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700">Save course</button></div></form></div>
<div id="courseToast" class="fixed bottom-5 right-5 z-[60] hidden rounded-lg bg-slate-900 px-4 py-3 text-sm font-semibold text-white shadow-xl" role="status"></div>
<script>
(() => {
  const admin = <?php echo json_encode(strtolower((string)($_SESSION['role'] ?? '')) === 'admin'); ?>;
  const state = { courses: [], colleges: [], programs: [], majors: [] };
  const $ = (id) => document.getElementById(id);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const toast = (message, error = false) => { const node = $('courseToast'); node.textContent = message; node.className = `fixed bottom-5 right-5 z-[60] rounded-lg px-4 py-3 text-sm font-semibold text-white shadow-xl ${error ? 'bg-red-600' : 'bg-slate-900'}`; setTimeout(() => node.classList.add('hidden'), 3200); };
  const request = async (url, options = {}) => { const response = await fetch(`pages/course.php${url}`, { headers: {'X-Requested-With':'XMLHttpRequest', ...(options.body instanceof FormData ? {} : {'Content-Type':'application/x-www-form-urlencoded'})}, ...options }); const data = await response.json(); if (!response.ok || !data.success) throw new Error(data.message || 'Request failed.'); return data; };
  const params = () => { const query = new URLSearchParams({action:'list', search:$('courseSearch').value, status:$('courseStatus').value, college_id:$('courseCollege').value, program_id:$('courseProgram').value}); return `?${query}`; };
  function render() { $('courseBody').innerHTML = state.courses.length ? state.courses.map((course) => `<tr class="align-top hover:bg-slate-50"><td class="px-3 py-4"><div class="font-bold text-slate-900">${esc(course.code)}</div><div class="mt-1 text-slate-600">${esc(course.name)}</div></td><td class="px-3 py-4 text-slate-600"><span>${course.units !== null ? `${course.units} unit${course.units === 1 ? '' : 's'}` : 'Units not set'}</span><span class="mx-1 text-slate-300">|</span><span>${esc(course.type)}</span>${course.yearLevel ? `<div class="mt-1 text-xs text-slate-500">Year ${course.yearLevel}</div>` : ''}</td><td class="px-3 py-4">${course.programName ? `<div class="font-medium text-slate-800">${esc(course.programName)}</div><div class="text-xs text-slate-500">${esc(course.collegeName)}${course.majorName ? ` / ${esc(course.majorName)}` : ''}</div>` : '<span class="text-xs italic text-slate-400">Unassigned</span>'}</td><td class="px-3 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold ${course.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}">${esc(course.status)}</span></td><td class="px-3 py-4 text-right"><div class="flex flex-wrap justify-end gap-2">${admin ? `<button type="button" data-edit="${course.id}" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:border-rose-400 hover:text-rose-700">Edit</button><button type="button" data-toggle="${course.id}" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700">${course.status === 'active' ? 'Deactivate' : 'Activate'}</button><button type="button" data-unlink="${course.id}" class="rounded-md border border-amber-300 px-2.5 py-1.5 text-xs font-semibold text-amber-700">Unlink</button><button type="button" data-delete="${course.id}" class="rounded-md border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-600">Delete</button>` : '<span class="text-xs text-slate-400">View only</span>'}</div></td></tr>`).join('') : '<tr><td colspan="5" class="px-3 py-12 text-center text-sm text-slate-500">No courses match the selected filters.</td></tr>'; }
  async function loadCourses() { $('courseStatusMessage').textContent = 'Loading courses...'; try { const data = await request(params()); state.courses = data.courses; $('totalCount').textContent = data.counts.total; $('activeCount').textContent = data.counts.active; $('inactiveCount').textContent = data.counts.inactive; render(); $('courseStatusMessage').textContent = `${state.courses.length} shown`; } catch (error) { $('courseStatusMessage').textContent = error.message; toast(error.message, true); } }
  function fillSelect(select, items, emptyLabel, selected = '') { select.innerHTML = `<option value="">${emptyLabel}</option>` + items.map((item) => `<option value="${item.id}" ${String(item.id) === String(selected) ? 'selected' : ''}>${esc(item.name)}</option>`).join(''); }
  function fillFormOptions(course = {}) { fillSelect($('formCollege'), state.colleges.filter((item) => item.status === 'active'), 'No college link', course.collegeId || ''); fillSelect($('formProgram'), state.programs.filter((item) => item.status === 'active' && (!course.collegeId || String(item.college_id) === String(course.collegeId))), 'No program link', course.programId || ''); fillSelect($('formMajor'), state.majors.filter((item) => item.status === 'active' && (!course.programId || String(item.program_id) === String(course.programId))), 'No major link', course.majorId || ''); }
  function openModal(course = null) { $('courseModalTitle').textContent = course ? 'Edit Course' : 'Add Course'; $('courseId').value = course?.id || ''; $('courseCode').value = course?.code || ''; $('courseName').value = course?.name || ''; $('courseUnits').value = course?.units ?? ''; $('courseType').value = course?.type || 'Major'; $('courseYear').value = course?.yearLevel ?? ''; $('courseDescription').value = course?.description || ''; $('formStatus').value = course?.status || 'active'; fillFormOptions(course || {}); $('formCollege').value = course?.collegeId || ''; $('courseModal').classList.remove('hidden'); $('courseModal').classList.add('flex'); }
  function closeModal() { $('courseModal').classList.add('hidden'); $('courseModal').classList.remove('flex'); }
  async function loadOptions() { const data = await request('?action=options'); state.colleges = data.colleges; state.programs = data.programs; state.majors = data.majors; fillSelect($('courseCollege'), state.colleges, 'All colleges'); fillSelect($('courseProgram'), state.programs, 'All programs'); }
  $('courseSearch').addEventListener('input', loadCourses); ['courseCollege','courseProgram','courseStatus'].forEach((id) => $(id).addEventListener('change', loadCourses)); $('addCourseBtn')?.addEventListener('click', () => openModal()); $('closeCourseModal').addEventListener('click', closeModal); $('cancelCourseModal').addEventListener('click', closeModal); $('formCollege').addEventListener('change', () => { fillSelect($('formProgram'), state.programs.filter((item) => !$('formCollege').value || String(item.college_id) === $('formCollege').value), 'No program link'); fillSelect($('formMajor'), [], 'No major link'); }); $('formProgram').addEventListener('change', () => fillSelect($('formMajor'), state.majors.filter((item) => String(item.program_id) === $('formProgram').value), 'No major link'));
  $('courseForm').addEventListener('submit', async (event) => { event.preventDefault(); const form = new FormData(event.target); form.append('action', 'save'); try { const data = await request('', {method:'POST', body:form}); closeModal(); toast(data.message); await loadCourses(); } catch (error) { toast(error.message, true); } });
  $('courseBody').addEventListener('click', async (event) => { const button = event.target.closest('button'); if (!button) return; const id = Number(button.dataset.edit || button.dataset.toggle || button.dataset.unlink || button.dataset.delete); const course = state.courses.find((item) => item.id === id); try { if (button.dataset.edit) openModal(course); if (button.dataset.toggle) { const data = await request('', {method:'POST', body:new URLSearchParams({action:'toggle_status', id})}); toast(data.message); await loadCourses(); } if (button.dataset.unlink && confirm('Remove this course from its program and major? The course record will remain.')) { const data = await request('', {method:'POST', body:new URLSearchParams({action:'unlink', id})}); toast(data.message); await loadCourses(); } if (button.dataset.delete) { const password = prompt('Enter your current password to permanently delete this course:'); if (password === null) return; const data = await request('', {method:'POST', body:new URLSearchParams({action:'delete', id, current_password:password})}); toast(data.message); await loadCourses(); } } catch (error) { toast(error.message, true); } });
  loadOptions().then(loadCourses).catch((error) => toast(error.message, true));
})();
</script>
