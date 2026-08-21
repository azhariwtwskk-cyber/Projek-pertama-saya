<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/staff_pwa_bootstrap.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/corrective_action_service.php';
require_once __DIR__ . '/cpms/includes/inspection_finding_service.php';
require_once __DIR__ . '/cpms/includes/inspection_assignment_service.php';

if (empty($_SESSION['staff_id'])) {
    header('Location: cpms/login.php');
    exit();
}
cpmsRequire('inspection.action.rectify', $conn);

$systemUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$propertyId = (int) (
    $_SESSION['cpms_property_id']
    ?? $_SESSION['staff_property_id']
    ?? 0
);
if ($systemUserId < 1 || $propertyId < 1) {
    http_response_code(403);
    exit('Unified Staff account context is required.');
}
if (!cpmsAssignmentTablesReady($conn)) {
    http_response_code(503);
    exit('Property/Staff workflow is not ready. Run migration 20260810_0061.');
}

$status = trim((string) ($_GET['status'] ?? 'active'));
if (!in_array($status, ['active', 'review', 'completed', 'all'], true)) {
    $status = 'active';
}
$whereStatus = "a.status IN ('Open', 'In Progress')";
if ($status === 'review') {
    $whereStatus = "a.status = 'Rectified'";
} elseif ($status === 'completed') {
    $whereStatus = "a.status IN ('Verified', 'Closed')";
} elseif ($status === 'all') {
    $whereStatus = '1 = 1';
}

$summary = ['open' => 0, 'progress' => 0, 'review' => 0, 'overdue' => 0];
$summaryStmt = $conn->prepare(
    'SELECT
        SUM(a.status = "Open") AS open_total,
        SUM(a.status = "In Progress") AS progress_total,
        SUM(a.status = "Rectified") AS review_total,
        SUM(a.due_date < CURDATE()
            AND a.status NOT IN ("Verified", "Closed")) AS overdue_total
     FROM inspection_corrective_actions a
     LEFT JOIN inspection_finding_action_links l
        ON l.action_id = a.id AND l.property_id = a.property_id
     WHERE a.property_id = ? AND a.assigned_system_user_id = ?'
);
if ($summaryStmt) {
    $summaryStmt->bind_param('ii', $propertyId, $systemUserId);
    $summaryStmt->execute();
    $row = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();
    $summary = [
        'open' => (int) ($row['open_total'] ?? 0),
        'progress' => (int) ($row['progress_total'] ?? 0),
        'review' => (int) ($row['review_total'] ?? 0),
        'overdue' => (int) ($row['overdue_total'] ?? 0),
    ];
}

$stmt = $conn->prepare(
    "SELECT a.id, a.action_no, a.title, a.priority, a.due_date,
            a.status, a.created_at, r.inspection_no,
            COALESCE(f.finding_name, a.title) AS finding_name,
            COALESCE(f.category, r.category) AS finding_category,
            COALESCE(f.location, r.location) AS finding_location,
            COALESCE(f.severity, a.priority) AS finding_severity,
            COALESCE(l.supervisor_status, 'Legacy Action')
                AS supervisor_status,
            (SELECT i.image_path
             FROM inspection_finding_images fi
             INNER JOIN inspection_images i
                ON i.id = fi.inspection_image_id
               AND i.property_id = fi.property_id
             WHERE fi.finding_id = f.id
               AND fi.property_id = f.property_id
             ORDER BY i.id ASC LIMIT 1) AS original_image
     FROM inspection_corrective_actions a
     INNER JOIN inspection_reports r
        ON r.id = a.inspection_id AND r.property_id = a.property_id
     LEFT JOIN inspection_finding_action_links l
        ON l.action_id = a.id AND l.property_id = a.property_id
     LEFT JOIN inspection_findings f
        ON f.id = l.finding_id AND f.property_id = l.property_id
     WHERE a.property_id = ?
       AND a.assigned_system_user_id = ?
       AND {$whereStatus}
     ORDER BY
       CASE WHEN a.due_date < CURDATE()
                 AND a.status NOT IN ('Verified', 'Closed') THEN 0 ELSE 1 END,
       FIELD(a.status, 'In Progress', 'Open', 'Rectified', 'Verified', 'Closed'),
       CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END,
       a.due_date ASC, a.id DESC"
);
$actions = [];
if ($stmt) {
    $stmt->bind_param('ii', $propertyId, $systemUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $actions[] = $row;
    }
    $stmt->close();
}

