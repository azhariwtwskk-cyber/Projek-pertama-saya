<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/password_reset.php';
$propertyCode = ''; $propertyId = 0;

$sent=false; $errors=[]; $login='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $login=trim((string)($_POST['login']??''));
    if ($login==='') $errors[]='Masukkan username atau e-mel.';
    if (!$errors && 'system_owner'==='property_portal' && $propertyId<1) $errors[]='Kod property tidak sah.';
    if (!$errors) {
        $stmt=$conn->prepare("SELECT id,full_name,email FROM system_users WHERE (username=? OR email=?) AND status='active' AND role IN ('system_owner','system_admin') LIMIT 1");
        if ($stmt) { $stmt->bind_param('ss', $login, $login); $stmt->execute(); $u=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($u) { $token=cpmsPasswordResetCreate($conn,'system_owner',(int)$u['id'],(string)$u['email']);
                if ($token) { $url=cpmsPasswordResetBaseUrl() . '/cpms/system_owner/reset_password.php?token=' . urlencode($token); cpmsPasswordResetSend((string)$u['email'],(string)$u['full_name'],$url); }
            }
        }
        $sent=true;
    }
}
function x(?string $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lupa Kata Laluan | System Owner</title><style>body{font-family:Arial;background:#eef2f7;margin:0;display:grid;place-items:center;min-height:100vh}.card{background:#fff;padding:28px;border-radius:16px;width:min(420px,88vw);box-shadow:0 15px 40px #0002}input,button{width:100%;box-sizing:border-box;padding:12px;margin-top:10px;border-radius:9px;border:1px solid #cbd5e1}button{background:#0f172a;color:#fff;font-weight:700}.ok{background:#dcfce7;padding:12px;border-radius:8px}.err{background:#fee2e2;padding:10px;border-radius:8px}a{color:#2563eb}</style></head><body><div class="card"><h1>Lupa Kata Laluan</h1><p>System Owner</p>
<?php if($sent):?><div class="ok">Jika akaun tersebut wujud, pautan reset telah dihantar ke e-mel berdaftar. Semak juga folder Spam.</div><?php else:?>
<?php foreach($errors as $e):?><div class="err"><?php echo x($e);?></div><?php endforeach;?>
<form method="post"><?php if($propertyCode!==''):?><input type="hidden" name="property" value="<?php echo x($propertyCode);?>"><?php endif;?><input name="login" value="<?php echo x($login);?>" placeholder="Username atau e-mel" required><button type="submit">Hantar Pautan Reset</button></form><?php endif;?>
<p><a href="login.php<?php echo $propertyCode!==''?'?property='.urlencode($propertyCode):'';?>">Kembali ke halaman login</a></p></div></body></html>