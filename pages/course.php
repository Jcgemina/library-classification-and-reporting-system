<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=course');
    exit;
}

requireLogin();

function courseJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (!$pdo) {
    courseJson(['success' => false, 'message' => 'Database unavailable.'], 503);
}

try {
    foreach ([
        "ALTER TABLE courses MODIFY COLUMN program_id INT DEFAULT NULL",
        "ALTER TABLE courses ADD COLUMN type VARCHAR(30) NOT NULL DEFAULT 'Major' AFTER description",
        "ALTER TABLE courses ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER type",
    ] as $upgrade) {
        try {
            $pdo->exec($upgrade);
        } catch (PDOException $ignored) {
        }
    }
} catch (Throwable $exception) {
    courseJson(['success' => false, 'message' => 'Course storage is unavailable.'], 503);
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action !== null) {
    $isAdmin = strtolower((string) ($_SESSION['role'] ?? '')) === 'admin';
    if ($action !== 'list' && !$isAdmin) {
        courseJson(['success' => false, 'message' => 'Admin access required.'], 403);
    }

    try {
        switch ($action) {
            case 'list':
                $search = trim((string) ($_GET['search'] ?? ''));
                $status = (string) ($_GET['status'] ?? 'all');
                $collegeId = (int) ($_GET['college_id'] ?? 0);
                $programId = (int) ($_GET['program_id'] ?? 0);
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

                if (in_array($status, ['active', 'inactive'], true)) {
                    $where[] = 'c.status = :status';
                    $params[':status'] = $status;
                }

                if ($collegeId > 0) {
                    $where[] = 'p.college_id = :college_id';
                    $params[':college_id'] = $collegeId;
                }

                if ($programId > 0) {
                    $where[] = 'c.program_id = :program_id';
                    $params[':program_id'] = $programId;
                }

                $sql = 'SELECT c.id, c.code, c.name, c.description, c.type, c.status, c.year_level, c.program_id, c.major_id, p.name AS program_name, col.id AS college_id, col.name AS college_name, m.name AS major_name FROM courses c LEFT JOIN programs p ON p.id = c.program_id LEFT JOIN colleges col ON col.id = p.college_id LEFT JOIN majors m ON m.id = c.major_id';
                if ($where) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }
                $sql .= ' ORDER BY c.code, c.name';

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $courses = array_map(static function (array $course): array {
                    return [
                        'id' => (int) $course['id'],
                        'code' => $course['code'],
                        'name' => $course['name'],
                        'description' => $course['description'] ?? '',
                        'type' => $course['type'],
                        'status' => $course['status'],
                        'yearLevel' => $course['year_level'] !== null ? (int) $course['year_level'] : null,
                        'programId' => $course['program_id'] !== null ? (int) $course['program_id'] : null,
                        'majorId' => $course['major_id'] !== null ? (int) $course['major_id'] : null,
                        'collegeId' => $course['college_id'] !== null ? (int) $course['college_id'] : null,
                        'collegeName' => $course['college_name'] ?? '',
                        'programName' => $course['program_name'] ?? '',
                        'majorName' => $course['major_name'] ?? '',
                    ];
                }, $stmt->fetchAll());

                $counts = [
                    'total' => (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
                    'active' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'active'")->fetchColumn(),
                    'inactive' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'inactive'")->fetchColumn(),
                ];

                courseJson(['success' => true, 'courses' => $courses, 'counts' => $counts]);

            case 'options':
                $colleges = $pdo->query('SELECT id, name, status FROM colleges ORDER BY name')->fetchAll();
                $programs = $pdo->query('SELECT id, college_id, name, status FROM programs ORDER BY name')->fetchAll();
                $majors = $pdo->query('SELECT id, program_id, name, status FROM majors ORDER BY name')->fetchAll();

                courseJson(['success' => true, 'colleges' => $colleges, 'programs' => $programs, 'majors' => $majors]);

            case 'delete':
                $id = (int) ($_POST['id'] ?? 0);
                $passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
                $passwordStmt->execute([':id' => (int) $_SESSION['user_id']]);

                if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $passwordStmt->fetchColumn())) {
                    courseJson(['success' => false, 'message' => 'The current password is incorrect. Nothing was deleted.'], 403);
                }

                $stmt = $pdo->prepare('DELETE FROM courses WHERE id = :id');
                $stmt->execute([':id' => $id]);

                courseJson(['success' => true, 'message' => 'Course deleted successfully.']);

            case 'unlink':
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('UPDATE courses SET program_id = NULL, major_id = NULL WHERE id = :id');
                $stmt->execute([':id' => $id]);

                courseJson(['success' => true, 'message' => 'Course academic link removed.']);

            case 'toggle_status':
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("UPDATE courses SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :id");
                $stmt->execute([':id' => $id]);

                courseJson(['success' => true, 'message' => 'Course status updated.']);

            case 'save':
                $id = (int) ($_POST['id'] ?? 0);
                $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
                $name = trim((string) ($_POST['name'] ?? ''));
                $programId = (int) ($_POST['program_id'] ?? 0) ?: null;
                $majorId = (int) ($_POST['major_id'] ?? 0) ?: null;
                $yearLevel = ($_POST['year_level'] ?? '') !== '' ? max(1, min(8, (int) $_POST['year_level'])) : null;
                $type = trim((string) ($_POST['type'] ?? 'Major'));
                $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
                $description = trim((string) ($_POST['description'] ?? ''));

                if ($code === '' || $name === '') {
                    courseJson(['success' => false, 'message' => 'Course code and name are required.'], 422);
                }

                if ($majorId !== null && $programId === null) {
                    courseJson(['success' => false, 'message' => 'A major requires a program assignment.'], 422);
                }

                if ($programId !== null) {
                    $programCheck = $pdo->prepare('SELECT id FROM programs WHERE id = :id LIMIT 1');
                    $programCheck->execute([':id' => $programId]);

                    if (!$programCheck->fetchColumn()) {
                        courseJson(['success' => false, 'message' => 'The selected program does not exist.'], 422);
                    }
                }

                if ($majorId !== null) {
                    $majorCheck = $pdo->prepare('SELECT id FROM majors WHERE id = :id AND program_id = :program_id LIMIT 1');
                    $majorCheck->execute([':id' => $majorId, ':program_id' => $programId]);

                    if (!$majorCheck->fetchColumn()) {
                        courseJson(['success' => false, 'message' => 'The selected major does not belong to that program.'], 422);
                    }
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE courses SET program_id = :program_id, major_id = :major_id, code = :code, name = :name, description = :description, type = :type, status = :status, year_level = :year_level WHERE id = :id');
                    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO courses (program_id, major_id, code, name, description, type, status, year_level) VALUES (:program_id, :major_id, :code, :name, :description, :type, :status, :year_level)');
                }

                $stmt->bindValue(':program_id', $programId, $programId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':major_id', $majorId, $majorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':code', $code);
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':description', $description !== '' ? $description : null, $description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':type', $type !== '' ? $type : 'Major');
                $stmt->bindValue(':status', $status);
                $stmt->bindValue(':year_level', $yearLevel, $yearLevel === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->execute();

                courseJson(['success' => true, 'message' => $id > 0 ? 'Course updated successfully.' : 'Course added successfully.']);

            default:
                courseJson(['success' => false, 'message' => 'Unknown course action.'], 400);
        }
    } catch (PDOException $exception) {
        courseJson([
            'success' => false,
            'message' => (($exception->errorInfo[1] ?? 0) === 1062)
                ? 'That course code is already assigned to this program.'
                : 'Unable to update the course.',
        ], 409);
    }
}
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-rose-600">Academic catalog</p>
            <h2 class="mt-1 text-3xl font-bold text-slate-900">Course Management</h2>
            <p class="mt-1 text-sm text-slate-500">Maintain course records and their links to colleges, programs, and majors.</p>
        </div>

        <?php if (strtolower((string) ($_SESSION['role'] ?? '')) === 'admin'): ?>
            <button type="button" id="addCourseBtn" class="inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700">
                <i data-lucide="plus" class="h-4 w-4"></i>
                Add Course
            </button>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total courses</p>
            <p id="totalCount" class="mt-1 text-2xl font-bold text-slate-900">0</p>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Active</p>
            <p id="activeCount" class="mt-1 text-2xl font-bold text-emerald-900">0</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Inactive</p>
            <p id="inactiveCount" class="mt-1 text-2xl font-bold text-slate-700">0</p>
        </div>
    </div>

    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex flex-1 flex-col gap-2 sm:flex-row">
                <input id="courseSearch" type="search" placeholder="Search code, name, or description" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100">
                <select id="courseCollege" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                    <option value="">All colleges</option>
                </select>
                <select id="courseProgram" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                    <option value="">All programs</option>
                </select>
                <select id="courseStatus" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                    <option value="all">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <span id="courseStatusMessage" class="text-xs text-slate-500" aria-live="polite">Loading courses...</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[1050px] text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-3">Course code</th>
                        <th class="px-3 py-3">Course name</th>
                        <th class="px-3 py-3">Details</th>
                        <th class="px-3 py-3">Academic link</th>
                        <th class="px-3 py-3">Status</th>
                        <th class="px-3 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="courseBody" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
    </section>
