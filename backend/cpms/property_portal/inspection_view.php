<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';
require_once dirname(__DIR__) . '/includes/corrective_action_service.php';

cpmsRequire('inspection.view', $conn);

if (!isset($propertyPortalUser) || !is_array($propertyPortalUser)) {
    $propertyPortalUser = [];
}

$currentPropertyId = cpmsInspectionPropertyId($propertyPortalUser);

if ($currentPropertyId < 1) {
    http_response_code(403);
    exit('Property context is unavailable.');
}

if (!cpmsInspectionTablesReady($conn)) {
    exit('Inspection tables are not ready. Import Sprint 2.1 SQL first.');
}

$currentUserId = cpmsInspectionCurrentUserId($propertyPortalUser);
$currentUserName = cpmsInspectionCurrentUserName($propertyPortalUser);

$inspectionId = (int) ($_GET['id'] ?? 0);
$record = cpmsInspectionFind($conn, $currentPropertyId, $inspectionId);

if (!$record) {
    http_response_code(404);
    exit('Inspection record not found.');
}

$pageTitle = 'Inspection ' . $record['inspection_no'];
$activeMenu = 'inspection';
$images = cpmsInspectionImages(
    $conn,
    $currentPropertyId,
    $inspectionId
);
$linkedWorkOrder = cpmsInspectionWorkOrderColumnReady($conn)
    ? cpmsInspectionWorkOrderByInspection(
        $conn,
        $currentPropertyId,
        $inspectionId
    )
    : null;
$correctiveActions = cpmsActionsByInspection(
    $conn,
    $currentPropertyId,
    $inspectionId
);
$success = '';
$error = '';

if (isset($_GET['created'])) {
    $success = 'Inspection record created successfully.';
}

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/inspection-module.css">

