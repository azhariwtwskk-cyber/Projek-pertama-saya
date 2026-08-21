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
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

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

function adminDailyWorkColumnExists(mysqli $conn, string $table, string $column): bool
{
    $escaped = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escaped}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

/**
 * Same candidate-list resolution as cpms/property_portal/
 * daily_work_review.php's dailyWorkImageUrl() and staff_work_history.php's
 * staffHistoryImageUrl() — this page previously used a naive, unqualified
 * "uploads/daily_work/<basename>" href with no image_path check and no
 * property subfolder, which 404s for any evidence submitted through the
 * legacy Staff Web Portal (uploads/daily_work/property_<id>/...). Kept as
 * a local function (matching this codebase's existing per-legacy-page
 * pattern) rather than a shared include, since this file has no
 * require_once chain to cpms/api/v1/services.php.
 */
function adminDailyWorkImageUrl(array $image, int $propertyId): string
{
    $path = trim((string) ($image["image_path"] ?? ""));
    $name = basename((string) ($image["image_name"] ?? ""));
    if ($path === "" && $name === "") {
        return "";
    }
    $candidates = [];
    if ($path !== "") {
        $candidates[] = ltrim($path, "/");
    }
    if ($name !== "") {
        $candidates[] = "uploads/daily_work/property_" . $propertyId . "/" . $name;
        $candidates[] = "uploads/daily_work/" . $name;
        $candidates[] = "cpms/uploads/daily_work/property_" . $propertyId . "/" . $name;
        $candidates[] = "cpms/uploads/daily_work/" . $name;
    }
    foreach ($candidates as $candidate) {
        if (is_file(__DIR__ . "/" . rawurldecode($candidate))) {
            return implode("/", array_map("rawurlencode", explode("/", $candidate)));
        }
    }
    return (string) ($candidates[0] ?? "");
}

if (
    !isset($_SESSION["admin_daily_csrf"]) ||
    !is_string($_SESSION["admin_daily_csrf"])
) {
    $_SESSION["admin_daily_csrf"] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["admin_daily_csrf"];
$successMessage = "";
$errorMessage = "";

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["review_work"])
) {
    $postedToken =
        (string) ($_POST["csrf_token"] ?? "");

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage =
            "Permintaan tidak sah. Sila muat semula halaman.";
    } else {
        $workId = (int) ($_POST["work_id"] ?? 0);
        $decision =
            trim((string) ($_POST["decision"] ?? ""));
        $remarks =
            trim((string) ($_POST["supervisor_remarks"] ?? ""));

        if (
            $workId < 1 ||
            !in_array(
                $decision,
                ["Verified", "Rejected"],
                true
            )
        ) {
            $errorMessage =
                "Maklumat pengesahan tidak sah.";
        } elseif (
            $decision === "Rejected" &&
            $remarks === ""
        ) {
            $errorMessage =
                "Catatan diperlukan jika kerja ditolak.";
        } else {
            $linkedStmt = $conn->prepare(
                "
                SELECT
                    work_order_id,
                    work_reference
                FROM daily_work_logs
                WHERE id = ?
                LIMIT 1
                "
            );

            $linkedWorkOrderId = 0;
            $dailyReference = "";

            if ($linkedStmt) {
                $linkedStmt->bind_param(
                    "i",
                    $workId
                );

                $linkedStmt->execute();

                $linkedRow =
                    $linkedStmt
                        ->get_result()
                        ->fetch_assoc();

                $linkedWorkOrderId =
                    (int) (
                        $linkedRow["work_order_id"] ?? 0
                    );

                $dailyReference =
                    (string) (
                        $linkedRow["work_reference"] ?? ""
                    );

                $linkedStmt->close();
            }

            $stmt = $conn->prepare(
                "
                UPDATE daily_work_logs
                SET
                    work_status = ?,
                    supervisor_remarks = ?,
                    verified_by = ?,
                    verified_at = NOW()
                WHERE id = ?
                "
            );

            if ($stmt) {
                $adminName =
                    (string) $_SESSION["admin"];

                $stmt->bind_param(
                    "sssi",
                    $decision,
                    $remarks,
                    $adminName,
                    $workId
                );

                if ($stmt->execute()) {
                    if ($linkedWorkOrderId > 0) {
                        $workOrderStatus =
                            $decision === "Verified"
                                ? "Verified"
                                : "In Progress";

                        $workOrderStmt = $conn->prepare(
                            "
                            UPDATE work_orders
                            SET
                                status = ?,
                                verified_by =
                                    CASE
                                        WHEN ? = 'Verified'
                                        THEN ?
                                        ELSE NULL
                                    END,
                                verified_at =
                                    CASE
                                        WHEN ? = 'Verified'
                                        THEN NOW()
                                        ELSE NULL
                                    END
                            WHERE id = ?
                            "
                        );

                        if ($workOrderStmt) {
                            $adminName =
                                (string) $_SESSION["admin"];

                            $workOrderStmt->bind_param(
                                "ssssi",
                                $workOrderStatus,
                                $workOrderStatus,
                                $adminName,
                                $workOrderStatus,
                                $linkedWorkOrderId
                            );

                            $workOrderStmt->execute();
                            $workOrderStmt->close();

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
                                VALUES (?, NULL, ?, ?, ?)
                                "
                            );

                            if ($historyStmt) {
                                $linkedRemark =
                                    "Daily Work " .
                                    $dailyReference .
                                    " " .
                                    $decision .
                                    (
                                        $remarks !== ""
                                            ? ": " . $remarks
                                            : ""
                                    );

                                $historyStmt->bind_param(
                                    "isss",
                                    $linkedWorkOrderId,
                                    $workOrderStatus,
                                    $linkedRemark,
                                    $adminName
                                );

                                $historyStmt->execute();
                                $historyStmt->close();
                            }
                        }
                    }

                    $successMessage =
                        "Rekod kerja berjaya dikemas kini.";
                } else {
                    $errorMessage =
                        "Rekod kerja tidak dapat dikemas kini.";
                }

                $stmt->close();
            }
        }
    }
}