</div>

<div id="courseModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm">
    <form id="courseForm" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-rose-600">Course record</p>
                <h3 id="courseModalTitle" class="text-xl font-bold text-slate-900">Add Course</h3>
            </div>
            <button type="button" id="closeCourseModal" class="text-slate-400" aria-label="Close">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>

        <input type="hidden" name="id" id="courseId">

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <label class="text-sm font-semibold text-slate-700">
                Course code
                <input name="code" id="courseCode" maxlength="30" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>

            <label class="text-sm font-semibold text-slate-700">
                Course name
                <input name="name" id="courseName" maxlength="180" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>

            <label class="text-sm font-semibold text-slate-700">
                Type
                <select name="type" id="courseType" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="Major">Major</option>
                    <option value="Minor">Minor</option>
                    <option value="Elective">Elective</option>
                    <option value="General Education">General Education</option>
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">
                Year level
                <input name="year_level" id="courseYear" type="number" min="1" max="8" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
            </label>

            <label class="text-sm font-semibold text-slate-700">
                Status
                <select name="status" id="formStatus" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">
                College
                <select id="formCollege" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="">No college link</option>
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">
                Program
                <select name="program_id" id="formProgram" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="">No program link</option>
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700 sm:col-span-2">
                Major
                <select name="major_id" id="formMajor" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="">No major link</option>
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700 sm:col-span-2">
                Description
                <textarea name="description" id="courseDescription" rows="4" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
            </label>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <button type="button" id="cancelCourseModal" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700">
                Save course
            </button>
        </div>
    </form>
