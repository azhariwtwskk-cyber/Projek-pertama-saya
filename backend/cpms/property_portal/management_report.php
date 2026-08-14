<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
cpmsPropertyRequire('reports.view');
require_once dirname(__DIR__) . '/includes/report_service.php';

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}

$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));
$periodLabel = date('F Y', strtotime($from));

function cpmsManagementMetric(
    mysqli $conn,
    string $table,
    int $propertyId,
    string $from,
    string $to,
    array $dateChoices
): int {
    if (!cpmsReportTableExists($conn, $table)) {
        return 0;
    }

    $dateColumn = cpmsReportDateColumn($conn, $table, $dateChoices);
    if ($dateColumn === null) {
        return 0;
    }

    list($where, $types, $params) = cpmsReportBuildWhere(
        cpmsReportPropertyWhere($conn, $table, $propertyId),
        cpmsReportDateFilter($conn, $table, $dateColumn, $from, $to)
    );

    return (int) cpmsReportScalar(
        $conn,
        "SELECT COUNT(*) FROM `{$table}`{$where}",
        $types,
        $params
    );
}

function cpmsManagementStatusMetric(
    mysqli $conn,
    string $table,
    int $propertyId,
    string $from,
    string $to,
    array $dateChoices,
    array $statusChoices,
    array $acceptedStatuses
): int {
    if (!cpmsReportTableExists($conn, $table)) {
        return 0;
    }

    $statusColumn = null;
    foreach ($statusChoices as $choice) {
        if (cpmsReportHasColumn($conn, $table, $choice)) {
            $statusColumn = $choice;
            break;
        }
    }

    if ($statusColumn === null) {
        return 0;
    }

    $dateColumn = cpmsReportDateColumn($conn, $table, $dateChoices);
    if ($dateColumn === null) {
        return 0;
    }

    list($where, $types, $params) = cpmsReportBuildWhere(
        cpmsReportPropertyWhere($conn, $table, $propertyId),
        cpmsReportDateFilter($conn, $table, $dateColumn, $from, $to)
    );

    $placeholders = implode(',', array_fill(0, count($acceptedStatuses), '?'));
    $where .= ($where === '' ? ' WHERE ' : ' AND ')
        . "LOWER(`{$statusColumn}`) IN ({$placeholders})";
    $types .= str_repeat('s', count($acceptedStatuses));
    foreach ($acceptedStatuses as $status) {
        $params[] = strtolower($status);
    }

    return (int) cpmsReportScalar(
        $conn,
        "SELECT COUNT(*) FROM `{$table}`{$where}",
        $types,
        $params
    );
}

function cpmsManagementMoney(
    mysqli $conn,
    string $table,
    int $propertyId,
    string $from,
    string $to,
    array $dateChoices,
    array $amountChoices
): float {
    if (!cpmsReportTableExists($conn, $table)) {
        return 0.0;
    }

    $amountColumn = null;
    foreach ($amountChoices as $choice) {
        if (cpmsReportHasColumn($conn, $table, $choice)) {
            $amountColumn = $choice;
            break;
        }
    }

    if ($amountColumn === null) {
        return 0.0;
    }

    $dateColumn = cpmsReportDateColumn($conn, $table, $dateChoices);
    if ($dateColumn === null) {
        return 0.0;
    }

    list($where, $types, $params) = cpmsReportBuildWhere(
        cpmsReportPropertyWhere($conn, $table, $propertyId),
        cpmsReportDateFilter($conn, $table, $dateColumn, $from, $to)
    );

    return cpmsReportScalar(
        $conn,
        "SELECT COALESCE(SUM(`{$amountColumn}`),0) FROM `{$table}`{$where}",
        $types,
        $params
    );
}

