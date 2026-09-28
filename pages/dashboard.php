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

if ($pdo instanceof PDO) {
  try {
    $totalBookTitles = (int)$pdo->query('SELECT COUNT(*) FROM books WHERE deleted_at IS NULL')->fetchColumn();
    $copyrightYearMetrics = getCopyrightYearMetrics($pdo);
  } catch (PDOException $exception) {
    error_log('Dashboard inventory metrics unavailable: ' . $exception->getMessage());
  }
}

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

  <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
    <div class="flex h-36 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
      <div class="flex items-center gap-3"><div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-rose-100 text-rose-600"><i data-lucide="book-open" class="h-5 w-5"></i></div><p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] leading-tight text-rose-800">Total Titles</p></div>
      <p class="text-3xl font-bold leading-none text-slate-950"><?php echo $totalBookTitles; ?></p>
    </div>
    <?php foreach ($copyrightYearMetrics as $index => $metric): ?>
      <?php $style = $copyrightMetricStyles[$index % count($copyrightMetricStyles)]; ?>
      <div class="flex h-36 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
        <div class="flex items-center gap-3"><div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg border <?php echo $style['iconBorder']; ?> <?php echo $style['iconBg']; ?> <?php echo $style['iconColor']; ?>"><i data-lucide="<?php echo $style['icon']; ?>" class="h-5 w-5"></i></div><p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] leading-tight <?php echo $style['labelColor']; ?>">Within <?php echo (int)$metric['years_threshold']; ?> Yrs</p></div>
        <p class="text-3xl font-bold leading-none <?php echo $style['valueColor']; ?>"><?php echo (int)$metric['title_count']; ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-[1.15fr_1.15fr_0.72fr]">
    <section class="min-h-[370px] rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
      <div class="flex items-center justify-between"><h2 class="text-base font-bold">Quarterly Progress</h2><i data-lucide="more-horizontal" class="h-5 w-5 text-slate-300"></i></div>
      <p class="mt-10 text-center text-sm italic text-slate-500">Loading progress...</p>
    </section>

    <section class="min-h-[370px] rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
      <h2 class="text-base font-bold">Upcoming Deadlines</h2>
    </section>

    <div class="space-y-4">
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
        <h2 class="text-base font-bold">Programs Overview</h2>
        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex items-center justify-between border-b border-slate-100 pb-2"><dt class="text-slate-600">Total Programs</dt><dd class="font-semibold text-slate-900">&mdash;</dd></div>
          <div class="flex items-center justify-between border-b border-slate-100 pb-2"><dt class="text-slate-600">Verified (any Q)</dt><dd class="font-semibold text-emerald-600">&mdash;</dd></div>
          <div class="flex items-center justify-between"><dt class="text-slate-600">Pending</dt><dd class="font-semibold text-rose-600">&mdash;</dd></div>
        </dl>
        <a href="app.php?page=academics" class="mt-5 flex items-center justify-center gap-1 border-t border-slate-100 pt-3 text-xs font-semibold text-rose-600"><i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>View Academics</a>
      </section>

      <section class="min-h-[150px] rounded-2xl bg-[#191b1c] p-5 text-white shadow-[0_2px_3px_rgba(15,23,42,0.18)]">
        <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-sky-200">Current Quarter</p>
        <p class="mt-5 text-lg font-semibold">&mdash;</p>
        <p class="mt-2 text-sm text-slate-400">&mdash;</p>
        <div class="mt-4 h-1.5 rounded-full bg-slate-600"></div>
        <p class="mt-1 text-right text-xs text-sky-200">0% through quarter</p>
      </section>
    </div>
  </div>
</div>
