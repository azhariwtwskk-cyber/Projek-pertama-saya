<?php

declare(strict_types=1);

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";
require_once __DIR__ . "/cpms/includes/modules.php";
require_once __DIR__ . "/cpms/includes/property_context.php";
require_once __DIR__ . "/cpms/includes/property_guard.php";
require_once __DIR__ . "/cpms/includes/audit_engine.php";
require_once __DIR__ . "/cpms/includes/notification_engine.php";
require_once __DIR__ . "/cpms/includes/notification_delivery_engine.php";

/*
|--------------------------------------------------------------------------
| Legacy V23 Operations Portal Guard
|--------------------------------------------------------------------------
| This root dashboard is dedicated to V23 Malawa Ria only. Property
| selection is reserved for the separate System Owner portal.
*/
if (!isset($_SESSION["admin"])) {
    header("Location: admin_login.php");
    exit();
}

$operationRole = strtolower(trim((string) ($_SESSION["role"] ?? "clerk")));
$allowedOperationRoles = ["property_admin", "manager", "clerk"];
if (!in_array($operationRole, $allowedOperationRoles, true)) {
    $operationRole = "clerk";
}

$canViewPropertyAudit = in_array(
    $operationRole,
    ["property_admin", "manager"],
    true
);

$roleLabels = [
    "property_admin" => $cpmsLanguage === "en" ? "Property Admin" : "Pentadbir Property",
    "manager" => $cpmsLanguage === "en" ? "Manager" : "Pengurus",
    "clerk" => $cpmsLanguage === "en" ? "Clerk" : "Kerani",
];
$operationRoleLabel = $roleLabels[$operationRole] ?? "Kerani";

$v23PropertyId = 0;
$v23Stmt = $conn->prepare(
    "SELECT id FROM cpms_properties
     WHERE is_active = 1
       AND (property_code = ? OR property_name = ?)
     ORDER BY CASE WHEN property_code = ? THEN 0 ELSE 1 END
     LIMIT 1"
);

if ($v23Stmt) {
    $v23Code = "V23";
    $v23Name = "V23 Malawa Ria Apartment";
    $v23Stmt->bind_param("sss", $v23Code, $v23Name, $v23Code);
    $v23Stmt->execute();
    $v23Row = $v23Stmt->get_result()->fetch_assoc();
    $v23Stmt->close();
    $v23PropertyId = (int) ($v23Row["id"] ?? 0);
}

if ($v23PropertyId < 1) {
    http_response_code(503);
    exit("Property V23 Malawa Ria yang aktif tidak dijumpai.");
}

$_SESSION["cpms_current_property_id"] = $v23PropertyId;

$propertyId = cpmsRequireCurrentPropertyId($conn);
$currentProperty = cpmsCurrentProperty($conn);

$notificationUserName =
    (string) (
        $_SESSION["admin"] ??
        ""
    );

$unreadNotificationCount =
    cpmsUnreadNotificationCount(
        $conn,
        $propertyId,
        "Administrator",
        $notificationUserName
    );

if (!$currentProperty) {
    http_response_code(503);
    exit("Tiada property aktif dipilih.");
}

/*
|--------------------------------------------------------------------------
| Branding property semasa
|--------------------------------------------------------------------------
*/

$cpmsSettings["property_name"] =
    (string) $currentProperty["name"];

$cpmsSettings["company_name"] =
    (string) $currentProperty["company_name"];

$cpmsSettings["address"] =
    (string) $currentProperty["address"];

$cpmsSettings["contact_phone"] =
    (string) $currentProperty["phone"];

$cpmsSettings["contact_email"] =
    (string) $currentProperty["email"];

$cpmsSettings["logo_path"] =
    (string) $currentProperty["logo_path"];

$cpmsSettings["primary_color"] =
    (string) $currentProperty["primary_color"];

$cpmsSettings["secondary_color"] =
    (string) $currentProperty["secondary_color"];

$cpmsEnabledModules = cpmsEnabledModules($conn);

$systemSettings = $cpmsSettings;
$isEnglish = $cpmsLanguage === "en";

function e(?string $value): string
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function tableExists(mysqli $conn, string $tableName): bool
{
    $sql = "SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $tableName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)($row["total"] ?? 0) > 0;
}

function columnExists(mysqli $conn, string $tableName, string $columnName): bool
{
    $sql = "SELECT COUNT(*) AS total FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ss", $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)($row["total"] ?? 0) > 0;
}

function statusClass(string $status): string
{
    return match (strtolower(trim($status))) {
        "in progress" => "status-progress",
        "resolved" => "status-resolved",
        "closed" => "status-closed",
        default => "status-pending",
    };
}

function priorityClass(string $priority): string
{
    $priority = strtolower($priority);
    if (str_contains($priority, "tinggi") || str_contains($priority, "high")) {
        return "priority-high";
    }
    if (str_contains($priority, "sederhana") || str_contains($priority, "medium")) {
        return "priority-medium";
    }
    return "priority-low";
}

function formatDateTime(?string $date): string
{
    if ($date === null || trim($date) === "") {
        return "-";
    }
    $timestamp = strtotime($date);
    return $timestamp === false ? $date : date("d/m/Y h:i A", $timestamp);
}


function moduleIsEnabled(
    array $modules,
    string $moduleKey
): bool {
    return
        isset($modules[$moduleKey]) &&
        ($modules[$moduleKey]["enabled"] ?? false) === true;
}

function currentBaseUrl(): string
{
    $isHttps =
        isset($_SERVER["HTTPS"]) &&
        $_SERVER["HTTPS"] !== "" &&
        strtolower((string) $_SERVER["HTTPS"]) !== "off";

    $scheme = $isHttps ? "https" : "http";
    $host = (string) ($_SERVER["HTTP_HOST"] ?? "");

    return $host !== ""
        ? $scheme . "://" . $host
        : "";
}

function normalizeWhatsAppNumber(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? "";

    if ($digits === "") {
        return "";
    }

    if (str_starts_with($digits, "60")) {
        return $digits;
    }

    if (str_starts_with($digits, "0")) {
        return "60" . substr($digits, 1);
    }

    return "60" . $digits;
}

function buildWhatsAppUrl(
    string $phone,
    string $complaintId,
    string $status,
    string $propertyName
): string {
    $number = normalizeWhatsAppNumber($phone);

    if ($number === "") {
        return "";
    }

    $trackingUrl =
        currentBaseUrl() .
        "/track_complaint.php?ref=" .
        rawurlencode($complaintId);

    $message =
        "Salam,\n\n" .
        "Ini adalah makluman daripada Pejabat Pengurusan " .
        $propertyName . ".\n\n" .
        "Nombor rujukan aduan: " . $complaintId . "\n" .
        "Status semasa: " . $status . "\n\n" .
        "Semak perkembangan aduan melalui pautan berikut:\n" .
        $trackingUrl . "\n\n" .
        "Terima kasih.";

    return
        "https://wa.me/" .
        $number .
        "?text=" .
        rawurlencode($message);
}

if (!isset($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

$hasAdminRemarks = columnExists($conn, "complaints", "admin_remarks");
$hasRemarks = columnExists($conn, "complaints", "remarks");
$hasUpdatedAt = columnExists($conn, "complaints", "updated_at");
$hasCreatedAt = columnExists($conn, "complaints", "created_at");
$hasComplaintLogs = tableExists($conn, "complaint_logs");

$successMessage = "";
$errorMessage = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_status"])) {
    $postedToken = (string)($_POST["csrf_token"] ?? "");

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage = "Permintaan tidak sah. Sila muat semula halaman.";
    } else {
        $complaintId = trim((string)($_POST["complaint_id"] ?? ""));
        $newStatus = trim((string)($_POST["status"] ?? ""));
        $remarks = trim((string)($_POST["remarks"] ?? ""));
        $allowedStatuses = ["Pending", "In Progress", "Resolved", "Closed"];

        if ($complaintId === "") {
            $errorMessage = "Nombor rujukan tidak sah.";
        } elseif (!in_array($newStatus, $allowedStatuses, true)) {
            $errorMessage = "Pilihan status tidak sah.";
        } elseif (
            (
                function_exists("mb_strlen")
                    ? mb_strlen($remarks, "UTF-8")
                    : strlen($remarks)
            ) > 2000
        ) {
            $errorMessage = "Catatan admin terlalu panjang.";
        } else {
            try {
                $conn->begin_transaction();

                $oldStatus = "";
                $oldRemarks = "";

                $oldStmt = $conn->prepare(
                    "
                    SELECT
                        status,
                        phone,
                        email,
                        name,
                        " . (
                            $hasAdminRemarks
                                ? "admin_remarks"
                                : (
                                    $hasRemarks
                                        ? "remarks"
                                        : "NULL AS remarks"
                                )
                        ) . "
                    FROM complaints
                    WHERE complaint_id = ?
                      AND property_id = ?
                    LIMIT 1
                    "
                );

                if (!$oldStmt) {
                    throw new RuntimeException(
                        "Rekod aduan semasa tidak dapat dibaca."
                    );
                }

                $oldStmt->bind_param(
                    "si",
                    $complaintId,
                    $propertyId
                );

                $oldStmt->execute();

                $oldRow =
                    $oldStmt
                        ->get_result()
                        ->fetch_assoc();

                $oldStmt->close();

                if (!$oldRow) {
                    throw new RuntimeException(
                        "Aduan tidak dijumpai dalam property semasa."
                    );
                }

                $oldStatus =
                    (string) ($oldRow["status"] ?? "");

                $oldRemarks =
                    (string) (
                        $oldRow["admin_remarks"] ??
                        $oldRow["remarks"] ??
                        ""
                    );

                $residentPhone =
                    trim(
                        (string) (
                            $oldRow["phone"] ??
                            ""
                        )
                    );

                $residentEmail =
                    trim(
                        (string) (
                            $oldRow["email"] ??
                            ""
                        )
                    );

                $residentName =
                    trim(
                        (string) (
                            $oldRow["name"] ??
                            "Resident"
                        )
                    );

                $fields = ["status = ?"];
                $types = "s";
                $values = [$newStatus];

                if ($hasAdminRemarks) {
                    $fields[] = "admin_remarks = ?";
                    $types .= "s";
                    $values[] = $remarks;
                } elseif ($hasRemarks) {
                    $fields[] = "remarks = ?";
                    $types .= "s";
                    $values[] = $remarks;
                }

                if ($hasUpdatedAt) {
                    $fields[] = "updated_at = NOW()";
                }

                $types .= "si";
                $values[] = $complaintId;
                $values[] = $propertyId;

                $sql =
                    "UPDATE complaints SET " .
                    implode(", ", $fields) .
                    " WHERE complaint_id = ? AND property_id = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException("Gagal menyediakan kemas kini status.");
                }
                $stmt->bind_param($types, ...$values);
                if (!$stmt->execute()) {
                    throw new RuntimeException("Status aduan gagal dikemas kini.");
                }
                $stmt->close();

                if ($hasComplaintLogs) {
                    $logHasRemarks = columnExists($conn, "complaint_logs", "remarks");
                    $logHasUpdatedBy = columnExists($conn, "complaint_logs", "updated_by");
                    $logHasCreatedAt = columnExists($conn, "complaint_logs", "created_at");

                    $columns = ["complaint_id", "status"];
                    $placeholders = ["?", "?"];
                    $logTypes = "ss";
                    $logValues = [$complaintId, $newStatus];

                    if ($logHasRemarks) {
                        $columns[] = "remarks";
                        $placeholders[] = "?";
                        $logTypes .= "s";
                        $logValues[] = $remarks;
                    }
                    if ($logHasUpdatedBy) {
                        $columns[] = "updated_by";
                        $placeholders[] = "?";
                        $logTypes .= "s";
                        $logValues[] = (string)$_SESSION["admin"];
                    }
                    if ($logHasCreatedAt) {
                        $columns[] = "created_at";
                        $placeholders[] = "NOW()";
                    }

                    $logSql = "INSERT INTO complaint_logs (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";
                    $logStmt = $conn->prepare($logSql);
                    if ($logStmt) {
                        $logStmt->bind_param($logTypes, ...$logValues);
                        $logStmt->execute();
                        $logStmt->close();
                    }
                }

                cpmsAuditAdmin(
                    $conn,
                    $propertyId,
                    "Complaint",
                    "UPDATE_STATUS",
                    $complaintId,
                    $complaintId,
                    "Complaint status updated.",
                    [
                        "status" => $oldStatus,
                        "remarks" => $oldRemarks
                    ],
                    [
                        "status" => $newStatus,
                        "remarks" => $remarks
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Queue notification delivery to resident
                |--------------------------------------------------------------------------
                */

                $residentMessage =
                    "Salam " .
                    $residentName .
                    ",\n\n" .
                    "Status aduan anda telah dikemas kini.\n\n" .
                    "No. Rujukan: " .
                    $complaintId .
                    "\n" .
                    "Status Lama: " .
                    $oldStatus .
                    "\n" .
                    "Status Baharu: " .
                    $newStatus .
                    "\n\n" .
                    (
                        $remarks !== ""
                            ? "Catatan Pengurusan: " .
                                $remarks .
                                "\n\n"
                            : ""
                    ) .
                    "Semak status melalui:\n" .
                    currentBaseUrl() .
                    "/track_complaint.php?ref=" .
                    rawurlencode($complaintId) .
                    "\n\n" .
                    "Terima kasih.\n" .
                    (
                        $currentProperty["name"] ??
                        cpmsPropertyName()
                    );

                if ($residentPhone !== "") {
                    cpmsQueueWhatsApp(
                        $conn,
                        $propertyId,
                        $residentPhone,
                        $residentMessage,
                        $residentName,
                        $complaintId
                    );
                }

                if ($residentEmail !== "") {
                    cpmsQueueEmail(
                        $conn,
                        $propertyId,
                        $residentEmail,
                        "Kemas Kini Status Aduan " .
                            $complaintId,
                        $residentMessage,
                        $residentName,
                        $complaintId
                    );
                }

                $conn->commit();
                $successMessage = "Status aduan {$complaintId} berjaya dikemas kini.";
            } catch (Throwable $error) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                }
                error_log("Dashboard update error: " . $error->getMessage());
                $errorMessage = "Status tidak dapat dikemas kini. Sila cuba semula.";
            }
        }
    }
}

