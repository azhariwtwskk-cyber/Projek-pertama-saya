<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/push_notification_service.php';

$isOwner = (string) ($_SESSION['cpms_user_role'] ?? '') === 'system_owner'
    || isset($_SESSION['system_owner_id']);
if (!$isOwner) {
    http_response_code(403);
    exit('System Owner access required.');
}

$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals(cpmsPushCsrfToken(), $token)) {
        $message = 'Invalid security token.';
    } else {
        try {
            $keys = cpmsPushGenerateKeys();
            $subject = trim((string) ($_POST['subject'] ?? ''));
            if (!preg_match('#^(mailto:|https://)#i', $subject)) {
                $subject = 'mailto:admin@cpms.local';
            }
            $stmt = $conn->prepare(
                "INSERT INTO cpms_push_settings
                    (id, public_key, private_key_pem, subject, dispatch_token)
                 VALUES (1, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE public_key = VALUES(public_key),
                    private_key_pem = VALUES(private_key_pem),
                    subject = VALUES(subject),
                    dispatch_token = VALUES(dispatch_token)"
            );
            $publicKey = (string) $keys['public_key'];
            $privatePem = (string) $keys['private_key_pem'];
            $dispatchToken = (string) $keys['dispatch_token'];
            $stmt->bind_param(
                'ssss',
                $publicKey, $privatePem, $subject, $dispatchToken
            );
            $stmt->execute();
            $stmt->close();
            $message = 'VAPID keys generated successfully.';
        } catch (Throwable $error) {
            $message = 'Setup failed: ' . $error->getMessage();
        }
    }
}
$result = $conn->query(
    'SELECT public_key, subject, dispatch_token, updated_at
     FROM cpms_push_settings WHERE id = 1'
);
$settings = $result ? $result->fetch_assoc() : null;
?>
<!doctype html>
<html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Push Setup</title>
<style>
body{font:16px Arial;background:#eef3f9;color:#10213d;margin:0;padding:30px}
.card{max-width:820px;margin:auto;background:#fff;padding:28px;border-radius:18px}
input{box-sizing:border-box;width:100%;padding:12px;margin:8px 0 18px}
button{padding:12px 18px;background:#174789;color:#fff;border:0;border-radius:8px}
code{display:block;overflow-wrap:anywhere;background:#eef3f9;padding:12px}
.ok{background:#dcfce7;padding:12px;border-radius:8px}
</style></head><body><main class="card">
<h1>CPMS v3.4.1 Push Setup</h1>
<?php if ($message !== ''): ?><p class="ok"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<p>Jana VAPID key sekali sahaja selepas migration. Menjana semula key akan
memerlukan telefon melanggan notifikasi semula.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars(cpmsPushCsrfToken()) ?>">
<label>Contact subject (e-mail atau HTTPS)
<input name="subject" value="<?= htmlspecialchars((string) ($settings['subject'] ?? 'mailto:admin@cpms.local')) ?>"></label>
<button type="submit"><?= $settings ? 'Generate New Keys' : 'Generate VAPID Keys' ?></button>
</form>
<?php if ($settings): ?>
<h2>Status: Ready</h2>
<p>Public key</p><code><?= htmlspecialchars((string) $settings['public_key']) ?></code>
<p>Dispatch URL (rahsiakan token ini)</p>
<code><?= htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? '')
    . dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/pwa_push_dispatch.php?token='
    . $settings['dispatch_token']) ?></code>
<p>Updated: <?= htmlspecialchars((string) $settings['updated_at']) ?></p>
<?php endif; ?>
</main></body></html>
