<?php

declare(strict_types=1);

session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

require_once "db.php";
require_once "staff_pwa_bootstrap.php";
require_once __DIR__ . "/cpms/includes/permission_engine.php";
$staffPwaBranding = cpmsStaffPwaBranding($conn);


function requireStaffSession(): void
{
    if (!isset($_SESSION["staff_id"])) {
        header("Location: cpms/login.php");
        exit();
    }

    $timeoutSeconds = 1800;

    if (
        isset($_SESSION["staff_last_activity"]) &&
        time() - (int) $_SESSION["staff_last_activity"] > $timeoutSeconds
    ) {
        session_unset();
        session_destroy();
        header("Location: cpms/login.php?expired=1");
        exit();
    }

    $_SESSION["staff_last_activity"] = time();
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function staffHistoryColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
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

function staffHistoryPropertyId(mysqli $conn, int $staffId): int
{
    $propertyId = (int) (
        $_SESSION["cpms_property_id"]
        ?? $_SESSION["staff_property_id"]
        ?? 0
    );

    if ($propertyId > 0) {
        return $propertyId;
    }

    if (staffHistoryColumnExists($conn, "staff", "property_id")) {
        $stmt = $conn->prepare(
            "SELECT property_id FROM staff WHERE id = ? LIMIT 1"
        );

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

function staffHistoryImageUrl(array $image, int $propertyId): string
{
    $imagePath = trim((string) ($image["image_path"] ?? ""));
    $fileName = rawurlencode(basename((string) ($image["image_name"] ?? "")));

    if ($imagePath === "" && $fileName === "") {
        return "";
    }

    $candidates = [];

    if ($imagePath !== "") {
        $candidates[] = ltrim($imagePath, "/");
    }

    if ($fileName !== "") {
        $candidates = array_merge($candidates, [
        "uploads/daily_work/property_" . $propertyId . "/" . $fileName,
        "uploads/daily_work/" . $fileName,
        "cpms/uploads/daily_work/property_" . $propertyId . "/" . $fileName,
        "cpms/uploads/daily_work/" . $fileName,
        ]);
    }

    foreach ($candidates as $candidate) {
        if (is_file(__DIR__ . "/" . rawurldecode($candidate))) {
            return $candidate;
        }
    }

    return (string) ($candidates[0] ?? "");
}


requireStaffSession();
cpmsRequire("daily_work.view", $conn);

function badgeClass(string $status): string
{
    return match ($status) {
        "Completed" => "pms-badge-completed",
        "Verified" => "pms-badge-verified",
        "Rejected" => "pms-badge-rejected",
        "In Progress" => "pms-badge-progress",
        default => "pms-badge-pending"
    };
}

$staffId = (int) $_SESSION["staff_id"];
$propertyId = staffHistoryPropertyId($conn, $staffId);

if ($propertyId < 1) {
    http_response_code(403);
    exit(
        "Akaun staff tidak mempunyai property_id yang sah. Sila log masuk semula melalui Unified Login atau semak assignment staff."
    );
}

$statusFilter =
    trim((string) ($_GET["status"] ?? ""));

$allowedStatuses = [
    "In Progress",
    "Completed",
    "Pending Material",
    "Pending Contractor",
    "Unable to Complete",
    "Verified",
    "Rejected"
];

$dailyWorkHasProperty = staffHistoryColumnExists($conn, "daily_work_logs", "property_id");
$workOrdersHasProperty = staffHistoryColumnExists($conn, "work_orders", "property_id");
$dailyImagesHasPath = staffHistoryColumnExists($conn, "daily_work_images", "image_path");

$where = "WHERE d.staff_id = ?";
$types = "i";
$values = [$staffId];

if ($dailyWorkHasProperty) {
    $where .= " AND (d.property_id = ? OR d.property_id IS NULL OR d.property_id = 0)";
    $types .= "i";
    $values[] = $propertyId;
}

if (
    $statusFilter !== "" &&
    in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $where .= " AND d.work_status = ?";
    $types .= "s";
    $values[] = $statusFilter;
}

$stmt = $conn->prepare(
    "
    SELECT
        d.*,
        w.work_order_reference,
        w.title AS work_order_title
    FROM daily_work_logs d
    LEFT JOIN work_orders w
        ON w.id = d.work_order_id
        " . ($workOrdersHasProperty && $dailyWorkHasProperty
            ? "AND w.property_id = d.property_id"
            : "") . "
    {$where}
    ORDER BY d.work_date DESC, d.created_at DESC
    "
);

$records = [];

if ($stmt) {
    $stmt->bind_param(
        $types,
        ...$values
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }

    $stmt->close();
}

$imagesByWork = [];

if (count($records) > 0) {
    $ids = array_map(
        static fn(array $row): int =>
            (int) $row["id"],
        $records
    );

    $placeholders =
        implode(",", array_fill(0, count($ids), "?"));

    $imageTypes =
        str_repeat("i", count($ids));

    $imageStmt = $conn->prepare(
        "
        SELECT
            daily_work_id,
            image_name,
            image_type
            " . ($dailyImagesHasPath ? ", image_path" : "") . "
        FROM daily_work_images
        WHERE daily_work_id IN ({$placeholders})
        ORDER BY id ASC
        "
    );

    if ($imageStmt) {
        $imageStmt->bind_param(
            $imageTypes,
            ...$ids
        );

        $imageStmt->execute();

        $imageResult =
            $imageStmt->get_result();

        while ($image = $imageResult->fetch_assoc()) {
            $workId = (int) $image["daily_work_id"];

            $imagesByWork[$workId][] = $image;
        }

        $imageStmt->close();
    }
}

$conn->close();

$createdReference =
    trim((string) ($_GET["created"] ?? ""));

?>

<!DOCTYPE html>
<html lang="ms">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
	    <title>Sejarah Kerja Saya | V23 PMS</title>
	    <link rel="stylesheet" href="css/pms.css?v=3">
	    <link rel="stylesheet" href="css/pms_daily_work.css?v=1">
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
            <a href="staff_dashboard.php">
                Dashboard
            </a>

            <a href="staff_work_form.php">
                Add Daily Work
            </a>

            <a
                href="staff_work_history.php"
                class="active"
            >
                My Work History
            </a>
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
                <h1>Sejarah Kerja Saya</h1>
                <p>
                    Lihat status pengesahan dan catatan supervisor.
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

        <?php if ($createdReference !== ""): ?>

            <div class="pms-alert-success">
                Rekod
                <strong>
                    <?php echo e($createdReference); ?>
                </strong>
                berjaya dihantar.
            </div>

        <?php endif; ?>

        <section class="pms-card">

            <form
                method="GET"
                class="daily-work-filter-form"
                style="grid-template-columns:1fr auto;"
            >

                <div class="pms-form-group">

                    <label for="status">
                        Filter Status
                    </label>

                    <select
                        id="status"
                        name="status"
                    >
                        <option value="">
                            Semua Status
                        </option>

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

                <div class="daily-work-inline-actions">
                    <button
                        type="submit"
                        class="pms-button"
                    >
                        Tapis
                    </button>

                    <a
                        href="staff_work_history.php"
                        class="pms-button-secondary"
                    >
                        Reset
                    </a>
                </div>

            </form>

        </section>

        <div class="pms-section-title">
            <h2>
                <?php echo count($records); ?>
                Rekod
            </h2>

            <a
                href="staff_work_form.php"
                class="pms-button"
            >
                + Kerja Baharu
            </a>
        </div>

        <section class="pms-card pms-table-wrap">

            <table class="pms-table">

                <thead>
                    <tr>
                        <th>Rujukan</th>
                        <th>Work Order</th>
                        <th>Tarikh</th>
                        <th>Kategori</th>
                        <th>Lokasi</th>
                        <th>Status</th>
                        <th>Tindakan</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($records) === 0): ?>

                    <tr>
                        <td colspan="7" class="daily-work-empty">
                            Tiada rekod dijumpai.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($records as $record): ?>

                        <?php
                        $workId = (int) $record["id"];
                        $safeId = "work-" . $workId;
                        ?>

                        <tr>
                            <td>
                                <span class="daily-work-reference">
                                    <?php
                                    echo e(
                                        (string)
                                        $record["work_reference"]
                                    );
                                    ?>
                                </span>
                            </td>

                            <td>
                                <?php if (
                                    !empty(
                                        $record[
                                            "work_order_reference"
                                        ]
                                    )
                                ): ?>
                                    <strong>
                                        <?php
                                        echo e(
                                            (string)
                                            $record[
                                                "work_order_reference"
                                            ]
                                        );
                                        ?>
                                    </strong>
                                    <br>
                                    <small>
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $record[
                                                    "work_order_title"
                                                ] ?? ""
                                            )
                                        );
                                        ?>
                                    </small>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    date(
                                        "d/m/Y",
                                        strtotime(
                                            (string)
                                            $record["work_date"]
                                        )
                                    )
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    $record["work_category"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    $record["block_location"]
                                );
                                ?>
                            </td>

                            <td>
                                <span
                                    class="pms-badge <?php
                                        echo e(
                                            badgeClass(
                                                (string)
                                                $record["work_status"]
                                            )
                                        );
                                    ?>"
                                >
                                    <?php
                                    echo e(
                                        (string)
                                        $record["work_status"]
                                    );
                                    ?>
                                </span>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    class="daily-work-toggle-button"
                                    onclick="toggleWork('<?php
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

                                <div class="daily-work-detail-grid">

                                    <div class="daily-work-detail-item">
                                        <strong>Masa</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $record["start_time"] ??
                                                    "-"
                                                )
                                            );
                                            ?>
                                            hingga
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $record["end_time"] ??
                                                    "-"
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="daily-work-detail-item">
                                        <strong>Lokasi Spesifik</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $record[
                                                        "specific_location"
                                                    ] ?? "-"
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div
                                        class="daily-work-detail-item
                                        daily-work-full"
                                    >
                                        <strong>
                                            Kerja Dilakukan
                                        </strong>

                                        <p>
                                            <?php
                                            echo nl2br(
                                                e(
                                                    (string)
                                                    $record[
                                                        "work_description"
                                                    ]
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div
                                        class="daily-work-detail-item
                                        daily-work-full"
                                    >
                                        <strong>
                                            Bahan / Alat
                                        </strong>

                                        <p>
                                            <?php
                                            echo nl2br(
                                                e(
                                                    (string)
                                                    (
                                                        $record[
                                                            "materials_used"
                                                        ] ?? "-"
                                                    )
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div
                                        class="daily-work-detail-item
                                        daily-work-full"
                                    >
                                        <strong>
                                            Masalah / Tindakan Susulan
                                        </strong>

                                        <p>
                                            <?php
                                            echo nl2br(
                                                e(
                                                    (string)
                                                    (
                                                        $record[
                                                            "issue_notes"
                                                        ] ?? "-"
                                                    )
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                </div>

                                <?php if (
                                    !empty(
                                        $record["supervisor_remarks"]
                                    )
                                ): ?>

                                    <div
                                        class="<?php
                                            echo
                                                $record["work_status"] ===
                                                "Rejected"
                                                    ? "daily-work-rejected-note"
                                                    : "pms-alert-success";
                                        ?>"
                                        style="margin-top:15px;"
                                    >
                                        <strong>
                                            Catatan Supervisor:
                                        </strong>

                                        <br>

                                        <?php
                                        echo nl2br(
                                            e(
                                                (string)
                                                $record[
                                                    "supervisor_remarks"
                                                ]
                                            )
                                        );
                                        ?>
                                    </div>

                                <?php endif; ?>

                                <?php
                                $images =
                                    $imagesByWork[$workId] ?? [];
                                ?>

                                <?php if (count($images) > 0): ?>

                                    <div class="daily-work-gallery">

                                        <?php foreach (
                                            $images as $image
                                        ): ?>
                                            <?php
                                            $imageUrl = staffHistoryImageUrl(
                                                $image,
                                                $propertyId
                                            );
                                            ?>

                                            <a
                                                href="<?php echo e($imageUrl); ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="daily-work-image-card"
                                            >
                                                <img
                                                    src="<?php echo e($imageUrl); ?>"
                                                    alt="<?php
                                                        echo e(
                                                            (string)
                                                            $image[
                                                                "image_type"
                                                            ]
                                                        );
                                                    ?>"
                                                >

                                                <span>
                                                    <?php
                                                    echo e(
                                                        (string)
                                                        $image[
                                                            "image_type"
                                                        ]
                                                    );
                                                    ?>
                                                </span>
                                            </a>

                                        <?php endforeach; ?>

                                    </div>

                                <?php endif; ?>

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
function toggleWork(id) {
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