</div>

<div id="courseToastContainer" class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3" role="status"></div>

<script>
(() => {
    const admin = <?php echo json_encode(strtolower((string) ($_SESSION['role'] ?? '')) === 'admin'); ?>;
    const state = { courses: [], colleges: [], programs: [], majors: [] };

    const $ = (id) => document.getElementById(id);
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));

    const toast = (message, error = false) => {
        const container = $('courseToastContainer');
        const node = document.createElement('div');
        const duration = 3000;

        node.className = `pointer-events-auto relative flex items-start gap-3 overflow-hidden rounded-xl border px-4 py-3 pb-4 text-sm shadow-lg ${error ? 'border-red-300 bg-red-50 text-red-800' : 'border-green-300 bg-green-50 text-green-800'}`;
        node.innerHTML = `
            <i data-lucide="${error ? 'circle-alert' : 'circle-check'}" class="mt-0.5 h-4 w-4 flex-shrink-0"></i>
            <span class="flex-1">${esc(message)}</span>
            <button type="button" class="text-current opacity-60 transition hover:opacity-100" aria-label="Dismiss notification">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
            <span class="absolute bottom-0 left-0 h-1 w-full origin-left ${error ? 'bg-red-500' : 'bg-green-500'}" data-toast-progress></span>
        `;

        container.appendChild(node);
        lucide.createIcons();

        const progress = node.querySelector('[data-toast-progress]');
        requestAnimationFrame(() => {
            progress.style.transition = `width ${duration}ms linear`;
            progress.style.width = '0%';
        });

        const dismiss = () => node.remove();
        node.querySelector('button').addEventListener('click', dismiss);
        setTimeout(dismiss, duration);
    };

    const request = async (url, options = {}) => {
        const rawOptions = {
            ...options,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' }),
                ...(options.headers ?? {})
            }
        };

        const response = await fetch(`pages/course.php${url}`, rawOptions);
        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Request failed.');
        }

        return data;
    };

    function fillSelect(select, items, emptyLabel, selected = '') {
        if (!select) {
            return;
        }

        select.innerHTML = `<option value="">${emptyLabel}</option>` + items
            .map((item) => `<option value="${item.id}" ${String(item.id) === String(selected) ? 'selected' : ''}>${esc(item.name)}</option>`)
            .join('');
    }

    function syncCourseProgramOptions() {
        const collegeId = $('courseCollege')?.value ?? '';
        const selectedProgram = $('courseProgram')?.value ?? '';
        const programs = state.programs.filter((program) => !collegeId || String(program.college_id) === String(collegeId));
        const validProgram = programs.some((program) => String(program.id) === String(selectedProgram)) ? selectedProgram : '';

        fillSelect($('courseProgram'), programs, 'All programs', validProgram);
    }

    function fillFormOptions(course = {}) {
        fillSelect(
            $('formCollege'),
            state.colleges.filter((item) => item.status === 'active'),
            'No college link',
            course.collegeId || ''
        );

        fillSelect(
            $('formProgram'),
            state.programs.filter(
                (item) => item.status === 'active' && (!course.collegeId || String(item.college_id) === String(course.collegeId))
            ),
            'No program link',
            course.programId || ''
        );

        fillSelect(
            $('formMajor'),
            state.majors.filter(
                (item) => item.status === 'active' && (!course.programId || String(item.program_id) === String(course.programId))
            ),
            'No major link',
            course.majorId || ''
        );
    }

    function openModal(course = null) {
        $('courseModalTitle').textContent = course ? 'Edit Course' : 'Add Course';
        $('courseId').value = course?.id || '';
        $('courseCode').value = course?.code || '';
        $('courseName').value = course?.name || '';
        $('courseType').value = course?.type || 'Major';
        $('courseYear').value = course?.yearLevel ?? '';
        $('courseDescription').value = course?.description || '';
        $('formStatus').value = course?.status || 'active';

        fillFormOptions(course || {});
        $('formCollege').value = course?.collegeId || '';

        $('courseModal').classList.remove('hidden');
        $('courseModal').classList.add('flex');
    }

    function closeModal() {
        $('courseModal').classList.add('hidden');
        $('courseModal').classList.remove('flex');
    }

    function closeCourseDetails() {
        $('courseDetailsModal')?.remove();
    }

    function openCourseDetails(course) {
        closeCourseDetails();

        const modal = document.createElement('div');
        modal.id = 'courseDetailsModal';
        modal.className = 'fixed inset-0 z-[55]';
        modal.innerHTML = `
            <button type="button" data-close-course-details class="absolute inset-0 bg-slate-900/30 backdrop-blur-sm" aria-label="Close course details"></button>
            <aside class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="courseDetailsTitle">
                <div class="flex items-start justify-between border-b border-slate-200 px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rose-600">Course record</p>
                        <h3 id="courseDetailsTitle" class="mt-1 text-xl font-bold text-slate-900">${esc(course.code)} - ${esc(course.name)}</h3>
                    </div>
                    <button type="button" data-close-course-details class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" aria-label="Close course details">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-6">
                    <p class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">${esc(course.description || 'No description provided.')}</p>

                    <dl class="mt-6 divide-y divide-slate-100 rounded-xl border border-slate-200">
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Type</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.type)}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Year level</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${course.yearLevel ?? 'Not set'}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">College</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.collegeName || 'Unassigned')}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Program</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.programName || 'Unassigned')}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Major</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.majorName || 'Unassigned')}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-3">
                            <dt class="text-sm text-slate-500">Status</dt>
                            <dd class="text-right text-sm font-medium text-slate-800">${esc(course.status)}</dd>
                        </div>
                    </dl>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
                    <button type="button" data-close-course-details class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Close</button>
                    ${admin ? `<button type="button" data-details-delete="${course.id}" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-100">Delete</button>` : ''}
                </div>
            </aside>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        modal.querySelectorAll('[data-close-course-details]').forEach((button) => {
            button.addEventListener('click', closeCourseDetails);
        });

        modal.querySelector('[data-details-delete]')?.addEventListener('click', () => openCourseDelete(course));
    }

    function closeCourseDelete() {
        $('courseDeleteModal')?.remove();
    }

    function openCourseDelete(course) {
        closeCourseDelete();

        const modal = document.createElement('div');
        modal.id = 'courseDeleteModal';
        modal.className = 'fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm';
        modal.innerHTML = `
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600">
                        <i data-lucide="trash-2" class="h-5 w-5"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Delete course?</h3>
                        <p class="mt-1 text-sm text-slate-600">Delete ${esc(course.code)} - ${esc(course.name)} permanently?</p>
                        <p class="mt-2 text-sm font-semibold text-red-600">This action cannot be undone.</p>
                    </div>
                </div>

                <label class="mt-5 block text-sm font-semibold text-slate-700">
                    Administrator password
                    <input type="password" data-course-delete-password autocomplete="current-password" class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm text-slate-700 focus:border-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Enter your password">
                </label>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" data-course-delete-cancel class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Cancel</button>
                    <button type="button" data-course-delete-confirm class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700">
                        <i data-lucide="trash-2" class="h-4 w-4"></i>
                        Delete
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        lucide.createIcons();

        modal.querySelector('[data-course-delete-cancel]').onclick = closeCourseDelete;
        modal.querySelector('[data-course-delete-password]').focus();
        modal.querySelector('[data-course-delete-confirm]').onclick = async () => {
            const password = modal.querySelector('[data-course-delete-password]').value;
            if (!password) {
                toast('Enter the administrator password to continue.', true);
                return;
            }

            const confirmButton = modal.querySelector('[data-course-delete-confirm]');
            confirmButton.disabled = true;
            confirmButton.innerHTML = '<i data-lucide="loader-circle" class="h-4 w-4 animate-spin"></i>Deleting...';
            lucide.createIcons();

            try {
                const data = await request('', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'delete', id: course.id, current_password: password })
                });

                closeCourseDelete();
                closeCourseDetails();
                toast(data.message);
                await loadCourses();
            } catch (error) {
                confirmButton.disabled = false;
                confirmButton.innerHTML = '<i data-lucide="trash-2" class="h-4 w-4"></i>Delete';
                lucide.createIcons();
                toast(error.message, true);
            }
        };
    }

    function render() {
        if (!$('courseBody')) {
            return;
        }

        $('courseBody').innerHTML = state.courses.length
            ? state.courses.map((course) => `
                <tr class="align-top hover:bg-slate-50">
                    <td class="px-3 py-4">
                        <div class="font-bold text-slate-900">${esc(course.code)}</div>
                    </td>
                    <td class="px-3 py-4 text-slate-600">
                        <div class="font-medium text-slate-800">${esc(course.name)}</div>
                    </td>
                    <td class="px-3 py-4 text-slate-600">
                        <span>${esc(course.type)}</span>
                        ${course.yearLevel ? `<div class="mt-1 text-xs text-slate-500">Year ${course.yearLevel}</div>` : ''}
                    </td>
                    <td class="px-3 py-4">
                        ${course.programName
                            ? `<div class="font-medium text-slate-800">${esc(course.programName)}</div><div class="text-xs text-slate-500">${esc(course.collegeName)}${course.majorName ? ` / ${esc(course.majorName)}` : ''}</div>`
                            : '<span class="text-xs italic text-slate-400">Unassigned</span>'}
                    </td>
                    <td class="px-3 py-4">
                        <span class="rounded-full px-2.5 py-1 text-xs font-bold ${course.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}">${esc(course.status)}</span>
                    </td>
                    <td class="px-3 py-4 text-right">
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="button" data-view="${course.id}" class="inline-flex items-center gap-1.5 rounded-md border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs font-semibold text-sky-700 hover:bg-sky-100">View</button>
                            ${admin ? `
                                <button type="button" data-edit="${course.id}" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:border-rose-400 hover:text-rose-700">Edit</button>
                                <button type="button" data-toggle="${course.id}" class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700">${course.status === 'active' ? 'Deactivate' : 'Activate'}</button>
                                <button type="button" data-unlink="${course.id}" class="rounded-md border border-amber-300 px-2.5 py-1.5 text-xs font-semibold text-amber-700">Unlink</button>
                                <button type="button" data-delete="${course.id}" class="rounded-md border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-600">Delete</button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `).join('')
            : `
                <tr>
                    <td colspan="5" class="px-3 py-10 text-center text-sm text-slate-500">No courses found.</td>
                </tr>
            `;
    }

    const params = () => {
        const query = new URLSearchParams({
            action: 'list',
            search: $('courseSearch').value,
            status: $('courseStatus').value,
            college_id: $('courseCollege').value,
            program_id: $('courseProgram').value
        });

        return `?${query}`;
    };

    let latestCourseRequest = 0;

    async function loadCourses() {
        syncCourseProgramOptions();
        const requestId = ++latestCourseRequest;

        try {
            const data = await request(params());
            if (requestId !== latestCourseRequest) {
                return;
            }

            state.courses = data.courses;
            $('totalCount').textContent = data.counts.total;
            $('activeCount').textContent = data.counts.active;
            $('inactiveCount').textContent = data.counts.inactive;
            render();
            $('courseStatusMessage').textContent = `${state.courses.length} shown`;
        } catch (error) {
            if (requestId !== latestCourseRequest) {
                return;
            }

            $('courseStatusMessage').textContent = error.message;
            toast(error.message, true);
        }
    }

    async function loadOptions() {
        const data = await request('?action=options');
        state.colleges = data.colleges;
        state.programs = data.programs;
        state.majors = data.majors;

        fillSelect($('courseCollege'), state.colleges, 'All colleges');
        fillSelect($('courseProgram'), state.programs, 'All programs');
    }

    $('courseSearch').addEventListener('input', loadCourses);
    ['courseCollege', 'courseProgram', 'courseStatus'].forEach((id) => $(id)?.addEventListener('change', loadCourses));
    $('addCourseBtn')?.addEventListener('click', () => openModal());
    $('closeCourseModal').addEventListener('click', closeModal);
    $('cancelCourseModal').addEventListener('click', closeModal);

    $('formCollege').addEventListener('change', () => {
        fillSelect(
            $('formProgram'),
            state.programs.filter((item) => !$('formCollege').value || String(item.college_id) === $('formCollege').value),
            'No program link'
        );
        fillSelect($('formMajor'), [], 'No major link');
    });

    $('formProgram').addEventListener('change', () => {
        fillSelect(
            $('formMajor'),
            state.majors.filter((item) => String(item.program_id) === $('formProgram').value),
            'No major link'
        );
    });

    $('courseForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = new FormData(event.target);
        form.append('action', 'save');

        try {
            const data = await request('', { method: 'POST', body: form });
            closeModal();
            toast(data.message);
            await loadCourses();
        } catch (error) {
            toast(error.message, true);
        }
    });

    $('courseBody').addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        if (!button) {
            return;
        }

        const id = Number(button.dataset.edit || button.dataset.toggle || button.dataset.unlink || button.dataset.delete || button.dataset.view || button.dataset.detailsDelete || 0);
        const course = state.courses.find((item) => item.id === id);

        try {
            if (button.dataset.view) {
                if (course) {
                    openCourseDetails(course);
                }
                return;
            }

            if (button.dataset.edit) {
                if (course) {
                    openModal(course);
                }
                return;
            }

            if (button.dataset.toggle) {
                const data = await request('', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'toggle_status', id })
                });
                toast(data.message);
                await loadCourses();
                return;
            }

            if (button.dataset.unlink && confirm('Remove this course from its program and major? The course record will remain.')) {
                const data = await request('', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'unlink', id })
                });
                toast(data.message);
                await loadCourses();
                return;
            }

            if (button.dataset.delete) {
                if (course) {
                    openCourseDelete(course);
                }
            }
        } catch (error) {
            toast(error.message, true);
        }
    });

    $('courseBody').addEventListener('click', (event) => {
        const deleteButton = event.target.closest('[data-details-delete]');
        if (!deleteButton) {
            return;
        }

        const course = state.courses.find((item) => item.id === Number(deleteButton.dataset.detailsDelete));
        if (course) {
            openCourseDelete(course);
        }
    });

    loadOptions()
        .then(loadCourses)
        .catch((error) => toast(error.message, true));
})();
</script>
