<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set("display_errors", "1");
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
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

$tables = [
    "complaints",
    "work_orders",
    "daily_work_logs",
    "daily_work_images",
    "assets",
    "preventive_maintenance",
    "asset_inspections",
    "staff"
];

$results = [];

foreach ($tables as $table) {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    $existsResult = $conn->query(
        "SHOW TABLES LIKE '" .
        $conn->real_escape_string($safeTable) .
        "'"
    );

    $exists =
        $existsResult &&
        $existsResult->num_rows > 0;

    $columns = [];
    $rowCount = null;
    $error = "";

    if ($exists) {
        $columnResult =
            $conn->query("SHOW COLUMNS FROM `{$safeTable}`");

        if ($columnResult) {
            while ($column = $columnResult->fetch_assoc()) {
                $columns[] = (string) $column["Field"];
            }
        } else {
            $error = $conn->error;
        }

        $countResult =
            $conn->query("SELECT COUNT(*) AS total FROM `{$safeTable}`");

        if ($countResult) {
            $countRow = $countResult->fetch_assoc();
            $rowCount = (int) ($countRow["total"] ?? 0);
        } elseif ($error === "") {
            $error = $conn->error;
        }
    }

    $results[] = [
        "table" => $table,
        "exists" => $exists,
        "rows" => $rowCount,
        "columns" => $columns,
        "error" => $error
    ];
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
    <title>Monthly Report Diagnostic</title>
    <link rel="stylesheet" href="css/pms.css?v=9">
</head>

<body class="pms-body">

<main
    class="pms-main"
    style="max-width:1100px;margin:0 auto;"
>

    <header class="pms-topbar">
        <div>
            <h1>Monthly Report Diagnostic</h1>
            <p>
                PHP <?php echo e(PHP_VERSION); ?>
            </p>
        </div>
    </header>

    <div class="pms-alert-success">
        Hantar screenshot halaman ini jika laporan masih HTTP 500.
        Jangan hantar kandungan db.php atau password database.
    </div>

    <section class="pms-card pms-table-wrap">

        <table class="pms-table">
            <thead>
                <tr>
                    <th>Table</th>
                    <th>Wujud</th>
                    <th>Rows</th>
                    <th>Columns</th>
                    <th>Error</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($results as $result): ?>
                    <tr>
                        <td>
                            <strong>
                                <?php echo e($result["table"]); ?>
                            </strong>
                        </td>

                        <td>
                            <?php
                            echo $result["exists"]
                                ? "Ya"
                                : "Tidak";
                            ?>
                        </td>

                        <td>
                            <?php
                            echo $result["rows"] === null
                                ? "-"
                                : (int) $result["rows"];
                            ?>
                        </td>

                        <td style="max-width:500px;word-break:break-word;">
                            <?php
                            echo e(
                                implode(
                                    ", ",
                                    $result["columns"]
                                )
                            );
                            ?>
                        </td>

                        <td>
                            <?php echo e($result["error"]); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </section>

</main>

</body>
</html>
