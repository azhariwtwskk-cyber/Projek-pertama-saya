<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__)
    . '/includes/preventive_maintenance_service.php';

cpmsRequire('maintenance.analytics', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$allowedPeriods = [30, 90, 180, 365];
$period = (int) ($_GET['period'] ?? 180);
if (!in_array($period, $allowedPeriods, true)) {
    $period = 180;
}
$endDate = date('Y-m-d');
$startDate = date(
    'Y-m-d',
    strtotime('-' . ($period - 1) . ' days')
);

$metrics = [
    'total_jobs' => 0,
    'total_cost' => 0.0,
    'total_downtime' => 0,
    'average_downtime' => 0.0,
    'verified_jobs' => 0,
    'successful_jobs' => 0,
];

$metricStmt = $conn->prepare(
    "SELECT COUNT(*) AS total_jobs,
            COALESCE(SUM(actual_cost), 0) AS total_cost,
            COALESCE(SUM(downtime_minutes), 0) AS total_downtime,
            COALESCE(AVG(downtime_minutes), 0) AS average_downtime,
            SUM(verified_at IS NOT NULL) AS verified_jobs,
            SUM(result = 'Completed') AS successful_jobs
     FROM cpms_pm_work_logs
     WHERE property_id = ?
       AND completed_date BETWEEN ? AND ?"
);
if ($metricStmt) {
    $metricStmt->bind_param(
        'iss',
        $propertyId,
        $startDate,
        $endDate
    );
    $metricStmt->execute();
    $row = $metricStmt->get_result()->fetch_assoc();
    if ($row) {
        $metrics = array_merge($metrics, $row);
    }
    $metricStmt->close();
}

$totalJobs = (int) $metrics['total_jobs'];
$verificationRate = $totalJobs > 0
    ? ((int) $metrics['verified_jobs'] / $totalJobs) * 100
    : 0.0;
$successRate = $totalJobs > 0
    ? ((int) $metrics['successful_jobs'] / $totalJobs) * 100
    : 0.0;

$activeSchedules = 0;
$coveredSchedules = 0;
$coverageStmt = $conn->prepare(
    "SELECT
        COUNT(DISTINCT CASE WHEN s.status = 'active'
              THEN s.id END) AS active_schedules,
        COUNT(DISTINCT CASE WHEN l.id IS NOT NULL
              THEN s.id END) AS covered_schedules
     FROM cpms_pm_schedules s
     LEFT JOIN cpms_pm_work_logs l
       ON l.schedule_id = s.id
      AND l.property_id = s.property_id
      AND l.completed_date BETWEEN ? AND ?
     WHERE s.property_id = ?"
);
if ($coverageStmt) {
    $coverageStmt->bind_param(
        'ssi',
        $startDate,
        $endDate,
        $propertyId
    );
    $coverageStmt->execute();
    $coverage = $coverageStmt->get_result()->fetch_assoc();
    $activeSchedules = (int) (
        $coverage['active_schedules'] ?? 0
    );
    $coveredSchedules = (int) (
        $coverage['covered_schedules'] ?? 0
    );
    $coverageStmt->close();
}
$coverageRate = $activeSchedules > 0
    ? ($coveredSchedules / $activeSchedules) * 100
    : 0.0;

$monthly = [];
$monthCursor = new DateTimeImmutable(
    date('Y-m-01', strtotime($startDate))
);
$lastMonth = new DateTimeImmutable(date('Y-m-01'));
while ($monthCursor <= $lastMonth) {
    $key = $monthCursor->format('Y-m');
    $monthly[$key] = [
        'label' => $monthCursor->format('M Y'),
        'jobs' => 0,
        'cost' => 0.0,
        'downtime' => 0,
    ];
    $monthCursor = $monthCursor->modify('+1 month');
}

$trendStmt = $conn->prepare(
    "SELECT DATE_FORMAT(completed_date, '%Y-%m') AS month_key,
            COUNT(*) AS jobs,
            COALESCE(SUM(actual_cost), 0) AS cost,
            COALESCE(SUM(downtime_minutes), 0) AS downtime
     FROM cpms_pm_work_logs
     WHERE property_id = ?
       AND completed_date BETWEEN ? AND ?
     GROUP BY DATE_FORMAT(completed_date, '%Y-%m')
     ORDER BY month_key"
);
if ($trendStmt) {
    $trendStmt->bind_param(
        'iss',
        $propertyId,
        $startDate,
        $endDate
    );
    $trendStmt->execute();
    $result = $trendStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $key = (string) $row['month_key'];
        if (isset($monthly[$key])) {
            $monthly[$key]['jobs'] = (int) $row['jobs'];
            $monthly[$key]['cost'] = (float) $row['cost'];
            $monthly[$key]['downtime'] = (int) $row['downtime'];
        }
    }
    $trendStmt->close();
}

