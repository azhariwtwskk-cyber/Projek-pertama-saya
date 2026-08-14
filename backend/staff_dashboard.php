<?php

declare(strict_types=1);

session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

require_once "db.php";
require_once __DIR__
    . "/cpms/includes/staff_session_compat.php";
require_once "staff_pwa_bootstrap.php";
require_once __DIR__ . "/cpms/includes/permission_engine.php";
require_once __DIR__ . "/cpms/includes/property_modules.php";
require_once __DIR__ . "/cpms/includes/notification_service.php";
$staffPwaBranding = cpmsStaffPwaBranding($conn);

if (
    !isset($_SESSION["staff_id"])
    || !cpmsStaffSessionRepair($conn)
) {
    header("Location: staff_logout.php");
    exit();
}

$timeoutSeconds = 1800;

if (
    isset($_SESSION["staff_last_activity"]) &&
    time() - (int) $_SESSION["staff_last_activity"] >
    $timeoutSeconds
) {
    cpmsStaffSessionDestroy();

    header("Location: cpms/login.php?expired=1");
    exit();
}

$_SESSION["staff_last_activity"] = time();

cpmsRequire("dashboard.view", $conn);

$staffCanViewWorkOrders = cpmsCan(
    "work_orders.view",
    $conn
);
$staffCanUpdateWorkOrders = cpmsCan(
    "work_orders.update",
    $conn
);
$staffCanViewDailyWork = cpmsCan(
    "daily_work.view",
    $conn
);
$staffCanCreateDailyWork = cpmsCan(
    "daily_work.create",
    $conn
);
$staffCanViewMaintenance = cpmsCan(
    "maintenance.view",
    $conn
);
$staffCanViewNotifications = cpmsCan(
    "notifications.view",
    $conn
);
$staffCanViewMobileInbox = cpmsCan(
    "mobile_inbox.view",
    $conn
);
$staffCanCreateEvidence = cpmsCan(
    "mobile_evidence.create",
    $conn
);
$staffCanClockAttendance = cpmsCan(
    "attendance.clock",
    $conn
);
$staffCanRectifyInspection = cpmsCan(
    "inspection.action.rectify",
    $conn
);

$staffDashboardPropertyId = (int) (
    $_SESSION["cpms_property_id"]
    ?? $_SESSION["staff_property_id"]
    ?? 0
);
$staffDashboardModules = $staffDashboardPropertyId > 0
    ? cpmsLoadPropertyModules($conn, $staffDashboardPropertyId)
    : cpmsModuleDefaults();

$showStaffWorkOrders = $staffCanViewWorkOrders
    && cpmsModuleEnabled("staff_work_orders", $staffDashboardModules);
$showStaffDailyWork = (
    $staffCanViewDailyWork || $staffCanCreateDailyWork
) && cpmsModuleEnabled("staff_daily_work", $staffDashboardModules);
$showStaffInspectionActions = $staffCanRectifyInspection
    && cpmsModuleEnabled("staff_inspection_actions", $staffDashboardModules);
$showStaffNotifications = $staffCanViewNotifications
    && cpmsModuleEnabled("staff_notifications", $staffDashboardModules);
$showStaffTaskInbox = $staffCanViewMobileInbox
    && cpmsModuleEnabled("staff_task_inbox", $staffDashboardModules);
$showStaffAttendance = $staffCanClockAttendance
    && cpmsModuleEnabled("staff_attendance", $staffDashboardModules);
$showStaffMaintenance = $staffCanViewMaintenance
    && cpmsModuleEnabled("staff_maintenance", $staffDashboardModules);

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