$statistics = [
    "total" => 0,
    "pending" => 0,
    "progress" => 0,
    "resolved" => 0,
    "closed" => 0
];

$statsStmt = $conn->prepare(
    "
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Pending') AS pending,
        SUM(status = 'In Progress') AS progress,
        SUM(status = 'Resolved') AS resolved,
        SUM(status = 'Closed') AS closed
    FROM complaints
    WHERE property_id = ?
    "
);

if ($statsStmt) {
    $statsStmt->bind_param("i", $propertyId);
    $statsStmt->execute();

    $row =
        $statsStmt
            ->get_result()
            ->fetch_assoc();

    foreach ($statistics as $key => $value) {
        $statistics[$key] =
            (int) ($row[$key] ?? 0);
    }

    $statsStmt->close();
}

$search = trim((string)($_GET["search"] ?? ""));
$statusFilter = trim((string)($_GET["status"] ?? ""));
$priorityFilter = trim((string)($_GET["priority"] ?? ""));
$allowedStatuses = ["Pending", "In Progress", "Resolved", "Closed"];
$allowedPriorities = ["Tinggi / High", "Sederhana / Medium", "Rendah / Low"];

$where = ["property_id = ?"];
$types = "i";
$values = [$propertyId];

if ($search !== "") {
    $where[] = "(complaint_id LIKE ? OR name LIKE ? OR phone LIKE ? OR unit_no LIKE ? OR subject LIKE ? OR category LIKE ?)";
    $like = "%{$search}%";
    for ($i = 0; $i < 6; $i++) {
        $types .= "s";
        $values[] = $like;
    }
}
if ($statusFilter !== "" && in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = "status = ?";
    $types .= "s";
    $values[] = $statusFilter;
}
if ($priorityFilter !== "" && in_array($priorityFilter, $allowedPriorities, true)) {
    $where[] = "priority = ?";
    $types .= "s";
    $values[] = $priorityFilter;
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
$orderBy = $hasCreatedAt ? "created_at DESC" : "complaint_id DESC";
$listStmt = $conn->prepare("SELECT * FROM complaints {$whereSql} ORDER BY {$orderBy}");
$complaints = [];
if ($listStmt) {
    if ($types !== "") {
        $listStmt->bind_param($types, ...$values);
    }
    $listStmt->execute();
    $result = $listStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $complaints[] = $row;
    }
    $listStmt->close();
}


/*
|--------------------------------------------------------------------------
| Ambil semua gambar aduan
|--------------------------------------------------------------------------
*/

$complaintImages = [];

