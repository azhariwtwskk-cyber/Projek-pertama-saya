<?php

declare(strict_types=1);

session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

require_once "db.php";

if (!isset($_SESSION["admin"])) {
    header("Location: admin_login.php");
    exit();
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function pmStatusClass(string $status): string
{
    return match ($status) {
        "Scheduled" => "pm-status-scheduled",
        "In Progress" => "pm-status-progress",
        "Completed" => "pm-status-completed",
        "Verified" => "pm-status-verified",
        "Overdue" => "pm-status-overdue",
        "Cancelled" => "pm-status-cancelled",
        default => "pm-status-scheduled"
    };
}

if (
    !isset($_SESSION["pm_csrf"]) ||
    !is_string($_SESSION["pm_csrf"])
) {
    $_SESSION["pm_csrf"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["pm_csrf"];
$successMessage = "";
$errorMessage = "";

$staff = [];
$result = $conn->query(
    "
    SELECT id, full_name, role
    FROM staff
    WHERE account_status = 'Active'
    ORDER BY full_name ASC
    "
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
}

$assets = [];
$result = $conn->query(
    "
    SELECT id, asset_code, asset_name, location
    FROM assets
    WHERE asset_status <> 'Disposed'
    ORDER BY asset_name ASC
    "
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $assets[] = $row;
    }
}

$selectedAssetId =
    (int) ($_GET["asset_id"] ?? 0);

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["create_schedule"])
) {
    $postedToken = (string) ($_POST["csrf_token"] ?? "");

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage = "Permintaan tidak sah.";
    } else {
        $assetId = (int) ($_POST["asset_id"] ?? 0);
        $scheduledDate =
            trim((string) ($_POST["scheduled_date"] ?? ""));
        $maintenanceType =
            trim((string) ($_POST["maintenance_type"] ?? ""));
        $assignedStaffRaw =
            trim((string) ($_POST["assigned_staff_id"] ?? ""));

        $assignedStaffId =
            $assignedStaffRaw === ""
                ? null
                : (int) $assignedStaffRaw;

        if (
            $assetId < 1 ||
            $scheduledDate === "" ||
            $maintenanceType === ""
        ) {
            $errorMessage = "Sila lengkapkan semua medan wajib.";
        } else {
            try {
                do {
                    $reference =
                        "PM-" .
                        date("Ym") .
                        "-" .
                        strtoupper(
                            bin2hex(random_bytes(3))
                        );

                    $stmt = $conn->prepare(
                        "
                        SELECT id
                        FROM preventive_maintenance
                        WHERE maintenance_reference = ?
                        LIMIT 1
                        "
                    );

                    $stmt->bind_param("s", $reference);
                    $stmt->execute();

                    $exists =
                        $stmt->get_result()->num_rows > 0;

                    $stmt->close();

                } while ($exists);

                $createdBy =
                    (string) $_SESSION["admin"];

                $stmt = $conn->prepare(
                    "
                    INSERT INTO preventive_maintenance
                    (
                        asset_id,
                        maintenance_reference,
                        scheduled_date,
                        maintenance_type,
                        assigned_staff_id,
                        status,
                        created_by
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?, 'Scheduled', ?
                    )
                    "
                );

                if (!$stmt) {
                    throw new RuntimeException(
                        "Jadual maintenance tidak dapat disediakan."
                    );
                }

                $stmt->bind_param(
                    "isssis",
                    $assetId,
                    $reference,
                    $scheduledDate,
                    $maintenanceType,
                    $assignedStaffId,
                    $createdBy
                );

                if (!$stmt->execute()) {
                    throw new RuntimeException(
                        "Jadual maintenance gagal disimpan."
                    );
                }

                $stmt->close();

                $successMessage =
                    "Preventive Maintenance berjaya dijadualkan.";

            } catch (Throwable $error) {
                error_log(
                    "Create maintenance error: " .
                    $error->getMessage()
                );

                $errorMessage =
                    "Preventive Maintenance tidak dapat dijadualkan.";
            }
        }
    }
}

