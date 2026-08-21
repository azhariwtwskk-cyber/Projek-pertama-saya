<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/permission_engine.php';

cpmsRequire('dashboard.view', $conn);
require_once __DIR__ . '/includes/guards/guard_dashboard.php';

function cpmsDashboardTableExists(
    mysqli $conn,
    string $table
): bool {
    $allowedTables = [
        'complaints',
        'work_orders',
        'daily_work_logs',
        'daily_work_images',
        'staff',
        'admin_staff',
        'cpms_resident_registrations',
        'cpms_facility_bookings',
    ];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $escapedTable = $conn->real_escape_string($table);
    $result = $conn->query(
        "SHOW TABLES LIKE '{$escapedTable}'"
    );

    return $result instanceof mysqli_result
        && $result->num_rows > 0;
}

function cpmsDashboardColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $allowedTables = [
        'complaints',
        'work_orders',
        'daily_work_logs',
        'daily_work_images',
        'staff',
        'admin_staff',
        'cpms_resident_registrations',
        'cpms_facility_bookings',
    ];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query(
        "SHOW COLUMNS FROM `$table`
         LIKE '{$escapedColumn}'"
    );

    return $result instanceof mysqli_result
        && $result->num_rows > 0;
}

function cpmsDashboardScalar(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): int {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return 0;
    }

    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return (int) ($row[0] ?? 0);
}

function cpmsDashboardDailyWorkWhere(
    mysqli $conn,
    int $propertyId,
    string $alias = 'd'
): array {
    $prefix = $alias !== '' ? $alias . '.' : '';

    if (
        cpmsDashboardColumnExists($conn, 'daily_work_logs', 'property_id')
        && cpmsDashboardColumnExists($conn, 'daily_work_logs', 'staff_id')
        && cpmsDashboardColumnExists($conn, 'staff', 'property_id')
    ) {
        return [
            '(' . $prefix . 'property_id = ? OR (COALESCE(' . $prefix . 'property_id,0)=0 AND s.property_id = ?))',
            'ii',
            [$propertyId, $propertyId],
            ' INNER JOIN staff s ON s.id = ' . $prefix . 'staff_id',
        ];
    }

    if (cpmsDashboardColumnExists($conn, 'daily_work_logs', 'property_id')) {
        return [
            $prefix . 'property_id = ?',
            'i',
            [$propertyId],
            '',
        ];
    }

    if (
        cpmsDashboardColumnExists($conn, 'daily_work_logs', 'staff_id')
        && cpmsDashboardColumnExists($conn, 'staff', 'property_id')
    ) {
        return [
            's.property_id = ?',
            'i',
            [$propertyId],
            ' INNER JOIN staff s ON s.id = ' . $prefix . 'staff_id',
        ];
    }

    return ['1=0', '', [], ''];
}

function cpmsDashboardCountByProperty(
    mysqli $conn,
    string $table,
    int $propertyId
): int {
    if (
        !cpmsDashboardTableExists($conn, $table)
        || !cpmsDashboardColumnExists(
            $conn,
            $table,
            'property_id'
        )
    ) {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM `$table`
         WHERE property_id = ?"
    );

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $propertyId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0);
}

function cpmsDashboardStatusCount(
    mysqli $conn,
    int $propertyId,
    array $statuses
): int {
    $normalized = [];

    foreach ($statuses as $status) {
        $value = strtolower(trim((string) $status));

        if ($value !== '') {
            $normalized[] = $value;
        }
    }

    $normalized = array_values(array_unique($normalized));

    if (!$normalized) {
        return 0;
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($normalized), '?')
    );

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM complaints
         WHERE property_id = ?
           AND LOWER(TRIM(COALESCE(status, '')))
               IN ($placeholders)"
    );

    if (!$stmt) {
        return 0;
    }

    $types = 'i' . str_repeat('s', count($normalized));
    $params = array_merge([$propertyId], $normalized);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0);
}

function cpmsDashboardBadgeClass(
    ?string $value,
    string $type
): string {
    $value = strtolower(trim((string) $value));

    if ($type === 'priority') {
        if (
            in_array(
                $value,
                ['high', 'urgent', 'critical', 'tinggi', 'segera'],
                true
            )
        ) {
            return 'badge-danger';
        }

        if (
            in_array(
                $value,
                ['medium', 'normal', 'sederhana'],
                true
            )
        ) {
            return 'badge-warning';
        }

        return 'badge-neutral';
    }

    if (
        in_array(
            $value,
            ['resolved', 'completed', 'closed', 'selesai'],
            true
        )
    ) {
        return 'badge-success';
    }

    if (
        in_array(
            $value,
            [
                'in progress',
                'in-progress',
                'processing',
                'sedang diproses',
                'dalam tindakan',
            ],
            true
        )
    ) {
        return 'badge-warning';
    }

    if (
        in_array(
            $value,
            ['rejected', 'cancelled', 'canceled', 'ditolak'],
            true
        )
    ) {
        return 'badge-danger';
    }

    return 'badge-neutral';
}

function cpmsDashboardGreeting(): string
{
    $hour = (int) date('G');

    if ($hour < 12) {
        return 'Good morning';
    }

    if ($hour < 18) {
        return 'Good afternoon';
    }

    return 'Good evening';
}

