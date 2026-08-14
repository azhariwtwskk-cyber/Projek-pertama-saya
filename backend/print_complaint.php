<?php

declare(strict_types=1);

session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

require_once "db.php";

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

function firstValue(
    array $row,
    array $keys,
    string $default = "-"
): string {
    foreach ($keys as $key) {
        if (
            array_key_exists($key, $row) &&
            trim((string) $row[$key]) !== ""
        ) {
            return (string) $row[$key];
        }
    }

    return $default;
}

function formatDateTimeValue(?string $value): string
{
    $value = trim((string) $value);

    if ($value === "") {
        return "-";
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date("d/m/Y h:i A", $timestamp);
}

function resolveComplaintImageUrl(
    string $imageName
): string {
    $safeName = basename($imageName);

    $candidatePaths = [
        "uploads/complaints/" . $safeName,
        "uploads/" . $safeName
    ];

    foreach ($candidatePaths as $relativePath) {
        if (is_file(__DIR__ . "/" . $relativePath)) {
            return $relativePath;
        }
    }

    return "uploads/" . $safeName;
}

/*
|--------------------------------------------------------------------------
| Terima semua bentuk parameter
|--------------------------------------------------------------------------
| print_complaint.php?ref=V23-260715-0007
| print_complaint.php?complaint_id=V23-260715-0007
| print_complaint.php?id=15
*/
$requestValue = trim(
    (string) (
        $_GET["ref"] ??
        $_GET["complaint_id"] ??
        $_GET["id"] ??
        ""
    )
);

if ($requestValue === "") {
    http_response_code(400);
    exit("ID aduan tidak sah.");
}

$complaint = null;

/*
|--------------------------------------------------------------------------
| Carian utama menggunakan complaint_id
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare(
    "
    SELECT *
    FROM complaints
    WHERE complaint_id = ?
    LIMIT 1
    "
);

if ($stmt) {
    $stmt->bind_param(
        "s",
        $requestValue
    );

    $stmt->execute();

    $complaint =
        $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Fallback: jika parameter berbentuk nombor, cuba kolum id
|--------------------------------------------------------------------------
| Jika kolum id tidak wujud, prepare() akan gagal dan kod diteruskan
| tanpa menghasilkan HTTP 500.
*/
if (
    !$complaint &&
    ctype_digit($requestValue)
) {
    $numericId = (int) $requestValue;

    $idStmt = $conn->prepare(
        "
        SELECT *
        FROM complaints
        WHERE id = ?
        LIMIT 1
        "
    );

    if ($idStmt) {
        $idStmt->bind_param(
            "i",
            $numericId
        );

        $idStmt->execute();

        $complaint =
            $idStmt->get_result()->fetch_assoc();

        $idStmt->close();
    }
}

if (!$complaint) {
    $conn->close();

    http_response_code(404);

    exit(
        "Rekod aduan tidak dijumpai. " .
        "Sila pastikan aduan tersebut masih wujud."
    );
}

$complaintId = firstValue(
    $complaint,
    ["complaint_id"],
    $requestValue
);

/*
|--------------------------------------------------------------------------
| Ambil gambar daripada complaint_images
|--------------------------------------------------------------------------
| Tiada semakan information_schema atau SHOW TABLES.
| Jika jadual tidak wujud, prepare() akan gagal dan kod terus berjalan.
*/
$images = [];

$imageStmt = $conn->prepare(
    "
    SELECT image_name
    FROM complaint_images
    WHERE complaint_id = ?
    ORDER BY id ASC
    "
);

if ($imageStmt) {
    $imageStmt->bind_param(
        "s",
        $complaintId
    );

    $imageStmt->execute();

    $imageResult =
        $imageStmt->get_result();

    while (
        $image =
        $imageResult->fetch_assoc()
    ) {
        $images[] =
            (string) $image["image_name"];
    }

    $imageStmt->close();
}

/*
|--------------------------------------------------------------------------
| Fallback gambar daripada kolum image dalam complaints
|--------------------------------------------------------------------------
*/
if (
    count($images) === 0 &&
    !empty($complaint["image"])
) {
    $images[] =
        (string) $complaint["image"];
}

$conn->close();

/*
|--------------------------------------------------------------------------
| Pemetaan data
|--------------------------------------------------------------------------
*/
$residentName = firstValue(
    $complaint,
    [
        "resident_name",
        "name",
        "full_name",
        "complainant_name"
    ]
);

$phone = firstValue(
    $complaint,
    [
        "phone",
        "phone_number",
        "contact_no",
        "mobile_no"
    ]
);

$email = firstValue(
    $complaint,
    [
        "email",
        "resident_email"
    ]
);

$subject = firstValue(
    $complaint,
    [
        "subject",
        "title",
        "complaint_title"
    ]
);

$category = firstValue(
    $complaint,
    [
        "category",
        "complaint_category"
    ]
);

$description = firstValue(
    $complaint,
    [
        "description",
        "complaint_description",
        "details"
    ]
);

$block = firstValue(
    $complaint,
    [
        "block",
        "block_location"
    ]
);

$unitNo = firstValue(
    $complaint,
    [
        "unit_no",
        "unit",
        "house_no"
    ]
);

$location = firstValue(
    $complaint,
    [
        "location",
        "specific_location"
    ]
);

$priority = firstValue(
    $complaint,
    [
        "priority",
        "urgency"
    ],
    "Medium"
);

$status = firstValue(
    $complaint,
    [
        "status",
        "complaint_status"
    ],
    "Pending"
);

$createdAt = formatDateTimeValue(
    firstValue(
        $complaint,
        [
            "created_at",
            "submitted_at",
            "complaint_date"
        ],
        ""
    )
);

$updatedAt = formatDateTimeValue(
    firstValue(
        $complaint,
        [
            "updated_at",
            "last_updated"
        ],
        ""
    )
);

$adminRemarks = firstValue(
    $complaint,
    [
        "admin_remarks",
        "remarks",
        "management_remarks"
    ]
);

$residentStatus = firstValue(
    $complaint,
    [
        "resident_status"
    ]
);

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
        Cetak Aduan <?php echo e($complaintId); ?>
    </title>

    <style>
        :root {
            --brown: #3a2419;
            --gold: #b59b20;
            --cream: #f5f1e9;
            --border: #ddd5ca;
            --muted: #6d6d6d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: #2f2f2f;
            font-family: Arial, sans-serif;
        }

        .print-toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            max-width: 900px;
            margin: 20px auto 0;
            padding: 0 16px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            background: var(--gold);
            color: #ffffff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
        }

        .button-secondary {
            background: var(--brown);
        }

        .document {
            width: min(900px, calc(100% - 32px));
            margin: 16px auto 30px;
            padding: 34px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(47, 28, 20, .08);
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--gold);
        }

        .header img {
            width: 150px;
            max-height: 90px;
            object-fit: contain;
        }

        .header-copy {
            text-align: right;
        }

        .header-copy h1 {
            margin: 0 0 6px;
            color: var(--brown);
            font-size: 25px;
        }

        .header-copy p {
            margin: 3px 0;
            color: var(--muted);
            font-size: 12px;
        }

        .reference {
            margin: 22px 0;
            padding: 15px 18px;
            border-left: 5px solid var(--gold);
            background: #fffaf0;
        }

        .reference strong {
            color: var(--brown);
            font-size: 19px;
        }

        .section {
            margin-top: 25px;
        }

        .section h2 {
            margin: 0 0 13px;
            padding-bottom: 7px;
            border-bottom: 1px solid var(--border);
            color: var(--brown);
            font-size: 18px;
        }

        .details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .detail {
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #faf9f7;
        }

        .detail strong {
            display: block;
            margin-bottom: 5px;
            color: var(--brown);
            font-size: 12px;
        }

        .detail span {
            color: #4f4f4f;
            font-size: 13px;
            line-height: 1.55;
        }

        .full {
            grid-column: 1 / -1;
        }

        .description {
            white-space: pre-wrap;
        }

        .images {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .images figure {
            margin: 0;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #ffffff;
        }

        .images img {
            display: block;
            width: 100%;
            height: 280px;
            object-fit: contain;
            background: #f4f4f4;
        }

        .images figcaption {
            padding: 8px;
            color: var(--muted);
            font-size: 11px;
        }

        .footer {
            margin-top: 40px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        @media (max-width: 650px) {
            .document {
                width: calc(100% - 18px);
                padding: 18px;
            }

            .header {
                align-items: flex-start;
                flex-direction: column;
            }

            .header-copy {
                text-align: left;
            }

            .details,
            .images {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 14mm;
            }

            body {
                background: #ffffff;
            }

            .print-toolbar {
                display: none !important;
            }

            .document {
                width: 100%;
                margin: 0;
                padding: 0;
                border: none;
                border-radius: 0;
                box-shadow: none;
            }

            .section,
            .detail,
            .images figure {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .images img {
                height: 90mm;
            }
        }
    </style>
</head>

<body>

<div class="print-toolbar">

    <a
        href="javascript:history.back()"
        class="button button-secondary"
    >
        ← Kembali
    </a>

    <button
        type="button"
        class="button"
        onclick="window.print()"
    >
        Cetak / Simpan PDF
    </button>

</div>

<main class="document">

    <header class="header">

        <img
            src="images/logo.png?v=4"
            alt="V23 Malawa Ria"
        >

        <div class="header-copy">
            <h1>Laporan Aduan Resident</h1>
            <p>V23 Malawa Ria Apartment</p>
            <p>Property Management System</p>
        </div>

    </header>

    <div class="reference">
        Nombor Aduan:
        <strong>
            <?php echo e($complaintId); ?>
        </strong>
    </div>

    <section class="section">

        <h2>Maklumat Aduan</h2>

        <div class="details">

            <div class="detail">
                <strong>Tarikh Dihantar</strong>
                <span><?php echo e($createdAt); ?></span>
            </div>

            <div class="detail">
                <strong>Status</strong>
                <span><?php echo e($status); ?></span>
            </div>

            <div class="detail">
                <strong>Kategori</strong>
                <span><?php echo e($category); ?></span>
            </div>

            <div class="detail">
                <strong>Keutamaan</strong>
                <span><?php echo e($priority); ?></span>
            </div>

            <div class="detail full">
                <strong>Tajuk Aduan</strong>
                <span><?php echo e($subject); ?></span>
            </div>

            <div class="detail full">
                <strong>Penerangan</strong>
                <span class="description"><?php
                    echo e($description);
                ?></span>
            </div>

        </div>

    </section>

    <section class="section">

        <h2>Maklumat Lokasi</h2>

        <div class="details">

            <div class="detail">
                <strong>Blok</strong>
                <span><?php echo e($block); ?></span>
            </div>

            <div class="detail">
                <strong>No. Unit</strong>
                <span><?php echo e($unitNo); ?></span>
            </div>

            <div class="detail full">
                <strong>Lokasi Spesifik</strong>
                <span><?php echo e($location); ?></span>
            </div>

        </div>

    </section>

    <section class="section">

        <h2>Maklumat Pengadu</h2>

        <div class="details">

            <div class="detail">
                <strong>Nama</strong>
                <span><?php echo e($residentName); ?></span>
            </div>

            <div class="detail">
                <strong>Status Penghuni</strong>
                <span><?php echo e($residentStatus); ?></span>
            </div>

            <div class="detail">
                <strong>No. Telefon</strong>
                <span><?php echo e($phone); ?></span>
            </div>

            <div class="detail">
                <strong>Email</strong>
                <span><?php echo e($email); ?></span>
            </div>

        </div>

    </section>

    <?php if (
        $adminRemarks !== "-" ||
        $updatedAt !== "-"
    ): ?>

        <section class="section">

            <h2>Maklum Balas Pengurusan</h2>

            <div class="details">

                <div class="detail">
                    <strong>Tarikh Kemas Kini</strong>
                    <span><?php echo e($updatedAt); ?></span>
                </div>

                <div class="detail full">
                    <strong>Catatan Pengurusan</strong>
                    <span class="description"><?php
                        echo e($adminRemarks);
                    ?></span>
                </div>

            </div>

        </section>

    <?php endif; ?>

    <?php if (count($images) > 0): ?>

        <section class="section">

            <h2>Gambar Aduan</h2>

            <div class="images">

                <?php foreach (
                    $images as $index => $imageName
                ): ?>

                    <?php
                    $imageUrl =
                        resolveComplaintImageUrl(
                            (string) $imageName
                        );
                    ?>

                    <figure>

                        <img
                            src="<?php echo e($imageUrl); ?>"
                            alt="Gambar Aduan"
                        >

                        <figcaption>
                            Gambar <?php echo $index + 1; ?>
                        </figcaption>

                    </figure>

                <?php endforeach; ?>

            </div>

        </section>

    <?php endif; ?>

    <footer class="footer">
        Dicetak pada
        <?php echo e(date("d/m/Y h:i A")); ?>
        melalui V23 Property Management System.
    </footer>

</main>

</body>
</html>
