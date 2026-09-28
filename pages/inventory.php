<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=inventory');
    exit;
}

requireLogin();
$csrfToken = $_SESSION['inventory_csrf'] ?? bin2hex(random_bytes(32));
$_SESSION['inventory_csrf'] = $csrfToken;

function inventoryJsonResponse(array $payload, int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($payload);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'create_book') {
  if (!$pdo instanceof PDO) inventoryJsonResponse(['success' => false, 'message' => 'The database is unavailable.'], 503);
  if (!in_array(strtolower((string)($_SESSION['role'] ?? '')), ['admin', 'librarian'], true)) {
    inventoryJsonResponse(['success' => false, 'message' => 'You do not have permission to add books.'], 403);
  }
  if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
    inventoryJsonResponse(['success' => false, 'message' => 'Your session has expired. Reload the page and try again.'], 403);
  }

  $title = trim((string)($_POST['title'] ?? ''));
  $author = trim((string)($_POST['author'] ?? ''));
  $isbn = trim((string)($_POST['isbn'] ?? ''));
  $publisher = trim((string)($_POST['publisher'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $publicationYearInput = trim((string)($_POST['publication_year'] ?? ''));
  $copyrightYearInput = trim((string)($_POST['copyright_year'] ?? ''));
  $copyCount = filter_var($_POST['copy_count'] ?? null, FILTER_VALIDATE_INT);
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

  try {
    $pdo->beginTransaction();
    $bookStmt = $pdo->prepare('INSERT INTO books (isbn, title, author, publisher, publication_year, copyright_year, description) VALUES (:isbn, :title, :author, :publisher, :publication_year, :copyright_year, :description)');
    $bookStmt->execute([
      ':isbn' => $isbn !== '' ? $isbn : null,
      ':title' => $title,
      ':author' => $author,
      ':publisher' => $publisher !== '' ? $publisher : null,
      ':publication_year' => $publicationYear,
      ':copyright_year' => $copyrightYear,
      ':description' => $description !== '' ? $description : null,
    ]);
    $bookId = (int)$pdo->lastInsertId();
    $copyStmt = $pdo->prepare('INSERT INTO book_copies (book_id, copy_number) VALUES (:book_id, :copy_number)');
    for ($copyNumber = 1; $copyNumber <= $copyCount; $copyNumber++) {
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

$totalBookTitles = 0;
$totalBookCopies = 0;
$copyrightYearMetrics = [];
$activeBooks = [];
$archivedBooks = [];
$inventorySchemaAvailable = false;
$escapeInventory = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentYear = (int)date('Y');

if ($pdo instanceof PDO) {
  try {
    $totalBookTitles = (int)$pdo->query('SELECT COUNT(*) FROM books WHERE deleted_at IS NULL')->fetchColumn();
    $totalBookCopies = (int)$pdo->query('SELECT COUNT(*) FROM book_copies c INNER JOIN books b ON b.book_id = c.book_id WHERE b.deleted_at IS NULL')->fetchColumn();
    $copyrightYearMetrics = getCopyrightYearMetrics($pdo);
    $bookQuery = 'SELECT b.book_id, b.isbn, b.title, b.author, b.publisher, b.publication_year, b.copyright_year, COALESCE(c.copy_count, 0) AS copy_count
      FROM books b
      LEFT JOIN (SELECT book_id, COUNT(*) AS copy_count FROM book_copies GROUP BY book_id) c ON c.book_id = b.book_id
      WHERE b.deleted_at IS NULL';
    $activeBooks = $pdo->query($bookQuery . ' ORDER BY b.title')->fetchAll();
    $archiveQuery = str_replace('WHERE b.deleted_at IS NULL', 'WHERE b.deleted_at IS NOT NULL', $bookQuery);
    $archiveQuery .= ' ORDER BY b.deleted_at DESC';
    $archivedBooks = $pdo->query($archiveQuery)->fetchAll();
    $courseReferenceRows = $pdo->query('SELECT bc.book_id, c.code, c.name FROM book_courses bc INNER JOIN courses c ON c.id = bc.course_id ORDER BY c.code, c.name')->fetchAll();
    $courseReferencesByBook = [];
    foreach ($courseReferenceRows as $reference) {
      $courseReferencesByBook[(int)$reference['book_id']][] = ['code' => $reference['code'], 'name' => $reference['name']];
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
<div class="min-h-[calc(100vh-5rem)] p-1 text-slate-900 sm:p-2">
  <div class="mb-5">
    <div>
      <h2 class="text-2xl font-bold text-slate-900">Inventory Management</h2>
      <p class="mt-1 text-sm text-slate-600">Browse and manage all library volumes and resources.</p>
    </div>
  </div>

  <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
    <div class="flex h-[76px] flex-col justify-center rounded-xl border border-slate-200 bg-white px-4 shadow-sm">
      <div class="flex items-center gap-2"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100 text-rose-700"><i data-lucide="book-open" class="h-4 w-4"></i></span><p class="font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-rose-800">Total Titles</p></div>
      <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-slate-950"><?php echo $totalBookTitles; ?></p>
    </div>
    <div class="flex h-[76px] flex-col justify-center rounded-xl border border-slate-200 bg-white px-4 shadow-sm">
      <div class="flex items-center gap-2"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-sky-100 text-sky-700"><i data-lucide="library" class="h-4 w-4"></i></span><p class="font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-sky-800">Total Volumes</p></div>
      <p class="mt-1 text-2xl font-bold leading-none tabular-nums text-slate-950"><?php echo $totalBookCopies; ?></p>
    </div>
    <?php foreach ($copyrightYearMetrics as $index => $metric): ?>
      <?php $style = $copyrightMetricStyles[$index % count($copyrightMetricStyles)]; ?>
      <div class="flex h-[76px] flex-col justify-center rounded-xl border border-slate-200 bg-white px-4 shadow-sm">
        <div class="flex items-center gap-2"><span class="flex h-7 w-7 items-center justify-center rounded-lg <?php echo $style['iconBg']; ?> <?php echo $style['iconColor']; ?>"><i data-lucide="<?php echo $style['icon']; ?>" class="h-4 w-4"></i></span><p class="font-mono text-[10px] font-semibold uppercase tracking-[0.12em] <?php echo $style['labelColor']; ?>">Within <?php echo (int)$metric['years_threshold']; ?> Yrs</p></div>
        <p class="mt-1 text-2xl font-bold leading-none tabular-nums <?php echo $style['valueColor']; ?>"><?php echo (int)$metric['title_count']; ?></p>
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
      View Archive<i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i>
    </button>
  </section>

  <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
    <div>
      <h3 class="text-base font-bold text-slate-900">Books</h3>
      <p class="mt-1 text-xs text-slate-600">Catalog and copyright-year status.</p>
    </div>
    <div class="flex items-center gap-2">
      <button type="button" disabled title="Book list filtering is not implemented yet." class="inline-flex h-9 items-center gap-2 rounded-xl bg-slate-100 px-4 text-sm font-semibold text-slate-400">
        <i data-lucide="list-filter" class="h-4 w-4"></i>Filters
      </button>
      <button type="button" data-inventory-add-open aria-haspopup="dialog" aria-controls="inventoryAddBookModal" class="inline-flex h-9 items-center gap-2 rounded-xl bg-rose-700 px-4 text-sm font-semibold text-white transition-colors hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">
        <i data-lucide="plus" class="h-4 w-4"></i>Add Book
      </button>
    </div>
  </div>

  <section class="mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
      <table class="w-full min-w-[900px] text-left text-sm">
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
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php if (!$inventorySchemaAvailable): ?>
            <tr><td colspan="<?php echo 5 + count($copyrightYearMetrics); ?>" class="px-4 py-12 text-center"><p class="text-sm font-semibold text-rose-700">Inventory database tables are unavailable.</p><p class="mt-1 text-xs text-slate-600">Apply the latest schema.sql to enable book records and copyright ranges.</p></td></tr>
          <?php elseif (!$activeBooks): ?>
            <tr><td colspan="<?php echo 5 + count($copyrightYearMetrics); ?>" class="px-4 py-14 text-center"><i data-lucide="book-x" class="mx-auto h-8 w-8 text-slate-300"></i><p class="mt-3 text-sm font-semibold text-slate-700">No books in the catalog</p><p class="mt-1 text-xs text-slate-600">Add a book to see its copyright-year status here.</p></td></tr>
          <?php else: ?>
            <?php foreach ($activeBooks as $book): ?>
              <?php $bookCopyrightYear = (int)($book['effective_copyright_year'] ?? 0); ?>
              <tr>
                <td class="px-4 py-3"><p class="font-semibold text-slate-900"><?php echo $escapeInventory($book['title']); ?></p><p class="mt-0.5 text-xs text-slate-600">by <?php echo $escapeInventory($book['author']); ?></p><p class="mt-0.5 font-mono text-[10px] text-slate-500">ISBN: <?php echo $escapeInventory($book['isbn'] ?: '—'); ?></p></td>
                <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-800"><?php echo $escapeInventory($book['publication_year'] ?: '—'); ?></td>
                <td class="px-4 py-3">
                  <?php if (!empty($book['course_references'])): ?>
                    <div class="flex flex-wrap gap-1">
                      <?php foreach ($book['course_references'] as $reference): ?>
                        <span class="inline-flex max-w-full items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-medium text-rose-800"><span class="font-semibold"><?php echo $escapeInventory($reference['code']); ?></span><span><?php echo $escapeInventory($reference['name']); ?></span></span>
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
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <dialog id="inventoryAddBookModal" aria-labelledby="inventoryAddBookTitle" class="fixed z-[100] m-auto max-h-[90vh] w-[calc(100%-1.5rem)] max-w-2xl overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
      <div><h3 id="inventoryAddBookTitle" class="text-base font-bold text-slate-900">Add Book</h3><p class="mt-1 text-xs text-slate-600">Enter title details and the number of physical copies.</p></div>
      <button type="button" data-inventory-add-close aria-label="Close add book form" class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-rose-500"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <form data-inventory-add-form class="max-h-[calc(90vh-4.5rem)] space-y-4 overflow-y-auto p-5">
      <input type="hidden" name="action" value="create_book">
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
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2"><i data-lucide="plus" class="h-4 w-4"></i>Add Book</button>
      </div>
    </form>
  </dialog>

  <dialog id="inventoryArchiveModal" aria-labelledby="inventoryArchiveTitle" class="fixed z-[100] m-auto max-h-[90vh] w-[calc(100%-1.5rem)] max-w-7xl overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/50">
    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 sm:px-5">
      <div>
        <h3 id="inventoryArchiveTitle" class="text-base font-bold text-slate-900">Archived Book Records</h3>
        <p class="mt-1 text-xs text-slate-600">Soft-deleted books can be restored anytime.</p>
      </div>
      <button type="button" data-inventory-archive-close aria-label="Close archived books" class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-rose-500">
        <i data-lucide="x" class="h-5 w-5"></i>
      </button>
    </div>
    <div class="max-h-[calc(90vh-4rem)] overflow-auto">
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
          </tr>
        </thead>
        <tbody>
          <?php if (!$archivedBooks): ?>
            <tr><td colspan="<?php echo 5 + count($copyrightYearMetrics); ?>" class="px-4 py-12 text-center"><p class="text-sm font-medium text-slate-700">No archived books</p><p class="mt-1 text-xs text-slate-600">Soft-deleted books will appear here.</p></td></tr>
          <?php else: ?>
            <?php foreach ($archivedBooks as $book): ?>
              <?php $bookCopyrightYear = (int)($book['effective_copyright_year'] ?? 0); ?>
              <tr>
                <td class="px-4 py-3"><p class="font-semibold text-slate-900"><?php echo $escapeInventory($book['title']); ?></p><p class="mt-0.5 text-xs text-slate-600">by <?php echo $escapeInventory($book['author']); ?></p><p class="mt-0.5 font-mono text-[10px] text-slate-500">ISBN: <?php echo $escapeInventory($book['isbn'] ?: '—'); ?></p></td>
                <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-800"><?php echo $escapeInventory($book['publication_year'] ?: '—'); ?></td>
                <td class="px-4 py-3">
                  <?php if (!empty($book['course_references'])): ?>
                    <div class="flex flex-wrap gap-1">
                      <?php foreach ($book['course_references'] as $reference): ?>
                        <span class="inline-flex max-w-full items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-medium text-rose-800"><span class="font-semibold"><?php echo $escapeInventory($reference['code']); ?></span><span><?php echo $escapeInventory($reference['name']); ?></span></span>
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
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </dialog>
</div>
<script>
(() => {
  const addBookButton = document.querySelector('[data-inventory-add-open]');
  const addBookDialog = document.getElementById('inventoryAddBookModal');
  const addBookForm = document.querySelector('[data-inventory-add-form]');
  const addBookCloseButton = document.querySelector('[data-inventory-add-close]');
  const addBookCancelButton = document.querySelector('[data-inventory-add-cancel]');
  const formMessage = document.querySelector('[data-inventory-form-message]');
  const archiveOpenButton = document.querySelector('[data-inventory-archive-open]');
  const archiveCloseButton = document.querySelector('[data-inventory-archive-close]');
  const archiveModal = document.getElementById('inventoryArchiveModal');

  function closeDialog(dialog) {
    if (dialog?.open) dialog.close();
  }

  addBookButton?.addEventListener('click', () => {
    addBookDialog.showModal();
  });
  addBookCloseButton?.addEventListener('click', () => closeDialog(addBookDialog));
  addBookCancelButton?.addEventListener('click', () => closeDialog(addBookDialog));
  archiveOpenButton?.addEventListener('click', () => archiveModal.showModal());
  archiveCloseButton?.addEventListener('click', () => closeDialog(archiveModal));
  archiveModal?.addEventListener('click', (event) => {
    if (event.target === archiveModal) closeDialog(archiveModal);
  });

  addBookForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitButton = addBookForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    formMessage.hidden = true;

    try {
      const response = await fetch('pages/inventory.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(addBookForm),
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to save this book.');
      window.location.reload();
    } catch (error) {
      formMessage.textContent = error.message || 'Unable to save this book. Please try again.';
      formMessage.hidden = false;
      submitButton.disabled = false;
    }
  });
})();
</script>
