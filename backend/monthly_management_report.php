<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");

/*
|--------------------------------------------------------------------------
| InfinityFree / PHP 8 mysqli compatibility
|--------------------------------------------------------------------------
| PHP 8 boleh menukar kesalahan SQL kepada exception yang menyebabkan
| HTTP 500 apabila struktur jadual lama tidak sama sepenuhnya.
| Mod ini membolehkan setiap seksyen laporan dilangkau dengan selamat.
*/
mysqli_report(MYSQLI_REPORT_OFF);

session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

require_once "db.php";

if (!isset($_SESSION["admin"])) {
    header("Location: admin_login.php");
    exit();
}

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

    $stmt->bind_param("s", $tableName);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function safeTruncate(
    string $text,
    int $maximumLength = 100
): string {
    $text = trim($text);

    if ($text === "") {
        return "-";
    }

    if (function_exists("mb_strlen")) {
        if (mb_strlen($text, "UTF-8") <= $maximumLength) {
            return $text;
        }

        return mb_substr(
            $text,
            0,
            $maximumLength,
            "UTF-8"
        ) . "...";
    }

    if (strlen($text) <= $maximumLength) {
        return $text;
    }

    return substr(
        $text,
        0,
        $maximumLength
    ) . "...";
}

function monthNameMs(int $month): string
{
    $months = [
        1 => "Januari",
        2 => "Februari",
        3 => "Mac",
        4 => "April",
        5 => "Mei",
        6 => "Jun",
        7 => "Julai",
        8 => "Ogos",
        9 => "September",
        10 => "Oktober",
        11 => "November",
        12 => "Disember"
    ];

    return $months[$month] ?? "";
}

$year = (int) ($_GET["year"] ?? date("Y"));
$month = (int) ($_GET["month"] ?? date("n"));

if ($year < 2020 || $year > 2100) {
    $year = (int) date("Y");
}

if ($month < 1 || $month > 12) {
    $month = (int) date("n");
}

$periodStart = sprintf("%04d-%02d-01", $year, $month);
$periodEnd = date(
    "Y-m-t",
    strtotime($periodStart)
);

$reportTitle =
    monthNameMs($month) . " " . $year;

$preparedBy =
    trim((string) ($_GET["prepared_by"] ?? ""));

$approvedBy =
    trim((string) ($_GET["approved_by"] ?? ""));

$reportWarnings = [];

function addReportWarning(
    array &$warnings,
    string $section
): void {
    if (!in_array($section, $warnings, true)) {
        $warnings[] = $section;
    }
}

$complaintStats = [
    "total" => 0,
    "pending" => 0,
    "progress" => 0,
    "resolved" => 0,
    "closed" => 0
];

$complaintsByCategory = [];
$complaintsByBlock = [];
$recentComplaints = [];

if (tableExists($conn, "complaints")) {
    $stmt = $conn->prepare(
        "
        SELECT
            COUNT(*) AS total,
            SUM(LOWER(status) = 'pending')
                AS pending_count,
            SUM(LOWER(status) = 'in progress')
                AS progress_count,
            SUM(LOWER(status) = 'resolved')
                AS resolved_count,
            SUM(LOWER(status) = 'closed')
                AS closed_count
        FROM complaints
        WHERE DATE(created_at) BETWEEN ? AND ?
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        $complaintStats["total"] =
            (int) ($row["total"] ?? 0);

        $complaintStats["pending"] =
            (int) ($row["pending_count"] ?? 0);

        $complaintStats["progress"] =
            (int) ($row["progress_count"] ?? 0);

        $complaintStats["resolved"] =
            (int) ($row["resolved_count"] ?? 0);

        $complaintStats["closed"] =
            (int) ($row["closed_count"] ?? 0);

        $stmt->close();
    }

    $stmt = $conn->prepare(
        "
        SELECT
            category,
            COUNT(*) AS total
        FROM complaints
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY category
        ORDER BY total DESC
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $complaintsByCategory[] = $row;
        }

        $stmt->close();
    }

    $stmt = $conn->prepare(
        "
        SELECT
            block,
            COUNT(*) AS total
        FROM complaints
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY block
        ORDER BY block ASC
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $complaintsByBlock[] = $row;
        }

        $stmt->close();
    }

    $stmt = $conn->prepare(
        "
        SELECT
            complaint_id,
            subject,
            category,
            block,
            status,
            created_at
        FROM complaints
        WHERE DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at DESC
        LIMIT 20
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $recentComplaints[] = $row;
        }

        $stmt->close();
    }
}

