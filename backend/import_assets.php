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

function calculateNextServiceDate(
    string $lastServiceDate,
    string $frequency
): ?string {
    if ($lastServiceDate === "") {
        return null;
    }

    try {
        $date = new DateTimeImmutable($lastServiceDate);
    } catch (Throwable $error) {
        return null;
    }

    return match ($frequency) {
        "Weekly" =>
            $date->modify("+1 week")->format("Y-m-d"),

        "Monthly" =>
            $date->modify("+1 month")->format("Y-m-d"),

        "Quarterly" =>
            $date->modify("+3 months")->format("Y-m-d"),

        "Half Yearly" =>
            $date->modify("+6 months")->format("Y-m-d"),

        "Yearly" =>
            $date->modify("+1 year")->format("Y-m-d"),

        default => null
    };
}

function normalizeDate(string $value): string
{
    $value = trim($value);

    if ($value === "") {
        return "";
    }

    $formats = [
        "Y-m-d",
        "d/m/Y",
        "d-m-Y",
        "m/d/Y"
    ];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat(
            $format,
            $value
        );

        if (
            $date !== false &&
            $date->format($format) === $value
        ) {
            return $date->format("Y-m-d");
        }
    }

    return "";
}

if (
    !isset($_SESSION["asset_import_csrf"]) ||
    !is_string($_SESSION["asset_import_csrf"])
) {
    $_SESSION["asset_import_csrf"] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["asset_import_csrf"];

$categories = [
    "Building",
    "Boom Gate",
    "Guard House",
    "Solar CCTV",
    "Water Tank",
    "Water Pump",
    "Pump Control Panel",
    "Float Valve",
    "Pressure Gauge",
    "Electrical Distribution Board",
    "Fire Hydrant",
    "Fire Extinguisher",
    "Playground",
    "Drainage",
    "Common Area Lighting",
    "Lamp Post",
    "Landscape",
    "Other"
];

$frequencies = [
    "Weekly",
    "Monthly",
    "Quarterly",
    "Half Yearly",
    "Yearly",
    "As Required"
];

$statuses = [
    "Active",
    "Under Maintenance",
    "Out of Service",
    "Expired",
    "Disposed"
];

$successMessage = "";
$errorMessage = "";
$importErrors = [];

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["import_assets"])
) {
    $postedToken =
        (string) ($_POST["csrf_token"] ?? "");

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage =
            "Permintaan tidak sah. Sila muat semula halaman.";
    } elseif (
        !isset($_FILES["csv_file"]) ||
        (int) $_FILES["csv_file"]["error"] !== UPLOAD_ERR_OK
    ) {
        $errorMessage =
            "Sila pilih fail CSV yang sah.";
    } elseif (
        (int) $_FILES["csv_file"]["size"] >
        2 * 1024 * 1024
    ) {
        $errorMessage =
            "Saiz fail CSV tidak boleh melebihi 2MB.";
    } else {
        $tmpName =
            (string) $_FILES["csv_file"]["tmp_name"];

        $handle = fopen($tmpName, "rb");

        if ($handle === false) {
            $errorMessage =
                "Fail CSV tidak dapat dibaca.";
        } else {
            $expectedHeaders = [
                "asset_code",
                "asset_name",
                "asset_category",
                "location",
                "block_location",
                "brand",
                "model",
                "serial_number",
                "installation_date",
                "warranty_expiry",
                "vendor_name",
                "vendor_phone",
                "maintenance_frequency",
                "last_service_date",
                "asset_status",
                "notes"
            ];

            $headers = fgetcsv($handle);

            if ($headers === false) {
                $errorMessage =
                    "Fail CSV kosong.";
            } else {
                $headers = array_map(
                    static fn($value): string =>
                        strtolower(
                            trim(
                                preg_replace(
                                    '/^\xEF\xBB\xBF/',
                                    '',
                                    (string) $value
                                )
                            )
                        ),
                    $headers
                );

                if ($headers !== $expectedHeaders) {
                    $errorMessage =
                        "Susunan column CSV tidak betul. Gunakan template yang diberikan.";
                } else {
                    $createdBy =
                        (string) $_SESSION["admin"];

                    $stmt = $conn->prepare(
                        "
                        INSERT INTO assets
                        (
                            asset_code,
                            asset_name,
                            asset_category,
                            location,
                            block_location,
                            brand,
                            model,
                            serial_number,
                            installation_date,
                            warranty_expiry,
                            vendor_name,
                            vendor_phone,
                            maintenance_frequency,
                            last_service_date,
                            next_service_date,
                            asset_status,
                            notes,
                            created_by
                        )
                        VALUES
                        (
                            ?, ?, ?, ?,
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            NULLIF(?, ''),
                            ?,
                            NULLIF(?, ''),
                            ?,
                            ?,
                            NULLIF(?, ''),
                            ?
                        )
                        "
                    );

                    if (!$stmt) {
                        $errorMessage =
                            "Import tidak dapat disediakan.";
                    } else {
                        $rowNumber = 1;
                        $successCount = 0;
                        $failedCount = 0;

                        $conn->begin_transaction();

                        try {
                            while (
                                ($row = fgetcsv($handle)) !== false
                            ) {
                                $rowNumber++;

                                if (
                                    count($row) === 1 &&
                                    trim((string) $row[0]) === ""
                                ) {
                                    continue;
                                }

                                if (
                                    count($row) !==
                                    count($expectedHeaders)
                                ) {
                                    $failedCount++;

                                    $importErrors[] =
                                        "Baris {$rowNumber}: jumlah column tidak betul.";

                                    continue;
                                }

                                $data = array_combine(
                                    $expectedHeaders,
                                    array_map(
                                        static fn($value): string =>
                                            trim((string) $value),
                                        $row
                                    )
                                );

                                if ($data === false) {
                                    $failedCount++;

                                    $importErrors[] =
                                        "Baris {$rowNumber}: data tidak dapat dibaca.";

                                    continue;
                                }

                                $assetCode =
                                    strtoupper($data["asset_code"]);

                                $assetName =
                                    $data["asset_name"];

                                $category =
                                    $data["asset_category"];

                                $location =
                                    $data["location"];

                                $blockLocation =
                                    $data["block_location"];

                                $brand =
                                    $data["brand"];

                                $model =
                                    $data["model"];

                                $serialNumber =
                                    $data["serial_number"];

                                $installationDate =
                                    normalizeDate(
                                        $data["installation_date"]
                                    );

                                $warrantyExpiry =
                                    normalizeDate(
                                        $data["warranty_expiry"]
                                    );

                                $vendorName =
                                    $data["vendor_name"];

                                $vendorPhone =
                                    $data["vendor_phone"];

                                $frequency =
                                    $data[
                                        "maintenance_frequency"
                                    ];

                                $lastServiceDate =
                                    normalizeDate(
                                        $data["last_service_date"]
                                    );

                                $status =
                                    $data["asset_status"];

                                $notes =
                                    $data["notes"];

                                if (
                                    $assetCode === "" ||
                                    $assetName === "" ||
                                    $category === "" ||
                                    $location === ""
                                ) {
                                    $failedCount++;

                                    $importErrors[] =
                                        "Baris {$rowNumber}: medan wajib kosong.";

                                    continue;
                                }

                                if (
                                    !preg_match(
                                        '/^[A-Z0-9][A-Z0-9\-_.]{1,49}$/',
                                        $assetCode
                                    )
                                ) {
                                    $failedCount++;

                                    $importErrors[] =
                                        "Baris {$rowNumber}: Asset Code tidak sah.";

                                    continue;
                                }

                                if (
                                    !in_array(
                                        $category,
                                        $categories,
                                        true
                                    )
                                ) {
                                    $failedCount++;

                                    $importErrors[] =
                                        "Baris {$rowNumber}: kategori tidak sah.";

                                    continue;
                                }

                                if (
                                    !in_array(
                                        $frequency,
                                        $frequencies,
                                        true
                                    )
                                ) {
                                    $failedCount++;

                                    $importErrors[] =
                                        "Baris {$rowNumber}: kekerapan tidak sah.";

                                    continue;
                                }

                                if (
                                    !in_array(
                                        $status,
                                        $statuses,
                                        true
                                    )
                                ) {
                                    $failedCount++;

                                    $importErrors[] =
                                        "Baris {$rowNumber}: status tidak sah.";

                                    continue;
                                }

                                $nextServiceDate =
                                    calculateNextServiceDate(
                                        $lastServiceDate,
                                        $frequency
                                    );

                                $stmt->bind_param(
                                    "ssssssssssssssssss",
                                    $assetCode,
                                    $assetName,
                                    $category,
                                    $location,
                                    $blockLocation,
                                    $brand,
                                    $model,
                                    $serialNumber,
                                    $installationDate,
                                    $warrantyExpiry,
                                    $vendorName,
                                    $vendorPhone,
                                    $frequency,
                                    $lastServiceDate,
                                    $nextServiceDate,
                                    $status,
                                    $notes,
                                    $createdBy
                                );

                                if ($stmt->execute()) {
                                    $successCount++;
                                } else {
                                    $failedCount++;

                                    if (
                                        (int) $stmt->errno === 1062
                                    ) {
                                        $importErrors[] =
                                            "Baris {$rowNumber}: Asset Code {$assetCode} sudah wujud.";
                                    } else {
                                        $importErrors[] =
                                            "Baris {$rowNumber}: gagal disimpan.";
                                    }
                                }
                            }

                            $conn->commit();

                            $successMessage =
                                "{$successCount} aset berjaya diimport. " .
                                "{$failedCount} rekod gagal.";

                        } catch (Throwable $error) {
                            $conn->rollback();

                            error_log(
                                "Asset CSV import error: " .
                                $error->getMessage()
                            );

                            $errorMessage =
                                "Import CSV gagal diproses.";
                        }

                        $stmt->close();
                    }
                }
            }

            fclose($handle);
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

    <title>
        Import Aset CSV | V23 PMS
    </title>

    <link
        rel="stylesheet"
        href="css/pms.css?v=7"
    >

    <link
        rel="stylesheet"
        href="css/pms_assets.css?v=2"
    >

    <style>
        .csv-guide {
            margin-top: 20px;
            padding: 18px;
            border: 1px solid #ddd4b8;
            border-radius: 10px;
            background: #fffaf0;
        }

        .csv-guide h3 {
            margin-top: 0;
            color: #4a2b20;
        }

        .csv-guide code {
            white-space: normal;
            word-break: break-word;
        }

        .csv-errors {
            max-height: 320px;
            overflow-y: auto;
            margin-top: 15px;
            padding: 15px;
            border: 1px solid #efbcbc;
            border-radius: 9px;
            background: #fff5f5;
        }

        .csv-errors ul {
            margin: 0;
            padding-left: 20px;
        }
    </style>
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
                <strong>
                    V23 Malawa Ria
                </strong>

                <span>
                    Property Management System
                </span>
            </div>

        </div>

        <nav class="pms-nav">

            <a href="admin_dashboard.php">
                Dashboard
            </a>

            <a href="admin_assets.php">
                Asset Management
            </a>

            <a
                href="import_assets.php"
                class="active"
            >
                Import Assets CSV
            </a>

            <a href="admin_maintenance.php">
                Preventive Maintenance
            </a>

        </nav>

        <div class="pms-sidebar-footer">

            <a href="admin_logout.php">
                Logout Admin
            </a>

        </div>

    </aside>

    <main class="pms-main">

        <header class="pms-topbar">

            <div>

                <h1>
                    Import Aset Secara Pukal
                </h1>

                <p>
                    Gunakan CSV untuk memasukkan banyak lampu,
                    pam dan aset lain sekaligus.
                </p>

            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">

                <a
                    href="asset_import_template.csv"
                    class="pms-button"
                    download
                >
                    Download Template CSV
                </a>

                <a
                    href="admin_assets.php"
                    class="pms-button-secondary"
                >
                    ← Senarai Aset
                </a>

            </div>

        </header>

        <?php if ($successMessage !== ""): ?>

            <div class="pms-alert-success">
                <?php echo e($successMessage); ?>
            </div>

        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>

            <div class="pms-alert-error">
                <?php echo e($errorMessage); ?>
            </div>

        <?php endif; ?>

        <?php if (count($importErrors) > 0): ?>

            <div class="csv-errors">

                <strong>
                    Rekod yang gagal:
                </strong>

                <ul>

                    <?php foreach (
                        array_slice($importErrors, 0, 100)
                        as $importError
                    ): ?>

                        <li>
                            <?php echo e($importError); ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>

        <section class="pms-card">

            <form
                method="POST"
                enctype="multipart/form-data"
                class="pms-form-grid"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($csrfToken); ?>"
                >

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >

                    <label for="csv_file">
                        Pilih Fail CSV *
                    </label>

                    <input
                        type="file"
                        id="csv_file"
                        name="csv_file"
                        accept=".csv,text/csv"
                        required
                    >

                    <small>
                        Maksimum 2MB. Gunakan template CSV yang diberikan.
                    </small>

                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >

                    <button
                        type="submit"
                        name="import_assets"
                        class="pms-button"
                    >
                        Import Aset
                    </button>

                </div>

            </form>

            <div class="csv-guide">

                <h3>
                    Cara Menggunakan CSV
                </h3>

                <p>
                    Jangan ubah nama atau susunan column.
                    Buka template menggunakan Microsoft Excel,
                    isi aset, kemudian simpan sebagai
                    <strong>CSV UTF-8</strong>.
                </p>

                <p>
                    Tarikh boleh menggunakan format:
                    <code>2026-07-14</code> atau
                    <code>14/07/2026</code>.
                </p>

                <p>
                    Contoh kategori:
                    Lamp Post, Water Pump,
                    Pump Control Panel,
                    Water Tank dan Fire Hydrant.
                </p>

            </div>

        </section>

    </main>

</div>

</body>

</html>