$search =
    trim((string) ($_GET["search"] ?? ""));
$statusFilter =
    trim((string) ($_GET["status"] ?? ""));
$categoryFilter =
    trim((string) ($_GET["category"] ?? ""));
$dateFilter =
    trim((string) ($_GET["work_date"] ?? ""));

$allowedStatuses = [
    "In Progress",
    "Completed",
    "Pending Material",
    "Pending Contractor",
    "Unable to Complete",
    "Verified",
    "Rejected"
];

$where = [];
$types = "";
$values = [];

if ($search !== "") {
    $where[] = "
        (
            d.work_reference LIKE ?
            OR w.work_order_reference LIKE ?
            OR s.full_name LIKE ?
            OR d.block_location LIKE ?
            OR d.specific_location LIKE ?
            OR d.work_description LIKE ?
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
    in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $where[] = "d.work_status = ?";
    $types .= "s";
    $values[] = $statusFilter;
}

if ($categoryFilter !== "") {
    $where[] = "d.work_category = ?";
    $types .= "s";
    $values[] = $categoryFilter;
}

if (
    $dateFilter !== "" &&
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)
) {
    $where[] = "d.work_date = ?";
    $types .= "s";
    $values[] = $dateFilter;
}

$whereSql =
    count($where) > 0
        ? "WHERE " . implode(" AND ", $where)
        : "";

$stmt = $conn->prepare(
    "
    SELECT
        d.*,
        s.full_name,
        s.role,
        w.work_order_reference,
        w.title AS work_order_title
    FROM daily_work_logs d
    INNER JOIN staff s
        ON s.id = d.staff_id
    LEFT JOIN work_orders w
        ON w.id = d.work_order_id
    {$whereSql}
    ORDER BY
        d.work_date DESC,
        d.created_at DESC
    "
);

$records = [];

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
        $records[] = $row;
    }

    $stmt->close();
}

$categories = [];

