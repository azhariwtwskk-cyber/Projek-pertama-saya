<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_finding_service.php';

$errors = [];
$success = '';
$ready = cpmsFindingTableExists($conn, 'inspection_finding_master');

if (!$ready) {
    $errors[] = 'Jadual Finding Master belum wujud. Jalankan migration CPMS v3.2.6 dahulu.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    if (!systemOwnerVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi keselamatan tidak sah. Sila muat semula halaman.';
    } else {
        $action = trim((string) ($_POST['action'] ?? 'create'));

        if ($action === 'create') {
            $propertyId = (int) ($_POST['property_id'] ?? 0);
            $propertyValue = $propertyId > 0 ? $propertyId : null;
            $code = strtoupper(trim((string) ($_POST['finding_code'] ?? '')));
            $category = trim((string) ($_POST['category'] ?? ''));
            $name = trim((string) ($_POST['finding_name'] ?? ''));
            $severity = trim((string) ($_POST['default_severity'] ?? 'Medium'));
            $recommendation = trim((string) ($_POST['recommendation_template'] ?? ''));
            $displayOrder = max(1, (int) ($_POST['display_order'] ?? 100));

            if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,80}$/', $code)) {
                $errors[] = 'Finding Code diperlukan dan hanya boleh mengandungi A-Z, 0-9, _ atau -.';
            }
            if ($category === '') {
                $errors[] = 'Category diperlukan.';
            }
            if ($name === '') {
                $errors[] = 'Finding Name diperlukan.';
            }
            if (!in_array($severity, ['Low', 'Medium', 'High', 'Critical'], true)) {
                $errors[] = 'Default Severity tidak sah.';
            }

            if (!$errors) {
                if ($propertyValue === null) {
                    $stmt = $conn->prepare(
                        'INSERT INTO inspection_finding_master (
                            property_id, finding_code, category, finding_name,
                            default_severity, recommendation_template,
                            display_order, status
                         ) VALUES (NULL, ?, ?, ?, ?, NULLIF(?, ""), ?, "active")'
                    );
                    if ($stmt) {
                        $stmt->bind_param(
                            'sssssi',
                            $code,
                            $category,
                            $name,
                            $severity,
                            $recommendation,
                            $displayOrder
                        );
                    }
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO inspection_finding_master (
                            property_id, finding_code, category, finding_name,
                            default_severity, recommendation_template,
                            display_order, status
                         ) VALUES (?, ?, ?, ?, ?, NULLIF(?, ""), ?, "active")'
                    );
                    if ($stmt) {
                        $stmt->bind_param(
                            'isssssi',
                            $propertyValue,
                            $code,
                            $category,
                            $name,
                            $severity,
                            $recommendation,
                            $displayOrder
                        );
                    }
                }

                if (!$stmt) {
                    $errors[] = 'Finding tidak dapat disediakan.';
                } elseif ($stmt->execute()) {
                    $success = 'Finding Master baharu berjaya ditambah.';
                } else {
                    $errors[] = (int) $stmt->errno === 1062
                        ? 'Finding Code ini sudah digunakan.'
                        : 'Finding tidak dapat disimpan: ' . $stmt->error;
                }
                if ($stmt) {
                    $stmt->close();
                }
            }
        } elseif ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $category = trim((string) ($_POST['category'] ?? ''));
            $name = trim((string) ($_POST['finding_name'] ?? ''));
            $severity = trim((string) ($_POST['default_severity'] ?? 'Medium'));
            $recommendation = trim((string) ($_POST['recommendation_template'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active');
            $displayOrder = max(1, (int) ($_POST['display_order'] ?? 100));

            if ($id < 1 || $category === '' || $name === '') {
                $errors[] = 'Maklumat finding tidak lengkap.';
            }
            if (!in_array($severity, ['Low', 'Medium', 'High', 'Critical'], true)) {
                $errors[] = 'Default Severity tidak sah.';
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'inactive';
            }

            if (!$errors) {
                $stmt = $conn->prepare(
                    'UPDATE inspection_finding_master
                     SET category = ?, finding_name = ?, default_severity = ?,
                         recommendation_template = NULLIF(?, ""),
                         display_order = ?, status = ?
                     WHERE id = ?'
                );
                if ($stmt) {
                    $stmt->bind_param(
                        'ssssisi',
                        $category,
                        $name,
                        $severity,
                        $recommendation,
                        $displayOrder,
                        $status,
                        $id
                    );
                    if ($stmt->execute()) {
                        $success = 'Finding Master berjaya dikemas kini.';
                    } else {
                        $errors[] = 'Finding tidak dapat dikemas kini: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }
}

$properties = [];
$propertyResult = $conn->query(
    'SELECT id, property_code, property_name
     FROM cpms_properties WHERE is_active = 1 ORDER BY property_name'
);
if ($propertyResult instanceof mysqli_result) {
    while ($row = $propertyResult->fetch_assoc()) {
        $properties[] = $row;
    }
    $propertyResult->free();
}

$rows = [];
if ($ready) {
    $result = $conn->query(
        'SELECT fm.*, p.property_code, p.property_name
         FROM inspection_finding_master fm
         LEFT JOIN cpms_properties p ON p.id = fm.property_id
         ORDER BY fm.category, fm.display_order, fm.finding_name'
    );
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inspection Finding Master | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        body{background:#f1f5f9;color:#0f172a}.wrap{max-width:1250px;margin:24px auto;padding:0 18px}.card{background:#fff;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:0 4px 16px #0001}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.full{grid-column:1/-1}label{font-size:13px;font-weight:800}input,select,textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #cbd5e1;border-radius:8px;margin:5px 0 10px}.btn,button{display:inline-block;padding:9px 13px;border:0;border-radius:8px;background:#0f172a;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.ok{background:#dcfce7;color:#166534;padding:10px;border-radius:8px;margin-bottom:10px}.err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:10px}.muted{color:#64748b}.master{border-top:1px solid #e2e8f0;padding-top:14px;margin-top:14px}.tag{display:inline-block;padding:3px 7px;border-radius:99px;background:#e2e8f0;font-size:12px}@media(max-width:760px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}
    </style>
</head>
<body>
<main class="wrap">
    <p><a class="btn" href="dashboard.php">← System Owner Dashboard</a></p>
    <h1>Inspection Finding Master</h1>
    <p class="muted">Senarai pilihan finding untuk HQ Inspector. Gunakan Inactive untuk menyembunyikan finding tanpa memadam sejarah lama.</p>

    <?php foreach ($errors as $error): ?>
        <div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endforeach; ?>
    <?php if ($success !== ''): ?>
        <div class="ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($ready): ?>
    <section class="card">
        <h2>Add Finding</h2>
        <form method="post" class="grid">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(systemOwnerCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create">
            <div>
                <label>Scope</label>
                <select name="property_id">
                    <option value="0">Global — semua property</option>
                    <?php foreach ($properties as $property): ?>
                        <option value="<?php echo (int) $property['id']; ?>">
                            <?php echo htmlspecialchars((string) $property['property_code'] . ' — ' . (string) $property['property_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Finding Code</label><input name="finding_code" placeholder="Contoh: BLD-DOOR" required></div>
            <div><label>Category</label><input name="category" placeholder="Contoh: Building" required></div>
            <div><label>Finding Name</label><input name="finding_name" placeholder="Contoh: Damaged Door" required></div>
            <div>
                <label>Default Severity</label>
                <select name="default_severity"><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select>
            </div>
            <div><label>Display Order</label><input type="number" name="display_order" value="100" min="1"></div>
            <div class="full"><label>Default Recommendation</label><textarea name="recommendation_template" rows="3"></textarea></div>
            <div class="full"><button type="submit">Add to Finding Master</button></div>
        </form>
    </section>

    <section class="card">
        <h2>Current Findings (<?php echo count($rows); ?>)</h2>
        <?php foreach ($rows as $row): ?>
            <form method="post" class="master grid">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(systemOwnerCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                <div class="full">
                    <strong><?php echo htmlspecialchars((string) $row['finding_code'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="tag"><?php echo $row['property_id'] === null ? 'Global' : htmlspecialchars((string) $row['property_code'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div><label>Category</label><input name="category" value="<?php echo htmlspecialchars((string) $row['category'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                <div><label>Finding Name</label><input name="finding_name" value="<?php echo htmlspecialchars((string) $row['finding_name'], ENT_QUOTES, 'UTF-8'); ?>" required></div>
                <div>
                    <label>Default Severity</label>
                    <select name="default_severity">
                        <?php foreach (['Low','Medium','High','Critical'] as $severity): ?>
                            <option <?php echo (string) $row['default_severity'] === $severity ? 'selected' : ''; ?>><?php echo $severity; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Display Order</label><input type="number" min="1" name="display_order" value="<?php echo (int) $row['display_order']; ?>"></div>
                <div class="full"><label>Default Recommendation</label><textarea name="recommendation_template" rows="2"><?php echo htmlspecialchars((string) $row['recommendation_template'], ENT_QUOTES, 'UTF-8'); ?></textarea></div>
                <div>
                    <label>Status</label>
                    <select name="status"><option value="active" <?php echo (string) $row['status'] === 'active' ? 'selected' : ''; ?>>Active</option><option value="inactive" <?php echo (string) $row['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option></select>
                </div>
                <div style="align-self:end"><button type="submit">Save Changes</button></div>
            </form>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
