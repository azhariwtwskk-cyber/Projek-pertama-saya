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
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

function currentBaseUrl(): string
{
    $https =
        isset($_SERVER["HTTPS"]) &&
        $_SERVER["HTTPS"] !== "" &&
        strtolower((string) $_SERVER["HTTPS"]) !== "off";

    $scheme =
        $https
            ? "https"
            : "http";

    $host =
        (string) (
            $_SERVER["HTTP_HOST"] ??
            ""
        );

    if ($host === "") {
        return "";
    }

    return
        $scheme .
        "://" .
        $host;
}

$isEnglish =
    $cpmsLanguage === "en";

/*
|--------------------------------------------------------------------------
| Language switcher
|--------------------------------------------------------------------------
*/

$languageQuery = $_GET;
$languageQuery["lang"] = "ms";

$bmUrl =
    basename(
        (string) $_SERVER["PHP_SELF"]
    ) .
    "?" .
    http_build_query($languageQuery);

$languageQuery["lang"] = "en";

$enUrl =
    basename(
        (string) $_SERVER["PHP_SELF"]
    ) .
    "?" .
    http_build_query($languageQuery);

/*
|--------------------------------------------------------------------------
| Ambil nombor rujukan
|--------------------------------------------------------------------------
*/

$reference = strtoupper(
    trim(
        (string) (
            $_GET["ref"] ??
            ""
        )
    )
);

if (
    $reference === "" ||
    !preg_match(
        '/^[A-Z0-9][A-Z0-9_-]{4,39}$/',
        $reference
    )
) {
    header(
        "Location: complaint_form.php"
    );

    exit();
}

/*
|--------------------------------------------------------------------------
| Ambil maklumat aduan
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        complaint_id,
        name,
        phone,
        email,
        block,
        unit_no,
        category,
        subject,
        priority,
        status
    FROM complaints
    WHERE complaint_id = ?
      AND property_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    exit(
        $isEnglish
            ? "The system cannot display the complaint information."
            : "Sistem tidak dapat memaparkan maklumat aduan."
    );
}

$stmt->bind_param(
    "si",
    $reference,
    $propertyId
);

$stmt->execute();

$complaint =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();
$conn->close();

if (!$complaint) {
    http_response_code(404);

    exit(
        $isEnglish
            ? "The complaint reference number was not found."
            : "Nombor rujukan aduan tidak dijumpai."
    );
}

/*
|--------------------------------------------------------------------------
| Dynamic tracking URL
|--------------------------------------------------------------------------
*/

$baseUrl =
    currentBaseUrl();

$trackingPath =
    "/track_complaint.php?ref=" .
    rawurlencode($reference);

$trackingUrl =
    $baseUrl !== ""
        ? $baseUrl . $trackingPath
        : "track_complaint.php?ref=" .
            rawurlencode($reference);

/*
|--------------------------------------------------------------------------
| Dynamic QR image
|--------------------------------------------------------------------------
*/

$qrImageUrl =
    "https://quickchart.io/qr" .
    "?text=" .
    rawurlencode($trackingUrl) .
    "&size=280" .
    "&margin=2" .
    "&ecLevel=Q";

/*
|--------------------------------------------------------------------------
| Dynamic WhatsApp message
|--------------------------------------------------------------------------
*/

$whatsappMessage =
    $isEnglish
        ? (
            "Your complaint for " .
            cpmsPropertyName() .
            " was submitted successfully.\n\n" .
            "Reference number: " .
            $reference .
            "\n" .
            "Track status: " .
            $trackingUrl
        )
        : (
            "Aduan untuk " .
            cpmsPropertyName() .
            " berjaya dihantar.\n\n" .
            "Nombor rujukan: " .
            $reference .
            "\n" .
            "Semak status: " .
            $trackingUrl
        );

$whatsappUrl =
    "https://wa.me/?text=" .
    rawurlencode(
        $whatsappMessage
    );

?>

