<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$inspectorId = (int) $hqInspector['id'];
$propertyId = max(0, (int) ($_GET['property_id'] ?? 0));
$status = trim((string) ($_GET['status'] ?? ''));
$severity = trim((string) ($_GET['severity'] ?? ''));
$q = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$allowedStatuses = [
    'Draft', 'Submitted', 'Under Review', 'Action Required',
    'Rejected', 'Verified', 'Closed',
];
$allowedSeverities = ['Low', 'Medium', 'High', 'Critical'];
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}
if ($severity !== '' && !in_array($severity, $allowedSeverities, true)) {
    $severity = '';
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$properties = [];
$propertyResult = $conn->query(
    'SELECT id, property_code, property_name
     FROM cpms_properties WHERE is_active = 1 ORDER BY property_name'
);
if ($propertyResult instanceof mysqli_result) {
    while ($row = $propertyResult->fetch_assoc()) {
        $properties[] = $row;
    }
    $propertyResult->free();
}

$where = '
    FROM inspection_reports r
    INNER JOIN cpms_properties p ON p.id = r.property_id
    WHERE r.reported_by_id = ?
      AND (? = 0 OR r.property_id = ?)
      AND (? = "" OR r.status = ?)
      AND (? = "" OR r.priority = ?)
      AND (? = "" OR CONCAT_WS(" ", r.inspection_no, r.location,
                 p.property_code, p.property_name) LIKE CONCAT("%", ?, "%"))
      AND (? = "" OR r.inspection_date >= ?)
      AND (? = "" OR r.inspection_date <= ?)';
$types = 'iiissssssssss';

$totalRows = 0;
$countStmt = $conn->prepare('SELECT COUNT(*) AS total ' . $where);
if ($countStmt) {
    $countStmt->bind_param(
        $types,
        $inspectorId,
        $propertyId,
        $propertyId,
        $status,
        $status,
        $severity,
        $severity,
        $q,
        $q,
        $dateFrom,
        $dateFrom,
        $dateTo,
        $dateTo
    );
    $countStmt->execute();
    $row = $countStmt->get_result()->fetch_assoc();
    $countStmt->close();
    $totalRows = (int) ($row['total'] ?? 0);
}

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$findingsReady = cpmsFindingTableExists($conn, 'inspection_findings');
$actionsReady = cpmsFindingTableExists($conn, 'inspection_corrective_actions');
$deliveryReady = cpmsFindingTableExists($conn, 'inspection_delivery_log');

$findingColumns = $findingsReady
    ? ', (SELECT COUNT(*) FROM inspection_findings f
          WHERE f.inspection_id = r.id
            AND f.property_id = r.property_id) AS finding_count'
    : ', 0 AS finding_count';
$actionColumns = $actionsReady
    ? ', (SELECT COUNT(*) FROM inspection_corrective_actions a
          WHERE a.inspection_id = r.id
            AND a.property_id = r.property_id) AS action_count,
         (SELECT COUNT(*) FROM inspection_corrective_actions a
          WHERE a.inspection_id = r.id
            AND a.property_id = r.property_id
            AND a.status NOT IN ("Verified", "Closed")) AS pending_action_count'
    : ', 0 AS action_count, 0 AS pending_action_count';
$emailColumn = $deliveryReady
    ? ', (SELECT d.status FROM inspection_delivery_log d
          WHERE d.inspection_id = r.id
            AND d.property_id = r.property_id
            AND d.channel = "email"
          ORDER BY d.id DESC LIMIT 1) AS email_status'
    : ', NULL AS email_status';

$rows = [];
$listStmt = $conn->prepare(
    'SELECT r.id, r.property_id, r.inspection_no, r.inspection_date,
            r.inspection_type, r.location, r.priority, r.status,
            r.due_date, r.updated_at, p.property_code, p.property_name'
    . $findingColumns . $actionColumns . $emailColumn
    . $where
    . ' ORDER BY r.inspection_date DESC, r.id DESC LIMIT ? OFFSET ?'
);
if ($listStmt) {
    $listTypes = $types . 'ii';
    $listStmt->bind_param(
        $listTypes,
        $inspectorId,
        $propertyId,
        $propertyId,
        $status,
        $status,
        $severity,
        $severity,
        $q,
        $q,
        $dateFrom,
        $dateFrom,
        $dateTo,
        $dateTo,
        $perPage,
        $offset
    );
    $listStmt->execute();
    $result = $listStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $listStmt->close();
}

function hqiInspectionQuery(array $changes = []): string
{
    $query = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return http_build_query($query);
}

