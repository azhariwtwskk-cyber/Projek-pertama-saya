<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/staff_pwa_bootstrap.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/inspection_service.php';
require_once __DIR__ . '/cpms/includes/inspection_finding_service.php';
require_once __DIR__ . '/cpms/includes/corrective_action_service.php';
require_once __DIR__ . '/cpms/includes/inspection_assignment_service.php';

if (empty($_SESSION['staff_id'])) {
    header('Location: cpms/login.php');
    exit();
}
cpmsRequire('inspection.action.rectify', $conn);

$systemUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$propertyId = (int) (
    $_SESSION['cpms_property_id']
    ?? $_SESSION['staff_property_id']
    ?? 0
);
$actionId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($systemUserId < 1 || $propertyId < 1 || $actionId < 1) {
    http_response_code(403);
    exit('Invalid Staff action context.');
}

$action = cpmsAssignmentStaffAction(
    $conn,
    $propertyId,
    $systemUserId,
    $actionId
);
if (!$action) {
    http_response_code(404);
    exit('Inspection task not found or not assigned to you.');
}

$errors = [];
$message = '';
$isAjaxImageUpload = (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && trim((string) ($_POST['operation'] ?? '')) === 'image'
    && (string) ($_POST['ajax_upload'] ?? '') === '1'
);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsActionVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi borang tamat. Sila muat semula halaman.';
    } elseif (in_array((string) $action['status'], ['Verified', 'Closed'], true)) {
        $errors[] = 'Tugasan ini telah disahkan dan tidak boleh diubah.';
    } else {
        $operation = trim((string) ($_POST['operation'] ?? ''));
        try {
            if ($operation === 'start') {
                cpmsAssignmentStaffUpdateStatus(
                    $conn,
                    $propertyId,
                    $systemUserId,
                    $actionId,
                    'In Progress',
                    trim((string) ($action['rectification_notes'] ?? ''))
                );
                $message = 'Tugasan dimulakan. Sila upload bukti kerja.';
            } elseif ($operation === 'submit') {
                cpmsAssignmentStaffUpdateStatus(
                    $conn,
                    $propertyId,
                    $systemUserId,
                    $actionId,
                    'Rectified',
                    trim((string) ($_POST['notes'] ?? ''))
                );
                $message = 'Pembaikan dihantar kepada Supervisor untuk semakan.';
            } elseif ($operation === 'image') {
                if ((string) $action['status'] === 'Rectified') {
                    throw new RuntimeException(
                        'Tugasan sedang disemak. Tunggu keputusan Supervisor.'
                    );
                }
                $saved = cpmsAssignmentStoreImages(
                    $conn,
                    $propertyId,
                    $action,
                    $_FILES['images'] ?? [],
                    trim((string) ($_POST['phase'] ?? 'Evidence')),
                    trim((string) ($_POST['caption'] ?? '')),
                    __DIR__ . '/cpms/uploads/inspection_actions',
                    (int) ($_POST['source_image_id'] ?? 0)
                );
                $message = $saved . ' gambar bukti berjaya dimuat naik.';
            } else {
                throw new RuntimeException('Operasi tidak dikenali.');
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
    $action = cpmsAssignmentStaffAction(
        $conn,
        $propertyId,
        $systemUserId,
        $actionId
    );

    if ($isAjaxImageUpload) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($errors ? 422 : 200);
        echo json_encode(
            [
                'success' => !$errors,
                'saved' => (int) ($saved ?? 0),
                'message' => $errors[0] ?? $message,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

$originalImages = (int) ($action['finding_id'] ?? 0) > 0
    ? cpmsAssignmentOriginalImages(
        $conn,
        $propertyId,
        (int) $action['inspection_id'],
        (int) $action['finding_id']
    )
    : [];
$images = cpmsActionImages($conn, $propertyId, $actionId);
$pairedSourceIds = [];
foreach ($images as $image) {
    if (
        (string) ($image['image_phase'] ?? '') === 'After'
        && (int) ($image['source_inspection_image_id'] ?? 0) > 0
    ) {
        $pairedSourceIds[(int) $image['source_inspection_image_id']] = true;
    }
}
$pairSummary = cpmsAssignmentPhotoPairSummary(
    $conn,
    $propertyId,
    $actionId
);
$defaultSourceImageId = 0;
foreach ($originalImages as $sourceImage) {
    $candidateId = (int) ($sourceImage['id'] ?? 0);
    if ($candidateId > 0 && !isset($pairedSourceIds[$candidateId])) {
        $defaultSourceImageId = $candidateId;
        break;
    }
}
if ($defaultSourceImageId < 1 && !empty($originalImages)) {
    $defaultSourceImageId = (int) ($originalImages[0]['id'] ?? 0);
}
$counts = cpmsAssignmentEvidenceCounts($conn, $propertyId, $actionId);
$progress = cpmsAssignmentProgress($conn, $propertyId, $actionId);
$branding = cpmsStaffPwaBranding($conn);
$locked = in_array((string) $action['status'], ['Rectified', 'Verified', 'Closed'], true);

function staffActionViewE(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ms"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo staffActionViewE((string) $action['action_no']); ?> | CPMS Staff</title>
<link rel="stylesheet" href="css/pms.css?v=6">
<style>
.ca-page{max-width:1080px;margin:0 auto;padding:24px}.ca-back{display:inline-block;background:#4a2b20;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;margin-bottom:16px;font-weight:800}.ca-panel{background:#fff;border:1px solid #e5ded7;border-radius:16px;padding:22px;margin-bottom:16px}.ca-panel h1,.ca-panel h2{color:#4a2b20;margin-top:0}.ca-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.ca-field{background:#f8f5f2;padding:14px;border-radius:10px}.ca-field span{display:block;color:#735e55;font-size:13px;margin-bottom:5px}.ca-form label{display:block;font-weight:800;margin:12px 0 6px}.ca-form select,.ca-form textarea,.ca-form input[type=file],.ca-form input[type=text]{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d9cec6;border-radius:9px;background:#fff}.ca-form textarea{min-height:120px}.ca-button{border:0;border-radius:9px;background:#b99a16;color:#fff;padding:12px 18px;font-weight:800;margin-top:14px;cursor:pointer}.ca-button-primary{background:#4a2b20}.ca-button-submit{background:#08783d}.ca-alert{padding:13px 15px;border-radius:9px;margin-bottom:12px}.ca-error{background:#fee2e2;color:#991b1b}.ca-success{background:#dcfce7;color:#08783d}.ca-notice{background:#fff7ed;color:#9a3412}.ca-photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.ca-photo{border:1px solid #e5ded7;border-radius:12px;overflow:hidden}.ca-photo img{display:block;width:100%;height:260px;object-fit:cover}.ca-photo div{padding:10px;font-size:13px}.ca-source{border-left:5px solid #f97316}.ca-counts{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.ca-counts span{background:#eee8e2;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:800}.ca-history{border-left:3px solid #d9cec6;padding:0 0 14px 14px;margin-left:6px}.ca-history small{color:#735e55}.ca-file-note{font-size:13px;color:#735e55}.ca-workflow{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.ca-step{padding:11px;border-radius:9px;background:#eee8e2;color:#735e55}.ca-step.done{background:#dcfce7;color:#08783d}.ca-step.current{background:#dbeafe;color:#174ea6}.ca-step strong{display:block}@media(max-width:700px){.ca-page{padding:14px}.ca-grid,.ca-workflow{grid-template-columns:1fr}.ca-photos,.source-picker{grid-template-columns:1fr}.ca-photo img,.source-option img{height:auto}}
.source-picker{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin:12px 0}.source-option{display:block;border:2px solid #d9cec6;border-radius:12px;overflow:hidden;background:#fff;cursor:pointer;position:relative}.source-option input{position:absolute;top:10px;left:10px;width:20px;height:20px;accent-color:#08783d}.source-option img{display:block;width:100%;height:220px;object-fit:cover}.source-option .source-label{padding:10px;font-size:12px}.source-option .pair-status{display:inline-block;margin-top:5px;padding:4px 7px;border-radius:999px;background:#fff2c7;color:#725a00;font-weight:800}.source-option .pair-status.done{background:#dcfce7;color:#08783d}.source-option:has(input:checked){border-color:#08783d;box-shadow:0 0 0 3px #dcfce7}.pair-summary{padding:12px;border-radius:10px;background:#eef6ff;color:#174ea6;margin:10px 0}.pair-summary.complete{background:#dcfce7;color:#08783d}
</style>
<?php echo cpmsStaffPwaHead($branding); ?><?php echo cpmsStaffPwaStyle($branding); ?>
    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head><body class="pms-body"><main class="ca-page">
<a class="ca-back" href="staff_corrective_actions.php">← Inspection Tasks</a>
<?php foreach ($errors as $error): ?><div class="ca-alert ca-error"><?php echo staffActionViewE($error); ?></div><?php endforeach; ?>
<?php if ($message !== ''): ?><div class="ca-alert ca-success"><?php echo staffActionViewE($message); ?></div><?php endif; ?>
<?php if ((string) $action['supervisor_status'] === 'Rejected'): ?><div class="ca-alert ca-error"><strong>Pembaikan ditolak oleh Supervisor/HQ dan perlu dibuat semula.</strong><br><?php echo nl2br(staffActionViewE((string) ($action['supervisor_remarks'] ?: 'Sila semak semula bukti dan arahan kerja.'))); ?></div><?php endif; ?>

<section class="ca-panel"><h2>Workflow</h2><div class="ca-workflow"><div class="ca-step done"><strong>1. Assigned</strong>Tugasan diterima</div><div class="ca-step <?php echo (string) $action['status'] === 'Open' ? 'current' : 'done'; ?>"><strong>2. Repair</strong>Upload bukti kerja</div><div class="ca-step <?php echo (string) $action['status'] === 'Rectified' ? 'current' : (in_array((string) $action['status'], ['Verified', 'Closed'], true) ? 'done' : ''); ?>"><strong>3. Supervisor</strong><?php echo staffActionViewE((string) $action['supervisor_status']); ?></div><div class="ca-step <?php echo in_array((string) $action['status'], ['Verified', 'Closed'], true) ? 'done' : ''; ?>"><strong>4. HQ</strong><?php echo in_array((string) $action['status'], ['Verified', 'Closed'], true) ? 'Verified' : 'Waiting'; ?></div></div></section>

<section class="ca-panel"><h1><?php echo staffActionViewE((string) $action['title']); ?></h1><div class="ca-grid"><div class="ca-field"><span>Rujukan</span><strong><?php echo staffActionViewE((string) $action['action_no']); ?></strong></div><div class="ca-field"><span>Status</span><strong><?php echo staffActionViewE((string) $action['status']); ?></strong></div><div class="ca-field"><span>Due Date</span><strong><?php echo staffActionViewE((string) ($action['due_date'] ?: '-')); ?></strong></div><div class="ca-field"><span>Inspection</span><strong><?php echo staffActionViewE((string) $action['inspection_no']); ?></strong></div><div class="ca-field"><span>Priority</span><strong><?php echo staffActionViewE((string) $action['priority']); ?></strong></div><div class="ca-field"><span>Supervisor Review</span><strong><?php echo staffActionViewE((string) $action['supervisor_status']); ?></strong></div></div><h2 style="margin-top:20px">Arahan Kerja</h2><p><?php echo nl2br(staffActionViewE((string) $action['description'])); ?></p></section>

<section class="ca-panel ca-source"><h2><?php echo (int) ($action['finding_id'] ?? 0) > 0 ? 'Finding & Gambar Asal HQ' : 'Legacy Inspection Action'; ?></h2><div class="ca-grid"><div class="ca-field"><span>Finding</span><strong><?php echo staffActionViewE((string) $action['finding_name']); ?></strong></div><div class="ca-field"><span>Category</span><strong><?php echo staffActionViewE((string) $action['finding_category']); ?></strong></div><div class="ca-field"><span>Severity / Lokasi</span><strong><?php echo staffActionViewE((string) $action['finding_severity']); ?> · <?php echo staffActionViewE((string) $action['finding_location']); ?></strong></div></div><?php if (trim((string) ($action['finding_remarks'] ?? '')) !== ''): ?><h3>Inspector Remarks</h3><p><?php echo nl2br(staffActionViewE((string) $action['finding_remarks'])); ?></p><?php endif; ?><?php if (trim((string) ($action['finding_recommendation'] ?? '')) !== ''): ?><h3>Recommendation</h3><p><?php echo nl2br(staffActionViewE((string) $action['finding_recommendation'])); ?></p><?php endif; ?><div class="ca-photos"><?php foreach ($originalImages as $image): ?><article class="ca-photo"><a href="cpms/<?php echo staffActionViewE((string) $image['image_path']); ?>" target="_blank" rel="noopener"><img src="cpms/<?php echo staffActionViewE((string) $image['image_path']); ?>" alt="Original HQ finding"></a><div><?php echo staffActionViewE((string) ($image['caption'] ?: $image['original_name'])); ?></div></article><?php endforeach; ?></div></section>

<?php if (!$locked): ?>
<?php if ((string) $action['status'] === 'Open'): ?><section class="ca-panel"><h2>Mulakan Tugasan</h2><p>Tekan Start Work sebelum menjalankan pembaikan.</p><form method="post"><input type="hidden" name="csrf_token" value="<?php echo staffActionViewE(cpmsActionCsrfToken()); ?>"><input type="hidden" name="id" value="<?php echo $actionId; ?>"><input type="hidden" name="operation" value="start"><button class="ca-button ca-button-primary" type="submit">Start Work</button></form></section><?php endif; ?>
<section class="ca-panel">
    <h2>Muat Naik Bukti Kerja</h2>
    <p>
        Pilih sehingga 30 gambar sekali. Sistem akan upload secara automatik
        dalam beberapa kumpulan kecil supaya lebih stabil di telefon dan cPanel.
    </p>
    <form id="evidenceUploadForm" class="ca-form" method="post"
          enctype="multipart/form-data">
        <input type="hidden" name="csrf_token"
               value="<?php echo staffActionViewE(cpmsActionCsrfToken()); ?>">
        <input type="hidden" name="id" value="<?php echo $actionId; ?>">
        <input type="hidden" name="operation" value="image">

        <?php if ((int) ($action['finding_id'] ?? 0) > 0): ?>
            <div class="pair-summary <?php echo (int) $pairSummary['remaining'] === 0 && (int) $pairSummary['required'] > 0 ? 'complete' : ''; ?>">
                Padanan selesai: <strong><?php echo (int) $pairSummary['completed']; ?> / <?php echo (int) $pairSummary['required']; ?></strong> gambar Inspector.
                <?php if ((int) $pairSummary['remaining'] > 0): ?>
                    Pilih setiap gambar Inspector dan upload gambar After yang sepadan.
                <?php else: ?>
                    Semua gambar Inspector sudah mempunyai bukti After.
                <?php endif; ?>
            </div>
            <label>1. Pilih gambar Inspector yang telah dibaiki</label>
            <div class="source-picker">
                <?php foreach ($originalImages as $sourceImage): ?>
                    <?php
                    $sourceId = (int) ($sourceImage['id'] ?? 0);
                    $isPaired = isset($pairedSourceIds[$sourceId]);
                    ?>
                    <label class="source-option">
                        <input type="radio" name="source_image_id"
                               value="<?php echo $sourceId; ?>"
                               <?php echo $sourceId === $defaultSourceImageId ? 'checked' : ''; ?>
                               required>
                        <img src="cpms/<?php echo staffActionViewE((string) $sourceImage['image_path']); ?>"
                             alt="Gambar asal Inspector">
                        <span class="source-label">
                            <?php echo staffActionViewE((string) ($sourceImage['caption'] ?: $sourceImage['original_name'])); ?><br>
                            <span class="pair-status <?php echo $isPaired ? 'done' : ''; ?>">
                                <?php echo $isPaired ? 'After sudah ada' : 'Menunggu After'; ?>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <label for="phase">2. Jenis bukti</label>
        <select id="phase" name="phase" required>
            <option value="After">After — selepas dibersihkan/dibaiki</option>
            <option value="During">During</option>
            <option value="Evidence">Other Evidence</option>
        </select>
        <label for="images">
            3. Ambil atau pilih gambar selepas kerja<br>
            Gambar (JPG, PNG atau WebP — maksimum 8 MB setiap satu)
        </label>
        <input id="images" type="file" name="images[]"
               accept="image/jpeg,image/png,image/webp" multiple required>
        <div id="fileCount" class="ca-file-note">Belum pilih gambar.</div>
        <label for="caption">4. Catatan untuk gambar After</label>
        <input id="caption" type="text" name="caption" maxlength="255"
               placeholder="Contoh: Kebocoran ditampal dan kawasan diuji semula.">
        <button id="evidenceUploadButton" class="ca-button" type="submit">
            Upload & Padankan Gambar
        </button>
    </form>
</section>
<section class="ca-panel"><h2>Submit for Supervisor Review</h2><p>Setiap gambar asal Inspector mesti mempunyai sekurang-kurangnya satu gambar <strong>After</strong> yang dipadankan sebelum penghantaran.</p><form class="ca-form" method="post"><input type="hidden" name="csrf_token" value="<?php echo staffActionViewE(cpmsActionCsrfToken()); ?>"><input type="hidden" name="id" value="<?php echo $actionId; ?>"><input type="hidden" name="operation" value="submit"><label for="notes">Catatan kerja lengkap</label><textarea id="notes" name="notes" required placeholder="Nyatakan kerja yang dilakukan, bahan digunakan dan keputusan ujian."><?php echo staffActionViewE((string) ($action['rectification_notes'] ?? '')); ?></textarea><button class="ca-button ca-button-submit" type="submit" onclick="return confirm('Hantar pembaikan ini kepada Supervisor?');">Submit Rectified</button></form></section>
<?php else: ?><div class="ca-alert ca-notice"><?php echo (string) $action['status'] === 'Rectified' ? 'Tugasan telah dihantar dan sedang menunggu semakan Supervisor/HQ.' : 'Tugasan telah disahkan dan rekod kini dikunci.'; ?><?php if (trim((string) ($action['supervisor_remarks'] ?? '')) !== ''): ?><br><strong>Supervisor:</strong> <?php echo staffActionViewE((string) $action['supervisor_remarks']); ?><?php endif; ?></div><?php endif; ?>

<section class="ca-panel"><h2>Bukti Kerja (<?php echo count($images); ?>)</h2><div class="ca-counts"><span>Before <?php echo $counts['Before']; ?></span><span>During <?php echo $counts['During']; ?></span><span>After <?php echo $counts['After']; ?></span><span>Other <?php echo $counts['Evidence']; ?></span></div><?php if (!$images): ?><p>Belum ada bukti kerja.</p><?php else: ?><div class="ca-photos"><?php foreach ($images as $image): ?><article class="ca-photo"><a href="cpms/<?php echo staffActionViewE((string) $image['image_path']); ?>" target="_blank" rel="noopener"><img src="cpms/<?php echo staffActionViewE((string) $image['image_path']); ?>" alt="Work evidence"></a><div><strong><?php echo staffActionViewE((string) $image['image_phase']); ?></strong><br><?php echo staffActionViewE((string) ($image['caption'] ?: 'Tiada catatan')); ?><br><small><?php echo staffActionViewE((string) $image['created_at']); ?></small></div></article><?php endforeach; ?></div><?php endif; ?></section>

<section class="ca-panel"><h2>Progress History</h2><?php if (!$progress): ?><p>Belum ada sejarah kemajuan.</p><?php endif; ?><?php foreach ($progress as $event): ?><div class="ca-history"><strong><?php echo staffActionViewE(ucwords(str_replace('_', ' ', (string) $event['event_type']))); ?></strong> · <?php echo staffActionViewE((string) $event['actor_name']); ?><br><small><?php echo staffActionViewE((string) $event['created_at']); ?><?php if ($event['new_status']): ?> · <?php echo staffActionViewE((string) $event['old_status']); ?> → <?php echo staffActionViewE((string) $event['new_status']); ?><?php endif; ?></small><?php if (trim((string) ($event['notes'] ?? '')) !== ''): ?><p><?php echo nl2br(staffActionViewE((string) $event['notes'])); ?></p><?php endif; ?></div><?php endforeach; ?></section>
</main><script>
(function () {
    'use strict';

    var form = document.getElementById('evidenceUploadForm');
    var input = document.getElementById('images');
    var count = document.getElementById('fileCount');
    var button = document.getElementById('evidenceUploadButton');
    var maximumFiles = 30;
    var maximumFileBytes = 8 * 1024 * 1024;
    var maximumBatchBytes = 20 * 1024 * 1024;
    var maximumBatchFiles = 5;

    if (!form || !input || !count || !button) return;

    function selectedFiles() {
        return Array.prototype.slice.call(input.files || []);
    }

    function validateFiles(files) {
        if (!files.length) {
            return 'Sila pilih sekurang-kurangnya satu gambar.';
        }
        if (files.length > maximumFiles) {
            return 'Maksimum 30 gambar bagi setiap pilihan.';
        }
        for (var index = 0; index < files.length; index += 1) {
            if (files[index].size > maximumFileBytes) {
                return files[index].name + ' melebihi had 8 MB.';
            }
        }
        return '';
    }

    function buildBatches(files) {
        var batches = [];
        var batch = [];
        var batchBytes = 0;

        files.forEach(function (file) {
            if (
                batch.length
                && (
                    batch.length >= maximumBatchFiles
                    || batchBytes + file.size > maximumBatchBytes
                )
            ) {
                batches.push(batch);
                batch = [];
                batchBytes = 0;
            }
            batch.push(file);
            batchBytes += file.size;
        });

        if (batch.length) batches.push(batch);
        return batches;
    }

    input.addEventListener('change', function () {
        var files = selectedFiles();
        var error = validateFiles(files);
        count.textContent = error || (
            files.length
                ? files.length + ' gambar dipilih dan sedia untuk upload.'
                : 'Belum pilih gambar.'
        );
    });

    form.addEventListener('submit', function (event) {
        var files = selectedFiles();
        var error = validateFiles(files);
        if (error) {
            event.preventDefault();
            count.textContent = error;
            return;
        }
        if (!window.fetch || !window.FormData || !window.Promise) {
            if (files.length > 10) {
                event.preventDefault();
                count.textContent = (
                    'Browser ini tidak menyokong upload automatik. '
                    + 'Pilih maksimum 10 gambar dahulu.'
                );
            }
            return;
        }

        event.preventDefault();
        button.disabled = true;
        button.textContent = 'Uploading...';

        var batches = buildBatches(files);
        var uploaded = 0;
        var sequence = Promise.resolve();

        batches.forEach(function (batch, batchIndex) {
            sequence = sequence.then(function () {
                var data = new FormData();
                data.append('csrf_token', form.elements.csrf_token.value);
                data.append('id', form.elements.id.value);
                data.append('operation', 'image');
                data.append('ajax_upload', '1');
                data.append('phase', form.elements.phase.value);
                data.append('caption', form.elements.caption.value);
                data.append(
                    'source_image_id',
                    form.elements.source_image_id
                        ? form.elements.source_image_id.value
                        : '0'
                );
                batch.forEach(function (file) {
                    data.append('images[]', file, file.name);
                });

                count.textContent = (
                    'Uploading kumpulan ' + (batchIndex + 1)
                    + ' daripada ' + batches.length + '...'
                );

                return fetch(window.location.href, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                }).then(function (response) {
                    return response.json().catch(function () {
                        throw new Error('Respons server tidak sah.');
                    }).then(function (result) {
                        if (!response.ok || !result.success) {
                            throw new Error(
                                result.message || 'Upload gagal.'
                            );
                        }
                        uploaded += Number(result.saved || batch.length);
                        count.textContent = (
                            uploaded + ' daripada ' + files.length
                            + ' gambar berjaya dimuat naik.'
                        );
                    });
                });
            });
        });

        sequence.then(function () {
            count.textContent = (
                uploaded + ' gambar berjaya dimuat naik. Memuat semula...'
            );
            window.setTimeout(function () {
                window.location.reload();
            }, 700);
        }).catch(function (uploadError) {
            count.textContent = (
                uploaded + ' gambar telah disimpan. Gagal meneruskan: '
                + uploadError.message
            );
            button.disabled = false;
            button.textContent = 'Sambung Upload';
        });
    });
}());
</script></body></html>