function cpmsManagementDailyWorkRows(
    mysqli $conn,
    int $propertyId,
    string $from,
    string $to
): array {
    if (!cpmsReportTableExists($conn, 'daily_work_logs')) {
        return [];
    }

    $where = 'd.work_date>=? AND d.work_date<=?';
    $types = 'ss';
    $params = [$from, $to];
    $join = '';

    if (cpmsReportHasColumn($conn, 'daily_work_logs', 'property_id')) {
        $where .= ' AND d.property_id=?';
        $types .= 'i';
        $params[] = $propertyId;
    } elseif (
        cpmsReportTableExists($conn, 'staff')
        && cpmsReportHasColumn($conn, 'staff', 'property_id')
        && cpmsReportHasColumn($conn, 'daily_work_logs', 'staff_id')
    ) {
        $join = ' INNER JOIN staff s ON s.id=d.staff_id';
        $where .= ' AND s.property_id=?';
        $types .= 'i';
        $params[] = $propertyId;
    }

    if (cpmsReportHasColumn($conn, 'daily_work_logs', 'work_status')) {
        $where .= " AND d.work_status='Verified'";
    }
    if (cpmsReportHasColumn($conn, 'daily_work_logs', 'include_in_monthly_report')) {
        $where .= ' AND d.include_in_monthly_report=1';
    }

    $staffJoin = $join === ''
        ? ' LEFT JOIN staff s ON s.id=d.staff_id'
        : $join;
    $sql = "SELECT d.work_reference,d.work_date,d.work_category,
                   d.block_location,d.specific_location,d.work_description,
                   s.full_name
            FROM daily_work_logs d
            {$staffJoin}
            WHERE {$where}
            ORDER BY d.work_date DESC,d.id DESC
            LIMIT 30";

    return cpmsReportFetch($conn, $sql, $types, $params);
}

$complaints = cpmsManagementMetric(
    $conn,
    'complaints',
    $currentPropertyId,
    $from,
    $to,
    ['created_at', 'complaint_date', 'date_created']
);
$resolvedComplaints = cpmsManagementStatusMetric(
    $conn,
    'complaints',
    $currentPropertyId,
    $from,
    $to,
    ['created_at', 'complaint_date', 'date_created'],
    ['status', 'complaint_status'],
    ['resolved', 'closed', 'completed']
);
$workOrders = cpmsManagementMetric(
    $conn,
    'work_orders',
    $currentPropertyId,
    $from,
    $to,
    ['created_at', 'scheduled_date', 'due_date']
);
$completedWorkOrders = cpmsManagementStatusMetric(
    $conn,
    'work_orders',
    $currentPropertyId,
    $from,
    $to,
    ['created_at', 'scheduled_date', 'due_date'],
    ['status'],
    ['completed', 'verified', 'closed']
);
$inspections = cpmsManagementMetric(
    $conn,
    'inspection_reports',
    $currentPropertyId,
    $from,
    $to,
    ['inspection_date', 'created_at']
);
$preventiveMaintenance = cpmsManagementMetric(
    $conn,
    'pm_completions',
    $currentPropertyId,
    $from,
    $to,
    ['completed_at', 'completion_date', 'created_at']
);
$attendance = cpmsManagementMetric(
    $conn,
    'cpms_attendance_sessions',
    $currentPropertyId,
    $from,
    $to,
    ['clock_in_at', 'attendance_date', 'created_at']
);
$facilityBookings = cpmsManagementMetric(
    $conn,
    'cpms_facility_bookings',
    $currentPropertyId,
    $from,
    $to,
    ['booking_date', 'created_at', 'start_at']
);
$maintenanceCost = cpmsManagementMoney(
    $conn,
    'pm_completions',
    $currentPropertyId,
    $from,
    $to,
    ['completed_at', 'completion_date', 'created_at'],
    ['actual_cost', 'cost']
);
$workOrderCost = cpmsManagementMoney(
    $conn,
    'work_orders',
    $currentPropertyId,
    $from,
    $to,
    ['created_at', 'completed_at', 'scheduled_date'],
    ['actual_cost', 'cost']
);

$complaintRate = $complaints > 0
    ? round(($resolvedComplaints / $complaints) * 100)
    : 0;
$workOrderRate = $workOrders > 0
    ? round(($completedWorkOrders / $workOrders) * 100)
    : 0;
$dailyWorkRows = cpmsManagementDailyWorkRows(
    $conn,
    $currentPropertyId,
    $from,
    $to
);

