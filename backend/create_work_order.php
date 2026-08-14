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

function columnExists(
    mysqli $conn,
    string $tableName,
    string $columnName
): bool {
    $stmt = $conn->prepare(
        "
        SELECT COUNT(*) AS total
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
        AND table_name = ?
        AND column_name = ?
        "
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "ss",
        $tableName,
        $columnName
    );

    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

if (
    !isset($_SESSION["wo_create_csrf"]) ||
    !is_string($_SESSION["wo_create_csrf"])
) {
    $_SESSION["wo_create_csrf"] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["wo_create_csrf"];
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

$complaintId =
    strtoupper(
        trim(
            (string) ($_GET["complaint_id"] ?? "")
        )
    );

$prefill = null;

if ($complaintId !== "") {
    $stmt = $conn->prepare(
        "
        SELECT
            complaint_id,
            category,
            subject,
            description,
            block,
            unit_no,
            location,
            priority
        FROM complaints
        WHERE complaint_id = ?
        LIMIT 1
        "
    );

    if ($stmt) {
        $stmt->bind_param("s", $complaintId);
        $stmt->execute();
        $prefill = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$securityPatrolId =
    (int) ($_GET["security_patrol_id"] ?? 0);

$securityPrefill = null;

$hasSecurityPatrols =
    tableExists($conn, "security_patrols");

$hasSecurityGuards =
    tableExists($conn, "security_guards");

$hasSecurityPatrolImages =
    tableExists($conn, "security_patrol_images");

$hasWorkOrderSecurityPatrolColumn =
    columnExists(
        $conn,
        "work_orders",
        "security_patrol_id"
    );

$hasPatrolWorkOrderIdColumn =
    columnExists(
        $conn,
        "security_patrols",
        "work_order_id"
    );

if (
    $securityPatrolId > 0 &&
    $hasSecurityPatrols &&
    $hasSecurityGuards
) {
    $stmt = $conn->prepare(
        "
        SELECT
            p.id,
            p.patrol_reference,
            p.patrol_date,
            p.patrol_type,
            p.duty_type,
            p.started_at,
            p.completed_at,
            p.locations_checked,
            p.patrol_notes,
            p.issue_found,
            p.issue_category,
            p.issue_priority,
            p.issue_description,
            p.work_order_required,
            g.full_name AS guard_name,
            g.guard_code
        FROM security_patrols p
        INNER JOIN security_guards g
            ON g.id = p.guard_id
        WHERE p.id = ?
        LIMIT 1
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "i",
            $securityPatrolId
        );

        $stmt->execute();

        $securityPrefill =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();
    }
}

$securityImages = [];

if (
    $securityPrefill &&
    $hasSecurityPatrolImages
) {
    $stmt = $conn->prepare(
        "
        SELECT
            image_name,
            location_label,
            image_caption
        FROM security_patrol_images
        WHERE patrol_id = ?
        ORDER BY id ASC
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "i",
            $securityPatrolId
        );

        $stmt->execute();

        $imageResult =
            $stmt->get_result();

        while ($image = $imageResult->fetch_assoc()) {
            $securityImages[] = $image;
        }

        $stmt->close();
    }
}

$securityLocations = [];

if ($securityPrefill) {
    $decodedLocations =
        json_decode(
            (string)
            ($securityPrefill["locations_checked"] ?? "[]"),
            true
        );

    if (is_array($decodedLocations)) {
        $securityLocations =
            array_values(
                array_filter(
                    array_map(
                        static fn($value): string =>
                            trim((string) $value),
                        $decodedLocations
                    ),
                    static fn(string $value): bool =>
                        $value !== ""
                )
            );
    }
}

$securityMainLocation =
    $securityLocations[0] ?? "";

$securitySuggestedBlock = "";

$blockCandidates = [
    "Block A",
    "Block B",
    "Block C",
    "Block D",
    "Block E",
    "Guard House",
    "Main Entrance / Boom Gate",
    "Playground",
    "Water Tank",
    "Common Area",
    "Car Park",
    "Drainage",
    "Other"
];

foreach ($securityLocations as $locationItem) {
    foreach ($blockCandidates as $candidate) {
        if (
            stripos(
                $locationItem,
                $candidate
            ) !== false
        ) {
            $securitySuggestedBlock =
                $candidate;
            break 2;
        }
    }
}

if (
    $securitySuggestedBlock === "" &&
    $securityMainLocation !== ""
) {
    $securitySuggestedBlock = "Other";
}

if (
    $securityPrefill &&
    $prefill === null
) {
    $securityIssueCategory =
        trim(
            (string)
            ($securityPrefill["issue_category"] ?? "")
        );

    $securityIssueDescription =
        trim(
            (string)
            ($securityPrefill["issue_description"] ?? "")
        );

    $securityPatrolNotes =
        trim(
            (string)
            ($securityPrefill["patrol_notes"] ?? "")
        );

    $securityReference =
        (string)
        $securityPrefill["patrol_reference"];

    $securityGuardName =
        (string)
        $securityPrefill["guard_name"];

    $securityTitleCategory =
        $securityIssueCategory !== ""
            ? $securityIssueCategory
            : "Security Patrol Issue";

    $descriptionParts = [
        "Sumber: Security Patrol " .
        $securityReference,
        "Dilaporkan oleh: " .
        $securityGuardName,
        "Tarikh patrol: " .
        date(
            "d/m/Y",
            strtotime(
                (string)
                $securityPrefill["patrol_date"]
            )
        ),
        "Lokasi diperiksa: " .
        (
            count($securityLocations) > 0
                ? implode(", ", $securityLocations)
                : "-"
        )
    ];

    if ($securityIssueDescription !== "") {
        $descriptionParts[] =
            "Isu dilaporkan: " .
            $securityIssueDescription;
    }

    if ($securityPatrolNotes !== "") {
        $descriptionParts[] =
            "Catatan patrol: " .
            $securityPatrolNotes;
    }

    $prefill = [
        "complaint_id" => "",
        "category" => $securityTitleCategory,
        "subject" =>
            $securityTitleCategory .
            " - " .
            (
                $securityMainLocation !== ""
                    ? $securityMainLocation
                    : $securityReference
            ),
        "description" =>
            implode(
                "\n\n",
                $descriptionParts
            ),
        "block" => $securitySuggestedBlock,
        "unit_no" => "",
        "location" =>
            count($securityLocations) > 0
                ? implode(", ", $securityLocations)
                : "",
        "priority" =>
            (string)
            (
                $securityPrefill["issue_priority"] ??
                "Medium"
            )
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken =
        (string) ($_POST["csrf_token"] ?? "");

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage = "Permintaan tidak sah.";
    } else {
        $complaintIdPost =
            strtoupper(
                trim(
                    (string)
                    ($_POST["complaint_id"] ?? "")
                )
            );

        $securityPatrolIdPost =
            (int)
            ($_POST["security_patrol_id"] ?? 0);

        $title =
            trim((string) ($_POST["title"] ?? ""));
        $description =
            trim((string) ($_POST["description"] ?? ""));
        $category =
            trim((string) ($_POST["category"] ?? ""));
        $priority =
            trim((string) ($_POST["priority"] ?? ""));
        $blockLocation =
            trim((string) ($_POST["block_location"] ?? ""));
        $specificLocation =
            trim((string) ($_POST["specific_location"] ?? ""));
        $assignedStaffRaw =
            trim((string) ($_POST["assigned_staff_id"] ?? ""));
        $contractor =
            trim((string) ($_POST["assigned_contractor"] ?? ""));
        $scheduledDate =
            trim((string) ($_POST["scheduled_date"] ?? ""));
        $dueDate =
            trim((string) ($_POST["due_date"] ?? ""));
        $estimatedCostRaw =
            trim((string) ($_POST["estimated_cost"] ?? ""));
        $remarks =
            trim((string) ($_POST["admin_remarks"] ?? ""));

        $assignedStaffId =
            $assignedStaffRaw === ""
                ? null
                : (int) $assignedStaffRaw;

        $estimatedCost =
            $estimatedCostRaw === ""
                ? null
                : (float) $estimatedCostRaw;

        $allowedPriorities = [
            "Low",
            "Medium",
            "High",
            "Emergency"
        ];

        if (
            $title === "" ||
            $description === "" ||
            $category === "" ||
            $blockLocation === ""
        ) {
            $errorMessage =
                "Sila lengkapkan semua medan wajib.";
        } elseif (
            !in_array(
                $priority,
                $allowedPriorities,
                true
            )
        ) {
            $errorMessage = "Priority tidak sah.";
        } elseif (
            $scheduledDate !== "" &&
            $dueDate !== "" &&
            $dueDate < $scheduledDate
        ) {
            $errorMessage =
                "Due date tidak boleh lebih awal daripada scheduled date.";
        } else {
            try {
                $conn->begin_transaction();

                do {
                    $reference =
                        "WO-" .
                        date("Ym") .
                        "-" .
                        strtoupper(
                            bin2hex(random_bytes(3))
                        );

                    $checkStmt = $conn->prepare(
                        "
                        SELECT id
                        FROM work_orders
                        WHERE work_order_reference = ?
                        LIMIT 1
                        "
                    );

                    if (!$checkStmt) {
                        throw new RuntimeException(
                            "Gagal menjana nombor Work Order."
                        );
                    }

                    $checkStmt->bind_param(
                        "s",
                        $reference
                    );

                    $checkStmt->execute();

                    $exists =
                        $checkStmt
                            ->get_result()
                            ->num_rows > 0;

                    $checkStmt->close();

                } while ($exists);

                $status =
                    $assignedStaffId !== null
                        ? "Assigned"
                        : "Open";

                $createdBy =
                    (string) $_SESSION["admin"];

                if (
                    $hasWorkOrderSecurityPatrolColumn
                ) {
                    $stmt = $conn->prepare(
                        "
                        INSERT INTO work_orders
                        (
                            work_order_reference,
                            complaint_id,
                            security_patrol_id,
                            title,
                            description,
                            category,
                            priority,
                            block_location,
                            specific_location,
                            assigned_staff_id,
                            assigned_contractor,
                            scheduled_date,
                            due_date,
                            estimated_cost,
                            status,
                            admin_remarks,
                            created_by
                        )
                        VALUES
                        (
                            ?,
                            NULLIF(?, ''),
                            NULLIF(?, 0),
                            ?, ?, ?, ?, ?, ?,
                            ?,
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            ?,
                            ?,
                            ?,
                            ?
                        )
                        "
                    );

                    if (!$stmt) {
                        throw new RuntimeException(
                            "Work Order tidak dapat disediakan."
                        );
                    }

                    $stmt->bind_param(
                        "ssissssssisssdsss",
                        $reference,
                        $complaintIdPost,
                        $securityPatrolIdPost,
                        $title,
                        $description,
                        $category,
                        $priority,
                        $blockLocation,
                        $specificLocation,
                        $assignedStaffId,
                        $contractor,
                        $scheduledDate,
                        $dueDate,
                        $estimatedCost,
                        $status,
                        $remarks,
                        $createdBy
                    );
                } else {
                    $stmt = $conn->prepare(
                        "
                        INSERT INTO work_orders
                        (
                            work_order_reference,
                            complaint_id,
                            title,
                            description,
                            category,
                            priority,
                            block_location,
                            specific_location,
                            assigned_staff_id,
                            assigned_contractor,
                            scheduled_date,
                            due_date,
                            estimated_cost,
                            status,
                            admin_remarks,
                            created_by
                        )
                        VALUES
                        (
                            ?,
                            NULLIF(?, ''),
                            ?, ?, ?, ?, ?, ?,
                            ?,
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            ?,
                            ?,
                            ?,
                            ?
                        )
                        "
                    );

                    if (!$stmt) {
                        throw new RuntimeException(
                            "Work Order tidak dapat disediakan."
                        );
                    }

                    $stmt->bind_param(
                        "ssssssssisssdsss",
                        $reference,
                        $complaintIdPost,
                        $title,
                        $description,
                        $category,
                        $priority,
                        $blockLocation,
                        $specificLocation,
                        $assignedStaffId,
                        $contractor,
                        $scheduledDate,
                        $dueDate,
                        $estimatedCost,
                        $status,
                        $remarks,
                        $createdBy
                    );
                }

                if (!$stmt->execute()) {
                    throw new RuntimeException(
                        "Work Order gagal disimpan."
                    );
                }

                $workOrderId =
                    (int) $conn->insert_id;

                $stmt->close();

                if (
                    $securityPatrolIdPost > 0 &&
                    $hasSecurityPatrols
                ) {
                    if ($hasPatrolWorkOrderIdColumn) {
                        $patrolStmt = $conn->prepare(
                            "
                            UPDATE security_patrols
                            SET
                                work_order_id = ?,
                                work_order_required = 1
                            WHERE id = ?
                            "
                        );

                        if ($patrolStmt) {
                            $patrolStmt->bind_param(
                                "ii",
                                $workOrderId,
                                $securityPatrolIdPost
                            );

                            $patrolStmt->execute();
                            $patrolStmt->close();
                        }
                    } else {
                        $patrolStmt = $conn->prepare(
                            "
                            UPDATE security_patrols
                            SET work_order_required = 1
                            WHERE id = ?
                            "
                        );

                        if ($patrolStmt) {
                            $patrolStmt->bind_param(
                                "i",
                                $securityPatrolIdPost
                            );

                            $patrolStmt->execute();
                            $patrolStmt->close();
                        }
                    }
                }

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
                    $historyStmt->bind_param(
                        "isss",
                        $workOrderId,
                        $status,
                        $remarks,
                        $createdBy
                    );

                    $historyStmt->execute();
                    $historyStmt->close();
                }

                $conn->commit();

                header(
                    "Location: admin_work_orders.php?created=" .
                    urlencode($reference)
                );

                exit();

            } catch (Throwable $error) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                }

                error_log(
                    "Create work order error: " .
                    $error->getMessage()
                );

                $errorMessage =
                    "Work Order tidak dapat dicipta.";
            }
        }
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
    <title>Create Work Order | V23 PMS</title>
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
            <a href="admin_daily_work.php">Daily Work</a>
            <a
                href="admin_work_orders.php"
                class="active"
            >
                Work Order
            </a>

            <a href="admin_security_patrols.php">
                Security Patrol
            </a>
        </nav>

        <div class="pms-sidebar-footer">
            <a href="admin_logout.php">Logout Admin</a>
        </div>

    </aside>

    <main class="pms-main">

        <header class="pms-topbar">
            <div>
                <h1>Create Work Order</h1>
                <p>
                    Cipta arahan kerja daripada aduan atau kerja rutin.
                </p>
            </div>

            <a
                href="admin_work_orders.php"
                class="pms-button-secondary"
            >
                ← Senarai Work Order
            </a>
        </header>

        <?php if ($errorMessage !== ""): ?>
            <div class="pms-alert-error">
                <?php echo e($errorMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($securityPrefill): ?>

            <section
                class="pms-card"
                style="
                    margin-bottom:18px;
                    border-left:5px solid #b59b20;
                "
            >

                <h2 style="margin-top:0;">
                    Sumber: Security Patrol
                </h2>

                <p>
                    <strong>Patrol Reference:</strong>
                    <?php
                    echo e(
                        (string)
                        $securityPrefill[
                            "patrol_reference"
                        ]
                    );
                    ?>
                </p>

                <p>
                    <strong>Reported By:</strong>
                    <?php
                    echo e(
                        (string)
                        $securityPrefill[
                            "guard_name"
                        ]
                    );
                    ?>
                </p>

                <p>
                    <strong>Tarikh:</strong>
                    <?php
                    echo e(
                        date(
                            "d/m/Y",
                            strtotime(
                                (string)
                                $securityPrefill[
                                    "patrol_date"
                                ]
                            )
                        )
                    );
                    ?>
                </p>

                <p>
                    <strong>Lokasi:</strong>
                    <?php
                    echo e(
                        count($securityLocations) > 0
                            ? implode(
                                ", ",
                                $securityLocations
                            )
                            : "-"
                    );
                    ?>
                </p>

                <?php if (
                    count($securityImages) > 0
                ): ?>

                    <div
                        style="
                            display:grid;
                            grid-template-columns:
                                repeat(3,minmax(0,1fr));
                            gap:12px;
                            margin-top:15px;
                        "
                    >

                        <?php foreach (
                            $securityImages as $image
                        ): ?>

                            <figure
                                style="
                                    margin:0;
                                    border:1px solid #ddd;
                                    border-radius:9px;
                                    overflow:hidden;
                                "
                            >
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
                                        alt="Security Patrol Image"
                                        style="
                                            width:100%;
                                            height:170px;
                                            object-fit:contain;
                                            background:#f5f5f5;
                                        "
                                    >
                                </a>

                                <figcaption
                                    style="
                                        padding:9px;
                                        font-size:12px;
                                        line-height:1.45;
                                    "
                                >
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

            </section>

        <?php endif; ?>

        <section class="pms-card">

            <form method="POST" class="pms-form-grid">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($csrfToken); ?>"
                >

                <input
                    type="hidden"
                    name="security_patrol_id"
                    value="<?php
                        echo (int) $securityPatrolId;
                    ?>"
                >

                <div class="pms-form-group">
                    <label for="complaint_id">
                        Nombor Aduan
                    </label>

                    <input
                        type="text"
                        id="complaint_id"
                        name="complaint_id"
                        value="<?php
                            echo e(
                                (string)
                                (
                                    $prefill["complaint_id"] ??
                                    $complaintId
                                )
                            );
                        ?>"
                        maxlength="50"
                        placeholder="Opsyenal"
                    >
                </div>

                <div class="pms-form-group">
                    <label for="priority">
                        Priority *
                    </label>

                    <select
                        id="priority"
                        name="priority"
                        required
                    >
                        <?php foreach (
                            ["Low","Medium","High","Emergency"]
                            as $priority
                        ): ?>
                            <option
                                value="<?php echo e($priority); ?>"
                                <?php
                                $prefillPriority =
                                    strtolower(
                                        (string)
                                        (
                                            $prefill["priority"] ??
                                            ""
                                        )
                                    );

                                echo
                                    (
                                        strtolower($priority) ===
                                        $prefillPriority
                                    ) ||
                                    (
                                        $priority === "High" &&
                                        str_contains(
                                            $prefillPriority,
                                            "tinggi"
                                        )
                                    ) ||
                                    (
                                        $priority === "Medium" &&
                                        (
                                            $prefill === null ||
                                            str_contains(
                                                $prefillPriority,
                                                "sederhana"
                                            )
                                        )
                                    ) ||
                                    (
                                        $priority === "Low" &&
                                        str_contains(
                                            $prefillPriority,
                                            "rendah"
                                        )
                                    )
                                        ? "selected"
                                        : "";
                                ?>
                            >
                                <?php echo e($priority); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >
                    <label for="title">
                        Tajuk Work Order *
                    </label>

                    <input
                        type="text"
                        id="title"
                        name="title"
                        maxlength="200"
                        value="<?php
                            echo e(
                                (string)
                                (
                                    $prefill["subject"] ?? ""
                                )
                            );
                        ?>"
                        required
                    >
                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >
                    <label for="description">
                        Description *
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        maxlength="5000"
                        required
                    ><?php
                        echo e(
                            (string)
                            (
                                $prefill["description"] ?? ""
                            )
                        );
                    ?></textarea>
                </div>

                <div class="pms-form-group">
                    <label for="category">
                        Category *
                    </label>

                    <input
                        type="text"
                        id="category"
                        name="category"
                        maxlength="100"
                        value="<?php
                            echo e(
                                (string)
                                (
                                    $prefill["category"] ?? ""
                                )
                            );
                        ?>"
                        placeholder="Contoh: Plumbing"
                        required
                    >
                </div>

                <div class="pms-form-group">
                    <label for="block_location">
                        Blok / Kawasan *
                    </label>

                    <select
                        id="block_location"
                        name="block_location"
                        required
                    >
                        <option value="">
                            -- Sila Pilih --
                        </option>

                        <?php foreach (
                            [
                                "Block A",
                                "Block B",
                                "Block C",
                                "Block D",
                                "Block E",
                                "Guard House",
                                "Main Entrance / Boom Gate",
                                "Playground",
                                "Water Tank",
                                "Common Area",
                                "Car Park",
                                "Drainage",
                                "Other"
                            ] as $location
                        ): ?>
                            <option
                                value="<?php echo e($location); ?>"
                                <?php
                                $selectedBlock =
                                    (string)
                                    (
                                        $prefill["block"] ??
                                        ""
                                    );

                                echo
                                    $selectedBlock === $location
                                        ? "selected"
                                        : "";
                                ?>
                            >
                                <?php echo e($location); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pms-form-group">
                    <label for="specific_location">
                        Lokasi Spesifik
                    </label>

                    <input
                        type="text"
                        id="specific_location"
                        name="specific_location"
                        maxlength="200"
                        value="<?php
                            echo e(
                                (string)
                                (
                                    $prefill["location"] ??
                                    $prefill["unit_no"] ??
                                    ""
                                )
                            );
                        ?>"
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
                        <option value="">
                            Belum Assign
                        </option>

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

                <div class="pms-form-group">
                    <label for="assigned_contractor">
                        Contractor
                    </label>

                    <input
                        type="text"
                        id="assigned_contractor"
                        name="assigned_contractor"
                        maxlength="150"
                    >
                </div>

                <div class="pms-form-group">
                    <label for="scheduled_date">
                        Scheduled Date
                    </label>

                    <input
                        type="date"
                        id="scheduled_date"
                        name="scheduled_date"
                    >
                </div>

                <div class="pms-form-group">
                    <label for="due_date">
                        Due Date
                    </label>

                    <input
                        type="date"
                        id="due_date"
                        name="due_date"
                    >
                </div>

                <div class="pms-form-group">
                    <label for="estimated_cost">
                        Estimated Cost (RM)
                    </label>

                    <input
                        type="number"
                        id="estimated_cost"
                        name="estimated_cost"
                        min="0"
                        step="0.01"
                    >
                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >
                    <label for="admin_remarks">
                        Admin Remarks
                    </label>

                    <textarea
                        id="admin_remarks"
                        name="admin_remarks"
                        maxlength="3000"
                    ></textarea>
                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >
                    <button
                        type="submit"
                        class="pms-button"
                    >
                        Cipta Work Order
                    </button>
                </div>

            </form>

        </section>

    </main>

</div>

</body>
</html>
