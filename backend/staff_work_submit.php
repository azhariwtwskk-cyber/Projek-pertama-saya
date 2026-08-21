<?php

declare(strict_types=1);

session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

require_once "db.php";
require_once "staff_pwa_bootstrap.php";
require_once __DIR__ . "/cpms/includes/permission_engine.php";
$staffPwaBranding = cpmsStaffPwaBranding($conn);

/*
|--------------------------------------------------------------------------
| Konfigurasi
|--------------------------------------------------------------------------
*/

const STAFF_SESSION_TIMEOUT = 1800;
const MAX_IMAGES_PER_GROUP = 3;
const MAX_IMAGE_SIZE = 12582912; // 12MB, Android camera friendly

const ALLOWED_WORK_STATUSES = [
    "In Progress",
    "Completed",
    "Pending Material",
    "Pending Contractor",
    "Unable to Complete"
];

const ALLOWED_IMAGE_MIME = [
    "image/jpeg" => "jpg",
    "image/png" => "png",
    "image/webp" => "webp"
];

/*
|--------------------------------------------------------------------------
| Helper umum
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

function requireStaffSession(): void
{
    if (!isset($_SESSION["staff_id"])) {
        header("Location: cpms/login.php");
        exit();
    }

    $lastActivity =
        (int) ($_SESSION["staff_last_activity"] ?? 0);

    if (
        $lastActivity > 0 &&
        time() - $lastActivity > STAFF_SESSION_TIMEOUT
    ) {
        session_unset();
        session_destroy();

        header("Location: cpms/login.php?expired=1");
        exit();
    }

    $_SESSION["staff_last_activity"] = time();
}

function showWorkError(
    string $message,
    int $statusCode = 400
): void {
    http_response_code($statusCode);

    $safeMessage = e($message);

    echo <<<HTML
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Rekod Tidak Berjaya</title>
    <link rel="stylesheet" href="css/pms.css?v=3">
<?php echo cpmsStaffPwaHead($staffPwaBranding); ?>
<?php echo cpmsStaffPwaStyle($staffPwaBranding); ?>
</head>
<body class="pms-body">
    <main
        class="pms-main"
        style="max-width:700px;margin:40px auto;"
    >
        <div class="pms-alert-error">
            {$safeMessage}
        </div>

        <a
            href="staff_work_form.php"
            class="pms-button-secondary"
        >
            ← Kembali ke Borang
        </a>
    </main>
<?php echo cpmsStaffPwaScripts(); ?>
</body>
</html>
HTML;

    exit();
}

function postString(string $key): string
{
    return trim(
        (string) ($_POST[$key] ?? "")
    );
}

function postInt(string $key): int
{
    return (int) ($_POST[$key] ?? 0);
}

function staffWorkColumnExists(
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

function staffWorkPropertyId(mysqli $conn, int $staffId): int
{
    $propertyId = (int) (
        $_SESSION["cpms_property_id"]
        ?? $_SESSION["staff_property_id"]
        ?? 0
    );

    if ($propertyId > 0) {
        return $propertyId;
    }

    if (staffWorkColumnExists($conn, "staff", "property_id")) {
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

function normalizeFiles(array $files): array
{
    if (!isset($files["name"])) {
        return [];
    }

    if (!is_array($files["name"])) {
        $singleFile = [
            "name" =>
                (string) ($files["name"] ?? ""),
            "tmp_name" =>
                (string) ($files["tmp_name"] ?? ""),
            "error" =>
                (int) ($files["error"] ?? UPLOAD_ERR_NO_FILE),
            "size" =>
                (int) ($files["size"] ?? 0)
        ];

        return
            $singleFile["error"] === UPLOAD_ERR_NO_FILE
                ? []
                : [$singleFile];
    }

    $normalized = [];
    $count = count($files["name"]);

    for ($index = 0; $index < $count; $index++) {
        $error =
            (int) (
                $files["error"][$index] ??
                UPLOAD_ERR_NO_FILE
            );

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $normalized[] = [
            "name" =>
                (string) ($files["name"][$index] ?? ""),
            "tmp_name" =>
                (string) ($files["tmp_name"][$index] ?? ""),
            "error" => $error,
            "size" =>
                (int) ($files["size"][$index] ?? 0)
        ];
    }

    return $normalized;
}

function validateCsrfToken(): void
{
    $postedToken =
        (string) ($_POST["csrf_token"] ?? "");

    $sessionToken =
        (string) ($_SESSION["staff_csrf_token"] ?? "");

    if (
        $sessionToken === "" ||
        !hash_equals($sessionToken, $postedToken)
    ) {
        showWorkError(
            "Permintaan tidak sah. Sila muat semula borang."
        );
    }
}

function validateWorkInput(array $data): void
{
    if (
        $data["work_date"] === "" ||
        $data["work_category"] === "" ||
        $data["block_location"] === "" ||
        $data["work_description"] === ""
    ) {
        showWorkError(
            "Sila lengkapkan semua medan wajib."
        );
    }

    if (
        !in_array(
            $data["work_status"],
            ALLOWED_WORK_STATUSES,
            true
        )
    ) {
        showWorkError(
            "Status kerja tidak sah."
        );
    }

    if (
        $data["start_time"] !== "" &&
        $data["end_time"] !== ""
    ) {
        $startTimestamp =
            strtotime($data["start_time"]);

        $endTimestamp =
            strtotime($data["end_time"]);

        if (
            $startTimestamp !== false &&
            $endTimestamp !== false &&
            $endTimestamp < $startTimestamp
        ) {
            showWorkError(
                "Masa tamat tidak boleh lebih awal daripada masa mula."
            );
        }
    }
}

function validateImageGroups(
    array $imageGroups,
    string $workStatus
): void {
    foreach ($imageGroups as $groupName => $files) {
        if (count($files) > MAX_IMAGES_PER_GROUP) {
            showWorkError(
                "Maksimum " .
                MAX_IMAGES_PER_GROUP .
                " gambar untuk kategori {$groupName}."
            );
        }
    }

    if (
        $workStatus === "Completed" &&
        count($imageGroups["After"]) === 0
    ) {
        showWorkError(
            "Sekurang-kurangnya satu gambar After diperlukan untuk kerja Completed."
        );
    }
}

function verifyAssignedWorkOrder(
    mysqli $conn,
    int $workOrderId,
    int $staffId,
    int $propertyId
): ?array {
    if ($workOrderId < 1) {
        return null;
    }

    $propertyFilter = staffWorkColumnExists($conn, "work_orders", "property_id")
        ? " AND property_id = ?"
        : "";

    $stmt = $conn->prepare(
        "
        SELECT
            id,
            work_order_reference,
            status
        FROM work_orders
        WHERE id = ?
          AND assigned_staff_id = ?
          {$propertyFilter}
          AND status NOT IN ('Verified', 'Cancelled')
        LIMIT 1
        "
    );

    if (!$stmt) {
        showWorkError(
            "Work Order tidak dapat disahkan.",
            500
        );
    }

    if ($propertyFilter !== "") {
        $stmt->bind_param("iii", $workOrderId, $staffId, $propertyId);
    } else {
        $stmt->bind_param("ii", $workOrderId, $staffId);
    }

    $stmt->execute();

    $workOrder =
        $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$workOrder) {
        showWorkError(
            "Work Order tidak sah atau tidak diberikan kepada anda."
        );
    }

    return $workOrder;
}

function generateDailyWorkReference(
    mysqli $conn
): string {
    do {
        $reference =
            "DW-" .
            date("Ymd") .
            "-" .
            strtoupper(
                bin2hex(random_bytes(3))
            );

        $stmt = $conn->prepare(
            "
            SELECT id
            FROM daily_work_logs
            WHERE work_reference = ?
            LIMIT 1
            "
        );

        if (!$stmt) {
            throw new RuntimeException(
                "Gagal menjana nombor rujukan."
            );
        }

        $stmt->bind_param(
            "s",
            $reference
        );

        $stmt->execute();

        $exists =
            $stmt->get_result()->num_rows > 0;

        $stmt->close();

    } while ($exists);

    return $reference;
}

function ensureUploadDirectory(
    string $uploadDirectory
): void {
    if (!is_dir($uploadDirectory)) {
        if (
            !mkdir(
                $uploadDirectory,
                0755,
                true
            ) &&
            !is_dir($uploadDirectory)
        ) {
            throw new RuntimeException(
                "Folder gambar kerja tidak dapat disediakan."
            );
        }
    }

    if (!is_writable($uploadDirectory)) {
        throw new RuntimeException(
            "Folder gambar kerja tidak boleh ditulis."
        );
    }
}

function storeUploadedImages(
    array $imageGroups,
    string $uploadDirectory,
    string $publicPrefix,
    array &$storedPaths
): array {
    $storedImages = [];

    foreach ($imageGroups as $imageType => $files) {
        foreach ($files as $file) {
            $uploadError =
                (int) $file["error"];

            if ($uploadError !== UPLOAD_ERR_OK) {
                $messages = [
                    UPLOAD_ERR_INI_SIZE => "Gambar melebihi had upload server.",
                    UPLOAD_ERR_FORM_SIZE => "Gambar melebihi had borang.",
                    UPLOAD_ERR_PARTIAL => "Gambar hanya separuh berjaya dimuat naik. Cuba semula.",
                    UPLOAD_ERR_NO_TMP_DIR => "Folder sementara server tidak tersedia.",
                    UPLOAD_ERR_CANT_WRITE => "Server gagal menulis fail gambar.",
                    UPLOAD_ERR_EXTENSION => "Upload gambar dihentikan oleh konfigurasi server.",
                ];

                throw new RuntimeException(
                    ($messages[$uploadError] ?? "Salah satu gambar gagal dimuat naik.") .
                    " Kod ralat: " . $uploadError
                );
            }

            if ((int) $file["size"] > MAX_IMAGE_SIZE) {
                throw new RuntimeException(
                    "Salah satu gambar melebihi 12MB. Sila pilih gambar lebih kecil."
                );
            }

            $temporaryName =
                (string) $file["tmp_name"];

            if (!is_uploaded_file($temporaryName)) {
                throw new RuntimeException(
                    "Fail gambar tidak sah."
                );
            }

            $finfo =
                new finfo(FILEINFO_MIME_TYPE);

            $mimeType =
                (string) $finfo->file($temporaryName);

            $imageInformation =
                @getimagesize($temporaryName);

            $detectedMime =
                is_array($imageInformation)
                    ? (string) (
                        $imageInformation["mime"] ??
                        ""
                    )
                    : "";

            if (
                !isset(ALLOWED_IMAGE_MIME[$mimeType]) ||
                $mimeType !== $detectedMime
            ) {
                throw new RuntimeException(
                    "Hanya gambar JPG, PNG dan WEBP dibenarkan."
                );
            }

            $fileName =
                bin2hex(random_bytes(16)) .
                "." .
                ALLOWED_IMAGE_MIME[$mimeType];

            $destination =
                $uploadDirectory . $fileName;

            if (
                !move_uploaded_file(
                    $temporaryName,
                    $destination
                )
            ) {
                throw new RuntimeException(
                    "Gambar gagal disimpan."
                );
            }

            $storedPaths[] =
                $destination;

            $storedImages[] = [
                "name" => $fileName,
                "path" => $publicPrefix . $fileName,
                "type" => $imageType
            ];
        }
    }

    return $storedImages;
}

function insertDailyWorkLog(
    mysqli $conn,
    string $workReference,
    int $staffId,
    int $propertyId,
    array $data
): int {
    $hasPropertyId = staffWorkColumnExists($conn, "daily_work_logs", "property_id");

    $columns = [
        "work_reference",
        "staff_id",
    ];
    $placeholders = ["?", "?"];
    $types = "si";
    $values = [$workReference, $staffId];

    if ($hasPropertyId) {
        $columns[] = "property_id";
        $placeholders[] = "?";
        $types .= "i";
        $values[] = $propertyId;
    }

    $columns = array_merge($columns, [
        "work_order_id",
        "work_date",
        "work_category",
        "block_location",
        "specific_location",
        "start_time",
        "end_time",
        "work_description",
        "materials_used",
        "issue_notes",
        "work_status",
        "include_in_newsletter",
    ]);
    $placeholders = array_merge($placeholders, [
        "NULLIF(?, 0)",
        "?",
        "?",
        "?",
        "?",
        "NULLIF(?, '')",
        "NULLIF(?, '')",
        "?",
        "?",
        "?",
        "?",
        "?",
    ]);
    $types .= "issssssssssi";
    $values = array_merge($values, [
        $data["work_order_id"],
        $data["work_date"],
        $data["work_category"],
        $data["block_location"],
        $data["specific_location"],
        $data["start_time"],
        $data["end_time"],
        $data["work_description"],
        $data["materials_used"],
        $data["issue_notes"],
        $data["work_status"],
        $data["include_in_newsletter"],
    ]);

    $stmt = $conn->prepare(
        "INSERT INTO daily_work_logs (`"
        . implode("`, `", $columns)
        . "`) VALUES ("
        . implode(", ", $placeholders)
        . ")"
    );

    if (!$stmt) {
        throw new RuntimeException(
            "Rekod kerja tidak dapat disediakan."
        );
    }

    $bindValues = [$types];
    foreach ($values as $key => &$value) {
        $bindValues[] = &$value;
    }
    call_user_func_array([$stmt, "bind_param"], $bindValues);

    if (!$stmt->execute()) {
        $databaseError = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            "Rekod kerja gagal disimpan. " .
            $databaseError
        );
    }

    $dailyWorkId =
        (int) $conn->insert_id;

    $stmt->close();

    return $dailyWorkId;
}

function insertDailyWorkImages(
    mysqli $conn,
    int $dailyWorkId,
    int $propertyId,
    array $storedImages
): void {
    if (count($storedImages) === 0) {
        return;
    }

    $hasPropertyId = staffWorkColumnExists($conn, "daily_work_images", "property_id");
    $hasImagePath = staffWorkColumnExists($conn, "daily_work_images", "image_path");
    $columns = ["daily_work_id", "image_name", "image_type"];
    $placeholders = ["?", "?", "?"];
    $types = "iss";

    if ($hasPropertyId) {
        $columns[] = "property_id";
        $placeholders[] = "?";
        $types .= "i";
    }

    if ($hasImagePath) {
        $columns[] = "image_path";
        $placeholders[] = "?";
        $types .= "s";
    }

    $stmt = $conn->prepare(
        "INSERT INTO daily_work_images (`"
        . implode("`, `", $columns)
        . "`) VALUES ("
        . implode(", ", $placeholders)
        . ")"
    );

    if (!$stmt) {
        throw new RuntimeException(
            "Rekod gambar tidak dapat disediakan."
        );
    }

    foreach ($storedImages as $image) {
        $imageName =
            (string) $image["name"];

        $imageType =
            (string) $image["type"];

        $values = [$dailyWorkId, $imageName, $imageType];

        if ($hasPropertyId) {
            $values[] = $propertyId;
        }

        if ($hasImagePath) {
            $values[] = (string) $image["path"];
        }

        $bindValues = [$types];
        foreach ($values as $key => &$value) {
            $bindValues[] = &$value;
        }
        call_user_func_array([$stmt, "bind_param"], $bindValues);

        if (!$stmt->execute()) {
            $databaseError = $stmt->error;
            $stmt->close();

            throw new RuntimeException(
                "Rekod gambar gagal disimpan. " .
                $databaseError
            );
        }
    }

    $stmt->close();
}

function mapDailyWorkStatusToWorkOrderStatus(
    string $workStatus
): string {
    return match ($workStatus) {
        "Completed" =>
            "Completed",

        "Pending Material" =>
            "Pending Material",

        "Pending Contractor" =>
            "Pending Contractor",

        "Unable to Complete" =>
            "In Progress",

        default =>
            "In Progress"
    };
}

function updateLinkedWorkOrder(
    mysqli $conn,
    array $linkedWorkOrder,
    int $workOrderId,
    string $workDescription,
    string $workStatus,
    string $workReference
): void {
    $oldStatus =
        (string) $linkedWorkOrder["status"];

    $newStatus =
        mapDailyWorkStatusToWorkOrderStatus(
            $workStatus
        );

    $updateStmt = $conn->prepare(
        "
        UPDATE work_orders
        SET
            status = ?,
            completion_notes = ?,
            completed_at =
                CASE
                    WHEN ? = 'Completed'
                    THEN COALESCE(completed_at, NOW())
                    ELSE completed_at
                END
        WHERE id = ?
        "
    );

    if (!$updateStmt) {
        throw new RuntimeException(
            "Status Work Order tidak dapat disediakan."
        );
    }

    $updateStmt->bind_param(
        "sssi",
        $newStatus,
        $workDescription,
        $newStatus,
        $workOrderId
    );

    if (!$updateStmt->execute()) {
        $databaseError =
            $updateStmt->error;

        $updateStmt->close();

        throw new RuntimeException(
            "Status Work Order gagal dikemas kini. " .
            $databaseError
        );
    }

    $updateStmt->close();

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
        VALUES (?, ?, ?, ?, ?)
        "
    );

    if (!$historyStmt) {
        return;
    }

    $updatedBy =
        (string) (
            $_SESSION["staff_name"] ??
            "Staff"
        );

    $historyRemarks =
        "Daily Work " .
        $workReference .
        ": " .
        $workDescription;

    $historyStmt->bind_param(
        "issss",
        $workOrderId,
        $oldStatus,
        $newStatus,
        $historyRemarks,
        $updatedBy
    );

    $historyStmt->execute();
    $historyStmt->close();
}

function removeStoredFiles(
    array $storedPaths
): void {
    foreach ($storedPaths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Permulaan proses
|--------------------------------------------------------------------------
*/

