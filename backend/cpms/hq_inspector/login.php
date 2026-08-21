<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
if(!empty($_SESSION['hq_inspector_id'])) hqiRedirect('dashboard.php');
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hqiVerify($_POST['csrf_token']??null)) $errors[]='Sesi keselamatan tidak sah.';
 $login=trim((string)($_POST['login']??''));$password=(string)($_POST['password']??'');
 if($login===''||$password==='')$errors[]='Masukkan username/e-mel dan kata laluan.';
 if(!$errors){$s=$conn->prepare('SELECT id,password_hash,status FROM hq_inspectors WHERE username=? OR email=? LIMIT 1');
  if($s){$s->bind_param('ss',$login,$login);$s->execute();$u=$s->get_result()->fetch_assoc();$s->close();
   if($u && $u['status']==='active' && password_verify($password,(string)$u['password_hash'])){session_regenerate_id(true);$_SESSION['hq_inspector_id']=(int)$u['id'];$up=$conn->prepare('UPDATE hq_inspectors SET last_login_at=NOW() WHERE id=?');if($up){$up->bind_param('i',$u['id']);$up->execute();$up->close();}hqiRedirect('dashboard.php');}
  }$errors[]='Maklumat login tidak sah.';}
}
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HQ Inspector Login</title><style>body{font-family:Arial;background:#eef2f7;margin:0;display:grid;place-items:center;min-height:100vh}.card{background:#fff;padding:28px;border-radius:16px;width:min(390px,88vw);box-shadow:0 15px 40px #0002}input,button{width:100%;box-sizing:border-box;padding:12px;margin-top:10px;border-radius:9px;border:1px solid #cbd5e1}button{background:#0f172a;color:#fff;font-weight:700}.err{background:#fee2e2;padding:10px;border-radius:8px}</style></head><body><form class="card" method="post"><h1>HQ Inspector</h1><p>Akses pemeriksaan untuk semua property.</p><?php foreach($errors as $e):?><div class="err"><?php echo hqiEscape($e);?></div><?php endforeach;?><input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf());?>"><input name="login" placeholder="Username atau e-mel" required><input type="password" name="password" placeholder="Kata laluan" required><p style="text-align:right"><a href="forgot_password.php">Lupa kata laluan?</a></p><button>Log Masuk</button></form></body></html>