$complaintCount = cpmsDashboardCountByProperty(
    $conn,
    'complaints',
    $currentPropertyId
);

$workOrderCount = cpmsDashboardCountByProperty(
    $conn,
    'work_orders',
    $currentPropertyId
);

$staffCount = cpmsDashboardCountByProperty(
    $conn,
    'staff',
    $currentPropertyId
);

if ($staffCount === 0) {
    $staffCount = cpmsDashboardCountByProperty(
        $conn,
        'admin_staff',
        $currentPropertyId
    );
}

$pendingCount = cpmsDashboardStatusCount(
    $conn,
    $currentPropertyId,
    ['Pending', 'New', 'Open', 'Menunggu', 'Baharu']
);

$inProgressCount = cpmsDashboardStatusCount(
    $conn,
    $currentPropertyId,
    [
        'In Progress',
        'In-Progress',
        'Processing',
        'Sedang Diproses',
        'Dalam Tindakan',
    ]
);

$resolvedCount = cpmsDashboardStatusCount(
    $conn,
    $currentPropertyId,
    ['Resolved', 'Completed', 'Closed', 'Selesai']
);

$resolutionRate = $complaintCount > 0
    ? (int) round(($resolvedCount / $complaintCount) * 100)
    : 0;

$attentionCount = $pendingCount + $inProgressCount;

$canManageResidentRegistrations = cpmsCan(
    'resident.registration.manage',
    $conn
);
$pendingResidentRegistrationCount = 0;
$recentResidentRegistrations = [];

if ($canManageResidentRegistrations) {
    try {
        $registrationCountStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM cpms_resident_registrations
             WHERE property_id=? AND status='Pending'"
        );
        if ($registrationCountStmt) {
            $registrationCountStmt->bind_param(
                'i',
                $currentPropertyId
            );
            if ($registrationCountStmt->execute()) {
                $registrationCountRow = $registrationCountStmt
                    ->get_result()
                    ->fetch_assoc();
                $pendingResidentRegistrationCount = (int) (
                    $registrationCountRow['total'] ?? 0
                );
            }
            $registrationCountStmt->close();
        }

        $registrationListStmt = $conn->prepare(
            "SELECT id,application_reference,full_name,block_name,
                    unit_no,resident_type,submitted_at
             FROM cpms_resident_registrations
             WHERE property_id=? AND status='Pending'
             ORDER BY submitted_at ASC,id ASC
             LIMIT 5"
        );
        if ($registrationListStmt) {
            $registrationListStmt->bind_param(
                'i',
                $currentPropertyId
            );
            if ($registrationListStmt->execute()) {
                $recentResidentRegistrations = $registrationListStmt
                    ->get_result()
                    ->fetch_all(MYSQLI_ASSOC);
            }
            $registrationListStmt->close();
        }
    } catch (Throwable $ignored) {
        $pendingResidentRegistrationCount = 0;
        $recentResidentRegistrations = [];
    }
}

$dailyWorkPendingReviewCount = 0;
$dailyWorkVerifiedTodayCount = 0;
$dailyWorkNewsletterCount = 0;
$dailyWorkMonthlyReportCount = 0;
$recentDailyWork = [];