$branding = cpmsStaffPwaBranding($conn);
function staffActionE(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function staffActionStatusClass(string $value): string
{
    $map = [
        'Open' => 'status-open',
        'In Progress' => 'status-progress',
        'Rectified' => 'status-review',
        'Verified' => 'status-done',
        'Closed' => 'status-done',
    ];
    return $map[$value] ?? 'status-open';
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspection Tasks | CPMS Staff</title>
    <link rel="stylesheet" href="css/pms.css?v=6">
    <style>
    .ca-page{max-width:1180px;margin:0 auto;padding:24px}.ca-head,.ca-card,.ca-kpi{background:#fff;border:1px solid #e5ded7;border-radius:16px}.ca-head{padding:24px;margin-bottom:14px;background:linear-gradient(135deg,#4a2b20,#815b3c);color:#fff}.ca-head h1{margin:0 0 7px}.ca-head p{margin:0;color:#f7e9dc}.ca-back{display:inline-block;background:#b99a16;color:#fff;padding:10px 14px;border-radius:9px;text-decoration:none;font-weight:800;margin-bottom:14px}.ca-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.ca-kpi{padding:14px;text-decoration:none;color:#4a2b20}.ca-kpi strong{display:block;font-size:25px}.ca-kpi span{color:#735e55;font-size:12px}.ca-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.ca-tabs a{padding:10px 14px;border-radius:999px;text-decoration:none;font-weight:800;background:#eee8e2;color:#4a2b20}.ca-tabs a.active{background:#4a2b20;color:#fff}.ca-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ca-card{display:grid;grid-template-columns:160px 1fr;gap:16px;padding:17px;color:inherit;text-decoration:none}.ca-photo{width:160px;height:130px;object-fit:cover;border-radius:10px;background:#eee8e2}.ca-card h2{font-size:18px;margin:7px 0;color:#4a2b20}.ca-meta{display:flex;gap:7px;flex-wrap:wrap;color:#735e55;font-size:13px}.ca-badge{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:800}.status-open{background:#fff2c7;color:#725a00}.status-progress{background:#dbeafe;color:#174ea6}.status-review{background:#ede9fe;color:#5b21b6}.status-done{background:#dcfce7;color:#08783d}.ca-overdue{color:#b91c1c;font-weight:800}.ca-empty{background:#fff;padding:34px;text-align:center;border-radius:16px;color:#735e55}@media(max-width:750px){.ca-page{padding:14px}.ca-grid{grid-template-columns:1fr}.ca-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:420px){.ca-card{grid-template-columns:1fr}.ca-photo{width:100%;height:190px}}
    </style>
    <?php echo cpmsStaffPwaHead($branding); ?><?php echo cpmsStaffPwaStyle($branding); ?>
    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head>
<body class="pms-body"><main class="ca-page">
    <a class="ca-back" href="staff_dashboard.php">← Dashboard</a>
    <section class="ca-head"><h1>Inspection Rectification Tasks</h1><p>Lihat finding dan gambar HQ, jalankan pembaikan, kemudian hantar bukti Before / During / After.</p></section>
    <section class="ca-kpis"><a class="ca-kpi" href="?status=active"><strong><?php echo $summary['open']; ?></strong><span>New Tasks</span></a><a class="ca-kpi" href="?status=active"><strong><?php echo $summary['progress']; ?></strong><span>In Progress</span></a><a class="ca-kpi" href="?status=review"><strong><?php echo $summary['review']; ?></strong><span>Under Review</span></a><a class="ca-kpi" href="?status=active"><strong><?php echo $summary['overdue']; ?></strong><span>Overdue</span></a></section>
    <nav class="ca-tabs"><a class="<?php echo $status === 'active' ? 'active' : ''; ?>" href="?status=active">Aktif</a><a class="<?php echo $status === 'review' ? 'active' : ''; ?>" href="?status=review">Dalam Semakan</a><a class="<?php echo $status === 'completed' ? 'active' : ''; ?>" href="?status=completed">Selesai</a><a class="<?php echo $status === 'all' ? 'active' : ''; ?>" href="?status=all">Semua</a></nav>
    <?php if (!$actions): ?><section class="ca-empty">Tiada tugasan inspection dalam kategori ini.</section><?php else: ?><section class="ca-grid">
    <?php foreach ($actions as $action): ?><?php $isOverdue = !empty($action['due_date']) && (string) $action['due_date'] < date('Y-m-d') && !in_array((string) $action['status'], ['Verified', 'Closed'], true); ?>
        <a class="ca-card" href="staff_corrective_action_view.php?id=<?php echo (int) $action['id']; ?>">
            <?php if (!empty($action['original_image'])): ?><img class="ca-photo" src="cpms/<?php echo staffActionE((string) $action['original_image']); ?>" alt="HQ finding"><?php else: ?><div class="ca-photo"></div><?php endif; ?>
            <div><span class="ca-badge <?php echo staffActionStatusClass((string) $action['status']); ?>"><?php echo staffActionE((string) $action['status']); ?></span><h2><?php echo staffActionE((string) $action['finding_name']); ?></h2><div class="ca-meta"><span><?php echo staffActionE((string) $action['action_no']); ?></span><span>•</span><span><?php echo staffActionE((string) $action['inspection_no']); ?></span></div><div class="ca-meta"><strong><?php echo staffActionE((string) $action['finding_location']); ?></strong><span>•</span><span><?php echo staffActionE((string) $action['finding_severity']); ?></span></div><div class="ca-meta" style="margin-top:8px"><span class="<?php echo $isOverdue ? 'ca-overdue' : ''; ?>">Due: <?php echo staffActionE((string) ($action['due_date'] ?: '-')); ?><?php echo $isOverdue ? ' · OVERDUE' : ''; ?></span><span>Supervisor: <?php echo staffActionE((string) $action['supervisor_status']); ?></span></div></div>
        </a>
    <?php endforeach; ?></section><?php endif; ?>
</main></body></html>
