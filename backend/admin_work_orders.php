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

if (
    !isset($_SESSION["wo_csrf"]) ||
    !is_string($_SESSION["wo_csrf"])
) {
    $_SESSION["wo_csrf"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["wo_csrf"];
$successMessage = "";
$errorMessage = "";

$allowedStatuses = [
    "Open",
    "Assigned",
    "In Progress",
    "Pending Material",
    "Pending Contractor",
    "Completed",
    "Verified",
    "Cancelled"
];

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["update_work_order"])
) {
    $postedToken = (string) ($_POST["csrf_token"] ?? "");

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage = "Permintaan tidak sah.";
    } else {
        $workOrderId = (int) ($_POST["work_order_id"] ?? 0);
        $newStatus = trim((string) ($_POST["status"] ?? ""));
        $staffIdRaw = trim((string) ($_POST["assigned_staff_id"] ?? ""));
        $remarks = trim((string) ($_POST["admin_remarks"] ?? ""));
        $actualCostRaw = trim((string) ($_POST["actual_cost"] ?? ""));

        $staffId = $staffIdRaw === "" ? null : (int) $staffIdRaw;
        $actualCost =
            $actualCostRaw === ""
                ? null
                : (float) $actualCostRaw;

        if (
            $workOrderId < 1 ||
            !in_array($newStatus, $allowedStatuses, true)
        ) {
            $errorMessage = "Maklumat Work Order tidak sah.";
        } else {
            try {
                $conn->begin_transaction();

                $checkStmt = $conn->prepare(
                    "
                    SELECT status
                    FROM work_orders
                    WHERE id = ?
                    LIMIT 1
                    FOR UPDATE
                    "
                );

                if (!$checkStmt) {
                    throw new RuntimeException(
                        "Rekod Work Order tidak dapat diperiksa."
                    );
                }

                $checkStmt->bind_param("i", $workOrderId);
                $checkStmt->execute();

                $current = $checkStmt
                    ->get_result()
                    ->fetch_assoc();

                $checkStmt->close();

                if (!$current) {
                    throw new RuntimeException(
                        "Work Order tidak dijumpai."
                    );
                }

                $oldStatus = (string) $current["status"];
                $assignedStatus =
                    $staffId !== null &&
                    $newStatus === "Open"
                        ? "Assigned"
                        : $newStatus;

                $verifiedBy =
                    $assignedStatus === "Verified"
                        ? (string) $_SESSION["admin"]
                        : null;

                $stmt = $conn->prepare(
                    "
                    UPDATE work_orders
                    SET
                        assigned_staff_id = ?,
                        status = ?,
                        admin_remarks = ?,
                        actual_cost = ?,
                        verified_by =
                            CASE
                                WHEN ? = 'Verified'
                                THEN ?
                                ELSE verified_by
                            END,
                        verified_at =
                            CASE
                                WHEN ? = 'Verified'
                                THEN NOW()
                                ELSE verified_at
                            END,
                        completed_at =
                            CASE
                                WHEN ? IN ('Completed','Verified')
                                THEN COALESCE(completed_at, NOW())
                                ELSE completed_at
                            END
                    WHERE id = ?
                    "
                );

                if (!$stmt) {
                    throw new RuntimeException(
                        "Kemas kini Work Order tidak dapat disediakan."
                    );
                }

                $stmt->bind_param(
                    "issdssssi",
                    $staffId,
                    $assignedStatus,
                    $remarks,
                    $actualCost,
                    $assignedStatus,
                    $verifiedBy,
                    $assignedStatus,
                    $assignedStatus,
                    $workOrderId
                );

                if (!$stmt->execute()) {
                    throw new RuntimeException(
                        "Work Order gagal dikemas kini."
                    );
                }

                $stmt->close();

                $historyStmt = $conn->prepare(
                    "
                    INSERT INTO work_order_history
                    (
                        work_order_id,
                        old_status,
                        new_status,
                        remarks,
                        updated_by
                    )
                    VALUES (?, ?, ?, ?, ?)
                    "
                );

                if ($historyStmt) {
                    $updatedBy = (string) $_SESSION["admin"];

                    $historyStmt->bind_param(
                        "issss",
                        $workOrderId,
                        $oldStatus,
                        $assignedStatus,
                        $remarks,
                        $updatedBy
                    );

                    $historyStmt->execute();
                    $historyStmt->close();
                }

                $conn->commit();
                $successMessage =
                    "Work Order berjaya dikemas kini.";

            } catch (Throwable $error) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                }

                error_log(
                    "Work order update error: " .
                    $error->getMessage()
                );

                $errorMessage =
                    "Work Order tidak dapat dikemas kini.";
            }
        }
    }
}

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

