<?php

session_start();


// =========================================
// SEMAK LOGIN ADMIN
// =========================================

if (!isset($_SESSION["admin_id"])) {

    header("Location: admin_login.php");

    exit();
}


// =========================================
// SAMBUNG DATABASE
// =========================================

require_once "db.php";


// =========================================
// AMBIL FILTER DARIPADA URL
// =========================================

$search = trim(
    $_GET["search"] ?? ""
);

$status_filter = trim(
    $_GET["status"] ?? ""
);

$category_filter = trim(
    $_GET["category"] ?? ""
);


// =========================================
// STATUS YANG DIBENARKAN
// =========================================

$allowed_statuses = [

    "Pending",

    "In Progress",

    "Resolved",

    "Closed"

];


if (
    !empty($status_filter) &&
    !in_array(
        $status_filter,
        $allowed_statuses,
        true
    )
) {

    $status_filter = "";

}


// =========================================
// QUERY ADUAN
// =========================================

$sql = "

    SELECT *

    FROM complaints

    WHERE
    (

        ? = ''

        OR complaint_id LIKE CONCAT('%', ?, '%')

        OR name LIKE CONCAT('%', ?, '%')

        OR phone LIKE CONCAT('%', ?, '%')

        OR unit_no LIKE CONCAT('%', ?, '%')

        OR subject LIKE CONCAT('%', ?, '%')

    )

    AND
    (

        ? = ''

        OR status = ?

    )

    AND
    (

        ? = ''

        OR category = ?

    )

    ORDER BY date_created DESC

";


$stmt = $conn->prepare($sql);


if (!$stmt) {

    die(
        "Ralat SQL: " .
        htmlspecialchars($conn->error)
    );

}


$stmt->bind_param(

    "ssssssssss",

    $search,

    $search,

    $search,

    $search,

    $search,

    $search,

    $status_filter,

    $status_filter,

    $category_filter,

    $category_filter

);


$stmt->execute();


$result = $stmt->get_result();


// =========================================
// NAMA FAIL
// =========================================

$filename =

    "Laporan_Aduan_V23_" .

    date("Y-m-d_H-i-s") .

    ".csv";


// =========================================
// HEADER DOWNLOAD
// =========================================

header(
    "Content-Type: text/csv; charset=UTF-8"
);

header(
    'Content-Disposition: attachment; filename="' .
    $filename .
    '"'
);


// =========================================
// BUKA OUTPUT
// =========================================

$output = fopen(
    "php://output",
    "w"
);


// =========================================
// UTF-8 BOM
// Supaya Excel baca aksara dengan betul
// =========================================

fprintf(
    $output,
    chr(0xEF) .
    chr(0xBB) .
    chr(0xBF)
);


// =========================================
// TAJUK COLUMN
// =========================================

fputcsv(
    $output,
    [

        "No.",

        "Nombor Rujukan",

        "Nama Pengadu",

        "No. Telefon",

        "Email",

        "Status Penghuni",

        "Blok",

        "No. Unit",

        "Lokasi Masalah",

        "Kategori Aduan",

        "Tajuk Aduan",

        "Penerangan Aduan",

        "Tahap Keutamaan",

        "Status Aduan",

        "Catatan Admin",

        "Tarikh Aduan",

        "Tarikh Kemas Kini"

    ]
);


// =========================================
// MASUKKAN DATA
// =========================================

$number = 1;


while (
    $row = $result->fetch_assoc()
) {


    $date_created = "";


    if (!empty($row["date_created"])) {

        $date_created = date(

            "d/m/Y h:i A",

            strtotime(
                $row["date_created"]
            )

        );

    }


    $updated_at = "";


    if (!empty($row["updated_at"])) {

        $updated_at = date(

            "d/m/Y h:i A",

            strtotime(
                $row["updated_at"]
            )

        );

    }


    fputcsv(
        $output,
        [

            $number++,

            $row["complaint_id"] ?? "",

            $row["name"] ?? "",

            $row["phone"] ?? "",

            $row["email"] ?? "",

            $row["resident_status"] ?? "",

            $row["block"] ?? "",

            $row["unit_no"] ?? "",

            $row["location"] ?? "",

            $row["category"] ?? "",

            $row["subject"] ?? "",

            $row["description"] ?? "",

            $row["priority"] ?? "",

            $row["status"] ?? "",

            $row["admin_remarks"] ?? "",

            $date_created,

            $updated_at

        ]
    );

}


// =========================================
// TUTUP
// =========================================

fclose($output);

$stmt->close();

$conn->close();

exit();

?>