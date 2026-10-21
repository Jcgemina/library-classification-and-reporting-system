<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=dashboard');
    exit;
}

requireLogin();
$totalBookTitles = 0;
$copyrightYearMetrics = [];
$totalActivePrograms = 0;
$verifiedPrograms = 0;
$pendingPrograms = 0;
$progressScopeCount = 0;
$currentQuarterNumber = (int) ceil((int) date('n') / 3);
$currentYear = (int) date('Y');
$quarterLabels = ['Q1', 'Q2', 'Q3', 'Q4'];
$quarterMetrics = array_fill(0, 4, ['completed' => 0, 'percent' => 0]);
$dashboardDataError = null;

$progressScopes = [];
$dashboardDataAvailable = false;
try {
  if (!$pdo instanceof PDO) {
    throw new PDOException('The database connection is unavailable.');
  }

  $totalBookTitles = (int)$pdo->query('SELECT COUNT(*) FROM books WHERE deleted_at IS NULL')->fetchColumn();
  $copyrightYearMetrics = getCopyrightYearMetrics($pdo);
  $totalActivePrograms = (int)$pdo->query(
    'SELECT COUNT(*)
     FROM programs p
     INNER JOIN colleges col ON col.id = p.college_id
     WHERE p.status = \'active\' AND col.status = \'active\''
  )->fetchColumn();

  $progressStatement = $pdo->prepare(
    'SELECT p.id AS program_id, m.id AS major_id, m.name AS major_name,
            COALESCE(pp.target_quarter, \'Q1\') AS target_quarter,
            COALESCE(pp.q1_actual, 0) AS q1_actual,
            COALESCE(pp.q2_actual, 0) AS q2_actual,
            COALESCE(pp.q3_actual, 0) AS q3_actual,
            COALESCE(pp.q4_actual, 0) AS q4_actual
     FROM programs p
     INNER JOIN colleges col ON col.id = p.college_id
     LEFT JOIN majors m
       ON m.program_id = p.id AND m.status = \'active\'
     LEFT JOIN program_progress pp
       ON pp.program_id = p.id
      AND pp.progress_year = :progress_year
      AND pp.major_scope_id = COALESCE(m.id, 0)
     WHERE p.status = \'active\'
       AND col.status = \'active\'
       AND (
         m.id IS NOT NULL
         OR NOT EXISTS (
           SELECT 1 FROM majors active_major
           WHERE active_major.program_id = p.id AND active_major.status = \'active\'
         )
       )
     ORDER BY p.name, m.name'
  );
  $progressStatement->execute([':progress_year' => $currentYear]);
  $progressScopes = $progressStatement->fetchAll(PDO::FETCH_ASSOC);
  $dashboardDataAvailable = true;
} catch (PDOException $exception) {
  error_log('Dashboard data unavailable: ' . $exception->getMessage());
  $dashboardDataError = 'Dashboard data could not be loaded. Check the database connection and apply the latest schema.sql.';
}

$verifiedProgramIds = [];
$upcomingDeadlines = [];
$overdueDeadlineCount = 0;
if ($dashboardDataAvailable) {
  $quarterActualCounts = array_fill(0, 4, 0);
  $progressScopeCount = count($progressScopes);
  foreach ($progressScopes as $scope) {
    $hasAnyQuarterVerified = false;
    foreach ($quarterLabels as $index => $quarter) {
      $isVerified = (bool)$scope[strtolower($quarter) . '_actual'];
      if ($isVerified) {
        $quarterActualCounts[$index]++;
        $hasAnyQuarterVerified = true;
      }
    }
    if ($hasAnyQuarterVerified) {
      $verifiedProgramIds[(int)$scope['program_id']] = true;
    }

    $targetQuarterNumber = (int)substr((string)$scope['target_quarter'], 1);
    $targetActual = (bool)$scope[strtolower((string)$scope['target_quarter']) . '_actual'];
    if (!$targetActual && $targetQuarterNumber < $currentQuarterNumber) {
      $overdueDeadlineCount++;
    } elseif (!$targetActual && $targetQuarterNumber >= $currentQuarterNumber) {
      $upcomingDeadlines[$targetQuarterNumber] = ($upcomingDeadlines[$targetQuarterNumber] ?? 0) + 1;
    }
  }

  foreach ($quarterActualCounts as $index => $completedCount) {
    $quarterMetrics[$index] = [
      'completed' => $completedCount,
      'percent' => $progressScopeCount > 0 ? (int)round($completedCount / $progressScopeCount * 100) : 0,
    ];
  }
  ksort($upcomingDeadlines);
  $verifiedPrograms = count($verifiedProgramIds);
  $pendingPrograms = max(0, $totalActivePrograms - $verifiedPrograms);
}

$currentQuarterStartMonth = (($currentQuarterNumber - 1) * 3) + 1;
$currentQuarterStart = new DateTimeImmutable(sprintf('%d-%02d-01', $currentYear, $currentQuarterStartMonth));
$currentQuarterEnd = $currentQuarterStart->modify('+3 months')->modify('-1 day');
$currentQuarterMetric = $quarterMetrics[$currentQuarterNumber - 1];
$escapeDashboardValue = static fn ($value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
$copyrightMetricStyles = [
  ['icon' => 'calendar-check', 'iconBorder' => 'border-emerald-200', 'iconBg' => 'bg-emerald-100', 'iconColor' => 'text-emerald-600', 'labelColor' => 'text-emerald-800', 'valueColor' => 'text-emerald-600'],
  ['icon' => 'calendar-clock', 'iconBorder' => 'border-amber-200', 'iconBg' => 'bg-amber-100', 'iconColor' => 'text-amber-600', 'labelColor' => 'text-amber-800', 'valueColor' => 'text-amber-600'],
  ['icon' => 'calendar-range', 'iconBorder' => 'border-rose-200', 'iconBg' => 'bg-rose-100', 'iconColor' => 'text-rose-600', 'labelColor' => 'text-rose-800', 'valueColor' => 'text-rose-700'],
];
?>

<div class="min-h-[calc(100vh-5rem)] p-1 text-slate-900 sm:p-2">
  <div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-900">Dashboard</h2>
    <p class="mt-1 text-sm text-slate-500">Overview of library books, copyright status, and quarterly progress.</p>
  </div>

  <?php if ($dashboardDataError !== null): ?>
    <div role="alert" class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"><?php echo $escapeDashboardValue($dashboardDataError); ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
    <a href="app.php?page=inventory" data-page="inventory" class="flex h-36 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)] transition hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rose-300">
      <div class="flex items-center gap-3"><div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-rose-100 text-rose-600"><i data-lucide="book-open" class="h-5 w-5"></i></div><p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] leading-tight text-rose-800">Total Titles</p></div>
      <p class="text-3xl font-bold leading-none text-slate-950"><?php echo $dashboardDataAvailable ? $totalBookTitles : '&mdash;'; ?></p>
    </a>
    <?php foreach ($copyrightYearMetrics as $index => $metric): ?>
      <?php $style = $copyrightMetricStyles[$index % count($copyrightMetricStyles)]; ?>
      <a href="app.php?page=inventory" data-page="inventory" class="flex h-36 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)] transition hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rose-300">
        <div class="flex items-center gap-3"><div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg border <?php echo $style['iconBorder']; ?> <?php echo $style['iconBg']; ?> <?php echo $style['iconColor']; ?>"><i data-lucide="<?php echo $style['icon']; ?>" class="h-5 w-5"></i></div><p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] leading-tight <?php echo $style['labelColor']; ?>">Within <?php echo (int)$metric['years_threshold']; ?> Yrs</p></div>
        <p class="text-3xl font-bold leading-none <?php echo $style['valueColor']; ?>"><?php echo (int)$metric['title_count']; ?></p>
      </a>
    <?php endforeach; ?>
    <?php if (!$dashboardDataAvailable): ?>
      <div class="flex h-36 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
        <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Collection metrics</p>
        <p class="text-3xl font-bold leading-none text-slate-400">&mdash;</p>
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-[1.15fr_1.15fr_0.72fr]">
    <section class="min-h-[370px] rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]" aria-labelledby="quarterlyProgressTitle">
      <div class="flex items-center justify-between">
        <h2 id="quarterlyProgressTitle" class="text-base font-bold">Quarterly Progress</h2>
        <a href="app.php?page=report" data-page="report" class="text-xs font-semibold text-rose-600 hover:text-rose-700">View report</a>
      </div>
      <p class="mt-1 text-xs text-slate-500">Verified scopes by quarter · <?php echo $currentYear; ?></p>
      <?php if (!$dashboardDataAvailable): ?>
        <p class="mt-10 text-center text-sm text-slate-500">Progress is unavailable.</p>
      <?php elseif ($progressScopeCount === 0): ?>
        <p class="mt-10 text-center text-sm text-slate-500">Add an active program to begin tracking quarterly progress.</p>
      <?php else: ?>
        <div class="mt-7 space-y-5">
          <?php foreach ($quarterMetrics as $index => $metric): ?>
            <div>
              <div class="mb-1.5 flex items-center justify-between text-sm">
                <span class="font-semibold text-slate-700"><?php echo $quarterLabels[$index]; ?></span>
                <span class="text-slate-500"><?php echo $metric['completed']; ?> / <?php echo $progressScopeCount; ?> scopes</span>
              </div>
              <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="<?php echo $quarterLabels[$index]; ?> verification progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $metric['percent']; ?>">
                <div class="h-full rounded-full bg-rose-500 transition-all" style="width: <?php echo $metric['percent']; ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="mt-6 text-xs text-slate-500">A scope is a program or one of its active majors.</p>
      <?php endif; ?>
    </section>

    <section class="min-h-[370px] rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]" aria-labelledby="upcomingDeadlinesTitle">
      <div class="flex items-center justify-between">
        <h2 id="upcomingDeadlinesTitle" class="text-base font-bold">Upcoming Deadlines</h2>
        <a href="app.php?page=report" data-page="report" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Update targets</a>
      </div>
      <p class="mt-1 text-xs text-slate-500">Incomplete scopes by target quarter</p>
      <?php if (!$dashboardDataAvailable): ?>
        <p class="mt-10 text-center text-sm text-slate-500">Targets are unavailable.</p>
      <?php elseif ($progressScopeCount === 0): ?>
        <p class="mt-10 text-center text-sm text-slate-500">No active program scopes to track.</p>
      <?php else: ?>
        <div class="mt-5 space-y-3">
          <?php if ($overdueDeadlineCount > 0): ?>
            <div class="flex items-center justify-between rounded-lg border border-rose-100 bg-rose-50 px-3 py-2.5">
              <div><p class="text-sm font-semibold text-rose-800">Overdue targets</p><p class="text-xs text-rose-600">Earlier than <?php echo $quarterLabels[$currentQuarterNumber - 1]; ?></p></div>
              <span class="text-lg font-bold text-rose-700"><?php echo $overdueDeadlineCount; ?></span>
            </div>
          <?php endif; ?>
          <?php foreach ($upcomingDeadlines as $quarterNumber => $scopeCount): ?>
            <?php
              $deadlineMonth = ($quarterNumber * 3);
              $deadlineDate = new DateTimeImmutable(sprintf('%d-%02d-01', $currentYear, $deadlineMonth));
              $deadlineDate = $deadlineDate->modify('+1 month')->modify('-1 day');
            ?>
            <div class="flex items-center justify-between border-b border-slate-100 pb-3 last:border-0">
              <div><p class="text-sm font-semibold text-slate-800"><?php echo $quarterLabels[$quarterNumber - 1]; ?> target</p><p class="text-xs text-slate-500">Due <?php echo $deadlineDate->format('M j'); ?></p></div>
              <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800"><?php echo $scopeCount; ?> pending</span>
            </div>
          <?php endforeach; ?>
          <?php if ($overdueDeadlineCount === 0 && $upcomingDeadlines === []): ?>
            <div class="mt-10 text-center">
              <i data-lucide="circle-check" class="mx-auto h-8 w-8 text-emerald-500"></i>
              <p class="mt-2 text-sm font-semibold text-slate-700">No outstanding targets</p>
              <p class="mt-1 text-xs text-slate-500">All current and upcoming target quarters are verified.</p>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>

    <div class="space-y-4">
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
        <h2 class="text-base font-bold">Programs Overview</h2>
        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex items-center justify-between border-b border-slate-100 pb-2"><dt class="text-slate-600">Total Programs</dt><dd class="font-semibold text-slate-900"><?php echo $dashboardDataAvailable ? $totalActivePrograms : '&mdash;'; ?></dd></div>
          <div class="flex items-center justify-between border-b border-slate-100 pb-2"><dt class="text-slate-600">Verified (any Q)</dt><dd class="font-semibold text-emerald-600"><?php echo $dashboardDataAvailable ? $verifiedPrograms : '&mdash;'; ?></dd></div>
          <div class="flex items-center justify-between"><dt class="text-slate-600">Pending</dt><dd class="font-semibold text-rose-600"><?php echo $dashboardDataAvailable ? $pendingPrograms : '&mdash;'; ?></dd></div>
        </dl>
        <a href="app.php?page=academics" data-page="academics" class="mt-5 flex items-center justify-center gap-1 border-t border-slate-100 pt-3 text-xs font-semibold text-rose-600"><i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>View Academics</a>
      </section>

      <section class="min-h-[150px] rounded-2xl bg-[#191b1c] p-5 text-white shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
        <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-sky-200">Current Quarter</p>
        <p class="mt-4 text-lg font-semibold"><?php echo $quarterLabels[$currentQuarterNumber - 1]; ?> · <?php echo $currentYear; ?></p>
        <p class="mt-1 text-sm text-slate-400"><?php echo $currentQuarterStart->format('M j'); ?> – <?php echo $currentQuarterEnd->format('M j'); ?></p>
        <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-600" role="progressbar" aria-label="Current quarter verification progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $currentQuarterMetric['percent']; ?>">
          <div class="h-full rounded-full bg-sky-300 transition-all" style="width: <?php echo $currentQuarterMetric['percent']; ?>%"></div>
        </div>
        <p class="mt-1 text-right text-xs text-sky-200"><?php echo $dashboardDataAvailable ? $currentQuarterMetric['percent'] . '% verified · ' . $currentQuarterMetric['completed'] . '/' . $progressScopeCount . ' scopes' : 'Progress unavailable'; ?></p>
      </section>
    </div>
  </div>
</div>
