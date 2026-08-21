<?php

declare(strict_types=1);

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";
require_once __DIR__ . "/cpms/includes/property_context.php";
require_once __DIR__ . "/cpms/includes/property_guard.php";

$propertyId = cpmsRequireCurrentPropertyId($conn);
$currentProperty = cpmsCurrentProperty($conn);

if (!$currentProperty) {
    http_response_code(503);
    exit("Tiada property aktif dipilih.");
}

/*
|--------------------------------------------------------------------------
| Gunakan branding property semasa
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


function e(?string $value): string
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function normalizePhone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? "";
}

function getStatusClass(string $status): string
{
    return match (strtolower(trim($status))) {
        "pending" => "status-pending",
        "in progress" => "status-progress",
        "resolved" => "status-resolved",
        "closed" => "status-closed",
        default => "status-pending"
    };
}

$isEnglish = $cpmsLanguage === "en";

$languageQuery = $_GET;
$languageQuery["lang"] = "ms";
$bmUrl = basename((string) $_SERVER["PHP_SELF"]) . "?" . http_build_query($languageQuery);

$languageQuery["lang"] = "en";
$enUrl = basename((string) $_SERVER["PHP_SELF"]) . "?" . http_build_query($languageQuery);

function resolveComplaintImageUrl(string $imageName): string
{
    $safeName = basename($imageName);
    $candidates = [
        "uploads/" . $safeName,
        "uploads/complaints/" . $safeName
    ];

    foreach ($candidates as $relativePath) {
        if (is_file(__DIR__ . "/" . $relativePath)) {
            return $relativePath;
        }
    }

    return "uploads/" . $safeName;
}

$reference = strtoupper(
    trim((string) ($_POST["reference"] ?? $_GET["ref"] ?? ""))
);

$phoneInput = trim((string) ($_POST["phone"] ?? ""));
$complaint = null;
$errorMessage = "";
$images = [];
$verificationAttempted = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $verificationAttempted = true;

    if ($reference === "" || $phoneInput === "") {
        $errorMessage = $isEnglish ? "Please enter the complaint reference number and phone number." : "Sila masukkan nombor rujukan dan nombor telefon.";
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9_-]{4,39}$/', $reference)) {
        $errorMessage = $isEnglish ? "The verification information is invalid or does not match." : "Maklumat pengesahan tidak sah atau tidak sepadan.";
    } else {
        $stmt = $conn->prepare(
            "SELECT * FROM complaints WHERE complaint_id = ? LIMIT 1"
        );

        if (!$stmt) {
            $errorMessage = $isEnglish ? "The system cannot perform verification at this time." : "Sistem tidak dapat membuat semakan pada masa ini.";
        } else {
            $stmt->bind_param("s", $reference);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result->fetch_assoc();
            $stmt->close();

            $storedPhone = normalizePhone((string) ($record["phone"] ?? ""));
            $submittedPhone = normalizePhone($phoneInput);

            if (
                !$record ||
                $storedPhone === "" ||
                $submittedPhone === "" ||
                !hash_equals($storedPhone, $submittedPhone)
            ) {
                $errorMessage = $isEnglish ? "The verification information is invalid or does not match." : "Maklumat pengesahan tidak sah atau tidak sepadan.";
            } else {
                $complaint = $record;
            }
        }

        if ($complaint !== null) {
            $imageStmt = $conn->prepare(
                "SELECT image_name
                 FROM complaint_images
                 WHERE complaint_id = ?
                 ORDER BY id ASC"
            );

            if ($imageStmt) {
                $imageStmt->bind_param("s", $reference);
                $imageStmt->execute();
                $imageResult = $imageStmt->get_result();

                while ($imageRow = $imageResult->fetch_assoc()) {
                    $images[] = (string) $imageRow["image_name"];
                }

                $imageStmt->close();
            }
        }

        if (
            $complaint !== null &&
            count($images) === 0 &&
            !empty($complaint["image"])
        ) {
            $images[] = (string) $complaint["image"];
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo e($cpmsLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo e(($isEnglish ? "Track complaint status for " : "Semak status aduan untuk ") . cpmsPropertyName()); ?>">
    <title><?php echo e(($isEnglish ? "Track Complaint" : "Semak Status Aduan") . " | " . cpmsPropertyName()); ?></title>
    <link rel="stylesheet" href="css/style.css?v=81">
    <link rel="stylesheet" href="css/cpms-public-complaints.css?v=110">
    <?php echo cpmsThemeStyleTag(); ?>
    <style>
        .secure-track-note {
            margin: 0 0 25px;
            padding: 15px 18px;
            border: 1px solid #c9dff1;
            border-left: 5px solid var(--cpms-secondary);
            border-radius: 9px;
            background: #eef7ff;
            color: #365a73;
            font-size: 14px;
            line-height: 1.65;
        }

        .secure-track-form .form-group {
            margin-top: 20px;
        }

        .secure-track-form input {
            width: 100%;
            min-height: 48px;
            padding: 12px 14px;
            border: 1px solid #cccccc;
            border-radius: 8px;
            font-size: 15px;
        }

        .secure-track-form input:focus {
            outline: none;
            border-color: var(--cpms-secondary);
            box-shadow: 0 0 0 3px rgba(181, 155, 32, 0.15);
        }

        .privacy-notice {
            margin-top: 25px;
            padding: 14px 16px;
            border-radius: 9px;
            background: #f7f7f7;
            color: #666666;
            font-size: 13px;
            line-height: 1.6;
            text-align: center;
        }

        .track-language-switcher{display:flex;justify-content:flex-end;gap:8px;margin-bottom:14px}
        .track-language-switcher a{display:inline-flex;align-items:center;justify-content:center;min-width:42px;padding:8px 11px;border:1px solid var(--cpms-primary);border-radius:7px;color:var(--cpms-primary);text-decoration:none;font-size:12px;font-weight:800}
        .track-language-switcher a.active,.track-language-switcher a:hover{background:var(--cpms-primary);color:#fff}
        .secure-track-form button{background:var(--cpms-secondary)}
        .container > h1,.masked-result-heading{color:var(--cpms-primary)}
        .cpms-track-footer{margin-top:28px;padding-top:14px;border-top:1px solid #ddd;color:#777;font-size:12px;text-align:center}

        .masked-result-heading {
            margin-top: 30px;
            color: #3a2419;
            text-align: center;
        }
    </style>
    <link rel="stylesheet" href="css/cpms-public-complaints.css?v=110">
</head>
<body>
    <div class="container">
        <div class="track-language-switcher">
            <a href="<?php echo e($bmUrl); ?>" class="<?php echo $cpmsLanguage === "ms" ? "active" : ""; ?>">BM</a>
            <a href="<?php echo e($enUrl); ?>" class="<?php echo $cpmsLanguage === "en" ? "active" : ""; ?>">EN</a>
        </div>

        <div class="logo">
            <a href="index.php">
                <img
                    src="<?php echo e(cpmsLogo()); ?>"
                    alt="<?php echo e(cpmsPropertyName() . " Logo"); ?>"
                    onerror="this.style.display='none';this.parentElement.classList.add('is-logo-missing');"
                >
            </a>
        </div>

        <h1>
            <?php echo $isEnglish ? "TRACK COMPLAINT" : "SEMAK STATUS ADUAN"; ?>
            <br>
            <small><?php echo $isEnglish ? "SEMAK STATUS ADUAN" : "TRACK COMPLAINT"; ?></small>
        </h1>

        <div class="secure-track-note">
            <?php if ($isEnglish): ?>
                To protect resident information, please enter the
                <strong>complaint reference number</strong> and the
                <strong>phone number used when submitting the complaint</strong>.
            <?php else: ?>
                Untuk melindungi maklumat penghuni, sila masukkan
                <strong>nombor rujukan aduan</strong> dan
                <strong>nombor telefon yang digunakan semasa menghantar aduan</strong>.
            <?php endif; ?>
        </div>

        <form action="<?php echo e(basename((string) $_SERVER['PHP_SELF']) . '?' . http_build_query($_GET)); ?>" method="POST" class="track-form secure-track-form" autocomplete="off">
            <div class="form-group">
                <label for="reference"><?php echo $isEnglish ? "Complaint Reference Number" : "Nombor Rujukan Aduan"; ?></label>
                <input
                    type="text"
                    id="reference"
                    name="reference"
                    value="<?php echo e($reference); ?>"
                    placeholder="<?php echo $isEnglish ? "Example: V23-260714-0001" : "Contoh: V23-260714-0001"; ?>"
                    maxlength="30"
                    required
                >
            </div>

            <div class="form-group">
                <label for="phone"><?php echo $isEnglish ? "Complainant Phone Number" : "Nombor Telefon Pengadu"; ?></label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    placeholder="<?php echo $isEnglish ? "Example: 012-3456789" : "Contoh: 012-3456789"; ?>"
                    maxlength="20"
                    inputmode="tel"
                    autocomplete="off"
                    required
                >
            </div>

            <button type="submit"><?php echo $isEnglish ? "CHECK STATUS" : "SEMAK STATUS"; ?><br><span><?php echo $isEnglish ? "SEMAK STATUS" : "CHECK STATUS"; ?></span></button>
        </form>

        <?php if ($verificationAttempted && $errorMessage !== ""): ?>
            <div class="error-message" role="alert">
                <?php echo e($errorMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($complaint !== null): ?>
            <?php
            $status = (string) ($complaint["status"] ?? "Pending");
            $remarks = (string) (
                $complaint["admin_remarks"] ??
                $complaint["remarks"] ??
                ""
            );
            ?>

            <h2 class="masked-result-heading"><?php echo $isEnglish ? "Complaint Status Information" : "Maklumat Status Aduan"; ?></h2>

            <div class="complaint-result">
                <div class="result-row">
                    <strong><?php echo $isEnglish ? "Reference Number" : "Nombor Rujukan"; ?></strong>
                    <span><?php echo e((string) $complaint["complaint_id"]); ?></span>
                </div>

                <div class="result-row">
                    <strong>Status</strong>
                    <span>
                        <span class="status-badge <?php echo e(getStatusClass($status)); ?>">
                            <?php echo e($status); ?>
                        </span>
                    </span>
                </div>

                <div class="result-row">
                    <strong><?php echo $isEnglish ? "Category" : "Kategori"; ?></strong>
                    <span><?php echo e((string) ($complaint["category"] ?? "-")); ?></span>
                </div>

                <div class="result-row">
                    <strong><?php echo $isEnglish ? "Complaint Subject" : "Tajuk Aduan"; ?></strong>
                    <span><?php echo e((string) ($complaint["subject"] ?? "-")); ?></span>
                </div>

                <div class="result-row">
                    <strong><?php echo $isEnglish ? "Description" : "Penerangan"; ?></strong>
                    <span><?php echo nl2br(e((string) ($complaint["description"] ?? "-"))); ?></span>
                </div>

                <div class="result-row">
                    <strong><?php echo $isEnglish ? "Priority Level" : "Tahap Keutamaan"; ?></strong>
                    <span><?php echo e((string) ($complaint["priority"] ?? "-")); ?></span>
                </div>

                <?php if ($remarks !== ""): ?>
                    <div class="result-row admin-remarks-row">
                        <strong><?php echo $isEnglish ? "Management Remarks" : "Catatan Pengurusan"; ?></strong>
                        <span><?php echo nl2br(e($remarks)); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (count($images) > 0): ?>
                <section class="multi-evidence-section">
                    <h2><?php echo $isEnglish ? "Complaint Evidence Images" : "Gambar Bukti Aduan"; ?></h2>
                    <p><?php echo count($images); ?> gambar tersedia.</p>

                    <div class="multi-evidence-grid">
                        <?php foreach ($images as $index => $imageName): ?>
                            <?php $imageUrl = resolveComplaintImageUrl((string)$imageName); ?>
                            <a
                                href="<?php echo e($imageUrl); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="multi-evidence-card"
                            >
                                <img
                                    src="<?php echo e($imageUrl); ?>"
                                    alt="Gambar bukti <?php echo $index + 1; ?>"
                                >
                                <span>Gambar <?php echo $index + 1; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="privacy-notice">
                <?php echo $isEnglish
                    ? "For privacy protection, this public page does not display the resident's full name, phone number, email address, unit number or detailed location."
                    : "Demi privasi, halaman awam ini tidak memaparkan nama penuh, nombor telefon, alamat e-mel, nombor unit atau lokasi terperinci penghuni."; ?>
            </div>
        <?php endif; ?>

        <div class="back-link">
            <a href="index.php">← <?php echo $isEnglish ? "Back to Home" : "Kembali ke Halaman Utama"; ?></a>
        </div>

        <footer class="cpms-track-footer">
            <?php echo e(cpmsFooter()); ?>
        </footer>
    </div>
</body>
</html>
