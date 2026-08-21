<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__)
    . '/includes/preventive_maintenance_service.php';

cpmsRequire('maintenance.report', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$currentYear = (int) date('Y');
$year = (int) ($_GET['year'] ?? $currentYear);
if ($year < 2020 || $year > $currentYear + 5) {
    $year = $currentYear;
}
$startDate = $year . '-01-01';
$endDate = $year . '-12-31';

$summary = [
    'total_schedules' => 0,
    'active_schedules' => 0,
    'overdue_schedules' => 0,
    'due_soon_schedules' => 0,
];
$scheduleStmt = $conn->prepare(
    "SELECT COUNT(*) AS total_schedules,
            SUM(status = 'active') AS active_schedules,
            SUM(status = 'active'
                AND next_due_date < CURDATE()) AS overdue_schedules,
            SUM(status = 'active'
                AND next_due_date BETWEEN CURDATE()
                    AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))
                AS due_soon_schedules
     FROM cpms_pm_schedules
     WHERE property_id = ?"
);
if ($scheduleStmt) {
    $scheduleStmt->bind_param('i', $propertyId);
    $scheduleStmt->execute();
    $row = $scheduleStmt->get_result()->fetch_assoc();
    if ($row) {
        $summary = array_merge($summary, $row);
    }
    $scheduleStmt->close();
}

$performance = [
    'jobs' => 0,
    'actual_cost' => 0.0,
    'downtime' => 0,
    'verified' => 0,
    'successful' => 0,
];
$performanceStmt = $conn->prepare(
    "SELECT COUNT(*) AS jobs,
            COALESCE(SUM(actual_cost), 0) AS actual_cost,
            COALESCE(SUM(downtime_minutes), 0) AS downtime,
            SUM(verified_at IS NOT NULL) AS verified,
            SUM(result = 'Completed') AS successful
     FROM cpms_pm_work_logs
     WHERE property_id = ?
       AND completed_date BETWEEN ? AND ?"
);
if ($performanceStmt) {
    $performanceStmt->bind_param(
        'iss',
        $propertyId,
        $startDate,
        $endDate
    );
    $performanceStmt->execute();
    $row = $performanceStmt->get_result()->fetch_assoc();
    if ($row) {
        $performance = array_merge($performance, $row);
    }
    $performanceStmt->close();
}

$months = [];
for ($month = 1; $month <= 12; $month++) {
    $months[$month] = [
        'label' => date('F', mktime(0, 0, 0, $month, 1)),
        'budget' => 0.0,
        'actual' => 0.0,
        'jobs' => 0,
        'downtime' => 0,
    ];
}

$budgetStmt = $conn->prepare(
    "SELECT budget_month, budget_amount
     FROM cpms_pm_budgets
     WHERE property_id = ?
       AND budget_year = ?"
);
if ($budgetStmt) {
    $budgetStmt->bind_param('ii', $propertyId, $year);
    $budgetStmt->execute();
    $result = $budgetStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $month = (int) $row['budget_month'];
        if (isset($months[$month])) {
            $months[$month]['budget'] = (float) $row['budget_amount'];
        }
    }
    $budgetStmt->close();
}

$monthlyStmt = $conn->prepare(
    "SELECT MONTH(completed_date) AS month_number,
            COUNT(*) AS jobs,
            COALESCE(SUM(actual_cost), 0) AS actual,
            COALESCE(SUM(downtime_minutes), 0) AS downtime
     FROM cpms_pm_work_logs
     WHERE property_id = ?
       AND completed_date BETWEEN ? AND ?
     GROUP BY MONTH(completed_date)"
);
if ($monthlyStmt) {
    $monthlyStmt->bind_param(
        'iss',
        $propertyId,
        $startDate,
        $endDate
    );
    $monthlyStmt->execute();
    $result = $monthlyStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $month = (int) $row['month_number'];
        if (isset($months[$month])) {
            $months[$month]['jobs'] = (int) $row['jobs'];
            $months[$month]['actual'] = (float) $row['actual'];
            $months[$month]['downtime'] = (int) $row['downtime'];
        }
    }
    $monthlyStmt->close();
}

