<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SESSION DAN DATABASE
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/db.php";

/*
|--------------------------------------------------------------------------
| TETAPAN RALAT
|--------------------------------------------------------------------------
| Jangan paparkan ralat teknikal pada laman sebenar.
|--------------------------------------------------------------------------
*/

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");

/*
|--------------------------------------------------------------------------
| JIKA ADMIN SUDAH LOGIN
|--------------------------------------------------------------------------
*/

if (
    isset($_SESSION["admin"]) &&
    trim((string) $_SESSION["admin"]) !== ""
) {
    header("Location: admin_dashboard.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| FUNGSI KESELAMATAN OUTPUT
|--------------------------------------------------------------------------
*/

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

/*
|--------------------------------------------------------------------------
| SEMAK KEWUJUDAN COLUMN
|--------------------------------------------------------------------------
*/

function adminLoginColumnExists(
    mysqli $conn,
    string $tableName,
    string $columnName
): bool {
    $sql = "
        SELECT COUNT(*) AS total
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "ss",
        $tableName,
        $columnName
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

/*
|--------------------------------------------------------------------------
| DAPATKAN PROPERTY DEFAULT
|--------------------------------------------------------------------------
| Fungsi ini cuba mendapatkan property aktif pertama.
|
| Ia menyokong:
| - cpms_properties.is_active
| - cpms_properties.status
| - atau property pertama jika tiada column status
|--------------------------------------------------------------------------
*/

function getDefaultPropertyId(mysqli $conn): int
{
    $propertyId = 0;

    $hasIsActive = adminLoginColumnExists(
        $conn,
        "cpms_properties",
        "is_active"
    );

    $hasStatus = adminLoginColumnExists(
        $conn,
        "cpms_properties",
        "status"
    );

    if ($hasIsActive) {
        $sql = "
            SELECT id
            FROM cpms_properties
            WHERE is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ";
    } elseif ($hasStatus) {
        $sql = "
            SELECT id
            FROM cpms_properties
            WHERE LOWER(status) = 'active'
            ORDER BY id ASC
            LIMIT 1
        ";
    } else {
        $sql = "
            SELECT id
            FROM cpms_properties
            ORDER BY id ASC
            LIMIT 1
        ";
    }

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log(
            "Default property prepare error: " .
            $conn->error
        );

        return 0;
    }

    if (!$stmt->execute()) {
        error_log(
            "Default property execute error: " .
            $stmt->error
        );

        $stmt->close();

        return 0;
    }

    $result = $stmt->get_result();
    $property = $result->fetch_assoc();

    if ($property) {
        $propertyId = (int) ($property["id"] ?? 0);
    }

    $stmt->close();

    return $propertyId;
}

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["admin_login_csrf"]) ||
    !is_string($_SESSION["admin_login_csrf"]) ||
    $_SESSION["admin_login_csrf"] === ""
) {
    $_SESSION["admin_login_csrf"] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    (string) $_SESSION["admin_login_csrf"];

/*
|--------------------------------------------------------------------------
| PEMBOLEH UBAH
|--------------------------------------------------------------------------
*/

$error = "";
$username = "";

/*
|--------------------------------------------------------------------------
| PROSES LOGIN
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $postedToken =
        (string) ($_POST["csrf_token"] ?? "");

    $username = trim(
        (string) ($_POST["username"] ?? "")
    );

    $password =
        (string) ($_POST["password"] ?? "");

    /*
    |--------------------------------------------------------------------------
    | SEMAK CSRF TOKEN
    |--------------------------------------------------------------------------
    */

    if (
        $postedToken === "" ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {
        $error =
            "Permintaan tidak sah. Sila muat semula halaman.";

    /*
    |--------------------------------------------------------------------------
    | VALIDASI MEDAN
    |--------------------------------------------------------------------------
    */

    } elseif (
        $username === "" ||
        $password === ""
    ) {
        $error =
            "Sila masukkan username dan password.";

    } elseif (
        (
            function_exists("mb_strlen")
                ? mb_strlen($username, "UTF-8")
                : strlen($username)
        ) > 100
    ) {
        $error =
            "Maklumat login tidak sah.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | AMBIL REKOD ADMIN
        |--------------------------------------------------------------------------
        */

        $sql = "
            SELECT
                a.id,
                a.username,
                a.password,
                aa.role AS access_role,
                aa.property_id AS access_property_id,
                aa.is_active AS access_is_active
            FROM admins a
            LEFT JOIN cpms_admin_access aa
                ON aa.admin_id = a.id
            WHERE a.username = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            error_log(
                "Admin login prepare error: " .
                $conn->error
            );

            $error =
                "Sistem tidak dapat memproses login pada masa ini.";

        } else {

            $stmt->bind_param(
                "s",
                $username
            );

            if (!$stmt->execute()) {

                error_log(
                    "Admin login execute error: " .
                    $stmt->error
                );

                $error =
                    "Sistem tidak dapat memproses login pada masa ini.";

            } else {

                $result = $stmt->get_result();
                $admin = $result->fetch_assoc();

                /*
                |--------------------------------------------------------------------------
                | SEMAK PASSWORD
                |--------------------------------------------------------------------------
                */

                $passwordIsValid =
                    $admin &&
                    password_verify(
                        $password,
                        (string) $admin["password"]
                    );

                $accessIsConfigured =
                    $admin &&
                    isset($admin["access_role"]) &&
                    trim((string) $admin["access_role"]) !== "";

                $accessIsActive =
                    $admin &&
                    (int) ($admin["access_is_active"] ?? 0) === 1;

                if ($passwordIsValid && !$accessIsConfigured) {
                    $error = "Akaun ini belum mempunyai role CPMS. Hubungi System Owner.";
                } elseif ($passwordIsValid && !$accessIsActive) {
                    $error = "Akaun ini telah dinyahaktifkan. Hubungi System Owner.";
                } elseif ($passwordIsValid) {

                    /*
                    |--------------------------------------------------------------------------
                    | DAPATKAN PROPERTY DEFAULT
                    |--------------------------------------------------------------------------
                    */

                    $propertyId =
                        (int) ($admin["access_property_id"] ?? 0);

                    if ($propertyId < 1) {
                        $propertyId = getDefaultPropertyId($conn);
                    }

                    if ($propertyId < 1) {

                        error_log(
                            "Admin login: Tiada property ditemui dalam cpms_properties."
                        );

                        $error =
                            "Tiada property aktif ditemui. Sila semak Property Manager.";

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | ELAKKAN SESSION FIXATION
                        |--------------------------------------------------------------------------
                        */

                        session_regenerate_id(true);

                        /*
                        |--------------------------------------------------------------------------
                        | SESSION ADMIN ASAL
                        |--------------------------------------------------------------------------
                        */

                        $_SESSION["admin"] =
                            (string) $admin["username"];

                        $_SESSION["admin_id"] =
                            (int) $admin["id"];

                        $_SESSION["admin_logged_in_at"] =
                            time();

                        /*
                        |--------------------------------------------------------------------------
                        | SESSION SERASI CPMS COMMERCIAL
                        |--------------------------------------------------------------------------
                        */

                        $_SESSION["user_id"] =
                            (int) $admin["id"];

                        $_SESSION["user_name"] =
                            (string) $admin["username"];

                        $_SESSION["admin_name"] =
                            (string) $admin["username"];

                        $accessRole = strtolower(
                            trim((string) ($admin["access_role"] ?? "clerk"))
                        );

                        $allowedAccessRoles = [
                            "property_admin",
                            "manager",
                            "clerk"
                        ];

                        if (!in_array($accessRole, $allowedAccessRoles, true)) {
                            $accessRole = "clerk";
                        }

                        $_SESSION["role"] = $accessRole;
                        $_SESSION["user_role"] = $accessRole;

                        /*
                        |--------------------------------------------------------------------------
                        | SESSION PROPERTY
                        |--------------------------------------------------------------------------
                        | Beberapa nama session ditetapkan untuk memastikan
                        | keserasian dengan modul CPMS lama dan baharu.
                        |--------------------------------------------------------------------------
                        */

                        $_SESSION["property_id"] =
                            $propertyId;

                        $_SESSION["current_property_id"] =
                            $propertyId;

                        $_SESSION["cpms_property_id"] =
                            $propertyId;

                        /*
                        |--------------------------------------------------------------------------
                        | JANA SEMULA CSRF LOGIN
                        |--------------------------------------------------------------------------
                        */

                        unset(
                            $_SESSION["admin_login_csrf"]
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | REDIRECT KE DASHBOARD
                        |--------------------------------------------------------------------------
                        */

                        header(
                            "Location: admin_dashboard.php"
                        );

                        exit();
                    }

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | MESEJ UMUM UNTUK KESELAMATAN
                    |--------------------------------------------------------------------------
                    */

                    $error =
                        "Username atau password tidak betul.";
                }
            }

            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| TUTUP DATABASE SEBELUM PAPAR HTML
|--------------------------------------------------------------------------
*/

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

    <meta
        name="description"
        content="Portal Login Administrator V23 Malawa Ria Apartment"
    >

    <title>
        Admin Login | V23 Malawa Ria
    </title>

    <link
        rel="stylesheet"
        href="css/style.css?v=10"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap"
        rel="stylesheet"
    >

</head>

<body class="login-page">

    <div class="login-overlay">

        <div class="login-card">

            <a
                href="index.php"
                class="login-logo-link"
                aria-label="Kembali ke halaman utama"
            >

                <img
                    src="images/logo.png"
                    class="login-logo"
                    alt="V23 Malawa Ria Logo"
                >

            </a>

            <div class="login-badge">
                ADMINISTRATOR PORTAL
            </div>

            <h1>
                Admin Login
            </h1>

            <p class="login-subtitle">
                V23 Malawa Ria Apartment
                <br>
                Complaint Management System
            </p>

            <?php if ($error !== ""): ?>

                <div
                    class="login-error"
                    role="alert"
                >
                    <?php echo e($error); ?>
                </div>

            <?php endif; ?>

            <form
                method="POST"
                action="admin_login.php"
                class="admin-login-form"
                autocomplete="on"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($csrfToken); ?>"
                >

                <div class="login-form-group">

                    <label for="username">
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        value="<?php echo e($username); ?>"
                        placeholder="Masukkan username"
                        maxlength="100"
                        autocomplete="username"
                        required
                        autofocus
                    >

                </div>

                <div class="login-form-group">

                    <label for="password">
                        Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Masukkan password"
                        autocomplete="current-password"
                        required
                    >

                </div>

                <button
                    type="submit"
                    class="login-button"
                >
                    LOGIN
                </button>

            </form>

            <div class="login-back-link">

                <a href="index.php">
                    ← Kembali ke Halaman Utama
                </a>

            </div>

        </div>

    </div>

</body>

</html>