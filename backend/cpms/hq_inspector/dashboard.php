<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$inspectorId = (int) $hqInspector['id'];
$inspectorSql = (string) $inspectorId;
$tablesReady = cpmsFindingTablesReady($conn);
$operationalReady = cpmsHqOperationalTablesReady($conn);
$actionsReady = cpmsFindingTableExists($conn, 'inspection_corrective_actions');
$assignmentReady = cpmsFindingTableExists(
    $conn,
    'inspection_finding_action_links'
);
$settingsReady = cpmsFindingTableExists($conn, 'inspection_property_settings');

$summary = [
    'total' => 0,
    'draft' => 0,
    'submitted' => 0,
    'critical' => 0,
    'pending_actions' => 0,
    'overdue_actions' => 0,
    'awaiting_verification' => 0,
    'email_failed' => 0,
];

$summaryStmt = $conn->prepare(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(status = "Draft"), 0) AS draft_total,
            COALESCE(SUM(status = "Submitted"), 0) AS submitted_total,
            COALESCE(SUM(
                priority = "Critical"
                AND status NOT IN ("Verified", "Closed")
            ), 0) AS critical_total
     FROM inspection_reports
     WHERE reported_by_id = ?'
);
if ($summaryStmt) {
    $summaryStmt->bind_param('i', $inspectorId);
    $summaryStmt->execute();
    $row = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();
    $summary['total'] = (int) ($row['total'] ?? 0);
    $summary['draft'] = (int) ($row['draft_total'] ?? 0);
    $summary['submitted'] = (int) ($row['submitted_total'] ?? 0);
    $summary['critical'] = (int) ($row['critical_total'] ?? 0);
}

if ($tablesReady) {
    $criticalStmt = $conn->prepare(
        'SELECT COUNT(DISTINCT r.id) AS total
         FROM inspection_reports r
         INNER JOIN inspection_findings f
            ON f.inspection_id = r.id
           AND f.property_id = r.property_id
         WHERE r.reported_by_id = ?
           AND f.severity = "Critical"
           AND f.status NOT IN ("Verified", "Closed")
           AND r.status NOT IN ("Verified", "Closed")'
    );
    if ($criticalStmt) {
        $criticalStmt->bind_param('i', $inspectorId);
        $criticalStmt->execute();
        $row = $criticalStmt->get_result()->fetch_assoc();
        $criticalStmt->close();
        $summary['critical'] = (int) ($row['total'] ?? 0);
    }
}

if ($actionsReady) {
    $actionSummaryJoin = $assignmentReady
        ? 'LEFT JOIN inspection_finding_action_links l
             ON l.action_id = a.id AND l.property_id = a.property_id'
        : '';
    $awaitingExpression = $assignmentReady
        ? 'a.status = "Rectified"
           AND (l.id IS NULL OR l.supervisor_status = "Approved")'
        : 'a.status = "Rectified"';
    $actionSummary = $conn->prepare(
        'SELECT
            COALESCE(SUM(a.status NOT IN ("Verified", "Closed")), 0)
                AS pending_total,
            COALESCE(SUM(
                a.due_date IS NOT NULL
                AND a.due_date < CURDATE()
                AND a.status NOT IN ("Verified", "Closed")
            ), 0) AS overdue_total,
            COALESCE(SUM(' . $awaitingExpression . '), 0) AS rectified_total
         FROM inspection_corrective_actions a
         INNER JOIN inspection_reports r
            ON r.id = a.inspection_id
           AND r.property_id = a.property_id
         ' . $actionSummaryJoin . '
         WHERE r.reported_by_id = ?'
    );
    if ($actionSummary) {
        $actionSummary->bind_param('i', $inspectorId);
        $actionSummary->execute();
        $row = $actionSummary->get_result()->fetch_assoc();
        $actionSummary->close();
        $summary['pending_actions'] = (int) ($row['pending_total'] ?? 0);
        $summary['overdue_actions'] = (int) ($row['overdue_total'] ?? 0);
        $summary['awaiting_verification'] = (int) ($row['rectified_total'] ?? 0);
    }
}

if ($tablesReady) {
    $emailSummary = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM inspection_delivery_log d
         INNER JOIN (
            SELECT inspection_id, property_id, MAX(id) AS latest_id
            FROM inspection_delivery_log
            WHERE channel = "email"
            GROUP BY inspection_id, property_id
         ) latest ON latest.latest_id = d.id
         INNER JOIN inspection_reports r
            ON r.id = d.inspection_id
           AND r.property_id = d.property_id
         WHERE r.reported_by_id = ? AND d.status = "Failed"'
    );
    if ($emailSummary) {
        $emailSummary->bind_param('i', $inspectorId);
        $emailSummary->execute();
        $row = $emailSummary->get_result()->fetch_assoc();
        $emailSummary->close();
        $summary['email_failed'] = (int) ($row['total'] ?? 0);
    }
}

