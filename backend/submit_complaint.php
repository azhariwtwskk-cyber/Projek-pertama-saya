<?php

declare(strict_types=1);

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";
require_once __DIR__ . "/cpms/includes/property_context.php";
require_once __DIR__ . "/cpms/includes/property_guard.php";
require_once __DIR__ . "/cpms/includes/audit_engine.php";
require_once __DIR__ . "/cpms/includes/notification_engine.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: complaint_form.php");
    exit();
}

function showError(string $message): void
{
    $safeMessage = htmlspecialchars(
        $message,
        ENT_QUOTES,
        "UTF-8"
    );

    http_response_code(400);

    echo <<<HTML
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Aduan Tidak Dapat Dihantar</title>
    <link rel="stylesheet" href="css/style.css?v=72">
</head>
<body>
    <div class="container">
        <div class="error-message">
            <strong>Aduan Tidak Dapat Dihantar</strong>
            <br><br>
            {$safeMessage}
        </div>

        <div class="back-link">
            <a href="complaint_form.php">
                ← Kembali ke Borang
            </a>
        </div>
    </div>
</body>
</html>
HTML;

    exit();
}

function postValue(string $key): string
{
    return trim((string) ($_POST[$key] ?? ""));
}

function normalizeUploadFiles(array $files): array
{
    if (!isset($files["name"])) {
        return [];
    }

    if (!is_array($files["name"])) {
        return [[
            "name" => $files["name"] ?? "",
            "type" => $files["type"] ?? "",
            "tmp_name" => $files["tmp_name"] ?? "",
            "error" => $files["error"] ?? UPLOAD_ERR_NO_FILE,
            "size" => $files["size"] ?? 0
        ]];
    }

    $normalized = [];
    $count = count($files["name"]);

    for ($index = 0; $index < $count; $index++) {
        $normalized[] = [
            "name" => $files["name"][$index] ?? "",
            "type" => $files["type"][$index] ?? "",
            "tmp_name" => $files["tmp_name"][$index] ?? "",
            "error" => $files["error"][$index] ?? UPLOAD_ERR_NO_FILE,
            "size" => $files["size"][$index] ?? 0
        ];
    }

    return $normalized;
}

function safePropertyCode(string $code): string
{
    $cleaned = strtoupper(
        preg_replace(
            '/[^A-Z0-9]/i',
            '',
            $code
        ) ?? ""
    );

    return $cleaned !== ""
        ? substr($cleaned, 0, 12)
        : "CPMS";
}

$propertyId =
    cpmsRequireCurrentPropertyId($conn);

$currentProperty =
    cpmsCurrentProperty($conn);

if (!$currentProperty) {
    showError(
        "Property aktif tidak dijumpai."
    );
}

$propertyCode =
    safePropertyCode(
        (string) (
            $currentProperty["code"] ??
            "CPMS"
        )
    );

$name = postValue("name");
$phone = postValue("phone");
$email = postValue("email");
$residentStatusRaw = postValue("resident_status");
$blockRaw = postValue("block");
$unitNo = postValue("unit_no");
$location = postValue("location");
$category = postValue("category");
$subject = postValue("subject");
$description = postValue("description");
$priorityRaw = postValue("priority");
$declaration = postValue("declaration");

$requiredFields = [
    "Nama penuh" => $name,
    "Nombor telefon" => $phone,
    "Status penghuni" => $residentStatusRaw,
    "Blok" => $blockRaw,
    "Nombor unit" => $unitNo,
    "Lokasi masalah" => $location,
    "Kategori aduan" => $category,
    "Tajuk aduan" => $subject,
    "Penerangan aduan" => $description,
    "Tahap keutamaan" => $priorityRaw
];

foreach ($requiredFields as $label => $value) {
    if ($value === "") {
        showError($label . " wajib diisi.");
    }
}

if ($declaration !== "1") {
    showError("Sila sahkan perakuan maklumat.");
}

if (
    $email !== "" &&
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    showError("Format alamat e-mel tidak sah.");
}

if (!preg_match('/^[0-9+\-\s()]{8,20}$/', $phone)) {
    showError("Format nombor telefon tidak sah.");
}

$residentStatusMap = [
    "Pemilik / Owner" => "Owner",
    "Penyewa / Tenant" => "Tenant",
    "Owner" => "Owner",
    "Tenant" => "Tenant"
];

if (!isset($residentStatusMap[$residentStatusRaw])) {
    showError("Status penghuni tidak sah.");
}

$residentStatus = $residentStatusMap[$residentStatusRaw];

$block = "";

if (
    preg_match(
        '/\b(?:BLOK|BLOCK)\s*([A-Z0-9]+)\b/i',
        $blockRaw,
        $matches
    )
) {
    $block = strtoupper($matches[1]);
} elseif (
    preg_match(
        '/^[A-Z0-9]+$/i',
        $blockRaw
    )
) {
    $block = strtoupper($blockRaw);
}

