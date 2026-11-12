<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=course');
    exit;
}

requireLogin();
$courseCsvEnabled = filter_var(environmentValue('COURSE_CSV_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);

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
                $minimumBooksPerCourse = (int) $pdo->query(
                    'SELECT minimum_books_per_course FROM library_configuration WHERE id = 1'
                )->fetchColumn();
                if ($minimumBooksPerCourse < 1) {
                    courseJson(['success' => false, 'message' => 'Course readiness settings are unavailable. Check the latest schema.sql.'], 503);
                }

                $search = trim(requestString($_GET, 'search'));
                $status = requestString($_GET, 'status', 'all');
                $collegeId = requestInt($_GET, 'college_id');
                $programId = requestInt($_GET, 'program_id');
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

                $sql = 'SELECT c.id, c.code, c.name, c.description, c.status, c.program_id, c.major_id, p.name AS program_name, col.id AS college_id, col.name AS college_name, m.name AS major_name,
                        (SELECT COUNT(*) FROM book_courses bc INNER JOIN books b ON b.book_id = bc.book_id WHERE bc.course_id = c.id AND b.deleted_at IS NULL) AS book_count
                        FROM courses c
                        LEFT JOIN programs p ON p.id = c.program_id
                        LEFT JOIN colleges col ON col.id = p.college_id
                        LEFT JOIN majors m ON m.id = c.major_id';
                if ($where) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }
                $sql .= ' ORDER BY c.code, c.name';

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $courses = array_map(static function (array $course) use ($minimumBooksPerCourse): array {
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
                        'bookCount' => (int) $course['book_count'],
                        'minimumBooks' => $minimumBooksPerCourse,
                        'meetsBookMinimum' => (int) $course['book_count'] >= $minimumBooksPerCourse,
                    ];
                }, $stmt->fetchAll());

                if ($courses) {
                    $courseIds = array_column($courses, 'id');
                    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
                    $bookStatement = $pdo->prepare(
                        "SELECT bc.course_id, b.book_id, b.title, b.author, b.publication_year
                         FROM book_courses bc
                         INNER JOIN books b ON b.book_id = bc.book_id AND b.deleted_at IS NULL
                         WHERE bc.course_id IN ({$placeholders})
                         ORDER BY b.title, b.book_id"
                    );
                    $bookStatement->execute($courseIds);
                    $booksByCourse = [];
                    foreach ($bookStatement->fetchAll(PDO::FETCH_ASSOC) as $book) {
                        $booksByCourse[(int) $book['course_id']][] = [
                            'id' => (int) $book['book_id'],
                            'title' => $book['title'],
                            'author' => $book['author'],
                            'year' => $book['publication_year'] !== null ? (int) $book['publication_year'] : null,
                        ];
                    }
                    foreach ($courses as $index => $course) {
                        $courses[$index]['referencedBooks'] = $booksByCourse[$course['id']] ?? [];
                    }
                }

                $counts = [
                    'total' => (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
                    'active' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'active'")->fetchColumn(),
                    'inactive' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'inactive'")->fetchColumn(),
                    'meets_minimum' => (int) $pdo->query(
                        'SELECT COUNT(*) FROM courses c
                         WHERE (SELECT COUNT(*)
                                FROM book_courses bc
                                INNER JOIN books b ON b.book_id = bc.book_id AND b.deleted_at IS NULL
                                WHERE bc.course_id = c.id) >= ' . (int) $minimumBooksPerCourse
                    )->fetchColumn(),
                ];

                courseJson(['success' => true, 'courses' => $courses, 'counts' => $counts]);

            case 'options':
                $colleges = $pdo->query('SELECT id, name, status FROM colleges ORDER BY name')->fetchAll();
                $programs = $pdo->query('SELECT id, college_id, name, status FROM programs ORDER BY name')->fetchAll();
                $majors = $pdo->query('SELECT id, program_id, name, status FROM majors ORDER BY name')->fetchAll();

                courseJson(['success' => true, 'colleges' => $colleges, 'programs' => $programs, 'majors' => $majors]);

            case 'delete':
                $id = requestInt($_POST, 'id');
                $passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
                $passwordStmt->execute([':id' => (int) $_SESSION['user_id']]);

                $storedPassword = $passwordStmt->fetchColumn();
                $currentPassword = requestString($_POST, 'current_password');
                if (!is_string($storedPassword) || !password_verify($currentPassword, $storedPassword)) {
                    courseJson(['success' => false, 'message' => 'The current password is incorrect. Nothing was deleted.'], 403);
                }

                $stmt = $pdo->prepare('DELETE FROM courses WHERE id = :id');
                $stmt->execute([':id' => $id]);

                courseJson(['success' => true, 'message' => 'Course deleted successfully.']);

            case 'toggle_status':
                $id = requestInt($_POST, 'id');
                $stmt = $pdo->prepare("UPDATE courses SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :id");
                $stmt->execute([':id' => $id]);

                courseJson(['success' => true, 'message' => 'Course status updated.']);

            case 'import':
                if (!$courseCsvEnabled) {
                    courseJson(['success' => false, 'message' => 'Course CSV import is temporarily disabled.'], 503);
                }

                $upload = $_FILES['csv_file'] ?? null;
                if (!is_array($upload)) {
                    courseJson(['success' => false, 'message' => 'Choose a CSV file to import.'], 422);
                }

                $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $message = match ($uploadError) {
                        UPLOAD_ERR_NO_FILE => 'Choose a CSV file to import.',
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The CSV exceeds the server upload limit.',
                        UPLOAD_ERR_PARTIAL => 'The CSV upload was incomplete. Try again.',
                        default => 'The CSV could not be uploaded. Try again.',
                    };
                    courseJson(['success' => false, 'message' => $message], 422);
                }

                if (($upload['size'] ?? 0) > 5 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'] ?? '')) {
                    courseJson(['success' => false, 'message' => 'The CSV file is invalid or exceeds the 5 MB limit.'], 422);
                }

                $handle = fopen($upload['tmp_name'], 'rb');
                if ($handle === false) {
                    courseJson(['success' => false, 'message' => 'Unable to read the uploaded CSV file.'], 422);
                }

                $headers = fgetcsv($handle, null, ',', '"', '');
                if (!is_array($headers) || $headers === [null]) {
                    fclose($handle);
                    courseJson(['success' => false, 'message' => 'The CSV file must include a header row.'], 422);
                }

                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? '';
                $aliases = ['course_code' => 'code', 'course_name' => 'name'];
                $indexes = [];
                foreach ($headers as $index => $header) {
                    $header = strtolower(trim(is_string($header) ? $header : ''));
                    $header = preg_replace('/[\s-]+/', '_', $header) ?? '';
                    $header = $aliases[$header] ?? $header;
                    if ($header !== '' && isset($indexes[$header])) {
                        fclose($handle);
                        courseJson(['success' => false, 'message' => 'The CSV contains duplicate column names.'], 422);
                    }
                    if ($header !== '') {
                        $indexes[$header] = $index;
                    }
                }

                foreach (['code', 'name', 'program_id'] as $requiredColumn) {
                    if (!isset($indexes[$requiredColumn])) {
                        fclose($handle);
                        courseJson(['success' => false, 'message' => 'CSV headers must include code, name, and program_id.'], 422);
                    }
                }

                $errors = [];
                $rows = [];
                $programCache = [];
                $majorCache = [];
                $seenCodes = [];
                $dataRowCount = 0;
                $rowNumber = 1;
                $programCheck = $pdo->prepare('SELECT id FROM programs WHERE id = :id LIMIT 1');
                $majorCheck = $pdo->prepare('SELECT id FROM majors WHERE id = :id AND program_id = :program_id LIMIT 1');
                $courseCheck = $pdo->prepare('SELECT id FROM courses WHERE program_id = :program_id AND code = :code LIMIT 1');

                while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                    $rowNumber++;
                    if ($values === null || $values === [null] || count(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== '')) === 0) {
                        continue;
                    }

                    $dataRowCount++;
                    if ($dataRowCount > 1000) {
                        $errors[] = 'The CSV may contain no more than 1,000 course rows.';
                        break;
                    }

                    $value = static function (string $column) use ($values, $indexes): string {
                        $index = $indexes[$column] ?? null;
                        return $index === null ? '' : trim($values[$index] ?? '');
                    };
                    $code = strtoupper($value('code'));
                    $name = $value('name');
                    $programIdValue = $value('program_id');
                    $majorIdValue = $value('major_id');
                    $description = $value('description');
                    $statusValue = strtolower($value('status'));
                    $rowErrors = [];

                    if (preg_match('/\A[A-Za-z0-9]{1,30}\z/', $code) !== 1) {
                        $rowErrors[] = 'code must contain 1 to 30 letters or numbers';
                    }
                    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 180) {
                        $rowErrors[] = 'name is required and must be at most 180 characters';
                    }
                    if (preg_match('/\A[1-9][0-9]*\z/', $programIdValue) !== 1) {
                        $rowErrors[] = 'program_id must be a positive integer';
                    }
                    if ($majorIdValue !== '' && preg_match('/\A[1-9][0-9]*\z/', $majorIdValue) !== 1) {
                        $rowErrors[] = 'major_id must be a positive integer';
                    }
                    if ((function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description)) > 500) {
                        $rowErrors[] = 'description must be at most 500 characters';
                    }
                    if ($statusValue !== '' && !in_array($statusValue, ['active', 'inactive'], true)) {
                        $rowErrors[] = 'status must be active or inactive';
                    }

                    $programId = preg_match('/\A[1-9][0-9]*\z/', $programIdValue) === 1 ? (int) $programIdValue : 0;
                    $majorId = $majorIdValue !== '' && preg_match('/\A[1-9][0-9]*\z/', $majorIdValue) === 1 ? (int) $majorIdValue : null;

                    if ($programId > 0) {
                        if (!array_key_exists($programId, $programCache)) {
                            $programCheck->execute([':id' => $programId]);
                            $programCache[$programId] = (bool) $programCheck->fetchColumn();
                        }
                        if (!$programCache[$programId]) {
                            $rowErrors[] = 'program_id does not match an existing program';
                        }
                    }

                    if ($majorId !== null && $programId > 0) {
                        $majorKey = $majorId . ':' . $programId;
                        if (!array_key_exists($majorKey, $majorCache)) {
                            $majorCheck->execute([':id' => $majorId, ':program_id' => $programId]);
                            $majorCache[$majorKey] = (bool) $majorCheck->fetchColumn();
                        }
                        if (!$majorCache[$majorKey]) {
                            $rowErrors[] = 'major_id does not belong to program_id';
                        }
                    }

                    if ($programId > 0 && preg_match('/\A[A-Za-z0-9]{1,30}\z/', $code) === 1) {
                        $codeKey = $programId . ':' . $code;
                        if (isset($seenCodes[$codeKey])) {
                            $rowErrors[] = 'code is repeated in this CSV for the same program';
                        } else {
                            $seenCodes[$codeKey] = true;
                            $courseCheck->execute([':program_id' => $programId, ':code' => $code]);
                            if ($courseCheck->fetchColumn()) {
                                $rowErrors[] = 'code already exists for this program';
                            }
                        }
                    }

                    if ($rowErrors) {
                        $errors[] = 'Row ' . $rowNumber . ': ' . implode('; ', $rowErrors) . '.';
                        continue;
                    }

                    $rows[] = [
                        'code' => $code,
                        'name' => $name,
                        'program_id' => $programId,
                        'major_id' => $majorId,
                        'description' => $description,
                        'status' => $statusValue !== '' ? $statusValue : 'active',
                    ];
                }
                fclose($handle);

                if ($dataRowCount === 0) {
                    $errors[] = 'The CSV contains no course rows.';
                }
                if ($errors) {
                    $errorMessage = 'No courses were imported. ' . implode(' ', array_slice($errors, 0, 10));
                    if (count($errors) > 10) {
                        $errorMessage .= ' Additional row errors were omitted.';
                    }
                    courseJson(['success' => false, 'message' => $errorMessage, 'errors' => $errors], 422);
                }

                $pdo->beginTransaction();
                try {
                    $insertCourse = $pdo->prepare('INSERT INTO courses (program_id, major_id, code, name, description, status) VALUES (:program_id, :major_id, :code, :name, :description, :status)');
                    foreach ($rows as $row) {
                        $insertCourse->execute([
                            ':program_id' => $row['program_id'],
                            ':major_id' => $row['major_id'],
                            ':code' => $row['code'],
                            ':name' => $row['name'],
                            ':description' => $row['description'] !== '' ? $row['description'] : null,
                            ':status' => $row['status'],
                        ]);
                    }
                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }

                courseJson(['success' => true, 'message' => count($rows) . ' courses imported successfully.']);

            case 'save':
                $id = requestInt($_POST, 'id');
                $code = strtoupper(trim(requestString($_POST, 'code')));
                $name = trim(requestString($_POST, 'name'));
                $programIdInput = requestInt($_POST, 'program_id');
                $programId = $programIdInput !== 0 ? $programIdInput : null;
                $majorIdInput = requestInt($_POST, 'major_id');
                $majorId = $majorIdInput !== 0 ? $majorIdInput : null;
                $status = requestString($_POST, 'status', 'active') === 'inactive' ? 'inactive' : 'active';
                $description = trim(requestString($_POST, 'description'));

                if ($programId === null) {
                    courseJson(['success' => false, 'message' => 'A program assignment is required for every course.'], 422);
                }

                if ($code === '' || $name === '') {
                    courseJson(['success' => false, 'message' => 'Course code and name are required.'], 422);
                }

                if (preg_match('/\A[A-Za-z0-9]{1,30}\z/', $code) !== 1) {
                    courseJson(['success' => false, 'message' => 'Course code must contain 1 to 30 letters or numbers.'], 422);
                }

                $programCheck = $pdo->prepare('SELECT id FROM programs WHERE id = :id LIMIT 1');
                $programCheck->execute([':id' => $programId]);

                if (!$programCheck->fetchColumn()) {
                    courseJson(['success' => false, 'message' => 'The selected program does not exist.'], 422);
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

                $stmt->bindValue(':program_id', $programId, PDO::PARAM_INT);
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

<div class="live-course-page space-y-6" data-course-page data-course-admin="<?php echo in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['admin', 'librarian'], true) ? 'true' : 'false'; ?>">
    <section class="courses-heading" aria-labelledby="coursesTitle">
        <div>
            <h1 id="coursesTitle">Courses</h1>
            <p class="page-description">Review course records, academic assignments, and active book coverage.</p>
        </div>
        <?php if (in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['admin', 'librarian'], true)): ?>
            <div class="flex flex-wrap gap-2">
                <?php if ($courseCsvEnabled): ?>
                    <button type="button" id="importCoursesBtn" class="live-button" title="Import a CSV with code, name, and program_id columns">
                        <i data-lucide="upload" aria-hidden="true"></i>Import CSV
                    </button>
                    <a href="assets/course-template.csv" download="courses-template.csv" class="live-button">
                        <i data-lucide="download" aria-hidden="true"></i>CSV template
                    </a>
                    <input type="file" id="courseCsvFile" accept=".csv,text/csv" class="hidden" aria-label="Choose course CSV file">
                <?php endif; ?>
                <button type="button" id="addCourseBtn" class="live-button live-button-primary">
                    <i data-lucide="plus" aria-hidden="true"></i>Add course
                </button>
            </div>
        <?php endif; ?>
    </section>

    <section class="courses-summary" aria-label="Course totals">
        <div class="courses-summary-item"><strong id="totalCount">0</strong><span>Courses</span></div>
        <div class="courses-summary-item"><strong id="activeCount">0</strong><span>Active</span></div>
        <div class="courses-summary-item"><strong id="readyCount">0</strong><span>Meet book minimum</span></div>
        <div class="courses-summary-item"><span id="courseStatusMessage" aria-live="polite">Loading courses…</span></div>
    </section>

    <section class="courses-workspace" aria-labelledby="coursesDirectoryTitle">
        <div class="courses-directory-heading">
            <div><h2 id="coursesDirectoryTitle">Course directory</h2><p>Search by code, name, or academic assignment.</p></div>
        </div>
        <div class="courses-filters">
            <label class="sr-only" for="courseSearch">Search courses</label>
            <input id="courseSearch" type="search" placeholder="Search course code, name, program, or major">
            <label class="sr-only" for="courseCollege">Filter by college</label>
            <select id="courseCollege"><option value="">All colleges</option></select>
            <label class="sr-only" for="courseProgram">Filter by program</label>
            <select id="courseProgram"><option value="">All programs</option></select>
            <label class="sr-only" for="courseStatus">Filter by status</label>
            <select id="courseStatus">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <div class="courses-table-wrap">
            <table class="courses-table">
                <thead>
                    <tr><th scope="col">Course</th><th scope="col">Academic link</th><th scope="col">Status</th><th scope="col">Book readiness</th><th scope="col">Actions</th></tr>
                </thead>
                <tbody id="courseBody"></tbody>
            </table>
        </div>
    </section>

    <div id="courseModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4">
    <form id="courseForm" class="course-modal">
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
                <input name="code" id="courseCode" type="text" autocapitalize="characters" pattern="[A-Za-z0-9]+" title="Use letters and numbers only." maxlength="30" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 uppercase">
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
                <select name="program_id" id="formProgram" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    <option value="">Select a program</option>
                </select>
            </label>

            <label class="text-sm font-semibold text-slate-700">
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
</div>

<script src="assets/js/course.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/course.js'); ?>"></script>