requireStaffSession();
cpmsRequire("daily_work.create", $conn);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: staff_work_form.php");
    exit();
}

validateCsrfToken();

$staffId =
    (int) $_SESSION["staff_id"];

$propertyId = staffWorkPropertyId($conn, $staffId);

if ($propertyId < 1) {
    showWorkError(
        "Akaun staff tidak mempunyai property_id yang sah. Sila log masuk semula melalui Unified Login atau semak assignment staff.",
        403
    );
}

$data = [
    "work_order_id" =>
        postInt("work_order_id"),

    "work_date" =>
        postString("work_date"),

    "work_category" =>
        postString("work_category"),

    "block_location" =>
        postString("block_location"),

    "specific_location" =>
        postString("specific_location"),

    "start_time" =>
        postString("start_time"),

    "end_time" =>
        postString("end_time"),

    "work_description" =>
        postString("work_description"),

    "materials_used" =>
        postString("materials_used"),

    "issue_notes" =>
        postString("issue_notes"),

    "work_status" =>
        postString("work_status"),

    "include_in_newsletter" =>
        postInt("include_in_newsletter") === 1
            ? 1
            : 0
];

validateWorkInput($data);

$linkedWorkOrder =
    verifyAssignedWorkOrder(
        $conn,
        $data["work_order_id"],
        $staffId,
        $propertyId
    );

