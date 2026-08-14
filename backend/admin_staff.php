<?php
declare(strict_types=1);session_start();require_once "db.php";
if(!isset($_SESSION["admin"])){header("Location: admin_login.php");exit();}
function e(?string $v):string{return htmlspecialchars($v??"",ENT_QUOTES,"UTF-8");}
if(!isset($_SESSION["csrf_token"]))$_SESSION["csrf_token"]=bin2hex(random_bytes(32));$token=$_SESSION["csrf_token"];$ok="";$err="";
if($_SERVER["REQUEST_METHOD"]==="POST"&&isset($_POST["create_staff"])){
 if(!hash_equals($token,(string)($_POST["csrf_token"]??""))){$err="Permintaan tidak sah.";}
 else{$name=trim((string)($_POST["full_name"]??""));$user=trim((string)($_POST["username"]??""));$pass=(string)($_POST["password"]??"");$role=trim((string)($_POST["role"]??""));$phone=trim((string)($_POST["phone"]??""));
  $roles=["Maintenance","Cleaner","Security","Landscape","Supervisor"];
  if($name===""||$user===""||$pass===""||!in_array($role,$roles,true))$err="Sila lengkapkan semua medan wajib.";
  elseif(strlen($pass)<8)$err="Password mesti sekurang-kurangnya 8 aksara.";
  else{$hash=password_hash($pass,PASSWORD_DEFAULT);$stmt=$conn->prepare("INSERT INTO staff(full_name,username,password,role,phone,account_status) VALUES(?,?,?,?,?,'Active')");
   if($stmt){$stmt->bind_param("sssss",$name,$user,$hash,$role,$phone);if($stmt->execute())$ok="Akaun pekerja berjaya dicipta.";elseif($stmt->errno===1062)$err="Username sudah digunakan.";else$err="Akaun tidak dapat dicipta.";$stmt->close();}
  }
 }
}
$list=[];$res=$conn->query("SELECT id,full_name,username,role,phone,account_status FROM staff ORDER BY full_name");if($res)while($r=$res->fetch_assoc())$list[]=$r;$conn->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Staff Management | V23 PMS</title><link rel="stylesheet" href="css/pms.css?v=1"></head>
<body><div class="pms-shell"><aside class="pms-sidebar"><div class="pms-brand"><img src="images/logo.png?v=3"><div><strong>V23 Malawa Ria</strong><small>Property Management System</small></div></div><nav class="pms-nav"><a href="admin_dashboard.php">Dashboard</a><a class="active" href="admin_staff.php">Staff</a><a href="#">Daily Work</a><a href="#">Work Order</a><a href="#">Assets</a><a href="admin_reports.php">Reports</a></nav></aside>
<main class="pms-main"><header class="pms-topbar"><div><h1>Pengurusan Pekerja</h1><p>Cipta akaun pekerja dengan password yang di-hash.</p></div><strong>Admin: <?=e((string)$_SESSION["admin"])?></strong></header>
<?php if($ok):?><div class="alert-ok"><?=e($ok)?></div><?php endif;?><?php if($err):?><div class="alert-err"><?=e($err)?></div><?php endif;?>
<section class="pms-card"><h2>Tambah Akaun Pekerja</h2><form method="post" class="pms-form-grid"><input type="hidden" name="csrf_token" value="<?=e($token)?>"><div class="pms-group"><label>Nama Penuh *</label><input name="full_name" required></div><div class="pms-group"><label>Telefon</label><input name="phone"></div><div class="pms-group"><label>Username *</label><input name="username" required></div><div class="pms-group"><label>Password *</label><input type="password" name="password" minlength="8" required></div><div class="pms-group full"><label>Jawatan *</label><select name="role" required><option value="">-- Pilih --</option><option>Maintenance</option><option>Cleaner</option><option>Security</option><option>Landscape</option><option>Supervisor</option></select></div><div class="full"><button class="btn" name="create_staff">Cipta Akaun</button></div></form></section>
<h2 style="margin-top:28px">Senarai Pekerja</h2><section class="pms-card table-wrap"><table class="pms-table"><thead><tr><th>Nama</th><th>Username</th><th>Jawatan</th><th>Telefon</th><th>Status</th></tr></thead><tbody><?php if(!$list):?><tr><td colspan="5">Belum ada pekerja.</td></tr><?php else:foreach($list as $s):?><tr><td><?=e($s["full_name"])?></td><td><?=e($s["username"])?></td><td><?=e($s["role"])?></td><td><?=e($s["phone"]?: "-")?></td><td><span class="badge <?=$s["account_status"]==="Active"?"active":"inactive"?>"><?=e($s["account_status"])?></span></td></tr><?php endforeach;endif;?></tbody></table></section></main></div></body></html>