$workOrderStats = [
    "total" => 0,
    "open" => 0,
    "progress" => 0,
    "pending" => 0,
    "completed" => 0,
    "verified" => 0,
    "overdue" => 0,
    "cost" => 0.0
];

$workOrders = [];

if (tableExists($conn, "work_orders")) {
    $stmt = $conn->prepare(
        "
        SELECT
            COUNT(*) AS total,
            SUM(status IN ('Open','Assigned'))
                AS open_count,
            SUM(status = 'In Progress')
                AS progress_count,
            SUM(status IN (
                'Pending Material',
                'Pending Contractor'
            )) AS pending_count,
            SUM(status = 'Completed')
                AS completed_count,
            SUM(status = 'Verified')
                AS verified_count,
            SUM(
                due_date IS NOT NULL
                AND due_date < CURDATE()
                AND status NOT IN (
                    'Completed',
                    'Verified',
                    'Cancelled'
                )
            ) AS overdue_count,
            COALESCE(SUM(actual_cost), 0)
                AS total_cost
        FROM work_orders
        WHERE DATE(created_at) BETWEEN ? AND ?
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        $workOrderStats["total"] =
            (int) ($row["total"] ?? 0);

        $workOrderStats["open"] =
            (int) ($row["open_count"] ?? 0);

        $workOrderStats["progress"] =
            (int) ($row["progress_count"] ?? 0);

        $workOrderStats["pending"] =
            (int) ($row["pending_count"] ?? 0);

        $workOrderStats["completed"] =
            (int) ($row["completed_count"] ?? 0);

        $workOrderStats["verified"] =
            (int) ($row["verified_count"] ?? 0);

        $workOrderStats["overdue"] =
            (int) ($row["overdue_count"] ?? 0);

        $workOrderStats["cost"] =
            (float) ($row["total_cost"] ?? 0);

        $stmt->close();
    }

    $stmt = $conn->prepare(
        "
        SELECT
            w.work_order_reference,
            w.title,
            w.category,
            w.block_location,
            w.priority,
            w.status,
            w.actual_cost,
            w.created_at,
            s.full_name AS assigned_staff_name
        FROM work_orders w
        LEFT JOIN staff s
            ON s.id = w.assigned_staff_id
        WHERE DATE(w.created_at) BETWEEN ? AND ?
        ORDER BY w.created_at DESC
        LIMIT 30
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $workOrders[] = $row;
        }

        $stmt->close();
    }
}

$dailyWorkStats = [
    "total" => 0,
    "completed" => 0,
    "verified" => 0,
    "rejected" => 0
];

$dailyWorks = [];
$dailyWorkPhotos = [];

if (tableExists($conn, "daily_work_logs")) {
    $stmt = $conn->prepare(
        "
        SELECT
            COUNT(*) AS total,
            SUM(work_status = 'Completed')
                AS completed_count,
            SUM(work_status = 'Verified')
                AS verified_count,
            SUM(work_status = 'Rejected')
                AS rejected_count
        FROM daily_work_logs
        WHERE work_date BETWEEN ? AND ?
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        $dailyWorkStats["total"] =
            (int) ($row["total"] ?? 0);

        $dailyWorkStats["completed"] =
            (int) ($row["completed_count"] ?? 0);

        $dailyWorkStats["verified"] =
            (int) ($row["verified_count"] ?? 0);

        $dailyWorkStats["rejected"] =
            (int) ($row["rejected_count"] ?? 0);

        $stmt->close();
    }

    $stmt = $conn->prepare(
        "
        SELECT
            d.id,
            d.work_reference,
            d.work_date,
            d.work_category,
            d.block_location,
            d.specific_location,
            d.work_description,
            d.work_status,
            s.full_name
        FROM daily_work_logs d
        INNER JOIN staff s
            ON s.id = d.staff_id
        WHERE d.work_date BETWEEN ? AND ?
        AND d.work_status = 'Verified'
        ORDER BY d.work_date DESC
        LIMIT 40
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $dailyWorks[] = $row;
        }

        $stmt->close();
    }

    if (
        tableExists(
            $conn,
            "daily_work_images"
        )
    ) {
        $stmt = $conn->prepare(
            "
            SELECT
                i.image_name,
                i.image_type,
                d.work_reference,
                d.work_date,
                d.work_category,
                d.block_location,
                d.work_description,
                s.full_name
            FROM daily_work_images i
            INNER JOIN daily_work_logs d
                ON d.id = i.daily_work_id
            INNER JOIN staff s
                ON s.id = d.staff_id
            WHERE d.work_date BETWEEN ? AND ?
            AND d.work_status = 'Verified'
            ORDER BY d.work_date DESC, i.id ASC
            LIMIT 30
            "
        );

        if ($stmt) {
            $stmt->bind_param(
                "ss",
                $periodStart,
                $periodEnd
            );

            $stmt->execute();

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $dailyWorkPhotos[] = $row;
            }

            $stmt->close();
        }
    }
}

