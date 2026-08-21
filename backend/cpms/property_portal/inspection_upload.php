<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';

if (!isset($propertyPortalUser) || !is_array($propertyPortalUser)) {
    $propertyPortalUser = [];
}

$currentPropertyId = cpmsInspectionPropertyId($propertyPortalUser);

if ($currentPropertyId < 1) {
    http_response_code(403);
    exit('Property context is unavailable.');
}

if (!cpmsInspectionTablesReady($conn)) {
    exit('Inspection tables are not ready. Import Sprint 2.1 SQL first.');
}

$currentUserId = cpmsInspectionCurrentUserId($propertyPortalUser);
$currentUserName = cpmsInspectionCurrentUserName($propertyPortalUser);

$inspectionId = (int) ($_GET['id'] ?? $_POST['inspection_id'] ?? 0);
$record = cpmsInspectionFind($conn, $currentPropertyId, $inspectionId);

if (!$record) {
    http_response_code(404);
    exit('Inspection record not found.');
}

$pageTitle = 'Upload Inspection Photos';
$activeMenu = 'inspection';
$errors = [];
$successCount = 0;
$currentCount = cpmsInspectionCountImages(
    $conn,
    $currentPropertyId,
    $inspectionId
);
$remaining = max(0, 30 - $currentCount);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsInspectionVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token is invalid. Refresh and try again.';
    }

    $label = trim((string) ($_POST['image_type'] ?? 'Finding'));
    $caption = trim((string) ($_POST['caption'] ?? ''));

    $files = $_FILES['photos'] ?? null;

    if (!$files || !isset($files['name']) || !is_array($files['name'])) {
        $errors[] = 'Select at least one image.';
    }

    if (!$errors) {
        $selectedCount = count($files['name']);

        if ($selectedCount > $remaining) {
            $errors[] = 'You can upload only ' . $remaining
                . ' more image(s) for this inspection.';
        } else {
            for ($index = 0; $index < $selectedCount; $index++) {
                if ((string) $files['name'][$index] === '') {
                    continue;
                }

                $file = [
                    'name' => $files['name'][$index],
                    'type' => $files['type'][$index] ?? '',
                    'tmp_name' => $files['tmp_name'][$index] ?? '',
                    'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$index] ?? 0,
                ];

                try {
                    cpmsInspectionStoreImage(
                        $conn,
                        $currentPropertyId,
                        $inspectionId,
                        $file,
                        dirname(__DIR__) . '/uploads/inspections',
                        $label,
                        $caption,
                        $currentUserId,
                        $currentUserName,
                        30
                    );
                    $successCount++;
                } catch (Throwable $exception) {
                    $errors[] = $file['name'] . ': '
                        . $exception->getMessage();
                }
            }
        }
    }

    $currentCount = cpmsInspectionCountImages(
        $conn,
        $currentPropertyId,
        $inspectionId
    );
    $remaining = max(0, 30 - $currentCount);
}

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/inspection-module.css">

<div class="inspection-wrap inspection-form-width">
    <div class="inspection-page-head">
        <div>
            <span class="inspection-eyebrow">PHOTO EVIDENCE</span>
            <h1>Upload Inspection Photos</h1>
            <p>
                <?php echo cpmsInspectionEscape(
                    (string) $record['inspection_no']
                ); ?>
                · <?php echo $currentCount; ?>/30 uploaded
            </p>
        </div>
        <a class="inspection-btn"
           href="inspection_view.php?id=<?php echo $inspectionId; ?>">
            Back to Report
        </a>
    </div>

    <?php if ($successCount > 0): ?>
        <div class="inspection-alert inspection-alert-success">
            <?php echo $successCount; ?> image(s) uploaded successfully.
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="inspection-alert inspection-alert-error">
            <?php foreach ($errors as $error): ?>
                <div><?php echo cpmsInspectionEscape($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($remaining < 1): ?>
        <div class="inspection-alert inspection-alert-warning">
            Maximum 30 images reached for this inspection.
        </div>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data"
              class="inspection-panel inspection-form"
              id="inspection-upload-form">
            <input type="hidden" name="csrf_token"
                   value="<?php echo cpmsInspectionEscape(
                       cpmsInspectionCsrfToken()
                   ); ?>">
            <input type="hidden" name="inspection_id"
                   value="<?php echo $inspectionId; ?>">

            <div class="inspection-form-grid">
                <label>
                    <span>Photo Label</span>
                    <select name="image_type" required>
                        <option>Finding</option>
                        <option>Before Repair</option>
                        <option>During Repair</option>
                        <option>After Repair</option>
                        <option>Evidence</option>
                        <option>Other</option>
                    </select>
                </label>

                <label>
                    <span>Caption for selected photos</span>
                    <input type="text" name="caption" maxlength="255"
                           placeholder="Example: Water leak at Block B">
                </label>

                <label class="inspection-full inspection-dropzone">
                    <span>Select Photos</span>
                    <input id="inspection-photos" type="file"
                           name="photos[]" multiple required
                           accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <strong>Choose up to <?php echo $remaining; ?> image(s)</strong>
                    <small>
                        Maximum 30 per inspection · 8 MB each · JPG, PNG or WebP
                    </small>
                </label>
            </div>

            <div id="inspection-preview" class="inspection-preview"></div>

            <div class="inspection-form-actions">
                <button class="inspection-btn inspection-btn-primary"
                        type="submit">
                    Upload Photos
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script src="assets/inspection-upload.js"></script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
