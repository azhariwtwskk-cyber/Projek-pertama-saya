<?php

declare(strict_types=1);

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";
require_once __DIR__ . "/cpms/includes/property_context.php";
require_once __DIR__ . "/cpms/includes/property_guard.php";

if (!isset($_SESSION["admin"])) {
    header("Location: admin_login.php");
    exit();
}

$auditRole = strtolower(trim((string) ($_SESSION["role"] ?? "clerk")));
if (!in_array($auditRole, ["property_admin", "manager"], true)) {
    http_response_code(403);
    header("Location: admin_dashboard.php?error=access_denied");
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

$propertyId =
    cpmsRequireCurrentPropertyId($conn);

$currentProperty =
    cpmsCurrentProperty($conn);

$isEnglish =
    $cpmsLanguage === "en";

$moduleFilter =
    trim(
        (string) (
            $_GET["module"] ??
            ""
        )
    );

$actionFilter =
    trim(
        (string) (
            $_GET["action"] ??
            ""
        )
    );

$search =
    trim(
        (string) (
            $_GET["search"] ??
            ""
        )
    );

$where = [
    "property_id = ?"
];

$types = "i";
$values = [
    $propertyId
];

if ($moduleFilter !== "") {
    $where[] =
        "module_name = ?";

    $types .= "s";
    $values[] =
        $moduleFilter;
}

if ($actionFilter !== "") {
    $where[] =
        "action_name = ?";

    $types .= "s";
    $values[] =
        $actionFilter;
}

if ($search !== "") {
    $where[] =
        "(
            reference_no LIKE ?
            OR record_id LIKE ?
            OR user_name LIKE ?
            OR remarks LIKE ?
        )";

    $like =
        "%" .
        $search .
        "%";

    for ($index = 0; $index < 4; $index++) {
        $types .= "s";
        $values[] = $like;
    }
}

$whereSql =
    implode(
        " AND ",
        $where
    );

$stmt = $conn->prepare(
    "
    SELECT
        id,
        property_id,
        module_name,
        action_name,
        record_id,
        reference_no,
        user_name,
        user_role,
        ip_address,
        user_agent,
        remarks,
        old_values,
        new_values,
        created_at
    FROM cpms_audit_logs
    WHERE {$whereSql}
    ORDER BY id DESC
    LIMIT 300
    "
);

$logs = [];

if ($stmt) {
    $stmt->bind_param(
        $types,
        ...$values
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row =
        $result->fetch_assoc()
    ) {
        $logs[] = $row;
    }

    $stmt->close();
}

$modules = [];
$moduleStmt = $conn->prepare(
    "
    SELECT DISTINCT module_name
    FROM cpms_audit_logs
    WHERE property_id = ?
    ORDER BY module_name ASC
    "
);

if ($moduleStmt) {
    $moduleStmt->bind_param(
        "i",
        $propertyId
    );

    $moduleStmt->execute();

    $moduleResult =
        $moduleStmt->get_result();

    while (
        $moduleRow =
        $moduleResult->fetch_assoc()
    ) {
        $modules[] =
            (string) $moduleRow["module_name"];
    }

    $moduleStmt->close();
}

$actions = [];
$actionStmt = $conn->prepare(
    "
    SELECT DISTINCT action_name
    FROM cpms_audit_logs
    WHERE property_id = ?
    ORDER BY action_name ASC
    "
);

if ($actionStmt) {
    $actionStmt->bind_param(
        "i",
        $propertyId
    );

    $actionStmt->execute();

    $actionResult =
        $actionStmt->get_result();

    while (
        $actionRow =
        $actionResult->fetch_assoc()
    ) {
        $actions[] =
            (string) $actionRow["action_name"];
    }

    $actionStmt->close();
}

$conn->close();

