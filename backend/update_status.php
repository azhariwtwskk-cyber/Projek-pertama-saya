<?php

session_start();


// =====================================
// SEMAK LOGIN ADMIN
// =====================================

if (!isset($_SESSION["admin_id"])) {

    header("Location: admin_login.php");

    exit();
}


require_once "db.php";


// =====================================
// PASTIKAN REQUEST POST
// =====================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    header("Location: admin_dashboard.php");

    exit();
}


// =====================================
// AMBIL DATA
// =====================================

$id = $_POST["id"] ?? "";

$status = trim(
    $_POST["status"] ?? ""
);

$admin_remarks = trim(
    $_POST["admin_remarks"] ?? ""
);

$changed_by = $_SESSION["admin_username"] ?? "Admin";


// =====================================
// STATUS YANG DIBENARKAN
// =====================================

$allowed_status = [

    "Pending",

    "In Progress",

    "Resolved",

    "Closed"

];


// =====================================
// VALIDATION
// =====================================

if (
    empty($id) ||
    !in_array(
        $status,
        $allowed_status,
        true
    )
) {

    die("Data status tidak sah.");

}


// =====================================
// MULAKAN TRANSACTION
// =====================================

$conn->begin_transaction();


try {


    // =================================
    // 1. UPDATE ADUAN SEMASA
    // =================================

    $sql_update = "

        UPDATE complaints

        SET
            status = ?,
            admin_remarks = ?

        WHERE id = ?

    ";


    $stmt_update =
        $conn->prepare($sql_update);


    if (!$stmt_update) {

        throw new Exception(
            "Ralat menyediakan arahan update."
        );

    }


    $stmt_update->bind_param(

        "ssi",

        $status,

        $admin_remarks,

        $id

    );


    if (!$stmt_update->execute()) {

        throw new Exception(
            "Gagal mengemas kini aduan."
        );

    }


    $stmt_update->close();



    // =================================
    // 2. SIMPAN DALAM HISTORY
    // =================================

    $sql_history = "

        INSERT INTO complaint_history
        (
            complaint_id,
            status,
            admin_remarks,
            changed_by
        )

        VALUES
        (
            ?, ?, ?, ?
        )

    ";


    $stmt_history =
        $conn->prepare($sql_history);


    if (!$stmt_history) {

        throw new Exception(
            "Ralat menyediakan rekod sejarah."
        );

    }


    $stmt_history->bind_param(

        "isss",

        $id,

        $status,

        $admin_remarks,

        $changed_by

    );


    if (!$stmt_history->execute()) {

        throw new Exception(
            "Gagal menyimpan sejarah perubahan."
        );

    }


    $stmt_history->close();



    // =================================
    // SEMUA BERJAYA
    // =================================

    $conn->commit();


    header(
        "Location: admin_dashboard.php?updated=1"
    );

    exit();


} catch (Exception $e) {


    // Batalkan semua perubahan jika ada error

    $conn->rollback();


    die(
        "Ralat: " .
        htmlspecialchars(
            $e->getMessage()
        )
    );

}


$conn->close();

?>