$assetRows = [];
$assetStmt = $conn->prepare(
    "SELECT s.asset_name,
            COUNT(l.id) AS jobs,
            COALESCE(SUM(l.actual_cost), 0) AS cost,
            COALESCE(SUM(l.downtime_minutes), 0) AS downtime,
            SUM(l.result = 'Completed') AS successful
     FROM cpms_pm_work_logs l
     INNER JOIN cpms_pm_schedules s
        ON s.id = l.schedule_id
       AND s.property_id = l.property_id
     WHERE l.property_id = ?
       AND l.completed_date BETWEEN ? AND ?
     GROUP BY s.asset_name
     ORDER BY cost DESC, jobs DESC
     LIMIT 10"
);
if ($assetStmt) {
    $assetStmt->bind_param(
        'iss',
        $propertyId,
        $startDate,
        $endDate
    );
    $assetStmt->execute();
    $result = $assetStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $assetRows[] = $row;
    }
    $assetStmt->close();
}

$staffRows = [];
$staffStmt = $conn->prepare(
    "SELECT COALESCE(NULLIF(performed_by_name, ''), 'CPMS User')
                AS staff_name,
            COUNT(*) AS jobs,
            COALESCE(SUM(actual_cost), 0) AS cost,
            COALESCE(SUM(downtime_minutes), 0) AS downtime,
            SUM(result = 'Completed') AS successful,
            SUM(verified_at IS NOT NULL) AS verified
     FROM cpms_pm_work_logs
     WHERE property_id = ?
       AND completed_date BETWEEN ? AND ?
     GROUP BY COALESCE(NULLIF(performed_by_name, ''), 'CPMS User')
     ORDER BY jobs DESC, staff_name
     LIMIT 10"
);
if ($staffStmt) {
    $staffStmt->bind_param(
        'iss',
        $propertyId,
        $startDate,
        $endDate
    );
    $staffStmt->execute();
    $result = $staffStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $staffRows[] = $row;
    }
    $staffStmt->close();
}

$maxMonthlyCost = 0.0;
foreach ($monthly as $monthData) {
    $maxMonthlyCost = max(
        $maxMonthlyCost,
        (float) $monthData['cost']
    );
}

