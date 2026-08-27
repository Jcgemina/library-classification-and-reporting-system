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
  if (!$pdo) {
    organizationJson(['success' => false, 'message' => 'Database unavailable.'], 503);
  }
  if ($action !== 'list' && strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    organizationJson(['success' => false, 'message' => 'Admin access required.'], 403);
  }

  try {
    if ($action === 'list') {
      $colleges = $pdo->query('SELECT id, name FROM colleges ORDER BY name')->fetchAll();
      foreach ($colleges as &$college) {
        $departmentStmt = $pdo->prepare('SELECT id, name FROM departments WHERE college_id = :id ORDER BY name');
        $departmentStmt->execute([':id' => $college['id']]);
        $college['departments'] = $departmentStmt->fetchAll();
        foreach ($college['departments'] as &$department) {
          $programStmt = $pdo->prepare('SELECT id, name FROM programs WHERE department_id = :id ORDER BY name');
          $programStmt->execute([':id' => $department['id']]);
          $department['programs'] = $programStmt->fetchAll();
          foreach ($department['programs'] as &$program) {
            $courseStmt = $pdo->prepare('SELECT id, code, name FROM courses WHERE program_id = :id ORDER BY code');
            $courseStmt->execute([':id' => $program['id']]);
            $program['courses'] = $courseStmt->fetchAll();
            $prospectusStmt = $pdo->prepare('SELECT file_name, file_path FROM program_prospectuses WHERE program_id = :id LIMIT 1');
            $prospectusStmt->execute([':id' => $program['id']]);
            $program['prospectus'] = $prospectusStmt->fetch() ?: null;
          }
        }
      }
      unset($college, $department, $program);
      organizationJson(['success' => true, 'colleges' => $colleges, 'counts' => [
        'colleges' => (int)$pdo->query('SELECT COUNT(*) FROM colleges')->fetchColumn(),
        'departments' => (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn(),
        'programs' => (int)$pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn(),
        'courses' => (int)$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
      ]]);
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') organizationJson(['success' => false, 'message' => 'A name is required.'], 422);
    if ($action === 'upload_prospectus') {
      $programId = (int)($_POST['program_id'] ?? 0);
      $file = $_FILES['prospectus'] ?? null;
      if (!$programId || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024 || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        organizationJson(['success' => false, 'message' => 'Please select a PDF prospectus no larger than 10 MB.'], 422);
      }
      $directory = __DIR__ . '/../uploads/prospectuses';
      if (!is_dir($directory)) mkdir($directory, 0755, true);
      $storedName = bin2hex(random_bytes(12)) . '.pdf';
      if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $storedName)) {
        organizationJson(['success' => false, 'message' => 'The prospectus could not be uploaded.'], 500);
      }
      $stmt = $pdo->prepare('INSERT INTO program_prospectuses (program_id, file_name, file_path) VALUES (:program_id, :file_name, :file_path) ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), file_path = VALUES(file_path), uploaded_at = CURRENT_TIMESTAMP');
      $stmt->execute([':program_id' => $programId, ':file_name' => basename($file['name']), ':file_path' => 'uploads/prospectuses/' . $storedName]);
    } elseif ($action === 'add_college') {
      $stmt = $pdo->prepare('INSERT INTO colleges (name) VALUES (:name)');
      $stmt->execute([':name' => $name]);
    } elseif ($action === 'add_department') {
      $stmt = $pdo->prepare('INSERT INTO departments (college_id, name) VALUES (:parent_id, :name)');
      $stmt->execute([':parent_id' => (int)$_POST['parent_id'], ':name' => $name]);
    } elseif ($action === 'add_program') {
      $stmt = $pdo->prepare('INSERT INTO programs (department_id, name) VALUES (:parent_id, :name)');
      $stmt->execute([':parent_id' => (int)$_POST['parent_id'], ':name' => $name]);
    } elseif ($action === 'add_course') {
      $stmt = $pdo->prepare('INSERT INTO courses (program_id, code, name, year_level) VALUES (:parent_id, :code, :name, :year_level)');
      $stmt->execute([':parent_id' => (int)$_POST['parent_id'], ':code' => strtoupper(trim((string)$_POST['code'])), ':name' => $name, ':year_level' => ($_POST['year_level'] ?? '') !== '' ? (int)$_POST['year_level'] : null]);
    } else {
      organizationJson(['success' => false, 'message' => 'Unknown organization action.'], 400);
    }
    organizationJson(['success' => true, 'message' => 'Organization updated successfully.']);
  } catch (PDOException $exception) {
    organizationJson(['success' => false, 'message' => (($exception->errorInfo[1] ?? 0) === 1062) ? 'That organization name already exists at this level.' : 'Unable to update the organization.'], 409);
  }
}
?>
<div class="space-y-6">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <p class="text-xs font-bold uppercase tracking-[0.18em] text-rose-600">Academic directory</p>
      <h2 class="mt-1 text-3xl font-bold text-slate-900">Organization Management</h2>
      <p class="mt-1 text-sm text-slate-500">Manage colleges, departments, programs, and courses.</p>
    </div>
    <button type="button" id="addCollegeBtn" class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-rose-700">
      <i data-lucide="plus" class="h-4 w-4"></i> Add College
    </button>
  </div>

  <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold uppercase text-slate-500">Colleges</p><p data-count="colleges" class="mt-2 text-3xl font-bold text-slate-900">0</p></div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold uppercase text-slate-500">Departments</p><p data-count="departments" class="mt-2 text-3xl font-bold text-slate-900">0</p></div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold uppercase text-slate-500">Programs</p><p data-count="programs" class="mt-2 text-3xl font-bold text-slate-900">0</p></div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold uppercase text-slate-500">Courses</p><p data-count="courses" class="mt-2 text-3xl font-bold text-slate-900">0</p></div>
  </div>

  <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
      <h3 class="text-lg font-bold text-slate-900">Institutional Structure</h3>
      <span id="organizationStatus" class="text-xs text-slate-400">Loading...</span>
    </div>
    <div id="organizationTree" class="mt-4 space-y-3"></div>
  </section>

  <section class="rounded-2xl border-t-4 border-rose-600 bg-white p-5 shadow-sm">
    <div class="flex items-center gap-2"><i data-lucide="file-text" class="h-5 w-5 text-rose-600"></i><h3 class="text-lg font-bold text-slate-900">Course Prospectus</h3></div>
    <div id="prospectusList" class="mt-4 grid gap-3 md:grid-cols-2"></div>
    <form id="prospectusForm" class="mt-5 grid gap-3 rounded-xl border border-dashed border-rose-300 bg-rose-50 p-4 md:grid-cols-[1fr_auto_auto]">
      <select name="program_id" id="prospectusProgram" required class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"><option value="">Select a program</option></select>
      <input type="file" name="prospectus" accept="application/pdf,.pdf" required class="min-w-0 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
      <button class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">Upload PDF</button>
    </form>
  </section>