$assetStats = [
    "total" => 0,
    "active" => 0,
    "maintenance" => 0,
    "overdue" => 0
];

if (tableExists($conn, "assets")) {
    $result = $conn->query(
        "
        SELECT
            COUNT(*) AS total,
            SUM(asset_status = 'Active')
                AS active_count,
            SUM(asset_status = 'Under Maintenance')
                AS maintenance_count,
            SUM(
                next_service_date IS NOT NULL
                AND next_service_date < CURDATE()
            ) AS overdue_count
        FROM assets
        "
    );

    if ($result) {
        $row = $result->fetch_assoc();

        $assetStats["total"] =
            (int) ($row["total"] ?? 0);

        $assetStats["active"] =
            (int) ($row["active_count"] ?? 0);

        $assetStats["maintenance"] =
            (int) ($row["maintenance_count"] ?? 0);

        $assetStats["overdue"] =
            (int) ($row["overdue_count"] ?? 0);
    }
}

$maintenanceStats = [
    "total" => 0,
    "scheduled" => 0,
    "completed" => 0,
    "verified" => 0,
    "overdue" => 0
];

$maintenanceRecords = [];

if (tableExists($conn, "preventive_maintenance")) {
    $stmt = $conn->prepare(
        "
        SELECT
            COUNT(*) AS total,
            SUM(status = 'Scheduled')
                AS scheduled_count,
            SUM(status = 'Completed')
                AS completed_count,
            SUM(status = 'Verified')
                AS verified_count,
            SUM(status = 'Overdue')
                AS overdue_count
        FROM preventive_maintenance
        WHERE scheduled_date BETWEEN ? AND ?
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        $maintenanceStats["total"] =
            (int) ($row["total"] ?? 0);

        $maintenanceStats["scheduled"] =
            (int) ($row["scheduled_count"] ?? 0);

        $maintenanceStats["completed"] =
            (int) ($row["completed_count"] ?? 0);

        $maintenanceStats["verified"] =
            (int) ($row["verified_count"] ?? 0);

        $maintenanceStats["overdue"] =
            (int) ($row["overdue_count"] ?? 0);

        $stmt->close();
    }

    $stmt = $conn->prepare(
        "
        SELECT
            p.maintenance_reference,
            p.scheduled_date,
            p.maintenance_type,
            p.status,
            a.asset_code,
            a.asset_name,
            a.location,
            s.full_name AS assigned_staff_name
        FROM preventive_maintenance p
        INNER JOIN assets a
            ON a.id = p.asset_id
        LEFT JOIN staff s
            ON s.id = p.assigned_staff_id
        WHERE p.scheduled_date BETWEEN ? AND ?
        ORDER BY p.scheduled_date DESC
        LIMIT 30
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $maintenanceRecords[] = $row;
        }

        $stmt->close();
    }
}

$inspectionStats = [
    "total" => 0,
    "good" => 0,
    "attention" => 0,
    "critical" => 0,
    "verified" => 0
];

$inspectionRecords = [];

