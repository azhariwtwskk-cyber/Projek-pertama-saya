<?php
declare(strict_types=1);

/*
 * CPMS v3.0.1 - Legacy User Pre-Migration Audit
 * Read-only diagnostic. No INSERT, UPDATE, ALTER or DELETE is performed.
 * Upload to: htdocs/cpms/system_owner/legacy_user_audit.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$auditDb = null;

foreach (['db', 'conn', 'mysqli', 'connection'] as $candidate) {
    if (isset(${$candidate}) && ${$candidate} instanceof mysqli) {
        $auditDb = ${$candidate};
        break;
    }
}

if (!$auditDb instanceof mysqli) {
    http_response_code(500);
    exit('CPMS database connection is unavailable.');
}

function cpmsAuditEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cpmsAuditTableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : [];
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsAuditColumns(mysqli $db, string $table): array
{
    $stmt = $db->prepare(
        'SELECT column_name, is_nullable
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
         ORDER BY ordinal_position'
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $columns = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[(string) $row['column_name']] = [
                'nullable' => strtoupper((string) $row['is_nullable']) === 'YES',
            ];
        }
    }

    $stmt->close();

    return $columns;
}

function cpmsAuditFirstColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $columns)) {
            return $candidate;
        }
    }

    return null;
}

function cpmsAuditIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function cpmsAuditValue(array $row, ?string $column): string
{
    if ($column === null) {
        return '';
    }

    return trim((string) ($row[$column] ?? ''));
}

$legacyTables = [
    'admins' => [
        'role' => 'system_owner',
        'property_required' => false,
    ],
    'property_admins' => [
        'role' => 'property_admin',
        'property_required' => true,
    ],
    'staff' => [
        'role' => 'staff',
        'property_required' => true,
    ],
    'security_guards' => [
        'role' => 'security',
        'property_required' => true,
    ],
    'residents' => [
        'role' => 'resident',
        'property_required' => true,
    ],
];

$columnCandidates = [
    'id' => ['id', 'user_id', 'admin_id', 'staff_id', 'guard_id', 'resident_id'],
    'username' => ['username', 'user_name', 'login_name'],
    'email' => ['email', 'email_address'],
    'password' => ['password_hash', 'password', 'passwd'],
    'property' => ['property_id'],
    'name' => ['full_name', 'name', 'display_name', 'resident_name'],
    'status' => ['status', 'is_active', 'active'],
    'role' => ['role', 'user_role'],
];

$report = [
    'report' => 'CPMS Legacy User Pre-Migration Audit',
    'version' => '3.0.1',
    'generated_at' => date('c'),
    'read_only' => true,
    'summary' => [
        'tables_expected' => count($legacyTables),
        'tables_found' => 0,
        'records_scanned' => 0,
        'blocking_issues' => 0,
        'warnings' => 0,
        'ready_records' => 0,
    ],
    'tables' => [],
    'duplicates' => [
        'usernames' => [],
        'emails' => [],
    ],
    'issues' => [],
    'migration_ready' => false,
];

$usernameIndex = [];
$emailIndex = [];

foreach ($legacyTables as $table => $definition) {
    if (!cpmsAuditTableExists($auditDb, $table)) {
        $report['tables'][$table] = [
            'exists' => false,
            'records' => 0,
            'ready' => 0,
            'blocking_issues' => 0,
            'warnings' => 1,
        ];
        $report['summary']['warnings']++;
        $report['issues'][] = [
            'severity' => 'warning',
            'table' => $table,
            'record_id' => null,
            'code' => 'table_missing',
            'message' => 'Legacy table was not found.',
        ];
        continue;
    }

    $report['summary']['tables_found']++;
    $columns = cpmsAuditColumns($auditDb, $table);
    $resolved = [];

    foreach ($columnCandidates as $purpose => $candidates) {
        $resolved[$purpose] = cpmsAuditFirstColumn($columns, $candidates);
    }

    $selectColumns = array_values(array_unique(array_filter($resolved)));

    if (!$selectColumns) {
        $report['tables'][$table] = [
            'exists' => true,
            'records' => 0,
            'ready' => 0,
            'blocking_issues' => 1,
            'warnings' => 0,
            'resolved_columns' => $resolved,
        ];
        $report['summary']['blocking_issues']++;
        $report['issues'][] = [
            'severity' => 'blocking',
            'table' => $table,
            'record_id' => null,
            'code' => 'no_supported_columns',
            'message' => 'No supported user columns were detected.',
        ];
        continue;
    }

    $quotedColumns = array_map('cpmsAuditIdentifier', $selectColumns);
    $query = 'SELECT ' . implode(', ', $quotedColumns)
        . ' FROM ' . cpmsAuditIdentifier($table);
    $result = $auditDb->query($query);

    if (!$result) {
        $report['tables'][$table] = [
            'exists' => true,
            'records' => 0,
            'ready' => 0,
            'blocking_issues' => 1,
            'warnings' => 0,
            'resolved_columns' => $resolved,
        ];
        $report['summary']['blocking_issues']++;
        $report['issues'][] = [
            'severity' => 'blocking',
            'table' => $table,
            'record_id' => null,
            'code' => 'read_failed',
            'message' => $auditDb->error,
        ];
        continue;
    }

    $tableSummary = [
        'exists' => true,
        'records' => 0,
        'ready' => 0,
        'blocking_issues' => 0,
        'warnings' => 0,
        'resolved_columns' => $resolved,
    ];

    while ($row = $result->fetch_assoc()) {
        $tableSummary['records']++;
        $report['summary']['records_scanned']++;

        $recordId = cpmsAuditValue($row, $resolved['id']);
        $username = cpmsAuditValue($row, $resolved['username']);
        $email = strtolower(cpmsAuditValue($row, $resolved['email']));
        $password = cpmsAuditValue($row, $resolved['password']);
        $propertyId = cpmsAuditValue($row, $resolved['property']);
        $name = cpmsAuditValue($row, $resolved['name']);
        $recordBlocking = 0;

        $addIssue = static function (
            string $severity,
            string $code,
            string $message
        ) use (
            &$report,
            &$tableSummary,
            &$recordBlocking,
            $table,
            $recordId
        ): void {
            $report['issues'][] = [
                'severity' => $severity,
                'table' => $table,
                'record_id' => $recordId !== '' ? $recordId : null,
                'code' => $code,
                'message' => $message,
            ];

            if ($severity === 'blocking') {
                $recordBlocking++;
                $tableSummary['blocking_issues']++;
                $report['summary']['blocking_issues']++;
            } else {
                $tableSummary['warnings']++;
                $report['summary']['warnings']++;
            }
        };

        if ($resolved['id'] === null || $recordId === '') {
            $addIssue('blocking', 'missing_id', 'Record has no supported primary identifier.');
        }

        if ($username === '' && $email === '') {
            $addIssue(
                'blocking',
                'missing_login_identity',
                'Both username and email are empty.'
            );
        }

        if ($password === '') {
            $addIssue(
                'blocking',
                'missing_password',
                'Password or password hash is empty.'
            );
        }

        if (
            (bool) $definition['property_required']
            && ($resolved['property'] === null || (int) $propertyId <= 0)
        ) {
            $addIssue(
                'blocking',
                'missing_property',
                'A valid property_id is required for this role.'
            );
        }

        if ($name === '') {
            $addIssue('warning', 'missing_name', 'User display name is empty.');
        }

        if ($username !== '') {
            $normalizedUsername = strtolower($username);
            $usernameIndex[$normalizedUsername][] = [
                'table' => $table,
                'record_id' => $recordId,
                'value' => $username,
            ];
        }

        if ($email !== '') {
            $emailIndex[$email][] = [
                'table' => $table,
                'record_id' => $recordId,
                'value' => $email,
            ];
        }

        if ($recordBlocking === 0) {
            $tableSummary['ready']++;
            $report['summary']['ready_records']++;
        }
    }

    $result->free();
    $report['tables'][$table] = $tableSummary;
}

foreach ($usernameIndex as $normalized => $matches) {
    if (count($matches) < 2) {
        continue;
    }

    $report['duplicates']['usernames'][$normalized] = $matches;
    $report['summary']['blocking_issues']++;
    $report['issues'][] = [
        'severity' => 'blocking',
        'table' => 'multiple',
        'record_id' => null,
        'code' => 'duplicate_username',
        'message' => 'Duplicate username found: ' . $matches[0]['value'],
        'matches' => $matches,
    ];
}

foreach ($emailIndex as $normalized => $matches) {
    if (count($matches) < 2) {
        continue;
    }

    $report['duplicates']['emails'][$normalized] = $matches;
    $report['summary']['blocking_issues']++;
    $report['issues'][] = [
        'severity' => 'blocking',
        'table' => 'multiple',
        'record_id' => null,
        'code' => 'duplicate_email',
        'message' => 'Duplicate email found: ' . $normalized,
        'matches' => $matches,
    ];
}

$report['migration_ready'] = $report['summary']['blocking_issues'] === 0;

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $filename = 'cpms_legacy_user_audit_' . date('Ymd_His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

$statusClass = $report['migration_ready'] ? 'ready' : 'blocked';
$statusText = $report['migration_ready']
    ? 'READY FOR MIGRATION'
    : 'REVIEW REQUIRED';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPMS v3.0.1 Legacy User Audit</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f4f7fb;
            color: #172033;
            font-family: Arial, sans-serif;
        }
        .wrap { width: min(1180px, 94%); margin: 32px auto; }
        .hero, .card {
            background: #fff;
            border: 1px solid #dfe6f0;
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(25, 40, 72, .07);
        }
        .hero { padding: 26px; margin-bottom: 18px; }
        .hero h1 { margin: 0 0 8px; font-size: 25px; }
        .hero p { margin: 0; color: #5b667a; }
        .badge {
            display: inline-block;
            margin-top: 16px;
            padding: 9px 13px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 13px;
        }
        .ready { background: #dcfce7; color: #166534; }
        .blocked { background: #fee2e2; color: #991b1b; }
        .grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .metric { padding: 20px; }
        .metric strong { display: block; font-size: 28px; }
        .metric span { color: #687386; font-size: 13px; }
        .card { padding: 22px; margin-bottom: 18px; overflow-x: auto; }
        h2 { margin: 0 0 16px; font-size: 19px; }
        table { width: 100%; border-collapse: collapse; min-width: 760px; }
        th, td {
            padding: 12px 10px;
            border-bottom: 1px solid #e7ebf1;
            text-align: left;
            font-size: 14px;
            vertical-align: top;
        }
        th { color: #4b5565; background: #f8fafc; }
        .button {
            display: inline-block;
            margin-top: 18px;
            padding: 11px 16px;
            border-radius: 9px;
            background: #173b73;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
        }
        .blocking { color: #b91c1c; font-weight: 700; }
        .warning { color: #a16207; font-weight: 700; }
        .ok { color: #15803d; font-weight: 700; }
        .empty { color: #687386; }
        @media (max-width: 760px) {
            .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body>
<main class="wrap">
    <section class="hero">
        <h1>CPMS v3.0.1 — Legacy User Pre-Migration Audit</h1>
        <p>Read-only verification before legacy accounts enter system_users.</p>
        <span class="badge <?php echo cpmsAuditEscape($statusClass); ?>">
            <?php echo cpmsAuditEscape($statusText); ?>
        </span>
        <br>
        <a class="button" href="?download=1">Download JSON Report</a>
    </section>

    <section class="grid">
        <div class="card metric">
            <strong><?php echo (int) $report['summary']['records_scanned']; ?></strong>
            <span>Records scanned</span>
        </div>
        <div class="card metric">
            <strong><?php echo (int) $report['summary']['ready_records']; ?></strong>
            <span>Ready records</span>
        </div>
        <div class="card metric">
            <strong><?php echo (int) $report['summary']['blocking_issues']; ?></strong>
            <span>Blocking issues</span>
        </div>
        <div class="card metric">
            <strong><?php echo (int) $report['summary']['warnings']; ?></strong>
            <span>Warnings</span>
        </div>
    </section>

    <section class="card">
        <h2>Legacy Tables</h2>
        <table>
            <thead>
            <tr>
                <th>Table</th>
                <th>Exists</th>
                <th>Records</th>
                <th>Ready</th>
                <th>Blocking</th>
                <th>Warnings</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($report['tables'] as $table => $summary): ?>
                <tr>
                    <td><?php echo cpmsAuditEscape((string) $table); ?></td>
                    <td><?php echo !empty($summary['exists']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo (int) ($summary['records'] ?? 0); ?></td>
                    <td class="ok"><?php echo (int) ($summary['ready'] ?? 0); ?></td>
                    <td class="blocking">
                        <?php echo (int) ($summary['blocking_issues'] ?? 0); ?>
                    </td>
                    <td class="warning">
                        <?php echo (int) ($summary['warnings'] ?? 0); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Issues</h2>
        <?php if (!$report['issues']): ?>
            <p class="ok">No migration issues detected.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Severity</th>
                    <th>Table</th>
                    <th>Record ID</th>
                    <th>Code</th>
                    <th>Message</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($report['issues'] as $issue): ?>
                    <tr>
                        <td class="<?php echo cpmsAuditEscape((string) $issue['severity']); ?>">
                            <?php echo cpmsAuditEscape(strtoupper((string) $issue['severity'])); ?>
                        </td>
                        <td><?php echo cpmsAuditEscape((string) $issue['table']); ?></td>
                        <td>
                            <?php echo cpmsAuditEscape((string) ($issue['record_id'] ?? '-')); ?>
                        </td>
                        <td><?php echo cpmsAuditEscape((string) $issue['code']); ?></td>
                        <td><?php echo cpmsAuditEscape((string) $issue['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
