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

$activityLogs = [];
$securityLogs = [];
$databaseError = null;

try {
    $activityLogs = $pdo->query(
        "SELECT a.*, u.full_name FROM activity_logs a
         LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 100"
    )->fetchAll();
    $securityLogs = $pdo->query(
        "SELECT s.*, u.full_name FROM security_logs s
         LEFT JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC LIMIT 100"
    )->fetchAll();
} catch (PDOException $exception) {
    $databaseError = 'Logs are not available yet. Run the updated schema.sql to create the audit tables.';
}
?>
<style>
  .logs-stage {
    position: relative;
    transition: height 260ms cubic-bezier(0.22, 1, 0.36, 1);
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

  @media (prefers-reduced-motion: reduce) {
    .logs-stage,
    .log-panel {
      transition: none;
    }
  }
</style>
<div class="min-h-[calc(100vh-5rem)] p-1 text-slate-900 sm:p-2">
  <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div><h2 class="text-2xl font-bold">Logs</h2><p class="mt-1 text-sm text-slate-500">Review system activity and security events.</p></div>
    <div class="flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm" role="tablist">
      <button type="button" class="log-tab rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white" data-log-tab="activity" aria-selected="true">Activity logs</button>
      <button type="button" class="log-tab rounded-md px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100" data-log-tab="security" aria-selected="false">Security logs</button>
    </div>
  </div>
  <?php if ($databaseError): ?><div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"><?php echo htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <div id="logsStage" class="logs-stage">
  <section id="activityLogPanel" class="log-panel is-active overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-bold">Activity logs</h3><p class="text-sm text-slate-500">Changes made inside the library system.</p></div>
    <?php if (!$activityLogs): ?><p class="p-5 text-sm text-slate-500">No activity logs recorded.</p><?php else: ?><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Time</th><th class="px-5 py-3">User</th><th class="px-5 py-3">Action</th><th class="px-5 py-3">Description</th><th class="px-5 py-3">IP address</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($activityLogs as $log): ?><tr><td class="whitespace-nowrap px-5 py-3 text-slate-500"><?php echo htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 font-medium"><?php echo htmlspecialchars($log['full_name'] ?? 'System', ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3"><?php echo htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 text-slate-600"><?php echo htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 text-slate-500"><?php echo htmlspecialchars($log['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  <section id="securityLogPanel" class="log-panel overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-5 py-4"><h3 class="font-bold">Security logs</h3><p class="text-sm text-slate-500">Authentication activity, blocked attempts, and suspicious events.</p></div>
    <?php if (!$securityLogs): ?><p class="p-5 text-sm text-slate-500">No security logs recorded.</p><?php else: ?><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Time</th><th class="px-5 py-3">Event</th><th class="px-5 py-3">Severity</th><th class="px-5 py-3">User</th><th class="px-5 py-3">Description</th><th class="px-5 py-3">IP address</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($securityLogs as $log): ?><tr><td class="whitespace-nowrap px-5 py-3 text-slate-500"><?php echo htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 font-medium"><?php echo htmlspecialchars($log['event_type'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3"><span class="rounded-full px-2 py-1 text-xs font-semibold <?php echo $log['severity'] === 'critical' ? 'bg-red-100 text-red-700' : ($log['severity'] === 'warning' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'); ?>"><?php echo htmlspecialchars($log['severity'], ENT_QUOTES, 'UTF-8'); ?></span></td><td class="px-5 py-3"><?php echo htmlspecialchars($log['full_name'] ?? $log['username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 text-slate-600"><?php echo htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-3 text-slate-500"><?php echo htmlspecialchars($log['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </section>
  </div>
</div>
<script>
document.querySelectorAll('[data-log-tab]').forEach(function (tab) {
  tab.addEventListener('click', function () {
    if (this.getAttribute('aria-selected') === 'true') {
      return;
    }

    const security = this.dataset.logTab === 'security';
    const stage = document.getElementById('logsStage');
    const nextPanel = document.getElementById(security ? 'securityLogPanel' : 'activityLogPanel');

    if (nextPanel.classList.contains('is-active')) {
      return;
    }

    stage.style.height = stage.offsetHeight + 'px';
    document.getElementById('activityLogPanel').classList.toggle('is-active', !security);
    document.getElementById('securityLogPanel').classList.toggle('is-active', security);
    document.querySelectorAll('[data-log-tab]').forEach(function (item) {
      item.classList.toggle('bg-slate-900', item === tab);
      item.classList.toggle('text-white', item === tab);
      item.classList.toggle('text-slate-600', item !== tab);
      item.classList.toggle('hover:bg-slate-100', item !== tab);
      item.setAttribute('aria-selected', item === tab ? 'true' : 'false');
    });

    requestAnimationFrame(function () {
      stage.style.height = nextPanel.offsetHeight + 'px';
    });

    stage.addEventListener('transitionend', function clearStageHeight(event) {
      if (event.propertyName === 'height') {
        stage.style.height = '';
        stage.removeEventListener('transitionend', clearStageHeight);
      }
    });
  });
});
</script>
