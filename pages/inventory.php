<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    header('Location: ../app.php?page=inventory');
    exit;
}

requireLogin();
$csrfToken = $_SESSION['inventory_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['inventory_csrf'] = $csrfToken;
$aiCourseUiConfigured = filter_var(environmentValue('AI_COURSE_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)
  && environmentValue('GEMINI_API_KEY') !== ''
  && environmentValue('GEMINI_EMBEDDING_MODEL') !== ''
  && environmentValue('GEMINI_RELEVANCE_MODEL') !== ''
  && function_exists('curl_init');

function inventoryJsonResponse(array $payload, int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($payload);
  exit;
}

function inventoryBookInput(array $input): array {
  $title = trim(requestString($input, 'title'));
  $author = trim(requestString($input, 'author'));
  $isbn = trim(requestString($input, 'isbn'));
  $publisher = trim(requestString($input, 'publisher'));
  $description = trim(requestString($input, 'description'));
  $publicationYearInput = trim(requestString($input, 'publication_year'));
  $copyrightYearInput = trim(requestString($input, 'copyright_year'));
  $copyCount = filter_var(requestString($input, 'copy_count'), FILTER_VALIDATE_INT);
  $currentYear = (int)date('Y');
  $publicationYear = $publicationYearInput === '' ? null : filter_var($publicationYearInput, FILTER_VALIDATE_INT);
  $copyrightYear = $copyrightYearInput === '' ? null : filter_var($copyrightYearInput, FILTER_VALIDATE_INT);

  if ($title === '' || strlen($title) > 255 || $author === '' || strlen($author) > 180) {
    inventoryJsonResponse(['success' => false, 'message' => 'Enter a title and author within the allowed length.'], 422);
  }
  if (strlen($isbn) > 20 || strlen($publisher) > 180) {
    inventoryJsonResponse(['success' => false, 'message' => 'ISBN or publisher is longer than allowed.'], 422);
  }
  if (($publicationYear !== null && ($publicationYear === false || $publicationYear < 1450 || $publicationYear > $currentYear))
    || ($copyrightYear !== null && ($copyrightYear === false || $copyrightYear < 1450 || $copyrightYear > $currentYear))) {
    inventoryJsonResponse(['success' => false, 'message' => 'Enter valid publication and copyright years.'], 422);
  }
  if ($copyCount === false || $copyCount < 1 || $copyCount > 5000) {
    inventoryJsonResponse(['success' => false, 'message' => 'The number of copies must be between 1 and 5,000.'], 422);
  }

  return [
    'title' => $title,
    'author' => $author,
    'isbn' => $isbn !== '' ? $isbn : null,
    'publisher' => $publisher !== '' ? $publisher : null,
    'description' => $description !== '' ? $description : null,
    'publication_year' => $publicationYear,
    'copyright_year' => $copyrightYear,
    'copy_count' => $copyCount,
  ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'create_book') {
  if (!$pdo instanceof PDO) inventoryJsonResponse(['success' => false, 'message' => 'The database is unavailable.'], 503);
  if (!in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'librarian'], true)) {
    inventoryJsonResponse(['success' => false, 'message' => 'You do not have permission to add books.'], 403);
  }
  if (!hash_equals($csrfToken, requestString($_POST, 'csrf_token'))) {
    inventoryJsonResponse(['success' => false, 'message' => 'Your session has expired. Reload the page and try again.'], 403);
  }

  $bookInput = inventoryBookInput($_POST);

  try {
    $pdo->beginTransaction();
    $bookStmt = $pdo->prepare('INSERT INTO books (isbn, title, author, publisher, publication_year, copyright_year, description) VALUES (:isbn, :title, :author, :publisher, :publication_year, :copyright_year, :description)');
    $bookStmt->execute([
      ':isbn' => $bookInput['isbn'],
      ':title' => $bookInput['title'],
      ':author' => $bookInput['author'],
      ':publisher' => $bookInput['publisher'],
      ':publication_year' => $bookInput['publication_year'],
      ':copyright_year' => $bookInput['copyright_year'],
      ':description' => $bookInput['description'],
    ]);
    $bookId = (int)$pdo->lastInsertId();
    $copyStmt = $pdo->prepare('INSERT INTO book_copies (book_id, copy_number) VALUES (:book_id, :copy_number)');
    for ($copyNumber = 1; $copyNumber <= $bookInput['copy_count']; $copyNumber++) {
      $copyStmt->execute([':book_id' => $bookId, ':copy_number' => $copyNumber]);
    }
    $pdo->commit();
    inventoryJsonResponse(['success' => true, 'message' => 'Book added successfully.']);
  } catch (PDOException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (($exception->errorInfo[1] ?? 0) === 1062) {
      inventoryJsonResponse(['success' => false, 'message' => 'A book with that ISBN already exists.'], 409);
    }
    error_log('Inventory book creation failed: ' . $exception->getMessage());
    inventoryJsonResponse(['success' => false, 'message' => 'Unable to save this book. Please try again.'], 500);
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Inventory book creation failed: ' . $exception->getMessage());
    inventoryJsonResponse(['success' => false, 'message' => 'Unable to save this book. Please try again.'], 500);
  }
}

$inventoryAction = requestString($_POST, 'action');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($inventoryAction, ['update_book', 'delete_book', 'restore_book', 'add_reference', 'edit_reference', 'remove_reference'], true)) {
  if (!$pdo instanceof PDO) inventoryJsonResponse(['success' => false, 'message' => 'The database is unavailable.'], 503);
  if (!in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'librarian'], true)) {
    inventoryJsonResponse(['success' => false, 'message' => 'You do not have permission to manage books.'], 403);
  }
  if (!hash_equals($csrfToken, requestString($_POST, 'csrf_token'))) {
    inventoryJsonResponse(['success' => false, 'message' => 'Your session has expired. Reload the page and try again.'], 403);
  }

  $bookId = filter_var(requestString($_POST, 'book_id'), FILTER_VALIDATE_INT);
  if ($bookId === false || $bookId < 1) inventoryJsonResponse(['success' => false, 'message' => 'Choose a valid book.'], 422);

  try {
    if ($inventoryAction === 'delete_book') {
      $pdo->beginTransaction();
      $stmt = $pdo->prepare('UPDATE books SET deleted_at = CURRENT_TIMESTAMP WHERE book_id = :book_id AND deleted_at IS NULL');
      $stmt->execute([':book_id' => $bookId]);
      if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        inventoryJsonResponse(['success' => false, 'message' => 'Book not found or already archived.'], 404);
      }
      $pdo->commit();
      inventoryJsonResponse(['success' => true, 'message' => 'Book moved to the archive.']);
    }

    if ($inventoryAction === 'restore_book') {
      $stmt = $pdo->prepare('UPDATE books SET deleted_at = NULL WHERE book_id = :book_id AND deleted_at IS NOT NULL');
      $stmt->execute([':book_id' => $bookId]);
      if ($stmt->rowCount() === 0) inventoryJsonResponse(['success' => false, 'message' => 'Archived book not found or already restored.'], 404);
      inventoryJsonResponse(['success' => true, 'message' => 'Book restored to the catalog.']);
    }

    if ($inventoryAction === 'add_reference') {
      $courseId = filter_var(requestString($_POST, 'course_id'), FILTER_VALIDATE_INT);
      if ($courseId === false || $courseId < 1) inventoryJsonResponse(['success' => false, 'message' => 'Choose a valid course.'], 422);
      $stmt = $pdo->prepare('INSERT INTO book_courses (book_id, course_id) SELECT b.book_id, c.id FROM books b INNER JOIN courses c ON c.id = :course_id AND c.status = \'active\' WHERE b.book_id = :book_id AND b.deleted_at IS NULL');
      $stmt->execute([':book_id' => $bookId, ':course_id' => $courseId]);
      if ($stmt->rowCount() === 0) inventoryJsonResponse(['success' => false, 'message' => 'Book or active course not found.'], 404);
      inventoryJsonResponse(['success' => true, 'message' => 'Course reference added.']);
    }

    if (in_array($inventoryAction, ['edit_reference', 'remove_reference'], true)) {
      $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);
      if ($courseId === false || $courseId < 1) inventoryJsonResponse(['success' => false, 'message' => 'Choose a valid course reference.'], 422);
      $referenceStmt = $pdo->prepare('SELECT 1 FROM book_courses bc INNER JOIN books b ON b.book_id = bc.book_id WHERE bc.book_id = :book_id AND bc.course_id = :course_id AND b.deleted_at IS NULL');
      $referenceStmt->execute([':book_id' => $bookId, ':course_id' => $courseId]);
      if (!$referenceStmt->fetchColumn()) inventoryJsonResponse(['success' => false, 'message' => 'This course reference no longer exists.'], 404);

      if ($inventoryAction === 'remove_reference') {
        $deleteReference = $pdo->prepare('DELETE FROM book_courses WHERE book_id = :book_id AND course_id = :course_id');
        $deleteReference->execute([':book_id' => $bookId, ':course_id' => $courseId]);
        inventoryJsonResponse(['success' => true, 'message' => 'Course reference removed.']);
      }

      $newCourseId = filter_var(requestString($_POST, 'new_course_id'), FILTER_VALIDATE_INT);
      if ($newCourseId === false || $newCourseId < 1) inventoryJsonResponse(['success' => false, 'message' => 'Choose a new course.'], 422);
      $activeCourseStmt = $pdo->prepare('SELECT 1 FROM courses WHERE id = :course_id AND status = \'active\'');
      $activeCourseStmt->execute([':course_id' => $newCourseId]);
      if (!$activeCourseStmt->fetchColumn()) inventoryJsonResponse(['success' => false, 'message' => 'The selected course is unavailable.'], 404);
      if ($newCourseId === $courseId) inventoryJsonResponse(['success' => true, 'message' => 'The reference is unchanged.']);
      $updateReference = $pdo->prepare('UPDATE book_courses SET course_id = :new_course_id WHERE book_id = :book_id AND course_id = :course_id');
      $updateReference->execute([':new_course_id' => $newCourseId, ':book_id' => $bookId, ':course_id' => $courseId]);
      inventoryJsonResponse(['success' => true, 'message' => 'Course reference updated.']);
    }

    $bookInput = inventoryBookInput($_POST);

    $pdo->beginTransaction();
    $bookStmt = $pdo->prepare('UPDATE books SET isbn = :isbn, title = :title, author = :author, publisher = :publisher, publication_year = :publication_year, copyright_year = :copyright_year, description = :description WHERE book_id = :book_id AND deleted_at IS NULL');
    $bookStmt->execute([
      ':book_id' => $bookId,
      ':isbn' => $bookInput['isbn'],
      ':title' => $bookInput['title'],
      ':author' => $bookInput['author'],
      ':publisher' => $bookInput['publisher'],
      ':publication_year' => $bookInput['publication_year'],
      ':copyright_year' => $bookInput['copyright_year'],
      ':description' => $bookInput['description'],
    ]);
    $existsStmt = $pdo->prepare('SELECT 1 FROM books WHERE book_id = :book_id AND deleted_at IS NULL');
    $existsStmt->execute([':book_id' => $bookId]);
    if (!$existsStmt->fetchColumn()) {
      $pdo->rollBack();
      inventoryJsonResponse(['success' => false, 'message' => 'Book not found or archived.'], 404);
    }
    $pdo->prepare('DELETE FROM book_copies WHERE book_id = :book_id AND copy_number > :copy_count')->execute([':book_id' => $bookId, ':copy_count' => $bookInput['copy_count']]);
    $copyStmt = $pdo->prepare('INSERT IGNORE INTO book_copies (book_id, copy_number) VALUES (:book_id, :copy_number)');
    for ($copyNumber = 1; $copyNumber <= $bookInput['copy_count']; $copyNumber++) {
      $copyStmt->execute([':book_id' => $bookId, ':copy_number' => $copyNumber]);
    }
    $pdo->commit();
    inventoryJsonResponse(['success' => true, 'message' => 'Book updated successfully.']);
  } catch (PDOException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (($exception->errorInfo[1] ?? 0) === 1062) {
      inventoryJsonResponse(['success' => false, 'message' => 'A book with that ISBN already exists, or this course is already linked.'], 409);
    }
    error_log('Inventory book action failed: ' . $exception->getMessage());
    inventoryJsonResponse(['success' => false, 'message' => 'Unable to complete this book action. Please try again.'], 500);
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Inventory book action failed: ' . $exception->getMessage());
    inventoryJsonResponse(['success' => false, 'message' => 'Unable to complete this book action. Please try again.'], 500);
  }
}