if (cpmsDashboardTableExists($conn, 'daily_work_logs')) {
    [$dailyWhere, $dailyTypes, $dailyParams, $dailyJoin] =
        cpmsDashboardDailyWorkWhere($conn, $currentPropertyId, 'd');

    $dailyWorkPendingReviewCount = cpmsDashboardScalar(
        $conn,
        "SELECT COUNT(*)
         FROM daily_work_logs d
         {$dailyJoin}
         WHERE {$dailyWhere}
           AND LOWER(TRIM(COALESCE(d.work_status,''))) IN
                ('completed','pending review','pending','in progress')",
        $dailyTypes,
        $dailyParams
    );

    $dailyWorkVerifiedTodayCount = cpmsDashboardScalar(
        $conn,
        "SELECT COUNT(*)
         FROM daily_work_logs d
         {$dailyJoin}
         WHERE {$dailyWhere}
           AND d.work_date = CURDATE()
           AND LOWER(TRIM(COALESCE(d.work_status,''))) = 'verified'",
        $dailyTypes,
        $dailyParams
    );

    if (cpmsDashboardColumnExists($conn, 'daily_work_logs', 'include_in_newsletter')) {
        $dailyWorkNewsletterCount = cpmsDashboardScalar(
            $conn,
            "SELECT COUNT(*)
             FROM daily_work_logs d
             {$dailyJoin}
             WHERE {$dailyWhere}
               AND d.include_in_newsletter = 1
               AND DATE_FORMAT(d.work_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
            $dailyTypes,
            $dailyParams
        );
    }

    if (cpmsDashboardColumnExists($conn, 'daily_work_logs', 'include_in_monthly_report')) {
        $dailyWorkMonthlyReportCount = cpmsDashboardScalar(
            $conn,
            "SELECT COUNT(*)
             FROM daily_work_logs d
             {$dailyJoin}
             WHERE {$dailyWhere}
               AND d.include_in_monthly_report = 1
               AND DATE_FORMAT(d.work_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
            $dailyTypes,
            $dailyParams
        );
    }

    $staffJoinForList = strpos($dailyJoin, 'JOIN staff') !== false
        ? $dailyJoin
        : ' LEFT JOIN staff s ON s.id = d.staff_id';

    $dailyListStmt = $conn->prepare(
        "SELECT
            d.id,
            d.work_reference,
            d.work_date,
            d.work_category,
            d.block_location,
            d.specific_location,
            d.work_status,
            s.full_name
         FROM daily_work_logs d
         {$staffJoinForList}
         WHERE {$dailyWhere}
         ORDER BY d.work_date DESC, d.id DESC
         LIMIT 5"
    );

    if ($dailyListStmt) {
        if ($dailyTypes !== '' && $dailyParams) {
            $dailyListStmt->bind_param($dailyTypes, ...$dailyParams);
        }
        $dailyListStmt->execute();
        $recentDailyWork = $dailyListStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);
        $dailyListStmt->close();
    }
}

$pendingFacilityBookingCount = 0;
if (
    cpmsDashboardTableExists($conn, 'cpms_facility_bookings')
    && cpmsDashboardColumnExists($conn, 'cpms_facility_bookings', 'property_id')
) {
    $facilityStatusColumn = '';

    if (cpmsDashboardColumnExists($conn, 'cpms_facility_bookings', 'status')) {
        $facilityStatusColumn = 'status';
    } elseif (cpmsDashboardColumnExists($conn, 'cpms_facility_bookings', 'request_status')) {
        $facilityStatusColumn = 'request_status';
    }

    if ($facilityStatusColumn !== '') {
        $pendingFacilityBookingCount = cpmsDashboardScalar(
            $conn,
            "SELECT COUNT(*)
             FROM cpms_facility_bookings
             WHERE property_id = ?
               AND LOWER(TRIM(COALESCE(`{$facilityStatusColumn}`,''))) = 'pending'",
            'i',
            [$currentPropertyId]
        );
    }
}

/*
 * Property Health Score uses only existing dashboard data, so this upgrade
 * does not require new database tables or columns.
 */
$resolutionComponent = min(100, max(0, $resolutionRate));
$attentionPenalty = $complaintCount > 0
    ? min(40, (int) round(($attentionCount / $complaintCount) * 40))
    : 0;
$propertyHealthScore = max(
    0,
    min(100, 70 + (int) round($resolutionComponent * 0.30) - $attentionPenalty)
);

if ($propertyHealthScore >= 85) {
    $propertyHealthLabel = 'Excellent';
} elseif ($propertyHealthScore >= 70) {
    $propertyHealthLabel = 'Good';
} elseif ($propertyHealthScore >= 50) {
    $propertyHealthLabel = 'Needs Attention';
} else {
    $propertyHealthLabel = 'Critical';
}

$dashboardAlerts = [];

if (
    $canManageResidentRegistrations
    && $pendingResidentRegistrationCount > 0
) {
    $dashboardAlerts[] = [
        'type' => 'warning',
        'title' => $pendingResidentRegistrationCount
            . ' resident registration(s) pending',
        'text' => 'Verify the unit before activating the Resident Portal account.',
        'url' => 'resident_registrations.php?status=Pending',
    ];
}

if ($pendingCount > 0) {
    $dashboardAlerts[] = [
        'type' => 'warning',
        'title' => $pendingCount . ' complaint(s) pending',
        'text' => 'Review new complaints and assign the next action.',
        'url' => 'complaints.php',
    ];
}

if ($inProgressCount > 0) {
    $dashboardAlerts[] = [
        'type' => 'info',
        'title' => $inProgressCount . ' complaint(s) in progress',
        'text' => 'Check progress and update residents where necessary.',
        'url' => 'complaints.php',
    ];
}

if ($workOrderCount === 0) {
    $dashboardAlerts[] = [
        'type' => 'neutral',
        'title' => 'No work order recorded',
        'text' => 'Create a work order when a complaint requires site action.',
        'url' => 'work_order_create.php',
    ];
}

if (!$dashboardAlerts) {
    $dashboardAlerts[] = [
        'type' => 'success',
        'title' => 'Operations are under control',
        'text' => 'There are no urgent dashboard alerts at this time.',
        'url' => 'dashboard.php',
    ];
}

$recentComplaints = [];

$stmt = $conn->prepare(
    "SELECT
        id,
        complaint_id,
        unit_no,
        block,
        location,
        category,
        subject,
        priority,
        status,
        date_created
     FROM complaints
     WHERE property_id = ?
     ORDER BY date_created DESC, id DESC
     LIMIT 6"
);

if ($stmt) {
    $stmt->bind_param('i', $currentPropertyId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $recentComplaints[] = $row;
    }

    $stmt->close();
}

$trendLabels = [];
$trendValues = [];
$trendMap = [];

for ($index = 5; $index >= 0; $index--) {
    $timestamp = strtotime(
        date('Y-m-01') . " -{$index} months"
    );

    $key = date('Y-m', $timestamp);
    $trendMap[$key] = 0;
    $trendLabels[] = date('M', $timestamp);
}

$trendStmt = $conn->prepare(
    "SELECT
        DATE_FORMAT(date_created, '%Y-%m') AS period_key,
        COUNT(*) AS total
     FROM complaints
     WHERE property_id = ?
       AND date_created >= DATE_SUB(
            DATE_FORMAT(CURDATE(), '%Y-%m-01'),
            INTERVAL 5 MONTH
       )
     GROUP BY DATE_FORMAT(date_created, '%Y-%m')
     ORDER BY period_key"
);

if ($trendStmt) {
    $trendStmt->bind_param('i', $currentPropertyId);
    $trendStmt->execute();

    $trendResult = $trendStmt->get_result();

    while ($row = $trendResult->fetch_assoc()) {
        $key = (string) $row['period_key'];

        if (array_key_exists($key, $trendMap)) {
            $trendMap[$key] = (int) $row['total'];
        }
    }

    $trendStmt->close();
}

$trendValues = array_values($trendMap);

$todayComplaintCount = 0;
$todayStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM complaints
     WHERE property_id = ?
       AND DATE(date_created) = CURDATE()"
);

