<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function demoEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function demoCsrfToken(): string
{
    if (empty($_SESSION['demo_data_csrf'])) {
        $_SESSION['demo_data_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['demo_data_csrf'];
}

function demoReference(string $prefix, int $propertyId, int $sequence): string
{
    return sprintf(
        '%s-P%02d-%s-%04d',
        $prefix,
        $propertyId,
        date('ymd'),
        $sequence
    );
}

$properties = [];
$result = $conn->query(
    "SELECT id, property_code, property_name
     FROM cpms_properties
     WHERE is_active = 1
     ORDER BY property_name"
);

if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $propertyId = (int) ($_POST['property_id'] ?? 0);
    $residentCount = max(0, min(20, (int) ($_POST['resident_count'] ?? 3)));
    $complaintCount = max(0, min(30, (int) ($_POST['complaint_count'] ?? 5)));
    $staffCount = max(0, min(10, (int) ($_POST['staff_count'] ?? 2)));

    if (
        !is_string($token)
        || !hash_equals(demoCsrfToken(), $token)
        || $propertyId <= 0
    ) {
        $message = 'Permintaan tidak sah.';
        $messageType = 'danger';
    } else {
        $propertyStmt = $conn->prepare(
            "SELECT property_code, property_name
             FROM cpms_properties
             WHERE id = ?
               AND is_active = 1
             LIMIT 1"
        );
        $propertyStmt->bind_param('i', $propertyId);
        $propertyStmt->execute();
        $property = $propertyStmt->get_result()->fetch_assoc();
        $propertyStmt->close();

        if (!$property) {
            $message = 'Property tidak ditemui atau tidak aktif.';
            $messageType = 'danger';
        } else {
            $createdResidents = 0;
            $createdComplaints = 0;
            $createdStaff = 0;

            $names = [
                'Ahmad Firdaus',
                'Siti Nur Aisyah',
                'Mohd Azlan',
                'Nurul Huda',
                'Daniel Lim',
                'Michelle Wong',
                'Roslan Japar',
                'Farah Nadia',
            ];

            $subjects = [
                ['Lampu koridor tidak menyala', 'Masalah Elektrik / Electrical Problem', 'Koridor'],
                ['Paip air mengalami kebocoran', 'Kebocoran Air / Water Leakage', 'Meter Air'],
                ['Longkang tersumbat', 'Kebersihan / Cleanliness', 'Kawasan Blok'],
                ['Bunyi bising pada waktu malam', 'Keselamatan / Security', 'Kawasan Kediaman'],
                ['Kerosakan di kawasan permainan', 'Fasiliti / Facility', 'Playground'],
                ['Pokok perlu dipangkas', 'Landskap / Landscape', 'Common Area'],
            ];

            $conn->begin_transaction();

            try {
                for ($i = 1; $i <= $residentCount; $i++) {
                    $name = $names[($i - 1) % count($names)] . ' Demo ' . $i;
                    $residentCode = demoReference('RES', $propertyId, $i);
                    $email = 'demo.p' . $propertyId . '.resident' . $i . '@example.test';
                    $phone = '019' . str_pad((string) ($propertyId * 100000 + $i), 7, '0', STR_PAD_LEFT);
                    $block = chr(64 + (($i - 1) % 5) + 1);
                    $unit = (($i - 1) % 4 + 1) . '-' . (($i - 1) % 10 + 1);
                    $type = $i % 2 === 0 ? 'TENANT' : 'OWNER';
                    $passwordHash = password_hash('Demo@12345', PASSWORD_DEFAULT);

                    $stmt = $conn->prepare(
                        "INSERT INTO cpms_residents (
                            property_id,
                            resident_code,
                            full_name,
                            email,
                            phone,
                            block_name,
                            unit_no,
                            resident_type,
                            password_hash,
                            is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Resident statement failed.');
                    }
                    $stmt->bind_param(
                        'issssssss',
                        $propertyId,
                        $residentCode,
                        $name,
                        $email,
                        $phone,
                        $block,
                        $unit,
                        $type,
                        $passwordHash
                    );
                    $stmt->execute();
                    $stmt->close();
                    $createdResidents++;
                }

                for ($i = 1; $i <= $staffCount; $i++) {
                    $fullName = 'Demo Staff P' . $propertyId . ' ' . $i;
                    $username = 'demo_p' . $propertyId . '_staff_' . $i . '_' . time();
                    $passwordHash = password_hash('Demo@12345', PASSWORD_DEFAULT);
                    $role = $i % 2 === 0 ? 'Maintenance' : 'Cleaner';
                    $phone = '018' . str_pad((string) ($propertyId * 100000 + $i), 7, '0', STR_PAD_LEFT);

                    $stmt = $conn->prepare(
                        "INSERT INTO staff (
                            property_id,
                            full_name,
                            username,
                            password,
                            role,
                            phone,
                            account_status
                        ) VALUES (?, ?, ?, ?, ?, ?, 'Active')"
                    );
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Staff statement failed. Run Build 004 SQL first.'
                        );
                    }
                    $stmt->bind_param(
                        'isssss',
                        $propertyId,
                        $fullName,
                        $username,
                        $passwordHash,
                        $role,
                        $phone
                    );
                    $stmt->execute();
                    $stmt->close();
                    $createdStaff++;
                }

                for ($i = 1; $i <= $complaintCount; $i++) {
                    $item = $subjects[($i - 1) % count($subjects)];
                    $name = $names[($i - 1) % count($names)] . ' Demo';
                    $complaintId = demoReference('TEST', $propertyId, $i);
                    $phone = '017' . str_pad((string) ($propertyId * 100000 + $i), 7, '0', STR_PAD_LEFT);
                    $email = 'demo.p' . $propertyId . '.complaint' . $i . '@example.test';
                    $residentStatus = $i % 2 === 0 ? 'Tenant' : 'Owner';
                    $block = chr(64 + (($i - 1) % 5) + 1);
                    $unit = (($i - 1) % 4 + 1) . '-' . (($i - 1) % 10 + 1);
                    $location = $item[2] . ', Blok ' . $block;
                    $category = $item[1];
                    $subject = '[DEMO] ' . $item[0];
                    $description = 'Rekod demo untuk menguji pengasingan data Property ID ' . $propertyId . '.';
                    $priority = $i % 3 === 0 ? 'Tinggi / High' : 'Sederhana / Medium';
                    $status = 'Pending';

                    $stmt = $conn->prepare(
                        "INSERT INTO complaints (
                            complaint_id,
                            property_id,
                            name,
                            phone,
                            email,
                            resident_status,
                            unit_no,
                            block,
                            location,
                            category,
                            subject,
                            description,
                            priority,
                            image,
                            status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?)"
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Complaint statement failed.');
                    }
                    $stmt->bind_param(
                        'sissssssssssss',
                        $complaintId,
                        $propertyId,
                        $name,
                        $phone,
                        $email,
                        $residentStatus,
                        $unit,
                        $block,
                        $location,
                        $category,
                        $subject,
                        $description,
                        $priority,
                        $status
                    );
                    $stmt->execute();
                    $stmt->close();
                    $createdComplaints++;
                }

                $batch = $conn->prepare(
                    "INSERT INTO cpms_demo_batches (
                        property_id,
                        residents_created,
                        complaints_created,
                        staff_created,
                        created_by
                    ) VALUES (?, ?, ?, ?, ?)"
                );
                if ($batch) {
                    $ownerName = (string) $systemOwnerUser['full_name'];
                    $batch->bind_param(
                        'iiiis',
                        $propertyId,
                        $createdResidents,
                        $createdComplaints,
                        $createdStaff,
                        $ownerName
                    );
                    $batch->execute();
                    $batch->close();
                }

                $conn->commit();
                $message = sprintf(
                    '%d resident, %d aduan dan %d pekerja demo berjaya dicipta untuk %s.',
                    $createdResidents,
                    $createdComplaints,
                    $createdStaff,
                    $property['property_name']
                );
            } catch (Throwable $e) {
                $conn->rollback();
                $message = 'Data demo gagal dijana. Pastikan SQL Build 004 dan Build 005 telah dijalankan.';
                $messageType = 'danger';
            }
        }
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo Data | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <link rel="stylesheet" href="assets/build_005.css">
</head>
<body>
<div class="so-dashboard">
    <header class="so-topbar">
        <div>
            <strong>Demo Data Generator</strong><br>
            <small>System Owner Portal</small>
        </div>
        <a href="logout.php">Log Keluar</a>
    </header>

    <div class="build-actions">
        <a href="dashboard.php">← Dashboard</a>
        <a href="health_check.php">System Health</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="so-alert so-alert-<?php echo demoEscape($messageType); ?>">
            <?php echo demoEscape($message); ?>
        </div>
    <?php endif; ?>

    <section class="so-panel build-panel">
        <h2>Jana Data Ujian</h2>
        <p>
            Gunakan fungsi ini untuk menguji property baharu yang masih belum
            mempunyai resident, aduan atau pekerja.
        </p>

        <form method="post" class="build-form">
            <input type="hidden" name="csrf_token" value="<?php echo demoEscape(demoCsrfToken()); ?>">

            <label>
                Property
                <select name="property_id" required>
                    <option value="">Pilih property</option>
                    <?php foreach ($properties as $property): ?>
                        <option value="<?php echo (int) $property['id']; ?>">
                            ID <?php echo (int) $property['id']; ?>
                            —
                            <?php echo demoEscape($property['property_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="build-grid">
                <label>
                    Jumlah Resident
                    <input type="number" name="resident_count" min="0" max="20" value="3">
                </label>

                <label>
                    Jumlah Aduan
                    <input type="number" name="complaint_count" min="0" max="30" value="5">
                </label>

                <label>
                    Jumlah Pekerja
                    <input type="number" name="staff_count" min="0" max="10" value="2">
                </label>
            </div>

            <div class="build-warning">
                Semua rekod dijana dengan tanda DEMO/TEST dan menggunakan kata
                laluan sementara <strong>Demo@12345</strong>.
            </div>

            <button type="submit">Generate Demo Data</button>
        </form>
    </section>
</div>
</body>
</html>
