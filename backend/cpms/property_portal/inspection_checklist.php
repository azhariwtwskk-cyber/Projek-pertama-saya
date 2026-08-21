<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/checklist_service.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';
cpmsRequire('inspection.checklist.view', $conn);
$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$inspectionId = (int) ($_GET['inspection_id'] ?? $_POST['inspection_id'] ?? 0);
$inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
if (!$inspection) { http_response_code(404); exit('Inspection not found.'); }
$errors = []; $success = '';
$assessment = cpmsChecklistAssessment($conn, $propertyId, $inspectionId);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsChecklistVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security session is invalid.';
    } elseif (isset($_POST['apply_template'])) {
        cpmsRequire('inspection.checklist.assess', $conn);
        try {
            cpmsChecklistApply($conn, $propertyId, $inspectionId, (int) ($_POST['template_id'] ?? 0));
            $success = 'Checklist applied to inspection.';
        } catch (Throwable $exception) { $errors[] = $exception->getMessage(); }
    } elseif (isset($_POST['save_score']) && $assessment) {
        cpmsRequire('inspection.checklist.assess', $conn);
        try {
            $score = cpmsChecklistScore($conn, $propertyId, $assessment, $_POST['result'] ?? [], $_POST['remarks'] ?? []);
            $success = 'Score saved: ' . $score['percent'] . '% — ' . $score['result'];
        } catch (Throwable $exception) { $errors[] = $exception->getMessage(); }
    }
    $assessment = cpmsChecklistAssessment($conn, $propertyId, $inspectionId);
}
$items = $assessment ? cpmsChecklistAssessmentItems($conn, $propertyId, (int) $assessment['id']) : [];
$templates = cpmsChecklistTemplates($conn, $propertyId);
$pageTitle = 'Inspection Checklist'; $activeMenu = 'inspection';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.ck-wrap{padding:24px}.ck-card{background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:20px;margin:14px 0}.ck-score{font-size:38px;font-weight:900;color:#173b73}.ck-table{width:100%;border-collapse:collapse}.ck-table th,.ck-table td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left}.ck-table select,.ck-table input{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px}.ck-btn{border:0;background:#173b73;color:#fff;padding:10px 14px;border-radius:9px;font-weight:800}.ck-alert{padding:12px;border-radius:9px;background:#dcfce7;color:#166534}.ck-error{background:#fee2e2;color:#991b1b}@media(max-width:700px){.ck-wrap{padding:14px}.ck-table{display:block;overflow:auto}}
</style>
<div class="ck-wrap"><a href="inspection_view.php?id=<?php echo $inspectionId; ?>">← Inspection</a>
<h1>Checklist — <?php echo cpmsChecklistEscape((string) $inspection['inspection_no']); ?></h1>
<?php if ($success): ?><div class="ck-alert"><?php echo cpmsChecklistEscape($success); ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="ck-alert ck-error"><?php echo cpmsChecklistEscape($error); ?></div><?php endforeach; ?>
<?php if (!$assessment): ?><section class="ck-card"><h2>Apply Template</h2>
<?php if (!$templates): ?><p>No active template. <a href="checklist_template_create.php">Create template</a>.</p>
<?php elseif (cpmsCan('inspection.checklist.assess', $conn)): ?><form method="post">
<input type="hidden" name="csrf_token" value="<?php echo cpmsChecklistEscape(cpmsChecklistCsrfToken()); ?>"><input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
<select name="template_id" required><?php foreach ($templates as $template): if ($template['status'] !== 'active') continue; ?><option value="<?php echo (int) $template['id']; ?>"><?php echo cpmsChecklistEscape((string) $template['template_name']); ?> (<?php echo (int) $template['item_count']; ?> items)</option><?php endforeach; ?></select>
<button class="ck-btn" name="apply_template" value="1">Apply Checklist</button></form><?php endif; ?></section>
<?php else: ?><section class="ck-card"><h2><?php echo cpmsChecklistEscape((string) $assessment['template_name']); ?></h2>
<div class="ck-score"><?php echo cpmsChecklistEscape((string) $assessment['score_percent']); ?>%</div>
<strong><?php echo cpmsChecklistEscape((string) $assessment['overall_result']); ?></strong> · Passing score <?php echo cpmsChecklistEscape((string) $assessment['passing_score']); ?>%</section>
<?php if (
    $assessment['overall_result'] === 'Non-Compliant'
    && cpmsCan('inspection.checklist.create_actions', $conn)
): ?>
<section class="ck-card">
    <h2>Non-Compliance Action</h2>
    <p>Create assigned Corrective Actions automatically from failed checklist items.</p>
    <a class="ck-btn" href="inspection_checklist_actions.php?inspection_id=<?php echo $inspectionId; ?>">
        Create Corrective Actions from Failed Items
    </a>
</section>
<?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo cpmsChecklistEscape(cpmsChecklistCsrfToken()); ?>"><input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
<section class="ck-card"><table class="ck-table"><thead><tr><th>#</th><th>Checklist Item</th><th>Weight</th><th>Result</th><th>Remarks</th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?php echo (int) $item['item_order']; ?></td>
<td><?php echo cpmsChecklistEscape((string) $item['item_name']); ?><?php echo (int) $item['is_required'] ? ' *' : ''; ?></td><td><?php echo cpmsChecklistEscape((string) $item['weight']); ?></td>
<td><select name="result[<?php echo (int) $item['id']; ?>]" required><option value="">Select</option><?php foreach (['Pass','Fail','N/A'] as $result): ?><option <?php echo $item['result'] === $result ? 'selected' : ''; ?>><?php echo $result; ?></option><?php endforeach; ?></select></td>
<td><input name="remarks[<?php echo (int) $item['id']; ?>]" value="<?php echo cpmsChecklistEscape((string) $item['remarks']); ?>"></td></tr><?php endforeach; ?>
</tbody></table><?php if (cpmsCan('inspection.checklist.assess', $conn)): ?><button class="ck-btn" name="save_score" value="1">Calculate & Save Score</button><?php endif; ?></section></form><?php endif; ?></div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
