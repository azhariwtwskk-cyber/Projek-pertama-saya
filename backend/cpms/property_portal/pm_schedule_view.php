<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once dirname(__DIR__).'/includes/preventive_maintenance_service.php';
cpmsRequire('maintenance.view',$conn);
$propertyId=(int)($_SESSION['cpms_property_id']??0);$scheduleId=(int)($_GET['id']??$_POST['schedule_id']??0);
$schedule=cpmsPmFindSchedule($conn,$propertyId,$scheduleId);if(!$schedule){http_response_code(404);exit('Maintenance schedule not found.');}
$errors=[];$success=isset($_GET['created'])?'Maintenance schedule created.':'';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!cpmsPmVerifyCsrf($_POST['csrf_token']??null)){$errors[]='Security session is invalid.';}
 elseif(isset($_POST['verify_work_log'])){
  cpmsRequire('maintenance.verify',$conn);
  if(cpmsPmVerifyWorkLog($conn,$propertyId,$scheduleId,(int)($_POST['work_log_id']??0))){
   $success='Maintenance completion verified.';
  }else{$errors[]='Work log could not be verified or was already verified.';}
 }
 elseif (
  empty($_SESSION['cpms_pm_completion_token'])
  || empty($_POST['completion_token'])
  || !hash_equals(
      (string) $_SESSION['cpms_pm_completion_token'],
      (string) $_POST['completion_token']
  )
 ){$errors[]='This maintenance completion was already submitted. Please use the new form.';}
 else{
  unset($_SESSION['cpms_pm_completion_token']);
  cpmsRequire('maintenance.complete',$conn);
  try{cpmsPmComplete($conn,$propertyId,$schedule,$_POST,$_FILES['evidence']??[],dirname(__DIR__).'/uploads/preventive_maintenance');$success='Maintenance completion recorded. Next due date updated.';}catch(Throwable $e){$errors[]=$e->getMessage();}
 }
 $schedule=cpmsPmFindSchedule($conn,$propertyId,$scheduleId);
}
if(empty($_SESSION['cpms_pm_completion_token'])){
 $_SESSION['cpms_pm_completion_token']=bin2hex(random_bytes(24));
}
$history=cpmsPmHistory($conn,$propertyId,$scheduleId);$pageTitle='Maintenance Schedule';$activeMenu='preventive_maintenance';
require __DIR__.'/includes/layout_header.php';require __DIR__.'/includes/layout_sidebar.php';require __DIR__.'/includes/layout_topbar.php';
?>
<style>.pm-wrap{padding:24px}.pm-card{background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:20px;margin:14px 0}.pm-info{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.pm-info div{background:#f8fafc;padding:13px;border-radius:9px}.pm-info span{display:block;color:#64748b;font-size:12px}.pm-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.pm-field.full{grid-column:1/-1}.pm-field label{display:block;font-weight:800;margin-bottom:5px}.pm-field input,.pm-field select,.pm-field textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:8px}.pm-btn{border:0;background:#173b73;color:#fff;padding:10px 14px;border-radius:8px;font-weight:800}.pm-alert{padding:12px;border-radius:9px;background:#dcfce7;color:#166534}.pm-error{background:#fee2e2;color:#991b1b}.pm-table{width:100%;border-collapse:collapse}.pm-table th,.pm-table td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.pm-thumb{width:90px;height:65px;object-fit:cover}@media(max-width:800px){.pm-wrap{padding:14px}.pm-info{grid-template-columns:repeat(2,1fr)}.pm-grid{grid-template-columns:1fr}.pm-field.full{grid-column:auto}.pm-table{display:block;overflow:auto}}</style>
<div class="pm-wrap"><a href="preventive_maintenance.php">← Preventive Maintenance</a><h1><?php echo cpmsPmEscape((string)$schedule['schedule_name']);?></h1>
<?php if($success):?><div class="pm-alert"><?php echo cpmsPmEscape($success);?></div><?php endif;?><?php foreach($errors as $error):?><div class="pm-alert pm-error"><?php echo cpmsPmEscape($error);?></div><?php endforeach;?>
<section class="pm-card"><div class="pm-info">
<div><span>Asset</span><strong><?php echo cpmsPmEscape((string)$schedule['asset_name']);?></strong></div><div><span>Status</span><strong><?php echo cpmsPmEscape((string)$schedule['due_status']);?></strong></div>
<div><span>Next Due</span><strong><?php echo cpmsPmEscape((string)$schedule['next_due_date']);?></strong></div><div><span>Frequency</span><strong>Every <?php echo (int)$schedule['frequency_interval'].' '.cpmsPmEscape((string)$schedule['frequency_unit']);?></strong></div>
<div><span>Assigned To</span><strong><?php echo cpmsPmEscape((string)($schedule['assigned_name']?:$schedule['vendor_name']?:'-'));?></strong></div><div><span>Priority</span><strong><?php echo cpmsPmEscape((string)$schedule['priority']);?></strong></div>
<div><span>Last Completed</span><strong><?php echo cpmsPmEscape((string)($schedule['last_completed_date']?:'-'));?></strong></div><div><span>Estimated Cost</span><strong>RM <?php echo number_format((float)$schedule['estimated_cost'],2);?></strong></div>
</div><h3>Instructions</h3><p><?php echo nl2br(cpmsPmEscape((string)($schedule['instructions']?:'No instructions.')));?></p></section>
<?php if(cpmsCan('maintenance.complete',$conn)):?><section class="pm-card"><h2>Record Maintenance Completion</h2><form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?php echo cpmsPmEscape(cpmsPmCsrfToken());?>"><input type="hidden" name="completion_token" value="<?php echo cpmsPmEscape((string)$_SESSION['cpms_pm_completion_token']);?>"><input type="hidden" name="schedule_id" value="<?php echo $scheduleId;?>"><div class="pm-grid">
<div class="pm-field"><label>Completed Date</label><input type="date" name="completed_date" value="<?php echo date('Y-m-d');?>" required></div>
<div class="pm-field"><label>Result</label><select name="result"><option>Completed</option><option>Completed with Finding</option><option>Failed</option></select></div>
<div class="pm-field full"><label>Work Notes</label><textarea name="work_notes" rows="4" required></textarea></div>
<div class="pm-field"><label>Meter Reading</label><input name="meter_reading"></div><div class="pm-field"><label>Actual Cost (RM)</label><input type="number" name="actual_cost" min="0" step="0.01"></div>
<div class="pm-field"><label>Downtime (minutes)</label><input type="number" name="downtime_minutes" min="0"></div><div class="pm-field"><label>Evidence Image</label><input type="file" name="evidence" accept="image/jpeg,image/png,image/webp"></div>
<div class="pm-field full"><label>Image Caption</label><input name="caption"></div></div><button class="pm-btn" id="pmCompleteButton">Complete & Calculate Next Due</button></form></section><?php endif;?>
<section class="pm-card"><h2>Maintenance History</h2><table class="pm-table"><thead><tr><th>Date</th><th>Result</th><th>Notes</th><th>Cost</th><th>Next Due</th><th>Evidence</th><th>Verification</th></tr></thead><tbody>
<?php if(!$history):?><tr><td colspan="7">No maintenance completion recorded.</td></tr><?php endif;?><?php foreach($history as $log):?><tr><td><?php echo cpmsPmEscape((string)$log['completed_date']);?></td><td><?php echo cpmsPmEscape((string)$log['result']);?></td><td><?php echo cpmsPmEscape((string)$log['work_notes']);?></td><td>RM <?php echo number_format((float)$log['actual_cost'],2);?></td><td><?php echo cpmsPmEscape((string)$log['next_due_date']);?></td><td><?php if($log['evidence_path']):?><a href="../<?php echo cpmsPmEscape((string)$log['evidence_path']);?>" target="_blank"><img class="pm-thumb" src="../<?php echo cpmsPmEscape((string)$log['evidence_path']);?>"></a><?php else:?>-<?php endif;?></td>
<td><?php if($log['verified_at']):?><strong>Verified</strong><br><small><?php echo cpmsPmEscape((string)$log['verified_by_name']);?><br><?php echo cpmsPmEscape((string)$log['verified_at']);?></small>
<?php elseif(cpmsCan('maintenance.verify',$conn)):?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo cpmsPmEscape(cpmsPmCsrfToken());?>"><input type="hidden" name="work_log_id" value="<?php echo (int)$log['id'];?>"><button class="pm-btn" name="verify_work_log" value="1">Verify</button></form><?php else:?>Pending<?php endif;?></td></tr><?php endforeach;?>
</tbody></table></section></div>
<script>
document.querySelectorAll('form[enctype="multipart/form-data"]').forEach(function(form){
 form.addEventListener('submit',function(){
  var button=document.getElementById('pmCompleteButton');
  if(button){button.disabled=true;button.textContent='Saving...';}
 });
});
</script>
<?php require __DIR__.'/includes/layout_footer.php';?>
