<?php

declare(strict_types=1);

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";
require_once __DIR__ . "/cpms/includes/property_context.php";
require_once __DIR__ . "/cpms/includes/property_guard.php";

if (!isset($_SESSION["admin"])) {
    header("Location: admin_login.php");
    exit();
}

$propertyId = cpmsRequireCurrentPropertyId($conn);
$currentProperty = cpmsCurrentProperty($conn);
$adminName = (string) ($_SESSION["admin"] ?? "");

if (
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"])
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

function notificationDate(?string $date): string
{
    if (!$date) {
        return "-";
    }

    $time = strtotime($date);

    return $time === false
        ? $date
        : date("d/m/Y h:i A", $time);
}

function safeLocalUrl(string $url): string
{
    $url = trim($url);

    if (
        $url === "" ||
        str_starts_with($url, "//") ||
        preg_match('/^(?:[a-z][a-z0-9+.-]*:|\\\\)/i', $url)
    ) {
        return "admin_notifications.php";
    }

    return $url;
}

$search = trim((string) ($_GET["search"] ?? ""));
$type = trim((string) ($_GET["type"] ?? ""));
$status = trim((string) ($_GET["status"] ?? "active"));
$period = trim((string) ($_GET["period"] ?? ""));
$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = [
    "property_id = ?",
    "(target_role IS NULL OR target_role = 'Administrator')",
    "(target_user IS NULL OR target_user = ?)",
    "deleted_at IS NULL"
];

$types = "is";
$values = [$propertyId, $adminName];

if ($search !== "") {
    $where[] = "(title LIKE ? OR message LIKE ? OR reference_no LIKE ?)";
    $like = "%" . $search . "%";
    $types .= "sss";
    array_push($values, $like, $like, $like);
}

if ($type !== "") {
    $where[] = "notification_type = ?";
    $types .= "s";
    $values[] = $type;
}

if ($status === "unread") {
    $where[] = "is_read = 0";
    $where[] = "archived_at IS NULL";
} elseif ($status === "read") {
    $where[] = "is_read = 1";
    $where[] = "archived_at IS NULL";
} elseif ($status === "archived") {
    $where[] = "archived_at IS NOT NULL";
} else {
    $where[] = "archived_at IS NULL";
}

if ($period === "today") {
    $where[] = "DATE(created_at) = CURDATE()";
} elseif ($period === "7") {
    $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($period === "30") {
    $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$whereSql = implode(" AND ", $where);

$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM cpms_notifications
     WHERE {$whereSql}"
);

$total = 0;

if ($countStmt) {
    $countStmt->bind_param($types, ...$values);
    $countStmt->execute();
    $total = (int) (
        $countStmt
            ->get_result()
            ->fetch_assoc()["total"] ?? 0
    );
    $countStmt->close();
}

$listTypes = $types . "ii";
$listValues = [...$values, $perPage, $offset];

$listStmt = $conn->prepare(
    "
    SELECT
        id,
        notification_type,
        category,
        priority,
        title,
        message,
        reference_no,
        action_url,
        is_read,
        read_at,
        archived_at,
        created_by,
        created_at
    FROM cpms_notifications
    WHERE {$whereSql}
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
    "
);

$notifications = [];

if ($listStmt) {
    $listStmt->bind_param($listTypes, ...$listValues);
    $listStmt->execute();
    $result = $listStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }

    $listStmt->close();
}

$typeRows = [];
$typeStmt = $conn->prepare(
    "
    SELECT DISTINCT notification_type
    FROM cpms_notifications
    WHERE property_id = ?
      AND notification_type IS NOT NULL
      AND notification_type <> ''
    ORDER BY notification_type
    "
);

if ($typeStmt) {
    $typeStmt->bind_param("i", $propertyId);
    $typeStmt->execute();
    $result = $typeStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $typeRows[] = (string) $row["notification_type"];
    }

    $typeStmt->close();
}

$unreadStmt = $conn->prepare(
    "
    SELECT COUNT(*) AS total
    FROM cpms_notifications
    WHERE property_id = ?
      AND (target_role IS NULL OR target_role = 'Administrator')
      AND (target_user IS NULL OR target_user = ?)
      AND is_read = 0
      AND archived_at IS NULL
      AND deleted_at IS NULL
    "
);

