<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';

$pageTitle = 'Inspection Module Check';
$activeMenu = 'inspection';

$currentPropertyId = (int) (
    $propertyPortalUser['property_id']
    ?? $_SESSION['property_id']
    ?? 0
);

$ready = cpmsInspectionTablesReady($conn);
$summary = $ready
    ? cpmsInspectionSummary($conn, $currentPropertyId)
    : [];

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.inspection-check-wrap{max-width:960px;margin:0 auto}
.inspection-check-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.06)}
.inspection-check-status{display:inline-flex;padding:8px 12px;border-radius:999px;font-weight:800;font-size:13px}
.inspection-check-ok{background:#dcfce7;color:#166534}
.inspection-check-bad{background:#fee2e2;color:#991b1b}
.inspection-check-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:22px}
.inspection-check-kpi{padding:16px;border:1px solid #e5e7eb;border-radius:13px;background:#f8fafc}
.inspection-check-kpi strong{display:block;font-size:27px;color:#0f172a}
.inspection-check-kpi span{font-size:12px;color:#64748b}
.inspection-check-note{margin-top:18px;padding:14px;border-radius:12px;background:#eff6ff;color:#1e3a8a;line-height:1.6}
@media(max-width:700px){.inspection-check-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<div class="inspection-check-wrap">
    <div class="inspection-check-card">
        <h1>Inspection & Compliance — Sprint 2.1</h1>

        <p>
            Database and backend foundation check for Property ID
            <strong><?php echo $currentPropertyId; ?></strong>.
        </p>

        <span class="inspection-check-status <?php
            echo $ready ? 'inspection-check-ok' : 'inspection-check-bad';
        ?>">
            <?php echo $ready
                ? 'READY — All inspection tables detected'
                : 'NOT READY — Import the SQL file first'; ?>
        </span>

        <?php if ($ready): ?>
            <div class="inspection-check-grid">
                <div class="inspection-check-kpi">
                    <strong><?php echo (int) $summary['total']; ?></strong>
                    <span>Total inspections</span>
                </div>
                <div class="inspection-check-kpi">
                    <strong><?php echo (int) $summary['submitted']; ?></strong>
                    <span>Submitted</span>
                </div>
                <div class="inspection-check-kpi">
                    <strong><?php echo (int) $summary['action_required']; ?></strong>
                    <span>Action required</span>
                </div>
                <div class="inspection-check-kpi">
                    <strong><?php echo (int) $summary['overdue']; ?></strong>
                    <span>Overdue</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="inspection-check-note">
            This page is only a technical verification page. The Inspector
            submission portal and Supervisor Review screen will be added in
            Sprint 2.2 and Sprint 2.3.
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
