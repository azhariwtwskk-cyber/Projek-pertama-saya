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

if (
    !isset($_SESSION["asset_csrf"]) ||
    !is_string($_SESSION["asset_csrf"])
) {
    $_SESSION["asset_csrf"] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["asset_csrf"];
$errorMessage = "";

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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken =
        (string) ($_POST["csrf_token"] ?? "");

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage =
            "Permintaan tidak sah. Sila muat semula halaman.";
    } else {
        $assetCode = strtoupper(
            trim((string) ($_POST["asset_code"] ?? ""))
        );

        $assetName =
            trim((string) ($_POST["asset_name"] ?? ""));

        $category =
            trim((string) ($_POST["asset_category"] ?? ""));

        $location =
            trim((string) ($_POST["location"] ?? ""));

        $blockLocation =
            trim((string) ($_POST["block_location"] ?? ""));

        $brand =
            trim((string) ($_POST["brand"] ?? ""));

        $model =
            trim((string) ($_POST["model"] ?? ""));

        $serialNumber =
            trim((string) ($_POST["serial_number"] ?? ""));

        $installationDate =
            trim((string) ($_POST["installation_date"] ?? ""));

        $warrantyExpiry =
            trim((string) ($_POST["warranty_expiry"] ?? ""));

        $vendorName =
            trim((string) ($_POST["vendor_name"] ?? ""));

        $vendorPhone =
            trim((string) ($_POST["vendor_phone"] ?? ""));

        $frequency =
            trim((string) (
                $_POST["maintenance_frequency"] ??
                ""
            ));

        $lastServiceDate =
            trim((string) ($_POST["last_service_date"] ?? ""));

        $status =
            trim((string) ($_POST["asset_status"] ?? ""));

        $notes =
            trim((string) ($_POST["notes"] ?? ""));

        if (
            $assetCode === "" ||
            $assetName === "" ||
            $category === "" ||
            $location === ""
        ) {
            $errorMessage =
                "Sila lengkapkan semua medan wajib.";
        } elseif (
            !preg_match(
                '/^[A-Z0-9][A-Z0-9\-_.]{1,49}$/',
                $assetCode
            )
        ) {
            $errorMessage =
                "Asset Code hanya boleh mengandungi huruf besar, nombor, titik, sengkang dan garis bawah.";
        } elseif (
            !in_array($category, $categories, true)
        ) {
            $errorMessage =
                "Kategori aset tidak sah.";
        } elseif (
            !in_array($frequency, $frequencies, true)
        ) {
            $errorMessage =
                "Kekerapan penyelenggaraan tidak sah.";
        } elseif (
            !in_array($status, $statuses, true)
        ) {
            $errorMessage =
                "Status aset tidak sah.";
        } else {
            $nextServiceDate =
                calculateNextServiceDate(
                    $lastServiceDate,
                    $frequency
                );

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
                    "Aset tidak dapat disediakan.";
            } else {
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
                    header(
                        "Location: admin_assets.php?created=" .
                        urlencode($assetCode)
                    );
                    exit();
                }

                if ((int) $stmt->errno === 1062) {
                    $errorMessage =
                        "Asset Code tersebut sudah digunakan.";
                } else {
                    error_log(
                        "Create asset error: " .
                        $stmt->error
                    );

                    $errorMessage =
                        "Aset tidak dapat disimpan.";
                }

                $stmt->close();
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

    <title>
        Tambah Aset | V23 PMS
    </title>

    <link
        rel="stylesheet"
        href="css/pms.css?v=7"
    >

    <link
        rel="stylesheet"
        href="css/pms_assets.css?v=2"
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

            <a
                href="admin_assets.php"
                class="active"
            >
                Asset Management
            </a>

            <a href="import_assets.php">
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
                    Tambah Aset
                </h1>

                <p>
                    Daftarkan aset V23 Malawa Ria satu demi satu.
                </p>

            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">

                <a
                    href="import_assets.php"
                    class="pms-button"
                >
                    Import CSV
                </a>

                <a
                    href="admin_assets.php"
                    class="pms-button-secondary"
                >
                    ← Senarai Aset
                </a>

            </div>

        </header>

        <?php if ($errorMessage !== ""): ?>

            <div class="pms-alert-error">
                <?php echo e($errorMessage); ?>
            </div>

        <?php endif; ?>

        <section class="pms-card">

            <form method="POST" class="pms-form-grid">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($csrfToken); ?>"
                >

                <div class="pms-form-group">

                    <label for="asset_code">
                        Asset Code *
                    </label>

                    <input
                        type="text"
                        id="asset_code"
                        name="asset_code"
                        maxlength="50"
                        placeholder="Contoh: LP-A-001"
                        required
                    >

                </div>

                <div class="pms-form-group">

                    <label for="asset_name">
                        Nama Aset *
                    </label>

                    <input
                        type="text"
                        id="asset_name"
                        name="asset_name"
                        maxlength="150"
                        placeholder="Contoh: Tiang Lampu Block A No. 1"
                        required
                    >

                </div>

                <div class="pms-form-group">

                    <label for="asset_category">
                        Kategori *
                    </label>

                    <select
                        id="asset_category"
                        name="asset_category"
                        required
                    >

                        <option value="">
                            -- Sila Pilih --
                        </option>

                        <?php foreach (
                            $categories as $category
                        ): ?>

                            <option
                                value="<?php echo e($category); ?>"
                            >
                                <?php echo e($category); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="pms-form-group">

                    <label for="location">
                        Lokasi *
                    </label>

                    <input
                        type="text"
                        id="location"
                        name="location"
                        maxlength="150"
                        placeholder="Contoh: Laluan hadapan Block A"
                        required
                    >

                </div>

                <div class="pms-form-group">

                    <label for="block_location">
                        Blok
                    </label>

                    <select
                        id="block_location"
                        name="block_location"
                    >

                        <option value="">
                            Tidak Berkaitan
                        </option>

                        <option value="Block A">
                            Block A
                        </option>

                        <option value="Block B">
                            Block B
                        </option>

                        <option value="Block C">
                            Block C
                        </option>

                        <option value="Block D">
                            Block D
                        </option>

                        <option value="Block E">
                            Block E
                        </option>

                    </select>

                </div>

                <div class="pms-form-group">

                    <label for="brand">
                        Jenama
                    </label>

                    <input
                        type="text"
                        id="brand"
                        name="brand"
                        maxlength="100"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="model">
                        Model
                    </label>

                    <input
                        type="text"
                        id="model"
                        name="model"
                        maxlength="100"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="serial_number">
                        Serial Number
                    </label>

                    <input
                        type="text"
                        id="serial_number"
                        name="serial_number"
                        maxlength="120"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="installation_date">
                        Tarikh Pemasangan
                    </label>

                    <input
                        type="date"
                        id="installation_date"
                        name="installation_date"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="warranty_expiry">
                        Waranti Tamat
                    </label>

                    <input
                        type="date"
                        id="warranty_expiry"
                        name="warranty_expiry"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="vendor_name">
                        Vendor
                    </label>

                    <input
                        type="text"
                        id="vendor_name"
                        name="vendor_name"
                        maxlength="150"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="vendor_phone">
                        Telefon Vendor
                    </label>

                    <input
                        type="text"
                        id="vendor_phone"
                        name="vendor_phone"
                        maxlength="30"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="maintenance_frequency">
                        Kekerapan Servis *
                    </label>

                    <select
                        id="maintenance_frequency"
                        name="maintenance_frequency"
                        required
                    >

                        <?php foreach (
                            $frequencies as $frequency
                        ): ?>

                            <option
                                value="<?php echo e($frequency); ?>"
                                <?php
                                echo $frequency === "Monthly"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo e($frequency); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="pms-form-group">

                    <label for="last_service_date">
                        Servis Terakhir
                    </label>

                    <input
                        type="date"
                        id="last_service_date"
                        name="last_service_date"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="asset_status">
                        Status Aset *
                    </label>

                    <select
                        id="asset_status"
                        name="asset_status"
                        required
                    >

                        <?php foreach (
                            $statuses as $status
                        ): ?>

                            <option
                                value="<?php echo e($status); ?>"
                            >
                                <?php echo e($status); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >

                    <label for="notes">
                        Catatan
                    </label>

                    <textarea
                        id="notes"
                        name="notes"
                        maxlength="3000"
                        placeholder="Contoh: Lampu LED 50W, tiang galvanised"
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
                        Simpan Aset
                    </button>

                </div>

            </form>

        </section>

    </main>

</div>

</body>

</html>