$unreadTotal = 0;

if ($unreadStmt) {
    $unreadStmt->bind_param("is", $propertyId, $adminName);
    $unreadStmt->execute();
    $unreadTotal = (int) (
        $unreadStmt
            ->get_result()
            ->fetch_assoc()["total"] ?? 0
    );
    $unreadStmt->close();
}

$totalPages = max(1, (int) ceil($total / $perPage));
$message = trim((string) ($_GET["message"] ?? ""));
$propertyName = (string) ($currentProperty["name"] ?? "Property");

$conn->close();
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Center | <?php echo e($propertyName); ?></title>
    <link rel="stylesheet" href="css/style.css?v=13">
    <link rel="stylesheet" href="css/pms.css?v=5">
    <style>
        .notification-page {
            max-width: 1180px;
            margin: 24px auto;
            padding: 0 16px 40px;
        }

        .notification-hero {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            margin-bottom: 20px;
            padding: 22px;
            border-radius: 15px;
            background: #3a2419;
            color: #fff;
        }

        .notification-hero h1 {
            margin: 0 0 6px;
            color: #fff;
            font-size: 26px;
        }

        .notification-hero p {
            margin: 0;
            color: rgba(255,255,255,.76);
        }

        .notification-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
        }

        .notification-button,
        .notification-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 13px;
            border: 0;
            border-radius: 8px;
            background: #b59b20;
            color: #fff;
            font-size: 12px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }

        .notification-link.secondary {
            border: 1px solid rgba(255,255,255,.35);
            background: transparent;
        }

        .notification-message {
            margin-bottom: 16px;
            padding: 13px 15px;
            border-left: 4px solid #2f9e44;
            border-radius: 8px;
            background: #edf9f0;
            color: #286436;
        }

        .notification-filter {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 18px;
            padding: 16px;
            border: 1px solid #e3e6e8;
            border-radius: 12px;
            background: #fff;
        }

        .notification-filter input,
        .notification-filter select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccd2d6;
            border-radius: 7px;
            background: #fff;
        }

        .notification-list {
            overflow: hidden;
            border: 1px solid #e1e5e8;
            border-radius: 13px;
            background: #fff;
        }

        .notification-batch {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 13px;
            border-bottom: 1px solid #e7eaec;
            background: #f7f9fa;
        }

        .notification-batch select {
            padding: 9px;
            border: 1px solid #cfd5d9;
            border-radius: 7px;
        }

        .notification-row {
            display: grid;
            grid-template-columns: 28px 44px minmax(0, 1fr) auto;
            gap: 12px;
            align-items: start;
            padding: 16px;
            border-bottom: 1px solid #edf0f2;
        }

        .notification-row:last-child {
            border-bottom: 0;
        }

        .notification-row.unread {
            background: #fff9ee;
        }

        .notification-icon {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 11px;
            background: #eef2f4;
            font-size: 20px;
        }

        .notification-title {
            margin: 0 0 5px;
            color: #273138;
            font-size: 14px;
        }

        .notification-text {
            margin: 0;
            color: #67747d;
            font-size: 12px;
            line-height: 1.55;
        }

        .notification-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 9px;
            color: #849099;
            font-size: 10px;
        }

        .notification-tag {
            padding: 3px 7px;
            border-radius: 999px;
            background: #edf2f4;
            color: #52616a;
            font-weight: 800;
        }

        .priority-high,
        .priority-critical {
            background: #fdeaea;
            color: #a82b2b;
        }

        .priority-medium {
            background: #fff4d6;
            color: #8a6b13;
        }

        .priority-low {
            background: #e9f7ed;
            color: #26723c;
        }

        .notification-open {
            white-space: nowrap;
            padding: 8px 10px;
            border-radius: 7px;
            background: #3a2419;
            color: #fff;
            text-decoration: none;
            font-size: 11px;
            font-weight: 800;
        }

        .notification-empty {
            padding: 38px 16px;
            color: #7a858c;
            text-align: center;
        }

        .notification-pagination {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 6px;
            margin-top: 18px;
        }

        .notification-pagination a,
        .notification-pagination span {
            min-width: 36px;
            padding: 8px 10px;
            border: 1px solid #d7dcdf;
            border-radius: 7px;
            background: #fff;
            color: #3a2419;
            text-align: center;
            text-decoration: none;
        }

        .notification-pagination span {
            background: #b59b20;
            color: #fff;
        }

        @media (max-width: 820px) {
            .notification-filter {
                grid-template-columns: 1fr 1fr;
            }

            .notification-filter input {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 620px) {
            .notification-hero {
                align-items: flex-start;
                flex-direction: column;
            }

            .notification-filter {
                grid-template-columns: 1fr;
            }

            .notification-filter input {
                grid-column: auto;
            }

            .notification-row {
                grid-template-columns: 26px 40px minmax(0, 1fr);
            }

            .notification-open {
                grid-column: 2 / -1;
                width: fit-content;
            }
        }
    </style>
</head>
<body class="admin-body">
<main class="notification-page">

    <section class="notification-hero">
        <div>
            <h1>🔔 Notification Center</h1>
            <p>
                <?php echo e($propertyName); ?> ·
                <?php echo $unreadTotal; ?> belum dibaca
            </p>
        </div>

        <div class="notification-hero-actions">
            <form
                action="admin_notification_mark_all.php"
                method="POST"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($csrfToken); ?>"
                >
                <button
                    type="submit"
                    class="notification-button"
                >
                    Tandakan Semua Dibaca
                </button>
            </form>

            <a
                href="admin_dashboard.php"
                class="notification-link secondary"
            >
                Kembali Dashboard
            </a>
        </div>
    </section>

    <?php if ($message !== ""): ?>
        <div class="notification-message">
            <?php echo e($message); ?>
        </div>
    <?php endif; ?>

    <form
        method="GET"
        action="admin_notifications.php"
        class="notification-filter"
    >
        <input
            type="search"
            name="search"
            value="<?php echo e($search); ?>"
            placeholder="Cari tajuk, mesej atau nombor rujukan..."
        >

        <select name="type">
            <option value="">Semua Jenis</option>
            <?php foreach ($typeRows as $typeOption): ?>
                <option
                    value="<?php echo e($typeOption); ?>"
                    <?php echo $type === $typeOption ? "selected" : ""; ?>
                >
                    <?php echo e($typeOption); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status">
            <option value="active" <?php echo $status === "active" ? "selected" : ""; ?>>
                Semua Aktif
            </option>
            <option value="unread" <?php echo $status === "unread" ? "selected" : ""; ?>>
                Belum Dibaca
            </option>
            <option value="read" <?php echo $status === "read" ? "selected" : ""; ?>>
                Sudah Dibaca
            </option>
            <option value="archived" <?php echo $status === "archived" ? "selected" : ""; ?>>
                Arkib
            </option>
        </select>

        <select name="period">
            <option value="">Semua Tarikh</option>
            <option value="today" <?php echo $period === "today" ? "selected" : ""; ?>>
                Hari Ini
            </option>
            <option value="7" <?php echo $period === "7" ? "selected" : ""; ?>>
                7 Hari
            </option>
            <option value="30" <?php echo $period === "30" ? "selected" : ""; ?>>
                30 Hari
            </option>
        </select>

        <button
            type="submit"
            class="notification-button"
        >
            Tapis
        </button>
    </form>

    <form
        action="admin_notification_action.php"
        method="POST"
        id="notification-batch-form"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo e($csrfToken); ?>"
        >

        <section class="notification-list">
            <div class="notification-batch">
                <label>
                    <input
                        type="checkbox"
                        id="select-all-notifications"
                    >
                    Pilih semua
                </label>

                <select name="action" required>
                    <option value="">Pilih tindakan...</option>
                    <option value="read">Tandakan Dibaca</option>
                    <option value="unread">Tandakan Belum Dibaca</option>
                    <option value="archive">Arkibkan</option>
                    <?php if ($status === "archived"): ?>
                        <option value="restore">Pulihkan daripada Arkib</option>
                    <?php endif; ?>
                    <option value="delete">Padam</option>
                </select>

                <button
                    type="submit"
                    class="notification-button"
                >
                    Laksanakan
                </button>
            </div>

            <?php if (count($notifications) === 0): ?>
                <div class="notification-empty">
                    Tiada notifikasi dijumpai.
                </div>
            <?php else: ?>

                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $notificationType = strtoupper(
                        trim(
                            (string) (
                                $notification["notification_type"] ??
                                "INFO"
                            )
                        )
                    );

                    $icon = match ($notificationType) {
                        "COMPLAINT" => "📝",
                        "WORK_ORDER" => "🛠️",
                        "PATROL", "SECURITY" => "🛡️",
                        "ASSET" => "🏢",
                        "MAINTENANCE" => "🔧",
                        "WARNING" => "⚠️",
                        "SUCCESS" => "✅",
                        default => "🔔"
                    };

                    $priority = strtolower(
                        (string) (
                            $notification["priority"] ??
                            "Medium"
                        )
                    );

                    $url = safeLocalUrl(
                        (string) (
                            $notification["action_url"] ??
                            ""
                        )
                    );
                    ?>

                    <article class="notification-row <?php
                        echo (int) $notification["is_read"] === 0
                            ? "unread"
                            : "";
                    ?>">
                        <input
                            type="checkbox"
                            name="notification_ids[]"
                            value="<?php echo (int) $notification["id"]; ?>"
                            class="notification-checkbox"
                        >

                        <span class="notification-icon">
                            <?php echo $icon; ?>
                        </span>

                        <div>
                            <h2 class="notification-title">
                                <?php echo e((string) $notification["title"]); ?>
                            </h2>

                            <p class="notification-text">
                                <?php
                                echo nl2br(
                                    e(
                                        (string) (
                                            $notification["message"] ??
                                            ""
                                        )
                                    )
                                );
                                ?>
                            </p>

                            <div class="notification-meta">
                                <span class="notification-tag">
                                    <?php echo e($notificationType); ?>
                                </span>

                                <span class="notification-tag priority-<?php echo e($priority); ?>">
                                    <?php echo e(ucfirst($priority)); ?>
                                </span>

                                <?php if (
                                    !empty($notification["reference_no"])
                                ): ?>
                                    <span class="notification-tag">
                                        <?php echo e((string) $notification["reference_no"]); ?>
                                    </span>
                                <?php endif; ?>

                                <span>
                                    <?php
                                    echo e(
                                        notificationDate(
                                            (string) (
                                                $notification["created_at"] ??
                                                ""
                                            )
                                        )
                                    );
                                    ?>
                                </span>
                            </div>
                        </div>

                        <a
                            href="admin_notification_open.php?id=<?php
                                echo (int) $notification["id"];
                            ?>&redirect=<?php echo rawurlencode($url); ?>"
                            class="notification-open"
                        >
                            Buka
                        </a>
                    </article>
                <?php endforeach; ?>

            <?php endif; ?>
        </section>
    </form>

    <?php if ($totalPages > 1): ?>
        <nav class="notification-pagination">
            <?php for ($number = 1; $number <= $totalPages; $number++): ?>
                <?php
                $query = $_GET;
                $query["page"] = $number;
                $href = "admin_notifications.php?" .
                    http_build_query($query);
                ?>

                <?php if ($number === $page): ?>
                    <span><?php echo $number; ?></span>
                <?php else: ?>
                    <a href="<?php echo e($href); ?>">
                        <?php echo $number; ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>

</main>

<script>
const selectAll =
    document.getElementById(
        "select-all-notifications"
    );

const notificationCheckboxes =
    document.querySelectorAll(
        ".notification-checkbox"
    );

if (selectAll) {
    selectAll.addEventListener(
        "change",
        function () {
            notificationCheckboxes.forEach(
                function (checkbox) {
                    checkbox.checked =
                        selectAll.checked;
                }
            );
        }
    );
}

const batchForm =
    document.getElementById(
        "notification-batch-form"
    );

if (batchForm) {
    batchForm.addEventListener(
        "submit",
        function (event) {
            const selected =
                document.querySelectorAll(
                    ".notification-checkbox:checked"
                );

            if (selected.length === 0) {
                event.preventDefault();
                window.alert(
                    "Sila pilih sekurang-kurangnya satu notifikasi."
                );
                return;
            }

            const action =
                batchForm.querySelector(
                    'select[name="action"]'
                ).value;

            if (
                action === "delete" &&
                !window.confirm(
                    "Padam notifikasi yang dipilih?"
                )
            ) {
                event.preventDefault();
            }
        }
    );
}
</script>
</body>
</html>