if ($block === "") {
    showError("Pilihan blok tidak sah.");
}

$allowedCategories = [
    "Kerosakan Bangunan / Building Maintenance",
    "Kebocoran Air / Water Leakage",
    "Masalah Elektrik / Electrical Problem",
    "Masalah Lif / Lift Problem",
    "Kebersihan / Cleanliness",
    "Keselamatan / Security",
    "Parkir / Parking",
    "Lain-lain / Others"
];

if (!in_array($category, $allowedCategories, true)) {
    showError("Kategori aduan tidak sah.");
}

$priorityMap = [
    "Tinggi / High" => "Tinggi / High",
    "Sederhana / Medium" => "Sederhana / Medium",
    "Rendah / Low" => "Rendah / Low"
];

if (!isset($priorityMap[$priorityRaw])) {
    showError("Tahap keutamaan tidak sah.");
}

$priority = $priorityMap[$priorityRaw];
$status = "Pending";

$uploadedFiles = normalizeUploadFiles(
    $_FILES["images"] ?? []
);

$uploadedFiles = array_values(
    array_filter(
        $uploadedFiles,
        static fn(array $file): bool =>
            (int) $file["error"] !== UPLOAD_ERR_NO_FILE
    )
);

if (count($uploadedFiles) > 5) {
    showError("Maksimum lima gambar sahaja dibenarkan.");
}

$storedPaths = [];
$storedNames = [];