if (
    tableExists(
        $conn,
        "complaint_images"
    )
) {
    $imageStmt = $conn->prepare(
        "
        SELECT
            complaint_id,
            image_name
        FROM complaint_images
        WHERE property_id = ?
        ORDER BY id ASC
        "
    );

    if ($imageStmt) {
        $imageStmt->bind_param("i", $propertyId);
        $imageStmt->execute();

        $imageResult =
            $imageStmt->get_result();

        while (
            $imageRow =
            $imageResult->fetch_assoc()
        ) {
            $imageComplaintId =
                (string) $imageRow["complaint_id"];

            if (
                !isset(
                    $complaintImages[
                        $imageComplaintId
                    ]
                )
            ) {
                $complaintImages[
                    $imageComplaintId
                ] = [];
            }

            $complaintImages[
                $imageComplaintId
            ][] =
                (string) $imageRow["image_name"];
        }

        $imageStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Data carta dashboard
|--------------------------------------------------------------------------
*/

$chartRows = [];

$chartStmt = $conn->prepare(
    "
    SELECT
        complaint_id,
        category,
        block,
        status
    FROM complaints
    WHERE property_id = ?
    "
);

if ($chartStmt) {
    $chartStmt->bind_param("i", $propertyId);
    $chartStmt->execute();

    $chartResult =
        $chartStmt->get_result();

    while ($chartRow = $chartResult->fetch_assoc()) {
        $chartRows[] = $chartRow;
    }

    $chartStmt->close();
}

/*
|--------------------------------------------------------------------------
| Carta kategori
|--------------------------------------------------------------------------
*/

$categoryCounts = [];

foreach ($chartRows as $chartRow) {
    $categoryName = trim(
        (string) ($chartRow["category"] ?? "Tidak Diketahui")
    );

    if ($categoryName === "") {
        $categoryName = "Tidak Diketahui";
    }

    if (!isset($categoryCounts[$categoryName])) {
        $categoryCounts[$categoryName] = 0;
    }

    $categoryCounts[$categoryName]++;
}

arsort($categoryCounts);

$topCategoryCounts = array_slice(
    $categoryCounts,
    0,
    6,
    true
);

$maxCategoryCount =
    count($topCategoryCounts) > 0
        ? max($topCategoryCounts)
        : 1;

/*
|--------------------------------------------------------------------------
| Analitik mengikut blok
|--------------------------------------------------------------------------
*/

$blockCounts = [];

foreach ($chartRows as $chartRow) {
    $blockName = trim(
        (string) ($chartRow["block"] ?? "")
    );

    if ($blockName === "") {
        $blockName = "Tidak Diketahui";
    }

    if (!isset($blockCounts[$blockName])) {
        $blockCounts[$blockName] = 0;
    }

    $blockCounts[$blockName]++;
}

arsort($blockCounts);

$topBlockName =
    count($blockCounts) > 0
        ? (string) array_key_first($blockCounts)
        : "-";

$topBlockTotal =
    count($blockCounts) > 0
        ? (int) reset($blockCounts)
        : 0;

$topCategoryName =
    count($categoryCounts) > 0
        ? (string) array_key_first($categoryCounts)
        : "-";

$topCategoryTotal =
    count($categoryCounts) > 0
        ? (int) reset($categoryCounts)
        : 0;

/*
|--------------------------------------------------------------------------
| Carta 6 bulan terakhir
|--------------------------------------------------------------------------
*/

$monthLabels = [];
$monthCounts = [];

$currentMonth = new DateTimeImmutable("first day of this month");

for ($i = 5; $i >= 0; $i--) {
    $monthDate = $currentMonth->modify("-{$i} months");
    $monthKey = $monthDate->format("Y-m");

    $monthLabels[$monthKey] =
        $monthDate->format("M Y");

    $monthCounts[$monthKey] = 0;
}

foreach ($chartRows as $chartRow) {
    $complaintReference = (string) (
        $chartRow["complaint_id"] ?? ""
    );

    if (
        preg_match(
            '/^[A-Z0-9]+-(\d{2})(\d{2})(\d{2})-/',
            $complaintReference,
            $dateParts
        )
    ) {
        $year = 2000 + (int) $dateParts[1];
        $month = (int) $dateParts[2];

        if ($month >= 1 && $month <= 12) {
            $monthKey = sprintf(
                "%04d-%02d",
                $year,
                $month
            );

            if (array_key_exists($monthKey, $monthCounts)) {
                $monthCounts[$monthKey]++;
            }
        }
    }
}

$maxMonthCount =
    count($monthCounts) > 0
        ? max($monthCounts)
        : 1;

if ($maxMonthCount < 1) {
    $maxMonthCount = 1;
}

/*
|--------------------------------------------------------------------------
| Ringkasan prestasi
|--------------------------------------------------------------------------
*/

$completedComplaints =
    $statistics["resolved"] +
    $statistics["closed"];

$resolutionRate =
    $statistics["total"] > 0
        ? round(
            (
                $completedComplaints /
                $statistics["total"]
            ) * 100
        )
        : 0;

$currentMonthKey = date("Y-m");

$currentMonthTotal =
    (int) ($monthCounts[$currentMonthKey] ?? 0);

$todayComplaintCount = 0;

if ($hasCreatedAt) {
    $todayStmt = $conn->prepare(
        "
        SELECT COUNT(*) AS total
        FROM complaints
        WHERE property_id = ?
          AND DATE(created_at) = CURDATE()
        "
    );

    if ($todayStmt) {
        $todayStmt->bind_param("i", $propertyId);
        $todayStmt->execute();

        $todayRow =
            $todayStmt
                ->get_result()
                ->fetch_assoc();

        $todayComplaintCount =
            (int) ($todayRow["total"] ?? 0);

        $todayStmt->close();
    }
}

$averageResolutionHours = 0.0;

if ($hasCreatedAt && $hasUpdatedAt) {
    $averageStmt = $conn->prepare(
        "
        SELECT
            AVG(
                TIMESTAMPDIFF(
                    HOUR,
                    created_at,
                    updated_at
                )
            ) AS average_hours
        FROM complaints
        WHERE property_id = ?
          AND status IN ('Resolved', 'Closed')
          AND created_at IS NOT NULL
          AND updated_at IS NOT NULL
        "
    );

    if ($averageStmt) {
        $averageStmt->bind_param("i", $propertyId);
        $averageStmt->execute();

        $averageRow =
            $averageStmt
                ->get_result()
                ->fetch_assoc();

        $averageResolutionHours = round(
            (float) (
                $averageRow["average_hours"] ??
                0
            ),
            1
        );

        $averageStmt->close();
    }
}

if ($averageResolutionHours >= 24) {
    $averageResolutionDisplay =
        round($averageResolutionHours / 24, 1) .
        " " .
        ($isEnglish ? "days" : "hari");
} elseif ($averageResolutionHours > 0) {
    $averageResolutionDisplay =
        $averageResolutionHours .
        " " .
        ($isEnglish ? "hours" : "jam");
} else {
    $averageResolutionDisplay = "-";
}

$outstandingTotal =
    $statistics["pending"] +
    $statistics["progress"];

$executiveSummary =
    $isEnglish
        ? (
            "A total of " .
            $currentMonthTotal .
            " complaint(s) were recorded this month. " .
            $completedComplaints .
            " complaint(s) have been resolved or closed, giving a resolution rate of " .
            $resolutionRate .
            "%. " .
            "The highest complaint category is " .
            $topCategoryName .
            " (" .
            $topCategoryTotal .
            "), while Block " .
            $topBlockName .
            " recorded the highest total (" .
            $topBlockTotal .
            ")."
        )
        : (
            "Sebanyak " .
            $currentMonthTotal .
            " aduan direkodkan pada bulan ini. " .
            $completedComplaints .
            " aduan telah diselesaikan atau ditutup dengan kadar penyelesaian " .
            $resolutionRate .
            "%. " .
            "Kategori aduan tertinggi ialah " .
            $topCategoryName .
            " (" .
            $topCategoryTotal .
            "), manakala Blok " .
            $topBlockName .
            " merekodkan jumlah tertinggi (" .
            $topBlockTotal .
            ")."
        );


/*
|--------------------------------------------------------------------------
| Notification Bell 5.8B-1 — latest notifications
|--------------------------------------------------------------------------
*/

$latestAdminNotifications = [];

$notificationStmt = $conn->prepare(
    "
    SELECT
        id,
        notification_type,
        title,
        message,
        reference_no,
        action_url,
        is_read,
        created_at
    FROM cpms_notifications
    WHERE property_id = ?
      AND (
            target_role IS NULL
            OR target_role = 'Administrator'
      )
      AND (
            target_user IS NULL
            OR target_user = ?
      )
    ORDER BY created_at DESC
    LIMIT 8
    "
);

if ($notificationStmt) {
    $notificationStmt->bind_param(
        "is",
        $propertyId,
        $notificationUserName
    );

    $notificationStmt->execute();
    $notificationResult =
        $notificationStmt->get_result();

    while (
        $notificationRow =
        $notificationResult->fetch_assoc()
    ) {
        $latestAdminNotifications[] =
            $notificationRow;
    }

    $notificationStmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo e($cpmsLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | <?php
        echo e(
            setting(
                $systemSettings,
                "property_name",
                "V23 Malawa Ria Apartment"
            )
        );
    ?></title>
    <link rel="stylesheet" href="css/style.css?v=13">
    <link rel="stylesheet" href="css/pms.css?v=5">

    <style>
        :root {
            --cpms-primary: <?php
                echo e(
                    setting(
                        $systemSettings,
                        "primary_color",
                        "#3a2419"
                    )
                );
            ?>;

            --cpms-secondary: <?php
                echo e(
                    setting(
                        $systemSettings,
                        "secondary_color",
                        "#b59b20"
                    )
                );
            ?>;
        }

        .admin-header {
            border-top: 5px solid var(--cpms-secondary);
        }

        .admin-header-info h1,
        .pms-admin-quick-menu-header h2 {
            color: var(--cpms-primary);
        }

        .pms-admin-quick-link:hover {
            border-color: var(--cpms-secondary);
        }

        .report-dashboard-button,
        .export-button,
        .filter-submit-button,
        .update-status-button {
            background-color: var(--cpms-secondary);
        }

        .cpms-commercial-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 22px;
            align-items: center;
            margin: 0 0 24px;
            padding: 26px;
            border-radius: 16px;
            background:
                linear-gradient(
                    135deg,
                    var(--cpms-primary),
                    color-mix(
                        in srgb,
                        var(--cpms-primary) 78%,
                        #000000
                    )
                );
            color: #ffffff;
            box-shadow: 0 12px 30px rgba(0,0,0,.12);
        }

        .cpms-commercial-hero h2 {
            margin: 0 0 7px;
            color: #ffffff;
            font-size: clamp(22px, 4vw, 32px);
        }

        .cpms-commercial-hero p {
            margin: 0;
            color: rgba(255,255,255,.82);
            line-height: 1.6;
        }

        .cpms-hero-meta {
            display: grid;
            gap: 7px;
            min-width: 190px;
            padding: 15px;
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 12px;
            background: rgba(255,255,255,.08);
            font-size: 12px;
        }

        .cpms-hero-meta strong {
            color: #ffffff;
        }

        .cpms-language-switcher {
            display: flex;
            gap: 7px;
            justify-content: flex-end;
            margin-top: 8px;
        }

        .cpms-language-switcher a {
            min-width: 38px;
            padding: 7px 9px;
            border: 1px solid rgba(255,255,255,.55);
            border-radius: 7px;
            color: #ffffff;
            text-align: center;
            text-decoration: none;
            font-size: 11px;
            font-weight: 800;
        }

        .cpms-language-switcher a.active,
        .cpms-language-switcher a:hover {
            border-color: var(--cpms-secondary);
            background: var(--cpms-secondary);
        }

        .cpms-executive-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 25px;
        }

        .cpms-executive-card {
            padding: 18px;
            border: 1px solid #e7e7e7;
            border-top: 4px solid var(--cpms-secondary);
            border-radius: 13px;
            background: #ffffff;
            box-shadow: 0 5px 17px rgba(0,0,0,.06);
        }

        .cpms-executive-card span {
            display: block;
            margin-bottom: 8px;
            color: #777777;
            font-size: 12px;
            font-weight: 700;
        }

        .cpms-executive-card strong {
            display: block;
            color: var(--cpms-primary);
            font-size: 28px;
        }

        .cpms-quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 0 0 25px;
        }

        .cpms-quick-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--cpms-primary);
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }

        .cpms-quick-actions a.highlight {
            background: var(--cpms-secondary);
        }

        @media screen and (max-width: 900px) {
            .cpms-executive-cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media screen and (max-width: 650px) {
            .cpms-commercial-hero {
                grid-template-columns: 1fr;
            }

            .cpms-hero-meta {
                min-width: 0;
            }

            .cpms-executive-cards {
                grid-template-columns: 1fr;
            }
        }

        .cpms-3b-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .cpms-3b-card {
            padding: 18px;
            border: 1px solid #e7e7e7;
            border-radius: 13px;
            background: #ffffff;
            box-shadow: 0 5px 17px rgba(0,0,0,.06);
        }

        .cpms-3b-card span {
            display: block;
            margin-bottom: 7px;
            color: #777777;
            font-size: 12px;
            font-weight: 700;
        }

        .cpms-3b-card strong {
            color: var(--cpms-primary);
            font-size: 22px;
        }

        .cpms-executive-summary {
            margin-bottom: 25px;
            padding: 20px;
            border-left: 5px solid var(--cpms-secondary);
            border-radius: 12px;
            background: #fffaf0;
            color: #4b4b4b;
            line-height: 1.75;
            box-shadow: 0 5px 17px rgba(0,0,0,.04);
        }

        .cpms-executive-summary h2 {
            margin: 0 0 8px;
            color: var(--cpms-primary);
            font-size: 18px;
        }

        .cpms-live-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            color: #666666;
            font-size: 12px;
        }

        .cpms-live-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #22a447;
            box-shadow: 0 0 0 4px rgba(34,164,71,.13);
        }

        @media screen and (max-width: 850px) {
            .cpms-3b-grid {
                grid-template-columns: 1fr;
            }
        }

        .cpms-property-switcher {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            margin-top: 10px;
        }

        .cpms-property-switcher select {
            width: 100%;
            min-width: 190px;
            padding: 8px 10px;
            border: 1px solid rgba(255,255,255,.45);
            border-radius: 8px;
            background: rgba(255,255,255,.12);
            color: #ffffff;
            font-weight: 700;
        }

        .cpms-property-switcher option {
            color: #222222;
            background: #ffffff;
        }

        .cpms-property-switcher button {
            padding: 8px 11px;
            border: 0;
            border-radius: 8px;
            background: var(--cpms-secondary);
            color: #ffffff;
            font-weight: 800;
            cursor: pointer;
        }

        .cpms-property-switcher-note {
            margin-top: 6px;
            color: rgba(255,255,255,.72);
            font-size: 11px;
        }

        @media screen and (max-width: 520px) {
            .cpms-property-switcher {
                grid-template-columns: 1fr;
            }
        }

        .cpms-notification-link {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 8px 11px;
            border: 1px solid rgba(255,255,255,.45);
            border-radius: 8px;
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }

        .cpms-notification-link:hover {
            border-color: var(--cpms-secondary);
            background: var(--cpms-secondary);
            color: #ffffff;
        }

        .cpms-notification-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 21px;
            height: 21px;
            padding: 0 6px;
            border-radius: 99px;
            background: #d93636;
            color: #ffffff;
            font-size: 10px;
            font-weight: 900;
        }

        .cpms-notification-count.zero {
            background: rgba(255,255,255,.22);
        }

        .whatsapp-resident-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            background-color: #25d366;
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
            cursor: pointer;
            transition:
                transform 0.2s ease,
                background-color 0.2s ease;
        }

        .whatsapp-resident-button:hover {
            background-color: #1eae54;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .whatsapp-detail-box {
            margin-top: 20px;
            padding: 18px;
            border: 1px solid #bfe8cd;
            border-radius: 10px;
            background-color: #effbf3;
        }

        .whatsapp-detail-box h3 {
            margin: 0 0 8px;
            color: #176b36;
        }

        .whatsapp-detail-box p {
            margin: 0 0 14px;
            color: #41674d;
            line-height: 1.6;
        }

        /* ==========================================
           DASHBOARD CHARTS
        ========================================== */

        .dashboard-analytics {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1.35fr);
            gap: 22px;
            margin-bottom: 28px;
        }

        .analytics-card {
            min-width: 0;
            padding: 24px;
            background: #ffffff;
            border: 1px solid #e5e5e5;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.07);
        }

        .analytics-card-wide {
            grid-column: 1 / -1;
        }

        .analytics-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
        }

        .analytics-heading h2 {
            margin: 0 0 5px;
            color: #333333;
            font-size: 19px;
        }

        .analytics-heading p {
            margin: 0;
            color: #777777;
            font-size: 13px;
            line-height: 1.5;
        }

        .analytics-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 11px;
            border-radius: 20px;
            background: #fff7dc;
            color: #8f7918;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .status-donut-layout {
            display: grid;
            grid-template-columns: 190px 1fr;
            align-items: center;
            gap: 24px;
        }

        .status-donut {
            width: 180px;
            height: 180px;
            margin: 0 auto;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background:
                conic-gradient(
                    #f39c12 0 var(--pending-end),
                    #3498db var(--pending-end) var(--progress-end),
                    #27ae60 var(--progress-end) var(--resolved-end),
                    #7f8c8d var(--resolved-end) 100%
                );
            position: relative;
        }

        .status-donut::before {
            content: "";
            position: absolute;
            width: 112px;
            height: 112px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: inset 0 0 0 1px #eeeeee;
        }

        .status-donut-center {
            position: relative;
            z-index: 1;
            text-align: center;
        }

        .status-donut-center strong {
            display: block;
            color: #333333;
            font-size: 34px;
            line-height: 1;
        }

        .status-donut-center span {
            display: block;
            margin-top: 6px;
            color: #777777;
            font-size: 12px;
            font-weight: 700;
        }

        .chart-legend {
            display: grid;
            gap: 12px;
        }

        .legend-row {
            display: grid;
            grid-template-columns: 12px 1fr auto;
            align-items: center;
            gap: 10px;
            color: #555555;
            font-size: 13px;
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .legend-pending { background: #f39c12; }
        .legend-progress { background: #3498db; }
        .legend-resolved { background: #27ae60; }
        .legend-closed { background: #7f8c8d; }

        .legend-row strong {
            color: #333333;
        }

        .performance-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .performance-item {
            padding: 20px;
            border: 1px solid #eadfb8;
            border-radius: 12px;
            background: #fffaf0;
            text-align: center;
        }

        .performance-item strong {
            display: block;
            margin-bottom: 7px;
            color: #b59b20;
            font-size: 35px;
        }

        .performance-item span {
            color: #555555;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.5;
        }

        .css-bar-chart {
            display: grid;
            gap: 15px;
        }

        .css-bar-row {
            display: grid;
            grid-template-columns: minmax(140px, 220px) 1fr 42px;
            align-items: center;
            gap: 14px;
        }

        .css-bar-label {
            overflow: hidden;
            color: #444444;
            font-size: 13px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .css-bar-track {
            height: 30px;
            overflow: hidden;
            background: #eeeeee;
            border-radius: 20px;
        }

        .css-bar-fill {
            width: var(--bar-width);
            min-width: 0;
            height: 100%;
            display: flex;
            align-items: center;
            border-radius: 20px;
            background:
                linear-gradient(
                    90deg,
                    #c7a35b,
                    #8f6b2f
                );
            transition: width 0.5s ease;
        }

        .css-bar-value {
            color: #555555;
            font-size: 13px;
            font-weight: 800;
            text-align: right;
        }

        .monthly-chart {
            min-height: 245px;
            display: grid;
            grid-template-columns: repeat(6, minmax(55px, 1fr));
            align-items: end;
            gap: 18px;
            padding-top: 15px;
            border-bottom: 1px solid #dddddd;
        }

        .monthly-column {
            height: 210px;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
        }

        .monthly-value {
            color: #555555;
            font-size: 12px;
            font-weight: 800;
        }

        .monthly-bar {
            width: min(58px, 75%);
            height: var(--month-height);
            min-height: 5px;
            border-radius: 9px 9px 0 0;
            background:
                linear-gradient(
                    180deg,
                    #c7a35b,
                    #8f6b2f
                );
            transition: height 0.5s ease;
        }

        .monthly-label {
            min-height: 34px;
            color: #666666;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.35;
            text-align: center;
        }

        .chart-empty {
            padding: 25px;
            border-radius: 10px;
            background: #f7f7f7;
            color: #777777;
            text-align: center;
        }

        @media screen and (max-width: 900px) {
            .dashboard-analytics {
                grid-template-columns: 1fr;
            }

            .analytics-card-wide {
                grid-column: auto;
            }

            .status-donut-layout {
                grid-template-columns: 1fr;
            }
        }


        .admin-evidence-gallery {
            display: grid;
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 15px;
        }

        .admin-evidence-gallery a {
            display: block;
            overflow: hidden;
            border: 1px solid #dddddd;
            border-radius: 10px;
            background: #ffffff;
            text-decoration: none;
        }

        .admin-evidence-gallery img {
            display: block;
            width: 100%;
            height: 170px;
            object-fit: cover;
        }

        .admin-evidence-gallery span {
            display: block;
            padding: 9px;
            color: #555555;
            font-size: 12px;
            font-weight: 700;
            text-align: center;
        }

        @media screen and (max-width: 600px) {
            .whatsapp-resident-button {
                width: 100%;
            }

            .analytics-card {
                padding: 19px 15px;
            }

            .analytics-heading {
                flex-direction: column;
            }

            .performance-grid {
                grid-template-columns: 1fr;
            }

            .css-bar-row {
                grid-template-columns: 1fr 38px;
                gap: 7px 10px;
            }

            .css-bar-label {
                grid-column: 1 / -1;
                white-space: normal;
            }

            .monthly-chart {
                grid-template-columns: repeat(6, 70px);
                gap: 12px;
                overflow-x: auto;
                padding-bottom: 5px;
            }

            .status-donut {
                width: 165px;
                height: 165px;
            }

            .status-donut::before {
                width: 102px;
                height: 102px;
            }
        }

        /* ==========================================
           PMS QUICK MANAGEMENT MENU
        ========================================== */

        .pms-admin-quick-menu {
            margin: 24px 0 28px;
            padding: 22px;
            background: #ffffff;
            border: 1px solid #e5e5e5;
            border-radius: 14px;
            box-shadow: 0 5px 18px rgba(0, 0, 0, 0.08);
        }

        .pms-admin-quick-menu-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 18px;
        }

        .pms-admin-quick-menu-header h2 {
            margin: 0 0 5px;
            color: #3a2419;
            font-size: 21px;
        }

        .pms-admin-quick-menu-header p {
            margin: 0;
            color: #777777;
            font-size: 13px;
        }

        .pms-admin-quick-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .pms-admin-quick-link {
            display: block;
            min-width: 0;
            padding: 18px 16px;
            border: 1px solid #eadfb8;
            border-radius: 11px;
            background: #fffaf0;
            color: #333333;
            text-decoration: none;
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease,
                border-color 0.2s ease;
        }

        .pms-admin-quick-link:hover {
            transform: translateY(-3px);
            border-color: #b59b20;
            box-shadow: 0 10px 24px rgba(181, 155, 32, 0.16);
        }

        .pms-admin-quick-icon {
            display: block;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .pms-admin-quick-link strong {
            display: block;
            margin-bottom: 6px;
            color: #4a2b20;
            font-size: 14px;
        }

        .pms-admin-quick-link span {
            display: block;
            color: #777777;
            font-size: 11px;
            line-height: 1.5;
        }

        @media screen and (max-width: 900px) {
            .pms-admin-quick-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media screen and (max-width: 520px) {
            .pms-admin-quick-menu {
                padding: 18px 14px;
            }

            .pms-admin-quick-grid {
                grid-template-columns: 1fr;
            }

            .pms-admin-quick-menu-header {
                align-items: flex-start;
                flex-direction: column;
            }
        }


        /* ==========================================
           CPMS NOTIFICATION BELL 5.8B-1
        ========================================== */

        .cpms-bell-wrap {
            position: relative;
            display: inline-flex;
            justify-content: flex-end;
        }

        .cpms-bell-button {
            position: relative;
            width: 44px;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255,255,255,.45);
            border-radius: 50%;
            background: rgba(255,255,255,.10);
            color: #ffffff;
            font-size: 20px;
            cursor: pointer;
            transition: background .18s ease, transform .18s ease;
        }

        .cpms-bell-button:hover,
        .cpms-bell-button[aria-expanded="true"] {
            background: var(--cpms-secondary);
            transform: translateY(-1px);
        }

        .cpms-bell-badge {
            position: absolute;
            top: -6px;
            right: -7px;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
            border-radius: 999px;
            background: #d93636;
            color: #ffffff;
            font-size: 10px;
            font-weight: 900;
        }

        .cpms-bell-badge.is-zero {
            display: none;
        }

        .cpms-bell-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            z-index: 3000;
            width: min(390px, calc(100vw - 28px));
            overflow: hidden;
            border: 1px solid #e1e5e8;
            border-radius: 14px;
            background: #ffffff;
            color: #263238;
            box-shadow: 0 18px 48px rgba(0,0,0,.22);
        }

        .cpms-bell-dropdown[hidden] {
            display: none;
        }

        .cpms-bell-dropdown-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid #eceff1;
        }

        .cpms-bell-dropdown-head strong {
            color: var(--cpms-primary);
            font-size: 15px;
        }

        .cpms-bell-dropdown-head span {
            color: #77838c;
            font-size: 11px;
        }

        .cpms-bell-list {
            max-height: 410px;
            overflow-y: auto;
        }

        .cpms-bell-item {
            display: grid;
            grid-template-columns: 38px minmax(0, 1fr);
            gap: 11px;
            padding: 13px 15px;
            border-bottom: 1px solid #eef1f2;
            color: inherit;
            text-decoration: none;
            transition: background .15s ease;
        }

        .cpms-bell-item:hover {
            background: #f7f9fa;
        }

        .cpms-bell-item.unread {
            background: #fff8ed;
        }

        .cpms-bell-item.unread:hover {
            background: #fff1dc;
        }

        .cpms-bell-icon {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #eef2f4;
            font-size: 18px;
        }

        .cpms-bell-content {
            min-width: 0;
        }

        .cpms-bell-title {
            display: block;
            margin-bottom: 4px;
            overflow: hidden;
            color: #2d3840;
            font-size: 13px;
            font-weight: 900;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cpms-bell-message {
            display: -webkit-box;
            overflow: hidden;
            color: #65727b;
            font-size: 12px;
            line-height: 1.45;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .cpms-bell-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 7px;
            color: #87929a;
            font-size: 10px;
        }

        .cpms-bell-type {
            padding: 3px 7px;
            border-radius: 999px;
            background: #edf2f5;
            color: #53616a;
            font-weight: 900;
            text-transform: uppercase;
        }

        .cpms-bell-empty {
            padding: 26px 16px;
            color: #75818a;
            font-size: 13px;
            text-align: center;
        }

        .cpms-bell-footer {
            display: block;
            padding: 13px 16px;
            background: #fafbfb;
            color: var(--cpms-primary);
            font-size: 12px;
            font-weight: 900;
            text-align: center;
            text-decoration: none;
        }

        .cpms-bell-footer:hover {
            background: #f2f4f5;
        }

        @media screen and (max-width: 560px) {
            .cpms-bell-dropdown {
                position: fixed;
                top: 72px;
                left: 12px;
                right: 12px;
                width: auto;
            }
        }

    
        /* ==========================================================
           CPMS ADMIN DASHBOARD V2 — STEP 1
           Professional sidebar and responsive application shell
        ========================================================== */

        * {
            box-sizing: border-box;
        }

        body.admin-body {
            margin: 0;
            min-height: 100vh;
            background: #f3f5f7;
        }

        .cpms-app-shell {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            min-height: 100vh;
        }

        .cpms-sidebar {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            padding: 22px 16px;
            background:
                linear-gradient(
                    180deg,
                    color-mix(in srgb, var(--cpms-primary) 92%, #000000),
                    color-mix(in srgb, var(--cpms-primary) 72%, #000000)
                );
            color: #ffffff;
            box-shadow: 8px 0 24px rgba(0,0,0,.10);
            z-index: 1000;
        }

        .cpms-sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            padding: 4px 7px 22px;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(255,255,255,.14);
        }

        .cpms-sidebar-logo {
            width: 46px;
            height: 46px;
            flex: 0 0 46px;
            display: grid;
            place-items: center;
            overflow: hidden;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 5px 14px rgba(0,0,0,.18);
        }

        .cpms-sidebar-logo img {
            width: 100%;
            height: 100%;
            padding: 4px;
            object-fit: contain;
        }

        .cpms-sidebar-brand-text {
            min-width: 0;
        }

        .cpms-sidebar-brand-text strong {
            display: block;
            overflow: hidden;
            color: #ffffff;
            font-size: 13px;
            line-height: 1.35;
            text-overflow: ellipsis;
        }

        .cpms-sidebar-brand-text span {
            display: block;
            margin-top: 4px;
            color: rgba(255,255,255,.65);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .cpms-sidebar-section-title {
            margin: 17px 10px 8px;
            color: rgba(255,255,255,.45);
            font-size: 9px;
            font-weight: 900;
            letter-spacing: .13em;
            text-transform: uppercase;
        }

        .cpms-sidebar-nav {
            display: grid;
            gap: 4px;
        }

        .cpms-sidebar-link {
            display: grid;
            grid-template-columns: 27px minmax(0, 1fr);
            align-items: center;
            gap: 9px;
            min-height: 43px;
            padding: 9px 11px;
            border-radius: 9px;
            color: rgba(255,255,255,.82);
            text-decoration: none;
            font-size: 12px;
            font-weight: 750;
            transition:
                transform .16s ease,
                background .16s ease,
                color .16s ease;
        }

        .cpms-sidebar-link:hover,
        .cpms-sidebar-link.active {
            background: rgba(255,255,255,.13);
            color: #ffffff;
            transform: translateX(2px);
        }

        .cpms-sidebar-link.active {
            box-shadow: inset 3px 0 0 var(--cpms-secondary);
        }

        .cpms-sidebar-icon {
            width: 27px;
            text-align: center;
            font-size: 17px;
        }

        .cpms-sidebar-footer {
            margin-top: 22px;
            padding: 15px 10px 2px;
            border-top: 1px solid rgba(255,255,255,.14);
        }

        .cpms-sidebar-user {
            display: grid;
            gap: 3px;
            margin-bottom: 11px;
        }

        .cpms-sidebar-user span {
            color: rgba(255,255,255,.56);
            font-size: 10px;
        }

        .cpms-sidebar-user strong {
            overflow: hidden;
            color: #ffffff;
            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cpms-sidebar-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 9px 11px;
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 8px;
            color: #ffffff;
            text-decoration: none;
            font-size: 11px;
            font-weight: 900;
        }

        .cpms-sidebar-logout:hover {
            border-color: var(--cpms-secondary);
            background: var(--cpms-secondary);
            color: #ffffff;
        }

        .cpms-main-content {
            min-width: 0;
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 24px 28px 40px;
        }

        .cpms-mobile-topbar {
            display: none;
        }

        .admin-header {
            margin-bottom: 18px;
            padding: 15px 18px;
            border-top: 0;
            border-left: 4px solid var(--cpms-secondary);
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 5px 18px rgba(0,0,0,.06);
        }

        .admin-header .admin-logo {
            display: none;
        }

        .admin-header-info h1 {
            margin: 0 0 4px;
            font-size: clamp(18px, 2.2vw, 25px);
        }

        .admin-header-info p {
            margin: 0;
        }

        .admin-header .admin-logout {
            display: none;
        }

        .cpms-commercial-hero {
            border-radius: 14px;
        }

        /* The same links are now available in the sidebar. */
        .pms-admin-quick-menu {
            display: none;
        }

        .dashboard-cards {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        @media screen and (max-width: 1180px) {
            .cpms-app-shell {
                grid-template-columns: 225px minmax(0, 1fr);
            }

            .cpms-main-content {
                padding: 22px 20px 36px;
            }

            .dashboard-cards {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media screen and (max-width: 820px) {
            .cpms-app-shell {
                display: block;
            }

            .cpms-sidebar {
                position: fixed;
                left: 0;
                top: 0;
                width: min(280px, 86vw);
                transform: translateX(-105%);
                transition: transform .22s ease;
            }

            body.cpms-sidebar-open .cpms-sidebar {
                transform: translateX(0);
            }

            body.cpms-sidebar-open::after {
                content: "";
                position: fixed;
                inset: 0;
                z-index: 900;
                background: rgba(0,0,0,.45);
            }

            .cpms-mobile-topbar {
                position: sticky;
                top: 0;
                z-index: 850;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 12px 15px;
                background: var(--cpms-primary);
                color: #ffffff;
                box-shadow: 0 4px 14px rgba(0,0,0,.16);
            }

            .cpms-mobile-menu-button {
                width: 40px;
                height: 40px;
                border: 1px solid rgba(255,255,255,.28);
                border-radius: 9px;
                background: rgba(255,255,255,.10);
                color: #ffffff;
                font-size: 20px;
                cursor: pointer;
            }

            .cpms-mobile-property {
                min-width: 0;
                overflow: hidden;
                font-size: 12px;
                font-weight: 900;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .cpms-main-content {
                padding: 16px 13px 32px;
            }

            .admin-header {
                display: none;
            }

            .dashboard-cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media screen and (max-width: 520px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
        }

    </style>
</head>
<body class="admin-body">

<div class="cpms-app-shell">

    <aside class="cpms-sidebar" id="cpms-sidebar">
        <div class="cpms-sidebar-brand">
            <div class="cpms-sidebar-logo">
                <img
                    src="<?php echo e(setting($systemSettings, "logo_path", "images/logo.png")); ?>"
                    alt="<?php echo e(setting($systemSettings, "property_name", "Property")); ?>"
                >
            </div>

            <div class="cpms-sidebar-brand-text">
                <strong>
                    <?php echo e(setting($systemSettings, "property_name", "Property")); ?>
                </strong>
                <span>CPMS Administration</span>
            </div>
        </div>

        <div class="cpms-sidebar-section-title">
            <?php echo $isEnglish ? "Main Menu" : "Menu Utama"; ?>
        </div>

        <nav class="cpms-sidebar-nav" aria-label="Admin navigation">
            <a href="admin_dashboard.php" class="cpms-sidebar-link active">
                <span class="cpms-sidebar-icon">🏠</span>
                <span><?php echo $isEnglish ? "Dashboard" : "Dashboard"; ?></span>
            </a>

            <a href="#complaint-list" class="cpms-sidebar-link">
                <span class="cpms-sidebar-icon">📝</span>
                <span><?php echo $isEnglish ? "Complaints" : "Aduan"; ?></span>
            </a>

            <?php if (moduleIsEnabled($cpmsEnabledModules, "work_orders")): ?>
                <a href="admin_work_orders.php" class="cpms-sidebar-link">
                    <span class="cpms-sidebar-icon">🛠️</span>
                    <span><?php echo $isEnglish ? "Work Orders" : "Arahan Kerja"; ?></span>
                </a>
            <?php endif; ?>

            <?php if (moduleIsEnabled($cpmsEnabledModules, "assets")): ?>
                <a href="admin_assets.php" class="cpms-sidebar-link">
                    <span class="cpms-sidebar-icon">🏢</span>
                    <span><?php echo $isEnglish ? "Assets" : "Aset"; ?></span>
                </a>
            <?php endif; ?>

            <a href="admin_maintenance.php" class="cpms-sidebar-link">
                <span class="cpms-sidebar-icon">🔧</span>
                <span><?php echo $isEnglish ? "Maintenance" : "Penyelenggaraan"; ?></span>
            </a>

            <a href="admin_staff.php" class="cpms-sidebar-link">
                <span class="cpms-sidebar-icon">👷</span>
                <span><?php echo $isEnglish ? "Staff" : "Pekerja"; ?></span>
            </a>

            <?php if (moduleIsEnabled($cpmsEnabledModules, "security_patrol")): ?>
                <a href="admin_security_patrols.php" class="cpms-sidebar-link">
                    <span class="cpms-sidebar-icon">🛡️</span>
                    <span><?php echo $isEnglish ? "Security" : "Sekuriti"; ?></span>
                </a>
            <?php endif; ?>

            <?php if (moduleIsEnabled($cpmsEnabledModules, "reports")): ?>
                <a href="admin_reports.php" class="cpms-sidebar-link">
                    <span class="cpms-sidebar-icon">📊</span>
                    <span><?php echo $isEnglish ? "Reports" : "Laporan"; ?></span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="cpms-sidebar-section-title">
            <?php echo $isEnglish ? "Administration" : "Pentadbiran"; ?>
        </div>

        <nav class="cpms-sidebar-nav" aria-label="System administration">
            <a href="admin_notifications.php" class="cpms-sidebar-link">
                <span class="cpms-sidebar-icon">🔔</span>
                <span>
                    <?php echo $isEnglish ? "Notifications" : "Notifikasi"; ?>
                    <?php if ($unreadNotificationCount > 0): ?>
                        (<?php echo $unreadNotificationCount; ?>)
                    <?php endif; ?>
                </span>
            </a>

            <?php if ($canViewPropertyAudit): ?>
                <a href="admin_audit_logs.php" class="cpms-sidebar-link">
                    <span class="cpms-sidebar-icon">🧾</span>
                    <span><?php echo $isEnglish ? "Audit Log" : "Log Audit"; ?></span>
                </a>
            <?php endif; ?>

        </nav>

        <div class="cpms-sidebar-footer">
            <div class="cpms-sidebar-user">
                <span><?php echo $isEnglish ? "Signed in as" : "Log masuk sebagai"; ?></span>
                <strong><?php echo e((string) $_SESSION["admin"]); ?></strong>
                <small><?php echo e($operationRoleLabel); ?></small>
            </div>

            <a href="admin_logout.php" class="cpms-sidebar-logout">
                <?php echo $isEnglish ? "Logout" : "Log Keluar"; ?>
            </a>
        </div>
    </aside>

    <div class="cpms-workspace">
        <div class="cpms-mobile-topbar">
            <button
                type="button"
                class="cpms-mobile-menu-button"
                id="cpms-mobile-menu-button"
                aria-label="<?php echo $isEnglish ? "Open menu" : "Buka menu"; ?>"
                aria-controls="cpms-sidebar"
                aria-expanded="false"
            >
                ☰
            </button>

            <div class="cpms-mobile-property">
                <?php echo e(setting($systemSettings, "property_name", "Property")); ?>
            </div>

            <a
                href="admin_notifications.php"
                style="color:#fff;text-decoration:none;font-size:19px"
                aria-label="<?php echo $isEnglish ? "Notifications" : "Notifikasi"; ?>"
            >
                🔔
            </a>
        </div>

        <main class="admin-container cpms-main-content">


    <header class="admin-header">
        <div class="admin-logo">
            <img
                src="<?php
                    echo e(
                        setting(
                            $systemSettings,
                            "logo_path",
                            "images/logo.png"
                        )
                    );
                ?>"
                alt="<?php
                    echo e(
                        setting(
                            $systemSettings,
                            "property_name",
                            "Property Logo"
                        )
                    );
                ?>"
            >
        </div>
        <div class="admin-header-info">
            <h1>
                <?php
                echo e(
                    setting(
                        $systemSettings,
                        "property_name",
                        "Property"
                    )
                );
                ?>
                — Admin Dashboard
            </h1>
            <p>
                <?php echo $isEnglish ? "Welcome" : "Selamat datang"; ?>,
                <strong><?php echo e((string)$_SESSION["admin"]); ?></strong>
            </p>
        </div>
        <div class="admin-logout">
            <a href="admin_logout.php"><?php echo $isEnglish ? "Logout" : "Log Keluar"; ?></a>
        </div>
    </header>

    <section class="cpms-commercial-hero">
        <div>
            <h2>
                <?php echo e(cpmsSystemName()); ?>
            </h2>

            <p>
                <?php
                echo $isEnglish
                    ? "Central command centre for complaints, work orders, assets, staff and security operations."
                    : "Pusat kawalan utama untuk aduan, arahan kerja, aset, pekerja dan operasi keselamatan.";
                ?>
            </p>
        </div>

        <div class="cpms-hero-meta">
            <div>
                <strong><?php echo e(cpmsPropertyName()); ?></strong>
            </div>

            <div>
                <?php echo e(date("d/m/Y h:i A")); ?>
            </div>

            <div>
                <?php echo e(cpmsFooter()); ?>
            </div>

            <div>
                <?php
                echo $isEnglish
                    ? "Enabled modules: "
                    : "Modul aktif: ";
                ?>
                <strong><?php echo count($cpmsEnabledModules); ?></strong>
            </div>

            <div>
                <?php
                echo $isEnglish
                    ? "Current property: "
                    : "Property semasa: ";
                ?>
                <strong>
                    <?php echo e((string) $currentProperty["name"]); ?>
                </strong>
            </div>

            <div class="cpms-property-switcher-note">
                <?php
                echo $isEnglish
                    ? "This operations account is restricted to V23 Malawa Ria Apartment."
                    : "Akaun operasi ini dihadkan kepada V23 Malawa Ria Apartment.";
                ?>
            </div>

            <div class="cpms-bell-wrap" id="cpms-admin-bell">
                <button
                    type="button"
                    class="cpms-bell-button"
                    id="cpms-bell-button"
                    aria-label="<?php
                        echo $isEnglish
                            ? "Open notifications"
                            : "Buka notifikasi";
                    ?>"
                    aria-expanded="false"
                    aria-controls="cpms-bell-dropdown"
                >
                    🔔

                    <span
                        class="cpms-bell-badge <?php
                            echo
                                $unreadNotificationCount > 0
                                    ? ""
                                    : "is-zero";
                        ?>"
                        id="cpms-bell-badge"
                    >
                        <?php
                        echo
                            $unreadNotificationCount > 99
                                ? "99+"
                                : $unreadNotificationCount;
                        ?>
                    </span>
                </button>

                <div
                    class="cpms-bell-dropdown"
                    id="cpms-bell-dropdown"
                    hidden
                >
                    <div class="cpms-bell-dropdown-head">
                        <strong>
                            <?php
                            echo $isEnglish
                                ? "Latest Notifications"
                                : "Notifikasi Terkini";
                            ?>
                        </strong>

                        <span>
                            <?php echo $unreadNotificationCount; ?>
                            <?php
                            echo $isEnglish
                                ? "unread"
                                : "belum dibaca";
                            ?>
                        </span>
                    </div>

                    <div class="cpms-bell-list">
                        <?php if (
                            count($latestAdminNotifications) === 0
                        ): ?>

                            <div class="cpms-bell-empty">
                                <?php
                                echo $isEnglish
                                    ? "No notifications yet."
                                    : "Belum ada notifikasi.";
                                ?>
                            </div>

                        <?php else: ?>

                            <?php foreach (
                                $latestAdminNotifications as
                                $adminNotification
                            ): ?>

                                <?php
                                $notificationType =
                                    strtoupper(
                                        trim(
                                            (string) (
                                                $adminNotification[
                                                    "notification_type"
                                                ] ?? "INFO"
                                            )
                                        )
                                    );

                                $notificationIcon =
                                    match ($notificationType) {
                                        "COMPLAINT" => "📝",
                                        "WORK_ORDER" => "🛠️",
                                        "PATROL",
                                        "SECURITY" => "🛡️",
                                        "ASSET" => "🏢",
                                        "MAINTENANCE" => "🔧",
                                        "WARNING" => "⚠️",
                                        "SUCCESS" => "✅",
                                        default => "🔔"
                                    };

                                $notificationUrl =
                                    trim(
                                        (string) (
                                            $adminNotification[
                                                "action_url"
                                            ] ?? ""
                                        )
                                    );

                                if (
                                    $notificationUrl === "" ||
                                    preg_match(
                                        '/^(javascript|data|vbscript):/i',
                                        $notificationUrl
                                    )
                                ) {
                                    $notificationUrl =
                                        "admin_notifications.php";
                                }
                                ?>

                                <a
                                    class="cpms-bell-item <?php
                                        echo
                                            (int) (
                                                $adminNotification[
                                                    "is_read"
                                                ] ?? 0
                                            ) === 0
                                                ? "unread"
                                                : "";
                                    ?>"
                                    href="admin_notification_open.php?id=<?php
                                        echo (int) $adminNotification["id"];
                                    ?>&redirect=<?php
                                        echo rawurlencode(
                                            $notificationUrl
                                        );
                                    ?>"
                                >
                                    <span class="cpms-bell-icon">
                                        <?php echo $notificationIcon; ?>
                                    </span>

                                    <span class="cpms-bell-content">
                                        <span class="cpms-bell-title">
                                            <?php
                                            echo e(
                                                (string) (
                                                    $adminNotification[
                                                        "title"
                                                    ] ?? "Notification"
                                                )
                                            );
                                            ?>
                                        </span>

                                        <span class="cpms-bell-message">
                                            <?php
                                            echo e(
                                                (string) (
                                                    $adminNotification[
                                                        "message"
                                                    ] ?? ""
                                                )
                                            );
                                            ?>
                                        </span>

                                        <span class="cpms-bell-meta">
                                            <span class="cpms-bell-type">
                                                <?php
                                                echo e(
                                                    $notificationType
                                                );
                                                ?>
                                            </span>

                                            <span>
                                                <?php
                                                echo e(
                                                    formatDateTime(
                                                        (string) (
                                                            $adminNotification[
                                                                "created_at"
                                                            ] ?? ""
                                                        )
                                                    )
                                                );
                                                ?>
                                            </span>
                                        </span>
                                    </span>
                                </a>

                            <?php endforeach; ?>

                        <?php endif; ?>
                    </div>

                    <a
                        href="admin_notifications.php"
                        class="cpms-bell-footer"
                    >
                        <?php
                        echo $isEnglish
                            ? "View all notifications"
                            : "Lihat semua notifikasi";
                        ?>
                    </a>
                </div>
            </div>

            <div class="cpms-language-switcher">
                <a
                    href="admin_dashboard.php?lang=ms"
                    class="<?php echo $cpmsLanguage === "ms" ? "active" : ""; ?>"
                >
                    BM
                </a>

                <a
                    href="admin_dashboard.php?lang=en"
                    class="<?php echo $cpmsLanguage === "en" ? "active" : ""; ?>"
                >
                    EN
                </a>
            </div>
        </div>
    </section>

    <section class="cpms-executive-cards">
        <article class="cpms-executive-card">
            <span>
                <?php echo $isEnglish ? "Complaints Today" : "Aduan Hari Ini"; ?>
            </span>
            <strong><?php echo $todayComplaintCount; ?></strong>
        </article>

        <article class="cpms-executive-card">
            <span>
                <?php echo $isEnglish ? "Current Month" : "Bulan Semasa"; ?>
            </span>
            <strong><?php echo $currentMonthTotal; ?></strong>
        </article>

        <article class="cpms-executive-card">
            <span>
                <?php echo $isEnglish ? "Resolution Rate" : "Kadar Penyelesaian"; ?>
            </span>
            <strong><?php echo $resolutionRate; ?>%</strong>
        </article>

        <article class="cpms-executive-card">
            <span>
                <?php echo $isEnglish ? "Outstanding" : "Belum Selesai"; ?>
            </span>
            <strong>
                <?php
                echo
                    $statistics["pending"] +
                    $statistics["progress"];
                ?>
            </strong>
        </article>
    </section>

    <section class="cpms-3b-grid">
        <article class="cpms-3b-card">
            <span>
                <?php echo $isEnglish ? "Average Resolution" : "Purata Penyelesaian"; ?>
            </span>
            <strong><?php echo e($averageResolutionDisplay); ?></strong>
        </article>

        <article class="cpms-3b-card">
            <span>
                <?php echo $isEnglish ? "Top Complaint Category" : "Kategori Aduan Tertinggi"; ?>
            </span>
            <strong><?php echo e($topCategoryName); ?></strong>
        </article>

        <article class="cpms-3b-card">
            <span>
                <?php echo $isEnglish ? "Highest Complaint Block" : "Blok Aduan Tertinggi"; ?>
            </span>
            <strong>
                <?php echo e($topBlockName); ?>
                <?php if ($topBlockTotal > 0): ?>
                    (<?php echo $topBlockTotal; ?>)
                <?php endif; ?>
            </strong>
        </article>
    </section>

    <section class="cpms-executive-summary">
        <h2>
            <?php echo $isEnglish ? "Executive Summary" : "Ringkasan Eksekutif"; ?>
        </h2>

        <p><?php echo e($executiveSummary); ?></p>

        <div class="cpms-live-status">
            <span class="cpms-live-dot"></span>
            <span>
                <?php echo $isEnglish ? "Dashboard refreshes automatically in" : "Dashboard dimuat semula secara automatik dalam"; ?>
                <strong id="cpms-refresh-countdown">60</strong>
                <?php echo $isEnglish ? "seconds" : "saat"; ?>.
            </span>
        </div>
    </section>

    <nav class="cpms-quick-actions">
        <?php if (moduleIsEnabled($cpmsEnabledModules, "work_orders")): ?>
            <a href="admin_work_order_create.php" class="highlight">
                ＋ <?php echo $isEnglish ? "Create Work Order" : "Cipta Work Order"; ?>
            </a>
        <?php endif; ?>

        <?php if (moduleIsEnabled($cpmsEnabledModules, "daily_work")): ?>
            <a href="admin_daily_work.php">
                <?php echo $isEnglish ? "Review Daily Work" : "Semak Daily Work"; ?>
            </a>
        <?php endif; ?>

        <?php if (moduleIsEnabled($cpmsEnabledModules, "security_patrol")): ?>
            <a href="admin_security_patrols.php">
                <?php echo $isEnglish ? "Security Patrol" : "Rondaan Sekuriti"; ?>
            </a>
        <?php endif; ?>

        <?php if (moduleIsEnabled($cpmsEnabledModules, "reports")): ?>
            <a href="monthly_management_report.php">
                <?php echo $isEnglish ? "Monthly Report" : "Laporan Bulanan"; ?>
            </a>
        <?php endif; ?>

        <a href="admin_system_settings.php">
            ⚙ <?php echo $isEnglish ? "System Settings" : "Tetapan Sistem"; ?>
        </a>
    </nav>

    <!-- ==========================================
         PMS MANAGEMENT MENU
    =========================================== -->

    <section class="pms-admin-quick-menu">

        <div class="pms-admin-quick-menu-header">

            <div>
                <h2>
                    <?php
                    echo e(
                        setting(
                            $systemSettings,
                            "system_name",
                            "Commercial Property Management System"
                        )
                    );
                    ?>
                </h2>

                <p>
                    <?php echo $isEnglish ? "Choose the management module to open." : "Pilih modul pengurusan yang ingin dibuka."; ?>
                </p>
            </div>

        </div>

        <div class="pms-admin-quick-grid">

            <a href="admin_staff.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">👷</span>
                <strong>Staff Management</strong>
                <span>Tambah pekerja, cipta akaun dan urus status staff.</span>
            </a>

            <a href="admin_daily_work.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">📝</span>
                <strong>Daily Work</strong>
                <span>Semak kerja harian, gambar dan pengesahan.</span>
            </a>

            <a href="admin_work_orders.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">🛠️</span>
                <strong>Work Order</strong>
                <span>Cipta, assign dan pantau arahan kerja.</span>
            </a>

            <?php if (moduleIsEnabled($cpmsEnabledModules, "assets")): ?>
            <a href="admin_assets.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">🏢</span>
                <strong>Asset Management</strong>
                <span>Daftar, cari dan pantau semua aset <?php
                    echo e(
                        setting(
                            $systemSettings,
                            "property_name",
                            "property"
                        )
                    );
                ?>.</span>
            </a>
            <?php endif; ?>

            <a href="admin_maintenance.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">🔧</span>
                <strong>Preventive Maintenance</strong>
                <span>Jadual dan pantau penyelenggaraan berkala aset.</span>
            </a>

            <a href="admin_asset_qr.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">📱</span>
                <strong>Asset QR Code</strong>
                <span>Papar dan cetak QR Code untuk setiap aset.</span>
            </a>

            <a href="admin_asset_inspections.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">🔍</span>
                <strong>Asset Inspections</strong>
                <span>Semak pemeriksaan aset yang dihantar pekerja.</span>
            </a>

            <a href="monthly_management_report.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">📄</span>
                <strong>Monthly Management Report</strong>
                <span>Jana laporan bulanan lengkap dan simpan sebagai PDF.</span>
            </a>

            <a href="admin_security_guards.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">🛡️</span>
                <strong>Security Guard Management</strong>
                <span>Tambah dan urus akaun pengawal tetap, buffer atau relief.</span>
            </a>

            <a href="admin_security_patrols.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">🚶</span>
                <strong>Security Patrol Records</strong>
                <span>Semak rekod rondaan, isu dan gambar yang dihantar pengawal.</span>
            </a>

            <?php if (moduleIsEnabled($cpmsEnabledModules, "reports")): ?>
            <a href="admin_reports.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">📊</span>
                <strong>Complaint Reports</strong>
                <span>Buka laporan statistik dan analisis aduan.</span>
            </a>
            <?php endif; ?>

            <a href="admin_notifications.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">🔔</span>
                <strong>Notification Center</strong>
                <span>
                    Lihat notifikasi sistem dan tindakan yang memerlukan perhatian.
                    Belum dibaca: <?php echo $unreadNotificationCount; ?>
                </span>
            </a>

            <a href="admin_notification_deliveries.php" class="pms-admin-quick-link">
                <span class="pms-admin-quick-icon">📤</span>
                <strong>Notification Delivery Queue</strong>
                <span>
                    Pantau mesej WhatsApp, email, SMS dan push notification.
                </span>
            </a>

            <?php if ($canViewPropertyAudit): ?>
                <a href="admin_audit_logs.php" class="pms-admin-quick-link">
                    <span class="pms-admin-quick-icon">🧾</span>
                    <strong>Audit Log</strong>
                    <span>Lihat sejarah tindakan pengguna dan perubahan sistem.</span>
                </a>
            <?php endif; ?>

        </div>

    </section>

    <?php if ($successMessage !== ""): ?>
        <div class="admin-success-message">
            <div class="success-icon">✓</div>
            <div class="success-text"><strong>Berjaya</strong><span><?php echo e($successMessage); ?></span></div>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ""): ?>
        <div class="error-message"><?php echo e($errorMessage); ?></div>
    <?php endif; ?>

    <section class="dashboard-cards">
        <div class="dashboard-card"><h3>Jumlah Aduan</h3><div class="dashboard-number"><?php echo $statistics["total"]; ?></div></div>
        <div class="dashboard-card"><h3>Pending</h3><div class="dashboard-number"><?php echo $statistics["pending"]; ?></div></div>
        <div class="dashboard-card"><h3>In Progress</h3><div class="dashboard-number"><?php echo $statistics["progress"]; ?></div></div>
        <div class="dashboard-card"><h3>Resolved</h3><div class="dashboard-number"><?php echo $statistics["resolved"]; ?></div></div>
        <div class="dashboard-card"><h3>Closed</h3><div class="dashboard-number"><?php echo $statistics["closed"]; ?></div></div>
    </section>

    <?php
    $totalForChart = max(
        1,
        (int) $statistics["total"]
    );

    $pendingEnd =
        (
            $statistics["pending"] /
            $totalForChart
        ) * 100;

    $progressEnd =
        $pendingEnd +
        (
            $statistics["progress"] /
            $totalForChart
        ) * 100;

    $resolvedEnd =
        $progressEnd +
        (
            $statistics["resolved"] /
            $totalForChart
        ) * 100;
    ?>

    <!-- ANALYTICS DASHBOARD -->
    <section class="dashboard-analytics">

        <!-- STATUS DONUT -->
        <article class="analytics-card">

            <div class="analytics-heading">

                <div>
                    <h2>
                        Aduan Mengikut Status
                    </h2>

                    <p>
                        Pecahan keseluruhan status aduan.
                    </p>
                </div>

                <span class="analytics-tag">
                    Live Data
                </span>

            </div>

            <div class="status-donut-layout">

                <div
                    class="status-donut"
                    style="
                        --pending-end:
                        <?php echo round($pendingEnd, 2); ?>%;

                        --progress-end:
                        <?php echo round($progressEnd, 2); ?>%;

                        --resolved-end:
                        <?php echo round($resolvedEnd, 2); ?>%;
                    "
                    aria-label="Carta status aduan"
                >

                    <div class="status-donut-center">

                        <strong>
                            <?php
                            echo $statistics["total"];
                            ?>
                        </strong>

                        <span>
                            Jumlah Aduan
                        </span>

                    </div>

                </div>

                <div class="chart-legend">

                    <div class="legend-row">

                        <span
                            class="legend-dot legend-pending"
                        ></span>

                        <span>
                            Pending
                        </span>

                        <strong>
                            <?php
                            echo $statistics["pending"];
                            ?>
                        </strong>

                    </div>

                    <div class="legend-row">

                        <span
                            class="legend-dot legend-progress"
                        ></span>

                        <span>
                            In Progress
                        </span>

                        <strong>
                            <?php
                            echo $statistics["progress"];
                            ?>
                        </strong>

                    </div>

                    <div class="legend-row">

                        <span
                            class="legend-dot legend-resolved"
                        ></span>

                        <span>
                            Resolved
                        </span>

                        <strong>
                            <?php
                            echo $statistics["resolved"];
                            ?>
                        </strong>

                    </div>

                    <div class="legend-row">

                        <span
                            class="legend-dot legend-closed"
                        ></span>

                        <span>
                            Closed
                        </span>

                        <strong>
                            <?php
                            echo $statistics["closed"];
                            ?>
                        </strong>

                    </div>

                </div>

            </div>

        </article>

        <!-- PERFORMANCE -->
        <article class="analytics-card">

            <div class="analytics-heading">

                <div>
                    <h2>
                        Ringkasan Prestasi
                    </h2>

                    <p>
                        Prestasi pengurusan aduan semasa.
                    </p>
                </div>

                <span class="analytics-tag">
                    KPI
                </span>

            </div>

            <div class="performance-grid">

                <div class="performance-item">

                    <strong>
                        <?php
                        echo $resolutionRate;
                        ?>%
                    </strong>

                    <span>
                        Kadar aduan selesai atau ditutup
                    </span>

                </div>

                <div class="performance-item">

                    <strong>
                        <?php
                        echo $currentMonthTotal;
                        ?>
                    </strong>

                    <span>
                        Aduan diterima pada bulan ini
                    </span>

                </div>

                <div class="performance-item">

                    <strong>
                        <?php
                        echo
                            $statistics["pending"] +
                            $statistics["progress"];
                        ?>
                    </strong>

                    <span>
                        Aduan masih memerlukan tindakan
                    </span>

                </div>

                <div class="performance-item">

                    <strong>
                        <?php
                        echo $completedComplaints;
                        ?>
                    </strong>

                    <span>
                        Jumlah aduan telah diselesaikan
                    </span>

                </div>

            </div>

        </article>

        <!-- CATEGORY BAR CHART -->
        <article class="analytics-card analytics-card-wide">

            <div class="analytics-heading">

                <div>
                    <h2>
                        Kategori Aduan Tertinggi
                    </h2>

                    <p>
                        Enam kategori dengan jumlah aduan terbanyak.
                    </p>
                </div>

                <span class="analytics-tag">
                    Top 6
                </span>

            </div>

            <?php if (
                count($topCategoryCounts) > 0
            ): ?>

                <div class="css-bar-chart">

                    <?php foreach (
                        $topCategoryCounts as
                        $categoryName =>
                        $categoryTotal
                    ): ?>

                        <?php
                        $categoryWidth =
                            (
                                $categoryTotal /
                                max(1, $maxCategoryCount)
                            ) * 100;
                        ?>

                        <div class="css-bar-row">

                            <div
                                class="css-bar-label"
                                title="<?php
                                    echo e(
                                        (string) $categoryName
                                    );
                                ?>"
                            >
                                <?php
                                echo e(
                                    (string) $categoryName
                                );
                                ?>
                            </div>

                            <div class="css-bar-track">

                                <div
                                    class="css-bar-fill"
                                    style="
                                        --bar-width:
                                        <?php
                                        echo round(
                                            $categoryWidth,
                                            2
                                        );
                                        ?>%;
                                    "
                                ></div>

                            </div>

                            <div class="css-bar-value">

                                <?php
                                echo (int) $categoryTotal;
                                ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="chart-empty">
                    Belum ada data kategori untuk dipaparkan.
                </div>

            <?php endif; ?>

        </article>

        <!-- MONTHLY CHART -->
        <article class="analytics-card analytics-card-wide">

            <div class="analytics-heading">

                <div>
                    <h2>
                        Trend Aduan 6 Bulan
                    </h2>

                    <p>
                        Jumlah aduan yang diterima setiap bulan.
                    </p>
                </div>

                <span class="analytics-tag">
                    6 Bulan
                </span>

            </div>

            <div class="monthly-chart">

                <?php foreach (
                    $monthCounts as
                    $monthKey =>
                    $monthTotal
                ): ?>

                    <?php
                    $monthHeight =
                        (
                            $monthTotal /
                            max(1, $maxMonthCount)
                        ) * 170;

                    if (
                        $monthTotal > 0 &&
                        $monthHeight < 12
                    ) {
                        $monthHeight = 12;
                    }
                    ?>

                    <div class="monthly-column">

                        <span class="monthly-value">

                            <?php
                            echo (int) $monthTotal;
                            ?>

                        </span>

                        <div
                            class="monthly-bar"
                            style="
                                --month-height:
                                <?php
                                echo round(
                                    $monthHeight,
                                    2
                                );
                                ?>px;
                            "
                        ></div>

                        <span class="monthly-label">

                            <?php
                            echo e(
                                $monthLabels[
                                    $monthKey
                                ]
                            );
                            ?>

                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        </article>

    </section>

    <div class="admin-export-area">
        <?php if (file_exists(__DIR__ . "/admin_reports.php")): ?>
            <a href="admin_reports.php" class="report-dashboard-button">Laporan Statistik</a>
        <?php endif; ?>
        <?php if (file_exists(__DIR__ . "/export_complaints.php")): ?>
            <a href="export_complaints.php" class="export-button">Export Excel</a>
        <?php endif; ?>
    </div>

    <section class="admin-filter-box">
        <form method="GET" action="admin_dashboard.php" class="admin-filter-form">
            <div class="filter-group">
                <label for="search">Cari Aduan</label>
                <input type="text" id="search" name="search" value="<?php echo e($search); ?>" placeholder="No. rujukan, nama, telefon, unit...">
            </div>
            <div class="filter-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Semua Status</option>
                    <?php foreach ($allowedStatuses as $option): ?>
                        <option value="<?php echo e($option); ?>" <?php echo $statusFilter === $option ? "selected" : ""; ?>><?php echo e($option); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="priority">Keutamaan</label>
                <select id="priority" name="priority">
                    <option value="">Semua Keutamaan</option>
                    <?php foreach ($allowedPriorities as $option): ?>
                        <option value="<?php echo e($option); ?>" <?php echo $priorityFilter === $option ? "selected" : ""; ?>><?php echo e($option); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-submit-button">Cari</button>
                <a href="admin_dashboard.php" class="filter-reset-button">Reset</a>
            </div>
        </form>
    </section>

    <section id="complaint-list">
        <div class="admin-section-title">
            <span>Senarai Aduan</span>
            <span class="result-count"><?php echo count($complaints); ?> rekod dijumpai</span>
        </div>

        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                <tr>
                    <th>No. Rujukan</th>
                    <th>Pengadu</th>
                    <th>Unit</th>
                    <th>Kategori</th>
                    <th>Keutamaan</th>
                    <th>Status</th>
                    <th>Tarikh</th>
                    <th>Tindakan</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$complaints): ?>
                    <tr><td colspan="8" class="no-complaint">Tiada aduan dijumpai.</td></tr>
                <?php else: ?>
                    <?php foreach ($complaints as $complaint): ?>
                        <?php
                        $complaintId = (string)($complaint["complaint_id"] ?? "");
                        $safeId = preg_replace('/[^A-Za-z0-9_-]/', '', $complaintId);
                        $status = (string)($complaint["status"] ?? "Pending");
                        $priority = (string)($complaint["priority"] ?? "-");
                        $remarksValue = $hasAdminRemarks
                            ? (string)($complaint["admin_remarks"] ?? "")
                            : ($hasRemarks ? (string)($complaint["remarks"] ?? "") : "");
                        $createdAt = $hasCreatedAt ? ($complaint["created_at"] ?? null) : null;

                        $residentPhone =
                            (string) ($complaint["phone"] ?? "");

                        $whatsappUrl = buildWhatsAppUrl(
                            $residentPhone,
                            $complaintId,
                            $status,
                            setting(
                                $systemSettings,
                                "property_name",
                                "V23 Malawa Ria Apartment"
                            )
                        );
                        ?>
                        <tr>
                            <td><strong><?php echo e($complaintId); ?></strong></td>
                            <td><?php echo e((string)($complaint["name"] ?? "-")); ?><br><small><?php echo e((string)($complaint["phone"] ?? "-")); ?></small></td>
                            <td>Blok <?php echo e((string)($complaint["block"] ?? "-")); ?><br><?php echo e((string)($complaint["unit_no"] ?? "-")); ?></td>
                            <td><?php echo e((string)($complaint["category"] ?? "-")); ?></td>
                            <td><span class="priority-badge <?php echo e(priorityClass($priority)); ?>"><?php echo e($priority); ?></span></td>
                            <td><span class="admin-status-badge <?php echo e(statusClass($status)); ?>"><?php echo e($status); ?></span></td>
                            <td><?php echo e(formatDateTime(is_string($createdAt) ? $createdAt : null)); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" class="view-button" onclick="toggleDetails('<?php echo e($safeId); ?>')">Lihat</button>

                                    <?php if ($whatsappUrl !== ""): ?>
                                        <a
                                            class="whatsapp-resident-button"
                                            href="<?php echo e($whatsappUrl); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            WhatsApp
                                        </a>
                                    <?php endif; ?>

                                    <?php if (file_exists(__DIR__ . "/print_complaint.php")): ?>
                                        <a class="print-complaint-button" href="print_complaint.php?ref=<?php echo urlencode($complaintId); ?>" target="_blank">Cetak</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr id="detail-<?php echo e($safeId); ?>" style="display:none;">
                            <td colspan="8">
                                <div class="complaint-detail-box">
                                    <h2>Maklumat Aduan</h2>
                                    <div class="detail-grid">
                                        <div class="detail-item"><strong>No. Rujukan</strong><p><?php echo e($complaintId); ?></p></div>
                                        <div class="detail-item"><strong>Status Penghuni</strong><p><?php echo e((string)($complaint["resident_status"] ?? "-")); ?></p></div>
                                        <div class="detail-item"><strong>Lokasi</strong><p><?php echo e((string)($complaint["location"] ?? "-")); ?></p></div>
                                        <div class="detail-item"><strong>Tajuk Aduan</strong><p><?php echo e((string)($complaint["subject"] ?? "-")); ?></p></div>
                                    </div>
                                    <div class="detail-full"><strong>Penerangan Aduan</strong><p><?php echo nl2br(e((string)($complaint["description"] ?? "-"))); ?></p></div>

                                    <?php
                                    $imagesForComplaint =
                                        $complaintImages[
                                            $complaintId
                                        ] ?? [];

                                    if (
                                        count($imagesForComplaint) === 0 &&
                                        !empty($complaint["image"])
                                    ) {
                                        $imagesForComplaint[] =
                                            (string) $complaint["image"];
                                    }
                                    ?>

                                    <?php if (
                                        count($imagesForComplaint) > 0
                                    ): ?>

                                        <div class="detail-full">

                                            <strong>
                                                Gambar Bukti
                                                (<?php
                                                    echo count(
                                                        $imagesForComplaint
                                                    );
                                                ?>)
                                            </strong>

                                            <div class="admin-evidence-gallery">

                                                <?php foreach (
                                                    $imagesForComplaint as
                                                    $imageIndex =>
                                                    $imageName
                                                ): ?>

                                                    <a
                                                        href="uploads/<?php
                                                            echo rawurlencode(
                                                                basename(
                                                                    $imageName
                                                                )
                                                            );
                                                        ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >

                                                        <img
                                                            src="uploads/<?php
                                                                echo rawurlencode(
                                                                    basename(
                                                                        $imageName
                                                                    )
                                                                );
                                                            ?>"
                                                            alt="Gambar bukti <?php
                                                                echo $imageIndex + 1;
                                                            ?>"
                                                        >

                                                        <span>
                                                            Gambar
                                                            <?php
                                                            echo $imageIndex + 1;
                                                            ?>
                                                        </span>

                                                    </a>

                                                <?php endforeach; ?>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                    <?php if ($whatsappUrl !== ""): ?>

                                        <div class="whatsapp-detail-box">

                                            <h3>
                                                Hubungi Pengadu melalui WhatsApp
                                            </h3>

                                            <p>
                                                Mesej akan disediakan secara automatik
                                                bersama nombor rujukan, status semasa dan
                                                pautan semakan aduan.
                                            </p>

                                            <a
                                                class="whatsapp-resident-button"
                                                href="<?php echo e($whatsappUrl); ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                Buka WhatsApp Pengadu
                                            </a>

                                        </div>

                                    <?php endif; ?>

                                    <div class="status-update-box">
                                        <h3>Kemas Kini Status</h3>
                                        <form method="POST" action="admin_dashboard.php" class="status-update-form-new">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="complaint_id" value="<?php echo e($complaintId); ?>">
                                            <div class="admin-form-group">
                                                <label>Status Baharu</label>
                                                <select name="status" required>
                                                    <?php foreach ($allowedStatuses as $option): ?>
                                                        <option value="<?php echo e($option); ?>" <?php echo $status === $option ? "selected" : ""; ?>><?php echo e($option); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="admin-form-group">
                                                <label>Catatan Admin</label>
                                                <textarea name="remarks" maxlength="2000" placeholder="Masukkan catatan atau perkembangan aduan..."><?php echo e($remarksValue); ?></textarea>
                                            </div>
                                            <button type="submit" name="update_status" class="update-status-button">Simpan Kemas Kini</button>
                                        </form>
                                    </div>

                                    <button type="button" class="close-detail-button" onclick="toggleDetails('<?php echo e($safeId); ?>')">Tutup</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer
        style="
            margin-top:28px;
            padding:18px;
            border-top:1px solid #e5e5e5;
            color:#777777;
            font-size:12px;
            text-align:center;
        "
    >
        <strong><?php echo e(cpmsPropertyName()); ?></strong>
        <br>
        <?php echo e(cpmsFooter()); ?>
    </footer>

        </main>
    </div>
</div>

<script>
let cpmsRefreshSeconds = 60;
const cpmsCountdown =
    document.getElementById("cpms-refresh-countdown");

window.setInterval(() => {
    cpmsRefreshSeconds -= 1;

    if (cpmsCountdown) {
        cpmsCountdown.textContent =
            String(cpmsRefreshSeconds);
    }

    if (cpmsRefreshSeconds <= 0) {
        window.location.reload();
    }
}, 1000);

function toggleDetails(id) {
    const row = document.getElementById("detail-" + id);
    if (!row) return;
    row.style.display = (row.style.display === "none" || row.style.display === "") ? "table-row" : "none";
}

/*
|--------------------------------------------------------------------------
| Notification Bell 5.8B-1
|--------------------------------------------------------------------------
*/

const cpmsBellButton =
    document.getElementById("cpms-bell-button");

const cpmsBellDropdown =
    document.getElementById("cpms-bell-dropdown");

const cpmsAdminBell =
    document.getElementById("cpms-admin-bell");

if (
    cpmsBellButton &&
    cpmsBellDropdown &&
    cpmsAdminBell
) {
    cpmsBellButton.addEventListener(
        "click",
        function (event) {
            event.stopPropagation();

            const shouldOpen =
                cpmsBellDropdown.hidden;

            cpmsBellDropdown.hidden =
                !shouldOpen;

            cpmsBellButton.setAttribute(
                "aria-expanded",
                shouldOpen ? "true" : "false"
            );
        }
    );

    cpmsBellDropdown.addEventListener(
        "click",
        function (event) {
            event.stopPropagation();
        }
    );

    document.addEventListener(
        "click",
        function () {
            cpmsBellDropdown.hidden = true;

            cpmsBellButton.setAttribute(
                "aria-expanded",
                "false"
            );
        }
    );

    document.addEventListener(
        "keydown",
        function (event) {
            if (event.key !== "Escape") {
                return;
            }

            cpmsBellDropdown.hidden = true;

            cpmsBellButton.setAttribute(
                "aria-expanded",
                "false"
            );

            cpmsBellButton.focus();
        }
    );
}


/*
|--------------------------------------------------------------------------
| CPMS Dashboard V2 mobile sidebar
|--------------------------------------------------------------------------
*/

const cpmsMobileMenuButton =
    document.getElementById("cpms-mobile-menu-button");

const cpmsSidebar =
    document.getElementById("cpms-sidebar");

function cpmsCloseSidebar() {
    document.body.classList.remove("cpms-sidebar-open");

    if (cpmsMobileMenuButton) {
        cpmsMobileMenuButton.setAttribute(
            "aria-expanded",
            "false"
        );
    }
}

if (cpmsMobileMenuButton && cpmsSidebar) {
    cpmsMobileMenuButton.addEventListener(
        "click",
        function (event) {
            event.stopPropagation();

            const isOpen =
                document.body.classList.toggle(
                    "cpms-sidebar-open"
                );

            cpmsMobileMenuButton.setAttribute(
                "aria-expanded",
                isOpen ? "true" : "false"
            );
        }
    );

    cpmsSidebar.addEventListener(
        "click",
        function (event) {
            event.stopPropagation();

            if (
                window.innerWidth <= 820 &&
                event.target.closest("a")
            ) {
                cpmsCloseSidebar();
            }
        }
    );

    document.addEventListener(
        "click",
        function () {
            if (window.innerWidth <= 820) {
                cpmsCloseSidebar();
            }
        }
    );

    window.addEventListener(
        "resize",
        function () {
            if (window.innerWidth > 820) {
                cpmsCloseSidebar();
            }
        }
    );
}

</script>
</body>
</html>