$search = trim((string) ($_GET["search"] ?? ""));
$statusFilter = trim((string) ($_GET["status"] ?? ""));
$priorityFilter = trim((string) ($_GET["priority"] ?? ""));
$staffFilter = (int) ($_GET["staff_id"] ?? 0);

$where = [];
$types = "";
$values = [];

if ($search !== "") {
    $where[] = "
        (
            w.work_order_reference LIKE ?
            OR w.complaint_id LIKE ?
            OR w.title LIKE ?
            OR w.description LIKE ?
            OR w.block_location LIKE ?
            OR w.specific_location LIKE ?
        )
    ";

    $like = "%" . $search . "%";

    for ($i = 0; $i < 6; $i++) {
        $types .= "s";
        $values[] = $like;
    }
}

if (
    $statusFilter !== "" &&
    in_array($statusFilter, $allowedStatuses, true)
) {
    $where[] = "w.status = ?";
    $types .= "s";
    $values[] = $statusFilter;
}

$allowedPriorities = [
    "Low",
    "Medium",
    "High",
    "Emergency"
];

if (
    $priorityFilter !== "" &&
    in_array(
        $priorityFilter,
        $allowedPriorities,
        true
    )
) {
    $where[] = "w.priority = ?";
    $types .= "s";
    $values[] = $priorityFilter;
}

if ($staffFilter > 0) {
    $where[] = "w.assigned_staff_id = ?";
    $types .= "i";
    $values[] = $staffFilter;
}

$whereSql =
    count($where) > 0
        ? "WHERE " . implode(" AND ", $where)
        : "";

$stmt = $conn->prepare(
    "
    SELECT
        w.*,
        s.full_name AS assigned_staff_name,
        s.role AS assigned_staff_role
    FROM work_orders w
    LEFT JOIN staff s
        ON s.id = w.assigned_staff_id
    {$whereSql}
    ORDER BY
        FIELD(
            w.status,
            'Emergency',
            'Open',
            'Assigned',
            'In Progress',
            'Pending Material',
            'Pending Contractor',
            'Completed',
            'Verified',
            'Cancelled'
        ),
        w.created_at DESC
    "
);

$workOrders = [];

