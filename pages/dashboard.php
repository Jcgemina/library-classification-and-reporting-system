<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
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
    'SELECT p.id AS program_id, p.name AS program_name, col.name AS college_name,
            m.id AS major_id, m.name AS major_name,
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
  $dashboardDataError = 'Dashboard data is temporarily unavailable. Try again. If the problem continues, contact your administrator.';
}

$verifiedProgramIds = [];
$upcomingDeadlines = [];
$upcomingScopes = [];
$overdueScopes = [];
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
    $deadlineScope = [
      'program_id' => (int)$scope['program_id'],
      'major_id' => (int)($scope['major_id'] ?? 0),
      'program_name' => (string)$scope['program_name'],
      'college_name' => (string)$scope['college_name'],
      'major_name' => (string)($scope['major_name'] ?? ''),
      'target_quarter' => (string)$scope['target_quarter'],
    ];
    if (!$targetActual && $targetQuarterNumber < $currentQuarterNumber) {
      $overdueDeadlineCount++;
      $overdueScopes[] = $deadlineScope;
    } elseif (!$targetActual && $targetQuarterNumber >= $currentQuarterNumber) {
      $upcomingDeadlines[$targetQuarterNumber] = ($upcomingDeadlines[$targetQuarterNumber] ?? 0) + 1;
      $upcomingScopes[$targetQuarterNumber][] = $deadlineScope;
    }
  }

  foreach ($quarterActualCounts as $index => $completedCount) {
    $quarterMetrics[$index] = [
      'completed' => $completedCount,
      'percent' => $progressScopeCount > 0 ? (int)round(((float)$completedCount / (float)$progressScopeCount) * 100.0) : 0,
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
$escapeDashboardValue = static fn (mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
$copyrightCoverageMetrics = array_map(static function (array $metric) use ($totalBookTitles, $dashboardDataAvailable): array {
  $titleCount = (int)$metric['title_count'];
  return [
    'years_threshold' => (int)$metric['years_threshold'],
    'title_count' => $titleCount,
    'percent' => $dashboardDataAvailable && $totalBookTitles > 0
      ? min(100, (int)round(($titleCount / $totalBookTitles) * 100))
      : 0,
  ];
}, $copyrightYearMetrics);
?>

<div class="min-h-[calc(100vh-5rem)] space-y-8 p-1 text-slate-900 sm:p-2">
  <header class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
    <div>
      <h2 class="text-2xl font-bold tracking-tight text-slate-900">Dashboard</h2>
      <p class="mt-1 text-sm text-slate-600">Collection coverage and academic progress · <?php echo $currentYear; ?></p>
    </div>
    <a href="app.php?page=report" data-page="report" class="inline-flex w-fit items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-rose-300 hover:text-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-300">
      <i data-lucide="file-chart-column" class="h-4 w-4" aria-hidden="true"></i>
      Open reports
    </a>
  </header>

  <?php if ($dashboardDataError !== null): ?>
    <div role="alert" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
      <p><?php echo $escapeDashboardValue($dashboardDataError); ?></p>
      <button type="button" data-retry-page="dashboard" class="rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-900 transition hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-400">Try again</button>
    </div>
  <?php endif; ?>

  <section class="border-y border-slate-300 py-5 sm:py-6" aria-labelledby="collectionCoverageTitle">
    <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-baseline">
      <div>
        <h2 id="collectionCoverageTitle" class="text-base font-bold text-slate-900">Collection coverage</h2>
        <p class="mt-1 text-xs text-slate-600">Copyright year, or publication year when unavailable · thresholds overlap</p>
      </div>
      <a href="app.php?page=inventory" data-page="inventory" class="w-fit text-xs font-semibold text-rose-700 underline-offset-4 hover:underline focus:outline-none focus:ring-2 focus:ring-rose-300">Browse inventory</a>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-x-4 gap-y-5 sm:grid-cols-4">
      <div class="flex flex-col justify-center border-b border-slate-200 pb-4 sm:border-b-0 sm:pb-0">
        <span class="text-xs font-medium text-slate-600">Active book titles</span>
        <strong class="mt-1 text-2xl font-bold leading-tight tabular-nums tracking-tight text-slate-900"><?php echo $dashboardDataAvailable ? number_format($totalBookTitles) : '&mdash;'; ?></strong>
        <span class="mt-1 text-[11px] text-slate-600">catalog records</span>
      </div>
      <?php foreach ($copyrightCoverageMetrics as $metric): ?>
        <div class="min-w-0 border-b border-slate-200 pb-4 last:border-b-0 sm:border-b-0 sm:pb-0">
          <div class="flex items-baseline justify-between gap-2">
            <span class="text-xs font-medium text-slate-600">Within <?php echo $metric['years_threshold']; ?> years</span>
            <span class="text-[11px] tabular-nums text-slate-600"><?php echo $dashboardDataAvailable ? $metric['percent'] . '%' : '&mdash;'; ?></span>
          </div>
          <strong class="mt-1 block text-xl font-bold leading-tight tabular-nums text-slate-900"><?php echo $dashboardDataAvailable ? number_format($metric['title_count']) : '&mdash;'; ?></strong>
          <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Collection titles within <?php echo $metric['years_threshold']; ?> years" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $dashboardDataAvailable ? $metric['percent'] : 0; ?>">
            <span class="block h-full rounded-full bg-rose-700" style="width: <?php echo $dashboardDataAvailable ? $metric['percent'] : 0; ?>%"></span>
          </div>
          <span class="mt-1 block text-[11px] text-slate-600">of active titles</span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="grid grid-cols-1 gap-x-10 gap-y-8 xl:grid-cols-[minmax(0,1.1fr)_minmax(300px,0.9fr)]">
    <section aria-labelledby="quarterlyProgressTitle">
      <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-300 pb-3">
        <div>
          <h2 id="quarterlyProgressTitle" class="text-base font-bold text-slate-900">Quarterly progress</h2>
          <p class="mt-1 text-xs text-slate-600">Verified program and major scopes · <?php echo $currentYear; ?></p>
        </div>
        <a href="app.php?page=report" data-page="report" class="text-xs font-semibold text-rose-700 underline-offset-4 hover:underline focus:outline-none focus:ring-2 focus:ring-rose-300">View report</a>
      </div>
      <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-slate-200 pb-4 text-xs text-slate-600">
        <span class="font-semibold text-slate-900">Current quarter</span>
        <span><?php echo $quarterLabels[$currentQuarterNumber - 1]; ?> · <?php echo $currentQuarterStart->format('M j'); ?>–<?php echo $currentQuarterEnd->format('M j'); ?></span>
        <span class="tabular-nums"><?php echo $dashboardDataAvailable ? $currentQuarterMetric['completed'] . ' / ' . $progressScopeCount . ' scopes verified' : 'Progress unavailable'; ?></span>
      </div>
      <?php if (!$dashboardDataAvailable): ?>
        <p class="py-5 text-sm text-slate-600">Quarterly progress is unavailable.</p>
      <?php elseif ($progressScopeCount === 0): ?>
        <p class="py-5 text-sm text-slate-600">Add an active program to begin tracking quarterly progress.</p>
      <?php else: ?>
        <div class="divide-y divide-slate-200">
          <?php foreach ($quarterMetrics as $index => $metric): ?>
            <?php $isCurrentQuarter = $index === $currentQuarterNumber - 1; ?>
            <div class="grid grid-cols-[32px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 py-3">
              <span class="text-xs font-semibold <?php echo $isCurrentQuarter ? 'text-rose-800' : 'text-slate-700'; ?>">Q<?php echo $index + 1; ?></span>
              <div class="h-1.5 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Q<?php echo $index + 1; ?> verification progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $metric['percent']; ?>">
                <span class="block h-full rounded-full <?php echo $isCurrentQuarter ? 'bg-rose-700' : 'bg-slate-500'; ?>" style="width: <?php echo $metric['percent']; ?>%"></span>
              </div>
              <span class="text-right text-xs tabular-nums text-slate-600"><?php echo $metric['completed']; ?> / <?php echo $progressScopeCount; ?> · <?php echo $metric['percent']; ?>%</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p class="mt-3 text-[11px] text-slate-600">A scope is a program or one of its active majors.</p>
    </section>

    <section aria-labelledby="upcomingDeadlinesTitle">
      <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-slate-300 pb-3">
        <div>
          <h2 id="upcomingDeadlinesTitle" class="text-base font-bold text-slate-900">Upcoming deadlines</h2>
          <p class="mt-1 text-xs text-slate-600">Incomplete scopes by target quarter</p>
        </div>
        <a href="app.php?page=report" data-page="report" class="text-xs font-semibold text-rose-700 underline-offset-4 hover:underline focus:outline-none focus:ring-2 focus:ring-rose-300">Update targets</a>
      </div>
      <?php if (!$dashboardDataAvailable): ?>
        <p class="py-5 text-sm text-slate-600">Targets are unavailable.</p>
      <?php elseif ($progressScopeCount === 0): ?>
        <p class="py-5 text-sm text-slate-600">No active program scopes to track.</p>
      <?php else: ?>
        <div class="divide-y divide-slate-200">
          <?php if ($overdueDeadlineCount > 0): ?>
            <div class="flex items-center justify-between gap-3 py-3">
              <div><p class="text-sm font-semibold text-rose-800">Overdue</p><p class="mt-0.5 text-xs text-slate-600">Targets before <?php echo $quarterLabels[$currentQuarterNumber - 1]; ?></p></div>
              <span class="text-sm font-semibold tabular-nums text-rose-800"><?php echo $overdueDeadlineCount; ?> overdue</span>
            </div>
            <ul class="max-h-48 divide-y divide-slate-100 overflow-y-auto border-y border-slate-100" aria-label="Overdue program and major scopes">
              <?php foreach ($overdueScopes as $scope): ?>
                <?php
                  $reportParams = ['page' => 'report', 'year' => $currentYear, 'program_id' => $scope['program_id']];
                  if ($scope['major_id'] > 0) $reportParams['major_id'] = $scope['major_id'];
                  $scopeDescription = $scope['major_name'] !== ''
                    ? $scope['program_name'] . ' · ' . $scope['major_name']
                    : $scope['program_name'] . ' · Program-level target';
                ?>
                <li>
                  <a href="app.php?<?php echo $escapeDashboardValue(http_build_query($reportParams)); ?>" data-page="report" class="flex items-center justify-between gap-3 px-2 py-2.5 text-left transition hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-300">
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-slate-800"><?php echo $escapeDashboardValue($scopeDescription); ?></span><span class="block truncate text-xs text-slate-600"><?php echo $escapeDashboardValue($scope['college_name']); ?> · <?php echo $escapeDashboardValue($scope['target_quarter']); ?> target</span></span>
                    <span class="flex-shrink-0 text-xs font-semibold text-rose-700">Review</span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php foreach ($upcomingDeadlines as $quarterNumber => $scopeCount): ?>
            <?php
              $deadlineDate = new DateTimeImmutable(sprintf('%d-%02d-01', $currentYear, $quarterNumber * 3));
              $deadlineDate = $deadlineDate->modify('+1 month')->modify('-1 day');
            ?>
            <details class="group" <?php echo $quarterNumber === array_key_first($upcomingDeadlines) ? 'open' : ''; ?>>
              <summary class="flex cursor-pointer list-none items-center justify-between gap-3 py-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300">
                <span><span class="block text-sm font-semibold text-slate-800"><?php echo $quarterLabels[$quarterNumber - 1]; ?> target</span><span class="block text-xs text-slate-600">Due <?php echo $deadlineDate->format('M j'); ?></span></span>
                <span class="flex flex-shrink-0 items-center gap-2"><span class="text-xs font-semibold text-amber-800"><?php echo $scopeCount; ?> pending</span><i data-lucide="chevron-down" class="h-4 w-4 text-slate-500 transition-transform group-open:rotate-180"></i></span>
              </summary>
              <ul class="max-h-40 divide-y divide-slate-100 overflow-y-auto border-t border-slate-200 px-2 py-1" aria-label="<?php echo $escapeDashboardValue($quarterLabels[$quarterNumber - 1]); ?> target scopes">
                <?php foreach ($upcomingScopes[$quarterNumber] as $scope): ?>
                  <?php
                    $reportParams = ['page' => 'report', 'year' => $currentYear, 'program_id' => $scope['program_id']];
                    if ($scope['major_id'] > 0) $reportParams['major_id'] = $scope['major_id'];
                    $scopeDescription = $scope['major_name'] !== ''
                      ? $scope['program_name'] . ' · ' . $scope['major_name']
                      : $scope['program_name'] . ' · Program-level target';
                  ?>
                  <li><a href="app.php?<?php echo $escapeDashboardValue(http_build_query($reportParams)); ?>" data-page="report" class="block rounded-md px-2 py-2 transition-colors hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-300"><span class="block truncate text-sm font-semibold text-slate-800"><?php echo $escapeDashboardValue($scopeDescription); ?></span><span class="block truncate text-xs text-slate-600"><?php echo $escapeDashboardValue($scope['college_name']); ?></span></a></li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php endforeach; ?>
          <?php if ($overdueDeadlineCount === 0 && $upcomingDeadlines === []): ?>
            <div class="flex items-start gap-3 py-5">
              <i data-lucide="circle-check" class="mt-0.5 h-5 w-5 flex-shrink-0 text-emerald-700" aria-hidden="true"></i>
              <div><p class="text-sm font-semibold text-emerald-900">No outstanding targets</p><p class="mt-1 text-sm text-slate-700">All current and upcoming target quarters are verified.</p></div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <section class="flex flex-wrap items-center gap-x-8 gap-y-3 border-y border-slate-300 py-4" aria-labelledby="programsOverviewTitle">
    <div class="mr-auto">
      <h2 id="programsOverviewTitle" class="text-base font-bold text-slate-900">Programs</h2>
      <p class="mt-1 text-xs text-slate-600">Active program verification</p>
    </div>
    <div class="flex items-baseline gap-2"><strong class="text-lg tabular-nums text-slate-900"><?php echo $dashboardDataAvailable ? number_format($totalActivePrograms) : '&mdash;'; ?></strong><span class="text-xs text-slate-600">active</span></div>
    <div class="flex items-baseline gap-2"><strong class="text-lg tabular-nums text-emerald-800"><?php echo $dashboardDataAvailable ? number_format($verifiedPrograms) : '&mdash;'; ?></strong><span class="text-xs text-slate-600">verified</span></div>
    <div class="flex items-baseline gap-2"><strong class="text-lg tabular-nums text-rose-800"><?php echo $dashboardDataAvailable ? number_format($pendingPrograms) : '&mdash;'; ?></strong><span class="text-xs text-slate-600">pending</span></div>
    <a href="app.php?page=academics" data-page="academics" class="text-xs font-semibold text-rose-700 underline-offset-4 hover:underline focus:outline-none focus:ring-2 focus:ring-rose-300">View academics</a>
  </section>
  <details class="text-xs text-slate-600">
    <summary class="w-fit cursor-pointer font-semibold underline decoration-slate-300 underline-offset-4 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-rose-300">How collection age is counted</summary>
    <p class="mt-2 max-w-3xl leading-5">Age uses the copyright year, or publication year when no copyright year is recorded. Thresholds are cumulative, so a title may appear in more than one count.</p>
  </details>