$assetRows = [];
$assetStmt = $conn->prepare(
    "SELECT s.asset_name,
            COUNT(l.id) AS jobs,
            COALESCE(SUM(l.actual_cost), 0) AS cost,
            COALESCE(SUM(l.downtime_minutes), 0) AS downtime,
            SUM(l.result = 'Completed') AS successful,
            SUM(l.verified_at IS NOT NULL) AS verified
     FROM cpms_pm_work_logs l
     INNER JOIN cpms_pm_schedules s
        ON s.id = l.schedule_id
       AND s.property_id = l.property_id
     WHERE l.property_id = ?
       AND l.completed_date BETWEEN ? AND ?
     GROUP BY s.asset_name
     ORDER BY cost DESC, jobs DESC"
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

$annualBudget = 0.0;
foreach ($months as $monthData) {
    $annualBudget += (float) $monthData['budget'];
}
$actualCost = (float) $performance['actual_cost'];
$variance = $annualBudget - $actualCost;
$jobs = (int) $performance['jobs'];
$successRate = $jobs > 0
    ? ((int) $performance['successful'] / $jobs) * 100
    : 0.0;
$verificationRate = $jobs > 0
    ? ((int) $performance['verified'] / $jobs) * 100
    : 0.0;

$pageTitle = 'Maintenance Final Report';
$activeMenu = 'preventive_maintenance';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.report-toolbar{display:flex;justify-content:center;gap:10px;padding:14px;background:#102442}.report-btn{display:inline-block;border:0;border-radius:8px;padding:10px 15px;background:#2d67ba;color:#fff;text-decoration:none;font-weight:800;cursor:pointer}.report-btn.secondary{background:#fff;color:#102442}.report-sheet{max-width:980px;margin:22px auto;background:#fff;padding:38px;border:1px solid #dbe3ef;box-shadow:0 12px 35px rgba(15,35,65,.08)}.report-header{display:flex;justify-content:space-between;gap:20px;align-items:center;border-bottom:3px solid #173b73;padding-bottom:18px}.report-logo{width:78px;height:78px;object-fit:contain}.report-property{display:flex;gap:16px;align-items:center}.report-property h1{margin:0;color:#102442}.report-reference{text-align:right}.report-section{margin-top:22px}.report-title{background:#e8eef7;border-left:5px solid #173b73;padding:10px 12px;color:#102442;font-size:18px}.report-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.report-kpi{border:1px solid #dbe3ef;padding:13px}.report-kpi span{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.report-kpi strong{display:block;margin-top:5px;font-size:19px;color:#102442}.report-table{width:100%;border-collapse:collapse}.report-table th,.report-table td{border:1px solid #dbe3ef;padding:8px;text-align:left}.report-table th{background:#edf2f8}.report-negative{color:#b91c1c}.report-positive{color:#15803d}.report-signatures{display:grid;grid-template-columns:repeat(3,1fr);gap:35px;margin-top:70px}.report-signature{border-top:1px solid #334155;padding-top:8px;text-align:center}.report-footer{border-top:1px solid #dbe3ef;margin-top:35px;padding-top:10px;color:#64748b;font-size:11px;display:flex;justify-content:space-between}@media(max-width:800px){.report-sheet{margin:0;padding:18px}.report-header{align-items:flex-start;flex-direction:column}.report-reference{text-align:left}.report-kpis{grid-template-columns:repeat(2,1fr)}.report-table{display:block;overflow-x:auto}.report-signatures{grid-template-columns:1fr;gap:55px}}@media print{@page{size:A4 portrait;margin:12mm}.admin-sidebar,.admin-topbar,.report-toolbar{display:none!important}.admin-main{margin:0!important}.report-sheet{box-shadow:none;border:0;margin:0;max-width:none;padding:0}.report-section{break-inside:avoid}.report-table{font-size:10px}.report-footer{position:relative}}
</style>
<div class="report-toolbar">
    <a class="report-btn secondary"
       href="preventive_maintenance.php">Back</a>
    <a class="report-btn secondary"
       href="?year=<?php echo $year - 1; ?>">← <?php echo $year - 1; ?></a>
    <a class="report-btn secondary"
       href="?year=<?php echo $year + 1; ?>"><?php echo $year + 1; ?> →</a>
    <button class="report-btn" onclick="window.print()">
        Print / Save as PDF
    </button>
</div>
<main class="report-sheet">
    <header class="report-header">
        <div class="report-property">
            <?php if (!empty($propertyPortalUser['logo_path'])): ?>
                <img class="report-logo"
                     src="<?php echo propertyPortalEscape(
                         cpmsBrandingAssetUrl(
                             $propertyPortalUser['logo_path']
                         )
                     ); ?>"
                     alt="Property logo">
            <?php endif; ?>
            <div>
                <h1><?php echo cpmsPmEscape($currentPropertyName); ?></h1>
                <strong>PREVENTIVE MAINTENANCE FINAL REPORT</strong>
            </div>
        </div>
        <div class="report-reference">
            <small>REPORT YEAR</small>
            <h2><?php echo $year; ?></h2>
            <span><?php echo cpmsPmEscape($currentPropertyCode); ?></span>
        </div>
    </header>

    <section class="report-section">
        <h2 class="report-title">1. Executive Summary</h2>
        <div class="report-kpis">
            <div class="report-kpi"><span>Total Schedules</span><strong><?php echo (int) $summary['total_schedules']; ?></strong></div>
            <div class="report-kpi"><span>Active Schedules</span><strong><?php echo (int) $summary['active_schedules']; ?></strong></div>
            <div class="report-kpi"><span>Maintenance Jobs</span><strong><?php echo $jobs; ?></strong></div>
            <div class="report-kpi"><span>Total Downtime</span><strong><?php echo number_format((int) $performance['downtime']); ?> min</strong></div>
            <div class="report-kpi"><span>Annual Budget</span><strong>RM <?php echo number_format($annualBudget, 2); ?></strong></div>
            <div class="report-kpi"><span>Actual Cost</span><strong>RM <?php echo number_format($actualCost, 2); ?></strong></div>
            <div class="report-kpi"><span>Budget Balance</span><strong class="<?php echo $variance < 0 ? 'report-negative' : 'report-positive'; ?>">RM <?php echo number_format($variance, 2); ?></strong></div>
            <div class="report-kpi"><span>Overdue Now</span><strong><?php echo (int) $summary['overdue_schedules']; ?></strong></div>
        </div>
    </section>

    <section class="report-section">
        <h2 class="report-title">2. Performance & Governance</h2>
        <table class="report-table">
            <tbody>
                <tr><th>Successful Completion Rate</th><td><?php echo number_format($successRate, 1); ?>%</td><th>Management Verification Rate</th><td><?php echo number_format($verificationRate, 1); ?>%</td></tr>
                <tr><th>Due Within 7 Days</th><td><?php echo (int) $summary['due_soon_schedules']; ?></td><th>Overdue Schedules</th><td><?php echo (int) $summary['overdue_schedules']; ?></td></tr>
            </tbody>
        </table>
    </section>

    <section class="report-section">
        <h2 class="report-title">3. Monthly Budget & Cost Variance</h2>
        <table class="report-table">
            <thead><tr><th>Month</th><th>Jobs</th><th>Budget</th><th>Actual</th><th>Variance</th><th>Downtime</th></tr></thead>
            <tbody>
                <?php foreach ($months as $monthData):
                    $monthVariance = (float) $monthData['budget']
                        - (float) $monthData['actual'];
                ?>
                    <tr>
                        <td><?php echo cpmsPmEscape($monthData['label']); ?></td>
                        <td><?php echo (int) $monthData['jobs']; ?></td>
                        <td>RM <?php echo number_format((float) $monthData['budget'], 2); ?></td>
                        <td>RM <?php echo number_format((float) $monthData['actual'], 2); ?></td>
                        <td class="<?php echo $monthVariance < 0 ? 'report-negative' : 'report-positive'; ?>">RM <?php echo number_format($monthVariance, 2); ?></td>
                        <td><?php echo number_format((int) $monthData['downtime']); ?> min</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="report-section">
        <h2 class="report-title">4. Asset Performance</h2>
        <table class="report-table">
            <thead><tr><th>Asset</th><th>Jobs</th><th>Cost</th><th>Downtime</th><th>Successful</th><th>Verified</th></tr></thead>
            <tbody>
                <?php if (!$assetRows): ?><tr><td colspan="6">No maintenance activity recorded for this year.</td></tr><?php endif; ?>
                <?php foreach ($assetRows as $row): ?>
                    <tr>
                        <td><?php echo cpmsPmEscape($row['asset_name']); ?></td>
                        <td><?php echo (int) $row['jobs']; ?></td>
                        <td>RM <?php echo number_format((float) $row['cost'], 2); ?></td>
                        <td><?php echo number_format((int) $row['downtime']); ?> min</td>
                        <td><?php echo (int) $row['successful']; ?></td>
                        <td><?php echo (int) $row['verified']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="report-signatures">
        <div class="report-signature">Prepared By</div>
        <div class="report-signature">Verified By</div>
        <div class="report-signature">Approved By</div>
    </section>

    <footer class="report-footer">
        <span>Generated by <?php echo cpmsPmEscape(
            cpmsPmCurrentUserName()
        ); ?> on <?php echo date('d/m/Y H:i'); ?></span>
        <span>CPMS v3.3.6</span>
    </footer>
</main>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