$totalBookTitles = 0;
$totalBookCopies = 0;
$copyrightYearMetrics = [];
$activeBooks = [];
$archivedBooks = [];
$activeCourses = [];
$inventorySchemaAvailable = false;
$escapeInventory = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$shortBookTitle = static function (string $title): string {
  $words = preg_split('/\s+/', trim($title), -1, PREG_SPLIT_NO_EMPTY);
  if ($words === false) {
    return $title;
  }
  $words = array_values(array_filter($words, static fn($word): bool => preg_match('/[\pL\pN]/u', $word) === 1));
  return count($words) > 3 ? implode(' ', array_slice($words, 0, 3)) . '...' : $title;
};
$currentYear = (int)date('Y');

if ($pdo instanceof PDO) {
  try {
    $totalBookTitles = (int)$pdo->query('SELECT COUNT(*) FROM vw_inventory_books WHERE deleted_at IS NULL')->fetchColumn();
    $totalBookCopies = (int)$pdo->query('SELECT COALESCE(SUM(copy_count), 0) FROM vw_inventory_books WHERE deleted_at IS NULL')->fetchColumn();
    $copyrightYearMetrics = getCopyrightYearMetrics($pdo);
    $activeBooks = $pdo->query('SELECT * FROM vw_inventory_books WHERE deleted_at IS NULL ORDER BY title')->fetchAll();
    $archivedBooks = $pdo->query('SELECT * FROM vw_inventory_books WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll();
    $courseReferenceRows = $pdo->query(
      'SELECT bc.book_id, c.id AS course_id, c.code, c.name, c.status, p.name AS program_name, col.name AS college_name
       FROM book_courses bc
       INNER JOIN courses c ON c.id = bc.course_id
       LEFT JOIN programs p ON p.id = c.program_id
       LEFT JOIN colleges col ON col.id = p.college_id
       ORDER BY c.code, c.name'
    )->fetchAll();
    $activeCourses = $pdo->query('SELECT id, code, name FROM courses WHERE status = \'active\' ORDER BY code, name')->fetchAll();
    $courseReferencesByBook = [];
    foreach ($courseReferenceRows as $reference) {
      $courseReferencesByBook[(int)$reference['book_id']][] = [
        'course_id' => (int)$reference['course_id'],
        'code' => $reference['code'],
        'name' => $reference['name'],
        'status' => $reference['status'],
        'program_name' => $reference['program_name'],
        'college_name' => $reference['college_name'],
      ];
    }
    foreach ($activeBooks as $bookIndex => $book) {
      $activeBooks[$bookIndex]['course_references'] = $courseReferencesByBook[(int)$book['book_id']] ?? [];
      $activeBooks[$bookIndex]['effective_copyright_year'] = $book['copyright_year'] ?? $book['publication_year'];
    }
    foreach ($archivedBooks as $bookIndex => $book) {
      $archivedBooks[$bookIndex]['course_references'] = $courseReferencesByBook[(int)$book['book_id']] ?? [];
      $archivedBooks[$bookIndex]['effective_copyright_year'] = $book['copyright_year'] ?? $book['publication_year'];
    }
    $inventorySchemaAvailable = true;
  } catch (PDOException $exception) {
    error_log('Inventory metrics unavailable: ' . $exception->getMessage());
  }
}

$copyrightMetricStyles = [
  ['icon' => 'calendar-check', 'iconBg' => 'bg-emerald-100', 'iconColor' => 'text-emerald-700', 'labelColor' => 'text-emerald-800', 'valueColor' => 'text-emerald-600'],
  ['icon' => 'calendar-clock', 'iconBg' => 'bg-amber-100', 'iconColor' => 'text-amber-700', 'labelColor' => 'text-amber-800', 'valueColor' => 'text-amber-600'],
  ['icon' => 'calendar-range', 'iconBg' => 'bg-rose-100', 'iconColor' => 'text-rose-700', 'labelColor' => 'text-rose-800', 'valueColor' => 'text-rose-700'],
];
?>
<div id="inventoryPage" class="min-h-[calc(100vh-5rem)] p-1 text-slate-900 sm:p-2">
  <div data-inventory-list-view>
  <div class="mb-5">
    <div>
      <h2 class="text-2xl font-bold text-slate-900">Inventory Management</h2>
      <p class="mt-1 text-sm text-slate-600">Browse and manage all library volumes and resources.</p>
    </div>
  </div>

  <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5">
    <div class="flex h-[76px] flex-col justify-center rounded-xl border border-slate-200 bg-white px-4 shadow-sm">
      <div class="flex items-center gap-2"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100 text-rose-700"><i data-lucide="book-open" class="h-4 w-4"></i></span><p class="font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-rose-800">Total Titles</p></div>
      <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-slate-950"><?php echo $totalBookTitles; ?></p>
    </div>
    <div class="flex h-[76px] flex-col justify-center rounded-xl border border-slate-200 bg-white px-4 shadow-sm">
      <div class="flex items-center gap-2"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-sky-100 text-sky-700"><i data-lucide="library" class="h-4 w-4"></i></span><p class="font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-sky-800">Total Volumes</p></div>
      <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-slate-950"><?php echo $totalBookCopies; ?></p>
    </div>
    <?php foreach (array_values($copyrightYearMetrics) as $index => $metric): ?>
      <?php $style = $copyrightMetricStyles[$index % count($copyrightMetricStyles)]; ?>
      <?php $isFinalCopyrightMetric = $index === count($copyrightYearMetrics) - 1; ?>
      <div class="flex h-[76px] <?php echo $isFinalCopyrightMetric ? 'col-span-2 flex-row items-center justify-between xl:col-span-1 xl:flex-col xl:justify-center' : 'flex-col justify-center'; ?> rounded-xl border border-slate-200 bg-white px-4 shadow-sm">
        <div class="flex items-center gap-2"><span class="flex h-7 w-7 items-center justify-center rounded-lg <?php echo $style['iconBg']; ?> <?php echo $style['iconColor']; ?>"><i data-lucide="<?php echo $style['icon']; ?>" class="h-4 w-4"></i></span><p class="font-mono text-[10px] font-semibold uppercase tracking-[0.12em] <?php echo $style['labelColor']; ?>">Within <?php echo (int)$metric['years_threshold']; ?> Yrs</p></div>
        <p class="<?php echo $isFinalCopyrightMetric ? 'mt-0 xl:mt-1' : 'mt-1'; ?> text-2xl font-bold leading-none tabular-nums <?php echo $style['valueColor']; ?>"><?php echo (int)$metric['title_count']; ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <section class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
    <div class="flex items-center gap-3">
      <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-700"><i data-lucide="archive" class="h-4 w-4"></i></div>
      <div class="flex items-center gap-3">
        <div>
          <h3 class="text-sm font-bold text-slate-900">Archived Books</h3>
          <p class="text-xs text-slate-600">Archived books can be restored anytime.</p>
        </div>
      </div>
    </div>
    <button type="button" data-inventory-archive-open aria-haspopup="dialog" aria-controls="inventoryArchiveModal" class="inline-flex h-9 items-center gap-2 rounded-lg bg-[#191b1c] px-4 text-xs font-semibold text-white transition-colors hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2">
      View Archive<span class="inline-flex min-w-5 items-center justify-center rounded-full bg-white/15 px-1.5 py-0.5 text-[10px] font-bold tabular-nums"><?php echo count($archivedBooks); ?></span><i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i>
    </button>
  </section>

  <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
    <div>
      <h3 class="text-base font-bold text-slate-900">Books</h3>
      <p class="mt-1 text-xs text-slate-600">Catalog and copyright-year status.</p>
    </div>
    <div class="flex items-center gap-2">
      <button type="button" data-inventory-add-open aria-haspopup="dialog" aria-controls="inventoryAddBookModal" class="inline-flex h-9 items-center gap-2 rounded-xl bg-rose-700 px-4 text-sm font-semibold text-white transition-colors hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">
        <i data-lucide="plus" class="h-4 w-4"></i>Add Book
      </button>
    </div>
  </div>

  <section class="mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rose-500" role="region" aria-label="Book inventory table. Scroll horizontally to view all columns." tabindex="0">
      <table class="w-full min-w-[1200px] text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 font-mono text-[10px] uppercase tracking-[0.1em] text-slate-600">
          <tr>
            <th scope="col" class="px-4 py-3">Book</th>
            <th scope="col" class="whitespace-nowrap px-4 py-3">Pub. Year</th>
            <th scope="col" class="px-4 py-3">References</th>
            <th scope="col" class="px-4 py-3">Publisher</th>
            <th scope="col" class="whitespace-nowrap px-4 py-3 text-center">Copies</th>
            <?php foreach ($copyrightYearMetrics as $metric): ?>
              <th scope="col" class="whitespace-nowrap px-4 py-3 text-center"><?php echo (int)$metric['years_threshold']; ?>-Yr</th>
            <?php endforeach; ?>
            <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (!$inventorySchemaAvailable): ?>
            <tr><td colspan="<?php echo 6 + count($copyrightYearMetrics); ?>" class="px-4 py-12 text-center"><p class="text-sm font-semibold text-rose-700">Inventory database tables are unavailable.</p><p class="mt-1 text-xs text-slate-600">Apply the latest schema.sql to enable book records and copyright ranges.</p></td></tr>
          <?php elseif (!$activeBooks): ?>
            <tr><td colspan="<?php echo 6 + count($copyrightYearMetrics); ?>" class="px-4 py-14 text-center"><i data-lucide="book-x" class="mx-auto h-8 w-8 text-slate-300"></i><p class="mt-3 text-sm font-semibold text-slate-700">No books in the catalog</p><p class="mt-1 text-xs text-slate-600">Add a book to see its copyright-year status here.</p></td></tr>
          <?php else: ?>
            <?php foreach ($activeBooks as $book): ?>
              <?php $bookCopyrightYear = (int)($book['effective_copyright_year'] ?? 0); ?>
              <tr>
                <td class="px-4 py-3"><p title="<?php echo $escapeInventory($book['title']); ?>" class="font-semibold text-slate-900"><?php echo $escapeInventory($shortBookTitle($book['title'])); ?></p><p class="mt-0.5 text-xs text-slate-600">by <?php echo $escapeInventory($book['author']); ?></p><p class="mt-0.5 font-mono text-[10px] text-slate-500">ISBN: <?php echo $escapeInventory($book['isbn'] ?: '—'); ?></p></td>
                <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-800"><?php echo $escapeInventory($book['publication_year'] ?: '—'); ?></td>
                <td class="px-4 py-3">
                  <?php if (!empty($book['course_references'])): ?>
                    <div class="flex flex-wrap items-center gap-1">
                      <?php foreach ($book['course_references'] as $reference): ?>
                        <span class="inline-flex max-w-full items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-800"><span class="flex-shrink-0 font-semibold"><?php echo $escapeInventory($reference['code']); ?></span><span class="min-w-0"><?php echo $escapeInventory($reference['name']); ?></span></span>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-xs text-slate-500">No courses linked yet</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-slate-700"><?php echo $escapeInventory($book['publisher'] ?: '—'); ?></td>
                <td class="px-4 py-3 text-center font-semibold tabular-nums text-slate-800"><?php echo (int)$book['copy_count']; ?></td>
                <?php foreach ($copyrightYearMetrics as $metric): ?>
                  <?php $withinRange = $bookCopyrightYear > 0 && $bookCopyrightYear >= $currentYear - (int)$metric['years_threshold'] && $bookCopyrightYear <= $currentYear; ?>
                  <td class="px-4 py-3 text-center"><span class="inline-flex h-6 w-6 items-center justify-center rounded-full <?php echo $withinRange ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'; ?>" aria-label="<?php echo $withinRange ? 'Within' : 'Outside'; ?> <?php echo (int)$metric['years_threshold']; ?> year range"><i data-lucide="<?php echo $withinRange ? 'check' : 'x'; ?>" class="h-3.5 w-3.5"></i></span></td>
                <?php endforeach; ?>
                <td class="px-4 py-3">
                  <div class="flex justify-end">
                    <button type="button" data-book-actions-open data-book-id="<?php echo (int)$book['book_id']; ?>" aria-controls="inventoryBookActionsMenu" aria-expanded="false" aria-label="More actions for <?php echo $escapeInventory($book['title']); ?>" title="More actions" class="flex h-8 w-8 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-400"><i data-lucide="ellipsis" class="h-4 w-4"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  </div>

  <section class="hidden space-y-6" data-inventory-book-detail aria-live="polite" aria-label="Book information"></section>

  <div id="inventoryBookActionsMenu" popover="auto" aria-label="Book actions" class="fixed inset-auto z-[105] m-0 w-[calc(100vw-1rem)] max-w-sm overflow-hidden rounded-xl border border-slate-200 bg-white p-0 shadow-2xl">
    <div class="border-b border-slate-200 px-4 py-3">
      <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Book actions</h3>
      <p data-book-actions-title class="mt-1 max-w-[16rem] truncate text-sm font-semibold text-slate-900"></p>
    </div>
    <div class="grid grid-cols-2 gap-2 p-4">
      <button type="button" data-book-action="view" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400"><i data-lucide="eye" class="h-4 w-4"></i>View info</button>
      <button type="button" data-book-action="edit" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-400"><i data-lucide="pencil" class="h-4 w-4"></i>Edit book</button>
      <button type="button" data-book-action="reference" <?php echo $activeCourses ? '' : 'disabled title="No active courses available"'; ?> class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-medium text-rose-800 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400 disabled:cursor-not-allowed disabled:opacity-40"><i data-lucide="link-2" class="h-4 w-4"></i>Add reference</button>
      <button type="button" data-book-action="manage-references" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-medium text-amber-800 hover:bg-amber-50 focus:outline-none focus:ring-2 focus:ring-amber-400"><i data-lucide="list-checks" class="h-4 w-4"></i>Manage refs</button>
      <button type="button" data-book-action="ai-course" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-medium text-indigo-800 hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-indigo-400"><i data-lucide="sparkles" class="h-4 w-4"></i>AI course</button>
      <button type="button" data-book-action="delete" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-medium text-rose-800 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-400"><i data-lucide="trash-2" class="h-4 w-4"></i>Archive</button>
    </div>
  </div>

  <dialog id="inventoryAddBookModal" aria-labelledby="inventoryAddBookTitle" class="fixed z-[100] m-auto max-h-[90vh] w-[calc(100%-1.5rem)] max-w-2xl overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
      <div><h3 id="inventoryAddBookTitle" data-book-form-title class="text-base font-bold text-slate-900">Add Book</h3><p class="mt-1 text-xs text-slate-600">Enter title details and the number of physical copies.</p></div>
      <button type="button" data-inventory-add-close aria-label="Close add book form" class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-rose-500"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <form data-inventory-add-form class="max-h-[calc(90vh-4.5rem)] space-y-4 overflow-y-auto p-5">
      <input type="hidden" name="action" value="create_book">
      <input type="hidden" name="book_id" value="">
      <input type="hidden" name="csrf_token" value="<?php echo $escapeInventory($csrfToken); ?>">
      <p data-inventory-form-message role="alert" hidden class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800"></p>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label class="text-sm font-medium text-slate-700 sm:col-span-2">Title <span class="text-rose-700">*</span><input name="title" required maxlength="255" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"></label>
        <label class="text-sm font-medium text-slate-700">Author <span class="text-rose-700">*</span><input name="author" required maxlength="180" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"></label>
        <label class="text-sm font-medium text-slate-700">ISBN<input name="isbn" maxlength="20" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"></label>
        <label class="text-sm font-medium text-slate-700">Publisher<input name="publisher" maxlength="180" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"></label>
        <label class="text-sm font-medium text-slate-700">Publication Year<input name="publication_year" type="number" min="1450" max="<?php echo $currentYear; ?>" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"></label>
        <label class="text-sm font-medium text-slate-700">Copyright Year<input name="copyright_year" type="number" min="1450" max="<?php echo $currentYear; ?>" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"></label>
        <label class="text-sm font-medium text-slate-700">Number of Copies<input name="copy_count" type="number" min="1" max="5000" value="1" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"></label>
        <label class="text-sm font-medium text-slate-700 sm:col-span-2">Description<textarea name="description" rows="3" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"></textarea></label>
      </div>

      <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
        <button type="button" data-inventory-add-cancel class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
        <button type="submit" data-book-form-submit class="inline-flex items-center gap-2 rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2"><i data-lucide="save" class="h-4 w-4"></i><span>Save Book</span></button>
      </div>
    </form>
  </dialog>

  <dialog id="inventoryAiCourseModal" aria-labelledby="inventoryAiCourseTitle" class="fixed z-[100] m-auto max-h-[90vh] w-[calc(100%-1.5rem)] max-w-xl overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
      <div><h3 id="inventoryAiCourseTitle" class="text-base font-bold text-slate-900">Suggested course references</h3><p class="mt-1 text-xs text-slate-600">Review likely matches before linking.</p></div>
      <button type="button" data-ai-course-close aria-label="Close AI course suggestion" class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-rose-500"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <div class="max-h-[calc(90vh-4.5rem)] space-y-5 overflow-y-auto p-5">
      <section class="border-b border-slate-200 pb-4">
        <p data-ai-course-book-title class="text-base font-semibold text-slate-900"></p>
        <p data-ai-course-book-author class="mt-1 text-xs text-slate-600"></p>
        <p data-ai-course-book-description class="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-700"></p>
        <p data-ai-course-description-warning hidden class="mt-3 items-start gap-2 text-xs text-amber-800"><i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 flex-shrink-0"></i><span>No description is available, so suggestions will rely on the title.</span></p>
      </section>
      <p class="text-xs leading-5 text-slate-600">Suggestions are for librarian review only and are not official classifications.</p>
      <p data-ai-course-setup hidden role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">AI suggestions need both an embedding model and a relevance-review model. Ask an administrator to complete setup.</p>
      <p data-ai-course-status hidden role="status" aria-live="polite" class="text-sm text-slate-600"></p>
      <p data-ai-course-error hidden role="alert" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"></p>
      <div data-ai-course-results hidden class="space-y-2"></div>
      <p data-ai-course-empty hidden class="rounded-lg border border-slate-200 px-4 py-5 text-center text-sm text-slate-600">No suggestions matched this book closely enough to review.</p>
      <div>
        <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Current course references</h4>
        <p data-ai-course-references class="mt-2 text-sm text-slate-700">No courses linked yet</p>
      </div>
      <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
        <button type="button" data-ai-course-close class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Close</button>
        <button type="button" data-ai-course-generate class="inline-flex items-center gap-2 rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"><i data-lucide="sparkles" class="h-4 w-4"></i><span>Review suggested courses</span></button>
      </div>
    </div>
  </dialog>

  <dialog id="inventoryAddReferenceModal" aria-labelledby="inventoryAddReferenceTitle" class="fixed z-[100] m-auto max-h-[90vh] w-[calc(100%-1.5rem)] max-w-lg overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
      <div><h3 id="inventoryAddReferenceTitle" class="text-base font-bold text-slate-900">Add Course Reference</h3><p class="mt-1 text-xs text-slate-600">Link this book to an active course.</p></div>
      <button type="button" data-reference-close aria-label="Close course reference form" class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-rose-500"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <form data-reference-form class="space-y-4 p-5">
      <input type="hidden" name="action" value="add_reference">
      <input type="hidden" name="book_id" value="">
      <input type="hidden" name="csrf_token" value="<?php echo $escapeInventory($csrfToken); ?>">
      <p data-reference-message role="alert" hidden class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800"></p>
      <label class="block text-sm font-medium text-slate-700">Course<select name="course_id" required class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"><option value="">Choose a course</option><?php foreach ($activeCourses as $course): ?><option value="<?php echo (int)$course['id']; ?>"><?php echo $escapeInventory($course['code'] . ' - ' . $course['name']); ?></option><?php endforeach; ?></select></label>
      <div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" data-reference-cancel class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-black hover:bg-slate-50">Cancel</button><button type="submit" class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800">Add Reference</button></div>
    </form>
  </dialog>

  <dialog id="inventoryManageReferencesModal" aria-labelledby="inventoryManageReferencesTitle" class="fixed z-[100] m-auto max-h-[90vh] w-[calc(100%-1.5rem)] max-w-xl overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
      <div><h3 id="inventoryManageReferencesTitle" class="text-base font-bold text-slate-900">Course References</h3><p data-managed-book-title class="mt-1 text-xs text-slate-600"></p></div>
      <button type="button" data-manage-references-close aria-label="Close course references" class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-rose-500"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <div data-reference-list class="max-h-[65vh] space-y-2 overflow-y-auto p-5"></div>
  </dialog>

  <dialog id="inventoryReferenceEditModal" aria-labelledby="inventoryReferenceEditTitle" class="fixed z-[110] m-auto w-[calc(100%-1.5rem)] max-w-md overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/60">
    <div class="p-5">
      <h3 id="inventoryReferenceEditTitle" class="text-base font-bold text-slate-900">Edit Course Reference</h3>
      <p data-reference-edit-copy class="mt-2 text-sm text-slate-600"></p>
      <form data-reference-edit-form class="mt-4">
        <input type="hidden" name="action" value="edit_reference">
        <input type="hidden" name="book_id" value="">
        <input type="hidden" name="course_id" value="">
        <input type="hidden" name="csrf_token" value="<?php echo $escapeInventory($csrfToken); ?>">
        <label class="block text-sm font-medium text-slate-700">Replace with<select name="new_course_id" required class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-100"><option value="">Choose a different course</option><?php foreach ($activeCourses as $course): ?><option value="<?php echo (int)$course['id']; ?>"><?php echo $escapeInventory($course['code'] . ' - ' . $course['name']); ?></option><?php endforeach; ?></select></label>
        <p data-reference-edit-error role="alert" hidden class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800"></p>
        <div class="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button type="button" data-reference-edit-cancel class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">Cancel</button>
          <button type="submit" data-reference-edit-submit class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800">Confirm Edit</button>
        </div>
      </form>
    </div>
  </dialog>

  <dialog id="inventoryReferenceRemoveModal" aria-labelledby="inventoryReferenceRemoveTitle" class="fixed z-[110] m-auto w-[calc(100%-1.5rem)] max-w-md overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/60">
    <div class="p-5">
      <h3 id="inventoryReferenceRemoveTitle" class="text-base font-bold text-slate-900">Remove Course Reference</h3>
      <p data-reference-remove-copy class="mt-2 text-sm text-slate-600"></p>
      <form data-reference-remove-form class="mt-4">
        <input type="hidden" name="action" value="remove_reference">
        <input type="hidden" name="book_id" value="">
        <input type="hidden" name="course_id" value="">
        <input type="hidden" name="csrf_token" value="<?php echo $escapeInventory($csrfToken); ?>">
        <p data-reference-remove-error role="alert" hidden class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800"></p>
        <div class="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button type="button" data-reference-remove-cancel class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">Cancel</button>
          <button type="submit" data-reference-remove-submit class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800">Remove Reference</button>
        </div>
      </form>
    </div>
  </dialog>

  <dialog id="inventoryDeleteBookModal" aria-labelledby="inventoryDeleteBookTitle" class="fixed z-[110] m-auto w-[calc(100%-1.5rem)] max-w-md overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/60">
    <div class="p-5">
      <h3 id="inventoryDeleteBookTitle" class="text-base font-bold text-slate-900">Move Book to Archive</h3>
      <p data-delete-book-copy class="mt-2 text-sm text-slate-600"></p>
      <form data-delete-book-form class="mt-4">
        <input type="hidden" name="action" value="delete_book">
        <input type="hidden" name="book_id" value="">
        <input type="hidden" name="csrf_token" value="<?php echo $escapeInventory($csrfToken); ?>">
        <p data-delete-book-error role="alert" hidden class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800"></p>
        <div class="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-4">
          <button type="button" data-delete-book-cancel class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">Cancel</button>
          <button type="submit" data-delete-book-submit class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800">Move to Archive</button>
        </div>
      </form>
    </div>
  </dialog>

  <dialog id="inventoryArchiveModal" aria-labelledby="inventoryArchiveTitle" class="fixed z-[100] m-auto max-h-[90vh] w-[calc(100%-1.5rem)] max-w-7xl overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
    <div class="grid grid-cols-[minmax(0,1fr)_36px] items-center gap-x-3 gap-y-3 border-b border-slate-200 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(220px,340px)_36px] sm:gap-x-5 sm:px-5">
      <div class="min-w-0">
        <h3 id="inventoryArchiveTitle" class="text-base font-bold text-slate-900">Archived Book Records</h3>
        <p class="mt-1 text-xs text-slate-600">Soft-deleted books can be restored anytime.</p>
      </div>
      <div class="relative col-span-full min-w-0 sm:col-span-1 sm:col-start-2 sm:row-start-1">
        <label class="sr-only" for="inventoryArchiveSearch">Search archived books</label>
        <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden="true"></i>
        <input id="inventoryArchiveSearch" type="search" data-inventory-archive-search placeholder="Search title, author, or ISBN" class="h-10 w-full min-w-0 rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-500 focus:border-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-100">
      </div>
      <button type="button" data-inventory-archive-close aria-label="Close archived books" class="col-start-2 row-start-1 flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-rose-500 sm:col-start-3">
        <i data-lucide="x" class="h-5 w-5"></i>
      </button>
    </div>
    <div class="max-h-[calc(90vh-4rem)] overflow-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rose-500" role="region" aria-label="Archived books table. Scroll to view all columns." tabindex="0">
      <table class="w-full min-w-[900px] text-left text-sm">
        <thead class="sticky top-0 border-b border-slate-200 bg-slate-50 font-mono text-[10px] uppercase tracking-[0.1em] text-slate-600">
          <tr>
            <th scope="col" class="px-4 py-3">Book</th>
            <th scope="col" class="whitespace-nowrap px-4 py-3">Pub. Year</th>
            <th scope="col" class="px-4 py-3">References</th>
            <th scope="col" class="px-4 py-3">Publisher</th>
            <th scope="col" class="whitespace-nowrap px-4 py-3 text-center">Copies</th>
            <?php foreach ($copyrightYearMetrics as $metric): ?>
              <th scope="col" class="whitespace-nowrap px-4 py-3 text-center"><?php echo (int)$metric['years_threshold']; ?>-Yr</th>
            <?php endforeach; ?>
            <th scope="col" class="whitespace-nowrap px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody id="inventoryArchiveTableBody">
          <?php if (!$archivedBooks): ?>
            <tr><td colspan="<?php echo 6 + count($copyrightYearMetrics); ?>" class="px-4 py-12 text-center"><p class="text-sm font-medium text-slate-700">No archived books</p><p class="mt-1 text-xs text-slate-600">Soft-deleted books will appear here.</p></td></tr>
          <?php else: ?>
            <?php foreach ($archivedBooks as $book): ?>
              <?php $bookCopyrightYear = (int)($book['effective_copyright_year'] ?? 0); ?>
              <tr data-inventory-archive-row>
                <td class="px-4 py-3"><p title="<?php echo $escapeInventory($book['title']); ?>" class="font-semibold text-slate-900"><?php echo $escapeInventory($shortBookTitle($book['title'])); ?></p><p class="mt-0.5 text-xs text-slate-600">by <?php echo $escapeInventory($book['author']); ?></p><p class="mt-0.5 font-mono text-[10px] text-slate-500">ISBN: <?php echo $escapeInventory($book['isbn'] ?: '—'); ?></p></td>
                <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-800"><?php echo $escapeInventory($book['publication_year'] ?: '—'); ?></td>
                <td class="px-4 py-3">
                  <?php if (!empty($book['course_references'])): ?>
                    <div class="flex flex-wrap items-center gap-1">
                      <?php foreach ($book['course_references'] as $reference): ?>
                        <span class="inline-flex max-w-full items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-800"><span class="flex-shrink-0 font-semibold"><?php echo $escapeInventory($reference['code']); ?></span><span class="min-w-0"><?php echo $escapeInventory($reference['name']); ?></span></span>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-xs text-slate-500">No courses linked yet</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-slate-700"><?php echo $escapeInventory($book['publisher'] ?: '—'); ?></td>
                <td class="px-4 py-3 text-center font-semibold tabular-nums text-slate-800"><?php echo (int)$book['copy_count']; ?></td>
                <?php foreach ($copyrightYearMetrics as $metric): ?>
                  <?php $withinRange = $bookCopyrightYear > 0 && $bookCopyrightYear >= $currentYear - (int)$metric['years_threshold'] && $bookCopyrightYear <= $currentYear; ?>
                  <td class="px-4 py-3 text-center"><span class="inline-flex h-6 w-6 items-center justify-center rounded-full <?php echo $withinRange ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'; ?>" aria-label="<?php echo $withinRange ? 'Within' : 'Outside'; ?> <?php echo (int)$metric['years_threshold']; ?> year range"><i data-lucide="<?php echo $withinRange ? 'check' : 'x'; ?>" class="h-3.5 w-3.5"></i></span></td>
                <?php endforeach; ?>
                <td class="px-4 py-3 text-right"><button type="button" data-book-action="restore" data-book-id="<?php echo (int)$book['book_id']; ?>" class="inline-flex h-8 items-center gap-2 rounded-md px-3 text-xs font-semibold text-emerald-800 hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-500" aria-label="Restore book" title="Restore book"><i data-lucide="rotate-ccw" class="h-4 w-4"></i><span>Restore</span></button></td>
              </tr>
            <?php endforeach; ?>
            <tr data-inventory-archive-no-matches hidden><td colspan="<?php echo 6 + count($copyrightYearMetrics); ?>" class="px-4 py-10 text-center text-sm text-slate-500" role="status">No archived books match your search.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </dialog>
</div>
<script type="application/json" id="inventoryPageData"><?php echo json_encode(['books' => array_column($activeBooks, null, 'book_id'), 'aiCourseConfigured' => $aiCourseUiConfigured], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?></script>
<script src="assets/js/inventory.js?v=<?php echo (int) filemtime(__DIR__ . '/../assets/js/inventory.js'); ?>"></script>