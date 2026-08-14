<?php
declare(strict_types=1);

/*
 * CPMS v3.4.5.1 — defensive GPS Attendance Health page.
 * This page deliberately avoids the normal CPMS error handler so that an
 * early bootstrap/database error can still be diagnosed on shared hosting.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!is_array($error)
        || !in_array((int) $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR,
            E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $message = htmlspecialchars(
        basename((string) $error['file']) . ':' . (int) $error['line']
        . ' — ' . (string) $error['message'],
        ENT_QUOTES,
        'UTF-8'
    );
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" '
        . 'content="width=device-width,initial-scale=1">'
        . '<div style="font:16px Arial;max-width:800px;margin:50px auto;'
        . 'padding:24px;border:1px solid #fecaca;border-radius:14px">'
        . '<h1>CPMS GPS Health Error</h1><p>' . $message . '</p></div>';
});

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once __DIR__ . '/db.php';

    $database = $conn ?? $mysqli ?? null;
    if (!$database instanceof mysqli) {
        throw new RuntimeException('Database connection variable is unavailable.');
    }

    $role = (string) ($_SESSION['cpms_user_role'] ?? '');
    $isOwner = $role === 'system_owner'
        || isset($_SESSION['system_owner_id']);
    if (!$isOwner) {
        http_response_code(403);
        exit('System Owner access required.');
    }

    $tableExists = static function (mysqli $db, string $table): bool {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $table);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['total'] ?? 0) === 1;
    };

    $tables = [
        'cpms_property_geofences',
        'cpms_attendance_sessions',
        'cpms_attendance_events',
        'permissions',
        'role_permissions',
    ];
    $checks = [];
    foreach ($tables as $table) {
        $checks[$table] = $tableExists($database, $table);
    }

    $permissionCount = 0;
    if ($checks['permissions']) {
        $result = $database->query(
            "SELECT COUNT(*) AS total FROM permissions
             WHERE permission_code IN ('attendance.clock',
                 'attendance.view', 'attendance.geofence.manage')"
        );
        if ($result) {
            $row = $result->fetch_assoc();
            $permissionCount = (int) ($row['total'] ?? 0);
            $result->free();
        }
    }

    $configured = 0;
    if ($checks['cpms_property_geofences']) {
        $result = $database->query(
            'SELECT COUNT(*) AS total FROM cpms_property_geofences'
        );
        if ($result) {
            $row = $result->fetch_assoc();
            $configured = (int) ($row['total'] ?? 0);
            $result->free();
        }
    }
    $passed = $checks['cpms_property_geofences']
        && $checks['cpms_attendance_sessions']
        && $checks['cpms_attendance_events']
        && $permissionCount === 3;
} catch (Throwable $exception) {
    http_response_code(500);
    $safeError = htmlspecialchars(
        get_class($exception) . ' — ' . $exception->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    );
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" '
        . 'content="width=device-width,initial-scale=1">'
        . '<div style="font:16px Arial;max-width:800px;margin:50px auto;'
        . 'padding:24px;border:1px solid #fecaca;border-radius:14px">'
        . '<h1>CPMS GPS Health Error</h1><p>' . $safeError . '</p></div>';
    exit;
}
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GPS Attendance Health | CPMS</title><style>
body{font:16px Arial;background:#eef3f9;color:#10213d;padding:22px}
main{max-width:760px;margin:auto}.card{background:#fff;padding:24px;
border-radius:16px;margin-bottom:14px}.pass{color:#087b35}.fail{color:#b42318}
table{width:100%;border-collapse:collapse}td{padding:10px;
border-bottom:1px solid #e2e8f0}a{color:#174789;font-weight:800}
</style></head><body><main><section class="card">
<h1>CPMS v3.4.5.1 — GPS Attendance Health</h1>
<h2 class="<?= $passed ? 'pass' : 'fail' ?>"><?= $passed ? 'PASS' : 'FAIL' ?></h2>
<p>Permissions: <?= $permissionCount ?>/3 · Property configured:
<?= $configured ?></p></section><section class="card"><table>
<?php foreach ($checks as $table => $ready): ?><tr>
<td><?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?></td>
<td class="<?= $ready ? 'pass' : 'fail' ?>"><?= $ready ? 'Ready' : 'Missing' ?></td>
</tr><?php endforeach; ?></table>
<?php if ($passed): ?><p><a href="attendance_geofence_setup.php">
Configure Property Geofence</a></p><?php else: ?>
<p class="fail">Migration v3.4.5 belum lengkap. Jangan konfigurasi geofence
sehingga semua jadual Ready dan Permissions 3/3.</p><?php endif; ?>
</section></main></body></html>
