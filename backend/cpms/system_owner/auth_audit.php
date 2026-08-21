<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$events = [];
$result = $conn->query(
    "SELECT
        logs.id,
        logs.event_type,
        logs.user_id,
        logs.property_id,
        logs.description,
        logs.ip_address,
        logs.user_agent,
        logs.created_at,
        users.username,
        users.full_name
     FROM cpms_auth_audit_logs logs
     LEFT JOIN system_users users
        ON logs.user_type = 'system_user'
       AND users.id = logs.user_id
     ORDER BY logs.id DESC
     LIMIT 200"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
    $result->free();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unified Login Audit | CPMS</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#f4f7fb;color:#172033;font-family:Arial,sans-serif}
        .wrap{width:min(1280px,96%);margin:28px auto}
        .card{background:#fff;border:1px solid #dfe6f0;border-radius:15px;padding:22px;box-shadow:0 8px 28px rgba(25,40,72,.07);overflow:auto}
        h1{margin:0 0 7px;font-size:25px}
        p{margin:0 0 20px;color:#64748b}
        table{width:100%;border-collapse:collapse;min-width:1050px}
        th,td{padding:11px 9px;border-bottom:1px solid #e5eaf1;text-align:left;font-size:13px;vertical-align:top}
        th{background:#f8fafc;color:#475569}
        .event{font-weight:800;color:#173b73}
        .fail{color:#b91c1c}
        .success{color:#15803d}
        .agent{max-width:310px;word-break:break-word;color:#64748b}
        a{display:inline-block;margin-bottom:16px;color:#173b73;font-weight:700;text-decoration:none}
    </style>
</head>
<body>
<main class="wrap">
    <a href="dashboard.php">← System Owner Dashboard</a>
    <section class="card">
        <h1>Unified Login Audit Trail</h1>
        <p>Latest 200 authentication events.</p>
        <table>
            <thead>
            <tr>
                <th>Time</th>
                <th>Event</th>
                <th>User</th>
                <th>Role / Details</th>
                <th>Property</th>
                <th>IP</th>
                <th>User Agent</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$events): ?>
                <tr><td colspan="7">No authentication events recorded.</td></tr>
            <?php else: ?>
                <?php foreach ($events as $event): ?>
                    <?php
                    $eventType = (string) $event['event_type'];
                    $eventClass = strpos($eventType, 'success') !== false
                        ? 'success'
                        : (
                            strpos($eventType, 'fail') !== false
                            || strpos($eventType, 'denied') !== false
                            || strpos($eventType, 'blocked') !== false
                                ? 'fail'
                                : ''
                        );
                    ?>
                    <tr>
                        <td><?php echo systemOwnerEscape((string) $event['created_at']); ?></td>
                        <td class="event <?php echo $eventClass; ?>">
                            <?php echo systemOwnerEscape($eventType); ?>
                        </td>
                        <td>
                            <?php
                            echo systemOwnerEscape(
                                trim((string) ($event['full_name'] ?? '')) !== ''
                                    ? (string) $event['full_name']
                                    : (string) ($event['username'] ?? '-')
                            );
                            ?>
                            <br>
                            <small>ID <?php echo (int) ($event['user_id'] ?? 0); ?></small>
                        </td>
                        <td><?php echo systemOwnerEscape((string) ($event['description'] ?? '')); ?></td>
                        <td><?php echo (int) ($event['property_id'] ?? 0) ?: 'Global'; ?></td>
                        <td><?php echo systemOwnerEscape((string) ($event['ip_address'] ?? '')); ?></td>
                        <td class="agent"><?php echo systemOwnerEscape((string) ($event['user_agent'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