function tableExists(
    mysqli $conn,
    string $tableName
): bool {
    $stmt = $conn->prepare(
        "
        SELECT COUNT(*) AS total
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
        AND table_name = ?
        "
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "s",
        $tableName
    );

    $stmt->execute();

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function columnExists(
    mysqli $conn,
    string $tableName,
    string $columnName
): bool {
    $stmt = $conn->prepare(
        "
        SELECT COUNT(*) AS total
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
        AND table_name = ?
        AND column_name = ?
        "
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $tableName, $columnName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function workOrderBadgeClass(string $status): string
{
    return match ($status) {
        "Open" => "wo-status-open",
        "Assigned" => "wo-status-assigned",
        "In Progress" => "wo-status-progress",
        "Pending Material",
        "Pending Contractor" => "wo-status-pending",
        "Completed" => "wo-status-completed",
        "Verified" => "wo-status-verified",
        "Cancelled" => "wo-status-cancelled",
        default => "wo-status-open"
    };
}

function dailyWorkBadgeClass(string $status): string
{
    switch ($status) {
        case "Completed":
            return "pms-badge-completed";
        case "Verified":
            return "pms-badge-verified";
        case "Rejected":
            return "pms-badge-rejected";
        case "In Progress":
            return "pms-badge-progress";
        default:
            return "pms-badge-pending";
    }
}

$staffId = (int) $_SESSION["staff_id"];
$staffMaintenanceCount = 0;
$staffSystemUserIdForPm = (int) ($_SESSION["cpms_user_id"] ?? 0);
$staffPropertyIdForPm = (int) (
    $_SESSION["cpms_property_id"]
    ?? $_SESSION["staff_property_id"]
    ?? 0
);
if (
    $showStaffMaintenance
    && $staffSystemUserIdForPm > 0
    && $staffPropertyIdForPm > 0
    && tableExists($conn, "cpms_pm_schedules")
) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM cpms_pm_schedules
         WHERE property_id = ?
           AND assigned_system_user_id = ?
           AND status = 'active'"
    );
    if ($stmt) {
        $stmt->bind_param(
            "ii",
            $staffPropertyIdForPm,
            $staffSystemUserIdForPm
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $staffMaintenanceCount = (int) ($row["total"] ?? 0);
        $stmt->close();
    }
}

if (
    $showStaffNotifications
    && $staffPropertyIdForPm > 0
    && tableExists($conn, "cpms_pm_schedules")
    && tableExists($conn, "cpms_user_notifications")
) {
    cpmsNotificationSyncPreventiveMaintenance(
        $conn,
        $staffPropertyIdForPm
    );
}
$staffSystemUserId = (int) ($_SESSION["cpms_user_id"] ?? 0);
$staffPropertyId = (int) (
    $_SESSION["cpms_property_id"]
    ?? $_SESSION["staff_property_id"]
    ?? 0
);

$correctiveActionStatistics = [
    "total" => 0,
    "open" => 0,
    "progress" => 0,
    "rectified" => 0
];

if (
    $showStaffInspectionActions &&
    $staffSystemUserId > 0 &&
    $staffPropertyId > 0 &&
    tableExists($conn, "inspection_corrective_actions")
) {
    $stmt = $conn->prepare(
        "
        SELECT
            COUNT(*) AS total,
            SUM(status = 'Open') AS open_count,
            SUM(status = 'In Progress') AS progress_count,
            SUM(status = 'Rectified') AS rectified_count
        FROM inspection_corrective_actions
        WHERE property_id = ?
          AND assigned_system_user_id = ?
          AND status IN ('Open', 'In Progress', 'Rectified')
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ii",
            $staffPropertyId,
            $staffSystemUserId
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $correctiveActionStatistics["total"] =
            (int) ($row["total"] ?? 0);
        $correctiveActionStatistics["open"] =
            (int) ($row["open_count"] ?? 0);
        $correctiveActionStatistics["progress"] =
            (int) ($row["progress_count"] ?? 0);
        $correctiveActionStatistics["rectified"] =
            (int) ($row["rectified_count"] ?? 0);
    }
}

$dailyStatistics = [
    "today" => 0,
    "progress" => 0,
    "completed" => 0,
    "verified" => 0
];

$dailyWorkPropertyFilter = "";
$dailyWorkPropertyTypes = "i";
$dailyWorkPropertyValues = [$staffId];

if (
    tableExists($conn, "daily_work_logs")
    && columnExists($conn, "daily_work_logs", "property_id")
    && $staffPropertyId > 0
) {
    $dailyWorkPropertyFilter = " AND (property_id = ? OR property_id IS NULL OR property_id = 0)";
    $dailyWorkPropertyTypes .= "i";
    $dailyWorkPropertyValues[] = $staffPropertyId;
}

$recentDailyWorks = [];

if (tableExists($conn, "daily_work_logs")) {
    $stmt = $conn->prepare(
        "
        SELECT
            SUM(work_date = CURDATE()) AS today_count,
            SUM(work_status = 'In Progress')
                AS progress_count,
            SUM(work_status = 'Completed')
                AS completed_count,
            SUM(work_status = 'Verified')
                AS verified_count
        FROM daily_work_logs
        WHERE staff_id = ?
        {$dailyWorkPropertyFilter}
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            $dailyWorkPropertyTypes,
            ...$dailyWorkPropertyValues
        );

        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $dailyStatistics["today"] =
            (int) ($row["today_count"] ?? 0);

        $dailyStatistics["progress"] =
            (int) ($row["progress_count"] ?? 0);

        $dailyStatistics["completed"] =
            (int) ($row["completed_count"] ?? 0);

        $dailyStatistics["verified"] =
            (int) ($row["verified_count"] ?? 0);

        $stmt->close();
    }

    $recentDailyStmt = $conn->prepare(
        "
        SELECT
            id,
            work_reference,
            work_date,
            work_category,
            block_location,
            specific_location,
            work_status
        FROM daily_work_logs
        WHERE staff_id = ?
        {$dailyWorkPropertyFilter}
        ORDER BY work_date DESC, id DESC
        LIMIT 5
        "
    );

    if ($recentDailyStmt) {
        $recentDailyStmt->bind_param(
            $dailyWorkPropertyTypes,
            ...$dailyWorkPropertyValues
        );
        $recentDailyStmt->execute();
        $recentDailyWorks = $recentDailyStmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);
        $recentDailyStmt->close();
    }
}

