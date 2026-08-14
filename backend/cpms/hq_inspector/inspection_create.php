<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$propertyId = (int) ($_GET['property_id'] ?? $_POST['property_id'] ?? 0);
$property = null;
$stmt = $conn->prepare(
    'SELECT id, property_code, property_name, company_name, address
     FROM cpms_properties
     WHERE id = ? AND is_active = 1
     LIMIT 1'
);
if ($stmt) {
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $property = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if (!$property) {
    hqiRedirect('dashboard.php');
}

$errors = [];
if (!cpmsFindingTablesReady($conn)) {
    $errors[] = 'CPMS v3.2.6 upgrade is not installed. Run migration 20260809_0018 first.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    if (!hqiVerify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security session. Please refresh the page.';
    } else {
        try {
            $inspectorName = (string) $hqInspector['inspector_code']
                . ' - ' . (string) $hqInspector['full_name'];
            $inspectionId = cpmsInspectionCreate(
                $conn,
                $propertyId,
                [
                    'inspection_date' => $_POST['inspection_date'] ?? date('Y-m-d'),
                    'inspection_type' => $_POST['inspection_type'] ?? 'General Inspection',
                    'category' => $_POST['inspection_type'] ?? 'General Inspection',
                    'location' => $_POST['location'] ?? '',
                    'priority' => 'Medium',
                    'status' => 'Draft',
                    'description' => '',
                    'finding' => '',
                    'recommendation' => '',
                    'reported_by_id' => (int) $hqInspector['id'],
                    'reported_by_name' => $inspectorName,
                ]
            );
            hqiRedirect(
                'inspection_view.php?id=' . $inspectionId
                . '&property_id=' . $propertyId
                . '&new=1'
            );
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$pageTitle = 'Create Inspection';
require __DIR__ . '/header.php';
?>
<div class="page-eyebrow">Inspection Workspace</div>
<h1 class="page-heading">Create Inspection</h1>
<p class="page-intro">Create a new inspection for <strong><?php echo hqiEscape((string) $property['property_code']); ?></strong> — <?php echo hqiEscape((string) $property['property_name']); ?>.</p>

<?php foreach ($errors as $error): ?>
    <div class="err"><?php echo hqiEscape($error); ?></div>
<?php endforeach; ?>

<div class="genesis-create-intro"><span class="page-overline">HQ INSPECTOR · NEW INSPECTION</span><div class="inspection-steps" aria-label="Inspection workflow"><div class="step active"><span>1</span><div><strong>Setup</strong><small>Property & template</small></div></div><div class="step"><span>2</span><div><strong>Checklist</strong><small>Findings & severity</small></div></div><div class="step"><span>3</span><div><strong>Evidence</strong><small>Before photos</small></div></div><div class="step"><span>4</span><div><strong>Submit</strong><small>Review inspection</small></div></div></div></div><form class="card inspection-form-card" method="post">
    <input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf()); ?>">
    <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">

    <label>Property</label>
    <input value="<?php echo hqiEscape((string) $property['property_code'] . ' — ' . (string) $property['property_name']); ?>" readonly>

    <label>Inspection Date</label>
    <input type="date" name="inspection_date"
           value="<?php echo hqiEscape((string) ($_POST['inspection_date'] ?? date('Y-m-d'))); ?>"
           required>

    <label>Inspection Type</label>
    <input name="inspection_type"
           value="<?php echo hqiEscape((string) ($_POST['inspection_type'] ?? 'General Inspection')); ?>"
           required>

    <label>Main Area / Location</label>
    <input name="location"
           value="<?php echo hqiEscape((string) ($_POST['location'] ?? '')); ?>"
           placeholder="Example: Common Area / Block A"
           required>

    <button type="submit">Create Inspection</button>
</form>
<?php require __DIR__ . '/footer.php'; ?>
