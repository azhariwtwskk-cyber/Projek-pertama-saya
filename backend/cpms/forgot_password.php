<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/cpms_bootstrap.php';
require_once __DIR__ . '/includes/password_recovery_service.php';
require_once __DIR__ . '/includes/unified_auth_audit.php';

$message = '';
$error = '';
$login = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $login = trim((string) ($_POST['login'] ?? ''));
    $csrf = $_POST['csrf_token'] ?? null;
    if (!cpmsPasswordRecoveryVerifyCsrf(
        'cpms_forgot_password_csrf',
        is_string($csrf) ? $csrf : null
    )) {
        $error = 'Sesi keselamatan tidak sah. Muat semula halaman.';
    } elseif ($login === '') {
        $error = 'Masukkan username atau alamat e-mel.';
    } else {
        $stmt = $conn->prepare(
            "SELECT id, email, full_name, property_id
             FROM system_users
             WHERE (username = ? OR email = ?) AND status = 'active'
             LIMIT 1"
        );
        $stmt->bind_param('ss', $login, $login);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (is_array($user)
            && filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)
            && cpmsPasswordRecoveryAllowed($conn, (int) $user['id'])) {
            $settings = cpmsPasswordRecoverySettings($conn);
            if ($settings) {
                try {
                    $token = cpmsPasswordRecoveryCreate(
                        $conn,
                        (int) $user['id'],
                        (int) ($settings['reset_lifetime_minutes'] ?? 30)
                    );
                    $url = cpmsPasswordRecoveryBaseUrl()
                        . '/reset_password.php?token='
                        . rawurlencode(
                            $token['selector'] . ':' . $token['verifier']
                        );
                    $sent = cpmsPasswordRecoverySendMail(
                        (string) $user['email'],
                        (string) $user['full_name'],
                        $url,
                        $settings
                    );
                    cpmsUnifiedAuditEvent(
                        $conn,
                        $sent
                            ? 'password_reset_requested'
                            : 'password_reset_delivery_failed',
                        (int) $user['id'],
                        (int) $user['property_id'] > 0
                            ? (int) $user['property_id'] : null,
                        null,
                        $sent
                            ? 'Password reset link sent.'
                            : 'Password reset email delivery failed.'
                    );
                } catch (Throwable $exception) {
                    if (function_exists('cpmsFoundationLog')) {
                        cpmsFoundationLog(
                            'Password recovery request failed: '
                            . $exception->getMessage()
                        );
                    }
                }
            }
        }
        $message = 'Jika akaun dan e-mel tersebut sah, pautan reset akan '
            . 'dihantar. Sila periksa Inbox dan Spam.';
        $login = '';
    }
}
$csrfToken = cpmsPasswordRecoveryCsrf('cpms_forgot_password_csrf');
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lupa Kata Laluan | CPMS</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;
font:16px Arial;color:#172033;background:linear-gradient(145deg,#eaf1fa,#f8fafc)}
.card{width:min(470px,calc(100% - 28px));background:#fff;padding:34px;border-radius:20px;
box-shadow:0 22px 55px rgba(15,35,66,.14)}h1{margin:0 0 10px;color:#0f2342}
p{line-height:1.55;color:#64748b}.alert{padding:13px;border-radius:9px;margin:16px 0}
.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}
label{display:block;font-weight:700;margin:20px 0 7px}input{width:100%;padding:13px;
border:1px solid #cbd5e1;border-radius:10px;font-size:16px}
button{width:100%;margin-top:18px;padding:13px;border:0;border-radius:10px;
background:#174789;color:#fff;font-weight:800;font-size:15px}.back{text-align:center;margin-top:20px}
a{color:#174789;font-weight:700}</style></head><body><main class="card">
<h1>Lupa Kata Laluan</h1>
<p>Masukkan username atau e-mel yang didaftarkan dalam CPMS.</p>
<?php if ($message): ?><div class="alert ok"><?= cpmsPasswordRecoveryEscape($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert bad"><?= cpmsPasswordRecoveryEscape($error) ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsPasswordRecoveryEscape($csrfToken) ?>">
<label for="login">Username atau e-mel</label>
<input id="login" name="login" value="<?= cpmsPasswordRecoveryEscape($login) ?>"
autocomplete="username" required>
<button type="submit">Hantar Pautan Reset</button>
</form>
<p class="back"><a href="login.php">Kembali ke Log Masuk</a></p>
</main></body></html>
