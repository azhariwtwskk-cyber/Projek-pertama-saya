<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/preventive_maintenance_service.php';
cpmsRequire('maintenance.manage', $conn);
$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$assignees = cpmsPmAssignees($conn, $propertyId);
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsPmVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security session is invalid.';
    } else {
        try {
            $id = cpmsPmCreateSchedule($conn, $propertyId, $_POST);
            header('Location: pm_schedule_view.php?id=' . $id . '&created=1');
            exit();
        } catch (Throwable $exception) { $errors[] = $exception->getMessage(); }
    }
}
$pageTitle='New Maintenance Schedule';$activeMenu='preventive_maintenance';
require __DIR__.'/includes/layout_header.php';require __DIR__.'/includes/layout_sidebar.php';require __DIR__.'/includes/layout_topbar.php';
?>
<style>.pm-wrap{max-width:920px;margin:auto;padding:24px}.pm-card{background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:22px}.pm-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.pm-field.full{grid-column:1/-1}.pm-field label{display:block;font-weight:800;margin-bottom:6px}.pm-field input,.pm-field select,.pm-field textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:9px}.pm-btn{border:0;background:#173b73;color:#fff;padding:11px 15px;border-radius:9px;font-weight:800}.pm-error{background:#fee2e2;color:#991b1b;padding:12px;border-radius:9px;margin-bottom:12px}@media(max-width:700px){.pm-grid{grid-template-columns:1fr}.pm-field.full{grid-column:auto}}</style>
<div class="pm-wrap"><a href="preventive_maintenance.php">← Preventive Maintenance</a><h1>New Maintenance Schedule</h1>
<?php foreach($errors as $error):?><div class="pm-error"><?php echo cpmsPmEscape($error);?></div><?php endforeach;?>
<form method="post" class="pm-card"><input type="hidden" name="csrf_token" value="<?php echo cpmsPmEscape(cpmsPmCsrfToken()); ?>"><div class="pm-grid">
<div class="pm-field"><label>Asset Name</label><input name="asset_name" required placeholder="Example: Water Pump No. 1"></div>
<div class="pm-field"><label>Asset ID (optional)</label><input type="number" name="asset_id" min="1"></div>
<div class="pm-field full"><label>Schedule Name</label><input name="schedule_name" required placeholder="Monthly pump inspection and servicing"></div>
<div class="pm-field"><label>Maintenance Type</label><select name="maintenance_type"><option>Preventive</option><option>Inspection</option><option>Servicing</option><option>Calibration</option><option>Cleaning</option><option>Testing</option></select></div>
<div class="pm-field"><label>Priority</label><select name="priority"><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select></div>
<div class="pm-field"><label>Frequency</label><select name="frequency_unit"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly" selected>Monthly</option><option value="yearly">Yearly</option></select></div>
<div class="pm-field"><label>Every</label><input type="number" name="frequency_interval" value="1" min="1" max="60" required></div>
<div class="pm-field"><label>First Due Date</label><input type="date" name="next_due_date" value="<?php echo date('Y-m-d',strtotime('+1 month'));?>" required></div>
<div class="pm-field"><label>Assign Staff / Contractor</label><select name="assigned_system_user_id"><option value="0">-- Not assigned --</option><?php foreach($assignees as $a):?><option value="<?php echo (int)$a['id'];?>"><?php echo cpmsPmEscape(ucfirst((string)$a['role_code']).' — '.(string)$a['full_name']);?></option><?php endforeach;?></select></div>
<div class="pm-field"><label>Vendor Name (optional)</label><input name="vendor_name"></div>
<div class="pm-field"><label>Estimated Cost (RM)</label><input type="number" name="estimated_cost" min="0" step="0.01"></div>
<div class="pm-field full"><label>Instructions / Scope</label><textarea name="instructions" rows="5"></textarea></div>
</div><button class="pm-btn">Create Schedule</button></form></div>
<?php require __DIR__.'/includes/layout_footer.php';?>