$workOrderStatistics = [
    "total" => 0,
    "assigned" => 0,
    "progress" => 0,
    "pending" => 0
];

$recentWorkOrders = [];
$hasWorkOrders = tableExists(
    $conn,
    "work_orders"
);

if ($hasWorkOrders) {
    $workOrderPropertyFilter = (
        columnExists($conn, "work_orders", "property_id")
        && $staffPropertyId > 0
    ) ? " AND property_id = ?" : "";

    $stmt = $conn->prepare(
        "
        SELECT
            COUNT(*) AS total,
            SUM(status = 'Assigned')
                AS assigned_count,
            SUM(status = 'In Progress')
                AS progress_count,
            SUM(
                status IN (
                    'Pending Material',
                    'Pending Contractor'
                )
            ) AS pending_count
        FROM work_orders
        WHERE assigned_staff_id = ?
        {$workOrderPropertyFilter}
        AND status NOT IN (
            'Verified',
            'Cancelled'
        )
        "
    );

    if ($stmt) {
        if ($workOrderPropertyFilter !== "") {
            $stmt->bind_param(
                "ii",
                $staffId,
                $staffPropertyId
            );
        } else {
            $stmt->bind_param(
                "i",
                $staffId
            );
        }

        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $workOrderStatistics["total"] =
            (int) ($row["total"] ?? 0);

        $workOrderStatistics["assigned"] =
            (int) ($row["assigned_count"] ?? 0);

        $workOrderStatistics["progress"] =
            (int) ($row["progress_count"] ?? 0);

        $workOrderStatistics["pending"] =
            (int) ($row["pending_count"] ?? 0);

        $stmt->close();
    }

    $stmt = $conn->prepare(
        "
        SELECT
            id,
            work_order_reference,
            title,
            block_location,
            specific_location,
            priority,
            status,
            due_date
        FROM work_orders
        WHERE assigned_staff_id = ?
        {$workOrderPropertyFilter}
        AND status NOT IN (
            'Verified',
            'Cancelled'
        )
        ORDER BY
            FIELD(
                status,
                'Assigned',
                'In Progress',
                'Pending Material',
                'Pending Contractor',
                'Completed'
            ),
            due_date ASC,
            created_at DESC
        LIMIT 6
        "
    );

    if ($stmt) {
        if ($workOrderPropertyFilter !== "") {
            $stmt->bind_param(
                "ii",
                $staffId,
                $staffPropertyId
            );
        } else {
            $stmt->bind_param(
                "i",
                $staffId
            );
        }

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $recentWorkOrders[] = $row;
        }

        $stmt->close();
    }
}

