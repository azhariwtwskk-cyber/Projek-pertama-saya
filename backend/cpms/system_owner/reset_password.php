<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/password_reset.php';
$token=trim((string)($_GET['token']??$_POST['token']??''));
$propertyCode=strtoupper(trim((string)($_GET['property']??$_POST['property']??'')));
$record=cpmsPasswordResetValidate($conn,'system_owner',$token); $errors=[]; $done=false;
if ($_SERVER['REQUEST_METHOD']==='POST' && $record) {
 $p=(string)($_POST['password']??''); $c=(string)($_POST['confirm_password']??'');
 if(strlen($p)<8)$errors[]='Kata laluan mesti sekurang-kurangnya 8 aksara.';
 if($p!==$c)$errors[]='Pengesahan kata laluan tidak sepadan.';
 if(!$errors){$done=cpmsPasswordResetComplete($conn,(int)$record['id'],'system_users',(int)$record['user_id'],$p);if(!$done)$errors[]='Reset kata laluan gagal. Cuba semula.';}
}
function x(?string $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset Kata Laluan | System Owner</title><style>body{font-family:Arial;background:#eef2f7;margin:0;display:grid;place-items:center;min-height:100vh}.card{background:#fff;padding:28px;border-radius:16px;width:min(420px,88vw);box-shadow:0 15px 40px #0002}input,button{width:100%;box-sizing:border-box;padding:12px;margin-top:10px;border-radius:9px;border:1px solid #cbd5e1}button{background:#0f172a;color:#fff;font-weight:700}.ok{background:#dcfce7;padding:12px;border-radius:8px}.err{background:#fee2e2;padding:10px;border-radius:8px}a{color:#2563eb}</style></head><body><div class="card"><h1>Tetapkan Kata Laluan Baharu</h1><p>System Owner</p>
<?php if($done):?><div class="ok">Kata laluan berjaya dikemas kini.</div><p><a href="login.php<?php echo $propertyCode!==''?'?property='.urlencode($propertyCode):'';?>">Log masuk sekarang</a></p>
<?php elseif(!$record):?><div class="err">Pautan reset tidak sah, telah digunakan atau tamat tempoh.</div><p><a href="forgot_password.php<?php echo $propertyCode!==''?'?property='.urlencode($propertyCode):'';?>">Buat permintaan baharu</a></p>
<?php else:?><?php foreach($errors as $e):?><div class="err"><?php echo x($e);?></div><?php endforeach;?><form method="post"><input type="hidden" name="token" value="<?php echo x($token);?>"><?php if($propertyCode!==''):?><input type="hidden" name="property" value="<?php echo x($propertyCode);?>"><?php endif;?><input type="password" name="password" placeholder="Kata laluan baharu" minlength="8" required><input type="password" name="confirm_password" placeholder="Ulang kata laluan baharu" minlength="8" required><button type="submit">Simpan Kata Laluan</button></form><?php endif;?></div></body></html>