?>
<!DOCTYPE html>
<html lang="<?php echo e($cpmsLanguage); ?>">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?php
        echo e(
            (
                $isEnglish
                    ? "Audit Log"
                    : "Log Audit"
            ) .
            " | " .
            (
                $currentProperty["name"] ??
                cpmsPropertyName()
            )
        );
        ?>
    </title>

    <?php echo cpmsThemeStyleTag(); ?>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f6f2ea;
            color: #333333;
            font-family: Arial, sans-serif;
        }

        .page {
            width: min(1200px, calc(100% - 24px));
            margin: 28px auto;
        }

        .header,
        .card {
            padding: 22px;
            border: 1px solid #e5ddd2;
            border-radius: 15px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(0,0,0,.06);
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 18px;
            border-top: 6px solid var(--cpms-secondary);
        }

        .header h1 {
            margin: 0 0 6px;
            color: var(--cpms-primary);
        }

        .header p {
            margin: 0;
            color: #777777;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border: 0;
            border-radius: 8px;
            background: var(--cpms-primary);
            color: #ffffff;
            text-decoration: none;
            font-weight: 800;
            cursor: pointer;
        }

        .button.gold {
            background: var(--cpms-secondary);
        }

        .filters {
            display: grid;
            grid-template-columns:
                minmax(180px, 1fr)
                minmax(160px, .7fr)
                minmax(160px, .7fr)
                auto;
            gap: 12px;
            margin-bottom: 18px;
        }

        .filters input,
        .filters select {
            width: 100%;
            padding: 10px 11px;
            border: 1px solid #d8d1c8;
            border-radius: 8px;
            font: inherit;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #eeeeee;
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }

        th {
            color: var(--cpms-primary);
        }

        .action-badge {
            display: inline-flex;
            padding: 5px 8px;
            border-radius: 99px;
            background: #fff6df;
            color: #8b6800;
            font-size: 11px;
            font-weight: 800;
        }

        details {
            margin-top: 7px;
        }

        summary {
            color: var(--cpms-primary);
            cursor: pointer;
            font-weight: 700;
        }

        pre {
            max-width: 520px;
            overflow: auto;
            padding: 10px;
            border-radius: 8px;
            background: #f5f5f5;
            color: #444444;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .empty {
            padding: 30px;
            color: #777777;
            text-align: center;
        }

        @media (max-width: 850px) {
            .header {
                align-items: flex-start;
                flex-direction: column;
            }

            .filters {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<main class="page">

    <header class="header">

        <div>
            <h1>
                <?php
                echo
                    $isEnglish
                        ? "CPMS Audit Log"
                        : "Log Audit CPMS";
                ?>
            </h1>

            <p>
                <?php echo e((string) ($currentProperty["name"] ?? "-")); ?>
            </p>
        </div>

        <a
            href="admin_dashboard.php"
            class="button"
        >
            ← Dashboard
        </a>

    </header>

    <section class="card">

        <form
            method="GET"
            class="filters"
        >

            <input
                type="text"
                name="search"
                value="<?php echo e($search); ?>"
                placeholder="<?php
                    echo $isEnglish
                        ? "Reference, user or remarks"
                        : "Rujukan, pengguna atau catatan";
                ?>"
            >

            <select name="module">

                <option value="">
                    <?php
                    echo $isEnglish
                        ? "All Modules"
                        : "Semua Modul";
                    ?>
                </option>

                <?php foreach ($modules as $module): ?>

                    <option
                        value="<?php echo e($module); ?>"
                        <?php
                        echo
                            $moduleFilter === $module
                                ? "selected"
                                : "";
                        ?>
                    >
                        <?php echo e($module); ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <select name="action">

                <option value="">
                    <?php
                    echo $isEnglish
                        ? "All Actions"
                        : "Semua Tindakan";
                    ?>
                </option>

                <?php foreach ($actions as $action): ?>

                    <option
                        value="<?php echo e($action); ?>"
                        <?php
                        echo
                            $actionFilter === $action
                                ? "selected"
                                : "";
                        ?>
                    >
                        <?php echo e($action); ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button
                type="submit"
                class="button gold"
            >
                <?php
                echo $isEnglish
                    ? "Filter"
                    : "Tapis";
                ?>
            </button>

        </form>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>
                            <?php echo $isEnglish ? "Date" : "Tarikh"; ?>
                        </th>
                        <th>Module</th>
                        <th>
                            <?php echo $isEnglish ? "Action" : "Tindakan"; ?>
                        </th>
                        <th>
                            <?php echo $isEnglish ? "Reference" : "Rujukan"; ?>
                        </th>
                        <th>
                            <?php echo $isEnglish ? "User" : "Pengguna"; ?>
                        </th>
                        <th>
                            <?php echo $isEnglish ? "Details" : "Butiran"; ?>
                        </th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($logs) === 0): ?>

                        <tr>
                            <td colspan="6" class="empty">
                                <?php
                                echo $isEnglish
                                    ? "No audit records found."
                                    : "Tiada rekod audit dijumpai.";
                                ?>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($logs as $log): ?>

                            <tr>

                                <td>
                                    <?php
                                    echo e(
                                        date(
                                            "d/m/Y h:i A",
                                            strtotime(
                                                (string) $log["created_at"]
                                            )
                                        )
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php echo e((string) $log["module_name"]); ?>
                                </td>

                                <td>
                                    <span class="action-badge">
                                        <?php echo e((string) $log["action_name"]); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php
                                    echo e(
                                        (string) (
                                            $log["reference_no"] ??
                                            $log["record_id"] ??
                                            "-"
                                        )
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php echo e((string) ($log["user_name"] ?? "-")); ?>
                                    <br>
                                    <small>
                                        <?php echo e((string) ($log["user_role"] ?? "-")); ?>
                                    </small>
                                </td>

                                <td>

                                    <details>
                                        <summary>
                                            <?php
                                            echo $isEnglish
                                                ? "View"
                                                : "Lihat";
                                            ?>
                                        </summary>

                                        <p>
                                            <strong>IP:</strong>
                                            <?php echo e((string) ($log["ip_address"] ?? "-")); ?>
                                        </p>

                                        <p>
                                            <strong>
                                                <?php echo $isEnglish ? "Remarks" : "Catatan"; ?>:
                                            </strong>
                                            <?php echo nl2br(e((string) ($log["remarks"] ?? "-"))); ?>
                                        </p>

                                        <?php if (!empty($log["old_values"])): ?>
                                            <strong>Before</strong>
                                            <pre><?php echo e((string) $log["old_values"]); ?></pre>
                                        <?php endif; ?>

                                        <?php if (!empty($log["new_values"])): ?>
                                            <strong>After</strong>
                                            <pre><?php echo e((string) $log["new_values"]); ?></pre>
                                        <?php endif; ?>

                                        <strong>User Agent</strong>
                                        <pre><?php echo e((string) ($log["user_agent"] ?? "-")); ?></pre>

                                    </details>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

</body>

</html>
