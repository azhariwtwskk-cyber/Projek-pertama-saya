<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';

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

$pageTitle = 'Inspections';
$activeMenu = 'inspection';

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$allowedFilters = [
    '',
    'Draft',
    'Submitted',
    'Under Review',
    'Action Required',
    'Verified',
    'Closed',
];

if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = '';
}

$summary = cpmsInspectionSummary($conn, $currentPropertyId);
$records = cpmsInspectionList(
    $conn,
    $currentPropertyId,
    $statusFilter,
    200
);

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/inspection-module.css">

<div class="inspection-wrap">
    <div class="inspection-page-head">
        <div>
            <span class="inspection-eyebrow">INSPECTION & COMPLIANCE</span>
            <h1>Inspection Records</h1>
            <p>Record findings, upload evidence and submit reports for review.</p>
        </div>

        <?php if (cpmsCan('inspection.create', $conn)): ?>
            <a class="inspection-btn inspection-btn-primary"
               href="inspection_create.php">
                + New Inspection
            </a>
        <?php endif; ?>
        <?php if (cpmsCan('inspection.checklist.view', $conn)): ?>
            <a class="inspection-btn"
               href="checklist_templates.php">
                Checklist Templates
            </a>
        <?php endif; ?>
    </div>

    <div class="inspection-kpi-grid">
        <a class="inspection-kpi" href="inspections.php">
            <strong><?php echo (int) $summary['total']; ?></strong>
            <span>Total</span>
        </a>
        <a class="inspection-kpi" href="inspections.php?status=Draft">
            <strong><?php echo (int) $summary['draft']; ?></strong>
            <span>Draft</span>
        </a>
        <a class="inspection-kpi" href="inspections.php?status=Submitted">
            <strong><?php echo (int) $summary['submitted']; ?></strong>
            <span>Submitted</span>
        </a>
        <a class="inspection-kpi" href="inspections.php?status=Action%20Required">
            <strong><?php echo (int) $summary['action_required']; ?></strong>
            <span>Action Required</span>
        </a>
        <a class="inspection-kpi" href="inspections.php?status=Verified">
            <strong><?php echo (int) $summary['verified']; ?></strong>
            <span>Verified / Closed</span>
        </a>
    </div>

    <div class="inspection-panel">
        <div class="inspection-panel-head">
            <h2><?php echo $statusFilter !== ''
                ? cpmsInspectionEscape($statusFilter)
                : 'All Inspections'; ?></h2>
            <span><?php echo count($records); ?> record(s)</span>
        </div>

        <?php if (!$records): ?>
            <div class="inspection-empty">
                <strong>No inspection record found.</strong>
                <p>Create the first inspection for this property.</p>
            </div>
        <?php else: ?>
            <div class="inspection-table-wrap">
                <table class="inspection-table">
                    <thead>
                        <tr>
                            <th>Inspection No.</th>
                            <th>Date</th>
                            <th>Location</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Photos</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td>
                                <strong><?php echo cpmsInspectionEscape(
                                    (string) $record['inspection_no']
                                ); ?></strong>
                            </td>
                            <td><?php echo cpmsInspectionEscape(
                                date(
                                    'd/m/Y',
                                    strtotime((string) $record['inspection_date'])
                                )
                            ); ?></td>
                            <td><?php echo cpmsInspectionEscape(
                                (string) $record['location']
                            ); ?></td>
                            <td><?php echo cpmsInspectionEscape(
                                (string) $record['category']
                            ); ?></td>
                            <td>
                                <span class="inspection-badge priority-<?php
                                    echo strtolower(cpmsInspectionEscape(
                                        (string) $record['priority']
                                    ));
                                ?>">
                                    <?php echo cpmsInspectionEscape(
                                        (string) $record['priority']
                                    ); ?>
                                </span>
                            </td>
                            <td>
                                <span class="inspection-badge status-badge">
                                    <?php echo cpmsInspectionEscape(
                                        (string) $record['status']
                                    ); ?>
                                </span>
                            </td>
                            <td><?php echo (int) $record['image_count']; ?>/30</td>
                            <td>
                                <a class="inspection-link"
                                   href="inspection_view.php?id=<?php
                                   echo (int) $record['id']; ?>">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
