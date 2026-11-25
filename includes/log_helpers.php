<?php

function logFilterParts(string $alias, string $search, ?int $userId, string $severity, string $fromTimestamp, string $untilTimestamp): array {
    $where = ["{$alias}.created_at >= :from_timestamp", "{$alias}.created_at < :until_timestamp"];
    $params = [':from_timestamp' => $fromTimestamp, ':until_timestamp' => $untilTimestamp];

    if ($search !== '') {
        $eventColumn = $alias === 'a' ? 'action' : 'event_type';
        $eventCondition = "({$alias}.{$eventColumn} LIKE :search_event OR REPLACE(REPLACE({$alias}.{$eventColumn}, '_', ' '), '-', ' ') LIKE :search_event_label)";
        $usernameCondition = $alias === 's' ? " OR {$alias}.username LIKE :search_username" : '';
        $searchValue = '%' . $search . '%';

        $where[] = "({$alias}.description LIKE :search_description OR {$alias}.ip_address LIKE :search_ip OR {$eventCondition} OR u.full_name LIKE :search_user{$usernameCondition})";
        $params[':search_description'] = $searchValue;
        $params[':search_ip'] = $searchValue;
        $params[':search_event'] = $searchValue;
        $params[':search_event_label'] = $searchValue;
        $params[':search_user'] = $searchValue;

        if ($alias === 's') {
            $params[':search_username'] = $searchValue;
        }
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