$settingSelect = $settingsReady
    ? 'COALESCE(s.max_photos_per_inspection, 100) AS photo_limit'
    : '100 AS photo_limit';
$settingJoin = $settingsReady
    ? 'LEFT JOIN inspection_property_settings s ON s.property_id = p.id'
    : '';
$actionPropertySelect = $actionsReady
    ? ', (SELECT COUNT(*)
          FROM inspection_corrective_actions a
          INNER JOIN inspection_reports ar
             ON ar.id = a.inspection_id
            AND ar.property_id = a.property_id
          WHERE a.property_id = p.id
            AND ar.reported_by_id = ' . $inspectorSql . '
            AND a.status NOT IN ("Verified", "Closed")) AS open_actions'
    : ', 0 AS open_actions';

$properties = [];
$propertyResult = $conn->query(
    'SELECT p.id, p.property_code, p.property_name, p.company_name,
            p.address, p.email, ' . $settingSelect . ',
            (SELECT COUNT(*) FROM inspection_reports r
             WHERE r.property_id = p.id
               AND r.reported_by_id = ' . $inspectorSql . ') AS inspection_total,
            (SELECT COUNT(*) FROM inspection_reports r
             WHERE r.property_id = p.id
               AND r.reported_by_id = ' . $inspectorSql . '
               AND r.status = "Draft") AS draft_total,
            (SELECT MAX(r.inspection_date) FROM inspection_reports r
             WHERE r.property_id = p.id
               AND r.reported_by_id = ' . $inspectorSql . ') AS last_inspection_date'
            . $actionPropertySelect . '
     FROM cpms_properties p ' . $settingJoin . '
     WHERE p.is_active = 1
     ORDER BY p.property_name'
);
if ($propertyResult instanceof mysqli_result) {
    while ($row = $propertyResult->fetch_assoc()) {
        $properties[] = $row;
    }
    $propertyResult->free();
}

$drafts = [];
$draftStmt = $conn->prepare(
    'SELECT r.id, r.property_id, r.inspection_no, r.inspection_date,
            r.location, r.priority, r.updated_at,
            p.property_code, p.property_name,
            (SELECT COUNT(*) FROM inspection_findings f
             WHERE f.inspection_id = r.id
               AND f.property_id = r.property_id) AS finding_count,
            (SELECT COUNT(*) FROM inspection_images i
             WHERE i.inspection_id = r.id
               AND i.property_id = r.property_id) AS image_count
     FROM inspection_reports r
     INNER JOIN cpms_properties p ON p.id = r.property_id
     WHERE r.reported_by_id = ? AND r.status = "Draft"
     ORDER BY r.updated_at DESC, r.id DESC LIMIT 8'
);
if ($draftStmt) {
    $draftStmt->bind_param('i', $inspectorId);
    $draftStmt->execute();
    $result = $draftStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $drafts[] = $row;
    }
    $draftStmt->close();
}

$recent = [];
$recentStmt = $conn->prepare(
    'SELECT r.id, r.property_id, r.inspection_no, r.inspection_date,
            r.location, r.priority, r.status,
            p.property_code, p.property_name,
            (SELECT COUNT(*) FROM inspection_findings f
             WHERE f.inspection_id = r.id
               AND f.property_id = r.property_id) AS finding_count
     FROM inspection_reports r
     INNER JOIN cpms_properties p ON p.id = r.property_id
     WHERE r.reported_by_id = ?
     ORDER BY r.updated_at DESC, r.id DESC LIMIT 10'
);
if ($recentStmt) {
    $recentStmt->bind_param('i', $inspectorId);
    $recentStmt->execute();
    $result = $recentStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent[] = $row;
    }
    $recentStmt->close();
}

$siteSlaSummary = [];
if ($actionsReady) {
    $slaStmt = $conn->prepare(
        'SELECT p.id AS property_id, p.property_code, p.property_name,
                COUNT(DISTINCT a.id) AS open_actions,
                COALESCE(SUM(
                    r.inspection_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                    AND a.status NOT IN ("Verified", "Closed")
                ), 0) AS overdue_14_days,
                COALESCE(SUM(
                    a.status = "Rectified"
                ), 0) AS awaiting_hq,
                MAX(r.inspection_date) AS latest_inspection_date
         FROM inspection_corrective_actions a
         INNER JOIN inspection_reports r
            ON r.id = a.inspection_id
           AND r.property_id = a.property_id
         INNER JOIN cpms_properties p ON p.id = a.property_id
         WHERE r.reported_by_id = ?
           AND a.status NOT IN ("Verified", "Closed")
         GROUP BY p.id, p.property_code, p.property_name
         HAVING open_actions > 0
         ORDER BY overdue_14_days DESC, open_actions DESC, p.property_name
         LIMIT 8'
    );
    if ($slaStmt) {
        $slaStmt->bind_param('i', $inspectorId);
        $slaStmt->execute();
        $result = $slaStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $siteSlaSummary[] = $row;
        }
        $slaStmt->close();
    }
}

