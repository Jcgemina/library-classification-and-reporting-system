<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Admin access is required to export logs.');
}

$today = new DateTimeImmutable('today');
$defaultStart = $today->modify('-6 days');
$activityStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', requestString($_GET, 'activity_start_date', requestString($_GET, 'start_date'))) ?: $defaultStart;
$activityEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', requestString($_GET, 'activity_end_date', requestString($_GET, 'end_date'))) ?: $today;
$securityStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', requestString($_GET, 'security_start_date', requestString($_GET, 'start_date'))) ?: $defaultStart;
$securityEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', requestString($_GET, 'security_end_date', requestString($_GET, 'end_date'))) ?: $today;

if ($activityStartDate > $activityEndDate) 
    [$activityStartDate, $activityEndDate] = [$activityEndDate, $activityStartDate];

if ($securityStartDate > $securityEndDate) 
    [$securityStartDate, $securityEndDate] = [$securityEndDate, $securityStartDate];

$activitySearch = trim(requestString($_GET, 'activity_search', requestString($_GET, 'search')));
$activityUserIdInput = requestString($_GET, 'activity_user_id', requestString($_GET, 'user_id'));
$activityUserId = filter_var($activityUserIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$securitySearch = trim(requestString($_GET, 'security_search', requestString($_GET, 'search')));
$securityUserIdInput = requestString($_GET, 'security_user_id', requestString($_GET, 'user_id'));
$securityUserId = filter_var($securityUserIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$securitySeverityInput = requestString($_GET, 'security_severity', requestString($_GET, 'severity'));
$securitySeverity = in_array($securitySeverityInput, ['info', 'warning', 'critical'], true) ? $securitySeverityInput : '';
$activityFromTimestamp = $activityStartDate->format('Y-m-d 00:00:00');
$activityUntilTimestamp = $activityEndDate->modify('+1 day')->format('Y-m-d 00:00:00');
$securityFromTimestamp = $securityStartDate->format('Y-m-d 00:00:00');
$securityUntilTimestamp = $securityEndDate->modify('+1 day')->format('Y-m-d 00:00:00');

function exportFilterParts(string $alias, string $search, ?int $userId, string $severity, string $fromTimestamp, string $untilTimestamp): array {

    $where = ["{$alias}.created_at >= :from_timestamp", "{$alias}.created_at < :until_timestamp"];
    $params = [':from_timestamp' => $fromTimestamp, ':until_timestamp' => $untilTimestamp];

    if ($search !== '') {
        $eventColumn = $alias === 'a' ? 'action' : 'event_type';
        $usernameCondition = $alias === 's' ? " OR {$alias}.username LIKE :search_username" : '';
        $where[] = "({$alias}.description LIKE :search_description OR {$alias}.ip_address LIKE :search_ip OR {$alias}.{$eventColumn} LIKE :search_event OR u.full_name LIKE :search_user{$usernameCondition})";
        $value = '%' . $search . '%';
        foreach (['description', 'ip', 'event', 'user'] as $field) $params[':search_' . $field] = $value;
        if ($alias === 's') $params[':search_username'] = $value;
    }
    if ($userId !== null) { $where[] = "{$alias}.user_id = :user_id"; $params[':user_id'] = $userId; }
    if ($severity !== '') { $where[] = "{$alias}.severity = :severity"; $params[':severity'] = $severity; }
    return [implode(' AND ', $where), $params];
}

if ($pdo === null) {
    http_response_code(503);
    exit('Database connection is not established.');
}

[$activityWhere, $activityParams] = exportFilterParts('a', $activitySearch, $activityUserId, '', $activityFromTimestamp, $activityUntilTimestamp);
[$securityWhere, $securityParams] = exportFilterParts('s', $securitySearch, $securityUserId, $securitySeverity, $securityFromTimestamp, $securityUntilTimestamp);
$activityQuery = $pdo->prepare("SELECT a.created_at, u.full_name, a.action, a.description, a.entity_type, a.entity_id, a.ip_address FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE {$activityWhere} ORDER BY a.created_at DESC");
$activityQuery->execute($activityParams);
$securityQuery = $pdo->prepare("SELECT s.created_at, u.full_name, s.username, s.event_type, s.severity, s.description, s.ip_address, s.user_agent FROM security_logs s LEFT JOIN users u ON u.id = s.user_id WHERE {$securityWhere} ORDER BY s.created_at DESC");
$securityQuery->execute($securityParams);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="appsys-library-logs-' . $activityStartDate->format('Y-m-d') . '-to-' . $activityEndDate->format('Y-m-d') . '.csv"');
$output = fopen('php://output', 'w');
if ($output === false) {
    throw new RuntimeException('Unable to open the CSV output stream.');
}
fputcsv($output, ['Type', 'Time', 'User', 'Event', 'Severity', 'Description', 'Entity', 'IP address', 'User agent']);
foreach ($activityQuery as $log) {
    fputcsv($output, ['Activity', $log['created_at'], $log['full_name'] ?? 'System', $log['action'], '', $log['description'], ($log['entity_type'] ?? '') . (($log['entity_id'] ?? null) !== null && $log['entity_id'] !== '' && $log['entity_id'] !== 0 && $log['entity_id'] !== '0' ? ' #' . $log['entity_id'] : ''), $log['ip_address'] ?? '', '']);
}
foreach ($securityQuery as $log) {
    fputcsv($output, ['Security', $log['created_at'], $log['full_name'] ?? $log['username'] ?? 'Unknown', $log['event_type'], $log['severity'], $log['description'], '', $log['ip_address'] ?? '', $log['user_agent'] ?? '']);
}
fclose($output);