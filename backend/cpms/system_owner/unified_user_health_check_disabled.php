<?php
declare(strict_types=1);

/*
 * CPMS v3.0.3 - Unified User Health Check
 * Upload to: htdocs/cpms/system_owner/unified_user_health_check.php
 * Read-only: this file does not modify database records.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$healthDb = null;

foreach (['db', 'conn', 'mysqli', 'connection'] as $candidate) {
    if (isset(${$candidate}) && ${$candidate} instanceof mysqli) {
        $healthDb = ${$candidate};
        break;
    }
}

if (!$healthDb instanceof mysqli) {
    http_response_code(500);
    exit('CPMS database connection is unavailable.');
}

function cpmsHealthEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cpmsHealthTableExists(mysqli $db, string $table): bool
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

function cpmsHealthColumnExists(
    mysqli $db,
    string $table,
    string $column
): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : [];
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsHealthScalar(mysqli $db, string $sql): int
{
    $result = $db->query($sql);

    if (!$result) {
        throw new RuntimeException($db->error);
    }

    $row = $result->fetch_assoc();

    return (int) ($row['total'] ?? 0);
}

function cpmsHealthRows(mysqli $db, string $sql): array
{
    $result = $db->query($sql);

    if (!$result) {
        throw new RuntimeException($db->error);
    }

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $result->free();

    return $rows;
}

$report = [
    'report' => 'CPMS Unified User Health Check',
    'version' => '3.0.3',
    'generated_at' => date('c'),
    'read_only' => true,
    'status' => 'healthy',
    'summary' => [
        'legacy_records' => 0,
        'unified_records' => 0,
        'assigned_roles' => 0,
        'blocking_issues' => 0,
        'warnings' => 0,
    ],
    'tables' => [],
    'checks' => [],
    'issues' => [],
];

$addCheck = static function (
    string $code,
    string $label,
    bool $passed,
    int $actual,
    ?int $expected = null
) use (&$report): void {
    $report['checks'][] = [
        'code' => $code,
        'label' => $label,
        'passed' => $passed,
        'actual' => $actual,
        'expected' => $expected,
    ];
};

$addIssue = static function (
    string $severity,
    string $code,
    string $message,
    array $details = []
) use (&$report): void {
    $report['issues'][] = array_merge([
        'severity' => $severity,
        'code' => $code,
        'message' => $message,
    ], $details);

    if ($severity === 'blocking') {
        $report['summary']['blocking_issues']++;
        $report['status'] = 'review_required';
    } else {
        $report['summary']['warnings']++;
    }
};

$requiredTables = [
    'system_users',
    'roles',
    'user_roles',
    'admins',
    'property_admins',
    'staff',
    'security_guards',
];

foreach ($requiredTables as $table) {
    $exists = cpmsHealthTableExists($healthDb, $table);
    $report['tables'][$table] = ['exists' => $exists];

    if (!$exists) {
        $addIssue(
            'blocking',
            'missing_table',
            'Required table is missing: ' . $table,
            ['table' => $table]
        );
    }
}

$requiredColumns = [
    'system_users' => [
        'id',
        'property_id',
        'username',
        'password_hash',
        'full_name',
        'status',
        'source_table',
        'source_id',
    ],
    'roles' => ['id', 'role_code', 'status'],
    'user_roles' => [
        'system_user_id',
        'role_id',
        'property_id',
        'status',
    ],
];

foreach ($requiredColumns as $table => $columns) {
    if (empty($report['tables'][$table]['exists'])) {
        continue;
    }

    $missing = [];

    foreach ($columns as $column) {
        if (!cpmsHealthColumnExists($healthDb, $table, $column)) {
            $missing[] = $column;
        }
    }

    $report['tables'][$table]['missing_columns'] = $missing;

    foreach ($missing as $column) {
        $addIssue(
            'blocking',
            'missing_column',
            $table . '.' . $column . ' is missing.',
            ['table' => $table, 'column' => $column]
        );
    }
}

if ($report['summary']['blocking_issues'] === 0) {
    $legacyDefinitions = [
        'admins' => [
            'expected_role' => 'system_owner',
            'property_required' => false,
            'property_expression' => 'NULL',
        ],
        'property_admins' => [
            'expected_role' => null,
            'property_required' => true,
            'property_expression' => 'legacy.property_id',
        ],
        'staff' => [
            'expected_role' => 'staff',
            'property_required' => true,
            'property_expression' => 'legacy.property_id',
        ],
        'security_guards' => [
            'expected_role' => 'security',
            'property_required' => true,
            'property_expression' => 'legacy.property_id',
        ],
    ];

    foreach ($legacyDefinitions as $table => $definition) {
        $legacyCount = cpmsHealthScalar(
            $healthDb,
            'SELECT COUNT(*) AS total FROM `' . $table . '`'
        );
        $unifiedCount = cpmsHealthScalar(
            $healthDb,
            "SELECT COUNT(*) AS total
             FROM system_users
             WHERE source_table = '" . $table . "'"
        );

        $report['tables'][$table]['legacy_records'] = $legacyCount;
        $report['tables'][$table]['unified_records'] = $unifiedCount;
        $report['summary']['legacy_records'] += $legacyCount;
        $report['summary']['unified_records'] += $unifiedCount;

        $addCheck(
            'count_' . $table,
            $table . ' migrated record count',
            $legacyCount === $unifiedCount,
            $unifiedCount,
            $legacyCount
        );

        if ($legacyCount !== $unifiedCount) {
            $addIssue(
                'blocking',
                'record_count_mismatch',
                $table . ' record count does not match.',
                [
                    'table' => $table,
                    'legacy_count' => $legacyCount,
                    'unified_count' => $unifiedCount,
                ]
            );
        }

        $missingRows = cpmsHealthRows(
            $healthDb,
            "SELECT legacy.id, legacy.username
             FROM `" . $table . "` legacy
             LEFT JOIN system_users users
               ON users.source_table = '" . $table . "'
              AND users.source_id = legacy.id
             WHERE users.id IS NULL"
        );

        foreach ($missingRows as $row) {
            $addIssue(
                'blocking',
                'missing_unified_user',
                'Legacy account is missing from system_users.',
                [
                    'table' => $table,
                    'record_id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                ]
            );
        }

        $identityMismatches = cpmsHealthRows(
            $healthDb,
            "SELECT
                legacy.id,
                legacy.username AS legacy_username,
                users.username AS unified_username
             FROM `" . $table . "` legacy
             INNER JOIN system_users users
               ON users.source_table = '" . $table . "'
              AND users.source_id = legacy.id
             WHERE BINARY LOWER(TRIM(users.username))
                 <> BINARY LOWER(TRIM(legacy.username))"
        );

        foreach ($identityMismatches as $row) {
            $addIssue(
                'blocking',
                'username_mismatch',
                'Legacy and unified usernames do not match.',
                [
                    'table' => $table,
                    'record_id' => (int) $row['id'],
                    'legacy_username' => (string) $row['legacy_username'],
                    'unified_username' => (string) $row['unified_username'],
                ]
            );
        }

        $passwordColumn = $table === 'property_admins'
            ? 'password_hash'
            : 'password';

        $passwordMismatches = cpmsHealthRows(
            $healthDb,
            "SELECT legacy.id, legacy.username
             FROM `" . $table . "` legacy
             INNER JOIN system_users users
               ON users.source_table = '" . $table . "'
              AND users.source_id = legacy.id
             WHERE users.password_hash IS NULL
                OR users.password_hash = ''
                OR BINARY users.password_hash
                    <> BINARY legacy.`" . $passwordColumn . "`"
        );

        foreach ($passwordMismatches as $row) {
            $addIssue(
                'blocking',
                'password_mismatch',
                'Password value was not copied exactly.',
                [
                    'table' => $table,
                    'record_id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                ]
            );
        }

        if ($definition['property_required']) {
            $propertyMismatches = cpmsHealthRows(
                $healthDb,
                "SELECT
                    legacy.id,
                    legacy.username,
                    legacy.property_id AS legacy_property_id,
                    users.property_id AS unified_property_id
                 FROM `" . $table . "` legacy
                 INNER JOIN system_users users
                   ON users.source_table = '" . $table . "'
                  AND users.source_id = legacy.id
                 WHERE users.property_id IS NULL
                    OR users.property_id <> legacy.property_id"
            );

            foreach ($propertyMismatches as $row) {
                $addIssue(
                    'blocking',
                    'property_mismatch',
                    'Legacy and unified property_id do not match.',
                    [
                        'table' => $table,
                        'record_id' => (int) $row['id'],
                        'username' => (string) $row['username'],
                        'legacy_property_id' => $row['legacy_property_id'],
                        'unified_property_id' => $row['unified_property_id'],
                    ]
                );
            }
        }

        if ($table === 'property_admins') {
            $expectedRoleExpression = "CASE
                WHEN LOWER(TRIM(legacy.role)) = 'manager' THEN 'manager'
                WHEN LOWER(TRIM(legacy.role)) = 'clerk' THEN 'clerk'
                ELSE 'property_admin'
            END";
        } else {
            $expectedRoleExpression = "'"
                . $definition['expected_role']
                . "'";
        }

        $roleMismatches = cpmsHealthRows(
            $healthDb,
            "SELECT
                legacy.id,
                legacy.username,
                " . $expectedRoleExpression . " AS expected_role,
                (
                    SELECT GROUP_CONCAT(
                        assigned_role.role_code
                        ORDER BY assigned_role.role_code
                    )
                    FROM user_roles current_assignment
                    INNER JOIN roles assigned_role
                      ON assigned_role.id = current_assignment.role_id
                    WHERE current_assignment.system_user_id = users.id
                      AND current_assignment.status = 'active'
                ) AS assigned_roles
             FROM `" . $table . "` legacy
             INNER JOIN system_users users
               ON users.source_table = '" . $table . "'
              AND users.source_id = legacy.id
             WHERE NOT EXISTS (
                SELECT 1
                FROM user_roles expected_assignment
                INNER JOIN roles expected_role_record
                  ON expected_role_record.id = expected_assignment.role_id
                WHERE expected_assignment.system_user_id = users.id
                  AND expected_assignment.status = 'active'
                  AND BINARY expected_role_record.role_code =
                      BINARY " . $expectedRoleExpression . "
             )"
        );

        foreach ($roleMismatches as $row) {
            $addIssue(
                'blocking',
                'role_mismatch',
                'Expected role is not assigned.',
                [
                    'table' => $table,
                    'record_id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                    'expected_role' => (string) $row['expected_role'],
                    'assigned_roles' => (string) ($row['assigned_roles'] ?? ''),
                ]
            );
        }
    }

    $assignedRoles = cpmsHealthScalar(
        $healthDb,
        "SELECT COUNT(*) AS total
         FROM user_roles assignments
         INNER JOIN system_users users
           ON users.id = assignments.system_user_id
         WHERE users.source_table IN (
            'admins',
            'property_admins',
            'staff',
            'security_guards'
         )
           AND assignments.status = 'active'"
    );
    $report['summary']['assigned_roles'] = $assignedRoles;

    $duplicateUsernames = cpmsHealthRows(
        $healthDb,
        "SELECT LOWER(TRIM(username)) AS normalized_value, COUNT(*) AS total
         FROM system_users
         GROUP BY LOWER(TRIM(username))
         HAVING COUNT(*) > 1"
    );

    foreach ($duplicateUsernames as $row) {
        $addIssue(
            'blocking',
            'duplicate_unified_username',
            'Duplicate username exists in system_users.',
            [
                'username' => (string) $row['normalized_value'],
                'count' => (int) $row['total'],
            ]
        );
    }

    $duplicateEmails = cpmsHealthRows(
        $healthDb,
        "SELECT LOWER(TRIM(email)) AS normalized_value, COUNT(*) AS total
         FROM system_users
         WHERE email IS NOT NULL
           AND TRIM(email) <> ''
         GROUP BY LOWER(TRIM(email))
         HAVING COUNT(*) > 1"
    );

    foreach ($duplicateEmails as $row) {
        $addIssue(
            'blocking',
            'duplicate_unified_email',
            'Duplicate email exists in system_users.',
            [
                'email' => (string) $row['normalized_value'],
                'count' => (int) $row['total'],
            ]
        );
    }

    $usersWithoutRoles = cpmsHealthRows(
        $healthDb,
        "SELECT users.id, users.username, users.source_table
         FROM system_users users
         LEFT JOIN user_roles assignments
           ON assignments.system_user_id = users.id
          AND assignments.status = 'active'
         WHERE users.source_table IN (
            'admins',
            'property_admins',
            'staff',
            'security_guards'
         )
         GROUP BY users.id, users.username, users.source_table
         HAVING COUNT(assignments.id) = 0"
    );

    foreach ($usersWithoutRoles as $row) {
        $addIssue(
            'blocking',
            'user_without_role',
            'Migrated user has no active role.',
            [
                'system_user_id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'source_table' => (string) $row['source_table'],
            ]
        );
    }
}

$report['status'] = $report['summary']['blocking_issues'] === 0
    ? 'healthy'
    : 'review_required';

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $filename = 'cpms_unified_user_health_'
        . date('Ymd_His')
        . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

$healthy = $report['status'] === 'healthy';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPMS v3.0.3 Unified User Health Check</title>
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
        .healthy { background: #dcfce7; color: #166534; }
        .review { background: #fee2e2; color: #991b1b; }
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
        .pass { color: #15803d; font-weight: 700; }
        .fail, .blocking { color: #b91c1c; font-weight: 700; }
        .warning { color: #a16207; font-weight: 700; }
        @media (max-width: 760px) {
            .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body>
<main class="wrap">
    <section class="hero">
        <h1>CPMS v3.0.3 — Unified User Health Check</h1>
        <p>Legacy-to-unified account and role verification.</p>
        <span class="badge <?php echo $healthy ? 'healthy' : 'review'; ?>">
            <?php echo $healthy ? 'HEALTHY' : 'REVIEW REQUIRED'; ?>
        </span>
        <br>
        <a class="button" href="?download=1">Download JSON Report</a>
    </section>

    <section class="grid">
        <div class="card metric">
            <strong><?php echo (int) $report['summary']['legacy_records']; ?></strong>
            <span>Legacy records</span>
        </div>
        <div class="card metric">
            <strong><?php echo (int) $report['summary']['unified_records']; ?></strong>
            <span>Unified records</span>
        </div>
        <div class="card metric">
            <strong><?php echo (int) $report['summary']['assigned_roles']; ?></strong>
            <span>Assigned roles</span>
        </div>
        <div class="card metric">
            <strong><?php echo (int) $report['summary']['blocking_issues']; ?></strong>
            <span>Blocking issues</span>
        </div>
    </section>

    <section class="card">
        <h2>Checks</h2>
        <table>
            <thead>
            <tr>
                <th>Check</th>
                <th>Status</th>
                <th>Actual</th>
                <th>Expected</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($report['checks'] as $check): ?>
                <tr>
                    <td><?php echo cpmsHealthEscape((string) $check['label']); ?></td>
                    <td class="<?php echo $check['passed'] ? 'pass' : 'fail'; ?>">
                        <?php echo $check['passed'] ? 'PASS' : 'FAIL'; ?>
                    </td>
                    <td><?php echo (int) $check['actual']; ?></td>
                    <td>
                        <?php
                        echo $check['expected'] === null
                            ? '-'
                            : (int) $check['expected'];
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Issues</h2>
        <?php if (!$report['issues']): ?>
            <p class="pass">No unified user health issues detected.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Severity</th>
                    <th>Code</th>
                    <th>Message</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($report['issues'] as $issue): ?>
                    <tr>
                        <td class="<?php echo cpmsHealthEscape((string) $issue['severity']); ?>">
                            <?php echo cpmsHealthEscape(strtoupper((string) $issue['severity'])); ?>
                        </td>
                        <td><?php echo cpmsHealthEscape((string) $issue['code']); ?></td>
                        <td><?php echo cpmsHealthEscape((string) $issue['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