try {
    $conn->begin_transaction();

    /*
    |--------------------------------------------------------------------------
    | Pastikan sequence property wujud
    |--------------------------------------------------------------------------
    */

    $sequenceEnsure = $conn->prepare(
        "
        INSERT INTO cpms_complaint_sequences
        (
            property_id,
            last_number
        )
        VALUES (?, 0)
        ON DUPLICATE KEY UPDATE
            property_id = VALUES(property_id)
        "
    );

    if (!$sequenceEnsure) {
        throw new RuntimeException(
            "Sequence aduan property tidak dapat disediakan."
        );
    }

    $sequenceEnsure->bind_param(
        "i",
        $propertyId
    );

    if (!$sequenceEnsure->execute()) {
        throw new RuntimeException(
            "Sequence aduan property gagal diwujudkan."
        );
    }

    $sequenceEnsure->close();

    /*
    |--------------------------------------------------------------------------
    | Lock sequence property semasa
    |--------------------------------------------------------------------------
    */

    $sequenceStmt = $conn->prepare(
        "
        SELECT last_number
        FROM cpms_complaint_sequences
        WHERE property_id = ?
        FOR UPDATE
        "
    );

    if (!$sequenceStmt) {
        throw new RuntimeException(
            "Nombor siri aduan tidak dapat disediakan."
        );
    }

    $sequenceStmt->bind_param(
        "i",
        $propertyId
    );

    $sequenceStmt->execute();

    $sequenceRow =
        $sequenceStmt
            ->get_result()
            ->fetch_assoc();

    $sequenceStmt->close();

    if (!$sequenceRow) {
        throw new RuntimeException(
            "Rekod nombor siri property tidak dijumpai."
        );
    }

    $nextNumber =
        ((int) $sequenceRow["last_number"]) + 1;

    $sequenceUpdate = $conn->prepare(
        "
        UPDATE cpms_complaint_sequences
        SET last_number = ?
        WHERE property_id = ?
        "
    );

    if (!$sequenceUpdate) {
        throw new RuntimeException(
            "Nombor siri property tidak dapat dikemas kini."
        );
    }

    $sequenceUpdate->bind_param(
        "ii",
        $nextNumber,
        $propertyId
    );

    if (!$sequenceUpdate->execute()) {
        throw new RuntimeException(
            "Nombor siri property gagal dikemas kini."
        );
    }

    $sequenceUpdate->close();

    $complaintId =
        $propertyCode .
        "-" .
        date("ymd") .
        "-" .
        str_pad(
            (string) $nextNumber,
            4,
            "0",
            STR_PAD_LEFT
        );

    $uploadDirectory =
        __DIR__ .
        "/uploads/";

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
                "Folder uploads tidak dapat disediakan."
            );
        }
    }

    if (!is_writable($uploadDirectory)) {
        throw new RuntimeException(
            "Folder uploads tidak mempunyai kebenaran menulis."
        );
    }

    $allowedMimeTypes = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp"
    ];

    $maxFileSize =
        5 * 1024 * 1024;

    foreach ($uploadedFiles as $file) {
        if (
            (int) $file["error"] !==
            UPLOAD_ERR_OK
        ) {
            throw new RuntimeException(
                "Salah satu gambar gagal dimuat naik."
            );
        }

        if (
            (int) $file["size"] >
            $maxFileSize
        ) {
            throw new RuntimeException(
                "Salah satu gambar melebihi saiz maksimum 5MB."
            );
        }

        $tmpName =
            (string) $file["tmp_name"];

        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException(
                "Fail gambar tidak sah."
            );
        }

        if (class_exists("finfo")) {
            $finfo =
                new finfo(FILEINFO_MIME_TYPE);

            $mimeType =
                (string) $finfo->file($tmpName);

        } elseif (
            function_exists("mime_content_type")
        ) {
            $mimeType =
                (string) mime_content_type($tmpName);

        } else {
            throw new RuntimeException(
                "Server tidak dapat mengesahkan jenis gambar."
            );
        }

        $imageInfo =
            @getimagesize($tmpName);

        $detectedMime =
            is_array($imageInfo)
                ? (string) (
                    $imageInfo["mime"] ??
                    ""
                )
                : "";

        if (
            !isset($allowedMimeTypes[$mimeType]) ||
            !isset($allowedMimeTypes[$detectedMime]) ||
            $mimeType !== $detectedMime
        ) {
            throw new RuntimeException(
                "Hanya gambar JPG, PNG dan WEBP dibenarkan."
            );
        }

        $extension =
            $allowedMimeTypes[$mimeType];

        try {
            $randomName =
                bin2hex(random_bytes(16));
        } catch (Throwable $error) {
            $randomName =
                uniqid(
                    strtolower($propertyCode) . "_",
                    true
                );
        }

        $imageName =
            $randomName .
            "." .
            $extension;

        $destination =
            $uploadDirectory .
            $imageName;

        if (
            !move_uploaded_file(
                $tmpName,
                $destination
            )
        ) {
            throw new RuntimeException(
                "Salah satu gambar gagal disimpan."
            );
        }

        $storedPaths[] =
            $destination;

        $storedNames[] =
            $imageName;
    }

    $legacyImage =
        $storedNames[0] ??
        "";

    $insertStmt = $conn->prepare(
        "
        INSERT INTO complaints
        (
            complaint_id,
            property_id,
            name,
            phone,
            email,
            resident_status,
            unit_no,
            block,
            location,
            category,
            subject,
            description,
            priority,
            image,
            status
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?
        )
        "
    );

    if (!$insertStmt) {
        throw new RuntimeException(
            "Sistem gagal menyediakan rekod aduan."
        );
    }

    $insertStmt->bind_param(
        "sisssssssssssss",
        $complaintId,
        $propertyId,
        $name,
        $phone,
        $email,
        $residentStatus,
        $unitNo,
        $block,
        $location,
        $category,
        $subject,
        $description,
        $priority,
        $legacyImage,
        $status
    );

    if (!$insertStmt->execute()) {
        throw new RuntimeException(
            "Aduan gagal disimpan. " .
            $insertStmt->error
        );
    }

    $insertStmt->close();

    if (count($storedNames) > 0) {
        $imageStmt = $conn->prepare(
            "
            INSERT INTO complaint_images
            (
                complaint_id,
                property_id,
                image_name
            )
            VALUES
            (
                ?,
                ?,
                ?
            )
            "
        );

        if (!$imageStmt) {
            throw new RuntimeException(
                "Sistem gagal menyediakan rekod gambar."
            );
        }

        foreach ($storedNames as $imageName) {
            $imageStmt->bind_param(
                "sis",
                $complaintId,
                $propertyId,
                $imageName
            );

            if (!$imageStmt->execute()) {
                throw new RuntimeException(
                    "Rekod gambar gagal disimpan."
                );
            }
        }

        $imageStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Audit: Complaint created
    |--------------------------------------------------------------------------
    */

    cpmsAudit(
        $conn,
        $propertyId,
        "Complaint",
        "CREATE",
        $complaintId,
        $complaintId,
        $name,
        "Resident",
        "Complaint submitted by resident.",
        null,
        [
            "status" => $status,
            "priority" => $priority,
            "category" => $category,
            "block" => $block,
            "unit_no" => $unitNo
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Notification: Aduan baharu untuk admin
    |--------------------------------------------------------------------------
    */

    cpmsNotifyAdmin(
        $conn,
        $propertyId,
        "Aduan Baharu Diterima",
        "Aduan baharu daripada " .
            $name .
            " telah diterima untuk " .
            $currentProperty["name"] .
            ".",
        "COMPLAINT",
        $complaintId,
        "admin_dashboard.php?search=" .
            urlencode($complaintId)
    );

    $conn->commit();

} catch (Throwable $error) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
    }

    foreach ($storedPaths as $storedPath) {
        if (is_file($storedPath)) {
            @unlink($storedPath);
        }
    }

    error_log(
        "Submit complaint property error: " .
        $error->getMessage()
    );

    showError(
        "Aduan tidak dapat dihantar. " .
        $error->getMessage()
    );
}

$conn->close();

header(
    "Location: complaint_success.php?ref=" .
    urlencode($complaintId)
);

exit();
