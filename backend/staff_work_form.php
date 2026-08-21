<?php

declare(strict_types=1);

session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

require_once "db.php";
require_once "staff_pwa_bootstrap.php";
require_once __DIR__ . "/cpms/includes/permission_engine.php";
$staffPwaBranding = cpmsStaffPwaBranding($conn);


function requireStaffSession(): void
{
    if (!isset($_SESSION["staff_id"])) {
        header("Location: cpms/login.php");
        exit();
    }

    $timeoutSeconds = 1800;

    if (
        isset($_SESSION["staff_last_activity"]) &&
        time() - (int) $_SESSION["staff_last_activity"] > $timeoutSeconds
    ) {
        session_unset();
        session_destroy();
        header("Location: cpms/login.php?expired=1");
        exit();
    }

    $_SESSION["staff_last_activity"] = time();
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function staffFormColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
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

function staffFormPropertyId(mysqli $conn, int $staffId): int
{
    $propertyId = (int) (
        $_SESSION["cpms_property_id"]
        ?? $_SESSION["staff_property_id"]
        ?? 0
    );

    if ($propertyId > 0) {
        return $propertyId;
    }

    if (staffFormColumnExists($conn, "staff", "property_id")) {
        $stmt = $conn->prepare("SELECT property_id FROM staff WHERE id = ? LIMIT 1");
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


requireStaffSession();
cpmsRequire("daily_work.create", $conn);

if (
    !isset($_SESSION["staff_csrf_token"]) ||
    !is_string($_SESSION["staff_csrf_token"])
) {
    $_SESSION["staff_csrf_token"] =
        bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["staff_csrf_token"];

$workOrderReference = strtoupper(
    trim(
        (string) ($_GET["work_order"] ?? "")
    )
);

$linkedWorkOrder = null;

if ($workOrderReference !== "") {
    $staffId = (int) $_SESSION["staff_id"];
    $propertyId = staffFormPropertyId($conn, $staffId);
    if ($propertyId < 1) {
        http_response_code(403);
        exit("Akaun staff tidak mempunyai property_id yang sah.");
    }
    $propertyFilter = staffFormColumnExists($conn, "work_orders", "property_id")
        ? " AND property_id = ?"
        : "";

    $workOrderStmt = $conn->prepare(
        "
        SELECT
            id,
            work_order_reference,
            title,
            description,
            category,
            block_location,
            specific_location,
            priority,
            scheduled_date,
            due_date,
            status
        FROM work_orders
        WHERE work_order_reference = ?
          AND assigned_staff_id = ?
          {$propertyFilter}
          AND status NOT IN ('Verified', 'Cancelled')
        LIMIT 1
        "
    );

    if ($workOrderStmt) {
        if ($propertyFilter !== "") {
            $workOrderStmt->bind_param(
                "sii",
                $workOrderReference,
                $staffId,
                $propertyId
            );
        } else {
            $workOrderStmt->bind_param(
                "si",
                $workOrderReference,
                $staffId
            );
        }

        $workOrderStmt->execute();

        $linkedWorkOrder =
            $workOrderStmt
                ->get_result()
                ->fetch_assoc();

        $workOrderStmt->close();
    }
}

$conn->close();

$categories = [
    "Maintenance",
    "Cleaning",
    "Security",
    "Landscape",
    "Plumbing",
    "Electrical",
    "Civil Work",
    "Pest Control",
    "Fire Safety",
    "Water Tank",
    "Playground",
    "Boom Gate",
    "Solar CCTV",
    "Guard House",
    "Drain Cleaning",
    "Other"
];

$locations = [
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

$statuses = [
    "In Progress",
    "Completed",
    "Pending Material",
    "Pending Contractor",
    "Unable to Complete"
];

?>

<!DOCTYPE html>
<html lang="ms">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Tambah Kerja Harian | V23 PMS</title>
    <link rel="stylesheet" href="css/pms.css?v=3">
    <link rel="stylesheet" href="css/pms_daily_work.css?v=1">
	<?php echo cpmsStaffPwaHead($staffPwaBranding); ?>
	<?php echo cpmsStaffPwaStyle($staffPwaBranding); ?>
	<link rel="stylesheet" href="css/workforce_portal_shell.css?v=394">
	    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head>

<body class="pms-body">

<div class="pms-shell">

    <aside class="pms-sidebar">

        <div class="pms-brand">
	            <img
	                src="<?php echo e((string) $staffPwaBranding['logo_path']); ?>"
	                alt="<?php echo e((string) $staffPwaBranding['property_name']); ?>"
	            >

            <div>
	                <strong><?php echo e((string) $staffPwaBranding['property_name']); ?></strong>
                <span>Staff Portal</span>
            </div>
        </div>

        <nav class="pms-nav">
            <a href="staff_dashboard.php">Dashboard</a>
            <a
                href="staff_work_form.php"
                class="active"
            >
                Add Daily Work
            </a>
            <a href="staff_work_history.php">
                My Work History
            </a>
        </nav>

        <div class="pms-sidebar-footer">
            <a href="staff_logout.php">
                Logout Staff
            </a>
        </div>

    </aside>

    <main class="pms-main">

        <header class="pms-topbar">
            <div>
                <h1>Tambah Kerja Harian</h1>
                <p>
                    Rekod kerja dan gambar Before, During serta After.
                </p>
            </div>

            <div class="pms-user-chip">
                <?php
                echo e(
                    (string) $_SESSION["staff_name"]
                );
                ?>
            </div>
        </header>

        <section class="pms-card">

            <form
                action="staff_work_submit.php"
                method="POST"
                enctype="multipart/form-data"
                class="pms-form-grid"
                id="daily-work-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($csrfToken); ?>"
                >

                <input
                    type="hidden"
                    name="work_order_id"
                    value="<?php
                        echo (int) (
                            $linkedWorkOrder["id"] ?? 0
                        );
                    ?>"
                >

                <?php if ($linkedWorkOrder !== null): ?>

                    <div
                        class="pms-alert-success
                        pms-form-group-full"
                    >
                        <strong>
                            Work Order:
                            <?php
                            echo e(
                                (string)
                                $linkedWorkOrder[
                                    "work_order_reference"
                                ]
                            );
                            ?>
                        </strong>

                        <br>

                        <?php
                        echo e(
                            (string)
                            $linkedWorkOrder["title"]
                        );
                        ?>

                        <br>

                        <small>
                            Rekod kerja ini akan dihubungkan
                            secara automatik kepada Work Order.
                        </small>
                    </div>

                <?php elseif ($workOrderReference !== ""): ?>

                    <div
                        class="pms-alert-error
                        pms-form-group-full"
                    >
                        Work Order tidak dijumpai, tidak diberikan
                        kepada anda, atau sudah ditutup.
                    </div>

                <?php endif; ?>

                <div class="pms-form-group">

                    <label for="work_date">
                        Tarikh Kerja *
                    </label>

                    <input
                        type="date"
                        id="work_date"
                        name="work_date"
                        value="<?php echo date("Y-m-d"); ?>"
                        max="<?php echo date("Y-m-d"); ?>"
                        required
                    >

                </div>

                <div class="pms-form-group">

                    <label for="work_category">
                        Kategori Kerja *
                    </label>

                    <select
                        id="work_category"
                        name="work_category"
                        required
                    >
                        <option value="">
                            -- Sila Pilih --
                        </option>

                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?php echo e($category); ?>"
                                <?php
                                echo
                                    (
                                        $linkedWorkOrder !== null &&
                                        $linkedWorkOrder[
                                            "category"
                                        ] === $category
                                    )
                                        ? "selected"
                                        : "";
                                ?>
                            >
                                <?php echo e($category); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                </div>

                <div class="pms-form-group">

                    <label for="block_location">
                        Kawasan / Blok *
                    </label>

                    <select
                        id="block_location"
                        name="block_location"
                        required
                    >
                        <option value="">
                            -- Sila Pilih --
                        </option>

                        <?php foreach ($locations as $location): ?>
                            <option
                                value="<?php echo e($location); ?>"
                                <?php
                                echo
                                    (
                                        $linkedWorkOrder !== null &&
                                        $linkedWorkOrder[
                                            "block_location"
                                        ] === $location
                                    )
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
                        placeholder="Contoh: Koridor tingkat 2"
                        value="<?php
                            echo e(
                                (string) (
                                    $linkedWorkOrder[
                                        "specific_location"
                                    ] ?? ""
                                )
                            );
                        ?>"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="start_time">
                        Masa Mula
                    </label>

                    <input
                        type="time"
                        id="start_time"
                        name="start_time"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="end_time">
                        Masa Tamat
                    </label>

                    <input
                        type="time"
                        id="end_time"
                        name="end_time"
                    >

                </div>

                <div class="pms-form-group">

                    <label for="work_status">
                        Status Kerja *
                    </label>

                    <select
                        id="work_status"
                        name="work_status"
                        required
                    >
                        <?php foreach ($statuses as $status): ?>
                            <option
                                value="<?php echo e($status); ?>"
                                <?php
                                echo $status === "Completed"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                <?php echo e($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                </div>

                <div class="pms-form-group">

                    <label for="include_in_newsletter">
                        Newsletter
                    </label>

                    <select
                        id="include_in_newsletter"
                        name="include_in_newsletter"
                    >
                        <option value="0">
                            Tidak
                        </option>
                        <option value="1">
                            Cadangkan untuk Newsletter
                        </option>
                    </select>

                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >

                    <label for="work_description">
                        Kerja Yang Dilakukan *
                    </label>

                    <textarea
                        id="work_description"
                        name="work_description"
                        maxlength="3000"
                        placeholder="Terangkan kerja yang telah dilakukan..."
                        required
                    ><?php
                        if ($linkedWorkOrder !== null) {
                            echo e(
                                "Work Order: " .
                                (string)
                                $linkedWorkOrder["title"] .
                                "\n\nArahan kerja:\n" .
                                (string)
                                $linkedWorkOrder["description"] .
                                "\n\nKerja yang telah dilakukan:\n"
                            );
                        }
                    ?></textarea>

                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >

                    <label for="materials_used">
                        Bahan / Alat Digunakan
                    </label>

                    <textarea
                        id="materials_used"
                        name="materials_used"
                        maxlength="2000"
                        placeholder="Contoh: LED bulb, cable tie, cleaning chemical"
                    ></textarea>

                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >

                    <label for="issue_notes">
                        Masalah / Tindakan Susulan
                    </label>

                    <textarea
                        id="issue_notes"
                        name="issue_notes"
                        maxlength="2000"
                        placeholder="Catat jika kerja belum selesai atau memerlukan contractor/material"
                    ></textarea>

                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >

                    <div class="daily-work-type-block">

                        <h3>Gambar Before</h3>

                        <p>
                            Maksimum 3 gambar. Sistem akan kecilkan gambar
                            telefon sebelum upload.
                        </p>

                        <input
                            type="file"
                            name="before_images[]"
                            id="before_images"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                        >

                        <div
                            id="before-preview"
                            class="daily-work-preview-grid"
                        ></div>
                        <small id="before-file-count"></small>

                    </div>

                    <div class="daily-work-type-block">

                        <h3>Gambar During</h3>

                        <p>
                            Maksimum 3 gambar. Sistem akan kecilkan gambar
                            telefon sebelum upload.
                        </p>

                        <input
                            type="file"
                            name="during_images[]"
                            id="during_images"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                        >

                        <div
                            id="during-preview"
                            class="daily-work-preview-grid"
                        ></div>
                        <small id="during-file-count"></small>

                    </div>

                    <div class="daily-work-type-block">

                        <h3>Gambar After *</h3>

                        <p>
                            Sekurang-kurangnya satu gambar After diperlukan
                            apabila status dipilih sebagai Completed.
                        </p>

                        <input
                            type="file"
                            name="after_images[]"
                            id="after_images"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                        >

                        <div
                            id="after-preview"
                            class="daily-work-preview-grid"
                        ></div>
                        <small id="after-file-count"></small>

                    </div>

                </div>

                <div
                    class="pms-form-group
                    pms-form-group-full"
                >

                    <button
                        type="submit"
                        class="pms-button"
                    >
                        Simpan Rekod Kerja
                    </button>

                </div>

            </form>

        </section>

    </main>

</div>

<script>
const CPMS_MAX_UPLOAD_SIZE = 12 * 1024 * 1024;
const CPMS_COMPRESS_LIMIT = 1600;
const CPMS_COMPRESS_QUALITY = 0.82;

async function compressImageFile(file) {
    if (!file.type || !file.type.startsWith("image/")) {
        return file;
    }

    if (file.type === "image/gif") {
        return file;
    }

    if (
        typeof createImageBitmap !== "function" ||
        typeof File !== "function"
    ) {
        return file;
    }

    let bitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch (error) {
        return file;
    }

    const scale = Math.min(
        1,
        CPMS_COMPRESS_LIMIT / Math.max(bitmap.width, bitmap.height)
    );
    const width = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));
    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext("2d");

    if (!context || typeof canvas.toBlob !== "function") {
        return file;
    }

    context.drawImage(bitmap, 0, 0, width, height);

    const blob = await new Promise((resolve) => {
        canvas.toBlob(resolve, "image/jpeg", CPMS_COMPRESS_QUALITY);
    });

    if (!blob || blob.size >= file.size) {
        return file;
    }

    const baseName = file.name.replace(/\.[^.]+$/, "");
    return new File([blob], baseName + ".jpg", {
        type: "image/jpeg",
        lastModified: Date.now()
    });
}

async function compressInputFiles(input) {
    const files = Array.from(input.files);

    if (files.length === 0) {
        return;
    }

    if (typeof DataTransfer !== "function") {
        return;
    }

    const transfer = new DataTransfer();

    for (const file of files) {
        const compressed = await compressImageFile(file);
        transfer.items.add(compressed);
    }

    input.files = transfer.files;
}

function setupImagePreview(inputId, previewId, label, countId) {
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    const counter = document.getElementById(countId);

    input.addEventListener("change", async () => {
        preview.innerHTML = "";
        if (counter) {
            counter.textContent = "";
        }

        if (input.files.length > 3) {
            alert(label + ": maksimum 3 gambar.");
            input.value = "";
            return;
        }

        try {
            await compressInputFiles(input);
        } catch (error) {
            console.warn("CPMS image compression skipped.", error);
        }

        const files = Array.from(input.files);

        if (counter) {
            counter.textContent = files.length > 0
                ? files.length + " gambar dipilih"
                : "";
        }

        for (const file of files) {
            if (file.size > CPMS_MAX_UPLOAD_SIZE) {
                alert(file.name + " melebihi 12MB walaupun selepas dikecilkan.");
                input.value = "";
                preview.innerHTML = "";
                return;
            }

            const card = document.createElement("div");
            card.className = "daily-work-preview-card";

            const image = document.createElement("img");
            image.alt = file.name;

            const caption = document.createElement("span");
            caption.textContent = label + " - " + file.name;

            const reader = new FileReader();

            reader.addEventListener("load", () => {
                image.src = reader.result;
            });

            reader.readAsDataURL(file);

            card.appendChild(image);
            card.appendChild(caption);
            preview.appendChild(card);
        }
    });
}

setupImagePreview(
    "before_images",
    "before-preview",
    "Before",
    "before-file-count"
);

setupImagePreview(
    "during_images",
    "during-preview",
    "During",
    "during-file-count"
);

setupImagePreview(
    "after_images",
    "after-preview",
    "After",
    "after-file-count"
);

document
    .getElementById("daily-work-form")
    .addEventListener("submit", async (event) => {
        const status =
            document.getElementById("work_status").value;

        const afterCount =
            document.getElementById("after_images")
                .files.length;

        if (
            status === "Completed" &&
            afterCount === 0
        ) {
            event.preventDefault();

            alert(
                "Sekurang-kurangnya satu gambar After " +
                "diperlukan untuk kerja Completed."
            );
            return;
        }

        const button = event.target.querySelector("button[type='submit']");
        if (button) {
            button.disabled = true;
            button.textContent = "Sedang upload...";
        }
    });
</script>

<?php echo cpmsStaffPwaScripts(); ?>
</body>
</html>
