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

function assetStatusClass(string $status): string
{
    return match ($status) {
        "Active" => "asset-status-active",
        "Under Maintenance" => "asset-status-maintenance",
        "Out of Service" => "asset-status-out",
        "Expired" => "asset-status-expired",
        "Disposed" => "asset-status-disposed",
        default => "asset-status-active"
    };
}

$search = trim((string) ($_GET["search"] ?? ""));
$categoryFilter = trim((string) ($_GET["category"] ?? ""));
$statusFilter = trim((string) ($_GET["status"] ?? ""));
$dueFilter = trim((string) ($_GET["due"] ?? ""));

$where = [];
$types = "";
$values = [];

if ($search !== "") {
    $where[] = "
        (
            asset_code LIKE ?
            OR asset_name LIKE ?
            OR location LIKE ?
            OR brand LIKE ?
            OR model LIKE ?
            OR serial_number LIKE ?
        )
    ";

    $like = "%" . $search . "%";

    for ($i = 0; $i < 6; $i++) {
        $types .= "s";
        $values[] = $like;
    }
}

if ($categoryFilter !== "") {
    $where[] = "asset_category = ?";
    $types .= "s";
    $values[] = $categoryFilter;
}

$allowedStatuses = [
    "Active",
    "Under Maintenance",
    "Out of Service",
    "Expired",
    "Disposed"
];

if (
    $statusFilter !== "" &&
    in_array($statusFilter, $allowedStatuses, true)
) {
    $where[] = "asset_status = ?";
    $types .= "s";
    $values[] = $statusFilter;
}

if ($dueFilter === "overdue") {
    $where[] = "
        next_service_date IS NOT NULL
        AND next_service_date < CURDATE()
    ";
} elseif ($dueFilter === "30days") {
    $where[] = "
        next_service_date IS NOT NULL
        AND next_service_date BETWEEN
            CURDATE()
            AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ";
}

$whereSql =
    count($where) > 0
        ? "WHERE " . implode(" AND ", $where)
        : "";

$stmt = $conn->prepare(
    "
    SELECT *
    FROM assets
    {$whereSql}
    ORDER BY
        CASE
            WHEN next_service_date IS NOT NULL
             AND next_service_date < CURDATE()
            THEN 0
            ELSE 1
        END,
        next_service_date ASC,
        asset_name ASC
    "
);

$assets = [];

if ($stmt) {
    if ($types !== "") {
        $stmt->bind_param($types, ...$values);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $assets[] = $row;
    }

    $stmt->close();
}

$categories = [];

$result = $conn->query(
    "
    SELECT DISTINCT asset_category
    FROM assets
    ORDER BY asset_category ASC
    "
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = (string) $row["asset_category"];
    }
}

$summary = [
    "total" => 0,
    "active" => 0,
    "maintenance" => 0,
    "overdue" => 0,
    "due30" => 0
];

$result = $conn->query(
    "
    SELECT
        COUNT(*) AS total,
        SUM(asset_status = 'Active') AS active_count,
        SUM(asset_status = 'Under Maintenance')
            AS maintenance_count,
        SUM(
            next_service_date IS NOT NULL
            AND next_service_date < CURDATE()
        ) AS overdue_count,
        SUM(
            next_service_date IS NOT NULL
            AND next_service_date BETWEEN
                CURDATE()
                AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ) AS due30_count
    FROM assets
    "
);

if ($result) {
    $row = $result->fetch_assoc();

    $summary["total"] = (int) ($row["total"] ?? 0);
    $summary["active"] = (int) ($row["active_count"] ?? 0);
    $summary["maintenance"] = (int) ($row["maintenance_count"] ?? 0);
    $summary["overdue"] = (int) ($row["overdue_count"] ?? 0);
    $summary["due30"] = (int) ($row["due30_count"] ?? 0);
}

