<?php

declare(strict_types=1);

session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

require_once "db.php";

/*
|--------------------------------------------------------------------------
| Lindungi halaman admin
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["admin"])) {
    header("Location: admin_login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Fungsi bantuan
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

function columnExists(
    mysqli $conn,
    string $tableName,
    string $columnName
): bool {
    $sql = "
        SELECT COUNT(*) AS total
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
        AND table_name = ?
        AND column_name = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "ss",
        $tableName,
        $columnName
    );

    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function formatDateTime(?string $date): string
{
    if ($date === null || trim($date) === "") {
        return "-";
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return date("d/m/Y h:i A", $timestamp);
}

/*
|--------------------------------------------------------------------------
| Filter laporan
|--------------------------------------------------------------------------
*/

$dateFrom = trim(
    (string) ($_GET["date_from"] ?? "")
);

$dateTo = trim(
    (string) ($_GET["date_to"] ?? "")
);

$statusFilter = trim(
    (string) ($_GET["status"] ?? "")
);

$categoryFilter = trim(
    (string) ($_GET["category"] ?? "")
);

$blockFilter = trim(
    (string) ($_GET["block"] ?? "")
);

$allowedStatuses = [
    "Pending",
    "In Progress",
    "Resolved",
    "Closed"
];

$allowedBlocks = ["A", "B", "C", "D", "E"];

$hasCreatedAt = columnExists(
    $conn,
    "complaints",
    "created_at"
);

/*
|--------------------------------------------------------------------------
| Dapatkan kategori unik
|--------------------------------------------------------------------------
*/

$categories = [];

$categoryResult = $conn->query(
    "
    SELECT DISTINCT category
    FROM complaints
    WHERE category IS NOT NULL
    AND category <> ''
    ORDER BY category ASC
    "
);

if ($categoryResult) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categories[] = (string) $row["category"];
    }
}

/*
|--------------------------------------------------------------------------
| Bina query laporan
|--------------------------------------------------------------------------
*/

$whereParts = [];
$types = "";
$values = [];

if (
    $statusFilter !== "" &&
    in_array($statusFilter, $allowedStatuses, true)
) {
    $whereParts[] = "status = ?";
    $types .= "s";
    $values[] = $statusFilter;
}

if (
    $categoryFilter !== "" &&
    in_array($categoryFilter, $categories, true)
) {
    $whereParts[] = "category = ?";
    $types .= "s";
    $values[] = $categoryFilter;
}

if (
    $blockFilter !== "" &&
    in_array($blockFilter, $allowedBlocks, true)
) {
    $whereParts[] = "block = ?";
    $types .= "s";
    $values[] = $blockFilter;
}

if ($hasCreatedAt && $dateFrom !== "") {
    $whereParts[] = "DATE(created_at) >= ?";
    $types .= "s";
    $values[] = $dateFrom;
}

if ($hasCreatedAt && $dateTo !== "") {
    $whereParts[] = "DATE(created_at) <= ?";
    $types .= "s";
    $values[] = $dateTo;
}

$whereSql = "";

if (count($whereParts) > 0) {
    $whereSql = "WHERE " . implode(
        " AND ",
        $whereParts
    );
}

$orderColumn = $hasCreatedAt
    ? "created_at DESC"
    : "complaint_id DESC";

$sql = "
    SELECT *
    FROM complaints
    {$whereSql}
    ORDER BY {$orderColumn}
";

$stmt = $conn->prepare($sql);

$complaints = [];

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
        $complaints[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Statistik laporan
|--------------------------------------------------------------------------
*/

$total = count($complaints);

$statusCounts = [
    "Pending" => 0,
    "In Progress" => 0,
    "Resolved" => 0,
    "Closed" => 0
];

$categoryCounts = [];
$blockCounts = [];

foreach ($complaints as $complaint) {
    $status = (string) (
        $complaint["status"] ?? "Pending"
    );

    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }

    $category = trim(
        (string) ($complaint["category"] ?? "")
    );

    if ($category !== "") {
        if (!isset($categoryCounts[$category])) {
            $categoryCounts[$category] = 0;
        }

        $categoryCounts[$category]++;
    }

    $block = trim(
        (string) ($complaint["block"] ?? "")
    );

    if ($block !== "") {
        if (!isset($blockCounts[$block])) {
            $blockCounts[$block] = 0;
        }

        $blockCounts[$block]++;
    }
}