$aiRiskScore = 100;
$aiRiskScore -= min(35, $summary['overdue_actions'] * 7);
$aiRiskScore -= min(25, $summary['critical'] * 8);
$aiRiskScore -= min(20, $summary['awaiting_verification'] * 4);
$aiRiskScore -= min(10, $summary['email_failed'] * 3);
$aiRiskScore = max(0, $aiRiskScore);
$aiRiskLabel = $aiRiskScore >= 80
    ? 'Stable'
    : ($aiRiskScore >= 55 ? 'Watchlist' : 'High Risk');

$sitePerformance = [];
if ($actionsReady) {
    $performanceStmt = $conn->prepare(
        'SELECT p.property_code, p.property_name,
                COUNT(DISTINCT r.id) AS inspection_total,
                COALESCE(SUM(a.status IN ("Verified", "Closed")), 0)
                    AS closed_actions,
                COUNT(a.id) AS action_total,
                COALESCE(SUM(
                    a.status NOT IN ("Verified", "Closed")
                    AND r.inspection_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                ), 0) AS overdue_14_days
         FROM inspection_reports r
         INNER JOIN cpms_properties p ON p.id = r.property_id
         LEFT JOIN inspection_corrective_actions a
            ON a.inspection_id = r.id
           AND a.property_id = r.property_id
         WHERE r.reported_by_id = ?
         GROUP BY p.id, p.property_code, p.property_name
         ORDER BY overdue_14_days DESC, action_total DESC, p.property_name
         LIMIT 6'
    );
    if ($performanceStmt) {
        $performanceStmt->bind_param('i', $inspectorId);
        $performanceStmt->execute();
        $result = $performanceStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $sitePerformance[] = $row;
        }
        $performanceStmt->close();
    }
}

$evidenceExceptions = [];
if ($actionsReady && $assignmentReady) {
    $reviewStatusSelect = cpmsHqActionImageReviewReady($conn)
        ? 'ai.hq_review_status'
        : '"Pending"';
    $exceptionStmt = $conn->prepare(
        'SELECT r.id AS inspection_id, r.property_id, r.inspection_no,
                r.inspection_date, p.property_code, p.property_name,
                f.finding_name, f.location,
                fi.inspection_image_id AS source_image_id,
                ai.id AS after_image_id,
                ai.image_path AS after_image_path,
                ' . $reviewStatusSelect . ' AS hq_review_status,
                a.id AS action_id, a.action_no
         FROM inspection_finding_images fi
         INNER JOIN inspection_findings f
            ON f.id = fi.finding_id
           AND f.inspection_id = fi.inspection_id
           AND f.property_id = fi.property_id
         INNER JOIN inspection_reports r
            ON r.id = fi.inspection_id
           AND r.property_id = fi.property_id
         INNER JOIN cpms_properties p ON p.id = r.property_id
         LEFT JOIN inspection_finding_action_links l
            ON l.finding_id = f.id
           AND l.inspection_id = f.inspection_id
           AND l.property_id = f.property_id
         LEFT JOIN inspection_corrective_actions a
            ON a.id = l.action_id
           AND a.property_id = l.property_id
         LEFT JOIN inspection_action_images ai
            ON ai.action_id = a.id
           AND ai.property_id = a.property_id
           AND ai.image_phase = "After"
           AND ai.source_inspection_image_id = fi.inspection_image_id
         WHERE r.reported_by_id = ?
           AND r.status NOT IN ("Verified", "Closed")
           AND (
                ai.id IS NULL
                OR ' . $reviewStatusSelect . ' = "Rejected"
           )
         ORDER BY
            CASE WHEN ai.id IS NULL THEN 0 ELSE 1 END,
            r.inspection_date ASC,
            r.id DESC
         LIMIT 8'
    );
    if ($exceptionStmt) {
        $exceptionStmt->bind_param('i', $inspectorId);
        $exceptionStmt->execute();
        $result = $exceptionStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $evidenceExceptions[] = $row;
        }
        $exceptionStmt->close();
    }
}

$recurringDefects = [];
if ($tablesReady) {
    $recurringStmt = $conn->prepare(
        'SELECT p.id AS property_id, p.property_code, p.property_name,
                f.finding_name, f.location,
                COUNT(*) AS occurrence_total,
                MAX(r.inspection_date) AS last_seen
         FROM inspection_findings f
         INNER JOIN inspection_reports r
            ON r.id = f.inspection_id
           AND r.property_id = f.property_id
         INNER JOIN cpms_properties p ON p.id = f.property_id
         WHERE r.reported_by_id = ?
         GROUP BY p.id, p.property_code, p.property_name,
                  f.finding_master_id, f.finding_name, f.location
         HAVING occurrence_total >= 2
         ORDER BY occurrence_total DESC, last_seen DESC
         LIMIT 8'
    );
    if ($recurringStmt) {
        $recurringStmt->bind_param('i', $inspectorId);
        $recurringStmt->execute();
        $result = $recurringStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recurringDefects[] = $row;
        }
        $recurringStmt->close();
    }
}

