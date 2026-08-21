<?php

declare(strict_types=1);

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

$languageQuery = $_GET;
$languageQuery["lang"] = "ms";
$bmUrl =
    basename((string) $_SERVER["PHP_SELF"]) .
    "?" .
    http_build_query($languageQuery);

$languageQuery["lang"] = "en";
$enUrl =
    basename((string) $_SERVER["PHP_SELF"]) .
    "?" .
    http_build_query($languageQuery);

$isEnglish = $cpmsLanguage === "en";

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
                ($isEnglish
                    ? "Resident complaint form for "
                    : "Borang aduan penghuni untuk "
                ) .
                cpmsPropertyName()
            );
        ?>"
    >

    <title>
        <?php
        echo e(
            ($isEnglish
                ? "Complaint Form"
                : "Borang Aduan"
            ) .
            " | " .
            cpmsPropertyName()
        );
        ?>
    </title>

    <link
        rel="stylesheet"
        href="css/style.css?v=71"
    >
    <link
        rel="stylesheet"
        href="css/cpms-public-complaints.css?v=110"
    >

    <?php echo cpmsThemeStyleTag(); ?>

    <style>
        .complaint-language-switcher {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 14px;
        }

        .complaint-language-switcher a {
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

        .complaint-language-switcher a.active,
        .complaint-language-switcher a:hover {
            background: var(--cpms-primary);
            color: #ffffff;
        }

        .complaint-form-heading h1,
        .section,
        .complaint-submit-button {
            color: var(--cpms-primary);
        }

        .complaint-submit-button {
            background: var(--cpms-secondary);
            color: #ffffff;
        }

        .complaint-submit-button:hover {
            filter: brightness(.95);
        }

        .complaint-form input:focus,
        .complaint-form select:focus,
        .complaint-form textarea:focus {
            border-color: var(--cpms-secondary);
            box-shadow: 0 0 0 3px rgba(181,155,32,.15);
        }

        .cpms-form-footer {
            margin-top: 28px;
            padding-top: 14px;
            border-top: 1px solid #dddddd;
            color: #777777;
            font-size: 12px;
            text-align: center;
        }
    </style>
    <link
        rel="stylesheet"
        href="css/cpms-public-complaints.css?v=110"
    >
</head>

<body>

    <div class="container complaint-form-container">

        <div class="complaint-language-switcher">

            <a
                href="<?php echo e($bmUrl); ?>"
                class="<?php echo $cpmsLanguage === "ms" ? "active" : ""; ?>"
            >
                BM
            </a>

            <a
                href="<?php echo e($enUrl); ?>"
                class="<?php echo $cpmsLanguage === "en" ? "active" : ""; ?>"
            >
                EN
            </a>

        </div>

        <div class="logo">
            <a href="index.php">
                <img
                    src="<?php echo e(cpmsLogo()); ?>"
                    alt="<?php
                        echo e(
                            cpmsPropertyName() .
                            " Logo"
                        );
                    ?>"
                    onerror="this.style.display='none';this.parentElement.classList.add('is-logo-missing');"
                >
            </a>
        </div>

        <div class="complaint-form-heading">

            <h1>
                <?php
                echo $isEnglish
                    ? "COMPLAINT FORM"
                    : "BORANG ADUAN";
                ?>

                <span>
                    <?php echo e(cpmsPropertyName()); ?>
                </span>

                <small>
                    <?php
                    echo $isEnglish
                        ? "BORANG ADUAN"
                        : "COMPLAINT FORM";
                    ?>
                </small>
            </h1>

            <p>
                <?php
                echo $isEnglish
                    ? "Please complete all required fields. You may upload up to five images as supporting evidence."
                    : "Sila lengkapkan semua ruangan wajib. Anda boleh memuat naik maksimum lima gambar sebagai bukti aduan.";
                ?>
            </p>

        </div>

        <form
            action="submit_complaint.php"
            method="POST"
            enctype="multipart/form-data"
            class="complaint-form"
            id="complaint-form"
        >

            <div class="section">
                <?php
                echo $isEnglish
                    ? "Complainant Information"
                    : "Maklumat Pengadu";
                ?>
                <span>
                    <?php
                    echo $isEnglish
                        ? "Maklumat Pengadu"
                        : "Complainant Information";
                    ?>
                </span>
            </div>

            <div class="form-group">
                <label for="name">
                    <?php
                    echo $isEnglish
                        ? "Full Name / Nama Penuh"
                        : "Nama Penuh / Full Name";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="150"
                    autocomplete="name"
                    placeholder="<?php
                        echo $isEnglish
                            ? "Enter full name"
                            : "Masukkan nama penuh";
                    ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="phone">
                    <?php
                    echo $isEnglish
                        ? "Phone Number / No. Telefon"
                        : "No. Telefon / Phone Number";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    maxlength="20"
                    autocomplete="tel"
                    inputmode="tel"
                    pattern="[0-9+\-\s()]{8,20}"
                    placeholder="<?php
                        echo $isEnglish
                            ? "Example: 012-3456789"
                            : "Contoh: 012-3456789";
                    ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="email">
                    <?php
                    echo $isEnglish
                        ? "Email Address / E-mel"
                        : "E-mel / Email Address";
                    ?>
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="150"
                    autocomplete="email"
                    placeholder="<?php
                        echo $isEnglish
                            ? "Example: name@email.com"
                            : "Contoh: nama@email.com";
                    ?>"
                >
            </div>

            <div class="form-group">
                <label for="resident_status">
                    <?php
                    echo $isEnglish
                        ? "Resident Status / Status Penghuni"
                        : "Status Penghuni / Resident Status";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <select
                    id="resident_status"
                    name="resident_status"
                    required
                >
                    <option value="">
                        <?php
                        echo $isEnglish
                            ? "-- Please Select / Sila Pilih --"
                            : "-- Sila Pilih / Please Select --";
                        ?>
                    </option>

                    <option value="Pemilik / Owner">
                        <?php
                        echo $isEnglish
                            ? "Owner / Pemilik"
                            : "Pemilik / Owner";
                        ?>
                    </option>

                    <option value="Penyewa / Tenant">
                        <?php
                        echo $isEnglish
                            ? "Tenant / Penyewa"
                            : "Penyewa / Tenant";
                        ?>
                    </option>
                </select>
            </div>

            <div class="section">
                <?php
                echo $isEnglish
                    ? "Apartment Information"
                    : "Maklumat Kediaman";
                ?>
                <span>
                    <?php
                    echo $isEnglish
                        ? "Maklumat Kediaman"
                        : "Apartment Information";
                    ?>
                </span>
            </div>

            <div class="form-group">
                <label for="block">
                    <?php
                    echo $isEnglish
                        ? "Block / Blok"
                        : "Blok / Block";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <select
                    id="block"
                    name="block"
                    required
                >
                    <option value="">
                        <?php
                        echo $isEnglish
                            ? "-- Please Select / Sila Pilih --"
                            : "-- Sila Pilih / Please Select --";
                        ?>
                    </option>

                    <?php foreach (["A", "B", "C", "D", "E"] as $block): ?>
                        <option value="Blok <?php echo e($block); ?> / Block <?php echo e($block); ?>">
                            <?php
                            echo $isEnglish
                                ? "Block " . e($block) . " / Blok " . e($block)
                                : "Blok " . e($block) . " / Block " . e($block);
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="unit_no">
                    <?php
                    echo $isEnglish
                        ? "Unit Number / No. Unit"
                        : "No. Unit / Unit Number";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <input
                    type="text"
                    id="unit_no"
                    name="unit_no"
                    maxlength="30"
                    placeholder="<?php
                        echo $isEnglish
                            ? "Example: A-12-05"
                            : "Contoh: A-12-05";
                    ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="location">
                    <?php
                    echo $isEnglish
                        ? "Problem Location / Lokasi Masalah"
                        : "Lokasi Masalah / Problem Location";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <input
                    type="text"
                    id="location"
                    name="location"
                    maxlength="150"
                    placeholder="<?php
                        echo $isEnglish
                            ? "Example: Block A lift or parking area"
                            : "Contoh: Lif Blok A atau kawasan parkir";
                    ?>"
                    required
                >
            </div>

            <div class="section">
                <?php
                echo $isEnglish
                    ? "Complaint Details"
                    : "Maklumat Aduan";
                ?>
                <span>
                    <?php
                    echo $isEnglish
                        ? "Maklumat Aduan"
                        : "Complaint Details";
                    ?>
                </span>
            </div>

            <div class="form-group">
                <label for="category">
                    <?php
                    echo $isEnglish
                        ? "Complaint Category / Kategori Aduan"
                        : "Kategori Aduan / Complaint Category";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <select
                    id="category"
                    name="category"
                    required
                >
                    <option value="">
                        <?php
                        echo $isEnglish
                            ? "-- Please Select / Sila Pilih --"
                            : "-- Sila Pilih / Please Select --";
                        ?>
                    </option>

                    <option value="Kerosakan Bangunan / Building Maintenance">
                        <?php echo $isEnglish ? "Building Maintenance / Kerosakan Bangunan" : "Kerosakan Bangunan / Building Maintenance"; ?>
                    </option>

                    <option value="Kebocoran Air / Water Leakage">
                        <?php echo $isEnglish ? "Water Leakage / Kebocoran Air" : "Kebocoran Air / Water Leakage"; ?>
                    </option>

                    <option value="Masalah Elektrik / Electrical Problem">
                        <?php echo $isEnglish ? "Electrical Problem / Masalah Elektrik" : "Masalah Elektrik / Electrical Problem"; ?>
                    </option>

                    <option value="Masalah Lif / Lift Problem">
                        <?php echo $isEnglish ? "Lift Problem / Masalah Lif" : "Masalah Lif / Lift Problem"; ?>
                    </option>

                    <option value="Kebersihan / Cleanliness">
                        <?php echo $isEnglish ? "Cleanliness / Kebersihan" : "Kebersihan / Cleanliness"; ?>
                    </option>

                    <option value="Keselamatan / Security">
                        <?php echo $isEnglish ? "Security / Keselamatan" : "Keselamatan / Security"; ?>
                    </option>

                    <option value="Parkir / Parking">
                        <?php echo $isEnglish ? "Parking / Parkir" : "Parkir / Parking"; ?>
                    </option>

                    <option value="Lain-lain / Others">
                        <?php echo $isEnglish ? "Others / Lain-lain" : "Lain-lain / Others"; ?>
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label for="subject">
                    <?php
                    echo $isEnglish
                        ? "Complaint Subject / Tajuk Aduan"
                        : "Tajuk Aduan / Complaint Subject";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <input
                    type="text"
                    id="subject"
                    name="subject"
                    maxlength="200"
                    placeholder="<?php
                        echo $isEnglish
                            ? "Summarise the issue"
                            : "Ringkaskan masalah yang berlaku";
                    ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="description">
                    <?php
                    echo $isEnglish
                        ? "Complaint Description / Penerangan Aduan"
                        : "Penerangan Aduan / Complaint Description";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <textarea
                    id="description"
                    name="description"
                    rows="6"
                    maxlength="2000"
                    placeholder="<?php
                        echo $isEnglish
                            ? "Describe the issue, time of occurrence and related details"
                            : "Terangkan masalah, masa kejadian dan maklumat berkaitan";
                    ?>"
                    required
                ></textarea>
            </div>

            <div class="form-group">
                <label for="priority">
                    <?php
                    echo $isEnglish
                        ? "Priority Level / Tahap Keutamaan"
                        : "Tahap Keutamaan / Priority Level";
                    ?>
                    <span class="required-mark">*</span>
                </label>

                <select
                    id="priority"
                    name="priority"
                    required
                >
                    <option value="">
                        <?php
                        echo $isEnglish
                            ? "-- Please Select / Sila Pilih --"
                            : "-- Sila Pilih / Please Select --";
                        ?>
                    </option>

                    <option value="Tinggi / High">
                        <?php echo $isEnglish ? "High / Tinggi" : "Tinggi / High"; ?>
                    </option>

                    <option value="Sederhana / Medium">
                        <?php echo $isEnglish ? "Medium / Sederhana" : "Sederhana / Medium"; ?>
                    </option>

                    <option value="Rendah / Low">
                        <?php echo $isEnglish ? "Low / Rendah" : "Rendah / Low"; ?>
                    </option>
                </select>
            </div>

            <div class="section">
                <?php
                echo $isEnglish
                    ? "Complaint Evidence"
                    : "Bukti Aduan";
                ?>
                <span>
                    <?php
                    echo $isEnglish
                        ? "Bukti Aduan"
                        : "Complaint Evidence";
                    ?>
                </span>
            </div>

            <div class="form-group">
                <label for="images">
                    <?php
                    echo $isEnglish
                        ? "Upload Images / Muat Naik Gambar"
                        : "Muat Naik Gambar / Upload Images";
                    ?>
                </label>

                <input
                    type="file"
                    id="images"
                    name="images[]"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                >

                <small class="form-help">
                    <?php
                    echo $isEnglish
                        ? "Maximum 5 images. Each image must not exceed 5MB. Allowed formats: JPG, PNG and WEBP."
                        : "Maksimum 5 gambar. Setiap gambar tidak melebihi 5MB. Format dibenarkan: JPG, PNG dan WEBP.";
                    ?>
                </small>

                <div
                    id="image-preview"
                    class="multi-image-preview"
                    aria-live="polite"
                ></div>
            </div>

            <div class="complaint-declaration">
                <label class="declaration-label">
                    <input
                        type="checkbox"
                        name="declaration"
                        value="1"
                        required
                    >

                    <span>
                        <?php
                        echo $isEnglish
                            ? "I confirm that all information provided is true and accurate."
                            : "Saya mengesahkan bahawa semua maklumat yang diberikan adalah benar dan tepat.";
                        ?>
                    </span>
                </label>
            </div>

            <button
                type="submit"
                class="complaint-submit-button"
            >
                <?php
                echo $isEnglish
                    ? "SUBMIT COMPLAINT"
                    : "HANTAR ADUAN";
                ?>

                <span>
                    <?php
                    echo $isEnglish
                        ? "HANTAR ADUAN"
                        : "SUBMIT COMPLAINT";
                    ?>
                </span>
            </button>

            <div class="back-link">
                <a href="index.php">
                    ←
                    <?php
                    echo $isEnglish
                        ? "Back to Home"
                        : "Kembali ke Halaman Utama";
                    ?>
                </a>
            </div>

        </form>

        <footer class="cpms-form-footer">
            <?php echo e(cpmsFooter()); ?>
        </footer>

    </div>

    <script>
        const imageInput =
            document.getElementById("images");

        const preview =
            document.getElementById("image-preview");

        const complaintForm =
            document.getElementById("complaint-form");

        const isEnglish =
            <?php echo $isEnglish ? "true" : "false"; ?>;

        function clearPreview() {
            preview.innerHTML = "";
        }

        function renderPreview(files) {
            clearPreview();

            if (files.length === 0) {
                return;
            }

            const info =
                document.createElement("p");

            info.className =
                "multi-image-count";

            info.textContent =
                files.length +
                (
                    isEnglish
                        ? " image(s) selected"
                        : " gambar dipilih"
                );

            preview.appendChild(info);

            Array.from(files).forEach((file) => {
                const card =
                    document.createElement("div");

                card.className =
                    "multi-image-preview-card";

                const image =
                    document.createElement("img");

                image.alt = file.name;

                const caption =
                    document.createElement("small");

                caption.textContent = file.name;

                const reader =
                    new FileReader();

                reader.addEventListener(
                    "load",
                    () => {
                        image.src =
                            reader.result;
                    }
                );

                reader.readAsDataURL(file);

                card.appendChild(image);
                card.appendChild(caption);
                preview.appendChild(card);
            });
        }

        imageInput.addEventListener(
            "change",
            () => {
                const files =
                    imageInput.files;

                if (files.length > 5) {
                    alert(
                        isEnglish
                            ? "Maximum five images only."
                            : "Maksimum lima gambar sahaja."
                    );

                    imageInput.value = "";
                    clearPreview();
                    return;
                }

                for (const file of files) {
                    if (
                        file.size >
                        5 * 1024 * 1024
                    ) {
                        alert(
                            isEnglish
                                ? "Image " + file.name + " exceeds the maximum size of 5MB."
                                : "Gambar " + file.name + " melebihi saiz maksimum 5MB."
                        );

                        imageInput.value = "";
                        clearPreview();
                        return;
                    }
                }

                renderPreview(files);
            }
        );

        complaintForm.addEventListener(
            "submit",
            (event) => {
                if (
                    imageInput.files.length > 5
                ) {
                    event.preventDefault();

                    alert(
                        isEnglish
                            ? "Maximum five images only."
                            : "Maksimum lima gambar sahaja."
                    );
                }
            }
        );
    </script>

</body>

</html>
