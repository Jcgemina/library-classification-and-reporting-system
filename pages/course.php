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

$action = $_GET['action'] ?? $_POST['action'] ?? null;
if ($action !== null) {
    $canManageCourses = in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['admin', 'librarian'], true);
    if (!$canManageCourses) {
        courseJson(['success' => false, 'message' => 'Course access required.'], 403);
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

                $sql = 'SELECT c.id, c.code, c.name, c.description, c.status, c.program_id, c.major_id, p.name AS program_name, col.id AS college_id, col.name AS college_name, m.name AS major_name FROM courses c LEFT JOIN programs p ON p.id = c.program_id LEFT JOIN colleges col ON col.id = p.college_id LEFT JOIN majors m ON m.id = c.major_id';                if ($where) {
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
                        'status' => $course['status'],
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
                    $stmt = $pdo->prepare('UPDATE courses SET program_id = :program_id, major_id = :major_id, code = :code, name = :name, description = :description, status = :status WHERE id = :id');
                    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO courses (program_id, major_id, code, name, description, status) VALUES (:program_id, :major_id, :code, :name, :description, :status)');
                }

                $stmt->bindValue(':program_id', $programId, $programId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':major_id', $majorId, $majorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':code', $code);
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':description', $description !== '' ? $description : null, $description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':status', $status);
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

<div class="space-y-6" data-course-page data-course-admin="<?php echo in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['admin', 'librarian'], true) ? 'true' : 'false'; ?>">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-rose-600">Academic catalog</p>
            <h2 class="mt-1 text-3xl font-bold text-slate-900">Course Management</h2>
            <p class="mt-1 text-sm text-slate-500">Maintain course records and their links to colleges, programs, and majors.</p>
        </div>

        <?php if (in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['admin', 'librarian'], true)): ?>
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

<script src="assets/js/course.js"></script>
