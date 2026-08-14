<?php
declare(strict_types=1);

require_once __DIR__ . '/cpms/includes/resident_session.php';
cpmsResidentSessionStart();
require_once __DIR__ . '/db.php';

define('CPMS_ALLOW_RESIDENT_AUTH', true);
require_once __DIR__ . '/cpms/includes/unified_auth.php';

function residentLoginEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function residentLoginToken(): string
{
    if (empty($_SESSION['resident_login_csrf'])) {
        $_SESSION['resident_login_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['resident_login_csrf'];
}

if (!empty($_SESSION['cpms_user_id']) && ($_SESSION['cpms_user_role'] ?? '') === 'resident') {
    header('Location: resident_dashboard.php');
    exit;
}

$errors = [];
$loginValue = '';
$lockedUntil = (int) ($_SESSION['resident_login_locked_until'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginValue = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');

    if ($lockedUntil > time()) {
        $minutes = max(1, (int) ceil(($lockedUntil - time()) / 60));
        $errors[] = 'Terlalu banyak percubaan gagal. Cuba lagi dalam ' . $minutes . ' minit.';
    } elseif (!hash_equals(residentLoginToken(), $token)) {
        $errors[] = 'Sesi keselamatan tidak sah. Muat semula halaman.';
    } elseif ($loginValue === '' || $password === '') {
        $errors[] = 'Masukkan username/e-mel dan kata laluan.';
    } else {
        try {
            $authentication = cpmsUnifiedAuthenticate($conn, $loginValue, $password);
            $role = (string) ($authentication['context']['role'] ?? '');
            $source = (string) ($authentication['user']['source_table'] ?? '');
            if (!empty($authentication['valid']) && $role === 'resident' && $source === 'cpms_residents') {
                session_regenerate_id(true);
                $_SESSION = [];
                foreach (cpmsUnifiedLegacySessionMap($authentication, $conn) as $key => $value) {
                    $_SESSION[$key] = $value;
                }
                $_SESSION['cpms_auth_channel'] = 'resident';
                $_SESSION['resident_login_csrf'] = bin2hex(random_bytes(32));
                $_SESSION['resident_login_attempts'] = 0;
                $_SESSION['resident_login_locked_until'] = 0;

                $userId = (int) ($authentication['user']['id'] ?? 0);
                $residentId = (int) ($authentication['user']['source_id'] ?? 0);
                if ($userId > 0) {
                    $stmt = $conn->prepare('UPDATE system_users SET last_login_at=NOW() WHERE id=?');
                    if ($stmt) { $stmt->bind_param('i', $userId); $stmt->execute(); $stmt->close(); }
                }
                if ($residentId > 0) {
                    $stmt = $conn->prepare('UPDATE cpms_residents SET last_login_at=NOW() WHERE id=?');
                    if ($stmt) { $stmt->bind_param('i', $residentId); $stmt->execute(); $stmt->close(); }
                }
                header('Location: resident_dashboard.php');
                exit;
            }
            $errors[] = 'Maklumat login resident tidak betul atau akaun tidak aktif.';
        } catch (Throwable $error) {
            $errors[] = 'Login tidak dapat diproses. Sila cuba semula.';
        }
        if ($errors) {
            $attempts = (int) ($_SESSION['resident_login_attempts'] ?? 0) + 1;
            $_SESSION['resident_login_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['resident_login_locked_until'] = time() + 900;
                $_SESSION['resident_login_attempts'] = 0;
            }
        }
    }
}
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Log Masuk Resident | CPMS</title><style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#eef3f9;color:#10213d}.resident-login{min-height:100vh;display:grid;grid-template-columns:1.05fr .95fr}.resident-brand{background:linear-gradient(145deg,#102e59,#21589b);color:#fff;padding:70px 9%;display:flex;flex-direction:column;justify-content:center}.resident-brand small{font-weight:800;letter-spacing:3px}.resident-brand h1{font-size:clamp(42px,6vw,78px);line-height:1.02;margin:22px 0}.resident-brand p{font-size:19px;line-height:1.6;max-width:600px}.resident-panel{display:flex;align-items:center;justify-content:center;padding:32px}.resident-card{width:min(100%,520px);background:#fff;border:1px solid #d7e1ee;border-radius:22px;padding:38px;box-shadow:0 24px 60px rgba(18,45,80,.12)}.resident-card h2{font-size:34px;margin:8px 0}.resident-card label{display:grid;gap:7px;margin-top:18px;font-weight:700}.resident-card input{width:100%;padding:14px;border:1px solid #bdcbe0;border-radius:11px;font-size:16px}.resident-card button{width:100%;margin-top:22px;padding:14px;border:0;border-radius:11px;background:#174b8b;color:#fff;font-size:17px;font-weight:800;cursor:pointer}.resident-error,.resident-success{padding:12px;border-radius:10px;margin-top:16px}.resident-error{background:#fee2e2;color:#991b1b}.resident-success{background:#dcfce7;color:#166534}.resident-help{color:#64748b;font-size:13px;margin-top:18px}@media(max-width:800px){.resident-login{grid-template-columns:1fr}.resident-brand{padding:38px 26px}.resident-brand h1{font-size:42px}.resident-panel{padding:22px}.resident-card{padding:26px}}
</style></head><body><main class="resident-login"><section class="resident-brand"><small>CPMS RESIDENT PORTAL</small><h1>Satu rumah.<br>Satu portal.</h1><p>Akses notis, permohonan perkhidmatan dan tempahan fasiliti property anda.</p></section><section class="resident-panel"><div class="resident-card"><small>AKSES KHAS RESIDENT</small><h2>Log Masuk Resident</h2><p>Gunakan akaun yang diberikan oleh pihak pengurusan.</p>
<?php if (isset($_GET['logout'])): ?><div class="resident-success">Anda telah log keluar dengan selamat.</div><?php endif; ?>
<?php if (isset($_GET['expired'])): ?><div class="resident-error">Sesi resident telah tamat. Sila log masuk semula.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="resident-error"><?php echo residentLoginEscape($error); ?></div><?php endforeach; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo residentLoginEscape(residentLoginToken()); ?>"><label>Username atau e-mel<input name="login" value="<?php echo residentLoginEscape($loginValue); ?>" autocomplete="username" required></label><label>Kata laluan<input type="password" name="password" autocomplete="current-password" required></label><button>Log Masuk Resident</button></form><p class="resident-help">Pengurusan, staff dan security sila gunakan Unified Login CPMS.</p></div></section></main></body></html>