$conn->query(
    "
    UPDATE preventive_maintenance
    SET status = 'Overdue'
    WHERE status = 'Scheduled'
    AND scheduled_date < CURDATE()
    "
);

$records = [];

$result = $conn->query(
    "
    SELECT
        p.*,
        a.asset_code,
        a.asset_name,
        a.location,
        s.full_name AS assigned_staff_name
    FROM preventive_maintenance p
    INNER JOIN assets a
        ON a.id = p.asset_id
    LEFT JOIN staff s
        ON s.id = p.assigned_staff_id
    ORDER BY
        CASE
            WHEN p.status = 'Overdue' THEN 0
            WHEN p.status = 'Scheduled' THEN 1
            ELSE 2
        END,
        p.scheduled_date ASC,
        p.created_at DESC
    "
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
}

$summary = [
    "scheduled" => 0,
    "progress" => 0,
    "completed" => 0,
    "verified" => 0,
    "overdue" => 0
];

$result = $conn->query(
    "
    SELECT
        SUM(status = 'Scheduled') AS scheduled_count,
        SUM(status = 'In Progress') AS progress_count,
        SUM(status = 'Completed') AS completed_count,
        SUM(status = 'Verified') AS verified_count,
        SUM(status = 'Overdue') AS overdue_count
    FROM preventive_maintenance
    "
);