$createdCode =
    trim((string) ($_GET["created"] ?? ""));

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
    <title>Asset Management | V23 PMS</title>
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
            <a href="admin_assets.php" class="active">Asset Management</a>
            <a href="admin_maintenance.php">Preventive Maintenance</a>
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
                <h1>Asset Management</h1>
                <p>
                    Daftar dan pantau aset sebenar V23 Malawa Ria.
                </p>
            </div>

            <a href="create_asset.php" class="pms-button">
                + Tambah Aset
            </a>
        </header>

        <?php if ($createdCode !== ""): ?>
            <div class="pms-alert-success">
                Aset
                <strong><?php echo e($createdCode); ?></strong>
                berjaya didaftarkan.
            </div>
        <?php endif; ?>

        <section class="asset-summary-grid">
            <article class="asset-summary-card">
                <strong><?php echo $summary["total"]; ?></strong>
                <span>Jumlah Aset</span>
            </article>

            <article class="asset-summary-card">
                <strong><?php echo $summary["active"]; ?></strong>
                <span>Aset Aktif</span>
            </article>

            <article class="asset-summary-card">
                <strong><?php echo $summary["maintenance"]; ?></strong>
                <span>Under Maintenance</span>
            </article>

            <article class="asset-summary-card">
                <strong><?php echo $summary["overdue"]; ?></strong>
                <span>Servis Overdue</span>
            </article>

            <article class="asset-summary-card">
                <strong><?php echo $summary["due30"]; ?></strong>
                <span>Due 30 Hari</span>
            </article>
        </section>

        <section class="pms-card">

            <form method="GET" class="asset-filter-grid">

                <div class="pms-form-group">
                    <label for="search">Carian</label>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?php echo e($search); ?>"
                        placeholder="Kod, nama, lokasi, model..."
                    >
                </div>

                <div class="pms-form-group">
                    <label for="category">Kategori</label>
                    <select id="category" name="category">
                        <option value="">Semua</option>
                        <?php foreach ($categories as $category): ?>
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
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">Semua</option>
                        <?php foreach ($allowedStatuses as $status): ?>
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
                    <label for="due">Servis</label>
                    <select id="due" name="due">
                        <option value="">Semua</option>
                        <option
                            value="overdue"
                            <?php
                            echo $dueFilter === "overdue"
                                ? "selected"
                                : "";
                            ?>
                        >
                            Overdue
                        </option>
                        <option
                            value="30days"
                            <?php
                            echo $dueFilter === "30days"
                                ? "selected"
                                : "";
                            ?>
                        >
                            Due 30 Hari
                        </option>
                    </select>
                </div>

                <div class="pms-form-group">
                    <button type="submit" class="pms-button">
                        Tapis
                    </button>
                </div>

            </form>

        </section>

        <div class="pms-section-title">
            <h2><?php echo count($assets); ?> Aset</h2>
        </div>

        <section class="pms-card pms-table-wrap">

            <table class="pms-table">

                <thead>
                    <tr>
                        <th>Asset Code</th>
                        <th>Nama Aset</th>
                        <th>Kategori</th>
                        <th>Lokasi</th>
                        <th>Kekerapan</th>
                        <th>Next Service</th>
                        <th>Status</th>
                        <th>Tindakan</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($assets) === 0): ?>
                    <tr>
                        <td colspan="8">
                            Tiada aset dijumpai.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($assets as $asset): ?>

                        <?php
                        $assetId = (int) $asset["id"];
                        $safeId = "asset-" . $assetId;
                        $nextService =
                            (string) ($asset["next_service_date"] ?? "");
                        ?>

                        <tr>
                            <td>
                                <span class="asset-code">
                                    <?php echo e((string) $asset["asset_code"]); ?>
                                </span>
                            </td>

                            <td>
                                <strong>
                                    <?php echo e((string) $asset["asset_name"]); ?>
                                </strong>
                            </td>

                            <td>
                                <?php echo e((string) $asset["asset_category"]); ?>
                            </td>

                            <td>
                                <?php echo e((string) $asset["location"]); ?>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    (string)
                                    $asset["maintenance_frequency"]
                                );
                                ?>
                            </td>

                            <td>
                                <?php if ($nextService === ""): ?>
                                    -
                                <?php else: ?>

                                    <?php
                                    $isOverdue =
                                        $nextService < date("Y-m-d");

                                    $isDue30 =
                                        !$isOverdue &&
                                        $nextService <=
                                        date(
                                            "Y-m-d",
                                            strtotime("+30 days")
                                        );
                                    ?>

                                    <span class="<?php
                                        echo $isOverdue
                                            ? "pm-overdue"
                                            : ($isDue30
                                                ? "pm-due-warning"
                                                : "");
                                    ?>">
                                        <?php
                                        echo e(
                                            date(
                                                "d/m/Y",
                                                strtotime($nextService)
                                            )
                                        );
                                        ?>
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <span
                                    class="pms-badge <?php
                                        echo e(
                                            assetStatusClass(
                                                (string)
                                                $asset["asset_status"]
                                            )
                                        );
                                    ?>"
                                >
                                    <?php
                                    echo e(
                                        (string)
                                        $asset["asset_status"]
                                    );
                                    ?>
                                </span>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    class="asset-view-button"
                                    onclick="toggleAsset('<?php
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

                                <div class="asset-detail-grid">

                                    <div class="asset-detail-item">
                                        <strong>Brand / Model</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                trim(
                                                    (string)
                                                    ($asset["brand"] ?? "") .
                                                    " " .
                                                    (string)
                                                    ($asset["model"] ?? "")
                                                ) ?: "-"
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="asset-detail-item">
                                        <strong>Serial Number</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $asset["serial_number"] ??
                                                    "-"
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="asset-detail-item">
                                        <strong>Vendor</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $asset["vendor_name"] ??
                                                    "-"
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="asset-detail-item">
                                        <strong>Warranty Expiry</strong>
                                        <p>
                                            <?php
                                            echo e(
                                                (string)
                                                (
                                                    $asset[
                                                        "warranty_expiry"
                                                    ] ?? "-"
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                    <div class="asset-detail-item asset-full">
                                        <strong>Notes</strong>
                                        <p>
                                            <?php
                                            echo nl2br(
                                                e(
                                                    (string)
                                                    (
                                                        $asset["notes"] ??
                                                        "-"
                                                    )
                                                )
                                            );
                                            ?>
                                        </p>
                                    </div>

                                </div>

                                <div style="margin-top:15px;display:flex;gap:10px;flex-wrap:wrap;">

                                    <a
                                        href="admin_maintenance.php?asset_id=<?php
                                            echo $assetId;
                                        ?>"
                                        class="pms-button"
                                    >
                                        Schedule Maintenance
                                    </a>

                                    <a
                                        href="create_work_order.php?asset_id=<?php
                                            echo $assetId;
                                        ?>"
                                        class="pms-button-secondary"
                                    >
                                        Create Work Order
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
function toggleAsset(id) {
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
