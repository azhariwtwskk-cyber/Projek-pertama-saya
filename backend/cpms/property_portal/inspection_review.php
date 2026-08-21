<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';
require_once dirname(__DIR__) . '/includes/corrective_action_service.php';

cpmsRequire('inspection.approve', $conn);

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

$inspectionId = (int) ($_GET['id'] ?? $_POST['inspection_id'] ?? 0);
$record = cpmsInspectionFind($conn, $currentPropertyId, $inspectionId);

if (!$record) {
    http_response_code(404);
    exit('Inspection record not found.');
}

if (!cpmsInspectionCanReview($propertyPortalUser)) {
    http_response_code(403);
    exit('You do not have permission to review inspections.');
}

$pageTitle = 'Review ' . $record['inspection_no'];
$activeMenu = 'inspection';
$errors = [];
$success = '';
$workOrderReady = cpmsInspectionWorkOrderColumnReady($conn);
$linkedWorkOrder = $workOrderReady
    ? cpmsInspectionWorkOrderByInspection(
        $conn,
        $currentPropertyId,
        $inspectionId
    )
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsInspectionVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token is invalid. Refresh and try again.';
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if ($action === 'create_work_order') {
        if ($remarks === '') {
            $errors[] = 'Supervisor instruction is required.';
        }

        if (!$workOrderReady) {
            $errors[] = 'Import the Sprint 2.4 SQL migration first.';
        }

        if (!$errors) {
            try {
                $workOrder = cpmsInspectionCreateWorkOrder(
                    $conn,
                    $currentPropertyId,
                    $inspectionId,
                    $currentUserId,
                    $currentUserName,
                    $remarks
                );

                header(
                    'Location: inspection_review.php?id='
                    . $inspectionId
                    . '&work_order_created=1'
                );
                exit;
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    } else {
        $statusMap = [
            'start_review' => 'Under Review',
            'request_action' => 'Action Required',
            'reject' => 'Rejected',
            'verify' => 'Verified',
            'close' => 'Closed',
            'return_draft' => 'Draft',
        ];

        if (!isset($statusMap[$action])) {
            $errors[] = 'Select a valid review action.';
        }

        if (
            in_array($action, ['request_action', 'reject'], true)
            && $remarks === ''
        ) {
            $errors[] = 'Supervisor remarks are required for this action.';
        }

        if (!$errors) {
            try {
                $updated = cpmsInspectionUpdateStatus(
                    $conn,
                    $currentPropertyId,
                    $inspectionId,
                    $statusMap[$action],
                    $remarks,
                    $currentUserId,
                    $currentUserName
                );

                if (!$updated) {
                    throw new RuntimeException(
                        'Unable to update inspection.'
                    );
                }

                header(
                    'Location: inspection_review.php?id='
                    . $inspectionId
                    . '&updated=1'
                );
                exit;
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    }
}

if (isset($_GET['updated'])) {
    $success = 'Inspection review status updated successfully.';
}

if (isset($_GET['work_order_created'])) {
    $success = 'Work Order created and linked successfully.';
}

$linkedWorkOrder = $workOrderReady
    ? cpmsInspectionWorkOrderByInspection(
        $conn,
        $currentPropertyId,
        $inspectionId
    )
    : null;

$record = cpmsInspectionFind($conn, $currentPropertyId, $inspectionId);
$history = cpmsInspectionHistory(
    $conn,
    $currentPropertyId,
    $inspectionId
);
$images = cpmsInspectionImages(
    $conn,
    $currentPropertyId,
    $inspectionId
);
$correctiveActions = cpmsActionsByInspection(
    $conn,
    $currentPropertyId,
    $inspectionId
);

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/inspection-module.css">
<link rel="stylesheet" href="assets/inspection-review.css">

<div class="inspection-wrap">
    <div class="inspection-page-head">
        <div>
            <span class="inspection-eyebrow">SUPERVISOR REVIEW</span>
            <h1><?php echo cpmsInspectionEscape(
                (string) $record['inspection_no']
            ); ?></h1>
            <p>
                <?php echo cpmsInspectionEscape(
                    (string) $record['location']
                ); ?>
                · Current status:
                <strong><?php echo cpmsInspectionEscape(
                    (string) $record['status']
                ); ?></strong>
            </p>
        </div>

        <div class="inspection-head-actions">
            <a class="inspection-btn"
               href="inspection_view.php?id=<?php echo $inspectionId; ?>">
                View Report
            </a>
            <a class="inspection-btn" href="inspections.php">
                All Inspections
            </a>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="inspection-alert inspection-alert-success">
            <?php echo cpmsInspectionEscape($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="inspection-alert inspection-alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?php echo cpmsInspectionEscape($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="inspection-review-grid">
        <section class="inspection-panel">
            <div class="inspection-panel-head">
                <h2>Inspection Summary</h2>
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
                    <dt>Priority</dt>
                    <dd><?php echo cpmsInspectionEscape(
                        (string) $record['priority']
                    ); ?></dd>
                </div>
                <div>
                    <dt>Category</dt>
                    <dd><?php echo cpmsInspectionEscape(
                        (string) $record['category']
                    ); ?></dd>
                </div>
                <div>
                    <dt>Photos</dt>
                    <dd><?php echo count($images); ?>/30</dd>
                </div>
            </dl>

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

        <section class="inspection-panel">
            <h2>Supervisor Decision</h2>

            <form method="post" class="inspection-review-form">
                <input type="hidden" name="csrf_token"
                       value="<?php echo cpmsInspectionEscape(
                           cpmsInspectionCsrfToken()
                       ); ?>">
                <input type="hidden" name="inspection_id"
                       value="<?php echo $inspectionId; ?>">

                <label>
                    <span>Remarks / Instructions</span>
                    <textarea name="remarks" rows="5"
                        placeholder="Example: Assign repair to maintenance team and upload after-repair photos."></textarea>
                </label>

                <div class="inspection-review-actions">
                    <button type="submit" name="action"
                            value="start_review"
                            class="inspection-btn">
                        Start Review
                    </button>
                    <button type="submit" name="action"
                            value="request_action"
                            class="inspection-btn inspection-btn-warning">
                        Action Required
                    </button>
                    <button type="submit" name="action"
                            value="reject"
                            class="inspection-btn inspection-btn-danger">
                        Reject / Return
                    </button>
                    <button type="submit" name="action"
                            value="verify"
                            class="inspection-btn inspection-btn-success">
                        Verify
                    </button>
                    <button type="submit" name="action"
                            value="close"
                            class="inspection-btn inspection-btn-primary">
                        Close
                    </button>
                </div>
            </form>

            <div class="inspection-work-order-note">
                <strong>Work Order Integration</strong>

                <?php if (!$workOrderReady): ?>
                    <p>
                        Import
                        <code>inspection_work_order_integration_v1.sql</code>
                        before creating a Work Order.
                    </p>
                <?php elseif ($linkedWorkOrder): ?>
                    <p>
                        Linked Work Order:
                        <strong><?php echo cpmsInspectionEscape(
                            (string) $linkedWorkOrder[
                                'work_order_reference'
                            ]
                        ); ?></strong>
                    </p>
                    <p>
                        Status:
                        <?php echo cpmsInspectionEscape(
                            (string) $linkedWorkOrder['status']
                        ); ?>
                    </p>
                    <a class="inspection-btn inspection-btn-primary"
                       href="work_orders.php">
                        Open Work Orders
                    </a>
                <?php else: ?>
                    <p>
                        Create one Work Order using this inspection's
                        category, priority, location, finding and
                        recommendation.
                    </p>

                    <form method="post" class="inspection-work-order-form" id="inspection-work-order-form">
                        <input type="hidden" name="csrf_token"
                               value="<?php echo cpmsInspectionEscape(
                                   cpmsInspectionCsrfToken()
                               ); ?>">
                        <input type="hidden" name="inspection_id"
                               value="<?php echo $inspectionId; ?>">
                        <input type="hidden" name="remarks"
                               value="<?php echo cpmsInspectionEscape(
                                   (string) ($_POST['remarks'] ?? '')
                               ); ?>">

                        <button
                            type="submit"
                            name="action"
                            value="create_work_order"
                            class="inspection-btn inspection-btn-primary"
                        >
                            Create Work Order
                        </button>
                    </form>

                    <small class="inspection-work-order-help">
                        Masukkan arahan supervisor di ruangan Remarks /
                        Instructions terlebih dahulu, kemudian klik butang ini.
                    </small>
                <?php endif; ?>
            </div>
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
                    + Assign Staff / Contractor
                </a>
            <?php endif; ?>
        </div>
        <?php if (!$correctiveActions): ?>
            <div class="inspection-empty">No corrective action assigned.</div>
        <?php else: ?>
            <div class="inspection-table-wrap">
                <table class="inspection-table">
                    <thead><tr><th>Action</th><th>Assignee</th><th>Due</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($correctiveActions as $correctiveAction): ?>
                        <tr>
                            <td><strong><?php echo cpmsActionEscape((string) $correctiveAction['action_no']); ?></strong><br>
                                <?php echo cpmsActionEscape((string) $correctiveAction['title']); ?></td>
                            <td><?php echo cpmsActionEscape((string) $correctiveAction['assigned_name']); ?></td>
                            <td><?php echo cpmsActionEscape((string) ($correctiveAction['due_date'] ?: '-')); ?></td>
                            <td><?php echo cpmsActionEscape((string) $correctiveAction['status']); ?></td>
                            <td><a href="inspection_action_view.php?id=<?php echo (int) $correctiveAction['id']; ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="inspection-panel">
        <div class="inspection-panel-head">
            <h2>Review & Status History</h2>
            <span><?php echo count($history); ?> event(s)</span>
        </div>

        <?php if (!$history): ?>
            <div class="inspection-empty">No status history found.</div>
        <?php else: ?>
            <div class="inspection-history">
                <?php foreach ($history as $item): ?>
                    <article class="inspection-history-item">
                        <span class="inspection-history-dot"></span>
                        <div>
                            <strong>
                                <?php echo cpmsInspectionEscape(
                                    (string) (
                                        $item['old_status']
                                        ?: 'Created'
                                    )
                                ); ?>
                                →
                                <?php echo cpmsInspectionEscape(
                                    (string) $item['new_status']
                                ); ?>
                            </strong>
                            <p><?php echo nl2br(cpmsInspectionEscape(
                                (string) (
                                    $item['remarks']
                                    ?: 'No remarks.'
                                )
                            )); ?></p>
                            <small>
                                <?php echo cpmsInspectionEscape(
                                    (string) (
                                        $item['changed_by_name']
                                        ?: 'System'
                                    )
                                ); ?>
                                ·
                                <?php echo cpmsInspectionEscape(
                                    date(
                                        'd/m/Y h:i A',
                                        strtotime((string) $item['created_at'])
                                    )
                                ); ?>
                            </small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>


<script>
(function () {
    'use strict';

    var reviewForm = document.querySelector('.inspection-review-form');
    var workOrderForm = document.getElementById('inspection-work-order-form');

    if (!reviewForm || !workOrderForm) {
        return;
    }

    workOrderForm.addEventListener('submit', function () {
        var visibleRemarks = reviewForm.querySelector(
            'textarea[name="remarks"]'
        );
        var hiddenRemarks = workOrderForm.querySelector(
            'input[name="remarks"]'
        );

        if (visibleRemarks && hiddenRemarks) {
            hiddenRemarks.value = visibleRemarks.value;
        }
    });
}());
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