arsort($categoryCounts);
ksort($blockCounts);

$completed =
    $statusCounts["Resolved"] +
    $statusCounts["Closed"];

$resolutionRate =
    $total > 0
        ? round(($completed / $total) * 100)
        : 0;

$reportGeneratedAt = date("d/m/Y h:i A");

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
        Laporan Aduan | V23 Malawa Ria
    </title>

    <link
        rel="stylesheet"
        href="css/style.css?v=50"
    >

    <style>
        .report-page {
            margin: 0;
            background: #eeeeee;
            color: #222222;
            font-family: Arial, sans-serif;
        }

        .report-container {
            width: 94%;
            max-width: 1200px;
            margin: 30px auto;
        }

        .report-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .report-toolbar-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .report-toolbar a,
        .report-toolbar button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 18px;
            border: none;
            border-radius: 7px;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
        }

        .report-back {
            background: #333333;
            color: #ffffff;
        }

        .report-print {
            background: #b59b20;
            color: #ffffff;
        }

        .report-document {
            background: #ffffff;
            padding: 35px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .report-document-header {
            display: grid;
            grid-template-columns: 120px 1fr auto;
            align-items: center;
            gap: 20px;
            padding-bottom: 22px;
            border-bottom: 3px solid #b59b20;
        }

        .report-document-logo {
            width: 110px;
            height: auto;
            object-fit: contain;
        }

        .report-document-title h1 {
            margin: 0 0 7px;
            font-size: 28px;
            color: #2f1c14;
            text-align: left;
        }

        .report-document-title h2 {
            margin: 0 0 5px;
            color: #8f7918;
            font-size: 19px;
        }

        .report-document-title p {
            margin: 0;
            color: #666666;
            line-height: 1.5;
        }

        .report-meta {
            text-align: right;
            color: #555555;
            font-size: 13px;
            line-height: 1.7;
        }

        .report-filter-box {
            margin: 24px 0;
            padding: 20px;
            background: #fffaf0;
            border: 1px solid #eadfb8;
            border-radius: 10px;
        }

        .report-filter-form {
            display: grid;
            grid-template-columns:
                repeat(5, minmax(120px, 1fr))
                auto;
            gap: 12px;
            align-items: end;
        }

        .report-filter-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .report-filter-group label {
            font-size: 13px;
            font-weight: bold;
            color: #444444;
        }

        .report-filter-group input,
        .report-filter-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #cccccc;
            border-radius: 7px;
            background: #ffffff;
        }

        .report-filter-actions {
            display: flex;
            gap: 8px;
        }

        .report-filter-actions button,
        .report-filter-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 14px;
            border: none;
            border-radius: 7px;
            text-decoration: none;
            font-weight: bold;
            white-space: nowrap;
        }

        .report-filter-submit {
            background: #b59b20;
            color: #ffffff;
            cursor: pointer;
        }

        .report-filter-reset {
            background: #555555;
            color: #ffffff;
        }

        .report-summary {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 14px;
            margin: 25px 0;
        }

        .report-summary-card {
            padding: 18px 12px;
            border: 1px solid #e2e2e2;
            border-radius: 10px;
            background: #fafafa;
            text-align: center;
        }

        .report-summary-card strong {
            display: block;
            margin-bottom: 7px;
            color: #b59b20;
            font-size: 30px;
        }

        .report-summary-card span {
            color: #555555;
            font-size: 12px;
            font-weight: bold;
        }

        .report-analysis-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 20px;
            margin: 25px 0;
        }

        .report-analysis-card {
            padding: 20px;
            border: 1px solid #dddddd;
            border-radius: 10px;
        }

        .report-analysis-card h3 {
            margin: 0 0 18px;
            color: #333333;
        }

        .report-bar-row {
            display: grid;
            grid-template-columns: minmax(130px, 210px) 1fr 36px;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
        }

        .report-bar-label {
            overflow: hidden;
            color: #444444;
            font-size: 12px;
            font-weight: bold;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .report-bar-track {
            height: 24px;
            overflow: hidden;
            border-radius: 20px;
            background: #eeeeee;
        }

        .report-bar-fill {
            height: 100%;
            width: var(--width);
            border-radius: 20px;
            background: linear-gradient(
                90deg,
                #c7a35b,
                #8f6b2f
            );
        }

        .report-bar-value {
            text-align: right;
            font-size: 12px;
            font-weight: bold;
        }

        .report-section-heading {
            margin: 28px 0 0;
            padding: 13px 16px;
            background: #333333;
            color: #ffffff;
            font-size: 16px;
        }

        .report-table-wrap {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-table th,
        .report-table td {
            padding: 10px;
            border: 1px solid #cccccc;
            text-align: left;
            vertical-align: top;
            font-size: 12px;
        }

        .report-table th {
            background: #f2f2f2;
        }

        .report-table tbody tr:nth-child(even) {
            background: #fafafa;
        }

        .report-empty {
            padding: 25px;
            text-align: center;
            color: #777777;
        }

        .report-signature {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            margin-top: 70px;
        }

        .report-signature-box {
            text-align: center;
        }

        .report-signature-line {
            border-top: 1px solid #333333;
            margin-bottom: 10px;
        }

        .report-footer-note {
            margin-top: 35px;
            padding-top: 15px;
            border-top: 1px solid #dddddd;
            color: #777777;
            font-size: 11px;
            text-align: center;
        }

        @media screen and (max-width: 950px) {
            .report-filter-form {
                grid-template-columns: repeat(2, 1fr);
            }

            .report-summary {
                grid-template-columns: repeat(3, 1fr);
            }

            .report-analysis-grid {
                grid-template-columns: 1fr;
            }
        }

        @media screen and (max-width: 600px) {
            .report-container {
                width: 96%;
                margin: 15px auto;
            }

            .report-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .report-toolbar-group {
                flex-direction: column;
            }

            .report-toolbar a,
            .report-toolbar button {
                width: 100%;
            }

            .report-document {
                padding: 22px 15px;
            }

            .report-document-header {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .report-document-logo {
                margin: 0 auto;
            }

            .report-document-title h1 {
                text-align: center;
                font-size: 23px;
            }

            .report-meta {
                text-align: center;
            }

            .report-filter-form {
                grid-template-columns: 1fr;
            }

            .report-filter-actions {
                flex-direction: column;
            }

            .report-summary {
                grid-template-columns: repeat(2, 1fr);
            }

            .report-bar-row {
                grid-template-columns: 1fr 35px;
            }

            .report-bar-label {
                grid-column: 1 / -1;
                white-space: normal;
            }

            .report-signature {
                grid-template-columns: 1fr;
                gap: 55px;
            }
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }

            .report-page {
                background: #ffffff !important;
            }

            .no-print,
            .report-filter-box {
                display: none !important;
            }

            .report-container {
                width: 100%;
                max-width: none;
                margin: 0;
            }

            .report-document {
                padding: 0;
                box-shadow: none;
            }

            .report-summary-card,
            .report-analysis-card,
            .report-table tr {
                break-inside: avoid;
            }

            .report-document-header,
            .report-summary-card,
            .report-analysis-card,
            .report-section-heading,
            .report-bar-fill {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }

            .report-table th,
            .report-table td {
                font-size: 9px;
                padding: 6px;
            }
        }
    </style>

</head>

<body class="report-page">

    <main class="report-container">

        <div class="report-toolbar no-print">

            <div class="report-toolbar-group">

                <a
                    href="admin_dashboard.php"
                    class="report-back"
                >
                    ← Kembali ke Dashboard
                </a>

            </div>

            <div class="report-toolbar-group">

                <button
                    type="button"
                    class="report-print"
                    onclick="window.print()"
                >
                    Cetak / Simpan sebagai PDF
                </button>

            </div>

        </div>

        <article class="report-document">

            <header class="report-document-header">

                <img
                    src="images/logo.png?v=2"
                    alt="V23 Malawa Ria Logo"
                    class="report-document-logo"
                >

                <div class="report-document-title">

                    <h1>
                        LAPORAN PENGURUSAN ADUAN
                    </h1>

                    <h2>
                        V23 Malawa Ria Apartment
                    </h2>

                    <p>
                        Pejabat Pengurusan V23 Malawa Ria,
                        Jalan KKIP Selatan,
                        88450 Kota Kinabalu, Sabah
                    </p>

                </div>

                <div class="report-meta">

                    <strong>
                        Tarikh Dijana
                    </strong>
                    <br>

                    <?php echo e($reportGeneratedAt); ?>

                    <br><br>

                    <strong>
                        Dijana Oleh
                    </strong>
                    <br>

                    <?php
                    echo e(
                        (string) $_SESSION["admin"]
                    );
                    ?>

                </div>

            </header>

            <section class="report-filter-box no-print">

                <form
                    method="GET"
                    action="admin_reports.php"
                    class="report-filter-form"
                >

                    <div class="report-filter-group">

                        <label for="date_from">
                            Tarikh Mula
                        </label>

                        <input
                            type="date"
                            id="date_from"
                            name="date_from"
                            value="<?php echo e($dateFrom); ?>"
                            <?php
                            echo
                                !$hasCreatedAt
                                    ? "disabled"
                                    : "";
                            ?>
                        >

                    </div>

                    <div class="report-filter-group">

                        <label for="date_to">
                            Tarikh Akhir
                        </label>

                        <input
                            type="date"
                            id="date_to"
                            name="date_to"
                            value="<?php echo e($dateTo); ?>"
                            <?php
                            echo
                                !$hasCreatedAt
                                    ? "disabled"
                                    : "";
                            ?>
                        >

                    </div>

                    <div class="report-filter-group">

                        <label for="status">
                            Status
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
                                    echo
                                        $statusFilter === $status
                                            ? "selected"
                                            : "";
                                    ?>
                                >
                                    <?php echo e($status); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="report-filter-group">

                        <label for="category">
                            Kategori
                        </label>

                        <select
                            id="category"
                            name="category"
                        >

                            <option value="">
                                Semua Kategori
                            </option>

                            <?php foreach (
                                $categories as $category
                            ): ?>

                                <option
                                    value="<?php echo e($category); ?>"
                                    <?php
                                    echo
                                        $categoryFilter === $category
                                            ? "selected"
                                            : "";
                                    ?>
                                >
                                    <?php echo e($category); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="report-filter-group">

                        <label for="block">
                            Blok
                        </label>

                        <select
                            id="block"
                            name="block"
                        >

                            <option value="">
                                Semua Blok
                            </option>

                            <?php foreach (
                                $allowedBlocks as $block
                            ): ?>

                                <option
                                    value="<?php echo e($block); ?>"
                                    <?php
                                    echo
                                        $blockFilter === $block
                                            ? "selected"
                                            : "";
                                    ?>
                                >
                                    Blok <?php echo e($block); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="report-filter-actions">

                        <button
                            type="submit"
                            class="report-filter-submit"
                        >
                            Tapis
                        </button>

                        <a
                            href="admin_reports.php"
                            class="report-filter-reset"
                        >
                            Reset
                        </a>

                    </div>

                </form>

            </section>

            <section class="report-summary">

                <div class="report-summary-card">
                    <strong><?php echo $total; ?></strong>
                    <span>Jumlah Aduan</span>
                </div>

                <div class="report-summary-card">
                    <strong><?php echo $statusCounts["Pending"]; ?></strong>
                    <span>Pending</span>
                </div>

                <div class="report-summary-card">
                    <strong><?php echo $statusCounts["In Progress"]; ?></strong>
                    <span>In Progress</span>
                </div>

                <div class="report-summary-card">
                    <strong><?php echo $statusCounts["Resolved"]; ?></strong>
                    <span>Resolved</span>
                </div>

                <div class="report-summary-card">
                    <strong><?php echo $statusCounts["Closed"]; ?></strong>
                    <span>Closed</span>
                </div>

                <div class="report-summary-card">
                    <strong><?php echo $resolutionRate; ?>%</strong>
                    <span>Kadar Penyelesaian</span>
                </div>

            </section>

            <section class="report-analysis-grid">

                <div class="report-analysis-card">

                    <h3>
                        Aduan Mengikut Kategori
                    </h3>

                    <?php
                    $maxCategory =
                        count($categoryCounts) > 0
                            ? max($categoryCounts)
                            : 1;
                    ?>

                    <?php if (
                        count($categoryCounts) > 0
                    ): ?>

                        <?php foreach (
                            array_slice(
                                $categoryCounts,
                                0,
                                8,
                                true
                            ) as
                            $category =>
                            $count
                        ): ?>

                            <?php
                            $width =
                                ($count / max(1, $maxCategory))
                                * 100;
                            ?>

                            <div class="report-bar-row">

                                <div
                                    class="report-bar-label"
                                    title="<?php echo e($category); ?>"
                                >
                                    <?php echo e($category); ?>
                                </div>

                                <div class="report-bar-track">

                                    <div
                                        class="report-bar-fill"
                                        style="
                                            --width:
                                            <?php
                                            echo round($width, 2);
                                            ?>%;
                                        "
                                    ></div>

                                </div>

                                <div class="report-bar-value">
                                    <?php echo (int) $count; ?>
                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="report-empty">
                            Tiada data kategori.
                        </div>

                    <?php endif; ?>

                </div>

                <div class="report-analysis-card">

                    <h3>
                        Aduan Mengikut Blok
                    </h3>

                    <?php
                    $maxBlock =
                        count($blockCounts) > 0
                            ? max($blockCounts)
                            : 1;
                    ?>

                    <?php if (
                        count($blockCounts) > 0
                    ): ?>

                        <?php foreach (
                            $blockCounts as
                            $block =>
                            $count
                        ): ?>

                            <?php
                            $width =
                                ($count / max(1, $maxBlock))
                                * 100;
                            ?>

                            <div class="report-bar-row">

                                <div class="report-bar-label">
                                    Blok <?php echo e($block); ?>
                                </div>

                                <div class="report-bar-track">

                                    <div
                                        class="report-bar-fill"
                                        style="
                                            --width:
                                            <?php
                                            echo round($width, 2);
                                            ?>%;
                                        "
                                    ></div>

                                </div>

                                <div class="report-bar-value">
                                    <?php echo (int) $count; ?>
                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="report-empty">
                            Tiada data blok.
                        </div>

                    <?php endif; ?>

                </div>

            </section>

            <h3 class="report-section-heading">
                Senarai Aduan
            </h3>

            <div class="report-table-wrap">

                <table class="report-table">

                    <thead>

                        <tr>
                            <th>No.</th>
                            <th>No. Rujukan</th>
                            <th>Pengadu</th>
                            <th>Blok / Unit</th>
                            <th>Kategori</th>
                            <th>Tajuk Aduan</th>
                            <th>Keutamaan</th>
                            <th>Status</th>
                            <th>Tarikh</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if ($total === 0): ?>

                            <tr>

                                <td
                                    colspan="9"
                                    class="report-empty"
                                >
                                    Tiada aduan dijumpai untuk
                                    penapis yang dipilih.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach (
                                $complaints as
                                $index =>
                                $complaint
                            ): ?>

                                <tr>

                                    <td>
                                        <?php echo $index + 1; ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $complaint[
                                                    "complaint_id"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $complaint[
                                                    "name"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        Blok
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $complaint[
                                                    "block"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                        /
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $complaint[
                                                    "unit_no"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $complaint[
                                                    "category"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $complaint[
                                                    "subject"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $complaint[
                                                    "priority"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            (string)
                                            (
                                                $complaint[
                                                    "status"
                                                ] ?? "-"
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo e(
                                            formatDateTime(
                                                $hasCreatedAt
                                                    ? (
                                                        $complaint[
                                                            "created_at"
                                                        ] ?? null
                                                    )
                                                    : null
                                            )
                                        );
                                        ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <section class="report-signature">

                <div class="report-signature-box">

                    <div class="report-signature-line"></div>

                    Disediakan Oleh
                    <br>

                    <strong>
                        Pejabat Pengurusan
                    </strong>

                </div>

                <div class="report-signature-box">

                    <div class="report-signature-line"></div>

                    Disahkan Oleh
                    <br>

                    <strong>
                        Pengurus Bangunan
                    </strong>

                </div>

            </section>

            <footer class="report-footer-note">

                Laporan ini dijana secara automatik melalui
                V23 Malawa Ria Complaint Management System.

            </footer>

        </article>

    </main>

</body>

</html>