$pageTitle = 'Monthly Management Report';
$activeMenu = 'reports';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.management-actions{display:flex;gap:12px;justify-content:space-between;align-items:end;margin-bottom:18px;flex-wrap:wrap}
.management-actions form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
.management-actions label{display:grid;gap:6px;font-weight:700;color:#334155}
.management-actions input{min-height:44px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:10px}
.management-button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;border:0;border-radius:10px;background:var(--property-primary);color:#fff;text-decoration:none;font-weight:800;cursor:pointer}
.management-button.secondary{background:#e2e8f0;color:#0f172a}
.management-paper{background:#fff;border:1px solid #dbe3ef;border-radius:18px;padding:34px;box-shadow:0 18px 40px rgba(15,23,42,.08)}
.management-head{display:flex;justify-content:space-between;gap:24px;padding-bottom:22px;border-bottom:3px solid var(--property-primary)}
.management-head h1{margin:0 0 6px;font-size:30px;color:#0f172a}.management-head p{margin:0;color:#64748b}
.management-ref{text-align:right}.management-ref strong{display:block;font-size:20px;color:#0f172a}
.management-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:24px 0}
.management-kpi{padding:18px;border-radius:14px;background:#f1f5f9;border-left:4px solid var(--property-primary)}
.management-kpi span{display:block;color:#64748b;font-size:13px;font-weight:700}.management-kpi strong{display:block;margin-top:6px;font-size:28px;color:#0f172a}
.management-section{margin-top:25px}.management-section h2{padding:10px 14px;margin:0 0 12px;background:#eaf0f8;border-left:4px solid var(--property-primary);font-size:18px}
.management-table{width:100%;border-collapse:collapse}.management-table th,.management-table td{padding:11px 12px;border:1px solid #dbe3ef;text-align:left}.management-table th{background:#f8fafc;color:#475569}
.management-note{min-height:90px;padding:14px;border:1px dashed #94a3b8;color:#64748b}
.management-signatures{display:grid;grid-template-columns:1fr 1fr;gap:50px;margin-top:55px}.management-signature{padding-top:38px;border-top:1px solid #475569}
@media(max-width:850px){.management-paper{padding:20px}.management-kpis{grid-template-columns:repeat(2,1fr)}.management-head{display:block}.management-ref{text-align:left;margin-top:15px}.management-signatures{grid-template-columns:1fr;gap:30px}}
@media print{
  .admin-sidebar,.admin-topbar,.admin-footer,.sidebar-overlay,.no-print{display:none!important}
  .admin-shell,.admin-main,.admin-content{display:block!important;width:100%!important;margin:0!important;padding:0!important;background:#fff!important}
  .management-paper{border:0;box-shadow:none;border-radius:0;padding:10mm}
  .management-kpis{grid-template-columns:repeat(4,1fr)}
  @page{size:A4 portrait;margin:8mm}
}
</style>

<section class="page-heading no-print">
    <div>
        <span class="section-label">MANAGEMENT REPORTING</span>
        <h1>Monthly Management Report</h1>
        <p>Ringkasan operasi bulanan untuk property semasa.</p>
    </div>
</section>

<div class="management-actions no-print">
    <form method="get">
        <label>
            Bulan laporan
            <input type="month" name="month"
                   value="<?php echo propertyPortalEscape($month); ?>">
        </label>
        <button class="management-button" type="submit">Papar Laporan</button>
        <a class="management-button secondary" href="reports.php">Detailed Reports</a>
    </form>
    <button class="management-button" type="button" onclick="window.print()">
        Print / Save as PDF
    </button>
</div>

<article class="management-paper">
    <header class="management-head">
        <div>
            <h1><?php echo propertyPortalEscape($currentPropertyName); ?></h1>
            <p>MONTHLY MANAGEMENT REPORT</p>
        </div>
        <div class="management-ref">
            <span>Reporting Period</span>
            <strong><?php echo propertyPortalEscape($periodLabel); ?></strong>
            <small>Property <?php echo (int) $currentPropertyId; ?></small>
        </div>
    </header>

    <section class="management-kpis">
        <div class="management-kpi"><span>Complaints</span><strong><?php echo $complaints; ?></strong></div>
        <div class="management-kpi"><span>Resolution Rate</span><strong><?php echo $complaintRate; ?>%</strong></div>
        <div class="management-kpi"><span>Work Orders</span><strong><?php echo $workOrders; ?></strong></div>
        <div class="management-kpi"><span>WO Completion</span><strong><?php echo $workOrderRate; ?>%</strong></div>
        <div class="management-kpi"><span>Inspections</span><strong><?php echo $inspections; ?></strong></div>
        <div class="management-kpi"><span>PM Completed</span><strong><?php echo $preventiveMaintenance; ?></strong></div>
        <div class="management-kpi"><span>Attendance Records</span><strong><?php echo $attendance; ?></strong></div>
        <div class="management-kpi"><span>Selected Daily Work</span><strong><?php echo count($dailyWorkRows); ?></strong></div>
    </section>

    <section class="management-section">
        <h2>1. Operational Summary</h2>
        <table class="management-table">
            <thead><tr><th>Module</th><th>Total</th><th>Completed / Resolved</th><th>Performance</th></tr></thead>
            <tbody>
                <tr><td>Complaints</td><td><?php echo $complaints; ?></td><td><?php echo $resolvedComplaints; ?></td><td><?php echo $complaintRate; ?>%</td></tr>
                <tr><td>Work Orders</td><td><?php echo $workOrders; ?></td><td><?php echo $completedWorkOrders; ?></td><td><?php echo $workOrderRate; ?>%</td></tr>
                <tr><td>Inspections</td><td><?php echo $inspections; ?></td><td colspan="2">Refer to detailed inspection report</td></tr>
                <tr><td>Preventive Maintenance</td><td><?php echo $preventiveMaintenance; ?></td><td colspan="2">Completed during selected month</td></tr>
            </tbody>
        </table>
    </section>

    <section class="management-section">
        <h2>2. Cost Summary</h2>
        <table class="management-table">
            <tbody>
                <tr><th>Preventive Maintenance Cost</th><td>RM <?php echo number_format($maintenanceCost, 2); ?></td></tr>
                <tr><th>Work Order Cost</th><td>RM <?php echo number_format($workOrderCost, 2); ?></td></tr>
                <tr><th>Total Recorded Operational Cost</th><td><strong>RM <?php echo number_format($maintenanceCost + $workOrderCost, 2); ?></strong></td></tr>
            </tbody>
        </table>
    </section>

    <section class="management-section">
        <h2>3. Selected Daily Work</h2>
        <?php if (!$dailyWorkRows): ?>
            <div class="management-note">
                Tiada kerja harian dipilih untuk Monthly Report bulan ini.
            </div>
        <?php else: ?>
            <table class="management-table">
                <thead><tr><th>Date</th><th>Staff</th><th>Work</th><th>Location</th></tr></thead>
                <tbody>
                    <?php foreach ($dailyWorkRows as $work): ?>
                        <tr>
                            <td><?php echo propertyPortalEscape($work['work_date'] ?? ''); ?></td>
                            <td><?php echo propertyPortalEscape($work['full_name'] ?? '-'); ?></td>
                            <td>
                                <strong><?php echo propertyPortalEscape($work['work_category'] ?? '-'); ?></strong><br>
                                <?php echo propertyPortalEscape($work['work_description'] ?? '-'); ?>
                            </td>
                            <td><?php echo propertyPortalEscape(trim((string) (($work['block_location'] ?? '') . ' ' . ($work['specific_location'] ?? '')))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="management-section">
        <h2>4. Management Remarks</h2>
        <div class="management-note">
            Ruang catatan pengurusan untuk pencapaian, isu utama dan tindakan susulan bulan ini.
        </div>
    </section>

    <section class="management-signatures">
        <div class="management-signature">Prepared by<br><strong>Property Management</strong></div>
        <div class="management-signature">Reviewed / Approved by<br><strong>Management Representative</strong></div>
    </section>
</article>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
