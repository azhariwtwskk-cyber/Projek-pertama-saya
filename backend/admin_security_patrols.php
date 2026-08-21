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

if (
    !isset($_SESSION["security_patrol_admin_csrf"]) ||
    !is_string($_SESSION["security_patrol_admin_csrf"])
) {
    $_SESSION["security_patrol_admin_csrf"] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION["security_patrol_admin_csrf"];

$successMessage = "";
$errorMessage = "";

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["review_patrol"])
) {
    $postedToken =
        (string) ($_POST["csrf_token"] ?? "");

    $patrolId =
        (int) ($_POST["patrol_id"] ?? 0);

    $decision =
        trim(
            (string) ($_POST["decision"] ?? "")
        );

    $remarks =
        trim(
            (string) ($_POST["supervisor_remarks"] ?? "")
        );

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage =
            "Permintaan tidak sah. Sila muat semula halaman.";
    } elseif (
        $patrolId < 1 ||
        !in_array(
            $decision,
            ["Verified", "Rejected"],
            true
        )
    ) {
        $errorMessage =
            "Maklumat semakan tidak sah.";
    } elseif (
        $decision === "Rejected" &&
        $remarks === ""
    ) {
        $errorMessage =
            "Catatan supervisor diperlukan untuk penolakan.";
    } else {
        $stmt = $conn->prepare(
            "
            UPDATE security_patrols
            SET
                patrol_status = ?,
                supervisor_remarks = ?,
                verified_by = ?,
                verified_at = NOW()
            WHERE id = ?
            "
        );

        if (!$stmt) {
            $errorMessage =
                "Semakan patrol tidak dapat disediakan.";
        } else {
            $adminName =
                (string) $_SESSION["admin"];

            $stmt->bind_param(
                "sssi",
                $decision,
                $remarks,
                $adminName,
                $patrolId
            );

            if ($stmt->execute()) {
                $successMessage =
                    "Rekod patrol berjaya dikemas kini kepada {$decision}.";
            } else {
                $errorMessage =
                    "Rekod patrol tidak dapat dikemas kini.";
            }

            $stmt->close();
        }
    }
}

$records = [];

$result = $conn->query(
    "
    SELECT
        p.*,
        g.guard_code,
        g.full_name,
        g.guard_type
    FROM security_patrols p
    INNER JOIN security_guards g
        ON g.id = p.guard_id
    ORDER BY
        FIELD(
            p.patrol_status,
            'Submitted',
            'Rejected',
            'Verified'
        ),
        p.created_at DESC
    "
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
}

$imagesByPatrol = [];

if (count($records) > 0) {
    $ids = array_map(
        static fn(array $row): int =>
            (int) $row["id"],
        $records
    );

    $placeholders =
        implode(
            ",",
            array_fill(0, count($ids), "?")
        );

    $types =
        str_repeat("i", count($ids));

    $stmt = $conn->prepare(
        "
        SELECT
            patrol_id,
            image_name,
            location_label,
            image_caption
        FROM security_patrol_images
        WHERE patrol_id IN ({$placeholders})
        ORDER BY id ASC
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            $types,
            ...$ids
        );

        $stmt->execute();

        $imageResult =
            $stmt->get_result();

        while ($image = $imageResult->fetch_assoc()) {
            $imagesByPatrol[
                (int) $image["patrol_id"]
            ][] = $image;
        }

        $stmt->close();
    }
}

$summary = [
    "total" => 0,
    "submitted" => 0,
    "verified" => 0,
    "rejected" => 0,
    "issues" => 0
];

$result = $conn->query(
    "
    SELECT
        COUNT(*) AS total,
        SUM(patrol_status = 'Submitted')
            AS submitted_count,
        SUM(patrol_status = 'Verified')
            AS verified_count,
        SUM(patrol_status = 'Rejected')
            AS rejected_count,
        SUM(issue_found = 1)
            AS issue_count
    FROM security_patrols
    "
);