if ($stmt) {
    if ($types !== "") {
        $stmt->bind_param(
            $types,
            ...$values
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $workOrders[] = $row;
    }

    $stmt->close();
}

$summary = [
    "total" => 0,
    "open" => 0,
    "progress" => 0,
    "pending" => 0,
    "completed" => 0
];

$summaryResult = $conn->query(
    "
    SELECT
        COUNT(*) AS total,
        SUM(status IN ('Open','Assigned')) AS open_count,
        SUM(status = 'In Progress') AS progress_count,
        SUM(status IN (
            'Pending Material',
            'Pending Contractor'
        )) AS pending_count,
        SUM(status IN (
            'Completed',
            'Verified'
        )) AS completed_count
    FROM work_orders
    "
);

if ($summaryResult) {
    $row = $summaryResult->fetch_assoc();

    $summary["total"] =
        (int) ($row["total"] ?? 0);

    $summary["open"] =
        (int) ($row["open_count"] ?? 0);

    $summary["progress"] =
        (int) ($row["progress_count"] ?? 0);

    $summary["pending"] =
        (int) ($row["pending_count"] ?? 0);

    $summary["completed"] =
        (int) ($row["completed_count"] ?? 0);
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
    <title>Work Order Management | V23 PMS</title>
    <link rel="stylesheet" href="css/pms.css?v=4">
    <link
        rel="stylesheet"
        href="css/pms_work_orders.css?v=1"
    >
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
                <strong>V23 Malawa Ria</strong>
                <span>Property Management System</span>
            </div>
        </div>

        <nav class="pms-nav">
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_staff.php">Staff Management</a>
            <a href="admin_daily_work.php">Daily Work</a>
            <a
                href="admin_work_orders.php"
                class="active"
            >
                Work Order
            </a>
            <a href="admin_reports.php">Reports</a>
        </nav>

        <div class="pms-sidebar-footer">
            <a href="admin_logout.php">Logout Admin</a>
        </div>

    </aside>

    <main class="pms-main">

        <header class="pms-topbar">
            <div>
                <h1>Work Order Management</h1>
                <p>
                    Cipta, assign dan pantau kerja operasi.
                </p>
            </div>

            <a
                href="create_work_order.php"
                class="pms-button"
            >
                + Work Order Baharu
            </a>
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

        <section class="wo-summary-grid">

            <article class="wo-summary-card">
                <strong><?php echo $summary["total"]; ?></strong>
                <span>Jumlah Work Order</span>
            </article>

            <article class="wo-summary-card">
                <strong><?php echo $summary["open"]; ?></strong>
                <span>Open / Assigned</span>
            </article>

            <article class="wo-summary-card">
                <strong><?php echo $summary["progress"]; ?></strong>
                <span>In Progress</span>
            </article>

            <article class="wo-summary-card">
                <strong><?php echo $summary["pending"]; ?></strong>
                <span>Pending</span>
            </article>

            <article class="wo-summary-card">
                <strong><?php echo $summary["completed"]; ?></strong>
                <span>Completed / Verified</span>
            </article>

        </section>

        <section class="pms-card">

            <form method="GET" class="wo-filter-grid">

                <div class="pms-form-group">
                    <label for="search">Carian</label>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?php echo e($search); ?>"
                        placeholder="Rujukan, tajuk, lokasi..."
                    >
                </div>

                <div class="pms-form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">Semua</option>

                        <?php foreach (
                            $allowedStatuses as $status
                        ): ?>
                            <option
                                value="<?php echo e($status); ?>"
                                <?php
                                echo $statusFilter === $status
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo e($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pms-form-group">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <option value="">Semua</option>

                        <?php foreach (
                            $allowedPriorities as $priority
                        ): ?>
                            <option
                                value="<?php echo e($priority); ?>"
                                <?php
                                echo $priorityFilter === $priority
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo e($priority); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pms-form-group">
                    <label for="staff_id">Pekerja</label>
                    <select id="staff_id" name="staff_id">
                        <option value="">Semua</option>

                        <?php foreach ($staff as $member): ?>
                            <option
                                value="<?php
                                    echo (int) $member["id"];
                                ?>"
                                <?php
                                echo $staffFilter ===
                                    (int) $member["id"]
                                        ? "selected"
                                        : "";
                                ?>
                            >
                                <?php
                                echo e(
                                    (string) $member["full_name"]
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wo-actions">
                    <button
                        type="submit"
                        class="pms-button"
                    >
                        Tapis
                    </button>

                    <a
                        href="admin_work_orders.php"
                        class="pms-button-secondary"
                    >
                        Reset
                    </a>
                </div>

            </form>

        </section>

        <div class="pms-section-title">
            <h2>
                <?php echo count($workOrders); ?>
                Work Order
            </h2>
        </div>

        <section class="pms-card pms-table-wrap">

            <table class="pms-table">

                <thead>
                    <tr>
                        <th>Rujukan</th>
                        <th>Tajuk</th>
                        <th>Lokasi</th>
                        <th>Assigned To</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Due Date</th>
                        <th>Tindakan</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($workOrders) === 0): ?>

                    <tr>
                        <td colspan="8">
                            Tiada Work Order dijumpai.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach (
                        $workOrders as $workOrder
                    ): ?>

                        <?php
                        $workOrderId =
                            (int) $workOrder["id"];

                        $safeId =
                            "wo-" . $workOrderId;
                        ?>

                        <tr>
                            <td>
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

                                <?php if (
                                    !empty(
                                        $workOrder["complaint_id"]
                                    )
                                ): ?>
                                    <br>
                                    <small>
                                        Aduan:
                                        <?php
                                        echo e(
                                            (string)
                                            $workOrder[
                                                "complaint_id"
                                            ]
                                        );
                                        ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td>
                                <strong>
                                    <?php
                                    echo e(
                                        (string)
                                        $workOrder["title"]
                                    );
                                    ?>
                                </strong>

                                <br>

                                <small>
                                    <?php
                                    echo e(
                                        (string)
                                        $workOrder["category"]
                                    );
                                    ?>
                                </small>
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

                                <br>

                                <small>
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
                                </small>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    (
                                        $workOrder[
                                            "assigned_staff_name"
                                        ] ?? "Belum Assign"
                                    )
                                );
                                ?>
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

                            <td>
                                <button
                                    type="button"
                                    class="wo-view-button"
                                    onclick="toggleWorkOrder('<?php
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
                            <td colspan="8">

                                <div class="wo-detail">

                                    <div class="wo-detail-item">
                                        <strong>Complaint ID</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $workOrder[
                                                        "complaint_id"
                                                    ] ?? "-"
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="wo-detail-item">
                                        <strong>Contractor</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $workOrder[
                                                        "assigned_contractor"
                                                    ] ?? "-"
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="wo-detail-item">
                                        <strong>Scheduled Date</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $workOrder[
                                                        "scheduled_date"
                                                    ] ?? "-"
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="wo-detail-item">
                                        <strong>Estimated Cost</strong>
                                        <p>
                                            RM
                                            <?php
                                            echo e(
                                                number_format(
                                                    (float)
                                                    (
                                                        $workOrder[
                                                            "estimated_cost"
                                                        ] ?? 0
                                                    ),
                                                    2
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

                                </div>

                                <div class="wo-update-box">

                                    <h3>
                                        Kemas Kini Work Order
                                    </h3>

                                    <form
                                        method="POST"
                                        class="pms-form-grid"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php
                                                echo e($csrfToken);
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="work_order_id"
                                            value="<?php
                                                echo $workOrderId;
                                            ?>"
                                        >

                                        <div class="pms-form-group">
                                            <label>Assign Staff</label>
                                            <select
                                                name="assigned_staff_id"
                                            >
                                                <option value="">
                                                    Belum Assign
                                                </option>

                                                <?php foreach (
                                                    $staff as $member
                                                ): ?>
                                                    <option
                                                        value="<?php
                                                            echo (int)
                                                            $member["id"];
                                                        ?>"
                                                        <?php
                                                        echo
                                                            (int)
                                                            (
                                                                $workOrder[
                                                                    "assigned_staff_id"
                                                                ] ?? 0
                                                            ) ===
                                                            (int)
                                                            $member["id"]
                                                                ? "selected"
                                                                : "";
                                                        ?>
                                                    >
                                                        <?php
                                                        echo e(
                                                            (string)
                                                            $member[
                                                                "full_name"
                                                            ]
                                                        );
                                                        ?>
                                                        -
                                                        <?php
                                                        echo e(
                                                            (string)
                                                            $member["role"]
                                                        );
                                                        ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="pms-form-group">
                                            <label>Status</label>
                                            <select
                                                name="status"
                                                required
                                            >
                                                <?php foreach (
                                                    $allowedStatuses as
                                                    $status
                                                ): ?>
                                                    <option
                                                        value="<?php
                                                            echo e($status);
                                                        ?>"
                                                        <?php
                                                        echo
                                                            $workOrder[
                                                                "status"
                                                            ] === $status
                                                                ? "selected"
                                                                : "";
                                                        ?>
                                                    >
                                                        <?php
                                                        echo e($status);
                                                        ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="pms-form-group">
                                            <label>Actual Cost (RM)</label>
                                            <input
                                                type="number"
                                                name="actual_cost"
                                                min="0"
                                                step="0.01"
                                                value="<?php
                                                    echo e(
                                                        (string)
                                                        (
                                                            $workOrder[
                                                                "actual_cost"
                                                            ] ?? ""
                                                        )
                                                    );
                                                ?>"
                                            >
                                        </div>

                                        <div
                                            class="pms-form-group
                                            pms-form-group-full"
                                        >
                                            <label>Admin Remarks</label>
                                            <textarea
                                                name="admin_remarks"
                                                maxlength="3000"
                                            ><?php
                                                echo e(
                                                    (string)
                                                    (
                                                        $workOrder[
                                                            "admin_remarks"
                                                        ] ?? ""
                                                    )
                                                );
                                            ?></textarea>
                                        </div>

                                        <div
                                            class="pms-form-group
                                            pms-form-group-full"
                                        >
                                            <button
                                                type="submit"
                                                name="update_work_order"
                                                class="pms-button"
                                            >
                                                Simpan Kemas Kini
                                            </button>
                                        </div>

                                    </form>

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
function toggleWorkOrder(id) {
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

</body>
</html>
