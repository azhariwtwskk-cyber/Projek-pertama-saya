<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_finding_service.php';

$allowedLimits = [30, 50, 100, 150, 200, 300];
$errors = [];
$success = '';
$ready = cpmsFindingTableExists($conn, 'inspection_property_settings');

if (!$ready) {
    $errors[] = 'Tetapan had gambar belum tersedia. Jalankan migration 20260810_0059_inspection_photo_limits dahulu.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    if (!systemOwnerVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi keselamatan tidak sah. Sila muat semula halaman.';
    } else {
        $propertyId = (int) ($_POST['property_id'] ?? 0);
        $photoLimit = (int) ($_POST['max_photos_per_inspection'] ?? 100);

        if ($propertyId < 1) {
            $errors[] = 'Property tidak sah.';
        }
        if (!in_array($photoLimit, $allowedLimits, true)) {
            $errors[] = 'Had gambar yang dipilih tidak sah.';
        }

        if (!$errors) {
            $updatedBy = trim((string) (
                $_SESSION['system_owner_name']
                ?? $_SESSION['system_owner_username']
                ?? 'System Owner'
            ));
            $stmt = $conn->prepare(
                'INSERT INTO inspection_property_settings (
                    property_id, max_photos_per_inspection, updated_by_name
                 ) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    max_photos_per_inspection = VALUES(max_photos_per_inspection),
                    updated_by_name = VALUES(updated_by_name),
                    updated_at = CURRENT_TIMESTAMP'
            );
            if (!$stmt) {
                $errors[] = 'Tetapan tidak dapat disediakan.';
            } else {
                $stmt->bind_param('iis', $propertyId, $photoLimit, $updatedBy);
                if ($stmt->execute()) {
                    $success = 'Had gambar inspection berjaya dikemas kini.';
                } else {
                    $errors[] = 'Tetapan tidak dapat disimpan: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

$properties = [];
$settingsJoin = $ready
    ? 'LEFT JOIN inspection_property_settings s ON s.property_id = p.id'
    : '';
$settingsColumns = $ready
    ? 'COALESCE(s.max_photos_per_inspection, 100) AS photo_limit,
       s.updated_by_name, s.updated_at'
    : '100 AS photo_limit, NULL AS updated_by_name, NULL AS updated_at';
$result = $conn->query(
    'SELECT p.id, p.property_code, p.property_name, p.is_active, '
    . $settingsColumns
    . ' FROM cpms_properties p '
    . $settingsJoin
    . ' ORDER BY p.is_active DESC, p.property_name ASC'
);
if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
    $result->free();
}

function inspectionSettingEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inspection Photo Settings | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        body{background:#f1f5f9;color:#0f172a}.wrap{max-width:1150px;margin:24px auto;padding:0 18px}.card{background:#fff;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:0 4px 16px #0001}.nav{display:flex;gap:9px;flex-wrap:wrap}.btn,button{display:inline-block;padding:9px 13px;border:0;border-radius:8px;background:#0f172a;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.btn.secondary{background:#334155}.ok{background:#dcfce7;color:#166534;padding:10px;border-radius:8px;margin-bottom:10px}.err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:10px}.info{background:#dbeafe;color:#1e40af;padding:12px;border-radius:9px}.muted{color:#64748b}.property{display:grid;grid-template-columns:minmax(260px,1fr) minmax(170px,220px) auto;gap:14px;align-items:end;padding:16px 0;border-top:1px solid #e2e8f0}.property:first-of-type{border-top:0}.property-name{font-weight:800}.tag{display:inline-block;padding:3px 7px;border-radius:99px;background:#e2e8f0;font-size:12px;margin-left:6px}label{font-size:13px;font-weight:800}select{width:100%;box-sizing:border-box;padding:9px;border:1px solid #cbd5e1;border-radius:8px;margin-top:5px}@media(max-width:760px){.property{grid-template-columns:1fr}.property button{width:100%}}
    </style>
</head>
<body>
<main class="wrap">
    <nav class="nav">
        <a class="btn" href="dashboard.php">← System Owner Dashboard</a>
        <a class="btn secondary" href="inspection_finding_master.php">Finding Master</a>
    </nav>

    <h1>Inspection Photo Settings</h1>
    <p class="muted">Tetapkan jumlah maksimum gambar bagi satu inspection untuk setiap property.</p>

    <?php foreach ($errors as $error): ?>
        <div class="err"><?php echo inspectionSettingEscape((string) $error); ?></div>
    <?php endforeach; ?>
    <?php if ($success !== ''): ?>
        <div class="ok"><?php echo inspectionSettingEscape($success); ?></div>
    <?php endif; ?>

    <div class="info">
        Tetapan lalai ialah <strong>100 gambar</strong>. Had aplikasi ialah
        <strong>300 gambar</strong> bagi satu inspection. Setiap gambar maksimum 8 MB;
        penggunaan storan hosting akan meningkat apabila had dinaikkan.
    </div>

    <section class="card" style="margin-top:18px">
        <h2>Property Limits (<?php echo count($properties); ?>)</h2>
        <?php if (!$properties): ?>
            <p class="muted">Tiada property dijumpai.</p>
        <?php endif; ?>
        <?php foreach ($properties as $property): ?>
            <form method="post" class="property">
                <input type="hidden" name="csrf_token" value="<?php echo inspectionSettingEscape(systemOwnerCsrfToken()); ?>">
                <input type="hidden" name="property_id" value="<?php echo (int) $property['id']; ?>">
                <div>
                    <div class="property-name">
                        <?php echo inspectionSettingEscape((string) $property['property_code']); ?> —
                        <?php echo inspectionSettingEscape((string) $property['property_name']); ?>
                        <span class="tag"><?php echo (int) $property['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span>
                    </div>
                    <?php if (!empty($property['updated_at'])): ?>
                        <small class="muted">
                            Last updated <?php echo inspectionSettingEscape((string) $property['updated_at']); ?>
                            <?php if (!empty($property['updated_by_name'])): ?>
                                by <?php echo inspectionSettingEscape((string) $property['updated_by_name']); ?>
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Maximum Photos per Inspection</label>
                    <select name="max_photos_per_inspection">
                        <?php foreach ($allowedLimits as $limit): ?>
                            <option value="<?php echo $limit; ?>" <?php echo (int) $property['photo_limit'] === $limit ? 'selected' : ''; ?>>
                                <?php echo $limit; ?> photos
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" <?php echo !$ready ? 'disabled' : ''; ?>>Save Limit</button>
            </form>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
