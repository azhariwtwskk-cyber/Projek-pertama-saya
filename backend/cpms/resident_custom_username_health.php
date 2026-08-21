<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';

if (
    (string) ($_SESSION['cpms_user_role'] ?? '') !== 'system_owner'
    && empty($_SESSION['system_owner_id'])
) {
    http_response_code(403);
    exit('System Owner access required.');
}

function cpmsResidentUsernameHealthCount(mysqli $db, string $sql): ?int
{
    try {
        $result = $db->query($sql);
    } catch (Throwable $exception) {
        return null;
    }
    if (!($result instanceof mysqli_result)) {
        return null;
    }
    $row = $result->fetch_assoc();
    return (int) ($row['total'] ?? 0);
}

$columnCount = cpmsResidentUsernameHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM information_schema.columns
     WHERE table_schema=DATABASE()
       AND table_name='cpms_resident_registrations'
       AND column_name IN ('requested_username','assigned_username')"
);

$indexCount = cpmsResidentUsernameHealthCount(
    $conn,
    "SELECT COUNT(DISTINCT index_name) AS total
     FROM information_schema.statistics
     WHERE table_schema=DATABASE()
       AND table_name='cpms_resident_registrations'
       AND index_name='idx_resident_registration_username'"
);

$duplicatePendingCount = cpmsResidentUsernameHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM (
        SELECT LOWER(requested_username) AS username_key
        FROM cpms_resident_registrations
        WHERE status='Pending'
          AND requested_username IS NOT NULL
          AND TRIM(requested_username)<>''
        GROUP BY LOWER(requested_username)
        HAVING COUNT(*)>1
     ) duplicates"
);

$approvedMismatchCount = cpmsResidentUsernameHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM cpms_resident_registrations a
     INNER JOIN system_users u
        ON u.source_table='cpms_residents'
       AND u.source_id=a.approved_resident_id
     WHERE a.status='Approved'
       AND a.assigned_username IS NOT NULL
       AND LOWER(a.assigned_username)<>LOWER(u.username)"
);

$legacyPendingCount = cpmsResidentUsernameHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM cpms_resident_registrations
     WHERE status='Pending'
       AND (requested_username IS NULL OR TRIM(requested_username)='')"
);

$schemaVersionCount = cpmsResidentUsernameHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM cpms_v2_schema_versions
     WHERE version_no='3.6.0.9'"
);

$checks = [
    ['Dua kolum username tersedia', $columnCount, 2],
    ['Index semakan username', $indexCount, 1],
    ['Username Pending bertindih', $duplicatePendingCount, 0],
    ['Username Approved tidak sepadan', $approvedMismatchCount, 0],
    ['Versi schema 3.6.0.9', $schemaVersionCount, 1],
];

$pass = true;
foreach ($checks as $check) {
    if ($check[1] === null || $check[1] !== $check[2]) {
        $pass = false;
        break;
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CPMS v3.6.0.9 Resident Username Health</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:820px;margin:auto;padding:28px;border-radius:17px;background:#fff;box-shadow:0 18px 44px #10213d17}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:12px;border-bottom:1px solid #dde5ef}.value{text-align:right;font-weight:900}.note{padding:13px;border-radius:10px;color:#1e3a8a;background:#eff6ff}
    </style>
</head>
<body>
<main class="box">
    <small>CPMS RELEASE CHECK</small>
    <h1>v3.6.0.9 — Resident Custom Username</h1>
    <h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>">
        <?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?>
    </h2>
    <p class="note">
        Permohonan lama yang masih Pending tanpa username pilihan:
        <strong><?php echo $legacyPendingCount === null
            ? 'Query gagal'
            : (int) $legacyPendingCount; ?></strong>.
        Rekod lama ini masih boleh diluluskan menggunakan kod resident.
    </p>
    <table>
        <?php foreach ($checks as $check): ?>
            <?php $ready = $check[1] !== null && $check[1] === $check[2]; ?>
            <tr>
                <td><?php echo htmlspecialchars((string) $check[0], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="value <?php echo $ready ? 'ok' : 'bad'; ?>">
                    <?php echo $check[1] === null
                        ? 'Query gagal'
                        : (int) $check[1]; ?>
                    / <?php echo (int) $check[2]; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p>
        <?php echo $pass
            ? 'Borang pendaftaran dan proses kelulusan sedia diuji.'
            : 'Upload semua fail patch dan jalankan Migration 0058.'; ?>
    </p>
</main>
</body>
</html>