$imageGroups = [
    "Before" =>
        normalizeFiles(
            $_FILES["before_images"] ?? []
        ),

    "During" =>
        normalizeFiles(
            $_FILES["during_images"] ?? []
        ),

    "After" =>
        normalizeFiles(
            $_FILES["after_images"] ?? []
        )
];

validateImageGroups(
    $imageGroups,
    $data["work_status"]
);

$dailyWorkUploadPrefix =
    "uploads/daily_work/property_" . $propertyId . "/";

$uploadDirectory =
    __DIR__ . "/" . $dailyWorkUploadPrefix;

$storedPaths = [];
$workReference = "";

try {
    $conn->begin_transaction();

    $workReference =
        generateDailyWorkReference($conn);

    ensureUploadDirectory(
        $uploadDirectory
    );

    $storedImages =
        storeUploadedImages(
            $imageGroups,
            $uploadDirectory,
            $dailyWorkUploadPrefix,
            $storedPaths
        );

    $dailyWorkId =
        insertDailyWorkLog(
            $conn,
            $workReference,
            $staffId,
            $propertyId,
            $data
        );

    insertDailyWorkImages(
        $conn,
        $dailyWorkId,
        $propertyId,
        $storedImages
    );

    if ($linkedWorkOrder !== null) {
        updateLinkedWorkOrder(
            $conn,
            $linkedWorkOrder,
            $data["work_order_id"],
            $data["work_description"],
            $data["work_status"],
            $workReference
        );
    }

    $conn->commit();

} catch (Throwable $error) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
    }

    removeStoredFiles(
        $storedPaths
    );

    error_log(
        "Daily work submit error: " .
        $error->getMessage()
    );

    showWorkError(
        "Rekod kerja tidak dapat disimpan. " .
        $error->getMessage()
    );
}

$conn->close();

header(
    "Location: staff_work_history.php?created=" .
    urlencode($workReference)
);

exit();
