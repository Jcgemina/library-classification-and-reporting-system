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
<style>
  .academic-tree-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 0 12px; }
  .academic-tree-search { position: relative; display: block; width: min(300px, 100%); }
  .academic-tree-search [data-lucide] { position: absolute; top: 50%; left: 10px; width: 15px; height: 15px; color: #64748b; transform: translateY(-50%); pointer-events: none; }
  .academic-tree-search input { width: 100%; height: 36px; padding: 0 10px 0 32px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; color: #0f172a; font-size: 11px; }
  .academic-tree-search input:focus-visible { outline: none; background: #fff1f2; border-color: #be123c; }
  .academic-tree-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px; }
  .academic-tree-table { width: 100%; min-width: 640px; border-collapse: collapse; text-align: left; }
  .academic-tree-table thead { border-bottom: 1px solid #e2e8f0; background: #f8fafc; color: #475569; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
  .academic-tree-table th { padding: 10px 12px; }
  .academic-tree-table td { border-top: 1px solid #f1f5f9; padding: 9px 12px; color: #334155; font-size: 11px; }
  .academic-tree-table tbody tr:hover { background: #f8fafc; }
  .academic-tree-name-cell { min-width: 230px; }
  .academic-tree-name { display: flex; min-width: 0; align-items: center; gap: 7px; }
  .academic-tree-name.is-major { padding-left: 30px; }
  .academic-tree-unit-name { min-width: 0; overflow-wrap: anywhere; color: #0f172a; font-weight: 600; }
  [data-major-row] .academic-tree-unit-name { font-weight: 400; }
  .academic-tree-toggle, .academic-tree-toggle-spacer { display: inline-grid; width: 23px; height: 23px; flex: 0 0 auto; place-items: center; }
  .academic-tree-toggle { border: 0; border-radius: 5px; background: transparent; color: #52606d; cursor: pointer; }
  .academic-tree-toggle:hover { background: #f1f5f9; color: #0f172a; }
  .academic-tree-toggle:focus-visible, .academic-tree-action-trigger:focus-visible { outline: none; background: #f0f1f2; color: #27323b; }
  .academic-tree-toggle [data-lucide] { width: 14px; height: 14px; transition: transform .16s ease; }
  .academic-tree-toggle[aria-expanded="true"] [data-lucide] { transform: rotate(90deg); }
  .academic-tree-status { display: inline-flex; border-radius: 6px; padding: 4px 8px; font-size: 10px; font-weight: 600; white-space: nowrap; }
  .academic-tree-actions-cell { width: 1%; text-align: right; white-space: nowrap; }
  .academic-tree-action-anchor { display: inline-flex; }
  .academic-tree-action-trigger { display: inline-flex; min-height: 32px; align-items: center; gap: 5px; padding: 0 7px; border: 0; border-radius: 6px; background: transparent; color: #334155; font-size: 10px; font-weight: 600; cursor: pointer; }
  .academic-tree-action-trigger:hover { background: #f5f6f7; }
  .academic-tree-action-trigger [data-lucide] { width: 14px; height: 14px; }
  .academic-action-menu-options[popover] { position: fixed; inset: auto; width: max-content; min-width: 170px; max-width: calc(100vw - 16px); max-height: calc(100vh - 16px); margin: 0; overflow: auto; padding: 5px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; box-shadow: 0 12px 30px rgb(15 23 42 / .16); color: #0f172a; }
  .academic-action-menu-options .academic-row-action { display: flex; width: 100%; min-height: 36px; height: auto; justify-content: flex-start; padding: 0 9px; border: 1px solid transparent; border-radius: 5px; background: transparent; color: #66717b; font-size: 11px; }
  .academic-action-menu-options .academic-row-action:hover,
  .academic-action-menu-options .academic-row-action:not(.is-delete):focus-visible { outline: none; background: #f2f3f4; color: #27323b; }
  .academic-action-menu-options .academic-row-action.is-delete { border-color: transparent; background: transparent; color: #9c5b63; }
  .academic-action-menu-options .academic-row-action.is-delete:hover,
  .academic-action-menu-options .academic-row-action.is-delete:focus-visible { outline: none; background: #fbf1f2; color: #8d414b; }
</style>
<div class="space-y-5">
  <header class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
      <h2 class="text-2xl font-bold tracking-tight text-slate-900">Academics</h2>
      <p class="mt-1 text-sm text-slate-600">Find an academic unit, explore its programs, or review curriculum plans.</p>
    </div>
    <button type="button" id="addCollegeBtn" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-300">
      <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i><span id="academicAddLabel">Add academic unit</span>
    </button>
  </header>

  <section class="grid min-h-[570px] grid-cols-1 overflow-hidden rounded-xl border border-slate-200 bg-white md:grid-cols-[220px_minmax(0,1fr)]" aria-label="Academic management workspace">
    <aside class="border-b border-slate-200 bg-slate-50 p-3 md:border-b-0 md:border-r md:p-4" aria-label="Academic navigation">
      <p class="mb-2 ml-2 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-600">Workspace</p>
      <nav class="mb-4 grid grid-cols-3 gap-1 md:mb-6 md:grid-cols-1" aria-label="Academic views" id="academicsTabNav" role="tablist">
        <button type="button" id="academicsStructureTab" data-tab="hierarchy" role="tab" class="academics-tab flex min-h-9 items-center gap-2 rounded-md bg-rose-100 px-2.5 text-left text-xs font-semibold text-rose-800" aria-selected="true" aria-current="page" aria-controls="hierarchyPanel" tabindex="0">
          <i data-lucide="network" class="h-4 w-4 shrink-0" aria-hidden="true"></i>Structure
        </button>
        <button type="button" id="academicsUnitsTab" data-tab="secondary" role="tab" class="academics-tab flex min-h-9 items-center gap-2 rounded-md px-2.5 text-left text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900" aria-selected="false" aria-controls="secondaryViewPanel" tabindex="-1">
          <i data-lucide="list-filter" class="h-4 w-4 shrink-0" aria-hidden="true"></i>All units
        </button>
        <button type="button" id="academicsProspectusesTab" data-tab="prospectus" role="tab" class="academics-tab flex min-h-9 items-center gap-2 rounded-md px-2.5 text-left text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900" aria-selected="false" aria-controls="prospectusPanel" tabindex="-1">
          <i data-lucide="files" class="h-4 w-4 shrink-0" aria-hidden="true"></i>Prospectuses
        </button>
      </nav>
      <div id="collegePicker">
        <p class="mb-2 ml-2 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-600">Colleges</p>
        <div id="collegeChoices" class="flex gap-1 overflow-x-auto pb-1 md:grid md:overflow-visible"></div>
      </div>
      <span id="organizationStatus" class="sr-only" aria-live="polite">Loading organization...</span>
      <button type="button" id="retryOrganizationBtn" class="hidden mt-3 text-xs font-semibold text-rose-700 underline underline-offset-2">Retry loading academic units</button>
    </aside>

    <div class="min-w-0 p-4 sm:p-6">
      <section id="hierarchyPanel" role="tabpanel" aria-labelledby="academicsStructureTab" tabindex="0" class="tab-panel" aria-label="Academic structure">
        <div id="organizationTree" class="min-w-0"></div>
      </section>

      <section id="secondaryViewPanel" role="tabpanel" aria-labelledby="academicsUnitsTab" tabindex="0" class="tab-panel hidden" aria-label="All academic units">
        <header class="flex flex-col justify-between gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-start">
          <div><h3 class="text-lg font-bold text-slate-900">All academic units</h3><p class="mt-1 text-xs text-slate-600">Search colleges, programs, and majors in one place.</p></div>
        </header>
        <div class="flex flex-col justify-between gap-3 py-4 sm:flex-row sm:items-center">
          <label class="relative block w-full sm:max-w-[300px]">
            <i data-lucide="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true"></i>
            <span class="sr-only">Search academic units</span>
            <input id="organizationSearch" type="search" placeholder="Search academic units" class="h-9 w-full rounded-lg border border-slate-200 bg-white pl-8 pr-3 text-xs text-slate-900 placeholder:text-slate-600 focus:border-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-100">
          </label>
          <div class="flex gap-2">
            <label class="sr-only" for="organizationTypeFilter">Filter by unit type</label>
            <select id="organizationTypeFilter" class="h-9 min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2 text-xs text-slate-700 focus:border-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-100 sm:flex-none">
              <option value="all">All unit types</option><option value="college">Colleges</option><option value="program">Programs</option><option value="major">Majors</option>
            </select>
            <label class="sr-only" for="organizationStatusFilter">Filter by status</label>
            <select id="organizationStatusFilter" class="h-9 min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2 text-xs text-slate-700 focus:border-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-100 sm:flex-none">
              <option value="all">All statuses</option><option value="active">Active</option><option value="archived">Archived</option>
            </select>
          </div>
        </div>
        <div class="overflow-x-auto rounded-lg border border-slate-200">
          <table class="w-full min-w-[640px] border-collapse text-left">
            <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase tracking-wide text-slate-600">
              <tr><th scope="col" class="px-3 py-2.5">Academic unit</th><th scope="col" class="px-3 py-2.5">Type</th><th scope="col" class="px-3 py-2.5">Status</th><th scope="col" class="px-3 py-2.5 text-right">Actions</th></tr>
            </thead>
            <tbody id="organizationRecordsBody" class="divide-y divide-slate-100"></tbody>
          </table>
        </div>
      </section>

      <section id="prospectusPanel" role="tabpanel" aria-labelledby="academicsProspectusesTab" tabindex="0" class="tab-panel hidden" aria-label="Prospectuses">
        <header class="flex flex-col justify-between gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-start">
          <div><h3 class="text-lg font-bold text-slate-900">Prospectuses</h3><p class="mt-1 text-xs text-slate-600">Review curriculum plans and whether a PDF is available.</p></div>
          <button type="button" id="addProspectusBtn" class="inline-flex min-h-9 items-center justify-center gap-2 rounded-lg bg-rose-700 px-3 text-xs font-semibold text-white transition hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-300"><i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>Upload prospectus</button>
        </header>
        <div class="flex flex-col justify-between gap-3 py-4 sm:flex-row sm:items-center">
          <label class="relative block w-full sm:max-w-[300px]">
            <i data-lucide="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true"></i>
            <span class="sr-only">Search prospectuses</span>
            <input id="prospectusSearch" type="search" placeholder="Search prospectuses" class="h-9 w-full rounded-lg border border-slate-200 bg-white pl-8 pr-3 text-xs text-slate-900 placeholder:text-slate-600 focus:border-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-100">
          </label>
          <span id="prospectusStatus" class="text-xs text-slate-600" aria-live="polite">Loading prospectuses...</span>
        </div>
        <div id="prospectusRecordsBody" class="min-w-0"></div>
      </section>
    </div>
  </section>
</div>
<div id="organizationModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"><form id="organizationForm" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><h3 id="organizationModalTitle" class="text-xl font-bold">Add College</h3><button type="button" id="closeOrganizationModal" class="text-slate-400" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button></div><input type="hidden" id="organizationAction" name="action"><input type="hidden" id="organizationId" name="id"><input type="hidden" id="organizationParent" name="parent_id"><input type="hidden" id="organizationMajor" name="major_id"><p id="organizationContextHint" class="mt-4 hidden rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-xs text-rose-700"></p><label id="parentSelectLabel" class="mt-5 hidden text-sm font-medium">Parent organization<select id="parentSelect" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"></select></label><label id="majorSelectLabel" class="mt-3 hidden text-sm font-medium">Major<select id="majorSelect" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="">No major</option></select></label><div id="courseFields" class="mt-5 hidden grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium">Course code<input name="code" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label></div><label id="organizationCodeLabel" class="mt-5 block text-sm font-medium">Program / Subject code<input name="organization_code" id="organizationCode" maxlength="30" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><label id="organizationStatusLabel" class="mt-3 block text-sm font-medium">Status<select name="status" id="organizationRecordStatus" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="active">Active</option><option value="archived">Archived</option></select></label><label class="mt-3 block text-sm font-medium">Name<input id="organizationName" name="name" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><button class="mt-5 w-full rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white">Save</button></form></div>
<div id="prospectusModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"><form id="prospectusRecordForm" class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><div><p class="text-xs font-bold uppercase tracking-wide text-rose-600">Prospectus upload</p><h3 id="prospectusModalTitle" class="text-xl font-bold text-slate-900">Upload Prospectus</h3></div><button type="button" id="closeProspectusModal" class="text-slate-400" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button></div><input type="hidden" name="id" id="prospectusRecordId"><label class="mt-5 block text-sm font-medium">Program<select name="program_id" id="prospectusRecordProgram" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"></select></label><label class="mt-3 block text-sm font-medium">Major <span class="font-normal text-slate-400">(optional)</span><select name="major_id" id="prospectusRecordMajor" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="">All majors / general curriculum</option></select></label><div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="text-sm font-medium">Prospectus title<input name="title" id="prospectusRecordTitle" required maxlength="255" placeholder="BS Information Technology" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label><label class="text-sm font-medium">Curriculum year<input name="curriculum_year" id="prospectusRecordYear" required maxlength="30" placeholder="2026-2027" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"></label></div><label class="mt-3 block text-sm font-medium">PDF prospectus<input type="file" name="prospectus" id="prospectusRecordFile" accept="application/pdf,.pdf" class="mt-1 block w-full rounded-lg border-2 border-dashed border-rose-300 bg-rose-50 p-3 text-xs text-slate-700"></label><button type="submit" class="mt-6 w-full rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-700">Upload Prospectus</button></form></div>
<script src="assets/js/organization.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/organization.js'); ?>"></script>