if ($todayStmt) {
    $todayStmt->bind_param('i', $currentPropertyId);
    $todayStmt->execute();

    $todayRow = $todayStmt->get_result()->fetch_assoc();
    $todayComplaintCount = (int) ($todayRow['total'] ?? 0);

    $todayStmt->close();
}

$currentMonthCount = (int) ($trendValues[count($trendValues) - 1] ?? 0);
$previousMonthCount = (int) ($trendValues[count($trendValues) - 2] ?? 0);

if ($previousMonthCount > 0) {
    $monthChangePercent = (int) round(
        (($currentMonthCount - $previousMonthCount) / $previousMonthCount)
        * 100
    );
} elseif ($currentMonthCount > 0) {
    $monthChangePercent = 100;
} else {
    $monthChangePercent = 0;
}

$workloadPerStaff = $staffCount > 0
    ? round($attentionCount / $staffCount, 1)
    : 0;

$resolvedShare = $complaintCount > 0
    ? (int) round(($resolvedCount / $complaintCount) * 100)
    : 0;

$executiveInsights = [];

if ($monthChangePercent > 20) {
    $executiveInsights[] = [
        'tone' => 'warning',
        'title' => 'Complaint volume increased',
        'text' => $monthChangePercent
            . '% higher than the previous month.',
    ];
} elseif ($monthChangePercent < -20) {
    $executiveInsights[] = [
        'tone' => 'success',
        'title' => 'Complaint volume improved',
        'text' => abs($monthChangePercent)
            . '% lower than the previous month.',
    ];
}

if ($workloadPerStaff > 3) {
    $executiveInsights[] = [
        'tone' => 'warning',
        'title' => 'High workload per staff member',
        'text' => $workloadPerStaff
            . ' open items for each active staff member.',
    ];
}

if ($resolutionRate >= 80) {
    $executiveInsights[] = [
        'tone' => 'success',
        'title' => 'Strong resolution performance',
        'text' => $resolutionRate
            . '% of complaints have been resolved.',
    ];
}

if (!$executiveInsights) {
    $executiveInsights[] = [
        'tone' => 'info',
        'title' => 'Operations remain stable',
        'text' => 'No major performance exception was detected.',
    ];
}