$slaReminders = [];
if ($actionsReady) {
    $slaReminderStmt = $conn->prepare(
        'SELECT a.id AS action_id, a.action_no, a.title, a.status,
                r.id AS inspection_id, r.property_id, r.inspection_no,
                r.inspection_date,
                DATEDIFF(CURDATE(), r.inspection_date) AS days_elapsed,
                p.property_code, p.property_name, p.email
         FROM inspection_corrective_actions a
         INNER JOIN inspection_reports r
            ON r.id = a.inspection_id
           AND r.property_id = a.property_id
         INNER JOIN cpms_properties p ON p.id = r.property_id
         WHERE r.reported_by_id = ?
           AND a.status NOT IN ("Verified", "Closed")
           AND DATEDIFF(CURDATE(), r.inspection_date) >= 7
         ORDER BY days_elapsed DESC, r.inspection_date ASC, a.id ASC
         LIMIT 10'
    );
    if ($slaReminderStmt) {
        $slaReminderStmt->bind_param('i', $inspectorId);
        $slaReminderStmt->execute();
        $result = $slaReminderStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $slaReminders[] = $row;
        }
        $slaReminderStmt->close();
    }
}

$aiInsights = [];
if ($summary['overdue_actions'] > 0) {
    $aiInsights[] = $summary['overdue_actions']
        . ' action(s) are overdue. Prioritise red-flag sites before creating new inspections.';
}
if ($summary['awaiting_verification'] > 0) {
    $aiInsights[] = $summary['awaiting_verification']
        . ' action(s) are waiting for HQ verification. Review each After photo and accept or reject it individually.';
}
if ($summary['email_failed'] > 0) {
    $aiInsights[] = $summary['email_failed']
        . ' email report(s) failed. Check the property email before sending reminders.';
}
if (!$aiInsights) {
    $aiInsights[] = 'No major operational risk detected. Continue inspections according to the property schedule.';
}