<!DOCTYPE html>
<html lang="<?php echo e($cpmsLanguage); ?>">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="<?php
            echo e(
                (
                    $isEnglish
                        ? "Complaint submission confirmation for "
                        : "Pengesahan penghantaran aduan untuk "
                ) .
                cpmsPropertyName()
            );
        ?>"
    >

    <title>
        <?php
        echo e(
            (
                $isEnglish
                    ? "Complaint Submitted"
                    : "Aduan Berjaya"
            ) .
            " | " .
            cpmsPropertyName()
        );
        ?>
    </title>

    <link
        rel="stylesheet"
        href="css/style.css?v=41"
    >
    <link
        rel="stylesheet"
        href="css/cpms-public-complaints.css?v=110"
    >

    <?php echo cpmsThemeStyleTag(); ?>

    <style>
        .success-page {
            min-height: 100vh;
            margin: 0;
            padding: 30px 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f4f1eb;
            font-family: Arial, sans-serif;
            color: #2b2b2b;
        }

        .success-card {
            width: 100%;
            max-width: 760px;
            padding: 40px;
            border: 1px solid rgba(181, 155, 32, 0.2);
            border-top: 6px solid var(--cpms-secondary);
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 15px 45px rgba(0, 0, 0, 0.12);
            text-align: center;
        }

        .success-language-switcher {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 15px;
        }

        .success-language-switcher a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            padding: 8px 11px;
            border: 1px solid var(--cpms-primary);
            border-radius: 7px;
            color: var(--cpms-primary);
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }

        .success-language-switcher a.active,
        .success-language-switcher a:hover {
            background: var(--cpms-primary);
            color: #ffffff;
        }

        .success-logo {
            width: 100%;
            max-width: 240px;
            max-height: 140px;
            object-fit: contain;
            margin: 0 auto 20px;
        }

        .success-property-name {
            margin: 0 0 18px;
            color: var(--cpms-primary);
            font-size: 15px;
            font-weight: 800;
        }

        .success-check {
            width: 72px;
            height: 72px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #28a745;
            color: #ffffff;
            font-size: 38px;
            font-weight: bold;
        }

        .success-card h1 {
            margin: 0 0 12px;
            color: var(--cpms-primary);
            font-size: 32px;
        }

        .success-intro {
            max-width: 600px;
            margin: 0 auto 28px;
            color: #666666;
            line-height: 1.7;
        }

        .success-reference-box {
            margin: 0 auto 30px;
            padding: 22px;
            border: 1px solid var(--cpms-secondary);
            border-radius: 14px;
            background: #fff9e6;
        }

        .success-reference-label {
            display: block;
            margin-bottom: 8px;
            color: var(--cpms-primary);
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .success-reference-number {
            display: block;
            color: var(--cpms-primary);
            font-size: clamp(24px, 6vw, 36px);
            font-weight: 800;
            letter-spacing: 1px;
            word-break: break-word;
        }

        .success-details {
            margin-bottom: 30px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            text-align: left;
        }

        .success-detail-row {
            display: grid;
            grid-template-columns: 190px 1fr;
            gap: 18px;
            padding: 14px 18px;
            border-bottom: 1px solid #eeeeee;
        }

        .success-detail-row:last-child {
            border-bottom: none;
        }

        .success-detail-row strong {
            color: var(--cpms-primary);
        }

        .qr-section {
            margin: 32px auto;
            padding: 28px 20px;
            border: 1px solid #e7dfcf;
            border-radius: 16px;
            background: #faf8f4;
        }

        .qr-section h2 {
            margin: 0 0 10px;
            color: var(--cpms-primary);
            font-size: 24px;
        }

        .qr-section p {
            max-width: 540px;
            margin: 0 auto 18px;
            color: #666666;
            line-height: 1.6;
        }

        .qr-image {
            display: block;
            width: 280px;
            max-width: 100%;
            height: auto;
            margin: 0 auto;
            padding: 10px;
            border: 1px solid #dddddd;
            border-radius: 12px;
            background: #ffffff;
        }

        .success-actions {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        .success-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 190px;
            padding: 14px 20px;
            border-radius: 9px;
            text-decoration: none;
            font-weight: 800;
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .success-action:hover {
            transform: translateY(-2px);
        }

        .action-track {
            background: var(--cpms-secondary);
            color: #ffffff;
            box-shadow: 0 8px 20px rgba(143, 107, 47, 0.25);
        }

        .action-whatsapp {
            background: #25d366;
            color: #ffffff;
        }

        .action-new {
            background: var(--cpms-primary);
            color: #ffffff;
        }

        .action-home {
            background: #333333;
            color: #ffffff;
        }

        .success-note {
            margin-top: 25px;
            padding: 15px;
            border-radius: 9px;
            background: #eef7ff;
            color: #365a73;
            font-size: 14px;
            line-height: 1.6;
        }

        .cpms-success-footer {
            margin-top: 28px;
            padding-top: 15px;
            border-top: 1px solid #dddddd;
            color: #777777;
            font-size: 12px;
            line-height: 1.6;
        }

        @media screen and (max-width: 600px) {
            .success-page {
                padding: 18px 12px;
            }

            .success-card {
                padding: 28px 18px;
                border-radius: 15px;
            }

            .success-logo {
                max-width: 190px;
            }

            .success-card h1 {
                font-size: 27px;
            }

            .success-detail-row {
                grid-template-columns: 1fr;
                gap: 5px;
            }

            .success-actions {
                flex-direction: column;
            }

            .success-action {
                width: 100%;
                min-width: 0;
            }

            .qr-image {
                width: 240px;
            }
        }
    </style>

    <link
        rel="stylesheet"
        href="css/cpms-public-complaints.css?v=110"
    >
</head>

<body class="success-page">

    <main class="success-card">

        <div class="success-language-switcher">

            <a
                href="<?php echo e($bmUrl); ?>"
                class="<?php
                    echo
                        $cpmsLanguage === "ms"
                            ? "active"
                            : "";
                ?>"
            >
                BM
            </a>

            <a
                href="<?php echo e($enUrl); ?>"
                class="<?php
                    echo
                        $cpmsLanguage === "en"
                            ? "active"
                            : "";
                ?>"
            >
                EN
            </a>

        </div>

        <a href="index.php">

            <img
                src="<?php echo e(cpmsLogo()); ?>"
                alt="<?php
                    echo e(
                        cpmsPropertyName() .
                        " Logo"
                    );
                ?>"
                class="success-logo"
                onerror="this.style.display='none';"
            >

        </a>

        <p class="success-property-name">
            <?php echo e(cpmsPropertyName()); ?>
        </p>

        <div class="success-check">
            ✓
        </div>

        <h1>
            <?php
            echo
                $isEnglish
                    ? "Complaint Submitted Successfully"
                    : "Aduan Berjaya Dihantar";
            ?>
        </h1>

        <p class="success-intro">
            <?php
            echo
                $isEnglish
                    ? "Thank you. Your complaint has been received by the system. Please save the reference number or scan the QR code to track its progress."
                    : "Terima kasih. Aduan anda telah diterima oleh sistem. Sila simpan nombor rujukan atau imbas kod QR untuk menyemak perkembangan aduan.";
            ?>
        </p>

        <section class="success-reference-box">

            <span class="success-reference-label">
                <?php
                echo
                    $isEnglish
                        ? "Complaint Reference Number"
                        : "Nombor Rujukan Aduan";
                ?>
            </span>

            <strong class="success-reference-number">
                <?php echo e($reference); ?>
            </strong>

        </section>

        <section class="success-details">

            <div class="success-detail-row">

                <strong>
                    <?php
                    echo
                        $isEnglish
                            ? "Complainant Name"
                            : "Nama Pengadu";
                    ?>
                </strong>

                <span>
                    <?php
                    echo e(
                        (string) (
                            $complaint["name"] ??
                            "-"
                        )
                    );
                    ?>
                </span>

            </div>

            <div class="success-detail-row">

                <strong>
                    <?php
                    echo
                        $isEnglish
                            ? "Block and Unit"
                            : "Blok dan Unit";
                    ?>
                </strong>

                <span>
                    <?php
                    echo
                        $isEnglish
                            ? "Block "
                            : "Blok ";

                    echo e(
                        (string) (
                            $complaint["block"] ??
                            "-"
                        )
                    );

                    echo
                        $isEnglish
                            ? ", Unit "
                            : ", Unit ";

                    echo e(
                        (string) (
                            $complaint["unit_no"] ??
                            "-"
                        )
                    );
                    ?>
                </span>

            </div>

            <div class="success-detail-row">

                <strong>
                    <?php
                    echo
                        $isEnglish
                            ? "Category"
                            : "Kategori";
                    ?>
                </strong>

                <span>
                    <?php
                    echo e(
                        (string) (
                            $complaint["category"] ??
                            "-"
                        )
                    );
                    ?>
                </span>

            </div>

            <div class="success-detail-row">

                <strong>
                    <?php
                    echo
                        $isEnglish
                            ? "Complaint Subject"
                            : "Tajuk Aduan";
                    ?>
                </strong>

                <span>
                    <?php
                    echo e(
                        (string) (
                            $complaint["subject"] ??
                            "-"
                        )
                    );
                    ?>
                </span>

            </div>

            <div class="success-detail-row">

                <strong>
                    <?php
                    echo
                        $isEnglish
                            ? "Initial Status"
                            : "Status Awal";
                    ?>
                </strong>

                <span>
                    <?php
                    echo e(
                        (string) (
                            $complaint["status"] ??
                            "Pending"
                        )
                    );
                    ?>
                </span>

            </div>

        </section>

        <section class="qr-section">

            <h2>
                <?php
                echo
                    $isEnglish
                        ? "Scan to Track Status"
                        : "Imbas untuk Semak Status";
                ?>
            </h2>

            <p>
                <?php
                echo
                    $isEnglish
                        ? "Use your phone camera to scan this QR code. You will be taken directly to the complaint tracking page."
                        : "Gunakan kamera telefon untuk mengimbas kod QR ini. Anda akan dibawa terus ke halaman semakan aduan.";
                ?>
            </p>

            <a
                href="<?php echo e($trackingUrl); ?>"
                target="_blank"
                rel="noopener noreferrer"
            >

                <img
                    src="<?php echo e($qrImageUrl); ?>"
                    alt="<?php
                        echo e(
                            (
                                $isEnglish
                                    ? "Complaint tracking QR code "
                                    : "Kod QR semakan aduan "
                            ) .
                            $reference
                        );
                    ?>"
                    class="qr-image"
                >

            </a>

        </section>

        <div class="success-actions">

            <a
                href="<?php echo e($trackingUrl); ?>"
                class="success-action action-track"
            >
                <?php
                echo
                    $isEnglish
                        ? "Track Complaint"
                        : "Semak Status Aduan";
                ?>
            </a>

            <a
                href="<?php echo e($whatsappUrl); ?>"
                class="success-action action-whatsapp"
                target="_blank"
                rel="noopener noreferrer"
            >
                <?php
                echo
                    $isEnglish
                        ? "Save via WhatsApp"
                        : "Simpan melalui WhatsApp";
                ?>
            </a>

            <a
                href="complaint_form.php"
                class="success-action action-new"
            >
                <?php
                echo
                    $isEnglish
                        ? "Submit Another Complaint"
                        : "Hantar Aduan Lain";
                ?>
            </a>

            <a
                href="index.php"
                class="success-action action-home"
            >
                <?php
                echo
                    $isEnglish
                        ? "Home"
                        : "Halaman Utama";
                ?>
            </a>

        </div>

        <div class="success-note">
            <?php
            echo
                $isEnglish
                    ? "Keep this reference number safely. The management may require it for further verification."
                    : "Simpan nombor rujukan ini dengan selamat. Pihak pengurusan mungkin memerlukannya untuk membuat semakan lanjut.";
            ?>
        </div>

        <footer class="cpms-success-footer">
            <strong>
                <?php echo e(cpmsPropertyName()); ?>
            </strong>
            <br>
            <?php echo e(cpmsFooter()); ?>
        </footer>

    </main>

</body>

</html>