if ($result) {
    $row = $result->fetch_assoc();

    $summary["scheduled"] =
        (int) ($row["scheduled_count"] ?? 0);

    $summary["progress"] =
        (int) ($row["progress_count"] ?? 0);

    $summary["completed"] =
        (int) ($row["completed_count"] ?? 0);

    $summary["verified"] =
        (int) ($row["verified_count"] ?? 0);

    $summary["overdue"] =
        (int) ($row["overdue_count"] ?? 0);
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
    <title>Preventive Maintenance | V23 PMS</title>
    <link rel="stylesheet" href="css/pms.css?v=6">
    <link rel="stylesheet" href="css/pms_assets.css?v=1">
</head>

<body class="pms-body">

<div class="pms-shell">

    <aside class="pms-sidebar">
        <div class="pms-brand">
            <img src="images/logo.png?v=4" alt="V23 Malawa Ria">
            <div>
                <strong>V23 Malawa Ria</strong>
                <span>Property Management System</span>
            </div>
        </div>

        <nav class="pms-nav">
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_assets.php">Asset Management</a>
            <a
                href="admin_maintenance.php"
                class="active"
            >
                Preventive Maintenance
            </a>
            <a href="admin_work_orders.php">Work Order</a>
            <a href="admin_reports.php">Reports</a>
        </nav>

        <div class="pms-sidebar-footer">
            <a href="admin_logout.php">Logout Admin</a>
        </div>
    </aside>

    <main class="pms-main">

        <header class="pms-topbar">
            <div>
                <h1>Preventive Maintenance</h1>
                <p>
                    Jadual dan pantau pemeriksaan berkala aset.
                </p>
            </div>
        </header>

        <?php if ($successMessage !== ""): ?>
            <div class="pms-alert-success">
                <?php echo e($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>
            <div class="pms-alert-error">
                <?php echo e($errorMessage); ?>
            </div>
        <?php endif; ?>

        <section class="asset-summary-grid">

            <article class="asset-summary-card">
                <strong><?php echo $summary["scheduled"]; ?></strong>
                <span>Scheduled</span>
            </article>

            <article class="asset-summary-card">
                <strong><?php echo $summary["progress"]; ?></strong>
                <span>In Progress</span>
            </article>

            <article class="asset-summary-card">
                <strong><?php echo $summary["completed"]; ?></strong>
                <span>Completed</span>
            </article>

            <article class="asset-summary-card">
                <strong><?php echo $summary["verified"]; ?></strong>
                <span>Verified</span>
            </article>

            <article class="asset-summary-card">
                <strong><?php echo $summary["overdue"]; ?></strong>
                <span>Overdue</span>
            </article>

        </section>

        <section class="pms-card">

            <div class="pms-section-title">
                <h2>Jadual Maintenance Baharu</h2>
            </div>

            <form method="POST" class="pms-form-grid">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($csrfToken); ?>"
                >

                <div class="pms-form-group">
                    <label for="asset_id">Aset *</label>
                    <select
                        id="asset_id"
                        name="asset_id"
                        required
                    >
                        <option value="">-- Sila Pilih --</option>

                        <?php foreach ($assets as $asset): ?>
                            <option
                                value="<?php echo (int) $asset["id"]; ?>"
                                <?php
                                echo $selectedAssetId ===
                                    (int) $asset["id"]
                                        ? "selected"
                                        : "";
                                ?>
                            >
                                <?php
                                echo e(
                                    (string) $asset["asset_code"]
                                );
                                ?>
                                -
                                <?php
                                echo e(
                                    (string) $asset["asset_name"]
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pms-form-group">
                    <label for="scheduled_date">Tarikh *</label>
                    <input
                        type="date"
                        id="scheduled_date"
                        name="scheduled_date"
                        required
                    >
                </div>

                <div class="pms-form-group">
                    <label for="maintenance_type">
                        Jenis Maintenance *
                    </label>
                    <input
                        type="text"
                        id="maintenance_type"
                        name="maintenance_type"
                        maxlength="150"
                        placeholder="Contoh: Monthly Visual Inspection"
                        required
                    >
                </div>

                <div class="pms-form-group">
                    <label for="assigned_staff_id">
                        Assign Staff
                    </label>
                    <select
                        id="assigned_staff_id"
                        name="assigned_staff_id"
                    >
                        <option value="">Belum Assign</option>

                        <?php foreach ($staff as $member): ?>
                            <option
                                value="<?php
                                    echo (int) $member["id"];
                                ?>"
                            >
                                <?php
                                echo e(
                                    (string) $member["full_name"]
                                );
                                ?>
                                -
                                <?php
                                echo e(
                                    (string) $member["role"]
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >
                    <button
                        type="submit"
                        name="create_schedule"
                        class="pms-button"
                    >
                        Jadualkan Maintenance
                    </button>
                </div>

            </form>

        </section>

        <div class="pms-section-title">
            <h2><?php echo count($records); ?> Rekod</h2>
        </div>

        <section class="pms-card pms-table-wrap">

            <table class="pms-table">

                <thead>
                    <tr>
                        <th>Rujukan</th>
                        <th>Aset</th>
                        <th>Lokasi</th>
                        <th>Jenis</th>
                        <th>Tarikh</th>
                        <th>Assigned To</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($records) === 0): ?>
                    <tr>
                        <td colspan="7">
                            Tiada jadual preventive maintenance.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($records as $record): ?>

                        <tr>
                            <td>
                                <span class="asset-code">
                                    <?php
                                    echo e(
                                        (string)
                                        $record[
                                            "maintenance_reference"
                                        ]
                                    );
                                    ?>
                                </span>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    $record["asset_code"]
                                );
                                ?>
                                <br>
                                <small>
                                    <?php
                                    echo e(
                                        (string)
                                        $record["asset_name"]
                                    );
                                    ?>
                                </small>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    $record["location"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    $record["maintenance_type"]
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
                                            $record["scheduled_date"]
                                        )
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    (
                                        $record[
                                            "assigned_staff_name"
                                        ] ?? "Belum Assign"
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <span
                                    class="pms-badge <?php
                                        echo e(
                                            pmStatusClass(
                                                (string)
                                                $record["status"]
                                            )
                                        );
                                    ?>"
                                >
                                    <?php
                                    echo e(
                                        (string)
                                        $record["status"]
                                    );
                                    ?>
                                </span>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </section>

    </main>

</div>

</body>
</html>
