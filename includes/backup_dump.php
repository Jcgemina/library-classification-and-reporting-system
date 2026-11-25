<?php

/**
 * Removes DEFINER clauses from a MySQL dump so the objects are created by the
 * account that performs the import. A dump taken as a system account contains
 * DEFINER=`root`@`localhost`, which MySQL 8 rejects for any other account with
 * error 1227 unless that account holds SYSTEM_USER; the application must not
 * hold that privilege.
 *
 * Returns the number of clauses removed. Lines are processed individually
 * because mysqldump always emits a DEFINER clause on a single line, which also
 * keeps large dumps streaming instead of loading them into memory.
 */
function backupStripDefiners(string $dumpFile): int {
    $temporary = $dumpFile . '.no-definer';
    $input = fopen($dumpFile, 'rb');
    if ($input === false) {
        throw new RuntimeException('Could not read the database dump.');
    }
    $output = fopen($temporary, 'wb');
    if ($output === false) {
        fclose($input);
        throw new RuntimeException('Could not rewrite the database dump.');
    }

    $account = '(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|[\w$.%-]+)\s*@\s*(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|[\w$.%:-]+)';
    // A /*!50017 DEFINER=... */ conditional comment disappears with its wrapper.
    $wrapped = '/\/\*!\d{5}\s*DEFINER\s*=\s*' . $account . '\s*\*\//i';
    // A bare DEFINER=... clause (CREATE PROCEDURE/EVENT/TRIGGER/VIEW) is dropped
    // in place, leaving constructs such as the view header
    // /*!50013 ... SQL SECURITY DEFINER */ intact.
    $bare = '/DEFINER\s*=\s*' . $account . '/i';
    $removed = 0;

    try {
        while (($line = fgets($input)) !== false) {
            $filtered = preg_replace($wrapped, '', $line, -1, $replaced);
            if (!is_string($filtered)) {
                throw new RuntimeException('Could not rewrite the database dump.');
            }
            $removed += $replaced;
            $filtered = preg_replace($bare, '', $filtered, -1, $replaced);
            if (!is_string($filtered)) {
                throw new RuntimeException('Could not rewrite the database dump.');
            }
            $removed += $replaced;
            if (fwrite($output, $filtered) === false) {
                throw new RuntimeException('Could not rewrite the database dump.');
            }
        }
        if (!feof($input)) {
            throw new RuntimeException('Could not read the database dump.');
        }
    } catch (Throwable $e) {
        fclose($input);
        fclose($output);
        @unlink($temporary);
        throw $e;
    }
    fclose($input);
    fclose($output);

    if ($removed > 0) {
        if (!rename($temporary, $dumpFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not rewrite the database dump.');
        }
        return $removed;
    }

    @unlink($temporary);
    return 0;
}

/**
 * Lists views, routines, events and triggers in the target schema that belong
 * to a MySQL account other than the one running the restore.
 *
 * MySQL refuses DROP or CREATE for such objects unless the session holds
 * SYSTEM_USER, and the restore account must not hold it. The import therefore
 * fails on the first such statement (error 1227 "SYSTEM_USER") even though the
 * dump itself contains no DEFINER clause. Detecting it up front keeps the
 * restore from changing anything first.
 *
 * @return array<int, array{type: string, name: string, definer: string}>
 */
function backupForeignDefinerObjects(PDO $connection, string $database): array {
    $account = $connection->query('SELECT CURRENT_USER()')->fetchColumn();
    if (!is_string($account) || $account === '') {
        return [];
    }

    $sources = [
        'VIEW' => 'SELECT \'VIEW\' AS object_type, TABLE_NAME AS object_name, DEFINER AS definer FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND DEFINER <> ?',
        'ROUTINE' => 'SELECT ROUTINE_TYPE AS object_type, ROUTINE_NAME AS object_name, DEFINER AS definer FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND DEFINER <> ?',
        'EVENT' => 'SELECT \'EVENT\' AS object_type, EVENT_NAME AS object_name, DEFINER AS definer FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? AND DEFINER <> ?',
        'TRIGGER' => 'SELECT \'TRIGGER\' AS object_type, TRIGGER_NAME AS object_name, DEFINER AS definer FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND DEFINER <> ?',
    ];

    $objects = [];
    foreach ($sources as $type => $sql) {
        try {
            $statement = $connection->prepare($sql);
            $statement->execute([$database, $account]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $objects[] = [
                    'type' => strtoupper((string)($row['object_type'] ?? $type)),
                    'name' => (string)($row['object_name'] ?? ''),
                    'definer' => (string)($row['definer'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            // A preflight check must never block a restore on a metadata query.
            error_log('Restore preflight could not inspect ' . $type . ' ownership: ' . $e->getMessage());
        }
    }

    return $objects;
}

/**
 * Lists the views, routines, events and triggers that a dump recreates.
 *
 * @return array<string, array<int, string>> Object type => object names.
 */
function backupDumpObjectNames(string $dumpFile): array {
    $objects = ['VIEW' => [], 'FUNCTION' => [], 'PROCEDURE' => [], 'EVENT' => [], 'TRIGGER' => []];
    $input = fopen($dumpFile, 'rb');
    if ($input === false) {
        throw new RuntimeException('Could not read the database dump.');
    }

    try {
        while (($line = fgets($input)) !== false) {
            if (preg_match_all('/DROP\s+(VIEW|FUNCTION|PROCEDURE|EVENT|TRIGGER)\s+IF\s+EXISTS\s+`([^`]+)`/i', $line, $matches, PREG_SET_ORDER) !== false) {
                foreach ($matches as $match) {
                    $objects[strtoupper($match[1])][$match[2]] = true;
                }
            }
            // mysqldump wraps CREATE statements in versioned comments, so the
            // prefix tokens are consumed until the object type appears.
            if (preg_match('/^(?:\/\*!\d+\s*|\*\/\s*|CREATE\s*|DEFINER=\S+\s*)+?(FUNCTION|PROCEDURE|EVENT|TRIGGER|VIEW)\s+`([^`]+)`/i', $line, $match) === 1) {
                $objects[strtoupper($match[1])][$match[2]] = true;
            }
        }
        if (!feof($input)) {
            throw new RuntimeException('Could not read the database dump.');
        }
    } finally {
        fclose($input);
    }

    $names = [];
    foreach ($objects as $type => $found) {
        $names[$type] = array_keys($found);
    }
    return $names;
}

/**
 * Drops the named stored objects with a privileged connection so the restore
 * account can create them again. Only objects the archive recreates should be
 * passed in.
 *
 * @param array<string, array<int, string>> $objects Object type => object names.
 * @return array{dropped: array<int, string>, failed: array<int, string>}
 */
function backupDropDatabaseObjects(PDO $connection, string $database, array $objects): array {
    $quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
    $dropped = [];
    $failed = [];
    // Triggers and events first, then views, then the routines views may use.
    foreach (['TRIGGER', 'EVENT', 'VIEW', 'FUNCTION', 'PROCEDURE'] as $type) {
        foreach (array_unique($objects[$type] ?? []) as $name) {
            $quotedName = $quotedDatabase . '.`' . str_replace('`', '``', (string)$name) . '`';
            try {
                $connection->exec('DROP ' . $type . ' IF EXISTS ' . $quotedName);
                $dropped[] = $type . ' ' . $name;
            } catch (Throwable $e) {
                $failed[] = $type . ' ' . $name . ': ' . $e->getMessage();
            }
        }
    }

    return ['dropped' => $dropped, 'failed' => $failed];
}

/**
 * True when the MySQL client output contains only the errors caused by stored
 * objects that another account owns: the blocked DROP (1227) and the following
 * "already exists" error for the same object. Any other error means the import
 * did not complete cleanly.
 *
 * @param array<int, string> $objectNames Names of the objects being preserved.
 */
function backupOnlyOwnershipErrors(string $details, array $objectNames): bool {
    foreach (preg_split('/\R/', $details) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, 'ERROR ')) {
            continue;
        }
        if (preg_match('/^ERROR 1227 \(42000\) at line \d+: .*SYSTEM_USER/i', $line) === 1) {
            continue;
        }
        if (preg_match('/^ERROR (?:1050|1304|1359|1537) \([0-9A-Z]{5}\) at line \d+: /', $line) === 1) {
            foreach ($objectNames as $name) {
                if ($name !== '' && stripos($line, $name) !== false) {
                    continue 2;
                }
            }
        }
        return false;
    }

    return true;
}
