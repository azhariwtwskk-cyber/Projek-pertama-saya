<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';

$noticeAllowedRoles = ['property_admin', 'manager'];
if (!in_array((string) $currentPropertyRole, $noticeAllowedRoles, true)) {
    cpmsPropertyRequire('resident.announcement.manage');
}

function noticeRedirect(): void { header('Location: announcements.php'); exit; }
function noticeTableExists(mysqli $db,string $table): bool {
    $s=$db->prepare('SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $s->bind_param('s',$table);$s->execute();$ok=(int)($s->get_result()->fetch_assoc()['total']??0)>0;$s->close();return $ok;
}
$flash=$_SESSION['notice_flash']??null;unset($_SESSION['notice_flash']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    $token=is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:null;
    if(!propertyPortalVerifyCsrf($token)){$_SESSION['notice_flash']=['danger','Token keselamatan tidak sah.'];noticeRedirect();}
    $action=trim((string)($_POST['action']??'create'));
    if($action==='status'){
        $id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'Archived');
        if(!in_array($status,['Published','Archived'],true))$status='Archived';
        $s=$conn->prepare('UPDATE cpms_resident_announcements SET status=? WHERE id=? AND property_id=?');
        $s->bind_param('sii',$status,$id,$currentPropertyId);$s->execute();$s->close();
        $_SESSION['notice_flash']=['success','Status notis dikemas kini.'];noticeRedirect();
    }
    $title=trim((string)($_POST['title']??''));$message=trim((string)($_POST['message']??''));
    $type=(string)($_POST['notice_type']??'Announcement');$priority=(string)($_POST['priority']??'Normal');
    $status=(string)($_POST['status']??'Published');$confirm=isset($_POST['requires_confirmation'])?1:0;
    $from=str_replace('T',' ',(string)($_POST['publish_from']??''));
    $untilRaw=str_replace('T',' ',(string)($_POST['publish_until']??''));$until=$untilRaw!==''?$untilRaw.':00':null;
    if(!in_array($type,['Announcement','Emergency'],true))$type='Announcement';
    if(!in_array($priority,['Normal','Important','Critical'],true))$priority='Normal';
    if(!in_array($status,['Draft','Published'],true))$status='Draft';
    if($title===''||$message===''||$from===''){$_SESSION['notice_flash']=['danger','Tajuk, mesej dan tarikh siaran diperlukan.'];noticeRedirect();}
    $from.=':00';$creator=(int)($_SESSION['cpms_user_id']??0);
    $s=$conn->prepare('INSERT INTO cpms_resident_announcements (property_id,title,message,notice_type,priority,requires_confirmation,publish_from,publish_until,status,created_by_system_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $s->bind_param('issssisssi',$currentPropertyId,$title,$message,$type,$priority,$confirm,$from,$until,$status,$creator);$s->execute();$s->close();
    if($status==='Published'&&noticeTableExists($conn,'cpms_resident_notifications')){
        $nTitle=$type==='Emergency'?'NOTIS KECEMASAN: '.$title:$title;$nType=$type==='Emergency'?'emergency_notice':'announcement';$url='resident_announcements.php';
        $s=$conn->prepare('INSERT INTO cpms_resident_notifications (property_id,resident_id,notification_type,title,message,action_url,is_read,created_at) SELECT ?,r.id,?,?,?,?,0,NOW() FROM cpms_residents r WHERE r.property_id=? AND r.is_active=1');
        $s->bind_param('issssi',$currentPropertyId,$nType,$nTitle,$message,$url,$currentPropertyId);$s->execute();$s->close();
    }
    $_SESSION['notice_flash']=['success','Notis resident berjaya disimpan.'];noticeRedirect();
}
$s=$conn->prepare('SELECT COUNT(*) total FROM cpms_residents WHERE property_id=? AND is_active=1');$s->bind_param('i',$currentPropertyId);$s->execute();$residentTotal=(int)($s->get_result()->fetch_assoc()['total']??0);$s->close();
$s=$conn->prepare('SELECT a.*,COUNT(ar.id) viewed_count,SUM(CASE WHEN ar.confirmed_at IS NOT NULL THEN 1 ELSE 0 END) confirmed_count FROM cpms_resident_announcements a LEFT JOIN cpms_resident_announcement_reads ar ON ar.announcement_id=a.id AND ar.property_id=a.property_id WHERE a.property_id=? GROUP BY a.id ORDER BY a.created_at DESC LIMIT 200');
$s->bind_param('i',$currentPropertyId);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
$pageTitle='Resident Notices';$activeMenu='announcements';require __DIR__.'/includes/layout_header.php';require __DIR__.'/includes/layout_sidebar.php';require __DIR__.'/includes/layout_topbar.php';
?>
<style>
.notice-grid{display:grid;grid-template-columns:minmax(300px,420px) 1fr;gap:20px}.notice-card{background:#fff;border:1px solid #dbe3ef;border-radius:16px;padding:22px}.notice-form{display:grid;gap:13px}.notice-form label{display:grid;gap:6px;font-weight:700}.notice-form input,.notice-form select,.notice-form textarea{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px}.notice-form textarea{min-height:150px;resize:vertical}.notice-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.notice-check{display:flex!important;align-items:center;gap:8px}.notice-check input{width:auto}.notice-btn{padding:11px 15px;border:0;border-radius:10px;background:var(--property-primary);color:#fff;font-weight:800;cursor:pointer}.notice-btn.gray{background:#e2e8f0;color:#0f172a}.notice-item{border:1px solid #dbe3ef;border-left:5px solid var(--property-primary);border-radius:13px;padding:16px;margin-bottom:13px}.notice-item.emergency{border-left-color:#dc2626;background:#fff7f7}.notice-head{display:flex;justify-content:space-between;gap:15px}.notice-badge{display:inline-block;padding:4px 9px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:800}.notice-stats{display:flex;gap:15px;flex-wrap:wrap;color:#64748b;font-size:13px}.notice-flash{padding:13px;border-radius:10px;margin-bottom:15px}.notice-flash.success{background:#dcfce7;color:#166534}.notice-flash.danger{background:#fee2e2;color:#991b1b}@media(max-width:900px){.notice-grid{grid-template-columns:1fr}.notice-row{grid-template-columns:1fr}}
</style>
<section class="page-heading"><div><span class="section-label">RESIDENT COMMUNICATION</span><h1>Announcement & Emergency Notice</h1><p>Notis rasmi untuk resident <strong><?php echo propertyPortalEscape($currentPropertyName);?></strong>.</p></div></section>
<?php if(is_array($flash)):?><div class="notice-flash <?php echo propertyPortalEscape((string)$flash[0]);?>"><?php echo propertyPortalEscape((string)$flash[1]);?></div><?php endif;?>
<div class="notice-grid"><section class="notice-card"><h2>Cipta Notis</h2><form method="post" class="notice-form"><input type="hidden" name="csrf_token" value="<?php echo propertyPortalEscape(propertyPortalCsrfToken());?>"><input type="hidden" name="action" value="create"><label>Jenis<select name="notice_type"><option>Announcement</option><option>Emergency</option></select></label><label>Tajuk<input name="title" maxlength="180" required></label><label>Mesej<textarea name="message" required></textarea></label><div class="notice-row"><label>Keutamaan<select name="priority"><option>Normal</option><option>Important</option><option>Critical</option></select></label><label>Status<select name="status"><option>Published</option><option>Draft</option></select></label></div><div class="notice-row"><label>Mula siaran<input type="datetime-local" name="publish_from" value="<?php echo date('Y-m-d\\TH:i');?>" required></label><label>Tamat siaran<input type="datetime-local" name="publish_until"></label></div><label class="notice-check"><input type="checkbox" name="requires_confirmation" value="1"> Wajib resident sahkan dibaca</label><button class="notice-btn">Simpan & Siarkan</button></form></section>
<section class="notice-card"><h2>Rekod Notis</h2><p><?php echo $residentTotal;?> resident aktif</p><?php if(!$rows):?><p>Tiada notis direkodkan.</p><?php endif;?><?php foreach($rows as $row):?><article class="notice-item <?php echo $row['notice_type']==='Emergency'?'emergency':'';?>"><div class="notice-head"><div><span class="notice-badge"><?php echo propertyPortalEscape($row['notice_type']);?></span> <span class="notice-badge"><?php echo propertyPortalEscape($row['priority']);?></span><h3><?php echo propertyPortalEscape($row['title']);?></h3></div><strong><?php echo propertyPortalEscape($row['status']);?></strong></div><p><?php echo nl2br(propertyPortalEscape($row['message']));?></p><div class="notice-stats"><span>Dilihat: <?php echo (int)$row['viewed_count'];?>/<?php echo $residentTotal;?></span><span>Disahkan: <?php echo (int)$row['confirmed_count'];?>/<?php echo $residentTotal;?></span><span>Siaran: <?php echo propertyPortalEscape($row['publish_from']);?></span></div><form method="post" style="margin-top:12px"><input type="hidden" name="csrf_token" value="<?php echo propertyPortalEscape(propertyPortalCsrfToken());?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo (int)$row['id'];?>"><input type="hidden" name="status" value="<?php echo $row['status']==='Published'?'Archived':'Published';?>"><button class="notice-btn gray"><?php echo $row['status']==='Published'?'Archive':'Publish';?></button></form></article><?php endforeach;?></section></div>
<?php require __DIR__.'/includes/layout_footer.php';?>
