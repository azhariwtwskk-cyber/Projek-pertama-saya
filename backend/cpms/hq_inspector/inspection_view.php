<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$inspectionId = (int) ($_GET['id'] ?? $_POST['inspection_id'] ?? 0);
$propertyId = (int) ($_GET['property_id'] ?? $_POST['property_id'] ?? 0);
$inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);

if (!$inspection
    || (int) ($inspection['reported_by_id'] ?? 0) !== (int) $hqInspector['id']
) {
    hqiRedirect('inspections.php');
}

$errors = [];
$success = '';
$inspectorName = (string) $hqInspector['inspector_code']
    . ' - ' . (string) $hqInspector['full_name'];

if (!cpmsFindingTablesReady($conn)) {
    $errors[] = 'CPMS v3.2.6 is incomplete. Run migration 20260809_0018 first.';
}

$photoLimit = cpmsFindingPhotoLimit($conn, $propertyId);

$requestedAction = trim((string) ($_POST['action'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $requestedAction === 'bulk_upload_photo'
) {
    header('Content-Type: application/json; charset=UTF-8');
    $createdFindingId = 0;
    $storedImageId = 0;

    try {
        if ($errors) {
            throw new RuntimeException((string) $errors[0]);
        }
        if (!hqiVerify($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException(
                'Invalid security session. Refresh the page and try again.'
            );
        }
        if ((string) ($inspection['status'] ?? '') !== 'Draft') {
            throw new RuntimeException('This inspection is no longer a Draft.');
        }
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            throw new RuntimeException('Image file was not received.');
        }

        $file = $_FILES['image'];
        cpmsFindingValidateImageBatch(
            $conn,
            $propertyId,
            $inspectionId,
            [$file],
            $photoLimit
        );

        $findingId = (int) ($_POST['finding_id'] ?? 0);
        if ($findingId > 0) {
            $existingFinding = cpmsFindingFind(
                $conn,
                $propertyId,
                $inspectionId,
                $findingId
            );
            if (!$existingFinding) {
                throw new RuntimeException(
                    'The issue group was not found. Please upload again.'
                );
            }
        } else {
            $findingId = cpmsFindingCreate(
                $conn,
                $propertyId,
                $inspectionId,
                (int) ($_POST['finding_master_id'] ?? 0),
                (string) ($_POST['finding_location'] ?? ''),
                (string) ($_POST['remarks'] ?? ''),
                '',
                (int) $hqInspector['id'],
                $inspectorName
            );
            $createdFindingId = $findingId;
        }

        $storedImageId = cpmsInspectionStoreImage(
            $conn,
            $propertyId,
            $inspectionId,
            $file,
            dirname(__DIR__) . '/uploads/inspections',
            'Finding',
            (string) ($_POST['photo_caption'] ?? ''),
            (int) $hqInspector['id'],
            $inspectorName,
            $photoLimit
        );
        cpmsFindingLinkImage(
            $conn,
            $propertyId,
            $inspectionId,
            $findingId,
            $storedImageId
        );
        if ($createdFindingId > 0) {
            cpmsFindingSyncLegacySummary($conn, $propertyId, $inspectionId);
        }

        echo json_encode([
            'ok' => true,
            'finding_id' => $findingId,
            'image_id' => $storedImageId,
            'image_count' => cpmsInspectionCountImages(
                $conn,
                $propertyId,
                $inspectionId
            ),
        ]);
        exit;
    } catch (Throwable $exception) {
        if ($storedImageId > 0) {
            cpmsFindingRemoveUnlinkedImage(
                $conn,
                $propertyId,
                $inspectionId,
                $storedImageId,
                dirname(__DIR__)
            );
        }
        if ($createdFindingId > 0) {
            cpmsFindingDeleteDraft(
                $conn,
                $propertyId,
                $inspectionId,
                $createdFindingId,
                dirname(__DIR__)
            );
        }
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => $exception->getMessage(),
        ]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    if (!hqiVerify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security session. Please refresh the page.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        try {
            if ($action === 'add_finding') {
                $files = cpmsFindingFiles($_FILES['images'] ?? []);
                if (!$files) {
                    throw new RuntimeException(
                        'Please select at least one image for this issue.'
                    );
                }
                cpmsFindingValidateImageBatch(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    $files,
                    $photoLimit
                );

                $findingId = cpmsFindingCreate(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    (int) ($_POST['finding_master_id'] ?? 0),
                    (string) ($_POST['finding_location'] ?? ''),
                    (string) ($_POST['remarks'] ?? ''),
                    (string) ($_POST['recommendation'] ?? ''),
                    (int) $hqInspector['id'],
                    $inspectorName
                );

                foreach ($files as $file) {
                    $imageId = cpmsInspectionStoreImage(
                        $conn,
                        $propertyId,
                        $inspectionId,
                        $file,
                        dirname(__DIR__) . '/uploads/inspections',
                        'Finding',
                        (string) ($_POST['photo_caption'] ?? ''),
                        (int) $hqInspector['id'],
                        $inspectorName,
                        $photoLimit
                    );
                    cpmsFindingLinkImage(
                        $conn,
                        $propertyId,
                        $inspectionId,
                        $findingId,
                        $imageId
                    );
                }
                cpmsFindingSyncLegacySummary($conn, $propertyId, $inspectionId);

                hqiRedirect(
                    'inspection_view.php?id=' . $inspectionId
                    . '&property_id=' . $propertyId
                    . '&finding_added=1'
                );
            } elseif ($action === 'delete_finding') {
                $deleted = cpmsFindingDeleteDraft(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    (int) ($_POST['finding_id'] ?? 0),
                    dirname(__DIR__)
                );
                if (!$deleted) {
                    throw new RuntimeException('Unable to remove issue.');
                }
                cpmsFindingSyncLegacySummary($conn, $propertyId, $inspectionId);
                hqiRedirect(
                    'inspection_view.php?id=' . $inspectionId
                    . '&property_id=' . $propertyId
                    . '&finding_deleted=1'
                );
            } elseif ($action === 'update_finding') {
                cpmsFindingUpdateDraft(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    (int) ($_POST['finding_id'] ?? 0),
                    (int) ($_POST['finding_master_id'] ?? 0),
                    (string) ($_POST['finding_location'] ?? ''),
                    (string) ($_POST['remarks'] ?? ''),
                    (string) ($_POST['recommendation'] ?? '')
                );
                cpmsFindingSyncLegacySummary($conn, $propertyId, $inspectionId);
                hqiRedirect(
                    'inspection_view.php?id=' . $inspectionId
                    . '&property_id=' . $propertyId
                    . '&finding_updated=1'
                );
            } elseif ($action === 'delete_finding_image') {
                $deleted = cpmsFindingDeleteImageDraft(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    (int) ($_POST['finding_id'] ?? 0),
                    (int) ($_POST['image_id'] ?? 0),
                    dirname(__DIR__)
                );
                if (!$deleted) {
                    throw new RuntimeException('Unable to remove image.');
                }
                hqiRedirect(
                    'inspection_view.php?id=' . $inspectionId
                    . '&property_id=' . $propertyId
                    . '&image_deleted=1'
                );
            } elseif ($action === 'cancel_inspection') {
                $deleted = cpmsInspectionDeleteDraft(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    dirname(__DIR__)
                );
                if (!$deleted) {
                    throw new RuntimeException('Only draft inspections can be cancelled.');
                }
                hqiRedirect('dashboard.php?inspection_cancelled=1');
            } elseif ($action === 'submit_inspection') {
                $result = cpmsFindingSubmitInspection(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    (int) $hqInspector['id'],
                    $inspectorName
                );
                $emailState = !empty($result['email']['ok']) ? 'sent' : 'failed';
                hqiRedirect(
                    'inspection_view.php?id=' . $inspectionId
                    . '&property_id=' . $propertyId
                    . '&submitted=1&email=' . $emailState
                    . '&notifications=' . (int) $result['notification_count']
                );
            } elseif ($action === 'retry_email') {
                if ((string) $inspection['status'] === 'Draft') {
                    throw new RuntimeException('Inspection has not been submitted.');
                }
                $email = cpmsFindingSendSubmissionEmail(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    true
                );
                hqiRedirect(
                    'inspection_view.php?id=' . $inspectionId
                    . '&property_id=' . $propertyId
                    . '&retry=' . (!empty($email['ok']) ? 'sent' : 'failed')
                );
            } elseif ($action === 'create_reinspection') {
                $newInspectionId = cpmsHqCreateReinspection(
                    $conn,
                    $propertyId,
                    $inspectionId,
                    (int) $hqInspector['id'],
                    $inspectorName
                );
                hqiRedirect(
                    'inspection_view.php?id=' . $newInspectionId
                    . '&property_id=' . $propertyId
                    . '&reinspection=1'
                );
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
$property = cpmsFindingProperty($conn, $propertyId);
$masters = cpmsFindingTablesReady($conn)
    ? cpmsFindingMasterList($conn, $propertyId)
    : [];
$findings = cpmsFindingTablesReady($conn)
    ? cpmsFindingList($conn, $propertyId, $inspectionId)
    : [];
$deliveryHistory = cpmsFindingTablesReady($conn)
    ? cpmsFindingDeliveryHistory($conn, $propertyId, $inspectionId)
    : [];
$correctiveActions = cpmsHqActionsByInspection(
    $conn,
    $propertyId,
    $inspectionId
);
$reinspections = cpmsHqReinspectionList(
    $conn,
    $propertyId,
    $inspectionId
);

$findingImages = [];
foreach ($findings as $finding) {
    $findingImages[(int) $finding['id']] = cpmsFindingImages(
        $conn,
        $propertyId,
        $inspectionId,
        (int) $finding['id']
    );
}

$recurringIssues = [];
if ($findings) {
    $recurringStmt = $conn->prepare(
        'SELECT COUNT(*) AS previous_total, MAX(r.inspection_date) AS last_date
         FROM inspection_findings f
         INNER JOIN inspection_reports r
            ON r.id = f.inspection_id
           AND r.property_id = f.property_id
         WHERE f.property_id = ?
           AND f.inspection_id <> ?
           AND (
                (f.finding_master_id > 0 AND f.finding_master_id = ?)
                OR (f.finding_name = ? AND f.location = ?)
           )'
    );
    if ($recurringStmt) {
        foreach ($findings as $finding) {
            $masterId = (int) ($finding['finding_master_id'] ?? 0);
            $findingName = (string) ($finding['finding_name'] ?? '');
            $location = (string) ($finding['location'] ?? '');
            $recurringStmt->bind_param(
                'iiiss',
                $propertyId,
                $inspectionId,
                $masterId,
                $findingName,
                $location
            );
            $recurringStmt->execute();
            $row = $recurringStmt->get_result()->fetch_assoc();
            $previousTotal = (int) ($row['previous_total'] ?? 0);
            if ($previousTotal > 0) {
                $recurringIssues[(int) $finding['id']] = [
                    'total' => $previousTotal,
                    'last_date' => (string) ($row['last_date'] ?? ''),
                ];
            }
        }
        $recurringStmt->close();
    }
}

$categories = [];
foreach ($masters as $master) {
    $categories[(string) $master['category']] = true;
}
$masterJson = json_encode(
    $masters,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$latestEmailStatus = '';
foreach ($deliveryHistory as $delivery) {
    if ((string) $delivery['channel'] === 'email') {
        $latestEmailStatus = (string) $delivery['status'];
        break;
    }
}

$pageTitle = (string) $inspection['inspection_no'];
require __DIR__ . '/header.php';
?>
<style>
    .meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
    .meta div{background:#f8fafc;border-radius:10px;padding:10px}.meta small{display:block;color:#64748b;margin-bottom:4px}
    .finding-card{border-left:5px solid #94a3b8}.finding-card.high{border-left-color:#f97316}.finding-card.critical{border-left-color:#dc2626}.finding-card.low{border-left-color:#22c55e}
    .issue-list{display:grid;gap:10px}.issue-row{display:grid;grid-template-columns:minmax(220px,1fr) 120px 150px minmax(180px,1fr);gap:10px;align-items:start;border:1px solid #e5eaf1;border-radius:12px;background:#fff;padding:12px}.issue-title{font-weight:900}.issue-meta{font-size:12px;color:#64748b;margin-top:3px}.issue-photos{display:flex;gap:7px;flex-wrap:wrap}.issue-photos img{width:64px;height:52px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0}.issue-photo{position:relative}.issue-photo form{margin:4px 0 0}.issue-photo button{font-size:10px;padding:4px 6px;background:#b91c1c;min-height:auto}
    .badge{display:inline-block;padding:4px 9px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:700}.badge.High{background:#ffedd5;color:#9a3412}.badge.Critical{background:#fee2e2;color:#991b1b}.badge.Low{background:#dcfce7;color:#166534}
    .photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,180px));gap:10px}.photos img{width:100%;height:125px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0}
    .actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.btn-danger{background:#b91c1c}.submit-card{border:2px solid #0f172a}.delivery{font-size:13px}.delivery td,.delivery th{padding:8px}.warn{background:#fff7ed;color:#9a3412;padding:10px;border-radius:8px}.info{background:#eff6ff;color:#1e40af;padding:10px;border-radius:8px}
    .bulk-toolbar{background:#f8fafc;border:1px solid #dbeafe;border-radius:12px;padding:14px;margin:14px 0}.bulk-toolbar-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.bulk-toolbar-grid .full{grid-column:1/-1}
    .bulk-photo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-top:16px}.bulk-photo-card{border:2px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff}.bulk-photo-card.invalid{border-color:#dc2626}.bulk-photo-card.uploaded{border-color:#22c55e;background:#f0fdf4}.bulk-photo-card.failed{border-color:#f97316;background:#fff7ed}
    .bulk-photo-head{display:flex;gap:10px;align-items:flex-start;margin-bottom:10px}.bulk-photo-head img{width:110px;height:90px;object-fit:cover;border-radius:8px;background:#e2e8f0}.bulk-photo-name{font-size:12px;word-break:break-word;color:#475569}.bulk-photo-status{font-size:12px;font-weight:800;margin-top:5px}.bulk-progress{display:none;margin-top:14px}.bulk-progress-track{height:12px;background:#e2e8f0;border-radius:99px;overflow:hidden}.bulk-progress-bar{height:100%;width:0;background:#2563eb;transition:width .2s}.bulk-controls{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.bulk-controls button{margin:0}.bulk-checkbox{width:auto;margin:2px 4px 0 0}.bulk-empty{padding:22px;border:2px dashed #cbd5e1;border-radius:12px;text-align:center;color:#64748b}
    .ai-suggestion{display:none;margin:8px 0 10px;padding:9px 10px;border-radius:10px;background:#eef6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:12px}.ai-suggestion strong{display:block;font-size:12px}.ai-suggestion button{margin-top:7px;padding:6px 9px;min-height:auto;border-radius:8px;font-size:11px;background:#1d4ed8}.recurring-badge{display:inline-block;margin-top:7px;padding:5px 8px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:900}
    .photo-item{position:relative}.photo-delete{margin-top:5px}.photo-delete button{font-size:11px;padding:6px 8px;background:#b91c1c}.edit-finding{margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px}.edit-finding summary{cursor:pointer;font-weight:800;color:#1d4ed8}.edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}.edit-grid .full{grid-column:1/-1}.action-table-wrap{overflow:auto}.action-table{min-width:850px}.action-table a{font-weight:800;color:#1d4ed8;text-decoration:none}.action-status{display:inline-block;padding:4px 8px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:800}.action-status.Rectified{background:#ede9fe;color:#6d28d9}.action-status.Verified,.action-status.Closed{background:#dcfce7;color:#166534}.overdue{color:#b91c1c;font-weight:800}.reinspection-list{display:flex;gap:8px;flex-wrap:wrap}.reinspection-list a{padding:8px 10px;border-radius:8px;background:#eff6ff;color:#1d4ed8;text-decoration:none;font-weight:800}
    @media(max-width:720px){.bulk-toolbar-grid,.edit-grid{grid-template-columns:1fr}.bulk-toolbar-grid .full,.edit-grid .full{grid-column:auto}.bulk-photo-grid{grid-template-columns:1fr}.bulk-photo-head img{width:95px;height:80px}}
</style>

<div class="genesis-inspection-view">
<div class="actions genesis-view-actions">
    <a class="btn" href="dashboard.php">← Properties</a>
    <a class="btn" href="inspections.php">My Inspections</a>
    <a class="btn" href="inspection_report.php?id=<?php echo $inspectionId; ?>&property_id=<?php echo $propertyId; ?>">Print / Save PDF</a>
    <?php if ((string) $inspection['status'] === 'Draft'): ?>
        <form method="post" onsubmit="return confirm('Cancel and delete this draft inspection? This action cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf()); ?>">
            <input type="hidden" name="action" value="cancel_inspection">
            <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
            <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
            <button class="btn-danger" type="submit">Cancel Draft</button>
        </form>
    <?php endif; ?>
</div>

<h1><?php echo hqiEscape((string) $inspection['inspection_no']); ?></h1>
<p class="muted">
    <?php echo hqiEscape((string) ($property['property_code'] ?? '')); ?> —
    <?php echo hqiEscape((string) ($property['property_name'] ?? '')); ?>
</p>

<?php if (isset($_GET['new'])): ?>
    <div class="ok">Draft created. Upload photos and select an issue.</div>
<?php endif; ?>
<?php if (isset($_GET['finding_added'])): ?>
    <div class="ok">Issue and image added successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['bulk_added'])): ?>
    <div class="ok"><?php echo (int) $_GET['bulk_added']; ?> image(s) saved into the Issue List.</div>
<?php endif; ?>
<?php if (isset($_GET['finding_deleted'])): ?>
    <div class="ok">Issue draft removed.</div>
<?php endif; ?>
<?php if (isset($_GET['finding_updated'])): ?>
    <div class="ok">Issue draft updated successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['image_deleted'])): ?>
    <div class="ok">Issue image removed successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['reinspection'])): ?>
    <div class="ok">Reinspection draft created successfully.</div>
<?php endif; ?>
<?php if (isset($_GET['submitted'])): ?>
    <div class="ok">Inspection submitted successfully. Portal notifications: <?php echo (int) ($_GET['notifications'] ?? 0); ?>.</div>
    <?php if (($_GET['email'] ?? '') === 'sent'): ?>
        <div class="ok">Email was accepted by the mail server for delivery.</div>
    <?php else: ?>
        <div class="warn">Inspection was submitted, but email failed or recipient is missing. Check the Delivery Log and use Retry.</div>
    <?php endif; ?>
<?php endif; ?>
<?php if (isset($_GET['retry'])): ?>
    <div class="<?php echo $_GET['retry'] === 'sent' ? 'ok' : 'warn'; ?>">
        Retry email: <?php echo $_GET['retry'] === 'sent' ? 'accepted by mail server.' : 'failed. Please check the property email or mail server configuration.'; ?>
    </div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="err"><?php echo hqiEscape($error); ?></div>
<?php endforeach; ?>

<section class="card">
    <div class="meta">
        <div><small>Status</small><strong><?php echo hqiEscape((string) $inspection['status']); ?></strong></div>
        <div><small>Date</small><strong><?php echo hqiEscape((string) $inspection['inspection_date']); ?></strong></div>
        <div><small>Main Location</small><strong><?php echo hqiEscape((string) $inspection['location']); ?></strong></div>
        <div><small>Highest Severity</small><strong><?php echo hqiEscape((string) $inspection['priority']); ?></strong></div>
        <div><small>Total Issues</small><strong><?php echo count($findings); ?></strong></div>
        <div><small>Photo Usage</small><strong><?php echo cpmsInspectionCountImages($conn, $propertyId, $inspectionId); ?> / <?php echo $photoLimit; ?></strong></div>
    </div>
    <?php if (trim((string) ($inspection['description'] ?? '')) !== ''): ?>
        <p><strong>General Notes</strong><br><?php echo nl2br(hqiEscape((string) $inspection['description'])); ?></p>
    <?php endif; ?>
</section>

<?php if ((string) $inspection['status'] === 'Draft'): ?>
<section class="card">
    <h2>Upload Photos & Select Issue</h2>
    <p class="muted">Select inspection photos. For each photo, choose the issue from the configured issue list.</p>
    <?php if (!$masters): ?>
        <div class="warn">No active Issue List. Ask the System Owner / Compliance Manager to activate the issue list.</div>
    <?php else: ?>
        <?php $remainingPhotos = cpmsFindingPhotoRemaining($conn, $propertyId, $inspectionId); ?>
        <label>Select Photos (remaining: <?php echo $remainingPhotos; ?> of <?php echo $photoLimit; ?>)</label>
        <input type="file" id="bulkPhotoInput"
               accept="image/jpeg,image/png,image/webp"
               multiple <?php echo $remainingPhotos < 1 ? 'disabled' : ''; ?>>
        <p class="muted">Select all photos from phone/computer. Maximum 8 MB per image. Images will be uploaded one by one after you press Save Issue Photos.</p>

        <div class="bulk-toolbar" id="bulkToolbar" hidden>
            <h3>Apply issue to selected photos</h3>
            <div class="bulk-toolbar-grid">
                <div class="full">
                    <label>Issue / Defect Found (auto)</label>
                    <select id="bulkSharedFinding">
                        <option value="">-- Select issue / defect --</option>
                        <?php foreach ($masters as $master): ?>
                            <option value="<?php echo (int) $master['id']; ?>">
                                <?php echo hqiEscape((string) $master['category']); ?> — <?php echo hqiEscape((string) $master['finding_name']); ?> (<?php echo hqiEscape((string) $master['default_severity']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Location (manual entry)</label>
                    <input id="bulkSharedLocation"
                           value=""
                           placeholder="Example: Block A, Level 3">
                </div>
                <div>
                    <label>Remarks (manual entry)</label>
                    <input id="bulkSharedRemarks" placeholder="Example: Requires immediate action">
                </div>
                <div class="full bulk-controls">
                    <button type="button" id="bulkApplyButton">Apply to Selected Photos</button>
                    <button type="button" id="bulkSelectAllButton">Select All</button>
                    <button type="button" id="bulkClearSelectionButton">Clear Selection</button>
                </div>
            </div>
        </div>

        <div id="bulkPhotoGrid" class="bulk-photo-grid">
            <div class="bulk-empty">Thumbnails and issue selections will appear here.</div>
        </div>

        <div class="bulk-progress" id="bulkProgress">
            <p id="bulkProgressText">Preparing upload…</p>
            <div class="bulk-progress-track"><div class="bulk-progress-bar" id="bulkProgressBar"></div></div>
        </div>

        <div class="bulk-controls" id="bulkSaveArea" hidden style="margin-top:16px">
            <button type="button" id="bulkSaveButton">Save Issue Photos</button>
            <span class="muted" id="bulkSummary"></span>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<h2>Issue List (<?php echo count($findings); ?>)</h2>
<?php if (!$findings): ?>
    <section class="card"><p class="muted">No issue yet. Upload photos and select an issue first.</p></section>
<?php endif; ?>

<div class="issue-list">
<?php foreach ($findings as $index => $finding): ?>
    <?php $severityClass = strtolower((string) $finding['severity']); ?>
    <section class="issue-row finding-card <?php echo hqiEscape($severityClass); ?>">
        <div>
            <div class="issue-title"><?php echo ($index + 1); ?>. <?php echo hqiEscape((string) $finding['finding_name']); ?></div>
            <div class="issue-meta"><?php echo hqiEscape((string) $finding['category']); ?> · <?php echo hqiEscape((string) $finding['location']); ?></div>
            <?php if (trim((string) ($finding['remarks'] ?? '')) !== ''): ?>
                <div class="issue-meta"><?php echo hqiEscape((string) $finding['remarks']); ?></div>
            <?php endif; ?>
            <?php if (isset($recurringIssues[(int) $finding['id']])): ?>
                <?php $recurring = $recurringIssues[(int) $finding['id']]; ?>
                <span class="recurring-badge">
                    Recurring Issue · <?php echo (int) $recurring['total']; ?> previous occurrence(s)
                    <?php if ($recurring['last_date'] !== ''): ?>
                        · Last <?php echo hqiEscape($recurring['last_date']); ?>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <div>
            <span class="badge <?php echo hqiEscape((string) $finding['severity']); ?>">
                <?php echo hqiEscape((string) $finding['severity']); ?>
            </span>
        </div>
        <div><?php echo hqiEscape((string) $finding['status']); ?></div>
        <div class="issue-photos">
            <?php foreach ($findingImages[(int) $finding['id']] as $image): ?>
                <div class="issue-photo">
                    <img src="../<?php echo hqiEscape((string) $image['image_path']); ?>" alt="Finding photo">
                    <?php if ((string) $inspection['status'] === 'Draft'): ?>
                        <form class="photo-delete" method="post" onsubmit="return confirm('Remove this image only?');">
                            <input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf()); ?>">
                            <input type="hidden" name="action" value="delete_finding_image">
                            <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                            <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
                            <input type="hidden" name="finding_id" value="<?php echo (int) $finding['id']; ?>">
                            <input type="hidden" name="image_id" value="<?php echo (int) $image['id']; ?>">
                            <button type="submit">Remove Photo</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ((string) $inspection['status'] === 'Draft'): ?>
            <form method="post" onsubmit="return confirm('Remove this issue and all linked images?');">
                <input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf()); ?>">
                <input type="hidden" name="action" value="delete_finding">
                <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
                <input type="hidden" name="finding_id" value="<?php echo (int) $finding['id']; ?>">
                <button class="btn-danger" type="submit">Remove Issue</button>
            </form>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>

<?php if ((string) $inspection['status'] !== 'Draft'): ?>
<section class="card">
    <div class="actions">
        <div style="margin-right:auto">
            <h2 style="margin-bottom:4px">Corrective Action Tracking</h2>
            <p class="muted">Monitor assignee, due date, rectification evidence and HQ verification.</p>
        </div>
    </div>
    <?php if (!$correctiveActions): ?>
        <p class="muted">The property team has not assigned any action yet.</p>
    <?php else: ?>
        <div class="action-table-wrap">
            <table class="action-table">
                <thead><tr><th>Action</th><th>Assigned To</th><th>Due</th><th>Staff Status</th><th>Supervisor</th><th>Evidence</th><th>HQ Decision</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($correctiveActions as $correctiveAction): ?>
                    <?php
                    $actionOverdue = !empty($correctiveAction['due_date'])
                        && (string) $correctiveAction['due_date'] < date('Y-m-d')
                        && !in_array(
                            (string) $correctiveAction['status'],
                            ['Verified', 'Closed'],
                            true
                        );
                    ?>
                    <tr>
                        <td><strong><?php echo hqiEscape((string) $correctiveAction['action_no']); ?></strong><br><small><?php echo hqiEscape((string) $correctiveAction['title']); ?></small></td>
                        <td><?php echo hqiEscape((string) ($correctiveAction['assigned_name'] ?: '-')); ?></td>
                        <td class="<?php echo $actionOverdue ? 'overdue' : ''; ?>"><?php echo hqiEscape((string) ($correctiveAction['due_date'] ?: '-')); ?><?php echo $actionOverdue ? '<br><small>OVERDUE</small>' : ''; ?></td>
                        <td><span class="action-status <?php echo hqiEscape((string) $correctiveAction['status']); ?>"><?php echo hqiEscape((string) $correctiveAction['status']); ?></span></td>
                        <td><?php echo hqiEscape((string) ($correctiveAction['supervisor_status'] ?: ((int) ($correctiveAction['source_finding_id'] ?? 0) > 0 ? 'Not Submitted' : 'Legacy'))); ?></td>
                        <td><?php echo (int) $correctiveAction['evidence_count']; ?></td>
                        <td><?php echo hqiEscape((string) ($correctiveAction['latest_hq_decision'] ?: '-')); ?></td>
                        <td><a href="action_review.php?id=<?php echo (int) $correctiveAction['id']; ?>&property_id=<?php echo $propertyId; ?>"><?php echo (string) $correctiveAction['status'] === 'Rectified' && ((int) ($correctiveAction['source_finding_id'] ?? 0) === 0 || (string) ($correctiveAction['supervisor_status'] ?? '') === 'Approved') ? 'Verify' : 'View'; ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="actions">
        <div style="margin-right:auto">
            <h2 style="margin-bottom:4px">Reinspection</h2>
            <p class="muted">Create a follow-up inspection to verify the actual site condition.</p>
        </div>
        <?php if (cpmsHqOperationalTablesReady($conn)): ?>
            <form method="post" onsubmit="return confirm('Create a draft reinspection for this report?');">
                <input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf()); ?>">
                <input type="hidden" name="action" value="create_reinspection">
                <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
                <button type="submit">+ Start Reinspection</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if (!cpmsHqOperationalTablesReady($conn)): ?>
        <div class="warn">Run migration 20260810_0060 to enable reinspection.</div>
    <?php elseif (!$reinspections): ?>
        <p class="muted">No reinspection yet.</p>
    <?php else: ?>
        <div class="reinspection-list">
            <?php foreach ($reinspections as $reinspection): ?>
                <a href="inspection_view.php?id=<?php echo (int) $reinspection['id']; ?>&property_id=<?php echo $propertyId; ?>"><?php echo hqiEscape((string) $reinspection['inspection_no']); ?> · <?php echo hqiEscape((string) $reinspection['status']); ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ((string) $inspection['status'] === 'Draft'): ?>
<section class="card submit-card">
    <h2>Submit to Property</h2>
    <p>After submission, the issue list will be locked and CPMS will:</p>
    <ol>
        <li>change the status to <strong>Submitted</strong>;</li>
        <li>create portal notifications for the property management team;</li>
        <li>send a summary email to the registered recipient;</li>
        <li>save the delivery status in the Delivery Log.</li>
    </ol>
    <form method="post" onsubmit="return confirm('Submit this inspection to the property team now?');">
        <input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf()); ?>">
        <input type="hidden" name="action" value="submit_inspection">
        <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
        <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
        <button type="submit">Submit Inspection</button>
    </form>
</section>
<?php endif; ?>

<?php if ((string) $inspection['status'] !== 'Draft'): ?>
<section class="card">
    <div class="actions">
        <div style="margin-right:auto">
            <h2 style="margin-bottom:4px">Delivery Log</h2>
            <p class="muted">“Sent” means the message was accepted by the mail server; final delivery still depends on the email provider.</p>
        </div>
        <?php if ($latestEmailStatus === 'Failed'): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf()); ?>">
                <input type="hidden" name="action" value="retry_email">
                <input type="hidden" name="inspection_id" value="<?php echo $inspectionId; ?>">
                <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
                <button type="submit">Retry Email</button>
            </form>
        <?php endif; ?>
    </div>
    <div style="overflow:auto">
        <table class="delivery">
            <thead><tr><th>Time</th><th>Recipient</th><th>Role</th><th>Trigger</th><th>Status</th><th>Error</th></tr></thead>
            <tbody>
            <?php if (!$deliveryHistory): ?>
                <tr><td colspan="6" class="muted">No delivery record.</td></tr>
            <?php endif; ?>
            <?php foreach ($deliveryHistory as $delivery): ?>
                <tr>
                    <td><?php echo hqiEscape((string) $delivery['created_at']); ?></td>
                    <td><?php echo hqiEscape((string) $delivery['recipient']); ?></td>
                    <td><?php echo hqiEscape((string) $delivery['recipient_role']); ?></td>
                    <td><?php echo hqiEscape((string) $delivery['trigger_type']); ?> #<?php echo (int) $delivery['attempt_no']; ?></td>
                    <td><strong><?php echo hqiEscape((string) $delivery['status']); ?></strong></td>
                    <td><?php echo hqiEscape((string) $delivery['last_error']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ((string) $inspection['status'] === 'Draft' && $masters): ?>
<script>
(function () {
    var masters = <?php echo $masterJson ?: '[]'; ?>;
    var categories = <?php echo json_encode(array_keys($categories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var maximumSelection = <?php echo (int) $remainingPhotos; ?>;
    var inspectionId = <?php echo $inspectionId; ?>;
    var propertyId = <?php echo $propertyId; ?>;
    var csrfToken = <?php echo json_encode(hqiCsrf(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var defaultLocation = <?php echo json_encode((string) $inspection['location'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var photoInput = document.getElementById('bulkPhotoInput');
    var toolbar = document.getElementById('bulkToolbar');
    var photoGrid = document.getElementById('bulkPhotoGrid');
    var saveArea = document.getElementById('bulkSaveArea');
    var saveButton = document.getElementById('bulkSaveButton');
    var summary = document.getElementById('bulkSummary');
    var progress = document.getElementById('bulkProgress');
    var progressText = document.getElementById('bulkProgressText');
    var progressBar = document.getElementById('bulkProgressBar');
    var sharedFinding = document.getElementById('bulkSharedFinding');
    var sharedLocation = document.getElementById('bulkSharedLocation');
    var sharedRemarks = document.getElementById('bulkSharedRemarks');
    var items = [];
    var findingGroups = {};
    var isUploading = false;

    function masterById(id) {
        var found = null;
        masters.forEach(function (master) {
            if (String(master.id) === String(id)) found = master;
        });
        return found;
    }

    function normaliseText(value) {
        return String(value || '').toLowerCase();
    }

    function masterText(master) {
        return normaliseText(
            master.category + ' ' + master.finding_name + ' '
            + (master.default_severity || '')
        );
    }

    function suggestMasterFromText(text) {
        var value = normaliseText(text);
        var rules = [
            {words: ['crack', 'broken', 'damage', 'wall', 'floor'], terms: ['crack', 'building', 'structural']},
            {words: ['leak', 'water', 'pipe', 'damp', 'seepage'], terms: ['leak', 'plumbing', 'water']},
            {words: ['lamp', 'light', 'lighting', 'dark', 'led', 'electrical'], terms: ['lighting', 'electrical', 'lamp']},
            {words: ['dirty', 'rubbish', 'trash', 'stain', 'clean'], terms: ['clean', 'cleanliness', 'housekeeping']},
            {words: ['hazard', 'unsafe', 'danger', 'slippery', 'exposed'], terms: ['safety', 'hazard']},
            {words: ['door', 'lock', 'gate', 'window'], terms: ['door', 'lock', 'access']},
            {words: ['paint', 'rust', 'corrosion'], terms: ['paint', 'rust', 'corrosion']}
        ];
        var best = null;
        rules.some(function (rule) {
            var matched = rule.words.some(function (word) {
                return value.indexOf(word) >= 0;
            });
            if (!matched) return false;
            best = masters.find(function (master) {
                var textValue = masterText(master);
                return rule.terms.some(function (term) {
                    return textValue.indexOf(term) >= 0;
                });
            }) || null;
            return best !== null;
        });
        return best;
    }

    function updateAiSuggestion(item) {
        var text = [
            item.file ? item.file.name : '',
            item.location ? item.location.value : '',
            item.remarks ? item.remarks.value : ''
        ].join(' ');
        var suggestion = suggestMasterFromText(text);
        if (!suggestion) {
            item.aiSuggestion.style.display = 'none';
            item.aiSuggestion.innerHTML = '';
            return;
        }
        item.aiSuggestedId = suggestion.id;
        item.aiSuggestion.style.display = 'block';
        item.aiSuggestion.innerHTML =
            '<strong>AI Defect Suggestion</strong>'
            + 'Suggested issue: '
            + escapeHtml(suggestion.category + ' - ' + suggestion.finding_name)
            + ' (' + escapeHtml(suggestion.default_severity || 'Medium') + ')'
            + '<br><button type="button">Use Suggestion</button>';
        item.aiSuggestion.querySelector('button').addEventListener('click', function () {
            fillFindingOptions(item.findingSelect, item.aiSuggestedId);
            setCardSeverity(item);
            item.status.textContent = 'Ready';
            item.card.classList.remove('invalid');
        });
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    function fillFindingOptions(select, selectedId) {
        select.innerHTML = '';
        var first = document.createElement('option');
        first.value = '';
        first.textContent = '-- Select issue / defect --';
        select.appendChild(first);
        masters.forEach(function (master) {
            var option = document.createElement('option');
            option.value = master.id;
            option.textContent = master.category + ' - '
                + master.finding_name + ' (' + master.default_severity + ')';
            if (String(master.id) === String(selectedId || '')) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }

    function makeField(labelText, control) {
        var wrapper = document.createElement('div');
        var label = document.createElement('label');
        label.textContent = labelText;
        wrapper.appendChild(label);
        wrapper.appendChild(control);
        return wrapper;
    }

    function updateSummary() {
        var uploaded = 0;
        var failed = 0;
        items.forEach(function (item) {
            if (item.uploaded) uploaded++;
            if (item.failed) failed++;
        });
        summary.textContent = items.length + ' selected · '
            + uploaded + ' uploaded'
            + (failed ? ' · ' + failed + ' failed' : '');
    }

    function setCardSeverity(item) {
        var master = masterById(item.findingSelect.value);
        item.severity.textContent = master
            ? 'Auto Severity: ' + master.default_severity
            : 'Severity: select an issue first.';
    }

    function createPhotoCard(file, index) {
        var item = {
            file: file,
            index: index,
            uploaded: false,
            failed: false,
            findingId: 0,
            objectUrl: URL.createObjectURL(file)
        };
        var card = document.createElement('article');
        card.className = 'bulk-photo-card';

        var head = document.createElement('div');
        head.className = 'bulk-photo-head';
        var image = document.createElement('img');
        image.src = item.objectUrl;
        image.alt = 'Inspection photo preview';
        var headText = document.createElement('div');
        var selectionLabel = document.createElement('label');
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = true;
        checkbox.className = 'bulk-checkbox';
        selectionLabel.appendChild(checkbox);
        selectionLabel.appendChild(document.createTextNode(' Select photo'));
        var fileName = document.createElement('div');
        fileName.className = 'bulk-photo-name';
        fileName.textContent = (index + 1) + '. ' + file.name;
        var status = document.createElement('div');
        status.className = 'bulk-photo-status';
        status.textContent = 'Waiting for issue';
        headText.appendChild(selectionLabel);
        headText.appendChild(fileName);
        headText.appendChild(status);
        head.appendChild(image);
        head.appendChild(headText);
        card.appendChild(head);

        var findingSelect = document.createElement('select');
        fillFindingOptions(findingSelect, '');
        var severity = document.createElement('div');
        severity.className = 'info';
        severity.textContent = 'Severity: select an issue first.';
        var aiSuggestion = document.createElement('div');
        aiSuggestion.className = 'ai-suggestion';
        var location = document.createElement('input');
        location.value = '';
        location.placeholder = defaultLocation
            ? 'Example: ' + defaultLocation
            : 'Example: Block A, Level 3';
        var remarks = document.createElement('textarea');
        remarks.rows = 2;
        remarks.placeholder = 'Remarks / manual notes';

        findingSelect.addEventListener('change', function () {
            setCardSeverity(item);
            card.classList.remove('invalid');
        });
        location.addEventListener('input', function () {
            card.classList.remove('invalid');
            updateAiSuggestion(item);
        });
        remarks.addEventListener('input', function () {
            updateAiSuggestion(item);
        });

        card.appendChild(makeField('Issue / Defect Found (auto)', findingSelect));
        card.appendChild(aiSuggestion);
        card.appendChild(severity);
        card.appendChild(makeField('Location (manual entry)', location));
        card.appendChild(makeField('Remarks (manual entry)', remarks));

        item.card = card;
        item.checkbox = checkbox;
        item.findingSelect = findingSelect;
        item.severity = severity;
        item.aiSuggestion = aiSuggestion;
        item.aiSuggestedId = '';
        item.location = location;
        item.remarks = remarks;
        item.status = status;
        updateAiSuggestion(item);
        return item;
    }

    function renderFiles(files) {
        items.forEach(function (item) {
            if (item.objectUrl) URL.revokeObjectURL(item.objectUrl);
        });
        items = [];
        findingGroups = {};
        photoGrid.innerHTML = '';

        files.forEach(function (file, index) {
            var item = createPhotoCard(file, index);
            items.push(item);
            photoGrid.appendChild(item.card);
        });
        toolbar.hidden = items.length === 0;
        saveArea.hidden = items.length === 0;
        progress.style.display = 'none';
        saveButton.disabled = false;
        saveButton.textContent = 'Save Issue Photos';
        updateSummary();
    }

    photoInput.addEventListener('change', function () {
        var selected = Array.prototype.slice.call(photoInput.files || []);
        if (selected.length > maximumSelection) {
            alert('Maximum remaining images allowed: '
                + maximumSelection + '.');
            photoInput.value = '';
            renderFiles([]);
            return;
        }

        var error = '';
        selected.forEach(function (file) {
            var typeAllowed = ['image/jpeg', 'image/png', 'image/webp']
                .indexOf(file.type) >= 0;
            var extensionAllowed = /\.(jpe?g|png|webp)$/i.test(file.name || '');
            if (!typeAllowed && !extensionAllowed) {
                error = 'Only JPG, PNG and WebP are allowed.';
            }
            if (file.size > (8 * 1024 * 1024)) {
                error = 'Each image must be 8 MB or less.';
            }
        });
        if (error) {
            alert(error);
            photoInput.value = '';
            renderFiles([]);
            return;
        }
        renderFiles(selected);
    });

    document.getElementById('bulkApplyButton').addEventListener('click', function () {
        if (!sharedFinding.value) {
            alert('Select an issue to apply.');
            return;
        }
        if (!sharedLocation.value.trim()) {
            alert('Enter the exact location to apply.');
            return;
        }
        items.forEach(function (item) {
            if (!item.checkbox.checked || item.uploaded) return;
            fillFindingOptions(item.findingSelect, sharedFinding.value);
            item.location.value = sharedLocation.value;
            item.remarks.value = sharedRemarks.value;
            setCardSeverity(item);
            item.card.classList.remove('invalid');
            item.status.textContent = 'Ready';
        });
    });

    document.getElementById('bulkSelectAllButton').addEventListener('click', function () {
        items.forEach(function (item) {
            if (!item.uploaded) item.checkbox.checked = true;
        });
    });
    document.getElementById('bulkClearSelectionButton').addEventListener('click', function () {
        items.forEach(function (item) {
            if (!item.uploaded) item.checkbox.checked = false;
        });
    });

    function groupKey(item) {
        return JSON.stringify([
            String(item.findingSelect.value),
            item.location.value.trim().toLowerCase(),
            item.remarks.value.trim().toLowerCase()
        ]);
    }

    function validateItems(pending) {
        var firstInvalid = null;
        pending.forEach(function (item) {
            var valid = item.findingSelect.value !== ''
                && item.location.value.trim() !== '';
            item.card.classList.toggle('invalid', !valid);
            if (!valid && !firstInvalid) firstInvalid = item;
        });
        if (firstInvalid) {
            firstInvalid.card.scrollIntoView({behavior: 'smooth', block: 'center'});
            alert('Select an issue and exact location for each highlighted photo.');
            return false;
        }
        return true;
    }

    function uploadOne(item, findingId) {
        var data = new FormData();
        data.append('action', 'bulk_upload_photo');
        data.append('csrf_token', csrfToken);
        data.append('inspection_id', String(inspectionId));
        data.append('property_id', String(propertyId));
        data.append('finding_id', String(findingId || 0));
        data.append('finding_master_id', item.findingSelect.value);
        data.append('finding_location', item.location.value.trim());
        data.append('remarks', item.remarks.value.trim());
        data.append('photo_caption', item.file.name);
        data.append('image', item.file, item.file.name);

        return fetch(window.location.href, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) {
            return response.text().then(function (body) {
                var result;
                try {
                    result = JSON.parse(body);
                } catch (error) {
                    throw new Error('Invalid server response. Your login may have expired.');
                }
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'Upload failed.');
                }
                return result;
            });
        });
    }

    saveButton.addEventListener('click', function () {
        if (isUploading) return;
        var pending = items.filter(function (item) {
            return !item.uploaded && item.checkbox.checked;
        });
        if (!pending.length) {
            alert('Select at least one photo to save.');
            return;
        }
        if (!validateItems(pending)) return;

        isUploading = true;
        saveButton.disabled = true;
        photoInput.disabled = true;
        progress.style.display = 'block';
        var completed = 0;
        var successCount = 0;
        var failedCount = 0;

        function next(position) {
            if (position >= pending.length) {
                isUploading = false;
                updateSummary();
                if (failedCount === 0) {
                    progressText.textContent = successCount
                        + ' image(s) saved. Refreshing…';
                    window.location.href = 'inspection_view.php?id=' + inspectionId
                        + '&property_id=' + propertyId
                        + '&bulk_added=' + successCount;
                } else {
                    progressText.textContent = successCount + ' successful, '
                        + failedCount + ' failed. Fix the issue and press Retry Failed Uploads.';
                    saveButton.disabled = false;
                    saveButton.textContent = 'Retry Failed Uploads';
                    photoInput.disabled = true;
                }
                return;
            }

            var item = pending[position];
            var key = groupKey(item);
            var existingFindingId = findingGroups[key] || 0;
            item.status.textContent = 'Uploading…';
            item.failed = false;
            item.card.classList.remove('failed');

            uploadOne(item, existingFindingId).then(function (result) {
                item.uploaded = true;
                item.findingId = Number(result.finding_id || 0);
                findingGroups[key] = item.findingId;
                item.status.textContent = 'Uploaded';
                item.card.classList.add('uploaded');
                item.checkbox.checked = false;
                item.checkbox.disabled = true;
                item.findingSelect.disabled = true;
                item.location.disabled = true;
                item.remarks.disabled = true;
                successCount++;
            }).catch(function (error) {
                item.failed = true;
                item.status.textContent = 'Failed: ' + error.message;
                item.card.classList.add('failed');
                failedCount++;
            }).then(function () {
                completed++;
                var percent = Math.round((completed / pending.length) * 100);
                progressBar.style.width = percent + '%';
                progressText.textContent = 'Uploading ' + completed
                    + ' / ' + pending.length + '…';
                updateSummary();
                next(position + 1);
            });
        }

        next(0);
    });
})();
</script>
<?php endif; ?>

</div>
<?php require __DIR__ . '/footer.php'; ?>
