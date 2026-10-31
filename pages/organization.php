<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=academics');
    exit;
}
requireLogin();

function organizationJson(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

$action = requestString($_GET, 'action', requestString($_POST, 'action'));
if ($action !== '') {
    if (!$pdo) organizationJson(['success' => false, 'message' => 'Database unavailable.'], 503);
    if (!in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'librarian'], true)) {
        organizationJson(['success' => false, 'message' => 'Academic access required.'], 403);
    }

    try {
        if ($action === 'list') {

            $colleges = $pdo->query(
                'SELECT id, name, status, created_at FROM colleges ORDER BY name'
            )->fetchAll(PDO::FETCH_ASSOC);

            $programs = $pdo->query(
                'SELECT id, college_id, name, status, created_at FROM programs ORDER BY name'
            )->fetchAll(PDO::FETCH_ASSOC);

            $majors = $pdo->query(
                'SELECT id, program_id, name, status, created_at FROM majors ORDER BY name'
            )->fetchAll(PDO::FETCH_ASSOC);

            $collegeMap = [];
            $programMap = [];
            $records = [];

            foreach ($colleges as $college) {
                $collegeId = (int) $college['id'];

                $collegeMap[$collegeId] = [
                    'id' => $collegeId,
                    'name' => $college['name'],
                    'status' => $college['status'],
                    'created_at' => $college['created_at'],
                    'programs' => []
                ];

                $records[] = [
                    'id' => $collegeId,
                    'name' => $college['name'],
                    'code' => '',
                    'type' => 'University / College',
                    'entity' => 'college',
                    'parentId' => null,
                    'status' => $college['status'] ?? 'active',
                    'description' => '',
                    'createdAt' => $college['created_at'],
                ];
            }

            foreach ($programs as $program) {
                $programId = (int) $program['id'];
                $collegeId = (int) $program['college_id'];

                $programMap[$programId] = [
                    'id' => $programId,
                    'college_id' => $collegeId,
                    'name' => $program['name'],
                    'status' => $program['status'],
                    'created_at' => $program['created_at'],
                    'majors' => [],
                ];

                $records[] = [
                    'id' => $programId,
                    'name' => $program['name'],
                    'code' => '',
                    'type' => 'Program',
                    'entity' => 'program',
                    'parentId' => $collegeId,
                    'status' => $program['status'] ?? 'active',
                    'description' => '',
                    'createdAt' => $program['created_at'],
                ];
            }

            foreach ($majors as $major) {
                $majorId = (int) $major['id'];
                $programId = (int) $major['program_id'];

                if (isset($programMap[$programId])) {
                    $programMap[$programId]['majors'][] = $major;
                }

                $records[] = [
                    'id' => $majorId,
                    'name' => $major['name'],
                    'code' => '',
                    'type' => 'Major',
                    'entity' => 'major',
                    'parentId' => $programId,
                    'status' => $major['status'] ?? 'active',
                    'description' => '',
                    'createdAt' => $major['created_at'],
                ];
            }

            foreach ($programMap as $program) {
                $collegeId = $program['college_id'];

                if (!isset($collegeMap[$collegeId])) {
                    continue;
                }

                $collegeMap[$collegeId]['programs'][] = $program;
            }

            $counts = [
                'colleges' => count($colleges),
                'programs' => count($programs),
                'majors' => count($majors),
            ];

            organizationJson([
                'success' => true,
                'colleges' => array_values($collegeMap),
                'counts' => $counts,
                'records' => $records,
            ]);
        }

        if ($action === 'prospectus_data') {
            $prospectuses = $pdo->query('SELECT p.id, p.program_id, p.major_id, p.curriculum_year, pr.name AS program_name, m.name AS major_name, pd.file_name AS pdf_file_name, pd.file_path AS pdf_file_path, (pd.id IS NOT NULL) AS has_specific_pdf FROM prospectuses p INNER JOIN programs pr ON pr.id = p.program_id LEFT JOIN majors m ON m.id = p.major_id LEFT JOIN prospectus_documents pd ON pd.prospectus_id = p.id ORDER BY pr.name, p.curriculum_year')->fetchAll();
            $programs = $pdo->query('SELECT id, name FROM programs WHERE status = \'active\' ORDER BY name')->fetchAll();
            $majors = $pdo->query('SELECT id, program_id, name FROM majors WHERE status = \'active\' ORDER BY name')->fetchAll();
            $defaultCurriculumYear = $pdo->query(
                'SELECT default_curriculum_year FROM library_configuration WHERE id = 1'
            )->fetchColumn();
            if ($defaultCurriculumYear === false || $defaultCurriculumYear === '') {
                throw new PDOException('Default prospectus curriculum year is unavailable. Apply the latest schema.sql.');
            }
            $availableCourses = $pdo->query(
                'SELECT id, program_id, major_id, code, name, units
                FROM courses
                WHERE status = \'active\'
                ORDER BY code, name'
            )->fetchAll();            
            organizationJson(['success' => true, 'prospectuses' => $prospectuses, 'programs' => $programs, 'majors' => $majors, 'availableCourses' => $availableCourses, 'defaultCurriculumYear' => $defaultCurriculumYear]);
        }

        if ($action === 'save_prospectus') {
            $id = requestInt($_POST, 'id');
            $programId = requestInt($_POST, 'program_id');
            $majorIdInput = requestInt($_POST, 'major_id');
            $majorId = $majorIdInput !== 0 ? $majorIdInput : null;
            $curriculumYear = trim(requestString($_POST, 'curriculum_year'));
            if (!$programId || $curriculumYear === '') organizationJson(['success' => false, 'message' => 'Program and curriculum year are required.'], 422);
            $programCheck = $pdo->prepare('SELECT id FROM programs WHERE id = :id LIMIT 1');
            $programCheck->execute([':id' => $programId]);
            if (!$programCheck->fetchColumn()) organizationJson(['success' => false, 'message' => 'The selected program does not exist.'], 422);
            if ($majorId !== null) {
                $majorCheck = $pdo->prepare('SELECT id FROM majors WHERE id = :id AND program_id = :program_id LIMIT 1');
                $majorCheck->execute([':id' => $majorId, ':program_id' => $programId]);
                if (!$majorCheck->fetchColumn()) organizationJson(['success' => false, 'message' => 'The selected major does not belong to that program.'], 422);
            }
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE prospectuses SET program_id = :program_id, major_id = :major_id, curriculum_year = :curriculum_year WHERE id = :id');
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            } else {
                $stmt = $pdo->prepare('INSERT INTO prospectuses (program_id, major_id, curriculum_year) VALUES (:program_id, :major_id, :curriculum_year)');
            }
            $stmt->bindValue(':program_id', $programId, PDO::PARAM_INT);
            $stmt->bindValue(':major_id', $majorId, $majorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':curriculum_year', $curriculumYear);
            $stmt->execute();
            $savedId = $id > 0 ? $id : (int)$pdo->lastInsertId();
            organizationJson(['success' => true, 'id' => $savedId, 'message' => $id > 0 ? 'Prospectus updated successfully.' : 'Prospectus created successfully.']);
        }

        if ($action === 'delete_prospectus_record') {
            $passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $passwordStmt->execute([':id' => (int)$_SESSION['user_id']]);
            $storedPassword = $passwordStmt->fetchColumn();
            if (!is_string($storedPassword) || !password_verify(requestString($_POST, 'current_password'), $storedPassword)) organizationJson(['success' => false, 'message' => 'The current password is incorrect. Nothing was deleted.'], 403);
            $stmt = $pdo->prepare('DELETE FROM prospectuses WHERE id = :id');
            $stmt->execute([':id' => requestInt($_POST, 'id')]);
            organizationJson(['success' => true, 'message' => 'Prospectus deleted successfully.']);
        }

        if ($action === 'upload_prospectus') {
            $prospectusId = requestInt($_POST, 'prospectus_id');
            $file = $_FILES['prospectus'] ?? null;
            if (!$prospectusId || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024 || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
                organizationJson(['success' => false, 'message' => 'Please select a PDF prospectus no larger than 10 MB.'], 422);
            }
            $prospectusStmt = $pdo->prepare('SELECT id FROM prospectuses WHERE id = :id LIMIT 1');
            $prospectusStmt->execute([':id' => $prospectusId]);
            if (!$prospectusStmt->fetchColumn()) organizationJson(['success' => false, 'message' => 'The selected prospectus does not exist.'], 404);
            $directory = __DIR__ . '/../uploads/prospectuses';
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) organizationJson(['success' => false, 'message' => 'Upload directory is unavailable.'], 500);
            $storedName = bin2hex(random_bytes(12)) . '.pdf';
            if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $storedName)) organizationJson(['success' => false, 'message' => 'The prospectus could not be uploaded.'], 500);
            $oldStmt = $pdo->prepare('SELECT file_path FROM prospectus_documents WHERE prospectus_id = :prospectus_id LIMIT 1');
            $oldStmt->execute([':prospectus_id' => $prospectusId]);
            $oldPath = $oldStmt->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO prospectus_documents (prospectus_id, file_name, file_path) VALUES (:prospectus_id, :file_name, :file_path) ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_path = VALUES(file_path), uploaded_at = CURRENT_TIMESTAMP');
            $stmt->execute([':prospectus_id' => $prospectusId, ':file_name' => basename($file['name']), ':file_path' => 'uploads/prospectuses/' . $storedName]);
            if ($oldPath) {
                $oldFile = __DIR__ . '/../' . ltrim((string)$oldPath, '/\\');
                if (is_file($oldFile)) unlink($oldFile);
            }
            organizationJson(['success' => true, 'message' => 'Prospectus uploaded successfully.']);
        }

        if ($action === 'delete_prospectus_document') {
            $prospectusId = requestInt($_POST, 'prospectus_id');
            $stmt = $pdo->prepare('SELECT file_path FROM prospectus_documents WHERE prospectus_id = :prospectus_id LIMIT 1');
            $stmt->execute([':prospectus_id' => $prospectusId]);
            $filePath = $stmt->fetchColumn();
            if (!$filePath) organizationJson(['success' => false, 'message' => 'No prospectus PDF is attached to this record.'], 404);
            $stmt = $pdo->prepare('DELETE FROM prospectus_documents WHERE prospectus_id = :prospectus_id');
            $stmt->execute([':prospectus_id' => $prospectusId]);
            $file = __DIR__ . '/../' . ltrim((string)$filePath, '/\\');
            if (is_file($file)) unlink($file);
            organizationJson(['success' => true, 'message' => 'Prospectus PDF removed successfully.']);
        }

        if ($action === 'create_hierarchy') {
            $hierarchy = json_decode(requestString($_POST, 'hierarchy'), true);
            if (!is_array($hierarchy)) {
                organizationJson(['success' => false, 'message' => 'The college hierarchy is invalid.'], 422);
            }
            $collegeName = trim(is_string($hierarchy['name'] ?? null) ? $hierarchy['name'] : '');
            $programs = $hierarchy['programs'] ?? [];
            if (!is_array($programs)) {
                organizationJson(['success' => false, 'message' => 'The college hierarchy is invalid.'], 422);
            }
            if ($collegeName === '') organizationJson(['success' => false, 'message' => 'College name is required.'], 422);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO colleges (name) VALUES (:name)');
                $stmt->execute([':name' => $collegeName]);
                $collegeId = (int)$pdo->lastInsertId();

                foreach ($programs as $program) {
                        if (!is_array($program)) {
                            continue;
                        }
                        $programName = trim(is_string($program['name'] ?? null) ? $program['name'] : '');
                        if ($programName === '') continue;
                        $stmt = $pdo->prepare('INSERT INTO programs (college_id, name) VALUES (:college_id, :name)');
                        $stmt->execute([':college_id' => $collegeId, ':name' => $programName]);
                        $programId = (int)$pdo->lastInsertId();

                        $majors = $program['majors'] ?? [];
                        if (!is_array($majors)) {
                            continue;
                        }
                        foreach ($majors as $major) {
                            if (!is_array($major)) {
                                continue;
                            }
                            $majorName = trim(is_string($major['name'] ?? null) ? $major['name'] : '');
                            if ($majorName === '') continue;
                            $stmt = $pdo->prepare('INSERT INTO majors (program_id, name) VALUES (:program_id, :name)');
                            $stmt->execute([':program_id' => $programId, ':name' => $majorName]);
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
            'program' => ['table' => 'programs', 'parent' => 'college_id'],
            'major' => ['table' => 'majors', 'parent' => 'program_id'],
        ];
        if (preg_match('/\A(add|edit|delete|archive)_(college|program|major|course)\z/', $action, $actionParts) !== 1) {
            organizationJson(['success' => false, 'message' => 'Unknown organization action.'], 400);
        }
        $operation = $actionParts[1];
        $entity = $actionParts[2];
        if ($entity === 'course') {
            organizationJson(['success' => false, 'message' => 'Courses are managed in the Courses tab.'], 422);
        }
        if ($operation === 'delete') {
            $currentPassword = requestString($_POST, 'current_password');
            $passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $passwordStmt->execute([':id' => (int)$_SESSION['user_id']]);
            $userPassword = (string)($passwordStmt->fetchColumn() ?: '');
            if ($currentPassword === '' || $userPassword === '' || !password_verify($currentPassword, $userPassword)) {
                organizationJson(['success' => false, 'message' => 'The current password is incorrect. Nothing was deleted.'], 403);
            }
        }
        if (isset($entityActions[$entity])) {
            $config = $entityActions[$entity];
            $id = requestInt($_POST, 'id');
            if ($operation === 'archive') {
                $stmt = $pdo->prepare("UPDATE {$config['table']} SET status = CASE WHEN status = 'archived' THEN 'active' ELSE 'archived' END WHERE id = :id");
                $stmt->execute([':id' => $id]);
                organizationJson(['success' => true, 'message' => 'Organization status updated successfully.']);
            }
            if ($operation === 'delete') {
                $dependentQueries = [
                    'college' => 'SELECT COUNT(*) FROM programs WHERE college_id = :id',
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
                }
                if ($dependencyCount > 0) {
                    organizationJson(['success' => false, 'message' => 'This organization is still used by related records. Archive it instead or remove its dependent records first.'], 409);
                }
                $stmt = $pdo->prepare("DELETE FROM {$config['table']} WHERE id = :id");
                $stmt->execute([':id' => $id]);
            } else {
                $name = trim(requestString($_POST, 'name'));
                $status = requestString($_POST, 'status', 'active') === 'archived' ? 'archived' : 'active';
                if ($name === '') organizationJson(['success' => false, 'message' => 'A name is required.'], 422);
                if ($operation === 'add') {
                    $sql = "INSERT INTO {$config['table']} (" . ($config['parent'] ? $config['parent'] . ', ' : '') . "name, status) VALUES (" . ($config['parent'] ? ':parent_id, ' : '') . ':name, :status)';
                } else {
                    $sql = "UPDATE {$config['table']} SET " . ($config['parent'] ? $config['parent'] . ' = :parent_id, ' : '') . "name = :name, status = :status WHERE id = :id";
                }
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':status', $status);
                if ($config['parent']) $stmt->bindValue(':parent_id', requestInt($_POST, 'parent_id'), PDO::PARAM_INT);
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
<div class="space-y-6">
    <div>
        <h2 class="text-3xl font-bold text-slate-900">Academic Structure</h2>
        <p class="mt-1 text-sm text-slate-500">Switch tabs below to manage institutional hierarchy, search secondary records, or review prospectus plans.</p>
  </div>

  <!-- Tabbing Navigation UI -->
    <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
        <div class="inline-flex self-start rounded-lg border border-slate-200/80 bg-white p-1 shadow-sm xl:translate-y-5">
            <nav class="flex items-center gap-1" aria-label="Academics Tabs" id="academicsTabNav">
      <!-- 1st Tab: Hierarchy -->
      <button type="button" 
              data-tab="hierarchy" 
              class="academics-tab inline-flex items-center gap-1.5 rounded-md bg-rose-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-rose-700"
              aria-selected="true" 
              aria-controls="hierarchyPanel">
        <i data-lucide="network" class="h-3.5 w-3.5"></i>
        <span>Hierarchy View</span>
      </button>

      <!-- 2nd Tab: Secondary View -->
      <button type="button" 
              data-tab="secondary" 
              class="academics-tab inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold text-slate-600 transition-all hover:bg-slate-100 hover:text-slate-900"
              aria-selected="false" 
              aria-controls="secondaryViewPanel">
        <i data-lucide="table-properties" class="h-3.5 w-3.5"></i>
        <span>Secondary View</span>
      </button>

      <!-- 3rd Tab: Prospectus -->
      <button type="button" 
              data-tab="prospectus" 
              class="academics-tab inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold text-slate-600 transition-all hover:bg-slate-100 hover:text-slate-900"
              aria-selected="false" 
              aria-controls="prospectusPanel">
        <i data-lucide="file-text" class="h-3.5 w-3.5"></i>
        <span>Prospectus</span>
      </button>
      </nav>
    </div>
    <section class="w-full max-w-[520px] rounded-xl border border-slate-200 bg-white px-4 py-2 shadow-sm xl:ml-auto xl:w-[500px] xl:flex-none" aria-label="Academic unit totals">
        <div class="grid grid-cols-[minmax(0,1fr)_repeat(3,minmax(0,1fr))] items-center">
            <h3 class="pr-3 text-[10px] font-bold uppercase leading-tight tracking-[0.1em] text-rose-700">Academic units</h3>
            <?php foreach (['colleges','programs','majors'] as $count): ?>
                <div class="min-w-0 border-l border-slate-200 px-3">
                    <span data-count="<?= $count ?>" class="block text-xl font-bold leading-none tabular-nums text-slate-900" aria-live="polite">0</span>
                    <span class="mt-1 block text-[10px] font-semibold leading-none text-slate-600"><?= ucfirst($count) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
  </div>

  <!-- Tab Content Panels -->
  <div>
    <!-- Panel 1: Hierarchy View -->
    <div id="hierarchyPanel" role="tabpanel" class="tab-panel space-y-6">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2"><i data-lucide="building-2" class="h-5 w-5 text-rose-600"></i><div><h3 class="text-xl font-bold text-slate-900">Institutional Structure</h3><p class="text-sm text-slate-500">Start with a college, then add its programs and majors.</p></div></div>
                    <div class="flex items-center gap-3"><span id="organizationStatus" class="text-xs text-slate-600" aria-live="polite">Loading organization...</span><button type="button" id="retryOrganizationBtn" class="hidden text-xs font-semibold text-rose-700 underline underline-offset-2">Retry</button><button type="button" id="addCollegeBtn" class="inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-rose-700 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rose-300 focus:ring-offset-2 active:scale-95"><i data-lucide="plus" class="h-4 w-4"></i> Add Academic Unit</button></div>
                </div>
                <div id="organizationTree" class="space-y-2"></div>
        </section>
    </div>

    <!-- Panel 2: Secondary View -->
    <div id="secondaryViewPanel" role="tabpanel" class="tab-panel hidden space-y-6">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-5 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-2"><i data-lucide="search" class="h-5 w-5 text-rose-600"></i><div><h3 class="text-xl font-bold text-slate-900">Secondary Records View</h3><p class="text-sm text-slate-500">Search and filter records after the hierarchy is in place.</p></div></div>
                <div class="flex flex-col gap-2 sm:flex-row"><div class="relative"><i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true"></i><input id="organizationSearch" type="search" placeholder="Search organizations" class="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"></div><select id="organizationStatusFilter" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="all">All statuses</option><option value="active">Active</option><option value="archived">Archived</option></select><select id="organizationTypeFilter" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"><option value="all">All types</option><option value="college">University / College</option><option value="program">Program</option><option value="major">Major</option></select></div>
            </div>
            <div class="overflow-x-auto"><table class="w-full min-w-[650px] text-left text-sm"><thead class="border-y border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-3">Organization Name</th><th class="px-3 py-3">Type</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Actions</th></tr></thead><tbody id="organizationRecordsBody" class="divide-y divide-slate-100"></tbody></table></div>
        </section>
    </div>

    <!-- Panel 3: Prospectus -->
    <div id="prospectusPanel" role="tabpanel" class="tab-panel hidden space-y-6">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-rose-600">Curriculum planning</p><h3 class="mt-1 text-xl font-bold text-slate-900">Prospectus Management</h3><p class="mt-1 text-sm text-slate-500">Upload a prospectus, then review the courses already assigned to its program or major.</p></div>
                <button type="button" id="addProspectusBtn" class="inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-700"><i data-lucide="upload" class="h-4 w-4"></i>Upload Prospectus</button>
            </div>
            <div class="mb-4 flex flex-col gap-2 sm:flex-row"><div class="relative w-full sm:max-w-md"><i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true"></i><input id="prospectusSearch" type="search" placeholder="Search prospectuses" class="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm outline-none focus:border-rose-600 focus:ring-2 focus:ring-rose-100"></div><span id="prospectusStatus" class="self-center text-xs text-slate-500" aria-live="polite">Loading prospectuses...</span></div>
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="max-h-[600px] overflow-x-auto overflow-y-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="w-[35%] px-4 py-3.5 font-bold">Program</th>
                                <th class="w-[25%] px-4 py-3.5 font-bold">Major</th>
                                <th class="w-[15%] px-4 py-3.5 font-bold">Curriculum</th>
                                <th class="w-[12%] px-4 py-3.5 text-center font-bold">PDF</th>
                                <th class="w-[13%] px-4 py-3.5 text-right font-bold">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="prospectusRecordsBody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
  </div>
</div>
<div id="organizationModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"><form id="organizationForm" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><h3 id="organizationModalTitle" class="text-xl font-bold">Add College</h3><button type="button" id="closeOrganizationModal" class="text-slate-400" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button></div><input type="hidden" id="organizationAction" name="action"><input type="hidden" id="organizationId" name="id"><input type="hidden" id="organizationParent" name="parent_id"><input type="hidden" id="organizationMajor" name="major_id"><p id="organizationContextHint" class="mt-4 hidden rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-xs text-rose-700"></p><label id="parentSelectLabel" class="mt-5 hidden text-sm font-medium">Parent organization<select id="parentSelect" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"></select></label><label id="majorSelectLabel" class="mt-3 hidden text-sm font-medium">Major<select id="majorSelect" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="">No major</option></select></label><div id="courseFields" class="mt-5 hidden grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium">Course code<input name="code" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label></div><label id="organizationCodeLabel" class="mt-5 block text-sm font-medium">Program / Subject code<input name="organization_code" id="organizationCode" maxlength="30" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><label id="organizationStatusLabel" class="mt-3 block text-sm font-medium">Status<select name="status" id="organizationRecordStatus" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="active">Active</option><option value="archived">Archived</option></select></label><label class="mt-3 block text-sm font-medium">Name<input id="organizationName" name="name" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><button class="mt-5 w-full rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white">Save</button></form></div>
<div id="prospectusModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"><form id="prospectusRecordForm" class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><div><p class="text-xs font-bold uppercase tracking-wide text-rose-600">Prospectus upload</p><h3 id="prospectusModalTitle" class="text-xl font-bold text-slate-900">Upload Prospectus</h3></div><button type="button" id="closeProspectusModal" class="text-slate-400" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button></div><input type="hidden" name="id" id="prospectusRecordId"><label class="mt-5 block text-sm font-medium">Program<select name="program_id" id="prospectusRecordProgram" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"></select></label><label class="mt-3 block text-sm font-medium">Major <span class="font-normal text-slate-400">(optional)</span><select name="major_id" id="prospectusRecordMajor" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="">All majors / general curriculum</option></select></label><div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="text-sm font-medium">Prospectus title<input name="title" id="prospectusRecordTitle" required maxlength="255" placeholder="BS Information Technology" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm font-medium">Curriculum year<input name="curriculum_year" id="prospectusRecordYear" required maxlength="30" placeholder="2026-2027" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label></div><label class="mt-3 block text-sm font-medium">PDF prospectus<input type="file" name="prospectus" id="prospectusRecordFile" accept="application/pdf,.pdf" class="mt-1 block w-full rounded-lg border-2 border-dashed border-rose-300 bg-rose-50 p-3 text-xs text-slate-700"></label><button type="submit" class="mt-6 w-full rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-700">Upload Prospectus</button></form></div>
<script src="assets/js/organization.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/organization.js'); ?>"></script>