$pageTitle = 'Maintenance Analytics';
$activeMenu = 'preventive_maintenance';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.analytics-wrap{padding:24px}.analytics-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.analytics-filter{display:flex;gap:8px;flex-wrap:wrap}.analytics-btn{display:inline-block;padding:9px 13px;border-radius:8px;background:#e8eef7;color:#173b73;text-decoration:none;font-weight:800}.analytics-btn.active{background:#173b73;color:#fff}.analytics-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}.analytics-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px}.analytics-kpi span{display:block;color:#64748b;font-size:13px}.analytics-kpi strong{display:block;color:#173b73;font-size:28px;margin:5px 0}.analytics-kpi small{color:#64748b}.analytics-layout{display:grid;grid-template-columns:1.1fr .9fr;gap:14px;margin-bottom:14px}.analytics-card h2{margin-top:0}.analytics-bars{display:flex;align-items:flex-end;gap:8px;height:245px;padding-top:20px;overflow-x:auto}.analytics-column{min-width:54px;flex:1;text-align:center}.analytics-bar-track{height:175px;display:flex;align-items:flex-end;justify-content:center;background:#f8fafc;border-radius:8px}.analytics-bar{width:72%;min-height:3px;background:linear-gradient(#2f6bc2,#173b73);border-radius:7px 7px 0 0}.analytics-column strong,.analytics-column small{display:block;font-size:11px}.analytics-table{width:100%;border-collapse:collapse}.analytics-table th,.analytics-table td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.analytics-progress{height:10px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:8px}.analytics-progress span{display:block;height:100%;background:#16a34a}.analytics-note{padding:12px;background:#f8fafc;border-radius:8px;color:#475569}.analytics-empty{text-align:center;padding:24px;color:#64748b}@media(max-width:900px){.analytics-wrap{padding:14px}.analytics-head{flex-direction:column}.analytics-kpis{grid-template-columns:repeat(2,1fr)}.analytics-layout{grid-template-columns:1fr}.analytics-table{display:block;overflow-x:auto}}@media print{.admin-sidebar,.admin-topbar,.analytics-filter{display:none!important}.admin-main{margin:0!important}.analytics-wrap{padding:0}}
</style>
<div class="analytics-wrap">
    <div class="analytics-head">
        <div>
            <a href="preventive_maintenance.php">← Preventive Maintenance</a>
            <h1>Maintenance Performance Analytics</h1>
            <p><?php echo cpmsPmEscape($startDate); ?>
                — <?php echo cpmsPmEscape($endDate); ?></p>
        </div>
        <div class="analytics-filter">
            <?php foreach ($allowedPeriods as $days): ?>
                <a class="analytics-btn <?php echo $period === $days ? 'active' : ''; ?>"
                   href="?period=<?php echo $days; ?>">
                    <?php echo $days; ?> Days
                </a>
            <?php endforeach; ?>
            <button class="analytics-btn"
                    type="button"
                    onclick="window.print()">Print</button>
        </div>
    </div>

    <section class="analytics-kpis">
        <div class="analytics-card analytics-kpi">
            <span>Total Maintenance Cost</span>
            <strong>RM <?php echo number_format(
                (float) $metrics['total_cost'],
                2
            ); ?></strong>
            <small><?php echo $totalJobs; ?> completed job(s)</small>
        </div>
        <div class="analytics-card analytics-kpi">
            <span>Total Downtime</span>
            <strong><?php echo number_format(
                (int) $metrics['total_downtime']
            ); ?> min</strong>
            <small>Average <?php echo number_format(
                (float) $metrics['average_downtime'],
                1
            ); ?> min/job</small>
        </div>
        <div class="analytics-card analytics-kpi">
            <span>Successful Completion</span>
            <strong><?php echo number_format($successRate, 1); ?>%</strong>
            <div class="analytics-progress"><span style="width:<?php echo min(100, $successRate); ?>%"></span></div>
        </div>
        <div class="analytics-card analytics-kpi">
            <span>Management Verification</span>
            <strong><?php echo number_format($verificationRate, 1); ?>%</strong>
            <div class="analytics-progress"><span style="width:<?php echo min(100, $verificationRate); ?>%"></span></div>
        </div>
    </section>

    <section class="analytics-layout">
        <div class="analytics-card">
            <h2>Monthly Cost Trend</h2>
            <?php if ($totalJobs < 1): ?>
                <div class="analytics-empty">No maintenance data for this period.</div>
            <?php else: ?>
                <div class="analytics-bars">
                    <?php foreach ($monthly as $monthData):
                        $height = $maxMonthlyCost > 0
                            ? ((float) $monthData['cost']
                                / $maxMonthlyCost) * 100
                            : 0;
                    ?>
                        <div class="analytics-column">
                            <strong>RM <?php echo number_format(
                                (float) $monthData['cost'],
                                0
                            ); ?></strong>
                            <div class="analytics-bar-track">
                                <span class="analytics-bar"
                                      style="height:<?php echo max(2, $height); ?>%"></span>
                            </div>
                            <small><?php echo cpmsPmEscape(
                                $monthData['label']
                            ); ?></small>
                            <small><?php echo (int) $monthData['jobs']; ?> job</small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="analytics-card">
            <h2>Schedule Coverage</h2>
            <div class="analytics-kpi">
                <strong><?php echo number_format($coverageRate, 1); ?>%</strong>
                <span><?php echo $coveredSchedules; ?> of
                    <?php echo $activeSchedules; ?> active schedules
                    had maintenance activity.</span>
            </div>
            <div class="analytics-progress">
                <span style="width:<?php echo min(100, $coverageRate); ?>%"></span>
            </div>
            <p class="analytics-note">
                Coverage measures active schedules with at least one
                completion during the selected period.
            </p>
        </div>
    </section>

    <section class="analytics-layout">
        <div class="analytics-card">
            <h2>Cost & Downtime by Asset</h2>
            <table class="analytics-table">
                <thead><tr><th>Asset</th><th>Jobs</th><th>Cost</th><th>Downtime</th><th>Success</th></tr></thead>
                <tbody>
                <?php if (!$assetRows): ?>
                    <tr><td colspan="5">No asset performance data.</td></tr>
                <?php endif; ?>
                <?php foreach ($assetRows as $row):
                    $jobs = (int) $row['jobs'];
                    $rate = $jobs > 0
                        ? ((int) $row['successful'] / $jobs) * 100
                        : 0;
                ?>
                    <tr>
                        <td><?php echo cpmsPmEscape($row['asset_name']); ?></td>
                        <td><?php echo $jobs; ?></td>
                        <td>RM <?php echo number_format((float) $row['cost'], 2); ?></td>
                        <td><?php echo number_format((int) $row['downtime']); ?> min</td>
                        <td><?php echo number_format($rate, 0); ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="analytics-card">
            <h2>Staff Performance</h2>
            <table class="analytics-table">
                <thead><tr><th>Staff</th><th>Jobs</th><th>Success</th><th>Verified</th></tr></thead>
                <tbody>
                <?php if (!$staffRows): ?>
                    <tr><td colspan="4">No staff performance data.</td></tr>
                <?php endif; ?>
                <?php foreach ($staffRows as $row):
                    $jobs = (int) $row['jobs'];
                    $staffSuccess = $jobs > 0
                        ? ((int) $row['successful'] / $jobs) * 100
                        : 0;
                    $staffVerified = $jobs > 0
                        ? ((int) $row['verified'] / $jobs) * 100
                        : 0;
                ?>
                    <tr>
                        <td><?php echo cpmsPmEscape($row['staff_name']); ?></td>
                        <td><?php echo $jobs; ?></td>
                        <td><?php echo number_format($staffSuccess, 0); ?>%</td>
                        <td><?php echo number_format($staffVerified, 0); ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