if ($result) {
    $row = $result->fetch_assoc();

    $summary["total"] =
        (int) ($row["total"] ?? 0);

    $summary["submitted"] =
        (int) ($row["submitted_count"] ?? 0);

    $summary["verified"] =
        (int) ($row["verified_count"] ?? 0);

    $summary["rejected"] =
        (int) ($row["rejected_count"] ?? 0);

    $summary["issues"] =
        (int) ($row["issue_count"] ?? 0);
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
        Security Patrol Records | V23 PMS
    </title>

    <link rel="stylesheet" href="css/pms.css?v=10">

    <style>
        :root {
            --brown: #3a2419;
            --brown-soft: #5a3a2c;
            --gold: #b59b20;
            --cream: #f6f2ea;
            --border: #e4ddd3;
            --muted: #6e6e6e;
            --success: #238b45;
            --danger: #b93232;
            --warning: #a75a15;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: #2f2f2f;
            font-family: Arial, sans-serif;
        }

        .patrol-shell {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr);
            min-height: 100vh;
        }

        .patrol-sidebar {
            padding: 22px 18px;
            background: var(--brown);
            color: #ffffff;
        }

        .patrol-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 18px;
            border-bottom: 1px solid rgba(255,255,255,.16);
        }

        .patrol-brand img {
            width: 58px;
            height: 58px;
            object-fit: contain;
            border-radius: 10px;
            background: #ffffff;
        }

        .patrol-brand strong {
            display: block;
            font-size: 15px;
            line-height: 1.25;
        }

        .patrol-brand span {
            display: block;
            margin-top: 4px;
            color: #d8cec8;
            font-size: 11px;
            line-height: 1.4;
        }

        .patrol-nav {
            display: grid;
            gap: 8px;
            margin-top: 22px;
        }

        .patrol-nav a {
            padding: 11px 12px;
            border-radius: 8px;
            color: #ffffff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }

        .patrol-nav a:hover,
        .patrol-nav a.active {
            background: rgba(255,255,255,.13);
            color: #f5db78;
        }

        .patrol-main {
            min-width: 0;
            padding: 26px;
        }

        .patrol-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
            padding: 20px 22px;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 7px 22px rgba(47,28,20,.07);
        }

        .patrol-topbar h1 {
            margin: 0 0 6px;
            color: var(--brown);
            font-size: 28px;
        }

        .patrol-topbar p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .patrol-button,
        .patrol-button-secondary,
        .patrol-button-danger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
        }

        .patrol-button {
            background: var(--gold);
            color: #ffffff;
        }

        .patrol-button-secondary {
            background: var(--brown);
            color: #ffffff;
        }

        .patrol-button-danger {
            background: var(--danger);
            color: #ffffff;
        }

        .patrol-alert-success,
        .patrol-alert-error {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
        }

        .patrol-alert-success {
            border: 1px solid #a8d8b8;
            background: #edf9f1;
            color: #176b36;
        }

        .patrol-alert-error {
            border: 1px solid #e5b3b3;
            background: #fff1f1;
            color: #9b2626;
        }

        .patrol-summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .patrol-summary-card {
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #ffffff;
            text-align: center;
            box-shadow: 0 5px 16px rgba(47,28,20,.05);
        }

        .patrol-summary-card strong {
            display: block;
            margin-bottom: 5px;
            color: var(--gold);
            font-size: 30px;
        }

        .patrol-summary-card span {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .patrol-card {
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 7px 22px rgba(47,28,20,.07);
        }

        .patrol-table-wrap {
            overflow-x: auto;
        }

        .patrol-table {
            width: 100%;
            border-collapse: collapse;
        }

        .patrol-table th,
        .patrol-table td {
            padding: 11px;
            border-bottom: 1px solid #e9e3dc;
            text-align: left;
            vertical-align: top;
            font-size: 12px;
        }

        .patrol-table th {
            background: #f5f1ea;
            color: var(--brown);
            font-weight: 800;
        }

        .patrol-badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 15px;
            background: #ececec;
            font-size: 11px;
            font-weight: 800;
        }

        .patrol-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 13px;
            margin: 16px 0 18px;
        }

        .patrol-detail-item {
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #faf9f7;
        }

        .patrol-detail-item strong {
            display: block;
            margin-bottom: 5px;
            color: var(--brown);
            font-size: 12px;
        }

        .patrol-detail-item p {
            margin: 0;
            color: #555555;
            line-height: 1.55;
            font-size: 13px;
        }

        .patrol-detail-full {
            grid-column: 1 / -1;
        }

        .patrol-issue-box {
            margin: 16px 0;
            padding: 16px;
            border: 1px solid #e5b3b3;
            border-radius: 10px;
            background: #fff1f1;
            color: #842626;
        }

        .patrol-gallery {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 14px;
        }

        .patrol-gallery figure {
            margin: 0;
            overflow: hidden;
            border: 1px solid #ddd6cc;
            border-radius: 10px;
            background: #ffffff;
        }

        .patrol-gallery img {
            display: block;
            width: 100%;
            height: 220px;
            object-fit: contain;
            background: #f3f1ed;
        }

        .patrol-gallery figcaption {
            padding: 10px;
            color: #555555;
            font-size: 12px;
            line-height: 1.5;
        }

        .patrol-review-form {
            display: grid;
            gap: 14px;
            margin-top: 20px;
        }

        .patrol-review-form textarea {
            width: 100%;
            min-height: 100px;
            padding: 11px;
            border: 1px solid #d7d0c7;
            border-radius: 8px;
            resize: vertical;
            font: inherit;
        }

        .patrol-review-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        @media (max-width: 1000px) {
            .patrol-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .patrol-gallery {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .patrol-shell {
                grid-template-columns: 1fr;
            }

            .patrol-sidebar {
                padding: 14px;
            }

            .patrol-nav {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .patrol-main {
                padding: 14px;
            }

            .patrol-topbar {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 560px) {
            .patrol-summary-grid,
            .patrol-detail-grid,
            .patrol-gallery {
                grid-template-columns: 1fr;
            }

            .patrol-detail-full {
                grid-column: auto;
            }

            .patrol-nav {
                grid-template-columns: 1fr;
            }

            .patrol-review-actions a,
            .patrol-review-actions button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="patrol-shell">

    <aside class="patrol-sidebar">

        <div class="patrol-brand">

            <img
                src="images/logo.png?v=4"
                alt="V23 Malawa Ria"
            >

            <div>
                <strong>V23 Malawa Ria</strong>
                <span>
                    Property Management System
                </span>
            </div>

        </div>

        <nav class="patrol-nav">

            <a href="admin_dashboard.php">
                Dashboard
            </a>

            <a href="admin_security_guards.php">
                Security Guards
            </a>

            <a
                href="admin_security_patrols.php"
                class="active"
            >
                Security Patrol Records
            </a>

            <a href="admin_work_orders.php">
                Work Orders
            </a>

            <a href="monthly_management_report.php">
                Monthly Report
            </a>

            <a href="admin_logout.php">
                Logout Admin
            </a>

        </nav>

    </aside>

    <main class="patrol-main">

        <header class="patrol-topbar">

            <div>
                <h1>
                    Security Patrol Records
                </h1>

                <p>
                    Semak rondaan, gambar lokasi dan isu yang dilaporkan.
                </p>
            </div>

            <a
                href="admin_security_guards.php"
                class="patrol-button"
            >
                Urus Pengawal
            </a>

        </header>

        <?php if ($successMessage !== ""): ?>

            <div class="patrol-alert-success">
                <?php echo e($successMessage); ?>
            </div>

        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>

            <div class="patrol-alert-error">
                <?php echo e($errorMessage); ?>
            </div>

        <?php endif; ?>

        <section class="patrol-summary-grid">

            <article class="patrol-summary-card">
                <strong>
                    <?php echo $summary["total"]; ?>
                </strong>
                <span>Jumlah Patrol</span>
            </article>

            <article class="patrol-summary-card">
                <strong>
                    <?php echo $summary["submitted"]; ?>
                </strong>
                <span>Menunggu Semakan</span>
            </article>

            <article class="patrol-summary-card">
                <strong>
                    <?php echo $summary["verified"]; ?>
                </strong>
                <span>Verified</span>
            </article>

            <article class="patrol-summary-card">
                <strong>
                    <?php echo $summary["rejected"]; ?>
                </strong>
                <span>Rejected</span>
            </article>

            <article class="patrol-summary-card">
                <strong>
                    <?php echo $summary["issues"]; ?>
                </strong>
                <span>Isu Dilaporkan</span>
            </article>

        </section>

        <section class="patrol-card">

            <div class="patrol-table-wrap">

                <table class="patrol-table">

                    <thead>
                        <tr>
                            <th>Rujukan</th>
                            <th>Pengawal</th>
                            <th>Tarikh</th>
                            <th>Jenis Patrol</th>
                            <th>Isu</th>
                            <th>Status</th>
                            <th>Tindakan</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (count($records) === 0): ?>

                        <tr>
                            <td colspan="7">
                                Tiada rekod Security Patrol.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($records as $record): ?>

                            <?php
                            $patrolId =
                                (int) $record["id"];

                            $safeId =
                                "admin-patrol-" . $patrolId;

                            $locations =
                                json_decode(
                                    (string)
                                    $record["locations_checked"],
                                    true
                                );

                            if (!is_array($locations)) {
                                $locations = [];
                            }

                            $patrolImages =
                                $imagesByPatrol[$patrolId] ?? [];
                            ?>

                            <tr>
                                <td>
                                    <strong>
                                        <?php
                                        echo e(
                                            (string)
                                            $record["patrol_reference"]
                                        );
                                        ?>
                                    </strong>
                                </td>

                                <td>
                                    <?php
                                    echo e(
                                        (string)
                                        $record["full_name"]
                                    );
                                    ?>

                                    <br>

                                    <small>
                                        <?php
                                        echo e(
                                            (string)
                                            $record["guard_type"]
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
                                                $record["patrol_date"]
                                            )
                                        )
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo e(
                                        (string)
                                        $record["patrol_type"]
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo (int)
                                        $record["issue_found"] === 1
                                            ? "Ya"
                                            : "Tidak";
                                    ?>
                                </td>

                                <td>
                                    <span class="patrol-badge">
                                        <?php
                                        echo e(
                                            (string)
                                            $record["patrol_status"]
                                        );
                                        ?>
                                    </span>
                                </td>

                                <td>
                                    <button
                                        type="button"
                                        class="patrol-button-secondary"
                                        onclick="toggleAdminPatrol('<?php
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
                                <td colspan="7">

                                    <div class="patrol-detail-grid">

                                        <div class="patrol-detail-item">
                                            <strong>Masa Mula</strong>
                                            <p>
                                                <?php
                                                echo e(
                                                    date(
                                                        "d/m/Y h:i A",
                                                        strtotime(
                                                            (string)
                                                            $record["started_at"]
                                                        )
                                                    )
                                                );
                                                ?>
                                            </p>
                                        </div>

                                        <div class="patrol-detail-item">
                                            <strong>Masa Tamat</strong>
                                            <p>
                                                <?php
                                                echo e(
                                                    date(
                                                        "d/m/Y h:i A",
                                                        strtotime(
                                                            (string)
                                                            $record["completed_at"]
                                                        )
                                                    )
                                                );
                                                ?>
                                            </p>
                                        </div>

                                        <div class="patrol-detail-item">
                                            <strong>Jenis Penugasan</strong>
                                            <p>
                                                <?php
                                                echo e(
                                                    (string)
                                                    $record["duty_type"]
                                                );
                                                ?>
                                            </p>
                                        </div>

                                        <div class="patrol-detail-item">
                                            <strong>Jumlah Gambar</strong>
                                            <p>
                                                <?php
                                                echo count($patrolImages);
                                                ?>
                                                gambar
                                            </p>
                                        </div>

                                        <div
                                            class="patrol-detail-item
                                            patrol-detail-full"
                                        >
                                            <strong>
                                                Lokasi Diperiksa
                                            </strong>

                                            <p>
                                                <?php
                                                echo e(
                                                    count($locations) > 0
                                                        ? implode(
                                                            ", ",
                                                            $locations
                                                        )
                                                        : "-"
                                                );
                                                ?>
                                            </p>
                                        </div>

                                        <div
                                            class="patrol-detail-item
                                            patrol-detail-full"
                                        >
                                            <strong>
                                                Catatan Patrol
                                            </strong>

                                            <p>
                                                <?php
                                                echo nl2br(
                                                    e(
                                                        (string)
                                                        (
                                                            $record[
                                                                "patrol_notes"
                                                            ] ?? "-"
                                                        )
                                                    )
                                                );
                                                ?>
                                            </p>
                                        </div>

                                    </div>

                                    <?php if (
                                        (int)
                                        $record["issue_found"] === 1
                                    ): ?>

                                        <div class="patrol-issue-box">

                                            <h3>Isu Ditemui</h3>

                                            <p>
                                                <strong>Kategori:</strong>
                                                <?php
                                                echo e(
                                                    (string)
                                                    (
                                                        $record[
                                                            "issue_category"
                                                        ] ?? "-"
                                                    )
                                                );
                                                ?>
                                            </p>

                                            <p>
                                                <strong>Priority:</strong>
                                                <?php
                                                echo e(
                                                    (string)
                                                    (
                                                        $record[
                                                            "issue_priority"
                                                        ] ?? "-"
                                                    )
                                                );
                                                ?>
                                            </p>

                                            <p>
                                                <strong>Penerangan:</strong>
                                                <br>

                                                <?php
                                                echo nl2br(
                                                    e(
                                                        (string)
                                                        (
                                                            $record[
                                                                "issue_description"
                                                            ] ?? "-"
                                                        )
                                                    )
                                                );
                                                ?>
                                            </p>

                                        </div>

                                    <?php endif; ?>

                                    <h3>
                                        Gambar Rondaan
                                    </h3>

                                    <?php if (
                                        count($patrolImages) === 0
                                    ): ?>

                                        <div class="patrol-alert-error">
                                            Tiada gambar rondaan dijumpai.
                                        </div>

                                    <?php else: ?>

                                        <div class="patrol-gallery">

                                            <?php foreach (
                                                $patrolImages as $image
                                            ): ?>

                                                <figure>

                                                    <a
                                                        href="uploads/security_patrol/<?php
                                                            echo rawurlencode(
                                                                basename(
                                                                    (string)
                                                                    $image[
                                                                        "image_name"
                                                                    ]
                                                                )
                                                            );
                                                        ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        <img
                                                            src="uploads/security_patrol/<?php
                                                                echo rawurlencode(
                                                                    basename(
                                                                        (string)
                                                                        $image[
                                                                            "image_name"
                                                                        ]
                                                                    )
                                                                );
                                                            ?>"
                                                            alt="Gambar Security Patrol"
                                                        >
                                                    </a>

                                                    <figcaption>
                                                        <strong>
                                                            <?php
                                                            echo e(
                                                                (string)
                                                                $image[
                                                                    "location_label"
                                                                ]
                                                            );
                                                            ?>
                                                        </strong>

                                                        <?php if (
                                                            !empty(
                                                                $image[
                                                                    "image_caption"
                                                                ]
                                                            )
                                                        ): ?>
                                                            <br>
                                                            <?php
                                                            echo e(
                                                                (string)
                                                                $image[
                                                                    "image_caption"
                                                                ]
                                                            );
                                                            ?>
                                                        <?php endif; ?>
                                                    </figcaption>

                                                </figure>

                                            <?php endforeach; ?>

                                        </div>

                                    <?php endif; ?>

                                    <form
                                        method="POST"
                                        class="patrol-review-form"
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
                                            name="patrol_id"
                                            value="<?php
                                                echo $patrolId;
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="decision"
                                            value=""
                                        >

                                        <div>
                                            <label>
                                                <strong>
                                                    Catatan Supervisor
                                                </strong>
                                            </label>

                                            <textarea
                                                name="supervisor_remarks"
                                                maxlength="2000"
                                                placeholder="Masukkan catatan semakan..."
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

                                        <div class="patrol-review-actions">

                                            <button
                                                type="submit"
                                                name="review_patrol"
                                                class="patrol-button"
                                                onclick="this.form.decision.value='Verified'"
                                            >
                                                Verify Patrol
                                            </button>

                                            <button
                                                type="submit"
                                                name="review_patrol"
                                                class="patrol-button-danger"
                                                onclick="this.form.decision.value='Rejected'"
                                            >
                                                Reject Patrol
                                            </button>

                                            <?php if (
                                                (int)
                                                $record[
                                                    "work_order_required"
                                                ] === 1
                                            ): ?>

                                                <a
                                                    href="create_work_order.php?security_patrol_id=<?php
                                                        echo $patrolId;
                                                    ?>"
                                                    class="patrol-button-secondary"
                                                >
                                                    Create Work Order
                                                </a>

                                            <?php endif; ?>

                                        </div>

                                    </form>

                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</div>

<script>
function toggleAdminPatrol(id) {
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