$result = $conn->query(
    "
    SELECT DISTINCT work_category
    FROM daily_work_logs
    ORDER BY work_category ASC
    "
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] =
            (string) $row["work_category"];
    }
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

    $idTypes =
        str_repeat("i", count($ids));

    $hasAdminImagePath = adminDailyWorkColumnExists($conn, "daily_work_images", "image_path");
    $adminImagePathSelect = $hasAdminImagePath ? "image_path," : "'' AS image_path,";
    $imageStmt = $conn->prepare(
        "
        SELECT
            daily_work_id,
            image_name,
            {$adminImagePathSelect}
            image_type
        FROM daily_work_images
        WHERE daily_work_id IN ({$placeholders})
        ORDER BY id ASC
        "
    );

    if ($imageStmt) {
        $imageStmt->bind_param(
            $idTypes,
            ...$ids
        );

        $imageStmt->execute();

        $imageResult =
            $imageStmt->get_result();

        while ($image = $imageResult->fetch_assoc()) {
            $workId =
                (int) $image["daily_work_id"];

            $imagesByWork[$workId][] = $image;
        }

        $imageStmt->close();
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
    <title>Daily Work Management | V23 PMS</title>
    <link rel="stylesheet" href="css/pms.css?v=3">
    <link rel="stylesheet" href="css/pms_daily_work.css?v=1">
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
            <a href="admin_dashboard.php">
                Dashboard
            </a>
            <a href="admin_staff.php">
                Staff Management
            </a>
            <a
                href="admin_daily_work.php"
                class="active"
            >
                Daily Work
            </a>
            <a href="admin_reports.php">
                Reports
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
                <h1>Daily Work Management</h1>
                <p>
                    Semak gambar, sahkan kerja dan sediakan
                    data laporan bulanan.
                </p>
            </div>

            <div class="pms-user-chip">
                Admin:
                <?php
                echo e(
                    (string) $_SESSION["admin"]
                );
                ?>
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

        <section class="pms-card">

            <form
                method="GET"
                class="daily-work-filter-form"
            >

                <div class="pms-form-group">
                    <label for="search">Carian</label>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?php echo e($search); ?>"
                        placeholder="Rujukan, pekerja atau lokasi"
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
                    <label for="category">Kategori</label>
                    <select id="category" name="category">
                        <option value="">Semua</option>

                        <?php foreach (
                            $categories as $category
                        ): ?>
                            <option
                                value="<?php echo e($category); ?>"
                                <?php
                                echo $categoryFilter === $category
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo e($category); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pms-form-group">
                    <label for="work_date">Tarikh</label>
                    <input
                        type="date"
                        id="work_date"
                        name="work_date"
                        value="<?php echo e($dateFilter); ?>"
                    >
                </div>

                <div class="daily-work-inline-actions">
                    <button
                        type="submit"
                        class="pms-button"
                    >
                        Tapis
                    </button>

                    <a
                        href="admin_daily_work.php"
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
                Rekod Kerja
            </h2>
        </div>

        <section class="pms-card pms-table-wrap">

            <table class="pms-table">

                <thead>
                    <tr>
                        <th>Rujukan</th>
                        <th>Work Order</th>
                        <th>Pekerja</th>
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
                        <td colspan="8" class="daily-work-empty">
                            Tiada rekod dijumpai.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($records as $record): ?>

                        <?php
                        $workId = (int) $record["id"];
                        $safeId = "admin-work-" . $workId;
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
                                    Routine Work
                                <?php endif; ?>
                            </td>

                            <td>
                                <strong>
                                    <?php
                                    echo e(
                                        (string) $record["full_name"]
                                    );
                                    ?>
                                </strong>

                                <br>

                                <small>
                                    <?php
                                    echo e(
                                        (string) $record["role"]
                                    );
                                    ?>
                                </small>
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
                                    onclick="toggleAdminWork('<?php
                                        echo e($safeId);
                                    ?>')"
                                >
                                    Semak
                                </button>
                            </td>
                        </tr>

                        <tr
                            id="<?php echo e($safeId); ?>"
                            style="display:none;"
                        >
                            <td colspan="8">

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
                                            $adminImageUrl = adminDailyWorkImageUrl(
                                                $image,
                                                (int) ($record["property_id"] ?? 0)
                                            );
                                            ?>

                                            <a
                                                href="<?php echo e($adminImageUrl); ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="daily-work-image-card"
                                            >
                                                <img
                                                    src="<?php echo e($adminImageUrl); ?>"
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

                                <div class="daily-work-review-box">

                                    <h3>
                                        Supervisor Verification
                                    </h3>

                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php
                                                echo e($csrfToken);
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="work_id"
                                            value="<?php echo $workId; ?>"
                                        >

                                        <div class="pms-form-group">

                                            <label>
                                                Catatan Supervisor
                                            </label>

                                            <textarea
                                                name="supervisor_remarks"
                                                maxlength="2000"
                                                placeholder="Catatan pengesahan atau sebab penolakan..."
                                            ><?php
                                                echo e(
                                                    (string)
                                                    (
                                                        $record[
                                                            "supervisor_remarks"
                                                        ] ?? ""
                                                    )
                                                );
                                            ?></textarea>

                                        </div>

                                        <div class="daily-work-review-actions">

                                            <button
                                                type="submit"
                                                name="review_work"
                                                value="1"
                                                class="pms-button"
                                                onclick="this.form.decision.value='Verified'"
                                            >
                                                Verify Work
                                            </button>

                                            <button
                                                type="submit"
                                                name="review_work"
                                                value="1"
                                                class="pms-button-danger"
                                                onclick="this.form.decision.value='Rejected'"
                                            >
                                                Reject Work
                                            </button>

                                            <input
                                                type="hidden"
                                                name="decision"
                                                value=""
                                            >

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
function toggleAdminWork(id) {
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
