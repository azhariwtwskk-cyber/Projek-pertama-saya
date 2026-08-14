<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';
require_once dirname(__DIR__) . '/includes/inspection_finding_service.php';
require_once dirname(__DIR__) . '/includes/inspection_assignment_service.php';

cpmsRequire('inspection.hq_report.manage', $conn);

if (!isset($propertyPortalUser) || !is_array($propertyPortalUser)) {
    $propertyPortalUser = [];
}
$propertyId = cpmsInspectionPropertyId($propertyPortalUser);
if ($propertyId < 1) {
    http_response_code(403);
    exit('Property context is unavailable.');
}

$ready = cpmsFindingTablesReady($conn) && cpmsAssignmentTablesReady($conn);
$summary = [
    'reports' => 0,
    'unassigned' => 0,
    'active' => 0,
    'overdue' => 0,
    'supervisor' => 0,
    'hq' => 0,
];
$records = [];
$filter = trim((string) ($_GET['filter'] ?? 'all'));
$search = strtolower(trim((string) ($_GET['q'] ?? '')));
$allowedFilters = ['all', 'new', 'active', 'supervisor', 'hq'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

if ($ready) {
    $summaryStmt = $conn->prepare(
        'SELECT
            (SELECT COUNT(DISTINCT r.id)
             FROM inspection_reports r
             INNER JOIN inspection_findings f
                ON f.inspection_id = r.id AND f.property_id = r.property_id
             WHERE r.property_id = ? AND r.status <> "Draft") AS reports,
            (SELECT COUNT(*)
             FROM inspection_findings f
             INNER JOIN inspection_reports r
                ON r.id = f.inspection_id AND r.property_id = f.property_id
             LEFT JOIN inspection_finding_action_links l
                ON l.finding_id = f.id AND l.property_id = f.property_id
             WHERE f.property_id = ? AND r.status <> "Draft"
               AND l.id IS NULL) AS unassigned,
            (SELECT COUNT(*) FROM inspection_corrective_actions a
             WHERE a.property_id = ?
               AND a.status IN ("Open", "In Progress")) AS active,
            (SELECT COUNT(*) FROM inspection_corrective_actions a
             WHERE a.property_id = ? AND a.due_date < CURDATE()
               AND a.status NOT IN ("Verified", "Closed")) AS overdue,
            (SELECT COUNT(*) FROM inspection_finding_action_links l
             INNER JOIN inspection_corrective_actions a
                ON a.id = l.action_id AND a.property_id = l.property_id
             WHERE l.property_id = ? AND a.status = "Rectified"
               AND l.supervisor_status = "Pending Review") AS supervisor,
            (SELECT COUNT(*) FROM inspection_finding_action_links l
             INNER JOIN inspection_corrective_actions a
                ON a.id = l.action_id AND a.property_id = l.property_id
             WHERE l.property_id = ? AND a.status = "Rectified"
               AND l.supervisor_status = "Approved") AS hq'
    );
    if ($summaryStmt) {
        $summaryStmt->bind_param(
            'iiiiii',
            $propertyId,
            $propertyId,
            $propertyId,
            $propertyId,
            $propertyId,
            $propertyId
        );
        $summaryStmt->execute();
        $row = $summaryStmt->get_result()->fetch_assoc();
        $summaryStmt->close();
        foreach ($summary as $key => $value) {
            $summary[$key] = (int) ($row[$key] ?? 0);
        }
    }

    $stmt = $conn->prepare(
        'SELECT r.id, r.inspection_no, r.inspection_date, r.location,
                r.priority, r.status, r.reported_by_name, r.updated_at,
                COUNT(DISTINCT f.id) AS finding_count,
                SUM(l.id IS NULL) AS unassigned_count,
                SUM(a.status IN ("Open", "In Progress")) AS active_count,
                SUM(a.status = "Rectified"
                    AND l.supervisor_status = "Pending Review") AS supervisor_count,
                SUM(a.status = "Rectified"
                    AND l.supervisor_status = "Approved") AS hq_count,
                SUM(a.due_date < CURDATE()
                    AND a.status NOT IN ("Verified", "Closed")) AS overdue_count
         FROM inspection_reports r
         INNER JOIN inspection_findings f
            ON f.inspection_id = r.id AND f.property_id = r.property_id
         LEFT JOIN inspection_finding_action_links l
            ON l.finding_id = f.id AND l.property_id = f.property_id
         LEFT JOIN inspection_corrective_actions a
            ON a.id = l.action_id AND a.property_id = l.property_id
         WHERE r.property_id = ? AND r.status <> "Draft"
         GROUP BY r.id
         ORDER BY r.updated_at DESC, r.id DESC
         LIMIT 200'
    );
    if ($stmt) {
        $stmt->bind_param('i', $propertyId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $matchesFilter = $filter === 'all'
                || ($filter === 'new' && (int) $row['unassigned_count'] > 0)
                || ($filter === 'active' && (int) $row['active_count'] > 0)
                || ($filter === 'supervisor' && (int) $row['supervisor_count'] > 0)
                || ($filter === 'hq' && (int) $row['hq_count'] > 0);
            $haystack = strtolower(
                (string) $row['inspection_no'] . ' '
                . (string) $row['location'] . ' '
                . (string) $row['reported_by_name']
            );
            if ($matchesFilter
                && ($search === '' || strpos($haystack, $search) !== false)
            ) {
                $records[] = $row;
            }
        }
        $stmt->close();
    }
}

$pageTitle = 'HQ Inspection Inbox';
$activeMenu = 'hq_inspection';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/inspection-module.css">
<style>
.hqi-hero{display:flex;justify-content:space-between;gap:16px;align-items:center;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;border-radius:18px;padding:24px;margin-bottom:16px}.hqi-hero h1{margin:4px 0}.hqi-hero p{margin:0;color:#dbeafe}.hqi-hero-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.hqi-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:16px}.hqi-kpi{display:block;background:#fff;border:1px solid #e2e8f0;border-radius:13px;padding:14px;text-decoration:none;color:#0f172a}.hqi-kpi strong{display:block;font-size:25px}.hqi-kpi span{color:#64748b;font-size:12px}.hqi-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.hqi-tools label{font-size:12px;color:#64748b}.hqi-tools input{min-width:260px}.hqi-tabs{display:flex;gap:7px;flex-wrap:wrap;margin:14px 0}.hqi-tabs a{padding:8px 12px;border-radius:999px;background:#e2e8f0;text-decoration:none;color:#334155;font-weight:800}.hqi-tabs a.active{background:#173b73;color:#fff}.hqi-report{display:grid;grid-template-columns:1.3fr repeat(4,.55fr) auto;gap:12px;align-items:center;border-bottom:1px solid #e2e8f0;padding:15px 0}.hqi-report:last-child{border:0}.hqi-report small{display:block;color:#64748b}.hqi-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:800}.hqi-badge.alert{background:#fee2e2;color:#991b1b}.hqi-badge.wait{background:#fef3c7;color:#92400e}.hqi-badge.ok{background:#dcfce7;color:#166534}.hqi-empty{padding:30px;text-align:center;color:#64748b}.hqi-warn{background:#fff7ed;color:#9a3412;padding:14px;border-radius:10px}@media(max-width:1000px){.hqi-kpis{grid-template-columns:repeat(3,1fr)}.hqi-report{grid-template-columns:1fr 1fr}.hqi-report .inspection-btn{justify-self:start}}@media(max-width:650px){.hqi-hero{align-items:stretch;flex-direction:column}.hqi-hero-actions{justify-content:flex-start}.hqi-kpis{grid-template-columns:repeat(2,1fr)}.hqi-report{grid-template-columns:1fr}.hqi-tools input{min-width:0;width:100%}}
</style>

<div class="inspection-wrap">
    <section class="hqi-hero">
        <div><small>PROPERTY RECTIFICATION CONTROL</small><h1>HQ Inspection Inbox</h1><p>Terima report, agihkan setiap finding dan pantau pembaikan sehingga HQ verification.</p></div>
        <div class="hqi-hero-actions">
            <a class="inspection-btn inspection-btn-primary" href="inspection_create.php?source=hq">+ Create Inspection</a>
            <a class="inspection-btn" href="inspections.php">All Inspections</a>
        </div>
    </section>

    <?php if (!$ready): ?>
        <div class="hqi-warn">Modul belum lengkap. Jalankan migration 20260810_0061 terlebih dahulu.</div>
    <?php else: ?>
    <section class="hqi-kpis">
        <a class="hqi-kpi" href="?filter=all"><strong><?php echo $summary['reports']; ?></strong><span>Reports Received</span></a>
        <a class="hqi-kpi" href="?filter=new"><strong><?php echo $summary['unassigned']; ?></strong><span>Unassigned Findings</span></a>
        <a class="hqi-kpi" href="?filter=active"><strong><?php echo $summary['active']; ?></strong><span>Staff In Progress</span></a>
        <a class="hqi-kpi" href="?filter=active"><strong><?php echo $summary['overdue']; ?></strong><span>Overdue Actions</span></a>
        <a class="hqi-kpi" href="?filter=supervisor"><strong><?php echo $summary['supervisor']; ?></strong><span>Supervisor Review</span></a>
        <a class="hqi-kpi" href="?filter=hq"><strong><?php echo $summary['hq']; ?></strong><span>Waiting HQ</span></a>
    </section>

    <section class="inspection-panel">
        <form class="hqi-tools" method="get">
            <div><label for="q">Search report / location / inspector</label><input id="q" name="q" value="<?php echo cpmsAssignmentEscape((string) ($_GET['q'] ?? '')); ?>" placeholder="Contoh: HQI atau Common Area"></div>
            <input type="hidden" name="filter" value="<?php echo cpmsAssignmentEscape($filter); ?>">
            <button class="inspection-btn inspection-btn-primary" type="submit">Search</button>
        </form>
        <nav class="hqi-tabs">
            <?php foreach (['all' => 'All', 'new' => 'New / Unassigned', 'active' => 'In Progress', 'supervisor' => 'Supervisor Review', 'hq' => 'Waiting HQ'] as $key => $label): ?>
                <a class="<?php echo $filter === $key ? 'active' : ''; ?>" href="?filter=<?php echo $key; ?>"><?php echo cpmsAssignmentEscape($label); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$records): ?><div class="hqi-empty">Tiada HQ inspection report dalam pilihan ini.</div><?php endif; ?>
        <?php foreach ($records as $record): ?>
            <article class="hqi-report">
                <div><strong><?php echo cpmsAssignmentEscape((string) $record['inspection_no']); ?></strong><small><?php echo cpmsAssignmentEscape((string) $record['inspection_date']); ?> · <?php echo cpmsAssignmentEscape((string) $record['location']); ?></small><small>Inspector: <?php echo cpmsAssignmentEscape((string) ($record['reported_by_name'] ?: '-')); ?></small></div>
                <div><small>Findings</small><strong><?php echo (int) $record['finding_count']; ?></strong></div>
                <div><small>Unassigned</small><span class="hqi-badge <?php echo (int) $record['unassigned_count'] > 0 ? 'alert' : 'ok'; ?>"><?php echo (int) $record['unassigned_count']; ?></span></div>
                <div><small>Supervisor</small><span class="hqi-badge wait"><?php echo (int) $record['supervisor_count']; ?></span></div>
                <div><small>Waiting HQ</small><span class="hqi-badge ok"><?php echo (int) $record['hq_count']; ?></span></div>
                <a class="inspection-btn inspection-btn-primary" href="inspection_hq_findings.php?id=<?php echo (int) $record['id']; ?>">Open Report</a>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