if (tableExists($conn, "asset_inspections")) {
    $stmt = $conn->prepare(
        "
        SELECT
            COUNT(*) AS total,
            SUM(condition_result = 'Good')
                AS good_count,
            SUM(condition_result = 'Attention Required')
                AS attention_count,
            SUM(condition_result = 'Critical')
                AS critical_count,
            SUM(inspection_status = 'Verified')
                AS verified_count
        FROM asset_inspections
        WHERE inspection_date BETWEEN ? AND ?
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();

        $inspectionStats["total"] =
            (int) ($row["total"] ?? 0);

        $inspectionStats["good"] =
            (int) ($row["good_count"] ?? 0);

        $inspectionStats["attention"] =
            (int) ($row["attention_count"] ?? 0);

        $inspectionStats["critical"] =
            (int) ($row["critical_count"] ?? 0);

        $inspectionStats["verified"] =
            (int) ($row["verified_count"] ?? 0);

        $stmt->close();
    }

    $stmt = $conn->prepare(
        "
        SELECT
            i.inspection_reference,
            i.inspection_date,
            i.condition_result,
            i.findings,
            i.inspection_status,
            a.asset_code,
            a.asset_name,
            s.full_name AS staff_name
        FROM asset_inspections i
        INNER JOIN assets a
            ON a.id = i.asset_id
        INNER JOIN staff s
            ON s.id = i.staff_id
        WHERE i.inspection_date BETWEEN ? AND ?
        ORDER BY i.inspection_date DESC
        LIMIT 30
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "ss",
            $periodStart,
            $periodEnd
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $inspectionRecords[] = $row;
        }

        $stmt->close();
    }
}

$conn->close();

$resolvedTotal =
    $complaintStats["resolved"] +
    $complaintStats["closed"];

$resolutionRate =
    $complaintStats["total"] > 0
        ? round(
            ($resolvedTotal / $complaintStats["total"]) * 100,
            1
        )
        : 0;

$workCompletionTotal =
    $workOrderStats["completed"] +
    $workOrderStats["verified"];

$workCompletionRate =
    $workOrderStats["total"] > 0
        ? round(
            (
                $workCompletionTotal /
                $workOrderStats["total"]
            ) * 100,
            1
        )
        : 0;

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
        Monthly Management Report | V23 PMS
    </title>

    <link
        rel="stylesheet"
        href="css/pms.css?v=9"
    >

    <link
        rel="stylesheet"
        href="css/pms_monthly_report.css?v=4"
    >

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="pms-body">

