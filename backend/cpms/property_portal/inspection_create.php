<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';

if (
    !cpmsCan('inspection.create', $conn)
    && !cpmsCan('inspection.hq_report.manage', $conn)
) {
    http_response_code(403);
    exit('Access denied. This account is not allowed to create inspections.');
}

if (!isset($propertyPortalUser) || !is_array($propertyPortalUser)) {
    $propertyPortalUser = [];
}

$currentPropertyId = cpmsInspectionPropertyId($propertyPortalUser);
$selectedPropertyId = $currentPropertyId;

function cpmsInspectionCreateTableExists(mysqli $conn, string $table): bool
{
    $allowed = ['cpms_properties'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }

    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function cpmsInspectionCreateProperties(mysqli $conn): array
{
    global $propertyPortalUser;

    if (!cpmsInspectionCreateTableExists($conn, 'cpms_properties')) {
        return [];
    }

    $properties = [];
    $propertyId = (int) ($propertyPortalUser['property_id'] ?? 0);

    if ($propertyId < 1) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT id, property_code, property_name
         FROM cpms_properties
         WHERE id = ?
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $propertyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $properties[] = $row;
        }
    }

    return $properties;
}

$propertyOptions = cpmsCan('inspection.hq_report.manage', $conn)
    ? cpmsInspectionCreateProperties($conn)
    : [];

if ($currentPropertyId < 1) {
    http_response_code(403);
    exit('Property context is unavailable.');
}

if (!cpmsInspectionTablesReady($conn)) {
    exit('Inspection tables are not ready. Import Sprint 2.1 SQL first.');
}

$currentUserId = cpmsInspectionCurrentUserId($propertyPortalUser);
$currentUserName = cpmsInspectionCurrentUserName($propertyPortalUser);

$pageTitle = 'Create Inspection';
$activeMenu = 'inspection';
$errors = [];

$data = [
    'inspection_date' => date('Y-m-d'),
    'inspection_type' => 'General Inspection',
    'category' => 'General',
    'location' => '',
    'priority' => 'Medium',
    'description' => '',
    'finding' => '',
    'recommendation' => '',
    'status' => 'Draft',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($data as $key => $value) {
        if (isset($_POST[$key])) {
            $data[$key] = trim((string) $_POST[$key]);
        }
    }

    $postedPropertyId = (int) ($_POST['property_id'] ?? $currentPropertyId);
    if ($propertyOptions) {
        $allowedPropertyIds = array_map(
            static function (array $property): int {
                return (int) ($property['id'] ?? 0);
            },
            $propertyOptions
        );
        if (in_array($postedPropertyId, $allowedPropertyIds, true)) {
            $selectedPropertyId = $postedPropertyId;
        }
    } else {
        $selectedPropertyId = $currentPropertyId;
    }

    $data['category'] = $data['inspection_type'];
    $data['priority'] = 'Medium';
    $data['status'] = 'Submitted';
    $data['description'] = '';
    $data['finding'] = '';
    $data['recommendation'] = '';

    if (!cpmsInspectionVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token is invalid. Refresh and try again.';
    }

    if ($data['location'] === '') {
        $errors[] = 'Location is required.';
    }

    if ($data['inspection_date'] === '') {
        $errors[] = 'Inspection date is required.';
    }

    if (!$errors) {
        try {
            $inspectionId = cpmsInspectionCreate(
                $conn,
                $selectedPropertyId,
                [
                    'inspection_date' => $data['inspection_date'],
                    'inspection_type' => $data['inspection_type'],
                    'category' => $data['category'],
                    'location' => $data['location'],
                    'priority' => $data['priority'],
                    'status' => $data['status'],
                    'description' => $data['description'],
                    'finding' => $data['finding'],
                    'recommendation' => $data['recommendation'],
                    'reported_by_id' => $currentUserId,
                    'reported_by_name' => $currentUserName,
                ]
            );

            header('Location: inspection_view.php?id=' . $inspectionId . '&created=1');
            exit;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/inspection-module.css">

<div class="inspection-wrap inspection-form-width">
    <div class="inspection-page-head">
        <div>
            <span class="inspection-eyebrow">HQ QUICK CREATE</span>
            <h1>Create Inspection</h1>
            <p>Pilih property, tarikh, inspection type dan lokasi sahaja. Detail finding boleh diisi selepas rekod dibuat.</p>
        </div>
        <a class="inspection-btn" href="<?php echo isset($_GET['source']) && $_GET['source'] === 'hq' ? 'hq_inspection_inbox.php' : 'inspections.php'; ?>">Back</a>
    </div>

    <?php if ($errors): ?>
        <div class="inspection-alert inspection-alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?php echo cpmsInspectionEscape($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="inspection-panel inspection-form">
        <input type="hidden" name="csrf_token"
               value="<?php echo cpmsInspectionEscape(
                   cpmsInspectionCsrfToken()
               ); ?>">

        <div class="inspection-form-grid">
            <?php if ($propertyOptions): ?>
            <label class="inspection-full">
                <span>Property *</span>
                <select name="property_id" required>
                    <?php foreach ($propertyOptions as $property): ?>
                        <?php $propertyId = (int) ($property['id'] ?? 0); ?>
                        <option value="<?php echo $propertyId; ?>"
                            <?php echo $selectedPropertyId === $propertyId ? 'selected' : ''; ?>>
                            <?php echo cpmsInspectionEscape(
                                (string) ($property['property_name'] ?? 'Property')
                                . ' (' . (string) ($property['property_code'] ?? $propertyId) . ')'
                            ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php else: ?>
                <input type="hidden" name="property_id" value="<?php echo (int) $currentPropertyId; ?>">
            <?php endif; ?>

            <label>
                <span>Inspection Date *</span>
                <input type="date" name="inspection_date" required
                       value="<?php echo cpmsInspectionEscape(
                           $data['inspection_date']
                       ); ?>">
            </label>

            <label>
                <span>Inspection Type</span>
                <select name="inspection_type">
                    <?php foreach ([
                        'General Inspection',
                        'Building Inspection',
                        'Fire Safety',
                        'Electrical',
                        'Plumbing',
                        'Housekeeping',
                        'Security',
                        'Landscape',
                        'Asset Inspection',
                        'Compliance Audit',
                    ] as $option): ?>
                        <option value="<?php echo cpmsInspectionEscape($option); ?>"
                            <?php echo $data['inspection_type'] === $option
                                ? 'selected'
                                : ''; ?>>
                            <?php echo cpmsInspectionEscape($option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="inspection-full">
                <span>Location *</span>
                <input type="text" name="location" required maxlength="190"
                       placeholder="Example: Block B, Level 3 corridor / Common Area / Pump Room"
                       value="<?php echo cpmsInspectionEscape(
                           $data['location']
                       ); ?>">
            </label>
        </div>

        <div class="inspection-form-actions">
            <button class="inspection-btn inspection-btn-primary"
                    type="submit" name="status" value="Submitted">
                Create Inspection
            </button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