$pageTitle = 'HQ Inspector Operational Dashboard';
require __DIR__ . '/header.php';
?>
<style>
    .hq-hero{display:flex;justify-content:space-between;gap:20px;align-items:center;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;padding:22px;border-radius:16px;margin-bottom:16px}.hq-hero h1{margin:4px 0;font-size:28px;letter-spacing:0}.hq-hero p{margin:0;color:#dbeafe;font-size:14px}.hero-actions{display:flex;gap:9px;flex-wrap:wrap}.hero-actions .btn{background:#fff;color:#0f172a}.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px}.kpi{background:#fff;border-radius:12px;padding:13px 14px;border:1px solid #e2e8f0}.kpi small{display:block;color:#64748b;margin-bottom:4px;font-size:12px}.kpi strong{font-size:24px}.kpi.alert strong{color:#b91c1c}.ai-grid{display:grid;grid-template-columns:.75fr 1.1fr 1.15fr;gap:14px;margin:16px 0}.ai-card{background:#fff;border:1px solid #dbe3ee;border-radius:16px;padding:16px;box-shadow:0 12px 28px #0f172a0f}.ai-card h2{font-size:16px;margin:0 0 10px}.ai-list{display:grid;gap:8px}.ai-list div{padding:10px;border-radius:10px;background:#f8fafc;color:#334155;font-size:13px}.risk-score{display:grid;place-items:center;text-align:center;min-height:186px;border-radius:14px;background:radial-gradient(circle at top,#eff6ff,#fff)}.risk-score strong{font-size:48px;color:#0f172a;line-height:1}.risk-score span{display:inline-block;margin-top:8px;padding:6px 10px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-weight:900;font-size:12px}.risk-score.Watchlist span{background:#fef3c7;color:#92400e}.risk-score.High-Risk span{background:#fee2e2;color:#991b1b}.bar-row{display:grid;grid-template-columns:120px 1fr 48px;gap:10px;align-items:center;margin:9px 0;font-size:12px}.bar-track{height:13px;border-radius:999px;background:#e2e8f0;overflow:hidden}.bar-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#2563eb,#06b6d4)}.bar-fill.warn{background:linear-gradient(90deg,#f59e0b,#dc2626)}.sla-badge{display:inline-block;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:900;background:#dcfce7;color:#166534}.sla-badge.over{background:#fee2e2;color:#991b1b}.premium-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:16px 0}.exception-list{display:grid;gap:10px}.exception-card{display:grid;grid-template-columns:1fr auto;gap:12px;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}.exception-card strong{display:block;color:#0f172a}.exception-card small{display:block;color:#64748b;margin-top:3px}.exception-card .missing{background:#fff7ed;color:#9a3412}.exception-card .rejected{background:#fee2e2;color:#991b1b}.section-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:22px}.section-head h2{margin:0;font-size:23px}.section-head .muted{margin:4px 0 0;font-size:14px}.search{max-width:320px}.search input{margin:0;padding:9px 10px;font-size:13px}.property-id,.pill{display:inline-block;background:#e2e8f0;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:800;line-height:1}.property-code{display:inline-block;margin-bottom:5px;color:#475569;font-size:11px;font-weight:900;letter-spacing:.04em;text-transform:uppercase}.property-name{font-size:14px;font-weight:900;line-height:1.25}.property-company{color:#334155;font-size:12px;margin-top:4px}.property-address{color:#64748b;font-size:12px;margin-top:3px;max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.property-action{text-align:right}.property-action .btn{white-space:nowrap;padding:8px 11px;border-radius:7px;font-size:12px}.email-ok{color:#166534;font-size:12px;font-weight:800}.email-warn{color:#b45309;font-size:12px;font-weight:800}.table-wrap{overflow:auto;border-radius:14px;border:1px solid #dbe3ee;box-shadow:0 12px 28px #0f172a0f}.ops-table{min-width:840px}.property-table{min-width:980px;font-size:13px}.property-table th,.property-table td{padding:10px 12px;vertical-align:middle}.property-table tbody tr:hover{background:#f8fafc}.property-table .num{font-weight:900;color:#0f172a}.ops-table a{font-weight:800;color:#1d4ed8;text-decoration:none}.status{display:inline-block;padding:4px 8px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:800}.status.Draft{background:#fef3c7;color:#92400e}.status.Submitted{background:#dbeafe;color:#1e40af}.status.Rectified{background:#ede9fe;color:#6d28d9}.status.Verified,.status.Closed{background:#dcfce7;color:#166534}.priority-Critical{color:#b91c1c;font-weight:800}.priority-High{color:#c2410c;font-weight:800}.overdue{color:#b91c1c;font-weight:800}.empty{padding:22px;text-align:center;color:#64748b;background:#fff;border-radius:14px}.notice{padding:12px;border-radius:10px;margin-bottom:12px}.notice.warning{background:#fff7ed;color:#9a3412}.notice.error{background:#fee2e2;color:#991b1b}@media(max-width:1100px){.ai-grid,.premium-grid{grid-template-columns:1fr}}@media(max-width:900px){.kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:680px){.hq-hero,.section-head{align-items:stretch;flex-direction:column}.hq-hero h1{font-size:24px}.kpis{grid-template-columns:repeat(2,1fr)}.kpi strong{font-size:22px}.search{max-width:none}.hero-actions .btn{flex:1;text-align:center}.property-address{white-space:normal}.exception-card{grid-template-columns:1fr}}
    .planner-link{display:inline-block;margin-top:6px;color:#1d4ed8;font-size:12px;font-weight:900;text-decoration:none}.reminder-status{font-weight:900}.reminder-status.escalate{color:#991b1b}.reminder-status.remind{color:#92400e}
</style>

<section class="hq-hero hq-hero-compact">
    <div>
        <small>HQ INSPECTOR OPERATIONS</small>
        <h1>Welcome, <?php echo hqiEscape((string) $hqInspector['full_name']); ?></h1>
        <p>Monitor inspections, rectification progress and verification across all properties.</p>
    </div>
    <div class="hero-actions">
        <a class="btn" href="dashboard.php?view=properties#properties">+ Start Inspection</a>
        <a class="btn" href="inspections.php">View All Inspections</a>
    </div>
</section>

<?php if (!$tablesReady): ?>
    <div class="notice error">CPMS v3.2.6 is incomplete. Run migration 20260809_0018.</div>
<?php endif; ?>
<?php if (!$operationalReady): ?>
    <div class="notice warning">Operational Dashboard is running in compatibility mode. Run migration 20260810_0060 to enable HQ verification and reinspection.</div>
<?php endif; ?>
<?php if (isset($_GET['inspection_cancelled'])): ?>
    <div class="notice" style="background:#dcfce7;color:#166534">Draft inspection cancelled successfully.</div>
<?php endif; ?>

<div class="overview-label"><span>Operational Overview</span><small>Live inspection workflow summary</small></div>
<section class="kpis kpis-premium">
    <div class="kpi"><div class="kpi-top"><small>Total Inspections</small><span class="kpi-icon">IN</span></div><strong><?php echo $summary['total']; ?></strong><em>All records</em></div>
    <div class="kpi"><div class="kpi-top"><small>Continue Draft</small><span class="kpi-icon">DR</span></div><strong><?php echo $summary['draft']; ?></strong><em>Needs completion</em></div>
    <div class="kpi"><div class="kpi-top"><small>Submitted</small><span class="kpi-icon">SB</span></div><strong><?php echo $summary['submitted']; ?></strong><em>Submitted reports</em></div>
    <div class="kpi alert"><div class="kpi-top"><small>Critical Open</small><span class="kpi-icon">CR</span></div><strong><?php echo $summary['critical']; ?></strong><em>Priority attention</em></div>
    <div class="kpi"><div class="kpi-top"><small>Pending Actions</small><span class="kpi-icon">AC</span></div><strong><?php echo $summary['pending_actions']; ?></strong><em>Open rectifications</em></div>
    <div class="kpi alert"><div class="kpi-top"><small>Overdue Actions</small><span class="kpi-icon">OD</span></div><strong><?php echo $summary['overdue_actions']; ?></strong><em>Past due date</em></div>
    <div class="kpi"><div class="kpi-top"><small>Awaiting HQ</small><span class="kpi-icon">HQ</span></div><strong><?php echo $summary['awaiting_verification']; ?></strong><em>Verification queue</em></div>
    <div class="kpi alert"><div class="kpi-top"><small>Email Failed</small><span class="kpi-icon">EM</span></div><strong><?php echo $summary['email_failed']; ?></strong><em>Delivery issues</em></div>
</section>

<section class="ai-grid">
    <div class="ai-card">
        <h2>AI Risk Score</h2>
        <div class="risk-score <?php echo hqiEscape(str_replace(' ', '.', $aiRiskLabel)); ?>">
            <div>
                <strong><?php echo $aiRiskScore; ?></strong>
                <span><?php echo hqiEscape($aiRiskLabel); ?></span>
                <p class="muted">Calculated from overdue actions, critical findings, HQ verification backlog and failed report delivery.</p>
            </div>
        </div>
    </div>
    <div class="ai-card performance-card">
        <div class="card-title-row"><div><h2>Inspection Performance</h2><small>Current operational workload</small></div><span class="live-chip">LIVE</span></div>
        <?php
        $graphRows = [
            ['Submitted', $summary['submitted'], 'submitted'],
            ['Pending', $summary['pending_actions'], 'pending'],
            ['Overdue', $summary['overdue_actions'], 'overdue'],
            ['Awaiting HQ', $summary['awaiting_verification'], 'awaiting'],
        ];
        $graphMax = max(1, $summary['submitted'], $summary['pending_actions'], $summary['overdue_actions'], $summary['awaiting_verification']);
        ?>
        <div class="kpi-chart" aria-label="Inspection performance chart">
            <?php foreach ($graphRows as $graph): ?>
                <?php $height = max(8, (int) round(((int) $graph[1] / $graphMax) * 100)); ?>
                <div class="chart-column">
                    <div class="chart-value"><?php echo (int) $graph[1]; ?></div>
                    <div class="chart-track"><span class="chart-bar <?php echo hqiEscape((string) $graph[2]); ?>" style="height:<?php echo $height; ?>%"></span></div>
                    <div class="chart-label"><?php echo hqiEscape((string) $graph[0]); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="ai-card insights-card">
        <div class="card-title-row"><div><h2>AI Operational Insights</h2><small>Priority signals from current data</small></div><span class="insight-dot"></span></div>
        <div class="ai-list">
            <?php foreach ($aiInsights as $insight): ?>
                <div><?php echo hqiEscape($insight); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="premium-grid" id="actions">
    <div class="ai-card">
        <h2>Site Performance Ranking</h2>
        <?php if (!$sitePerformance): ?>
            <div class="empty">No site performance data yet.</div>
        <?php else: ?>
            <?php
            $performanceMax = 1;
            foreach ($sitePerformance as $site) {
                $performanceMax = max($performanceMax, (int) $site['action_total']);
            }
            ?>
            <?php foreach ($sitePerformance as $site): ?>
                <?php
                $completion = (int) $site['action_total'] > 0
                    ? (int) round(((int) $site['closed_actions'] / (int) $site['action_total']) * 100)
                    : 100;
                $volumeWidth = max(4, (int) round(((int) $site['action_total'] / $performanceMax) * 100));
                ?>
                <div class="bar-row">
                    <strong title="<?php echo hqiEscape((string) $site['property_name']); ?>"><?php echo hqiEscape((string) $site['property_code']); ?></strong>
                    <span class="bar-track"><span class="bar-fill <?php echo (int) $site['overdue_14_days'] > 0 ? 'warn' : ''; ?>" style="width:<?php echo $volumeWidth; ?>%"></span></span>
                    <b><?php echo $completion; ?>%</b>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="ai-card">
        <h2>Missing / Rejected After Photos</h2>
        <?php if (!$evidenceExceptions): ?>
            <div class="empty">No missing or rejected After photos.</div>
        <?php else: ?>
            <div class="exception-list">
                <?php foreach ($evidenceExceptions as $item): ?>
                    <?php $isMissing = empty($item['after_image_id']); ?>
                    <div class="exception-card">
                        <div>
                            <strong><?php echo hqiEscape((string) $item['property_code']); ?> · <?php echo hqiEscape((string) $item['inspection_no']); ?></strong>
                            <small><?php echo hqiEscape((string) $item['finding_name']); ?> · <?php echo hqiEscape((string) $item['location']); ?></small>
                            <a class="planner-link" href="inspection_create.php?property_id=<?php echo (int) $item['property_id']; ?>&source=reinspection&follow_up=<?php echo (int) $item['inspection_id']; ?>">
                                Schedule Reinspection · <?php echo date('Y-m-d', strtotime('+7 days')); ?>
                            </a>
                        </div>
                        <?php if ((int) ($item['action_id'] ?? 0) > 0): ?>
                            <a class="sla-badge <?php echo $isMissing ? 'missing' : 'rejected'; ?>"
                               href="action_review.php?id=<?php echo (int) $item['action_id']; ?>&property_id=<?php echo (int) $item['property_id']; ?>">
                                <?php echo $isMissing ? 'Missing After' : 'Rejected After'; ?>
                            </a>
                        <?php else: ?>
                            <span class="sla-badge missing">Not Assigned</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="premium-grid">
    <div class="ai-card">
        <h2>Recurring Defect Detection</h2>
        <?php if (!$recurringDefects): ?>
            <div class="empty">No recurring defects detected yet.</div>
        <?php else: ?>
            <div class="exception-list">
                <?php foreach ($recurringDefects as $defect): ?>
                    <div class="exception-card">
                        <div>
                            <strong><?php echo hqiEscape((string) $defect['property_code']); ?> · <?php echo hqiEscape((string) $defect['finding_name']); ?></strong>
                            <small><?php echo hqiEscape((string) $defect['location']); ?> · Last seen <?php echo hqiEscape((string) $defect['last_seen']); ?></small>
                        </div>
                        <span class="sla-badge over"><?php echo (int) $defect['occurrence_total']; ?> times</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="ai-card">
        <h2>SLA Reminder Automation</h2>
        <?php if (!$slaReminders): ?>
            <div class="empty">No reminders due. All open actions are under 7 days or already closed.</div>
        <?php else: ?>
            <div class="exception-list">
                <?php foreach ($slaReminders as $reminder): ?>
                    <?php
                    $daysElapsed = (int) ($reminder['days_elapsed'] ?? 0);
                    $isEscalation = $daysElapsed >= 14;
                    $email = trim((string) ($reminder['email'] ?? ''));
                    $subject = rawurlencode('CPMS Inspection SLA Reminder - ' . (string) $reminder['inspection_no']);
                    $body = rawurlencode(
                        'Dear Property Team,' . "\n\n"
                        . 'Please update the rectification evidence for '
                        . (string) $reminder['inspection_no'] . ' / '
                        . (string) $reminder['action_no'] . '.'
                    );
                    ?>
                    <div class="exception-card">
                        <div>
                            <strong><?php echo hqiEscape((string) $reminder['property_code']); ?> · <?php echo hqiEscape((string) $reminder['action_no']); ?></strong>
                            <small><?php echo hqiEscape((string) $reminder['title']); ?> · <?php echo $daysElapsed; ?> days from inspection</small>
                            <?php if (filter_var($email, FILTER_VALIDATE_EMAIL)): ?>
                                <a class="planner-link" href="mailto:<?php echo hqiEscape($email); ?>?subject=<?php echo $subject; ?>&body=<?php echo $body; ?>">Prepare Reminder Email</a>
                            <?php endif; ?>
                        </div>
                        <span class="reminder-status <?php echo $isEscalation ? 'escalate' : 'remind'; ?>">
                            <?php echo $isEscalation ? 'Escalation Due' : 'Reminder Due'; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="section-head">
    <div>
        <h2>2-Week Site Response Summary</h2>
        <p class="muted">Sites with incomplete After evidence or verification after 14 days from the inspection date.</p>
    </div>
</div>
<?php if (!$siteSlaSummary): ?>
    <div class="empty">All sites are within the 2-week response window.</div>
<?php else: ?>
<div class="table-wrap"><table class="ops-table"><thead><tr><th>Property</th><th>Latest Inspection</th><th>Open Actions</th><th>Awaiting HQ</th><th>14-Day Status</th></tr></thead><tbody>
<?php foreach ($siteSlaSummary as $site): ?>
    <tr>
        <td><strong><?php echo hqiEscape((string) $site['property_code']); ?></strong><br><small><?php echo hqiEscape((string) $site['property_name']); ?></small></td>
        <td><?php echo hqiEscape((string) ($site['latest_inspection_date'] ?: '-')); ?></td>
        <td><?php echo (int) $site['open_actions']; ?></td>
        <td><?php echo (int) $site['awaiting_hq']; ?></td>
        <td>
            <?php if ((int) $site['overdue_14_days'] > 0): ?>
                <span class="sla-badge over"><?php echo (int) $site['overdue_14_days']; ?> overdue</span>
            <?php else: ?>
                <span class="sla-badge">Within 14 days</span>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<div class="section-head"><h2>Continue Draft</h2><a href="inspections.php?status=Draft">View all drafts</a></div>
<?php if (!$drafts): ?>
    <div class="empty">No draft inspections.</div>
<?php else: ?>
<div class="table-wrap"><table class="ops-table"><thead><tr><th>Inspection</th><th>Property</th><th>Location</th><th>Findings</th><th>Photos</th><th>Updated</th><th></th></tr></thead><tbody>
<?php foreach ($drafts as $draft): ?>
    <tr>
        <td><a href="inspection_view.php?id=<?php echo (int) $draft['id']; ?>&property_id=<?php echo (int) $draft['property_id']; ?>"><?php echo hqiEscape((string) $draft['inspection_no']); ?></a></td>
        <td><?php echo hqiEscape((string) $draft['property_code']); ?></td>
        <td><?php echo hqiEscape((string) $draft['location']); ?></td>
        <td><?php echo (int) $draft['finding_count']; ?></td>
        <td><?php echo (int) $draft['image_count']; ?></td>
        <td><?php echo hqiEscape((string) $draft['updated_at']); ?></td>
        <td><a class="btn" href="inspection_view.php?id=<?php echo (int) $draft['id']; ?>&property_id=<?php echo (int) $draft['property_id']; ?>">Continue</a></td>
    </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<div class="section-head" id="properties">
    <div><h2>Select Property</h2><p class="muted">Select a property before starting an inspection.</p></div>
    <div class="search"><input id="propertySearch" placeholder="Search Property ID, code or name..."></div>
</div>
<div class="table-wrap" id="propertyGrid">
<table class="ops-table property-table">
    <thead>
        <tr>
            <th>Property Details</th>
            <th>ID</th>
            <th>Total</th>
            <th>Draft</th>
            <th>Actions</th>
            <th>Last</th>
            <th>Photos</th>
            <th>Email</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($properties as $property): ?>
        <?php
        $email = trim((string) ($property['email'] ?? ''));
        $hasEmail = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
        $searchText = strtolower(
            (string) $property['id'] . ' '
            . (string) $property['property_code'] . ' '
            . (string) $property['property_name']
        );
        ?>
        <tr data-property-search="<?php echo hqiEscape($searchText); ?>">
            <td>
                <div class="property-code"><?php echo hqiEscape((string) $property['property_code']); ?></div>
                <div class="property-name"><?php echo hqiEscape((string) $property['property_name']); ?></div>
                <div class="property-company"><?php echo hqiEscape((string) $property['company_name']); ?></div>
                <div class="property-address" title="<?php echo hqiEscape((string) $property['address']); ?>"><?php echo hqiEscape((string) $property['address']); ?></div>
            </td>
            <td><span class="property-id"><?php echo (int) $property['id']; ?></span></td>
            <td class="num"><?php echo (int) $property['inspection_total']; ?></td>
            <td class="num"><?php echo (int) $property['draft_total']; ?></td>
            <td class="num"><?php echo (int) $property['open_actions']; ?></td>
            <td><?php echo hqiEscape((string) ($property['last_inspection_date'] ?: '-')); ?></td>
            <td><span class="pill"><?php echo (int) $property['photo_limit']; ?> photos</span></td>
            <td class="<?php echo $hasEmail ? 'email-ok' : 'email-warn'; ?>"><?php echo $hasEmail ? 'Ready' : 'Incomplete'; ?></td>
            <td class="property-action"><a class="btn" href="inspection_create.php?property_id=<?php echo (int) $property['id']; ?>">Create Inspection</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if (!$properties): ?><div class="empty">No active properties found.</div><?php endif; ?>

<div class="section-head"><h2>Recent Inspections</h2><a href="inspections.php">View full history</a></div>
<?php if (!$recent): ?>
    <div class="empty">No inspections yet.</div>
<?php else: ?>
<div class="table-wrap"><table class="ops-table"><thead><tr><th>Inspection</th><th>Property</th><th>Date</th><th>Location</th><th>Findings</th><th>Severity</th><th>Status</th></tr></thead><tbody>
<?php foreach ($recent as $item): ?>
    <tr>
        <td><a href="inspection_view.php?id=<?php echo (int) $item['id']; ?>&property_id=<?php echo (int) $item['property_id']; ?>"><?php echo hqiEscape((string) $item['inspection_no']); ?></a></td>
        <td><?php echo hqiEscape((string) $item['property_code']); ?></td>
        <td><?php echo hqiEscape((string) $item['inspection_date']); ?></td>
        <td><?php echo hqiEscape((string) $item['location']); ?></td>
        <td><?php echo (int) $item['finding_count']; ?></td>
        <td class="priority-<?php echo hqiEscape((string) $item['priority']); ?>"><?php echo hqiEscape((string) $item['priority']); ?></td>
        <td><span class="status <?php echo hqiEscape((string) $item['status']); ?>"><?php echo hqiEscape((string) $item['status']); ?></span></td>
    </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<script>
(function () {
    var input = document.getElementById('propertySearch');
    if (!input) return;
    input.addEventListener('input', function () {
        var term = input.value.toLowerCase().trim();
        document.querySelectorAll('[data-property-search]').forEach(function (row) {
            row.style.display = row.getAttribute('data-property-search').indexOf(term) >= 0
                ? ''
                : 'none';
        });
    });
}());
</script>
<?php require __DIR__ . '/footer.php'; ?>
