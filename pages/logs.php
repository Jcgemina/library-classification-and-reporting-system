<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    header('Location: ../app.php?page=logs');
    exit;
}

requireLogin();
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-700">Admin access is required to view logs.</div>';
    exit;
}

$today = new DateTimeImmutable('today');
$defaultStart = $today->modify('-6 days');
$parseLogDate = static fn(string $value, DateTimeImmutable $fallback): DateTimeImmutable => DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: $fallback;
$activityStartDate = $parseLogDate((string)($_GET['activity_start_date'] ?? $_GET['start_date'] ?? ''), $defaultStart);
$activityEndDate = $parseLogDate((string)($_GET['activity_end_date'] ?? $_GET['end_date'] ?? ''), $today);
$securityStartDate = $parseLogDate((string)($_GET['security_start_date'] ?? $_GET['start_date'] ?? ''), $defaultStart);
$securityEndDate = $parseLogDate((string)($_GET['security_end_date'] ?? $_GET['end_date'] ?? ''), $today);
if ($activityStartDate > $activityEndDate) [$activityStartDate, $activityEndDate] = [$activityEndDate, $activityStartDate];
if ($securityStartDate > $securityEndDate) [$securityStartDate, $securityEndDate] = [$securityEndDate, $securityStartDate];

$activitySearch = trim((string)($_GET['activity_search'] ?? $_GET['search'] ?? ''));
$activityUserId = filter_var($_GET['activity_user_id'] ?? $_GET['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$securitySearch = trim((string)($_GET['security_search'] ?? $_GET['search'] ?? ''));
$securityUserId = filter_var($_GET['security_user_id'] ?? $_GET['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$securitySeverity = in_array($_GET['security_severity'] ?? $_GET['severity'] ?? '', ['info', 'warning', 'critical'], true) ? (string)($_GET['security_severity'] ?? $_GET['severity']) : '';
$activeTab = ($_GET['log_tab'] ?? '') === 'security' ? 'security' : 'activity';
$pageSize = 8;
$activityPage = max(1, (int)($_GET['activity_page'] ?? 1));
$securityPage = max(1, (int)($_GET['security_page'] ?? 1));
$activityFromTimestamp = $activityStartDate->format('Y-m-d 00:00:00');
$activityUntilTimestamp = $activityEndDate->modify('+1 day')->format('Y-m-d 00:00:00');
$securityFromTimestamp = $securityStartDate->format('Y-m-d 00:00:00');
$securityUntilTimestamp = $securityEndDate->modify('+1 day')->format('Y-m-d 00:00:00');
$activityLogs = [];
$securityLogs = [];
$activityTotal = 0;
$securityTotal = 0;
$logUsers = [];
$databaseError = null;

function logFilterParts(string $alias, string $search, ?int $userId, string $severity, string $fromTimestamp, string $untilTimestamp): array {
  $where = ["{$alias}.created_at >= :from_timestamp", "{$alias}.created_at < :until_timestamp"];
  $params = [':from_timestamp' => $fromTimestamp, ':until_timestamp' => $untilTimestamp];

  if ($search !== '') {
    $eventColumn = $alias === 'a' ? 'action' : 'event_type';
    $usernameCondition = $alias === 's' ? " OR {$alias}.username LIKE :search_username" : '';
    $where[] = "({$alias}.description LIKE :search_description OR {$alias}.ip_address LIKE :search_ip OR {$alias}.{$eventColumn} LIKE :search_event OR u.full_name LIKE :search_user{$usernameCondition})";
    $searchValue = '%' . $search . '%';
    $params[':search_description'] = $searchValue;
    $params[':search_ip'] = $searchValue;
    $params[':search_event'] = $searchValue;
    $params[':search_user'] = $searchValue;
    if ($alias === 's') $params[':search_username'] = $searchValue;
  }
  if ($userId !== null) {
    $where[] = "{$alias}.user_id = :user_id";
    $params[':user_id'] = $userId;
  }
  if ($severity !== '') {
    $where[] = "{$alias}.severity = :severity";
    $params[':severity'] = $severity;
  }

  return [implode(' AND ', $where), $params];
}

try {
  if ($pdo === null) {
    throw new PDOException('Database connection is not established.');
  }

  $logUsers = $pdo->query("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();
  [$activityWhere, $activityParams] = logFilterParts('a', $activitySearch, $activityUserId, '', $activityFromTimestamp, $activityUntilTimestamp);
  [$securityWhere, $securityParams] = logFilterParts('s', $securitySearch, $securityUserId, $securitySeverity, $securityFromTimestamp, $securityUntilTimestamp);

  $countActivity = $pdo->prepare("SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE {$activityWhere}");
  $countActivity->execute($activityParams);
  $activityTotal = (int)$countActivity->fetchColumn();
  $countSecurity = $pdo->prepare("SELECT COUNT(*) FROM security_logs s LEFT JOIN users u ON u.id = s.user_id WHERE {$securityWhere}");
  $countSecurity->execute($securityParams);
  $securityTotal = (int)$countSecurity->fetchColumn();

  $activityOffset = ($activityPage - 1) * $pageSize;
  $activityQuery = $pdo->prepare("SELECT a.*, u.full_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE {$activityWhere} ORDER BY a.created_at DESC LIMIT {$pageSize} OFFSET {$activityOffset}");
  $activityQuery->execute($activityParams);
  $activityLogs = $activityQuery->fetchAll();

  $securityOffset = ($securityPage - 1) * $pageSize;
  $securityQuery = $pdo->prepare("SELECT s.*, u.full_name FROM security_logs s LEFT JOIN users u ON u.id = s.user_id WHERE {$securityWhere} ORDER BY s.created_at DESC LIMIT {$pageSize} OFFSET {$securityOffset}");
  $securityQuery->execute($securityParams);
  $securityLogs = $securityQuery->fetchAll();
} catch (PDOException $exception) {
  error_log('Error fetching logs: ' . $exception->getMessage());
  $databaseError = 'Unable to retrieve logs right now. Check the connection and try again.';
}

$totalPages = static fn(int $total): int => max(1, (int)ceil($total / $pageSize));
$activityPages = $totalPages($activityTotal);
$securityPages = $totalPages($securityTotal);
$activityPage = min($activityPage, $activityPages);
$securityPage = min($securityPage, $securityPages);
$queryParams = $_GET;
unset($queryParams['page'], $queryParams['activity_page'], $queryParams['security_page'], $queryParams['start_date'], $queryParams['end_date'], $queryParams['search'], $queryParams['user_id'], $queryParams['severity']);
$queryParams['activity_start_date'] = $activityStartDate->format('Y-m-d');
$queryParams['activity_end_date'] = $activityEndDate->format('Y-m-d');
$queryParams['security_start_date'] = $securityStartDate->format('Y-m-d');
$queryParams['security_end_date'] = $securityEndDate->format('Y-m-d');
$queryParams['activity_search'] = $activitySearch;
$queryParams['activity_user_id'] = $activityUserId ?? '';
$queryParams['security_search'] = $securitySearch;
$queryParams['security_user_id'] = $securityUserId ?? '';
$queryParams['security_severity'] = $securitySeverity;
$queryParams['log_tab'] = $activeTab;
$buildPageUrl = static function (string $type, int $page) use ($queryParams): string {
  $params = $queryParams;
  $params[$type . '_page'] = $page;
  return 'app.php?page=logs&' . http_build_query($params);
};
$formatLogDate = static fn(string $value): string => (new DateTimeImmutable($value))->format('M j, Y g:i A');
$formatLogLabel = static fn(string $value): string => ucwords(str_replace(['_', '-'], ' ', $value));
$renderLogPagination = static function (string $type, int $currentPage, int $pages) use ($buildPageUrl): void {
  if ($pages <= 1) return;
  $previous = max(1, $currentPage - 1);
  $next = min($pages, $currentPage + 1);
  echo '<div class="log-pagination" aria-label="' . htmlspecialchars(ucfirst($type) . ' log pages', ENT_QUOTES, 'UTF-8') . '">';
  echo '<a class="' . ($currentPage === 1 ? 'pointer-events-none opacity-40 ' : '') . 'inline-flex h-9 items-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 hover:bg-slate-100" href="' . htmlspecialchars($buildPageUrl($type, $previous), ENT_QUOTES, 'UTF-8') . '">Previous</a>';
  echo '<span class="text-xs font-semibold text-slate-500">Page ' . $currentPage . ' of ' . $pages . '</span>';
  echo '<a class="' . ($currentPage === $pages ? 'pointer-events-none opacity-40 ' : '') . 'inline-flex h-9 items-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 hover:bg-slate-100" href="' . htmlspecialchars($buildPageUrl($type, $next), ENT_QUOTES, 'UTF-8') . '">Next</a>';
  echo '</div>';
};
?>
<style>
  .logs-stage {
    position: relative;
  }

  .log-panel {
    position: absolute;
    inset: 0;
    width: 100%;
    opacity: 0;
    pointer-events: none;
    transform: translateY(10px);
    transition: opacity 220ms ease, transform 220ms ease;
  }

  .log-panel.is-active {
    position: relative;
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
  }

  .log-table-shell {
    max-height: calc(100vh - 260px);
    overflow-y: auto;
  }

  .log-filter-form {
    margin: 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    background: #f8fafc;
  }

  .log-results-card {
    margin: 0 1rem 1rem;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    background: #ffffff;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
  }

  .log-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 1rem 1.25rem 1.25rem;
    border-top: 1px solid #e2e8f0;
    background: linear-gradient(to top, #ffffff, #f8fafc);
  }

  @media (prefers-reduced-motion: reduce) {
    .log-panel {
      transition: none;
    }
  }
</style>
<div id="logsContent" class="min-h-[calc(100vh-5rem)] p-1 text-slate-900 sm:p-2">
  <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div><h2 class="text-2xl font-bold">System activity and security</h2><p class="mt-1 text-sm text-slate-500">Review activity and security events with separate filters for each stream.</p></div>
    <div class="flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm" role="tablist" aria-label="Log type">
      <button id="activityLogTab" type="button" role="tab" aria-controls="activityLogPanel" tabindex="<?php echo $activeTab === 'activity' ? '0' : '-1'; ?>" class="log-tab rounded-md px-3 py-2 text-sm font-semibold <?php echo $activeTab === 'activity' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'; ?>" data-log-tab="activity" aria-selected="<?php echo $activeTab === 'activity' ? 'true' : 'false'; ?>">Activity logs</button>
      <button id="securityLogTab" type="button" role="tab" aria-controls="securityLogPanel" tabindex="<?php echo $activeTab === 'security' ? '0' : '-1'; ?>" class="log-tab rounded-md px-3 py-2 text-sm font-semibold <?php echo $activeTab === 'security' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'; ?>" data-log-tab="security" aria-selected="<?php echo $activeTab === 'security' ? 'true' : 'false'; ?>">Security logs</button>
    </div>
  </div>
  <?php if ($databaseError): ?><div role="alert" class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><span><?php echo htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'); ?></span><a href="app.php?page=logs" class="font-semibold underline underline-offset-2">Try again</a></div><?php endif; ?>
  <div id="logsStage" class="logs-stage">
  <section id="activityLogPanel" role="tabpanel" aria-labelledby="activityLogTab" <?php echo $activeTab === 'activity' ? '' : 'hidden'; ?> class="log-panel <?php echo $activeTab === 'activity' ? 'is-active' : ''; ?>">
    <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-bold">Activity logs</h3><p class="text-sm text-slate-500">Changes made inside the library system.</p></div>
    <form id="activityLogFilterForm" data-log-filter-form="activity" method="get" action="app.php" class="log-filter-form p-4">
      <input type="hidden" name="page" value="logs"><input type="hidden" name="log_tab" value="activity">
      <input type="hidden" name="security_start_date" value="<?php echo htmlspecialchars($securityStartDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="security_end_date" value="<?php echo htmlspecialchars($securityEndDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="security_search" value="<?php echo htmlspecialchars($securitySearch, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="security_user_id" value="<?php echo $securityUserId ?? ''; ?>"><input type="hidden" name="security_severity" value="<?php echo htmlspecialchars($securitySeverity, ENT_QUOTES, 'UTF-8'); ?>">
      <div class="grid gap-3 lg:grid-cols-[1.5fr_1fr_1fr_1fr_auto] lg:items-end">
        <label class="block text-sm font-semibold text-slate-700">Search activity<input type="search" name="activity_search" value="<?php echo htmlspecialchars($activitySearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="User, action, IP, or description" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal text-slate-900 outline-none transition focus:border-slate-900 focus:ring-2 focus:ring-slate-200"></label>
        <label class="block text-sm font-semibold text-slate-700">From<input type="date" name="activity_start_date" value="<?php echo htmlspecialchars($activityStartDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal text-slate-900 outline-none transition focus:border-slate-900 focus:ring-2 focus:ring-slate-200"></label>
        <label class="block text-sm font-semibold text-slate-700">To<input type="date" name="activity_end_date" value="<?php echo htmlspecialchars($activityEndDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal text-slate-900 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"></label>
        <label class="block text-sm font-semibold text-slate-700">User<select name="activity_user_id" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal text-slate-900 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"><option value="">All users</option><?php foreach ($logUsers as $user): ?><option value="<?php echo (int)$user['id']; ?>" <?php echo $activityUserId === (int)$user['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['full_name'] . ' (' . $user['username'] . ')', ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        <div class="flex gap-2"><button type="submit" class="inline-flex min-h-[42px] items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply activity filters</button><a href="#" data-log-reset="activity" class="inline-flex min-h-[42px] items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-white">Reset</a></div>
      </div>
      <p class="mt-3 flex flex-wrap gap-3 text-xs text-slate-500"><span><?php echo number_format($activityTotal); ?> activity records match this range.</span><a href="pages/logs_export.php?<?php echo htmlspecialchars(http_build_query($queryParams), ENT_QUOTES, 'UTF-8'); ?>" class="font-semibold text-rose-700 underline decoration-rose-200 underline-offset-2 hover:text-rose-900">Export filtered logs</a></p>
    </form>
    <?php if ($databaseError): ?><p class="m-4 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">Activity records are unavailable until the connection is restored.</p><?php elseif (!$activityLogs): ?><p class="m-4 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">No activity logs match the selected filters.</p><?php else: ?><div class="log-results-card"><div class="log-table-shell overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Time</th><th class="px-5 py-3">User</th><th class="px-5 py-3">Action</th><th class="px-5 py-3">Description</th><th class="px-5 py-3">IP address</th><th class="px-5 py-3">Details</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($activityLogs as $log): ?><tr><td class="whitespace-nowrap px-5 py-3 text-slate-500"><?php echo htmlspecialchars($formatLogDate($log['created_at']), ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 font-medium"><?php echo htmlspecialchars($log['full_name'] ?? 'System', ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3"><?php echo htmlspecialchars($formatLogLabel($log['action']), ENT_QUOTES, 'UTF-8'); ?></td><td class="max-w-[32rem] px-5 py-3 text-slate-600"><?php echo htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 text-slate-500"><?php echo htmlspecialchars($log['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3"><button type="button" class="log-detail-button font-semibold text-rose-700 underline decoration-rose-200 underline-offset-2 hover:text-rose-900" data-log-detail="<?php echo htmlspecialchars(json_encode(['type' => 'Activity', 'time' => $formatLogDate($log['created_at']), 'user' => $log['full_name'] ?? 'System', 'action' => $formatLogLabel($log['action']), 'description' => $log['description'], 'ip' => $log['ip_address'] ?? '-', 'entity' => ($log['entity_type'] ?? '-') . (($log['entity_id'] ?? null) ? ' #' . $log['entity_id'] : ''), 'agent' => '-'], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8'); ?>">View</button></td></tr><?php endforeach; ?></tbody></table></div><?php $renderLogPagination('activity', $activityPage, $activityPages); ?></div><?php endif; ?>
  </section>
  <section id="securityLogPanel" role="tabpanel" aria-labelledby="securityLogTab" <?php echo $activeTab === 'security' ? '' : 'hidden'; ?> class="log-panel <?php echo $activeTab === 'security' ? 'is-active' : ''; ?>">
    <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-bold">Security logs</h3><p class="text-sm text-slate-500">Authentication activity, blocked attempts, and suspicious events.</p></div>
    <form id="securityLogFilterForm" data-log-filter-form="security" method="get" action="app.php" class="log-filter-form p-4">
      <input type="hidden" name="page" value="logs"><input type="hidden" name="log_tab" value="security">
      <input type="hidden" name="activity_start_date" value="<?php echo htmlspecialchars($activityStartDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="activity_end_date" value="<?php echo htmlspecialchars($activityEndDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="activity_search" value="<?php echo htmlspecialchars($activitySearch, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="activity_user_id" value="<?php echo $activityUserId ?? ''; ?>">
      <div class="grid gap-3 lg:grid-cols-[1.5fr_1fr_1fr_1fr_1fr_auto] lg:items-end">
        <label class="block text-sm font-semibold text-slate-700">Search security<input type="search" name="security_search" value="<?php echo htmlspecialchars($securitySearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="User, event, IP, or description" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal text-slate-900 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"></label>
        <label class="block text-sm font-semibold text-slate-700">From<input type="date" name="security_start_date" value="<?php echo htmlspecialchars($securityStartDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal text-slate-900 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"></label>
        <label class="block text-sm font-semibold text-slate-700">To<input type="date" name="security_end_date" value="<?php echo htmlspecialchars($securityEndDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal text-slate-900 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"></label>
        <label class="block text-sm font-semibold text-slate-700">User<select name="security_user_id" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal text-slate-900 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"><option value="">All users</option><?php foreach ($logUsers as $user): ?><option value="<?php echo (int)$user['id']; ?>" <?php echo $securityUserId === (int)$user['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($user['full_name'] . ' (' . $user['username'] . ')', ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        <label class="block text-sm font-semibold text-slate-700">Severity<select name="security_severity" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-normal text-slate-900 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"><option value="">All severities</option><?php foreach (['info', 'warning', 'critical'] as $option): ?><option value="<?php echo $option; ?>" <?php echo $securitySeverity === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars($formatLogLabel($option), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></label>
        <div class="flex gap-2"><button type="submit" class="inline-flex min-h-[42px] items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply security filters</button><a href="#" data-log-reset="security" class="inline-flex min-h-[42px] items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-white">Reset</a></div>
      </div>
      <p class="mt-3 flex flex-wrap gap-3 text-xs text-slate-500"><span><?php echo number_format($securityTotal); ?> security records match this range.</span><a href="pages/logs_export.php?<?php echo htmlspecialchars(http_build_query($queryParams), ENT_QUOTES, 'UTF-8'); ?>" class="font-semibold text-rose-700 underline decoration-rose-200 underline-offset-2 hover:text-rose-900">Export filtered logs</a></p>
    </form>
    <?php if ($databaseError): ?><p class="m-4 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">Security records are unavailable until the connection is restored.</p><?php elseif (!$securityLogs): ?><p class="m-4 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">No security logs match the selected filters.</p><?php else: ?><div class="log-results-card"><div class="log-table-shell overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Time</th><th class="px-5 py-3">Event</th><th class="px-5 py-3">Severity</th><th class="px-5 py-3">User</th><th class="px-5 py-3">Description</th><th class="px-5 py-3">IP address</th><th class="px-5 py-3">Details</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($securityLogs as $log): ?><tr><td class="whitespace-nowrap px-5 py-3 text-slate-500"><?php echo htmlspecialchars($formatLogDate($log['created_at']), ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 font-medium"><?php echo htmlspecialchars($formatLogLabel($log['event_type']), ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3">
      <span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo $log['severity'] === 'critical' ? 'bg-red-100 text-red-700' : ($log['severity'] === 'warning' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'); ?>"><?php echo htmlspecialchars($formatLogLabel($log['severity']), ENT_QUOTES, 'UTF-8'); ?></span>
      </td><td class="px-5 py-3"><?php echo htmlspecialchars($log['full_name'] ?? $log['username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></td><td class="max-w-[32rem] px-5 py-3 text-slate-600"><?php echo htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 text-slate-500"><?php echo htmlspecialchars($log['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3"><button type="button" class="log-detail-button font-semibold text-rose-700 underline decoration-rose-200 underline-offset-2 hover:text-rose-900" data-log-detail="<?php echo htmlspecialchars(json_encode(['type' => 'Security', 'time' => $formatLogDate($log['created_at']), 'user' => $log['full_name'] ?? $log['username'] ?? 'Unknown', 'action' => $formatLogLabel($log['event_type']), 'severity' => $formatLogLabel($log['severity']), 'description' => $log['description'], 'ip' => $log['ip_address'] ?? '-', 'entity' => '-', 'agent' => $log['user_agent'] ?? '-'], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8'); ?>">View</button></td></tr><?php endforeach; ?></tbody></table></div><?php $renderLogPagination('security', $securityPage, $securityPages); ?></div><?php endif; ?>
  </section>
  </div>
</div>
<script>
(function () {
const detailDialog = document.createElement('dialog');
detailDialog.className = 'w-[min(92vw,38rem)] rounded-2xl border border-slate-200 p-0 shadow-2xl backdrop:bg-slate-900/50';
detailDialog.innerHTML = '<div class="border-b border-slate-100 px-5 py-4"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Audit detail</p><h3 class="mt-1 text-lg font-bold text-slate-900" data-log-detail-title>Event detail</h3></div><button type="button" aria-label="Close event details" data-log-detail-close class="rounded-lg p-1 text-2xl leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-700">&times;</button></div></div><dl class="grid gap-3 px-5 py-5 text-sm sm:grid-cols-2" data-log-detail-fields></dl>';
document.body.appendChild(detailDialog);
const escapeHtml = function (value) {
  return String(value).replace(/[&<>'"]/g, function (character) {
    return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[character];
  });
};

document.querySelectorAll('[data-log-detail]').forEach(function (button) {
  button.addEventListener('click', function () {
    const detail = JSON.parse(button.dataset.logDetail);
    detailDialog.querySelector('[data-log-detail-title]').textContent = detail.action;
    detailDialog.querySelector('[data-log-detail-fields]').innerHTML = Object.entries(detail).filter(function (entry) {
      return entry[0] !== 'action' && entry[0] !== 'description';
    }).map(function (entry) {
      return '<div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">' + escapeHtml(entry[0]) + '</dt><dd class="mt-1 break-words font-medium text-slate-900">' + escapeHtml(entry[1]) + '</dd></div>';
    }).join('') + '<div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Description</dt><dd class="mt-1 text-slate-900">' + escapeHtml(detail.description) + '</dd></div>';
    detailDialog.showModal();
  });
});

detailDialog.querySelector('[data-log-detail-close]').addEventListener('click', function () {
  detailDialog.close();
});

detailDialog.addEventListener('click', function (event) {
  if (event.target === detailDialog) {
    detailDialog.close();
  }
});

const activateLogTab = function (tabName) {
  const security = tabName === 'security';
  const activityPanel = document.getElementById('activityLogPanel');
  const securityPanel = document.getElementById('securityLogPanel');
  activityPanel.classList.toggle('is-active', !security);
  securityPanel.classList.toggle('is-active', security);
  activityPanel.hidden = security;
  securityPanel.hidden = !security;
  const formTab = document.querySelector('[data-log-filter-form="' + tabName + '"] input[name="log_tab"]');
  if (formTab) formTab.value = tabName;
  document.querySelectorAll('[data-log-tab]').forEach(function (item) {
    const selected = item.dataset.logTab === tabName;
    item.classList.toggle('bg-slate-900', selected);
    item.classList.toggle('text-white', selected);
    item.classList.toggle('text-slate-600', !selected);
    item.classList.toggle('hover:bg-slate-100', !selected);
    item.setAttribute('aria-selected', selected ? 'true' : 'false');
    item.setAttribute('tabindex', selected ? '0' : '-1');
  });
  document.querySelectorAll('#' + tabName + 'LogPanel a').forEach(function (link) {
    if (link.dataset.logReset) return;
    const linkUrl = new URL(link.href, window.location.href);
    if (link.href.includes('logs_export.php')) {
      linkUrl.searchParams.set('log_tab', tabName);
      link.href = linkUrl.toString();
      return;
    }
    if (linkUrl.searchParams.get('page') !== 'logs') return;
    linkUrl.searchParams.set('log_tab', tabName);
    link.href = linkUrl.toString();
  });
};

document.addEventListener('click', function (event) {
  const tab = event.target.closest('[data-log-tab]');
  if (!tab || !document.getElementById('logsContent')?.contains(tab)) return;
  activateLogTab(tab.dataset.logTab);
  const tabUrl = new URL(window.location.href);
  tabUrl.searchParams.set('log_tab', tab.dataset.logTab);
  history.replaceState({page: 'logs'}, '', tabUrl);
});

document.querySelector('[role="tablist"]').addEventListener('keydown', function (event) {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
  const tabs = [...this.querySelectorAll('[role="tab"]')];
  const current = tabs.findIndex(function (tab) { return tab.getAttribute('aria-selected') === 'true'; });
  const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
  event.preventDefault();
  tabs[nextIndex].focus();
  tabs[nextIndex].click();
});

const logsRoot = document.getElementById('logsContent');
const refreshLogs = function (url) {
  if (!logsRoot) return;
  logsRoot.setAttribute('aria-busy', 'true');
  fetch('pages/logs.php' + url.search, {
    method: 'GET',
    headers: {'X-Requested-With': 'XMLHttpRequest'}
  })
    .then(function (response) {
      if (!response.ok) throw new Error('Logs refresh failed');
      return response.text();
    })
    .then(function (html) {
      const fragment = document.createElement('div');
      fragment.innerHTML = html;
      const nextRoot = fragment.querySelector('#logsContent');
      if (!nextRoot) throw new Error('Logs fragment is missing');
      logsRoot.replaceWith(nextRoot);
      nextRoot.querySelectorAll('script').forEach(function (oldScript) {
        const newScript = document.createElement('script');
        newScript.textContent = oldScript.textContent;
        oldScript.replaceWith(newScript);
      });
    })
    .catch(function () {
      logsRoot.removeAttribute('aria-busy');
      const error = document.createElement('p');
      error.className = 'mt-3 text-sm font-medium text-red-700';
      error.textContent = 'The filters could not be applied. Please try again.';
      document.querySelector('[data-log-filter-form="' + new URL(window.location.href).searchParams.get('log_tab') + '"]')?.appendChild(error);
    });
};

document.querySelectorAll('[data-log-filter-form]').forEach(function (form) {
  form.addEventListener('submit', function (event) {
  event.preventDefault();
  const url = new URL(window.location.href);
  url.search = '';
  url.searchParams.set('page', 'logs');
  new FormData(this).forEach(function (value, key) {
    if (value !== '') url.searchParams.set(key, value);
  });
  url.searchParams.delete('activity_page');
  url.searchParams.delete('security_page');
  history.pushState({page: 'logs'}, '', url);
  refreshLogs(url);
  });
});

logsRoot?.addEventListener('click', function (event) {
  const link = event.target.closest('a');
  if (!link || link.href.includes('logs_export.php')) return;
  const resetType = link.dataset.logReset;
  if (resetType) {
    event.preventDefault();
    const url = new URL(window.location.href);
    ['search', 'start_date', 'end_date', 'user_id', 'severity', resetType + '_search', resetType + '_start_date', resetType + '_end_date', resetType + '_user_id', resetType + '_severity', resetType + '_page'].forEach(function (key) {
      url.searchParams.delete(key);
    });
    url.searchParams.set('page', 'logs');
    url.searchParams.set('log_tab', resetType);
    history.pushState({page: 'logs'}, '', url);
    refreshLogs(url);
    return;
  }
  const url = new URL(link.href, window.location.href);
  if (url.searchParams.get('page') !== 'logs') return;
  event.preventDefault();
  history.pushState({page: 'logs'}, '', url);
  refreshLogs(url);
});
})();
</script>
