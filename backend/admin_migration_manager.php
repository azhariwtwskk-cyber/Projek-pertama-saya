<?php

declare(strict_types=1);

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";
require_once __DIR__ . "/cpms/includes/migration_engine.php";

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

if (!isset($_SESSION["migration_csrf"])) {
    $_SESSION["migration_csrf"] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    (string) $_SESSION["migration_csrf"];

$isEnglish =
    $cpmsLanguage === "en";

$successMessage = "";
$errorMessage = "";

cpmsEnsureMigrationTable($conn);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken =
        (string) ($_POST["csrf_token"] ?? "");

    if (!hash_equals($csrfToken, $postedToken)) {
        $errorMessage =
            $isEnglish
                ? "Invalid request."
                : "Permintaan tidak sah.";
    } else {
        $action =
            (string) ($_POST["action"] ?? "");

        try {
            if ($action === "sync") {
                $count =
                    cpmsSyncDetectedMigrations(
                        $conn
                    );

                $successMessage =
                    $isEnglish
                        ? $count . " existing migration(s) were registered."
                        : $count . " migrasi sedia ada berjaya didaftarkan.";

            } elseif ($action === "run") {
                $completed =
                    cpmsRunPendingMigrations(
                        $conn
                    );

                $successMessage =
                    count($completed) > 0
                        ? (
                            $isEnglish
                                ? count($completed) . " pending migration(s) completed."
                                : count($completed) . " migrasi tertunda berjaya dijalankan."
                        )
                        : (
                            $isEnglish
                                ? "No pending migrations."
                                : "Tiada migrasi tertunda."
                        );
            }
        } catch (Throwable $error) {
            error_log(
                "Migration Manager error: " .
                $error->getMessage()
            );

            $errorMessage =
                $error->getMessage();
        }
    }
}

$appliedKeys =
    cpmsAppliedMigrationKeys($conn);

$migrations =
    cpmsMigrationDefinitions();

$conn->close();

?>
<!DOCTYPE html>
<html lang="<?php echo e($cpmsLanguage); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>
<?php
echo e(
    (
        $isEnglish
            ? "Migration Manager"
            : "Pengurus Migrasi"
    ) .
    " | " .
    cpmsPropertyName()
);
?>
</title>

<?php echo cpmsThemeStyleTag(); ?>

<style>
*{box-sizing:border-box}
body{margin:0;background:#f6f2ea;font-family:Arial,sans-serif;color:#333}
.page{width:min(1050px,calc(100% - 24px));margin:30px auto}
.header,.card{padding:22px;border:1px solid #e5ddd2;border-radius:15px;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.06)}
.header{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px;border-top:6px solid var(--cpms-secondary)}
.header h1{margin:0 0 5px;color:var(--cpms-primary)}
.header p{margin:0;color:#777}
.actions{display:flex;gap:9px;flex-wrap:wrap}
.button{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border:0;border-radius:8px;background:var(--cpms-secondary);color:#fff;text-decoration:none;font-weight:800;cursor:pointer}
.button.dark{background:var(--cpms-primary)}
.alert{margin-bottom:15px;padding:13px;border-radius:9px;font-weight:700}
.ok{background:#edf9f1;color:#176b36}
.err{background:#fff0f0;color:#9f2626}
table{width:100%;border-collapse:collapse}
th,td{padding:13px;border-bottom:1px solid #eee;text-align:left;font-size:13px}
th{color:var(--cpms-primary)}
.status{display:inline-flex;padding:5px 9px;border-radius:99px;font-size:11px;font-weight:800}
.applied{background:#edf9f1;color:#176b36}
.pending{background:#fff6df;color:#9c7200}
.note{margin-top:18px;padding:14px;border-left:4px solid var(--cpms-secondary);border-radius:8px;background:#fffaf0;color:#666;line-height:1.6}
@media(max-width:720px){.header{align-items:flex-start;flex-direction:column}.card{overflow-x:auto}}
</style>
</head>

<body>
<main class="page">

<header class="header">
    <div>
        <h1>
            <?php
            echo
                $isEnglish
                    ? "CPMS Migration Manager"
                    : "Pengurus Migrasi CPMS";
            ?>
        </h1>

        <p>
            <?php
            echo
                $isEnglish
                    ? "Track and run database updates safely."
                    : "Rekod dan jalankan kemas kini database dengan selamat.";
            ?>
        </p>
    </div>

    <div class="actions">
        <a href="admin_dashboard.php" class="button dark">
            ← Dashboard
        </a>
    </div>
</header>

<?php if ($successMessage !== ""): ?>
    <div class="alert ok">
        <?php echo e($successMessage); ?>
    </div>
<?php endif; ?>

<?php if ($errorMessage !== ""): ?>
    <div class="alert err">
        <?php echo e($errorMessage); ?>
    </div>
<?php endif; ?>

<section class="card">

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>
                    <?php echo $isEnglish ? "Migration" : "Migrasi"; ?>
                </th>
                <th>Status</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($migrations as $index => $migration): ?>
                <?php
                $applied =
                    in_array(
                        $migration["key"],
                        $appliedKeys,
                        true
                    );
                ?>

                <tr>
                    <td><?php echo $index + 1; ?></td>

                    <td>
                        <strong>
                            <?php echo e($migration["name"]); ?>
                        </strong>
                        <br>
                        <small>
                            <?php echo e($migration["key"]); ?>
                        </small>
                    </td>

                    <td>
                        <span class="status <?php echo $applied ? "applied" : "pending"; ?>">
                            <?php
                            echo
                                $applied
                                    ? ($isEnglish ? "Applied" : "Selesai")
                                    : ($isEnglish ? "Pending" : "Tertunda");
                            ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="actions" style="margin-top:20px">

        <form method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo e($csrfToken); ?>"
            >

            <input
                type="hidden"
                name="action"
                value="sync"
            >

            <button type="submit" class="button dark">
                <?php
                echo
                    $isEnglish
                        ? "Register Existing Structure"
                        : "Daftar Struktur Sedia Ada";
                ?>
            </button>
        </form>

        <form method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo e($csrfToken); ?>"
            >

            <input
                type="hidden"
                name="action"
                value="run"
            >

            <button type="submit" class="button">
                <?php
                echo
                    $isEnglish
                        ? "Run Pending Migrations"
                        : "Jalankan Migrasi Tertunda";
                ?>
            </button>
        </form>

    </div>

    <div class="note">
        <?php
        echo
            $isEnglish
                ? "For this existing CPMS installation, click Register Existing Structure first. The manager will detect completed database work without running it again."
                : "Untuk pemasangan CPMS sedia ada ini, klik Daftar Struktur Sedia Ada dahulu. Sistem akan mengesan perubahan database yang telah siap tanpa menjalankannya semula.";
        ?>
    </div>

</section>

</main>
</body>
</html>