$pageTitle = 'My Inspections';
require __DIR__ . '/header.php';
?>
<style>
    .page-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.filter-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;margin-bottom:18px}.filters{display:grid;grid-template-columns:2fr repeat(5,minmax(130px,1fr));gap:10px;align-items:end}.filters label{font-size:12px;font-weight:800;color:#475569}.filters input,.filters select{margin:5px 0 0}.filter-actions{display:flex;gap:7px}.table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:14px}.inspection-table{min-width:1050px}.inspection-table a{font-weight:800;color:#1d4ed8;text-decoration:none}.status,.tag{display:inline-block;padding:4px 8px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:800}.status.Draft{background:#fef3c7;color:#92400e}.status.Submitted{background:#dbeafe;color:#1e40af}.status.Verified,.status.Closed{background:#dcfce7;color:#166534}.email-Failed{color:#b91c1c;font-weight:800}.email-Sent{color:#166534;font-weight:800}.severity-Critical{color:#b91c1c;font-weight:800}.severity-High{color:#c2410c;font-weight:800}.pagination{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:14px}.pages{display:flex;gap:6px}.pages a,.pages span{padding:8px 11px;border-radius:8px;background:#fff;border:1px solid #cbd5e1;text-decoration:none;color:#0f172a}.pages .current{background:#0f172a;color:#fff}.empty{padding:28px;text-align:center;background:#fff;color:#64748b}@media(max-width:1050px){.filters{grid-template-columns:repeat(2,1fr)}.filters .wide{grid-column:1/-1}}@media(max-width:650px){.page-head,.pagination{align-items:stretch;flex-direction:column}.filters{grid-template-columns:1fr}.filters .wide{grid-column:auto}.filter-actions .btn,.filter-actions button{flex:1;text-align:center}}
</style>

<div class="page-head">
    <div><h1>My Inspections</h1><p class="muted">Carian dan sejarah inspection milik anda.</p></div>
    <a class="btn" href="dashboard.php#properties">+ Start Inspection</a>
</div>

<form class="filter-card filters" method="get">
    <div class="wide"><label>Search<input name="q" value="<?php echo hqiEscape($q); ?>" placeholder="Inspection no., location atau property"></label></div>
    <div><label>Property<select name="property_id"><option value="0">All Properties</option><?php foreach ($properties as $property): ?><option value="<?php echo (int) $property['id']; ?>" <?php echo $propertyId === (int) $property['id'] ? 'selected' : ''; ?>><?php echo hqiEscape((string) $property['property_code']); ?> — <?php echo hqiEscape((string) $property['property_name']); ?></option><?php endforeach; ?></select></label></div>
    <div><label>Status<select name="status"><option value="">All Statuses</option><?php foreach ($allowedStatuses as $option): ?><option <?php echo $status === $option ? 'selected' : ''; ?>><?php echo hqiEscape($option); ?></option><?php endforeach; ?></select></label></div>
    <div><label>Severity<select name="severity"><option value="">All Severities</option><?php foreach ($allowedSeverities as $option): ?><option <?php echo $severity === $option ? 'selected' : ''; ?>><?php echo hqiEscape($option); ?></option><?php endforeach; ?></select></label></div>
    <div><label>From<input type="date" name="date_from" value="<?php echo hqiEscape($dateFrom); ?>"></label></div>
    <div><label>To<input type="date" name="date_to" value="<?php echo hqiEscape($dateTo); ?>"></label></div>
    <div class="filter-actions"><button type="submit">Apply</button><a class="btn" href="inspections.php">Reset</a></div>
</form>

<p class="muted"><?php echo $totalRows; ?> inspection(s) found.</p>
<div class="table-wrap genesis-data-table">
<table class="inspection-table">
    <thead><tr><th>Inspection</th><th>Property</th><th>Date / Type</th><th>Location</th><th>Severity</th><th>Status</th><th>Findings</th><th>Actions</th><th>Email</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="10"><div class="empty">Tiada inspection sepadan dengan filter.</div></td></tr><?php endif; ?>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><a href="inspection_view.php?id=<?php echo (int) $row['id']; ?>&property_id=<?php echo (int) $row['property_id']; ?>"><?php echo hqiEscape((string) $row['inspection_no']); ?></a></td>
            <td><strong><?php echo hqiEscape((string) $row['property_code']); ?></strong><br><small><?php echo hqiEscape((string) $row['property_name']); ?></small></td>
            <td><?php echo hqiEscape((string) $row['inspection_date']); ?><br><small><?php echo hqiEscape((string) $row['inspection_type']); ?></small></td>
            <td><?php echo hqiEscape((string) $row['location']); ?></td>
            <td class="severity-<?php echo hqiEscape((string) $row['priority']); ?>"><?php echo hqiEscape((string) $row['priority']); ?></td>
            <td><span class="status <?php echo hqiEscape((string) $row['status']); ?>"><?php echo hqiEscape((string) $row['status']); ?></span></td>
            <td><?php echo (int) $row['finding_count']; ?></td>
            <td><?php echo (int) $row['pending_action_count']; ?> open / <?php echo (int) $row['action_count']; ?> total</td>
            <td class="email-<?php echo hqiEscape((string) ($row['email_status'] ?? '')); ?>"><?php echo hqiEscape((string) ($row['email_status'] ?: '-')); ?></td>
            <td><a href="inspection_report.php?id=<?php echo (int) $row['id']; ?>&property_id=<?php echo (int) $row['property_id']; ?>">Report</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="pagination">
    <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
    <div class="pages">
        <?php if ($page > 1): ?><a href="?<?php echo hqiEscape(hqiInspectionQuery(['page' => $page - 1])); ?>">← Previous</a><?php endif; ?>
        <span class="current"><?php echo $page; ?></span>
        <?php if ($page < $totalPages): ?><a href="?<?php echo hqiEscape(hqiInspectionQuery(['page' => $page + 1])); ?>">Next →</a><?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