$pageTitle = 'Executive Dashboard';
$activeMenu = 'dashboard';

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/executive-dashboard-v3.css">
<link rel="stylesheet" href="assets/premium-dashboard-compact.css">
<link rel="stylesheet" href="assets/resident-registration-queue.css?v=3607">
<style>
.ops-command-grid{display:grid;grid-template-columns:1fr 1.35fr .85fr;gap:14px;margin:14px 0 18px}.ops-card{border:1px solid #dbe5f2;border-radius:14px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.06);padding:16px}.ops-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:13px}.ops-card-head h2{margin:3px 0 0;font-size:15px;color:#0f172a}.ops-pill{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:26px;padding:0 9px;border-radius:999px;background:var(--property-primary,#2563eb);color:var(--property-on-primary,#fff);font-weight:900;font-size:12px}.ops-action-list,.ops-daily-list,.ops-report-list{display:grid;gap:9px}.ops-action{display:grid;grid-template-columns:10px 1fr auto;gap:10px;align-items:center;padding:10px;border:1px solid #edf2f7;border-radius:10px;text-decoration:none;color:#334155;background:#f8fafc}.ops-action i{width:9px;height:9px;border-radius:999px;background:#64748b}.ops-action.warning i{background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.12)}.ops-action.danger i{background:#dc2626;box-shadow:0 0 0 4px rgba(220,38,38,.10)}.ops-action.success i{background:#16a34a;box-shadow:0 0 0 4px rgba(22,163,74,.10)}.ops-action strong{display:block;font-size:12px;color:#0f172a}.ops-action small{display:block;margin-top:2px;color:#64748b;font-size:11px}.ops-action b{font-size:18px;color:#94a3b8}.ops-daily-row{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:10px;border:1px solid #edf2f7;border-radius:10px}.ops-daily-row strong{display:block;color:#0f172a;font-size:12px}.ops-daily-row small{display:block;margin-top:3px;color:#64748b;font-size:11px}.ops-status{border-radius:999px;padding:5px 8px;background:#e2e8f0;color:#334155;font-weight:800;font-size:10px}.ops-status.Verified{background:#dcfce7;color:#166534}.ops-status.Rejected{background:#fee2e2;color:#991b1b}.ops-report-list a{display:grid;grid-template-columns:32px 1fr;gap:10px;align-items:center;text-decoration:none;color:#0f172a;border:1px solid #edf2f7;border-radius:10px;padding:10px;background:#fff}.ops-report-list span{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;background:#eff6ff;color:#1d4ed8;font-weight:900}.ops-report-list strong{display:block;font-size:12px}.ops-report-list small{display:block;color:#64748b;font-size:11px;margin-top:2px}.ops-empty{padding:12px;border-radius:10px;background:#f8fafc;color:#64748b;font-size:12px}.ops-mini-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px}.ops-mini-metrics div{padding:10px;border-radius:10px;background:#f8fafc;border:1px solid #edf2f7}.ops-mini-metrics span{display:block;font-size:10px;color:#64748b;font-weight:800;text-transform:uppercase}.ops-mini-metrics strong{display:block;margin-top:3px;color:#0f172a;font-size:18px}@media(max-width:1100px){.ops-command-grid{grid-template-columns:1fr 1fr}.ops-command-grid .ops-card:last-child{grid-column:1/-1}}@media(max-width:720px){.ops-command-grid{grid-template-columns:1fr}.ops-mini-metrics{grid-template-columns:1fr}}
</style>

<section class="executive-hero<?php echo !empty($propertyPortalUser['dashboard_banner_path']) ? ' dashboard-brand-banner' : ''; ?>"
    <?php if (!empty($propertyPortalUser['dashboard_banner_path'])): ?>
        style="background-image:url('<?php echo propertyPortalEscape(cpmsBrandingAssetUrl($propertyPortalUser['dashboard_banner_path'])); ?>');"
    <?php endif; ?>>
    <div class="executive-hero-copy">
        <span class="section-label">EXECUTIVE OVERVIEW</span>

        <h1>
            <?php echo propertyPortalEscape(
                cpmsDashboardGreeting()
            ); ?>,
            <?php echo propertyPortalEscape(
                (string) $propertyPortalUser['full_name']
            ); ?>
        </h1>

        <p>
            Monitor complaints, work orders and operational performance
            for
            <strong>
                <?php echo propertyPortalEscape(
                    $currentPropertyName
                ); ?>
            </strong>.
        </p>

        <div class="hero-meta">
            <span>
                <?php echo propertyPortalEscape(
                    date('l, d F Y')
                ); ?>
            </span>

            <span class="hero-status">
                <i></i>
                System operational
            </span>
        </div>
    </div>

    <div class="executive-score executive-score-v2">
        <div>
            <span>Property Health</span>
            <strong><?php echo $propertyHealthScore; ?>%</strong>
            <small><?php echo propertyPortalEscape($propertyHealthLabel); ?></small>
        </div>

        <div class="score-divider"></div>

        <div>
            <span>Resolution Rate</span>
            <strong><?php echo $resolutionRate; ?>%</strong>
            <small>
                <?php echo $resolvedCount; ?> of
                <?php echo $complaintCount; ?> resolved
            </small>
        </div>
    </div>
</section>

<div class="dashboard-view-toolbar" aria-label="Dashboard display controls">
    <span>Dashboard view</span>
    <div class="dashboard-view-switch" role="group" aria-label="Choose dashboard density">
        <button type="button" class="is-active" data-dashboard-density="compact">Compact</button>
        <button type="button" data-dashboard-density="comfortable">Comfortable</button>
    </div>
</div>

<section class="ops-command-grid" aria-label="Property operations command centre">
    <article class="ops-card">
        <div class="ops-card-head">
            <div>
                <span class="section-label">ACTION REQUIRED</span>
                <h2>Priority Queue</h2>
            </div>
            <span class="ops-pill">
                <?php echo $pendingResidentRegistrationCount + $pendingCount + $dailyWorkPendingReviewCount + $pendingFacilityBookingCount; ?>
            </span>
        </div>

        <div class="ops-action-list">
            <?php if ($dailyWorkPendingReviewCount > 0): ?>
                <a class="ops-action warning" href="daily_work_review.php?status=Completed">
                    <i></i>
                    <span><strong>Daily work pending review</strong><small><?php echo $dailyWorkPendingReviewCount; ?> staff submission(s) need verification.</small></span>
                    <b>→</b>
                </a>
            <?php endif; ?>

            <?php if ($pendingResidentRegistrationCount > 0): ?>
                <a class="ops-action warning" href="resident_registrations.php?status=Pending">
                    <i></i>
                    <span><strong>Resident approval pending</strong><small><?php echo $pendingResidentRegistrationCount; ?> account application(s) waiting.</small></span>
                    <b>→</b>
                </a>
            <?php endif; ?>

            <?php if ($pendingCount > 0): ?>
                <a class="ops-action danger" href="complaints.php">
                    <i></i>
                    <span><strong>Complaint needs action</strong><small><?php echo $pendingCount; ?> pending complaint(s) for this property.</small></span>
                    <b>→</b>
                </a>
            <?php endif; ?>

            <?php if ($pendingFacilityBookingCount > 0): ?>
                <a class="ops-action warning" href="facilities.php">
                    <i></i>
                    <span><strong>Facility booking pending</strong><small><?php echo $pendingFacilityBookingCount; ?> booking request(s) waiting.</small></span>
                    <b>→</b>
                </a>
            <?php endif; ?>

            <?php if (($pendingResidentRegistrationCount + $pendingCount + $dailyWorkPendingReviewCount + $pendingFacilityBookingCount) === 0): ?>
                <div class="ops-action success">
                    <i></i>
                    <span><strong>No urgent action</strong><small>Property operations are currently clear.</small></span>
                    <b></b>
                </div>
            <?php endif; ?>
        </div>
    </article>

    <article class="ops-card">
        <div class="ops-card-head">
            <div>
                <span class="section-label">STAFF OPERATIONS</span>
                <h2>Daily Work Review</h2>
            </div>
            <a class="text-link" href="daily_work_review.php">Open Review →</a>
        </div>

        <div class="ops-mini-metrics">
            <div><span>Pending</span><strong><?php echo $dailyWorkPendingReviewCount; ?></strong></div>
            <div><span>Newsletter</span><strong><?php echo $dailyWorkNewsletterCount; ?></strong></div>
            <div><span>Monthly Report</span><strong><?php echo $dailyWorkMonthlyReportCount; ?></strong></div>
        </div>

        <?php if (!$recentDailyWork): ?>
            <div class="ops-empty">Belum ada rekod kerja harian staff untuk property ini.</div>
        <?php else: ?>
            <div class="ops-daily-list">
                <?php foreach ($recentDailyWork as $work): ?>
                    <article class="ops-daily-row">
                        <div>
                            <strong><?php echo propertyPortalEscape((string) ($work['work_category'] ?: 'Daily Work')); ?></strong>
                            <small>
                                <?php echo propertyPortalEscape((string) ($work['full_name'] ?: 'Staff')); ?>
                                ·
                                <?php echo propertyPortalEscape((string) ($work['work_date'] ?: '-')); ?>
                                ·
                                <?php echo propertyPortalEscape(trim((string) (($work['block_location'] ?? '') . ' ' . ($work['specific_location'] ?? '')))); ?>
                            </small>
                        </div>
                        <span class="ops-status <?php echo propertyPortalEscape((string) ($work['work_status'] ?? '')); ?>">
                            <?php echo propertyPortalEscape((string) ($work['work_status'] ?: 'Pending')); ?>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="ops-card">
        <div class="ops-card-head">
            <div>
                <span class="section-label">REPORT STUDIO</span>
                <h2>Management Output</h2>
            </div>
        </div>

        <div class="ops-report-list">
            <a href="management_report.php">
                <span>MR</span>
                <div><strong>Monthly Management Report</strong><small>Generate report with selected daily work.</small></div>
            </a>
            <a href="newsletter.php">
                <span>NL</span>
                <div><strong>Monthly Newsletter</strong><small>Prepare publication for residents.</small></div>
            </a>
            <a href="reports.php">
                <span>CSV</span>
                <div><strong>Detailed Reports</strong><small>Filter and export operational records.</small></div>
            </a>
        </div>
    </article>
</section>

<?php if ($canManageResidentRegistrations): ?>
    <section class="resident-registration-queue panel">
        <div class="resident-registration-queue-head">
            <div>
                <span class="section-label">RESIDENT ACCOUNT APPROVAL</span>
                <h2>Resident Registrations</h2>
                <p>Permohonan akaun yang menunggu semakan untuk property ini.</p>
            </div>
            <a href="resident_registrations.php?status=Pending">
                Pending
                <strong><?php echo $pendingResidentRegistrationCount; ?></strong>
            </a>
        </div>

        <?php if (!$recentResidentRegistrations): ?>
            <div class="resident-registration-queue-empty">
                Tiada permohonan akaun Resident yang menunggu kelulusan.
            </div>
        <?php else: ?>
            <div class="resident-registration-queue-list">
                <?php foreach ($recentResidentRegistrations as $application): ?>
                    <article>
                        <div>
                            <small><?php echo propertyPortalEscape(
                                (string) $application['application_reference']
                            ); ?></small>
                            <strong><?php echo propertyPortalEscape(
                                (string) $application['full_name']
                            ); ?></strong>
                        </div>
                        <span><?php echo propertyPortalEscape(
                            (string) $application['block_name']
                            . ' / ' . (string) $application['unit_no']
                        ); ?></span>
                        <span><?php echo propertyPortalEscape(
                            (string) $application['resident_type']
                        ); ?></span>
                        <time><?php echo propertyPortalEscape(
                            date(
                                'd/m/Y H:i',
                                strtotime((string) $application['submitted_at'])
                            )
                        ); ?></time>
                        <a href="resident_registrations.php?status=Pending">
                            Semak →
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="executive-command-strip" aria-label="Executive performance summary">
    <article>
        <span>Current Month</span>
        <strong><?php echo $currentMonthCount; ?></strong>
        <small>complaints recorded</small>
    </article>

    <article>
        <span>Month-on-Month</span>
        <strong class="<?php echo $monthChangePercent > 0
            ? 'metric-negative'
            : ($monthChangePercent < 0 ? 'metric-positive' : ''); ?>">
            <?php echo $monthChangePercent > 0 ? '+' : ''; ?><?php echo $monthChangePercent; ?>%
        </strong>
        <small>versus previous month</small>
    </article>

    <article>
        <span>Open Load / Staff</span>
        <strong><?php echo number_format($workloadPerStaff, 1); ?></strong>
        <small>active workload ratio</small>
    </article>

    <article>
        <span>Resolved Share</span>
        <strong><?php echo $resolvedShare; ?>%</strong>
        <small>of total complaints</small>
    </article>
</section>

<section class="premium-kpi-grid">
    <article class="premium-kpi-card">
        <div class="premium-kpi-icon icon-complaint">!</div>

        <div class="premium-kpi-content">
            <span>Total Complaints</span>
            <strong><?php echo $complaintCount; ?></strong>
            <small>
                <?php echo $todayComplaintCount; ?> received today
            </small>
        </div>

        <a href="complaints.php" aria-label="View complaints">→</a>
    </article>

    <article class="premium-kpi-card">
        <div class="premium-kpi-icon icon-pending">◷</div>

        <div class="premium-kpi-content">
            <span>Needs Attention</span>
            <strong><?php echo $attentionCount; ?></strong>
            <small>
                <?php echo $pendingCount; ?> pending ·
                <?php echo $inProgressCount; ?> in progress
            </small>
        </div>

        <a href="complaints.php" aria-label="View pending complaints">→</a>
    </article>

    <article class="premium-kpi-card">
        <div class="premium-kpi-icon icon-work">⚒</div>

        <div class="premium-kpi-content">
            <span>Work Orders</span>
            <strong><?php echo $workOrderCount; ?></strong>
            <small>Property operational tasks</small>
        </div>

        <a href="work_orders.php" aria-label="View work orders">→</a>
    </article>

    <article class="premium-kpi-card">
        <div class="premium-kpi-icon icon-staff">◎</div>

        <div class="premium-kpi-content">
            <span>Staff Members</span>
            <strong><?php echo $staffCount; ?></strong>
            <small>Assigned to this property</small>
        </div>

        <span class="kpi-static">Active</span>
    </article>
</section>

<section class="executive-grid">
    <article class="panel chart-panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">PERFORMANCE</span>
                <h2>Complaint Trend</h2>
                <p>Complaints received during the last six months.</p>
            </div>

            <span class="chart-range">6 months</span>
        </div>

        <div class="chart-stage">
            <canvas
                id="complaintTrendChart"
                width="900"
                height="310"
                aria-label="Complaint trend chart"
            ></canvas>
        </div>
    </article>

    <article class="panel status-panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">STATUS</span>
                <h2>Complaint Distribution</h2>
                <p>Current complaint workload by status.</p>
            </div>
        </div>

        <div class="donut-layout">
            <div
                class="status-donut"
                style="
                    --resolved-share: <?php echo $complaintCount > 0
                        ? ($resolvedCount / $complaintCount) * 100
                        : 0; ?>%;
                    --progress-share: <?php echo $complaintCount > 0
                        ? (($resolvedCount + $inProgressCount)
                            / $complaintCount) * 100
                        : 0; ?>%;
                "
            >
                <div>
                    <strong><?php echo $complaintCount; ?></strong>
                    <span>Total</span>
                </div>
            </div>

            <div class="status-legend">
                <div>
                    <i class="legend-resolved"></i>
                    <span>Resolved</span>
                    <strong><?php echo $resolvedCount; ?></strong>
                </div>

                <div>
                    <i class="legend-progress"></i>
                    <span>In Progress</span>
                    <strong><?php echo $inProgressCount; ?></strong>
                </div>

                <div>
                    <i class="legend-pending"></i>
                    <span>Pending</span>
                    <strong><?php echo $pendingCount; ?></strong>
                </div>
            </div>
        </div>
    </article>
</section>

<section class="executive-insight-panel panel">
    <div class="panel-heading">
        <div>
            <span class="section-label">EXECUTIVE INTELLIGENCE</span>
            <h2>Management Insights</h2>
            <p>Automatically generated from current property performance.</p>
        </div>
        <span class="insight-updated">Updated <?php echo date('H:i'); ?></span>
    </div>

    <div class="executive-insight-grid">
        <?php foreach ($executiveInsights as $insight): ?>
            <article class="executive-insight executive-insight-<?php
                echo propertyPortalEscape($insight['tone']);
            ?>">
                <span class="insight-indicator"></span>
                <div>
                    <strong><?php echo propertyPortalEscape(
                        $insight['title']
                    ); ?></strong>
                    <p><?php echo propertyPortalEscape(
                        $insight['text']
                    ); ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="executive-lower-grid">
    <article class="panel recent-panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">RECENT ACTIVITY</span>
                <h2>Latest Complaints</h2>
            </div>

            <a class="text-link" href="complaints.php">
                View all →
            </a>
        </div>

        <?php if (!$recentComplaints): ?>
            <div class="empty-state">
                <strong>No complaints recorded</strong>
                <span>
                    New complaints for this property will appear here.
                </span>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table premium-table">
                    <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Complaint</th>
                        <th>Location</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($recentComplaints as $row): ?>
                        <tr>
                            <td>
                                <a
                                    class="record-link"
                                    href="complaint_view.php?ref=<?php
                                    echo rawurlencode(
                                        (string) $row['complaint_id']
                                    );
                                    ?>"
                                >
                                    <?php echo propertyPortalEscape(
                                        (string) $row['complaint_id']
                                    ); ?>
                                </a>
                            </td>

                            <td>
                                <strong>
                                    <?php echo propertyPortalEscape(
                                        (string) (
                                            $row['subject']
                                            ?: 'Untitled complaint'
                                        )
                                    ); ?>
                                </strong>

                                <small>
                                    <?php echo propertyPortalEscape(
                                        (string) (
                                            $row['category']
                                            ?: 'Not specified'
                                        )
                                    ); ?>
                                </small>
                            </td>

                            <td>
                                <?php
                                $locationParts = array_filter([
                                    $row['block']
                                        ? 'Block ' . $row['block']
                                        : '',
                                    $row['unit_no']
                                        ? 'Unit ' . $row['unit_no']
                                        : '',
                                    $row['location'] ?? '',
                                ]);

                                echo propertyPortalEscape(
                                    $locationParts
                                        ? implode(', ', $locationParts)
                                        : '-'
                                );
                                ?>
                            </td>

                            <td>
                                <span class="badge <?php
                                    echo cpmsDashboardBadgeClass(
                                        $row['priority'] ?? '',
                                        'priority'
                                    );
                                ?>">
                                    <?php echo propertyPortalEscape(
                                        (string) (
                                            $row['priority']
                                            ?: 'Normal'
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge <?php
                                    echo cpmsDashboardBadgeClass(
                                        $row['status'] ?? '',
                                        'status'
                                    );
                                ?>">
                                    <?php echo propertyPortalEscape(
                                        (string) (
                                            $row['status']
                                            ?: 'Pending'
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <?php
                                $timestamp = strtotime(
                                    (string) $row['date_created']
                                );

                                echo propertyPortalEscape(
                                    $timestamp
                                        ? date('d M Y', $timestamp)
                                        : '-'
                                );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <aside class="executive-side-column">
        <article class="panel quick-action-panel">
            <div class="panel-heading">
                <div>
                    <span class="section-label">SHORTCUTS</span>
                    <h2>Quick Actions</h2>
                </div>
            </div>

            <div class="quick-action-grid">
                <a href="complaints.php">
                    <span>!</span>
                    <strong>Manage Complaints</strong>
                    <small>Review and update records</small>
                </a>

                <a href="work_orders.php">
                    <span>⚒</span>
                    <strong>Manage Work Orders</strong>
                    <small>Track operational tasks</small>
                </a>

                <?php if (cpmsCan('settings.manage', $conn)): ?>
                    <a href="users.php">
                        <span>◎</span>
                        <strong>Property Users</strong>
                        <small>Manage portal accounts</small>
                    </a>

                    <a href="module_manager.php">
                        <span>▦</span>
                        <strong>Module Manager</strong>
                        <small>Configure property modules</small>
                    </a>
                <?php endif; ?>
            </div>
        </article>

        <article class="panel notification-panel">
            <div class="panel-heading">
                <div>
                    <span class="section-label">ATTENTION CENTRE</span>
                    <h2>Operational Alerts</h2>
                </div>
            </div>

            <div class="dashboard-alert-list">
                <?php foreach ($dashboardAlerts as $alert): ?>
                    <a
                        class="dashboard-alert dashboard-alert-<?php
                        echo propertyPortalEscape($alert['type']);
                        ?>"
                        href="<?php echo propertyPortalEscape($alert['url']); ?>"
                    >
                        <span class="dashboard-alert-dot"></span>
                        <span>
                            <strong><?php echo propertyPortalEscape(
                                $alert['title']
                            ); ?></strong>
                            <small><?php echo propertyPortalEscape(
                                $alert['text']
                            ); ?></small>
                        </span>
                        <b>→</b>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel property-card">
            <div class="property-card-top">
                <div>
                    <span class="section-label">PROPERTY</span>
                    <h2><?php echo propertyPortalEscape(
                        $currentPropertyName
                    ); ?></h2>
                </div>

                <span class="property-code">
                    <?php echo propertyPortalEscape(
                        $currentPropertyCode
                    ); ?>
                </span>
            </div>

            <dl>
                <div>
                    <dt>Property ID</dt>
                    <dd><?php echo $currentPropertyId; ?></dd>
                </div>

                <div>
                    <dt>Account Role</dt>
                    <dd><?php echo propertyPortalEscape(
                        cpmsPropertyRoleLabel()
                    ); ?></dd>
                </div>

                <div>
                    <dt>Open Workload</dt>
                    <dd><?php echo $attentionCount; ?></dd>
                </div>
            </dl>
        </article>
    </aside>
</section>

<script>
window.cpmsDashboardTrend = {
    labels: <?php echo json_encode(
        $trendLabels,
        JSON_UNESCAPED_SLASHES
    ); ?>,
    values: <?php echo json_encode(
        $trendValues,
        JSON_UNESCAPED_SLASHES
    ); ?>
};
</script>
<script src="assets/executive-dashboard-v3.js"></script>
<script src="assets/premium-dashboard-compact.js"></script>
<script src="assets/executive-dashboard.js"></script>

<?php
require __DIR__ . '/includes/layout_footer.php';