</div>

<div id="organizationModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm">
  <form id="organizationForm" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
    <div class="flex items-center justify-between"><h3 id="organizationModalTitle" class="text-xl font-bold text-slate-900">Add College</h3><button type="button" id="closeOrganizationModal" class="text-slate-400" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button></div>
    <input type="hidden" id="organizationAction" name="action"><input type="hidden" id="organizationParent" name="parent_id">
    <div id="courseFields" class="mt-5 hidden grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium text-slate-700">Course code<input name="code" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label><label class="text-sm font-medium text-slate-700">Year level<input name="year_level" type="number" min="1" max="8" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label></div>
    <label class="mt-5 block text-sm font-medium text-slate-700">Name<input id="organizationName" name="name" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"></label>
    <button class="mt-5 w-full rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-700">Save</button>
  </form>
</div>

<div id="organizationToast" class="fixed bottom-5 right-5 z-[60] hidden rounded-xl bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-lg"></div>

<script>
(() => {
  const tree = document.getElementById('organizationTree');
  const modal = document.getElementById('organizationModal');
  const form = document.getElementById('organizationForm');
  const toast = (message, error = false) => { const el = document.getElementById('organizationToast'); el.textContent = message; el.className = `fixed bottom-5 right-5 z-[60] rounded-xl px-4 py-3 text-sm font-medium text-white shadow-lg ${error ? 'bg-red-700' : 'bg-slate-900'}`; setTimeout(() => el.classList.add('hidden'), 3200); };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const openForm = (action, title, parent = '') => { document.getElementById('organizationAction').value = action; document.getElementById('organizationParent').value = parent; document.getElementById('organizationModalTitle').textContent = title; document.getElementById('courseFields').classList.toggle('hidden', action !== 'add_course'); form.reset(); document.getElementById('organizationAction').value = action; document.getElementById('organizationParent').value = parent; modal.classList.remove('hidden'); modal.classList.add('flex'); document.getElementById('organizationName').focus(); };
  const button = (action, title, parent) => `<button type="button" data-add="${action}" data-title="${esc(title)}" data-parent="${parent}" class="text-xs font-semibold text-rose-600 hover:text-rose-800">+ ${esc(title)}</button>`;
  function render(data) {
    Object.entries(data.counts).forEach(([key, value]) => { const el = document.querySelector(`[data-count="${key}"]`); if (el) el.textContent = value; });
    const programs = [];
    data.colleges.forEach(college => college.departments.forEach(department => department.programs.forEach(program => programs.push(program))));
    document.getElementById('prospectusProgram').innerHTML = '<option value="">Select a program</option>' + programs.map(program => `<option value="${program.id}">${esc(program.name)}</option>`).join('');
    document.getElementById('prospectusList').innerHTML = programs.filter(program => program.prospectus).map(program => `<div class="flex items-center justify-between rounded-xl border border-slate-200 p-3"><div><p class="font-semibold text-slate-800">${esc(program.name)}</p><p class="text-xs text-slate-500">${esc(program.prospectus.file_name)}</p></div><a href="${esc(program.prospectus.file_path)}" target="_blank" rel="noopener" class="text-xs font-semibold text-rose-600">View PDF</a></div>`).join('') || '<p class="text-sm italic text-slate-400">No prospectuses uploaded yet.</p>';
    if (!data.colleges.length) { tree.innerHTML = '<div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-500">No colleges yet. Add the first college to build the hierarchy.</div>'; lucide.createIcons(); return; }
    tree.innerHTML = data.colleges.map(college => `<article class="rounded-xl border border-slate-200 bg-slate-50 p-4"><div class="flex flex-wrap items-center justify-between gap-2"><div class="flex items-center gap-2"><i data-lucide="building-2" class="h-4 w-4 text-rose-600"></i><strong>${esc(college.name)}</strong><span class="text-xs text-slate-400">${college.departments.length} dept${college.departments.length === 1 ? '' : 's'}</span></div>${button('add_department','Add department',college.id)}</div><div class="mt-3 space-y-2 border-l-2 border-rose-200 pl-4">${college.departments.length ? college.departments.map(department => `<div class="rounded-lg bg-white p-3"><div class="flex flex-wrap items-center justify-between gap-2"><span class="font-semibold text-slate-700">${esc(department.name)}</span>${button('add_program','Add program',department.id)}</div><div class="mt-2 space-y-1 pl-3">${department.programs.map(program => `<div class="border-l border-slate-200 pl-3"><div class="flex flex-wrap items-center justify-between gap-2 text-sm"><span class="text-slate-700">${esc(program.name)}</span>${button('add_course','Add course',program.id)}</div>${program.courses.length ? `<div class="mt-1 space-y-1 pl-3 text-xs text-slate-500">${program.courses.map(course => `<div><span class="mr-2 rounded bg-rose-100 px-1.5 py-0.5 font-semibold text-rose-700">${esc(course.code)}</span>${esc(course.name)}</div>`).join('')}</div>` : '<p class="mt-1 pl-3 text-xs italic text-slate-400">No courses yet</p>'}</div>`).join('')}</div></div>`).join('') : '<p class="text-sm italic text-slate-400">No departments yet</p>'}</div></article>`).join('');
    lucide.createIcons(); document.getElementById('organizationStatus').textContent = 'Updated just now';
  }
  function load() { fetch('pages/organization.php?action=list', {headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r => r.json()).then(result => { if (!result.success) throw new Error(result.message); render(result); }).catch(error => toast(error.message, true)); }
  document.getElementById('addCollegeBtn').addEventListener('click', () => openForm('add_college', 'Add College'));
  tree.addEventListener('click', event => { const add = event.target.closest('[data-add]'); if (add) openForm(add.dataset.add, add.dataset.title, add.dataset.parent); });
  document.getElementById('closeOrganizationModal').addEventListener('click', () => modal.classList.add('hidden'));
  form.addEventListener('submit', event => { event.preventDefault(); fetch('pages/organization.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:new URLSearchParams(new FormData(form))}).then(r => r.json()).then(result => { if (!result.success) throw new Error(result.message); modal.classList.add('hidden'); toast(result.message); load(); }).catch(error => toast(error.message, true)); });
  document.getElementById('prospectusForm').addEventListener('submit', event => { event.preventDefault(); const data = new FormData(event.target); data.append('action', 'upload_prospectus'); fetch('pages/organization.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:data}).then(r => r.json()).then(result => { if (!result.success) throw new Error(result.message); event.target.reset(); toast('Prospectus uploaded successfully.'); load(); }).catch(error => toast(error.message, true)); });
  load();
})();
</script>