<div class="pms-shell">

    <aside class="pms-sidebar">

        <div class="pms-brand">

            <img
                src="images/logo.png?v=4"
                alt="V23 Malawa Ria"
            >

            <div>
                <strong>
                    V23 Malawa Ria
                </strong>

                <span>
                    Property Management System
                </span>
            </div>

        </div>

        <nav class="pms-nav">

            <a href="admin_dashboard.php">
                Dashboard
            </a>

            <a href="admin_reports.php">
                Complaint Reports
            </a>

            <a
                href="monthly_management_report.php"
                class="active"
            >
                Monthly Management Report
            </a>

            <a href="admin_work_orders.php">
                Work Orders
            </a>

            <a href="admin_assets.php">
                Assets
            </a>

        </nav>

        <div class="pms-sidebar-footer">

            <a href="admin_logout.php">
                Logout Admin
            </a>

        </div>

    </aside>

    <main class="pms-main">

        <header class="pms-topbar">

            <div>

                <h1>
                    Monthly Management Report
                </h1>

                <p>
                    Jana laporan bulanan untuk MC dan pemaju.
                </p>

            </div>

        </header>

        <div class="report-toolbar">

            <form
                method="GET"
                class="report-filter-form"
            >

                <div class="pms-form-group">

                    <label for="month">
                        Bulan
                    </label>

                    <select
                        id="month"
                        name="month"
                    >

                        <?php for ($m = 1; $m <= 12; $m++): ?>

                            <option
                                value="<?php echo $m; ?>"
                                <?php
                                echo $month === $m
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo e(monthNameMs($m)); ?>
                            </option>

                        <?php endfor; ?>

                    </select>

                </div>

                <div class="pms-form-group">

                    <label for="year">
                        Tahun
                    </label>

                    <select
                        id="year"
                        name="year"
                    >

                        <?php for (
                            $y = (int) date("Y") - 3;
                            $y <= (int) date("Y") + 1;
                            $y++
                        ): ?>

                            <option
                                value="<?php echo $y; ?>"
                                <?php
                                echo $year === $y
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo $y; ?>
                            </option>

                        <?php endfor; ?>

                    </select>

                </div>

                <div class="pms-form-group">

                    <label for="prepared_by">
                        Disediakan Oleh
                    </label>

                    <input
                        type="text"
                        id="prepared_by"
                        name="prepared_by"
                        value="<?php echo e($preparedBy); ?>"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="approved_by">
                        Diluluskan Oleh
                    </label>

                    <input
                        type="text"
                        id="approved_by"
                        name="approved_by"
                        value="<?php echo e($approvedBy); ?>"
                    >

                </div>

                <button
                    type="submit"
                    class="pms-button"
                >
                    Jana Laporan
                </button>

            </form>

            <button
                type="button"
                class="pms-button-secondary"
                onclick="window.print()"
            >
                Cetak / Simpan PDF
            </button>

        </div>

        <?php if (count($reportWarnings) > 0): ?>

            <div class="pms-alert-error no-print">
                Sebahagian data laporan tidak dapat dibaca kerana struktur
                database modul tersebut belum sepadan sepenuhnya. Halaman
                masih boleh digunakan. Modul terlibat:
                <strong>
                    <?php echo e(implode(", ", $reportWarnings)); ?>
                </strong>
            </div>

        <?php endif; ?>

        <article class="report-document">

            <section class="report-cover">

                <img
                    src="images/logo.png?v=4"
                    alt="V23 Malawa Ria"
                >

                <h1>
                    MONTHLY MANAGEMENT REPORT
                </h1>

                <h2>
                    <?php echo e($reportTitle); ?>
                </h2>

                <p>
                    V23 Malawa Ria Apartment
                </p>

                <p>
                    Property Management System
                </p>

                <?php if ($preparedBy !== ""): ?>

                    <p style="margin-top:35px;">
                        Disediakan oleh:
                        <strong>
                            <?php echo e($preparedBy); ?>
                        </strong>
                    </p>

                <?php endif; ?>

            </section>

            <section class="report-section">

                <h2>
                    1. Executive Summary
                </h2>

                <div class="report-note">

                    Dalam bulan
                    <strong>
                        <?php echo e($reportTitle); ?>
                    </strong>,
                    sebanyak
                    <strong>
                        <?php
                        echo $complaintStats["total"];
                        ?>
                    </strong>
                    aduan diterima,
                    <strong>
                        <?php
                        echo $workOrderStats["total"];
                        ?>
                    </strong>
                    Work Order direkodkan dan
                    <strong>
                        <?php
                        echo $dailyWorkStats["verified"];
                        ?>
                    </strong>
                    rekod kerja harian telah disahkan.
                    Kadar penyelesaian aduan ialah
                    <strong>
                        <?php echo $resolutionRate; ?>%
                    </strong>
                    manakala kadar penyelesaian Work Order ialah
                    <strong>
                        <?php echo $workCompletionRate; ?>%
                    </strong>.
                </div>

                <div class="report-kpi-grid" style="margin-top:18px;">

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $complaintStats["total"];
                            ?>
                        </strong>
                        <span>Aduan Diterima</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $workOrderStats["total"];
                            ?>
                        </strong>
                        <span>Work Order</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $dailyWorkStats["verified"];
                            ?>
                        </strong>
                        <span>Daily Work Verified</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            RM
                            <?php
                            echo number_format(
                                $workOrderStats["cost"],
                                2
                            );
                            ?>
                        </strong>
                        <span>Kos Kerja</span>
                    </div>

                </div>

            </section>

            <section class="report-section page-break">

                <h2>
                    2. Complaint Analysis
                </h2>

                <div class="report-kpi-grid">

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $complaintStats["total"];
                            ?>
                        </strong>
                        <span>Jumlah</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $complaintStats["pending"];
                            ?>
                        </strong>
                        <span>Pending</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $complaintStats["progress"];
                            ?>
                        </strong>
                        <span>In Progress</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $resolvedTotal;
                            ?>
                        </strong>
                        <span>Resolved / Closed</span>
                    </div>

                </div>

                <canvas
                    id="complaintStatusChart"
                    class="report-chart"
                ></canvas>

                <h3>
                    Mengikut Kategori
                </h3>

                <?php if (
                    count($complaintsByCategory) === 0
                ): ?>

                    <div class="report-empty">
                        Tiada data aduan untuk bulan ini.
                    </div>

                <?php else: ?>

                    <table class="report-table">

                        <thead>
                            <tr>
                                <th>Kategori</th>
                                <th>Jumlah</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach (
                                $complaintsByCategory as $row
                            ): ?>

                                <tr>
                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["category"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo (int) $row["total"];
                                        ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

                <h3>
                    Mengikut Blok
                </h3>

                <canvas
                    id="complaintBlockChart"
                    class="report-chart"
                ></canvas>

                <h3>
                    Senarai Aduan Terkini
                </h3>

                <?php if (
                    count($recentComplaints) === 0
                ): ?>

                    <div class="report-empty">
                        Tiada aduan untuk bulan ini.
                    </div>

                <?php else: ?>

                    <table class="report-table">

                        <thead>
                            <tr>
                                <th>Rujukan</th>
                                <th>Tarikh</th>
                                <th>Tajuk</th>
                                <th>Kategori</th>
                                <th>Blok</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach (
                                $recentComplaints as $row
                            ): ?>

                                <tr>
                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["complaint_id"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            date(
                                                "d/m/Y",
                                                strtotime(
                                                    (string)
                                                    $row["created_at"]
                                                )
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["subject"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["category"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["block"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["status"]
                                        );
                                        ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </section>

            <section class="report-section page-break">

                <h2>
                    3. Work Order Summary
                </h2>

                <div class="report-kpi-grid">

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $workOrderStats["total"];
                            ?>
                        </strong>
                        <span>Jumlah</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $workOrderStats["open"];
                            ?>
                        </strong>
                        <span>Open / Assigned</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $workOrderStats["progress"];
                            ?>
                        </strong>
                        <span>In Progress</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $workCompletionTotal;
                            ?>
                        </strong>
                        <span>Completed / Verified</span>
                    </div>

                </div>

                <canvas
                    id="workOrderChart"
                    class="report-chart"
                ></canvas>

                <?php if (count($workOrders) === 0): ?>

                    <div class="report-empty">
                        Tiada Work Order untuk bulan ini.
                    </div>

                <?php else: ?>

                    <table class="report-table">

                        <thead>
                            <tr>
                                <th>Rujukan</th>
                                <th>Tajuk</th>
                                <th>Lokasi</th>
                                <th>Staff</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Kos</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($workOrders as $row): ?>

                                <tr>
                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row[
                                                "work_order_reference"
                                            ]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["title"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["block_location"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $row[
                                                    "assigned_staff_name"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["priority"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["status"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        RM
                                        <?php
                                        echo number_format(
                                            (float)
                                            (
                                                $row["actual_cost"] ?? 0
                                            ),
                                            2
                                        );
                                        ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </section>

            <section class="report-section page-break">

                <h2>
                    4. Daily Work Activities
                </h2>

                <div class="report-kpi-grid">

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $dailyWorkStats["total"];
                            ?>
                        </strong>
                        <span>Jumlah Rekod</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $dailyWorkStats["completed"];
                            ?>
                        </strong>
                        <span>Completed</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $dailyWorkStats["verified"];
                            ?>
                        </strong>
                        <span>Verified</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $dailyWorkStats["rejected"];
                            ?>
                        </strong>
                        <span>Rejected</span>
                    </div>

                </div>

                <?php if (count($dailyWorks) === 0): ?>

                    <div class="report-empty">
                        Tiada Daily Work verified untuk bulan ini.
                    </div>

                <?php else: ?>

                    <table class="report-table">

                        <thead>
                            <tr>
                                <th>Tarikh</th>
                                <th>Rujukan</th>
                                <th>Pekerja</th>
                                <th>Kategori</th>
                                <th>Lokasi</th>
                                <th>Kerja Dilakukan</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($dailyWorks as $row): ?>

                                <tr>
                                    <td>
                                        <?php
                                        echo e(
                                            date(
                                                "d/m/Y",
                                                strtotime(
                                                    (string)
                                                    $row["work_date"]
                                                )
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["work_reference"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["full_name"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["work_category"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["block_location"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            safeTruncate(
                                                (string)
                                                $row["work_description"],
                                                100
                                            )
                                        );
                                        ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </section>

            <section class="report-section page-break">

                <h2>
                    5. Daily Work Photo Report
                </h2>

                <?php if (
                    count($dailyWorkPhotos) === 0
                ): ?>

                    <div class="report-empty">
                        Tiada gambar kerja verified untuk bulan ini.
                    </div>

                <?php else: ?>

                    <div class="report-photo-grid">

                        <?php foreach (
                            $dailyWorkPhotos as $photo
                        ): ?>

                            <article class="report-photo-card">

                                <img
                                    src="uploads/daily_work/<?php
                                        echo rawurlencode(
                                            basename(
                                                (string)
                                                $photo["image_name"]
                                            )
                                        );
                                    ?>"
                                    alt="Daily Work Photo"
                                >

                                <div>

                                    <strong>
                                        <?php
                                        echo e(
                                            (string)
                                            $photo["work_category"]
                                        );
                                        ?>
                                        —
                                        <?php
                                        echo e(
                                            (string)
                                            $photo["image_type"]
                                        );
                                        ?>
                                    </strong>

                                    <span>
                                        <?php
                                        echo e(
                                            date(
                                                "d/m/Y",
                                                strtotime(
                                                    (string)
                                                    $photo["work_date"]
                                                )
                                            )
                                        );
                                        ?>
                                        |
                                        <?php
                                        echo e(
                                            (string)
                                            $photo["block_location"]
                                        );
                                        ?>
                                        |
                                        <?php
                                        echo e(
                                            (string)
                                            $photo["full_name"]
                                        );
                                        ?>
                                    </span>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

            <section class="report-section page-break">

                <h2>
                    6. Asset & Preventive Maintenance
                </h2>

                <h3>
                    Asset Register Summary
                </h3>

                <div class="report-kpi-grid">

                    <div class="report-kpi-card">
                        <strong>
                            <?php echo $assetStats["total"]; ?>
                        </strong>
                        <span>Jumlah Aset</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php echo $assetStats["active"]; ?>
                        </strong>
                        <span>Active</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $assetStats["maintenance"];
                            ?>
                        </strong>
                        <span>Under Maintenance</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $assetStats["overdue"];
                            ?>
                        </strong>
                        <span>Service Overdue</span>
                    </div>

                </div>

                <h3>
                    Preventive Maintenance Summary
                </h3>

                <div class="report-kpi-grid">

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $maintenanceStats["total"];
                            ?>
                        </strong>
                        <span>Jumlah</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $maintenanceStats["scheduled"];
                            ?>
                        </strong>
                        <span>Scheduled</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo
                                $maintenanceStats["completed"] +
                                $maintenanceStats["verified"];
                            ?>
                        </strong>
                        <span>Completed / Verified</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $maintenanceStats["overdue"];
                            ?>
                        </strong>
                        <span>Overdue</span>
                    </div>

                </div>

                <?php if (
                    count($maintenanceRecords) > 0
                ): ?>

                    <table class="report-table">

                        <thead>
                            <tr>
                                <th>Rujukan</th>
                                <th>Tarikh</th>
                                <th>Aset</th>
                                <th>Lokasi</th>
                                <th>Jenis</th>
                                <th>Staff</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach (
                                $maintenanceRecords as $row
                            ): ?>

                                <tr>
                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row[
                                                "maintenance_reference"
                                            ]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            date(
                                                "d/m/Y",
                                                strtotime(
                                                    (string)
                                                    $row["scheduled_date"]
                                                )
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["asset_code"]
                                        );
                                        ?>
                                        —
                                        <?php
                                        echo e(
                                            (string)
                                            $row["asset_name"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["location"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["maintenance_type"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $row[
                                                    "assigned_staff_name"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["status"]
                                        );
                                        ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </section>

            <section class="report-section page-break">

                <h2>
                    7. Asset Inspection Report
                </h2>

                <div class="report-kpi-grid">

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $inspectionStats["total"];
                            ?>
                        </strong>
                        <span>Jumlah Inspection</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $inspectionStats["good"];
                            ?>
                        </strong>
                        <span>Good</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $inspectionStats["attention"];
                            ?>
                        </strong>
                        <span>Attention Required</span>
                    </div>

                    <div class="report-kpi-card">
                        <strong>
                            <?php
                            echo $inspectionStats["critical"];
                            ?>
                        </strong>
                        <span>Critical</span>
                    </div>

                </div>

                <?php if (
                    count($inspectionRecords) === 0
                ): ?>

                    <div class="report-empty">
                        Tiada pemeriksaan aset untuk bulan ini.
                    </div>

                <?php else: ?>

                    <table class="report-table">

                        <thead>
                            <tr>
                                <th>Rujukan</th>
                                <th>Tarikh</th>
                                <th>Aset</th>
                                <th>Pekerja</th>
                                <th>Condition</th>
                                <th>Findings</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach (
                                $inspectionRecords as $row
                            ): ?>

                                <tr>
                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row[
                                                "inspection_reference"
                                            ]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            date(
                                                "d/m/Y",
                                                strtotime(
                                                    (string)
                                                    $row["inspection_date"]
                                                )
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["asset_code"]
                                        );
                                        ?>
                                        —
                                        <?php
                                        echo e(
                                            (string)
                                            $row["asset_name"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["staff_name"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["condition_result"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            safeTruncate(
                                                (string)
                                                ($row["findings"] ?? "-"),
                                                90
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            $row["inspection_status"]
                                        );
                                        ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </section>

            <section class="report-section page-break">

                <h2>
                    8. Recommendations & Next Month Plan
                </h2>

                <div class="report-note">

                    <strong>
                        Cadangan automatik berdasarkan data:
                    </strong>

                    <ul>

                        <?php if (
                            $complaintStats["pending"] > 0 ||
                            $complaintStats["progress"] > 0
                        ): ?>
                            <li>
                                Selesaikan
                                <?php
                                echo
                                    $complaintStats["pending"] +
                                    $complaintStats["progress"];
                                ?>
                                aduan yang masih belum ditutup.
                            </li>
                        <?php endif; ?>

                        <?php if (
                            $workOrderStats["overdue"] > 0
                        ): ?>
                            <li>
                                Beri keutamaan kepada
                                <?php
                                echo $workOrderStats["overdue"];
                                ?>
                                Work Order yang overdue.
                            </li>
                        <?php endif; ?>

                        <?php if (
                            $assetStats["overdue"] > 0
                        ): ?>
                            <li>
                                Jadualkan servis bagi
                                <?php
                                echo $assetStats["overdue"];
                                ?>
                                aset yang telah melepasi tarikh servis.
                            </li>
                        <?php endif; ?>

                        <?php if (
                            $inspectionStats["critical"] > 0
                        ): ?>
                            <li>
                                Cipta Work Order segera untuk
                                <?php
                                echo $inspectionStats["critical"];
                                ?>
                                pemeriksaan berstatus Critical.
                            </li>
                        <?php endif; ?>

                        <?php if (
                            $complaintStats["total"] === 0 &&
                            $workOrderStats["total"] === 0 &&
                            $assetStats["overdue"] === 0
                        ): ?>
                            <li>
                                Teruskan pemantauan rutin dan
                                preventive maintenance mengikut jadual.
                            </li>
                        <?php endif; ?>

                    </ul>

                </div>

                <div class="report-signature-grid">

                    <div class="report-signature">

                        <?php
                        echo $preparedBy !== ""
                            ? e($preparedBy)
                            : "Prepared By";
                        ?>

                        <br>

                        Property Management

                    </div>

                    <div class="report-signature">

                        <?php
                        echo $approvedBy !== ""
                            ? e($approvedBy)
                            : "Approved By";
                        ?>

                        <br>

                        Management Committee / Developer

                    </div>

                </div>

            </section>

        </article>

    </main>

</div>

<script>
const complaintStatusData = {
    labels: [
        "Pending",
        "In Progress",
        "Resolved",
        "Closed"
    ],
    datasets: [{
        data: [
            <?php echo $complaintStats["pending"]; ?>,
            <?php echo $complaintStats["progress"]; ?>,
            <?php echo $complaintStats["resolved"]; ?>,
            <?php echo $complaintStats["closed"]; ?>
        ]
    }]
};

new Chart(
    document.getElementById("complaintStatusChart"),
    {
        type: "doughnut",
        data: complaintStatusData,
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    }
);

new Chart(
    document.getElementById("complaintBlockChart"),
    {
        type: "bar",
        data: {
            labels: <?php
            echo json_encode(
                array_map(
                    static fn(array $row): string =>
                        (string) $row["block"],
                    $complaintsByBlock
                ),
                JSON_UNESCAPED_UNICODE
            );
            ?>,
            datasets: [{
                label: "Jumlah Aduan",
                data: <?php
                echo json_encode(
                    array_map(
                        static fn(array $row): int =>
                            (int) $row["total"],
                        $complaintsByBlock
                    )
                );
                ?>
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    }
);

new Chart(
    document.getElementById("workOrderChart"),
    {
        type: "bar",
        data: {
            labels: [
                "Open / Assigned",
                "In Progress",
                "Pending",
                "Completed",
                "Verified"
            ],
            datasets: [{
                label: "Work Order",
                data: [
                    <?php echo $workOrderStats["open"]; ?>,
                    <?php echo $workOrderStats["progress"]; ?>,
                    <?php echo $workOrderStats["pending"]; ?>,
                    <?php echo $workOrderStats["completed"]; ?>,
                    <?php echo $workOrderStats["verified"]; ?>
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    }
);
</script>

</body>

</html>
