<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';
require_once dirname(__DIR__) . '/includes/inspection_finding_service.php';
require_once dirname(__DIR__) . '/includes/corrective_action_service.php';
require_once dirname(__DIR__) . '/includes/inspection_assignment_service.php';

cpmsRequire('inspection.action.manage', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$inspectionId = (int) ($_GET['inspection_id'] ?? $_POST['inspection_id'] ?? 0);
$findingId = (int) ($_GET['source_finding_id'] ?? $_POST['source_finding_id'] ?? 0);
$inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
if (!$inspection) {
    http_response_code(404);
    exit('Inspection record not found.');
}
if (!cpmsAssignmentTablesReady($conn)) {
    http_response_code(503);
    exit('Run migration 20260810_0061 before assigning findings.');
}

$finding = $findingId > 0
    ? cpmsFindingFind($conn, $propertyId, $inspectionId, $findingId)
    : null;
if ($findingId > 0 && !$finding) {
    http_response_code(404);
    exit('Finding record not found.');
}
if ($finding) {
    $existing = cpmsAssignmentLinkByFinding($conn, $propertyId, $findingId);
    if ($existing) {
        header('Location: inspection_action_view.php?id=' . (int) $existing['action_id']);
        exit;
    }
}

$assignees = cpmsActionAssignees($conn, $propertyId);
$errors = [];
$defaultTitle = $finding
    ? (string) $finding['finding_name'] . ' — ' . (string) $finding['location']
    : trim((string) ($inspection['recommendation'] ?? ''));
$descriptionParts = [];
if ($finding) {
    $descriptionParts[] = 'Finding: ' . (string) $finding['finding_name'];
    $descriptionParts[] = 'Location: ' . (string) $finding['location'];
    if (trim((string) ($finding['remarks'] ?? '')) !== '') {
        $descriptionParts[] = 'Inspector remarks: ' . (string) $finding['remarks'];
    }
    if (trim((string) ($finding['recommendation'] ?? '')) !== '') {
        $descriptionParts[] = 'Required rectification: '
            . (string) $finding['recommendation'];
    }
}
$defaultDescription = $finding
    ? implode("\n\n", $descriptionParts)
    : trim((string) ($inspection['finding'] ?? ''));
$defaultPriority = $finding
    ? (string) $finding['severity']
    : (string) ($inspection['priority'] ?? 'Medium');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsActionVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security session is invalid. Please reload this page.';
    }
    if (!$errors) {
        try {
            if ($findingId > 0) {
                $actionId = cpmsAssignmentCreateForFinding(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    $findingId,
                    $_POST
                );
            } else {
                $actionId = cpmsActionCreate(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    $_POST
                );
            }
            header('Location: inspection_action_view.php?id=' . $actionId . '&created=1');
            exit;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$pageTitle = 'Assign Finding';
$activeMenu = 'hq_inspection';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.ca-wrap{max-width:980px;margin:0 auto;padding:24px}.ca-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:22px}.ca-head{display:flex;justify-content:space-between;gap:15px;align-items:start;margin-bottom:20px}.ca-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.ca-field{margin-bottom:14px}.ca-field.full{grid-column:1/-1}.ca-field label{display:block;font-weight:800;margin-bottom:6px}.ca-field input,.ca-field select,.ca-field textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:9px}.ca-btn{display:inline-flex;padding:11px 15px;border:0;border-radius:9px;background:#173b73;color:#fff;text-decoration:none;font-weight:800;cursor:pointer}.ca-btn.secondary{background:#e2e8f0;color:#334155}.ca-error{background:#fee2e2;color:#991b1b;padding:12px;border-radius:9px;margin-bottom:12px}.ca-source{background:#eff6ff;border:1px solid #bfdbfe;padding:15px;border-radius:12px;margin-bottom:16px}.ca-source-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.ca-source small{display:block;color:#64748b}@media(max-width:700px){.ca-grid,.ca-source-grid{grid-template-columns:1fr}.ca-wrap{padding:14px}}
</style>
<div class="ca-wrap">
    <div class="ca-head">
        <div><small>PROPERTY RECTIFICATION ASSIGNMENT</small><h1>Assign Finding</h1><p><?php echo cpmsActionEscape((string) $inspection['inspection_no']); ?> · <?php echo cpmsActionEscape((string) $inspection['location']); ?></p></div>
        <a class="ca-btn secondary" href="inspection_hq_findings.php?id=<?php echo $inspectionId; ?>">← Report</a>
    </div>
    <?php foreach ($errors as $error): ?><div class="ca-error"><?php echo cpmsActionEscape($error); ?></div><?php endforeach; ?>

    <?php if ($finding): ?>
    <section class="ca-source">
        <div class="ca-source-grid">
            <div><small>Finding</small><strong><?php echo cpmsActionEscape((string) $finding['finding_name']); ?></strong></div>
            <div><small>Category</small><strong><?php echo cpmsActionEscape((string) $finding['category']); ?></strong></div>
            <div><small>Location</small><strong><?php echo cpmsActionEscape((string) $finding['location']); ?></strong></div>
            <div><small>Severity</small><strong><?php echo cpmsActionEscape((string) $finding['severity']); ?></strong></div>
        </div>
        <p>Finding dan gambar asal HQ akan dipaparkan secara automatik kepada penerima tugasan.</p>
    </section>
    <?php endif; ?>

    <form method="post" class="ca-card">
        <input type="hidden" name="csrf_token" value="<?php echo cpmsActionEscape(cpmsActionCsrfToken()); ?>">
        <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
        <input type="hidden" name="source_finding_id" value="<?php echo $findingId; ?>">
        <div class="ca-grid">
            <div class="ca-field full"><label>Action Title</label><input name="title" required maxlength="190" value="<?php echo cpmsActionEscape((string) ($_POST['title'] ?? $defaultTitle)); ?>"></div>
            <div class="ca-field full"><label>Instructions / Required Rectification</label><textarea name="description" rows="7" required><?php echo cpmsActionEscape((string) ($_POST['description'] ?? $defaultDescription)); ?></textarea></div>
            <div class="ca-field"><label>Assign to Staff / Contractor</label><select name="assigned_system_user_id" required><option value="">-- Select assignee --</option><?php foreach ($assignees as $assignee): ?><option value="<?php echo (int) $assignee['id']; ?>" <?php echo (int) ($_POST['assigned_system_user_id'] ?? 0) === (int) $assignee['id'] ? 'selected' : ''; ?>><?php echo cpmsActionEscape(ucfirst((string) $assignee['role_code']) . ' — ' . (string) $assignee['full_name']); ?></option><?php endforeach; ?></select><?php if (!$assignees): ?><small>Tiada Staff/Contractor aktif dijumpai. Daftar dan beri role yang betul dahulu.</small><?php endif; ?></div>
            <div class="ca-field"><label>Priority</label><select name="priority"><?php foreach (['Low', 'Medium', 'High', 'Critical'] as $priority): ?><option value="<?php echo $priority; ?>" <?php echo (string) ($_POST['priority'] ?? $defaultPriority) === $priority ? 'selected' : ''; ?>><?php echo $priority; ?></option><?php endforeach; ?></select></div>
            <div class="ca-field"><label>Due Date</label><input type="date" name="due_date" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo cpmsActionEscape((string) ($_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days')))); ?>"></div>
        </div>
        <button class="ca-btn" type="submit" <?php echo !$assignees ? 'disabled' : ''; ?>>Create & Assign</button>
    </form>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
