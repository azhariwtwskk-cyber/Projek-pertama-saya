<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/push_notification_service.php';
$identity = cpmsPushRequireIdentity();
$result = $conn->query('SELECT public_key FROM cpms_push_settings WHERE id = 1');
$row = $result ? $result->fetch_assoc() : null;
if (!$row) {
    cpmsPushJson(['ok' => false, 'error' => 'push_not_configured'], 503);
}
cpmsPushJson([
    'ok' => true,
    'publicKey' => (string) $row['public_key'],
    'csrf' => cpmsPushCsrfToken(),
]);
