<?php

declare(strict_types=1);

session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

require_once "db.php";
require_once "staff_pwa_bootstrap.php";
require_once __DIR__ . "/cpms/includes/permission_engine.php";
$staffPwaBranding = cpmsStaffPwaBranding($conn);

if (!isset($_SESSION["staff_id"])) {
    header("Location: cpms/login.php");
    exit();
}

cpmsRequire("work_orders.view", $conn);

function e(?string $value): string
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function staffWoColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function staffWoPropertyId(mysqli $conn, int $staffId): int
{
    $propertyId = (int) (
        $_SESSION["cpms_property_id"]
        ?? $_SESSION["staff_property_id"]
        ?? 0
    );

    if ($propertyId > 0) {
        return $propertyId;
    }

    if (staffWoColumnExists($conn, "staff", "property_id")) {
        $stmt = $conn->prepare("SELECT property_id FROM staff WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $staffId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $propertyId = (int) ($row["property_id"] ?? 0);
            if ($propertyId > 0) {
                $_SESSION["staff_property_id"] = $propertyId;
                $_SESSION["cpms_property_id"] = $propertyId;
                return $propertyId;
            }
        }
    }

    return 0;
}

function statusBadgeClass(string $status): string
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

$staffId = (int) $_SESSION["staff_id"];
$propertyId = staffWoPropertyId($conn, $staffId);
if ($propertyId < 1) {
    http_response_code(403);
    exit("Akaun staff tidak mempunyai property_id yang sah.");
}
$propertyFilter = staffWoColumnExists($conn, "work_orders", "property_id")
    ? " AND property_id = ?"
    : "";

$stmt = $conn->prepare(
    "
    SELECT *
    FROM work_orders
    WHERE assigned_staff_id = ?
    {$propertyFilter}
    ORDER BY
        FIELD(
            status,
            'Assigned',
            'In Progress',
            'Pending Material',
            'Pending Contractor',
            'Completed',
            'Verified',
            'Cancelled'
        ),
        due_date ASC,
        created_at DESC
    "
);

$workOrders = [];

if ($stmt) {
    if ($propertyFilter !== "") {
        $stmt->bind_param("ii", $staffId, $propertyId);
    } else {
        $stmt->bind_param("i", $staffId);
    }
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $workOrders[] = $row;
    }

    $stmt->close();
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
    <title>My Work Orders | CPMS</title>
    <link rel="stylesheet" href="css/pms.css?v=4">
    <link
        rel="stylesheet"
        href="css/pms_work_orders.css?v=1"
    >
	<?php echo cpmsStaffPwaHead($staffPwaBranding); ?>
	<?php echo cpmsStaffPwaStyle($staffPwaBranding); ?>
	<link rel="stylesheet" href="css/workforce_portal_shell.css?v=394">
	    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head>

<body class="pms-body">

<div class="pms-shell">

    <aside class="pms-sidebar">

        <div class="pms-brand">
	            <img
	                src="<?php echo e((string) $staffPwaBranding['logo_path']); ?>"
	                alt="<?php echo e((string) $staffPwaBranding['property_name']); ?>"
	            >

            <div>
	                <strong><?php echo e((string) $staffPwaBranding['property_name']); ?></strong>
                <span>Staff Portal</span>
            </div>
        </div>

        <nav class="pms-nav">
            <a href="staff_dashboard.php">Dashboard</a>
            <a
                href="staff_work_orders.php"
                class="active"
            >
                My Work Orders
            </a>
            <a href="staff_work_form.php">Add Daily Work</a>
            <a href="staff_work_history.php">My Work History</a>
        </nav>

        <div class="pms-sidebar-footer">
            <a href="staff_logout.php">Logout Staff</a>
        </div>

    </aside>

    <main class="pms-main">

        <header class="pms-topbar">
            <div>
                <h1>My Work Orders</h1>
                <p>
                    Tugasan yang telah diberikan kepada anda.
                </p>
            </div>

            <div class="pms-user-chip">
                <?php
                echo e(
                    (string) $_SESSION["staff_name"]
                );
                ?>
            </div>
        </header>

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
                        <th>Tindakan</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($workOrders) === 0): ?>

                    <tr>
                        <td colspan="7">
                            Tiada Work Order diberikan.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach (
                        $workOrders as $workOrder
                    ): ?>

                        <?php
                        $safeId =
                            "staff-wo-" .
                            (int) $workOrder["id"];
                        ?>

                        <tr>
                            <td data-label="Rujukan">
                                <span class="wo-reference">
                                    <?php
                                    echo e(
                                        (string)
                                        $workOrder[
                                            "work_order_reference"
                                        ]
                                    );
                                    ?>
                                </span>
                            </td>

                            <td data-label="Tajuk">
                                <?php
                                echo e(
                                    (string) $workOrder["title"]
                                );
                                ?>
                            </td>

                            <td data-label="Lokasi">
                                <?php
                                echo e(
                                    (string)
                                    $workOrder[
                                        "block_location"
                                    ]
                                );
                                ?>
                            </td>

                            <td data-label="Priority">
                                <?php
                                echo e(
                                    (string)
                                    $workOrder["priority"]
                                );
                                ?>
                            </td>

                            <td data-label="Status">
                                <span
                                    class="pms-badge <?php
                                        echo e(
                                            statusBadgeClass(
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

                            <td data-label="Due Date">
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

                            <td data-label="Tindakan">
                                <button
                                    type="button"
                                    class="wo-view-button"
                                    onclick="toggleStaffWO('<?php
                                        echo e($safeId);
                                    ?>')"
                                >
                                    Lihat
                                </button>
                            </td>
                        </tr>

                        <tr
                            id="<?php echo e($safeId); ?>"
                            style="display:none;"
                        >
                            <td colspan="7">

                                <div class="wo-detail">

                                    <div class="wo-detail-item">
                                        <strong>Category</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                $workOrder["category"]
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="wo-detail-item">
                                        <strong>Lokasi Spesifik</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $workOrder[
                                                        "specific_location"
                                                    ] ?? "-"
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="wo-detail-item wo-full">
                                        <strong>Description</strong>
                                        <p>
                                            <?php
                                            echo nl2br(
                                                e(
                                                    (string)
                                                    $workOrder[
                                                        "description"
                                                    ]
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="wo-detail-item wo-full">
                                        <strong>Admin Remarks</strong>
                                        <p>
                                            <?php
                                            echo nl2br(
                                                e(
                                                    (string)
                                                    (
                                                        $workOrder[
                                                            "admin_remarks"
                                                        ] ?? "-"
                                                    )
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                </div>

                                <div class="wo-actions" style="margin-top:15px;">

                                    <a
                                        href="staff_work_form.php?work_order=<?php
                                            echo urlencode(
                                                (string)
                                                $workOrder[
                                                    "work_order_reference"
                                                ]
                                            );
                                        ?>"
                                        class="pms-button"
                                    >
                                        Rekod Kerja Harian
                                    </a>

                                </div>

                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </section>

    </main>

</div>

<script>
function toggleStaffWO(id) {
    const row = document.getElementById(id);

    if (!row) {
        return;
    }

    row.style.display =
        row.style.display === "none" ||
        row.style.display === ""
            ? "table-row"
            : "none";
}
</script>

<?php echo cpmsStaffPwaScripts(); ?>
</body>
</html>