$conn->close();

?>

<!DOCTYPE html>
<html lang="ms">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Staff Dashboard | CPMS
    </title>

    <link
        rel="stylesheet"
        href="css/pms.css?v=5"
    >

    <link
        rel="stylesheet"
        href="css/pms_work_orders.css?v=2"
    >

    <style>
        :root {
            --brown: <?php echo e($staffPwaBranding['primary_color']); ?>;
            --gold: <?php echo e($staffPwaBranding['secondary_color']); ?>;
        }
    </style>

    <style>
        .staff-dashboard-link {
            display: block;
            color: inherit;
            text-decoration: none;
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .staff-dashboard-link:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 30px rgba(47, 28, 20, 0.13);
        }

        .staff-work-order-alert {
            margin-bottom: 22px;
            padding: 18px 20px;
            border: 1px solid #d8c57f;
            border-radius: 12px;
            background: #fff8df;
            color: #604e15;
        }

        .staff-work-order-alert strong {
            display: block;
            margin-bottom: 6px;
            font-size: 16px;
        }

        .staff-work-order-alert p {
            margin: 0 0 13px;
            line-height: 1.6;
        }

        .staff-dashboard-section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin: 28px 0 14px;
        }

        .staff-dashboard-section-title h2 {
            margin: 0;
            color: #4a2b20;
            font-size: 20px;
        }

        @media screen and (max-width: 600px) {
            .staff-dashboard-section-title {
                align-items: stretch;
                flex-direction: column;
            }
        }

        .staff-mobile-nav {
            display: none;
        }

        @media screen and (max-width: 900px) {
            .staff-mobile-nav {
                position: sticky;
                top: 0;
                z-index: 9999;
                display: block;
                padding: 10px 14px;
                background: var(--cpms-primary, #4a2b20);
                color: #fff;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.18);
            }

            .staff-mobile-nav-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }

            .staff-mobile-nav-title {
                min-width: 0;
            }

            .staff-mobile-nav-title strong,
            .staff-mobile-nav-title span {
                display: block;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .staff-mobile-nav-title strong {
                font-size: 15px;
            }

            .staff-mobile-nav-title span {
                margin-top: 2px;
                color: rgba(255, 255, 255, 0.75);
                font-size: 12px;
            }

            .staff-mobile-menu {
                position: relative;
                flex: 0 0 auto;
            }

            .staff-mobile-menu summary {
                display: block;
                min-width: 44px;
                padding: 9px 12px;
                border: 1px solid rgba(255, 255, 255, 0.35);
                border-radius: 9px;
                background: rgba(255, 255, 255, 0.12);
                color: #fff;
                cursor: pointer;
                font-size: 20px;
                font-weight: 700;
                line-height: 1;
                list-style: none;
                text-align: center;
            }

            .staff-mobile-menu summary::-webkit-details-marker {
                display: none;
            }

            .staff-mobile-menu-links {
                position: absolute;
                top: calc(100% + 10px);
                right: 0;
                width: min(82vw, 310px);
                max-height: 72vh;
                overflow-y: auto;
                padding: 8px;
                border: 1px solid #dce3ec;
                border-radius: 12px;
                background: #fff;
                box-shadow: 0 18px 45px rgba(0, 0, 0, 0.22);
            }

            .staff-mobile-menu-links a {
                display: block;
                margin: 0;
                padding: 12px 13px;
                border-radius: 8px;
                color: #18253a;
                font-size: 14px;
                font-weight: 700;
                text-decoration: none;
            }

            .staff-mobile-menu-links a:hover,
            .staff-mobile-menu-links a:focus {
                background: #eef3f8;
            }

            .staff-mobile-menu-links .staff-mobile-logout {
                margin-top: 7px;
                border-top: 1px solid #e0e6ee;
                border-radius: 0 0 8px 8px;
                color: #b42318;
            }

            .pms-main {
                min-width: 0;
            }
        }
    </style>

<?php echo cpmsStaffPwaHead($staffPwaBranding); ?>
<?php echo cpmsStaffPwaStyle($staffPwaBranding); ?>
	<link rel="stylesheet"
	      href="cpms/assets/cpms-responsive-global.css?v=337">
	<link rel="stylesheet" href="css/workforce_portal_shell.css?v=393">
	    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head>

<body class="pms-body" data-cpms-pwa="staff">

<header class="staff-mobile-nav">
    <div class="staff-mobile-nav-row">
        <div class="staff-mobile-nav-title">
            <strong>
                <?php echo e((string) $staffPwaBranding['property_name']); ?>
            </strong>
            <span>
                <?php echo e((string) $_SESSION['staff_name']); ?>
            </span>
        </div>

        <details class="staff-mobile-menu">
            <summary aria-label="Buka menu">&#9776;</summary>

            <nav class="staff-mobile-menu-links">
                <a href="staff_dashboard.php">Dashboard</a>

                <?php if ($showStaffWorkOrders): ?>
                    <a href="staff_work_orders.php">My Work Orders</a>
                <?php endif; ?>

                <?php if ($showStaffDailyWork): ?>
                    <a href="staff_work_form.php">Add Daily Work</a>
                <?php endif; ?>

                <?php if ($showStaffDailyWork): ?>
                    <a href="staff_work_history.php">My Work History</a>
                <?php endif; ?>

                <?php if ($showStaffInspectionActions): ?>
                    <a href="staff_corrective_actions.php">
                        Corrective Actions
                    </a>
                <?php endif; ?>

                <?php if ($showStaffNotifications): ?>
                    <a href="staff_notifications.php">Notifications</a>
                <?php endif; ?>

                <?php if ($showStaffTaskInbox): ?>
                    <a href="mobile_task_inbox.php">Task Inbox</a>
                <?php endif; ?>

                <?php if ($showStaffAttendance): ?>
                    <a href="/cpms/mobile_attendance.php">GPS Attendance</a>
                <?php endif; ?>

                <?php if ($showStaffMaintenance): ?>
                    <a href="staff_maintenance.php">
                        Preventive Maintenance
                    </a>
                <?php endif; ?>

                <a
                    class="staff-mobile-logout"
                    href="staff_logout.php"
                >
                    Logout Staff
                </a>
            </nav>
        </details>
    </div>
</header>

<div class="pms-shell">

    <aside class="pms-sidebar">

        <div class="pms-brand">

	            <img
	                src="<?php echo e((string) $staffPwaBranding['logo_path']); ?>"
	                alt="<?php echo e((string) $staffPwaBranding['property_name']); ?>"
	            >

            <div>
                <strong>
	                    <?php echo e((string) $staffPwaBranding['property_name']); ?>
                </strong>

                <span>
                    Staff Portal
                </span>
            </div>

        </div>

        <nav class="pms-nav">

            <a
                href="staff_dashboard.php"
                class="active"
            >
                Dashboard
            </a>

            <?php if ($showStaffWorkOrders): ?>
            <a href="staff_work_orders.php">
                My Work Orders
            </a>
            <?php endif; ?>

            <?php if ($showStaffDailyWork): ?>
            <a href="staff_work_form.php">
                Add Daily Work
            </a>
            <?php endif; ?>

            <?php if ($showStaffDailyWork): ?>
            <a href="staff_work_history.php">
                My Work History
            </a>
            <?php endif; ?>

            <?php if ($showStaffInspectionActions): ?>
            <a href="staff_corrective_actions.php">
                Corrective Actions
            </a>
            <?php endif; ?>

            <?php if ($showStaffNotifications): ?>
            <a href="staff_notifications.php">
                Notifications
            </a>
            <?php endif; ?>

            <?php if ($showStaffTaskInbox): ?>
            <a href="mobile_task_inbox.php">
                Task Inbox
            </a>
            <?php endif; ?>

            <?php if ($showStaffAttendance): ?>
            <a href="/cpms/mobile_attendance.php">
                GPS Attendance
            </a>
            <?php endif; ?>

            <?php if ($showStaffMaintenance): ?>
            <a href="staff_maintenance.php">
                Preventive Maintenance
            </a>
            <?php endif; ?>

        </nav>

        <div class="pms-sidebar-footer">

            <a href="staff_logout.php">
                Logout Staff
            </a>

        </div>

    </aside>

    <main class="pms-main">

        <header class="pms-topbar">

            <div>

                <h1>
                    Selamat Datang,
                    <?php
                    echo e(
                        (string) $_SESSION["staff_name"]
                    );
                    ?>
                </h1>

                <p>
                    Semak tugasan dan rekod kerja harian anda.
                </p>

            </div>

            <div class="pms-user-chip">

                <?php
                echo e(
                    (string) $_SESSION["staff_role"]
                );
                ?>

            </div>

        </header>

        <?php if (
            $showStaffWorkOrders &&
            $hasWorkOrders &&
            $workOrderStatistics["total"] > 0
        ): ?>

            <section class="staff-work-order-alert">

                <strong>
                    Anda mempunyai
                    <?php
                    echo $workOrderStatistics["total"];
                    ?>
                    Work Order aktif.
                </strong>

                <p>
                    Buka My Work Orders untuk melihat tugasan
                    yang telah diberikan oleh pihak pengurusan.
                </p>

                <a
                    href="staff_work_orders.php"
                    class="pms-button"
                >
                    Buka My Work Orders
                </a>

            </section>

        <?php endif; ?>

        <?php if (
            $showStaffMaintenance &&
            $staffMaintenanceCount > 0
        ): ?>
            <section class="staff-work-order-alert">
                <strong>
                    Anda mempunyai <?php echo $staffMaintenanceCount; ?>
                    Preventive Maintenance aktif.
                </strong>
                <p>
                    Semak due date, arahan kerja dan hantar bukti
                    maintenance kepada Property Admin.
                </p>
                <a href="staff_maintenance.php" class="pms-button">
                    Buka Preventive Maintenance
                </a>
            </section>
        <?php endif; ?>

        <?php if (
            $showStaffInspectionActions &&
            $correctiveActionStatistics["total"] > 0
        ): ?>

            <section class="staff-work-order-alert">
                <strong>
                    Anda mempunyai
                    <?php echo $correctiveActionStatistics["total"]; ?>
                    Corrective Action aktif.
                </strong>
                <p>
                    Semak arahan pembaikan, kemas kini status dan
                    muat naik bukti gambar.
                </p>
                <a
                    href="staff_corrective_actions.php"
                    class="pms-button"
                >
                    Buka Corrective Actions
                </a>
            </section>

        <?php endif; ?>

        <?php if ($showStaffInspectionActions): ?>
        <div class="staff-dashboard-section-title">
            <h2>Ringkasan Corrective Action</h2>
            <a
                href="staff_corrective_actions.php"
                class="pms-button-secondary"
            >
                Lihat Semua
            </a>
        </div>

        <section class="pms-grid pms-grid-4">
            <a href="staff_corrective_actions.php" class="pms-card pms-stat staff-dashboard-link">
                <strong><?php echo $correctiveActionStatistics["total"]; ?></strong>
                <span>Tugasan Aktif</span>
            </a>
            <a href="staff_corrective_actions.php" class="pms-card pms-stat staff-dashboard-link">
                <strong><?php echo $correctiveActionStatistics["open"]; ?></strong>
                <span>Tugasan Baharu</span>
            </a>
            <a href="staff_corrective_actions.php" class="pms-card pms-stat staff-dashboard-link">
                <strong><?php echo $correctiveActionStatistics["progress"]; ?></strong>
                <span>Sedang Dilaksanakan</span>
            </a>
            <a href="staff_corrective_actions.php" class="pms-card pms-stat staff-dashboard-link">
                <strong><?php echo $correctiveActionStatistics["rectified"]; ?></strong>
                <span>Menunggu Pengesahan</span>
            </a>
        </section>
        <?php endif; ?>
        <?php if ($showStaffWorkOrders): ?>

        <div class="staff-dashboard-section-title">

            <h2>
                Ringkasan Work Order
            </h2>

            <a
                href="staff_work_orders.php"
                class="pms-button-secondary"
            >
                Lihat Semua
            </a>

        </div>

        <section class="pms-grid pms-grid-4">

            <a
                href="staff_work_orders.php"
                class="pms-card pms-stat staff-dashboard-link"
            >
                <strong>
                    <?php
                    echo $workOrderStatistics["total"];
                    ?>
                </strong>

                <span>
                    Work Order Aktif
                </span>
            </a>

            <a
                href="staff_work_orders.php"
                class="pms-card pms-stat staff-dashboard-link"
            >
                <strong>
                    <?php
                    echo $workOrderStatistics["assigned"];
                    ?>
                </strong>

                <span>
                    Tugasan Baharu
                </span>
            </a>

            <a
                href="staff_work_orders.php"
                class="pms-card pms-stat staff-dashboard-link"
            >
                <strong>
                    <?php
                    echo $workOrderStatistics["progress"];
                    ?>
                </strong>

                <span>
                    Sedang Dilaksanakan
                </span>
            </a>

            <a
                href="staff_work_orders.php"
                class="pms-card pms-stat staff-dashboard-link"
            >
                <strong>
                    <?php
                    echo $workOrderStatistics["pending"];
                    ?>
                </strong>

                <span>
                    Pending
                </span>
            </a>

        </section>

                <?php endif; ?>

        <?php if ($showStaffDailyWork): ?>
        <div class="staff-dashboard-section-title">

            <h2>
                Sejarah Kerja Terkini
            </h2>

        </div>

        <section class="pms-card">

            <?php if (count($recentDailyWorks) === 0): ?>

                <p class="pms-muted">
                    Belum ada rekod kerja harian yang disimpan.
                </p>

            <?php else: ?>

                <div class="pms-table-wrap">
                    <table class="pms-table">
                        <thead>
                            <tr>
                                <th>Tarikh</th>
                                <th>Kerja</th>
                                <th>Lokasi</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentDailyWorks as $dailyWork): ?>
                                <tr>
                                    <td><?php echo e((string) ($dailyWork["work_date"] ?? "")); ?></td>
                                    <td>
                                        <strong><?php echo e((string) ($dailyWork["work_category"] ?? "Daily Work")); ?></strong>
                                        <small><?php echo e((string) ($dailyWork["work_reference"] ?? "")); ?></small>
                                    </td>
                                    <td><?php echo e(trim((string) (($dailyWork["block_location"] ?? "") . " " . ($dailyWork["specific_location"] ?? "")))); ?></td>
                                    <td>
                                        <span class="pms-badge <?php echo e(dailyWorkBadgeClass((string) ($dailyWork["work_status"] ?? ""))); ?>">
                                            <?php echo e((string) ($dailyWork["work_status"] ?? "Pending")); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p>
                    <a href="staff_work_history.php">
                        Lihat semua sejarah kerja
                    </a>
                </p>

            <?php endif; ?>

        </section>
        <?php endif; ?>

        <?php if ($showStaffDailyWork): ?>
        <div class="staff-dashboard-section-title">

            <h2>
                Ringkasan Daily Work
            </h2>

        </div>

        <section class="pms-grid pms-grid-4">

            <article class="pms-card pms-stat">

                <strong>
                    <?php
                    echo $dailyStatistics["today"];
                    ?>
                </strong>

                <span>
                    Rekod Hari Ini
                </span>

            </article>

            <article class="pms-card pms-stat">

                <strong>
                    <?php
                    echo $dailyStatistics["progress"];
                    ?>
                </strong>

                <span>
                    In Progress
                </span>

            </article>

            <article class="pms-card pms-stat">

                <strong>
                    <?php
                    echo $dailyStatistics["completed"];
                    ?>
                </strong>

                <span>
                    Menunggu Pengesahan
                </span>

            </article>

            <article class="pms-card pms-stat">

                <strong>
                    <?php
                    echo $dailyStatistics["verified"];
                    ?>
                </strong>

                <span>
                    Verified
                </span>

            </article>

        </section>
        <?php endif; ?>

        <?php if ($showStaffWorkOrders || $showStaffDailyWork): ?>
        <div class="staff-dashboard-section-title">

            <h2>
                Tindakan Utama
            </h2>

        </div>

        <section class="pms-grid pms-grid-3">

            <?php if ($showStaffWorkOrders): ?>
            <a
                class="pms-card staff-dashboard-link"
                href="staff_work_orders.php"
            >
                <h3>
                    My Work Orders
                </h3>

                <p>
                    Lihat tugasan yang telah diberikan kepada anda.
                </p>
            </a>
            <?php endif; ?>

            <?php if ($staffCanCreateDailyWork && $showStaffDailyWork): ?>
            <a
                class="pms-card staff-dashboard-link"
                href="staff_work_form.php"
            >
                <h3>
                    Tambah Kerja Harian
                </h3>

                <p>
                    Rekod kerja, lokasi, masa dan gambar.
                </p>
            </a>
            <?php endif; ?>

            <?php if ($staffCanViewDailyWork && $showStaffDailyWork): ?>
            <a
                class="pms-card staff-dashboard-link"
                href="staff_work_history.php"
            >
                <h3>
                    Sejarah Kerja Saya
                </h3>

                <p>
                    Lihat rekod dan status pengesahan.
                </p>
            </a>
            <?php endif; ?>

        </section>
        <?php endif; ?>

        <?php if ($showStaffWorkOrders): ?>
        <div class="staff-dashboard-section-title">

            <h2>
                Work Order Terkini
            </h2>

            <a
                href="staff_work_orders.php"
                class="pms-button-secondary"
            >
                Lihat Semua
            </a>

        </div>

        <section class="pms-card pms-table-wrap">

            <table class="pms-table">

                <thead>

                    <tr>
                        <th>Rujukan</th>
                        <th>Tajuk</th>
                        <th>Lokasi</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Due Date</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (
                    !$hasWorkOrders ||
                    count($recentWorkOrders) === 0
                ): ?>

                    <tr>

                        <td colspan="6">
                            Tiada Work Order aktif diberikan kepada anda.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach (
                        $recentWorkOrders as
                        $workOrder
                    ): ?>

                        <tr>

                            <td>

                                <a
                                    href="staff_work_orders.php"
                                    class="wo-reference"
                                >
                                    <?php
                                    echo e(
                                        (string)
                                        $workOrder[
                                            "work_order_reference"
                                        ]
                                    );
                                    ?>
                                </a>
                                <?php if ($staffCanCreateEvidence): ?>
                                    <a
                                        href="mobile_evidence.php?type=work_order&amp;id=<?=
                                            (int) $workOrder["id"] ?>"
                                        aria-label="Tambah bukti gambar"
                                    >📷</a>
                                <?php endif; ?>

                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    $workOrder["title"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    $workOrder[
                                        "block_location"
                                    ]
                                );
                                ?>

                                <?php if (
                                    !empty(
                                        $workOrder[
                                            "specific_location"
                                        ]
                                    )
                                ): ?>

                                    <br>

                                    <small>
                                        <?php
                                        echo e(
                                            (string)
                                            $workOrder[
                                                "specific_location"
                                            ]
                                        );
                                        ?>
                                    </small>

                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    $workOrder["priority"]
                                );
                                ?>
                            </td>

                            <td>

                                <span
                                    class="pms-badge <?php
                                        echo e(
                                            workOrderBadgeClass(
                                                (string)
                                                $workOrder["status"]
                                            )
                                        );
                                    ?>"
                                >
                                    <?php
                                    echo e(
                                        (string)
                                        $workOrder["status"]
                                    );
                                    ?>
                                </span>

                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    (
                                        $workOrder["due_date"] ??
                                        "-"
                                    )
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </section>
        <?php endif; ?>

    </main>

</div>

<?php echo cpmsStaffPwaScripts(); ?>
</body>

</html>
