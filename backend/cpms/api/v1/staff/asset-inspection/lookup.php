<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '') {
    cpmsApiError('TOKEN_REQUIRED', 'Token QR aset diperlukan.', 422);
}

$stmt = $db->prepare(
    "SELECT * FROM assets WHERE public_token = ? AND property_id = ? AND asset_status <> 'Disposed' LIMIT 1"
);
$stmt->bind_param('si', $token, $propertyId);
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!is_array($asset)) {
    cpmsApiError('ASSET_NOT_FOUND', 'Aset tidak dijumpai.', 404);
}

// Checklist ikut kategori aset (padan dengan staff_asset_inspection.php)
$sets = [
    'Lamp Post' => ['Lampu menyala', 'Fitting dan cover baik', 'Tiang tidak berkarat atau senget', 'Kabel tidak terdedah', 'Kawasan selamat'],
    'Water Pump' => ['Pam beroperasi normal', 'Tiada kebocoran', 'Tiada bunyi atau getaran luar biasa', 'Tekanan mencukupi', 'Auto dan manual berfungsi'],
    'Pump Control Panel' => ['Lampu indikator berfungsi', 'Contactor baik', 'Overload relay normal', 'Wiring selamat', 'Tiada tanda panas atau terbakar'],
    'Boom Gate' => ['Motor berfungsi', 'Sensor berfungsi', 'Barrier arm baik', 'Remote berfungsi', 'Manual release boleh digunakan'],
    'Solar CCTV' => ['Kamera berfungsi', 'Rakaman tersedia', 'Panel solar bersih', 'Bateri baik', 'Pandangan tidak terhalang'],
    'Water Tank' => ['Paras air normal', 'Tiada kebocoran', 'Penutup selamat', 'Float valve berfungsi', 'Tangki bersih'],
    'Fire Hydrant' => ['Mudah diakses', 'Valve baik', 'Tiada kebocoran', 'Tanda lokasi jelas', 'Keadaan fizikal baik'],
    'Fire Extinguisher' => ['Pressure gauge normal', 'Pin dan seal lengkap', 'Badan tidak berkarat', 'Mudah diakses', 'Tarikh luput sah'],
    'Playground' => ['Struktur kukuh', 'Tiada bahagian tajam', 'Tiada karat berbahaya', 'Lantai selamat', 'Kawasan bersih'],
    'Drainage' => ['Tiada sumbatan', 'Aliran lancar', 'Tiada kerosakan struktur', 'Tiada bau luar biasa', 'Kawasan bersih'],
];
$items = $sets[(string) $asset['asset_category']] ?? [
    'Keadaan fizikal baik', 'Aset berfungsi normal', 'Tiada risiko keselamatan', 'Kawasan bersih', 'Tiada tindakan segera diperlukan',
];

cpmsApiRespond([
    'asset' => [
        'name' => (string) $asset['asset_name'],
        'code' => (string) $asset['asset_code'],
        'category' => (string) $asset['asset_category'],
        'location' => (string) ($asset['location'] ?? ''),
    ],
    'checklist_items' => $items,
]);
