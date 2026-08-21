<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/checklist_service.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';
require_once dirname(__DIR__) . '/includes/corrective_action_service.php';
require_once dirname(__DIR__) . '/includes/non_compliance_action_service.php';

cpmsRequire('inspection.checklist.create_actions', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$inspectionId = (int) (
    $_GET['inspection_id']
    ?? $_POST['inspection_id']
    ?? 0
);
$inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
if (!$inspection) {
    http_response_code(404);
    exit('Inspection not found.');
}
$assessment = cpmsChecklistAssessment(
    $conn,
    $propertyId,
    $inspectionId
);
if (!$assessment || $assessment['overall_result'] !== 'Non-Compliant') {
    http_response_code(409);
    exit('A completed Non-Compliant checklist is required.');
}

$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsChecklistVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security session is invalid.';
    } else {
        try {
            $created = cpmsCreateActionsFromFailedItems(
                $conn,
                $propertyId,
                $inspectionId,
                $_POST['failed_items'] ?? [],
                $_POST
            );
            $success = count($created)
                . ' Corrective Action(s) created and assigned.';
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$failedItems = cpmsFailedChecklistItems(
    $conn,
    $propertyId,
    $inspectionId
);
$assignees = cpmsActionAssignees($conn, $propertyId);
$pageTitle = 'Create Actions from Non-Compliance';
$activeMenu = 'inspection';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.nc-wrap{padding:24px}.nc-card{background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:20px;margin:14px 0}.nc-item{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:start;padding:14px;border-bottom:1px solid #e2e8f0}.nc-item:last-child{border-bottom:0}.nc-fail{color:#b91c1c;font-weight:900}.nc-created{color:#15803d;font-weight:800}.nc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.nc-grid select,.nc-grid input{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:9px}.nc-btn{border:0;background:#173b73;color:#fff;padding:11px 15px;border-radius:9px;font-weight:800}.nc-alert{padding:12px;border-radius:9px;background:#dcfce7;color:#166534}.nc-error{background:#fee2e2;color:#991b1b}@media(max-width:700px){.nc-wrap{padding:14px}.nc-grid{grid-template-columns:1fr}.nc-item{grid-template-columns:auto 1fr}}
</style>
<div class="nc-wrap">
<a href="inspection_checklist.php?inspection_id=<?php echo $inspectionId; ?>">← Checklist</a>
<h1>Auto Corrective Action</h1>
<p><?php echo cpmsChecklistEscape((string) $inspection['inspection_no']); ?>
 · Select failed items and assign rectification.</p>
<?php if ($success): ?><div class="nc-alert"><?php echo cpmsChecklistEscape($success); ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="nc-alert nc-error"><?php echo cpmsChecklistEscape($error); ?></div><?php endforeach; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?php echo cpmsChecklistEscape(cpmsChecklistCsrfToken()); ?>">
<input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
<section class="nc-card"><h2>Failed Checklist Items</h2>
<?php if (!$failedItems): ?><p>No failed item found.</p><?php endif; ?>
<?php foreach ($failedItems as $item): ?><label class="nc-item">
<?php if (empty($item['corrective_action_id'])): ?><input type="checkbox" name="failed_items[]" value="<?php echo (int) $item['id']; ?>">
<?php else: ?><span>✓</span><?php endif; ?>
<span><strong><?php echo cpmsChecklistEscape((string) $item['item_name']); ?></strong><br>
<small><?php echo cpmsChecklistEscape((string) ($item['remarks'] ?: 'No remarks')); ?></small></span>
<?php if (empty($item['corrective_action_id'])): ?><span class="nc-fail">FAIL</span>
<?php else: ?><span class="nc-created"><?php echo cpmsChecklistEscape((string) $item['action_no']); ?> · <?php echo cpmsChecklistEscape((string) $item['action_status']); ?></span><?php endif; ?>
</label><?php endforeach; ?></section>

<section class="nc-card"><h2>Assignment</h2><div class="nc-grid">
<label>Staff / Contractor<select name="assigned_system_user_id" required><option value="">-- Select --</option>
<?php foreach ($assignees as $assignee): ?><option value="<?php echo (int) $assignee['id']; ?>"><?php echo cpmsChecklistEscape(ucfirst((string) $assignee['role_code']) . ' — ' . (string) $assignee['full_name']); ?></option><?php endforeach; ?>
</select></label>
<label>Priority<select name="priority"><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select></label>
<label>Due Date<input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required></label>
</div><button class="nc-btn" style="margin-top:15px">Create & Assign Selected Actions</button></section>
</form></div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