<div class="inspection-wrap">
    <div class="inspection-page-head">
        <div>
            <span class="inspection-eyebrow">INSPECTION REPORT</span>
            <h1><?php echo cpmsInspectionEscape(
                (string) $record['inspection_no']
            ); ?></h1>
            <p><?php echo cpmsInspectionEscape(
                (string) $record['location']
            ); ?></p>
        </div>
        <div class="inspection-head-actions">
            <a class="inspection-btn" href="inspections.php">Back</a>
            <?php if (cpmsCan('inspection.report.export', $conn)): ?>
                <a class="inspection-btn"
                   href="inspection_report.php?id=<?php echo $inspectionId; ?>">
                    Summary Report / PDF
                </a>
            <?php endif; ?>
            <?php if (cpmsCan('inspection.approve', $conn)): ?>
                <a class="inspection-btn"
                   href="inspection_review.php?id=<?php echo $inspectionId; ?>">
                    Supervisor Review
                </a>
            <?php endif; ?>
            <?php if (cpmsCan('inspection.update', $conn)): ?>
                <a class="inspection-btn inspection-btn-primary"
                   href="inspection_upload.php?id=<?php echo $inspectionId; ?>">
                    Upload Photos (<?php echo count($images); ?>/30)
                </a>
            <?php endif; ?>
            <?php if (cpmsCan('inspection.checklist.view', $conn)): ?>
                <a class="inspection-btn inspection-btn-primary"
                   href="inspection_checklist.php?inspection_id=<?php echo $inspectionId; ?>">
                    Checklist & Score
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="inspection-alert inspection-alert-success">
            <?php echo cpmsInspectionEscape($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($linkedWorkOrder): ?>
        <section class="inspection-alert inspection-alert-success">
            <strong>Linked Work Order:</strong>
            <?php echo cpmsInspectionEscape(
                (string) $linkedWorkOrder['work_order_reference']
            ); ?>
            · Status:
            <?php echo cpmsInspectionEscape(
                (string) $linkedWorkOrder['status']
            ); ?>
            · <a href="work_orders.php">Open Work Orders</a>
        </section>
    <?php endif; ?>

    <div class="inspection-detail-grid">
        <section class="inspection-panel">
            <div class="inspection-panel-head">
                <h2>Inspection Details</h2>
                <span class="inspection-badge status-badge">
                    <?php echo cpmsInspectionEscape(
                        (string) $record['status']
                    ); ?>
                </span>
            </div>

            <dl class="inspection-definition-grid">
                <div>
                    <dt>Date</dt>
                    <dd><?php echo cpmsInspectionEscape(
                        date(
                            'd/m/Y',
                            strtotime((string) $record['inspection_date'])
                        )
                    ); ?></dd>
                </div>
                <div>
                    <dt>Type</dt>
                    <dd><?php echo cpmsInspectionEscape(
                        (string) $record['inspection_type']
                    ); ?></dd>
                </div>
                <div>
                    <dt>Category</dt>
                    <dd><?php echo cpmsInspectionEscape(
                        (string) $record['category']
                    ); ?></dd>
                </div>
                <div>
                    <dt>Priority</dt>
                    <dd><?php echo cpmsInspectionEscape(
                        (string) $record['priority']
                    ); ?></dd>
                </div>
                <div class="inspection-full">
                    <dt>Reported By</dt>
                    <dd><?php echo cpmsInspectionEscape(
                        (string) ($record['reported_by_name'] ?: '-')
                    ); ?></dd>
                </div>
            </dl>
        </section>

        <section class="inspection-panel">
            <h2>Description</h2>
            <div class="inspection-prose"><?php echo nl2br(
                cpmsInspectionEscape(
                    (string) ($record['description'] ?: 'No description.')
                )
            ); ?></div>

            <h2>Finding</h2>
            <div class="inspection-prose"><?php echo nl2br(
                cpmsInspectionEscape(
                    (string) ($record['finding'] ?: 'No finding recorded.')
                )
            ); ?></div>

            <h2>Recommendation</h2>
            <div class="inspection-prose"><?php echo nl2br(
                cpmsInspectionEscape(
                    (string) (
                        $record['recommendation']
                        ?: 'No recommendation recorded.'
                    )
                )
            ); ?></div>
        </section>
    </div>

    <section class="inspection-panel">
        <div class="inspection-panel-head">
            <div>
                <h2>Corrective Actions</h2>
                <span><?php echo count($correctiveActions); ?> action(s)</span>
            </div>
            <?php if (cpmsCan('inspection.action.manage', $conn)): ?>
                <a class="inspection-btn inspection-btn-primary"
                   href="inspection_action_create.php?inspection_id=<?php echo $inspectionId; ?>">
                    + Assign Corrective Action
                </a>
            <?php endif; ?>
        </div>
        <?php if (!$correctiveActions): ?>
            <div class="inspection-empty">No corrective action assigned.</div>
        <?php else: ?>
            <div class="inspection-table-wrap">
                <table class="inspection-table">
                    <thead><tr><th>Action</th><th>Assigned To</th><th>Priority</th><th>Due</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($correctiveActions as $action): ?>
                        <tr>
                            <td><strong><?php echo cpmsActionEscape((string) $action['action_no']); ?></strong><br>
                                <small><?php echo cpmsActionEscape((string) $action['title']); ?></small></td>
                            <td><?php echo cpmsActionEscape((string) $action['assigned_name']); ?></td>
                            <td><?php echo cpmsActionEscape((string) $action['priority']); ?></td>
                            <td><?php echo cpmsActionEscape((string) ($action['due_date'] ?: '-')); ?></td>
                            <td><?php echo cpmsActionEscape((string) $action['status']); ?></td>
                            <td><a href="inspection_action_view.php?id=<?php echo (int) $action['id']; ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="inspection-panel">
        <div class="inspection-panel-head">
            <div>
                <h2>Photo Timeline</h2>
                <span>Maximum 30 photos per inspection</span>
            </div>
            <?php if (cpmsCan('inspection.update', $conn)): ?>
                <a class="inspection-link"
                   href="inspection_upload.php?id=<?php echo $inspectionId; ?>">
                    Add Photos
                </a>
            <?php endif; ?>
        </div>

        <?php if (!$images): ?>
            <div class="inspection-empty">
                <strong>No photo uploaded.</strong>
                <p>Upload finding, before, during, after or evidence photos.</p>
            </div>
        <?php else: ?>
            <div class="inspection-gallery">
                <?php foreach ($images as $image): ?>
                    <figure class="inspection-photo-card">
                        <a href="../<?php echo cpmsInspectionEscape(
                            (string) $image['image_path']
                        ); ?>" target="_blank" rel="noopener">
                            <img src="../<?php echo cpmsInspectionEscape(
                                (string) $image['image_path']
                            ); ?>" alt="">
                        </a>
                        <figcaption>
                            <span class="inspection-badge">
                                <?php echo cpmsInspectionEscape(
                                    (string) $image['image_type']
                                ); ?>
                            </span>
                            <strong><?php echo cpmsInspectionEscape(
                                (string) (
                                    $image['caption']
                                    ?: $image['original_name']
                                )
                            ); ?></strong>
                            <small>
                                <?php echo cpmsInspectionEscape(
                                    date(
                                        'd/m/Y h:i A',
                                        strtotime((string) $image['created_at'])
                                    )
                                ); ?>
                                · <?php echo cpmsInspectionEscape(
                                    (string) (
                                        $image['uploaded_by_name']
                                        ?: 'User'
                                    )
                                ); ?>
                            </small>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
