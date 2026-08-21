<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';
require_once dirname(__DIR__) . '/includes/inspection_finding_service.php';
require_once dirname(__DIR__) . '/includes/corrective_action_service.php';
require_once dirname(__DIR__) . '/includes/inspection_assignment_service.php';

cpmsRequire('inspection.view', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$actionId = (int) ($_GET['id'] ?? $_POST['action_id'] ?? 0);
$action = cpmsActionFind($conn, $propertyId, $actionId);
if (!$action) {
    http_response_code(404);
    exit('Corrective action not found.');
}
$link = cpmsAssignmentLinkByAction($conn, $propertyId, $actionId);
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cpmsActionVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security session is invalid. Please reload this page.';
    } elseif (isset($_POST['pair_legacy_images'])) {
        cpmsRequire('inspection.action.verify', $conn);
        $transactionStarted = false;
        try {
            $mappings = $_POST['source_image_ids'] ?? [];
            if (!is_array($mappings) || !$mappings) {
                throw new InvalidArgumentException(
                    'Pilih gambar Before untuk setiap gambar After lama.'
                );
            }
            if (count($mappings) > 300) {
                throw new InvalidArgumentException(
                    'Maksimum 300 padanan dibenarkan bagi satu tindakan.'
                );
            }

            $conn->begin_transaction();
            $transactionStarted = true;
            $pairedTotal = 0;
            foreach ($mappings as $actionImageId => $sourceImageId) {
                cpmsAssignmentPairLegacyAfterImage(
                    $conn,
                    $propertyId,
                    $actionId,
                    (int) $actionImageId,
                    (int) $sourceImageId
                );
                $pairedTotal++;
            }
            $conn->commit();
            $transactionStarted = false;
            $success = $pairedTotal
                . ' gambar After lama berjaya dipadankan.';
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            $errors[] = $exception->getMessage();
        }
    } elseif (isset($_POST['supervisor_review'])) {
        cpmsRequire('inspection.action.verify', $conn);
        try {
            $decision = trim((string) ($_POST['decision'] ?? ''));
            if ($decision === 'Approved' && $link) {
                if (!cpmsAssignmentPhotoPairingReady($conn)) {
                    throw new RuntimeException(
                        'Jalankan migration 20260810_0062 sebelum Approve.'
                    );
                }
                $pairSummary = cpmsAssignmentPhotoPairSummary(
                    $conn,
                    $propertyId,
                    $actionId
                );
                if ((int) $pairSummary['remaining'] > 0) {
                    throw new RuntimeException(
                        'Tidak boleh Approve: masih ada '
                        . (int) $pairSummary['remaining']
                        . ' gambar Inspector tanpa gambar After.'
                    );
                }
            }
            cpmsAssignmentSupervisorReview(
                $conn,
                $propertyId,
                $actionId,
                $decision,
                trim((string) ($_POST['remarks'] ?? ''))
            );
            $success = $decision === 'Approved'
                ? 'Pembaikan diluluskan dan dihantar ke queue HQ Inspector.'
                : 'Pembaikan ditolak dan dikembalikan kepada staff sebagai In Progress.';
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    } elseif (isset($_POST['legacy_update'])) {
        cpmsRequire('inspection.action.manage', $conn);
        try {
            $status = trim((string) ($_POST['status'] ?? ''));
            if (in_array($status, ['Verified', 'Closed'], true)) {
                cpmsRequire('inspection.action.verify', $conn);
            }
            if (!cpmsActionUpdateStatus(
                $conn,
                $propertyId,
                $actionId,
                $status,
                trim((string) ($_POST['notes'] ?? ''))
            )) {
                throw new RuntimeException('Status gagal dikemas kini.');
            }
            $success = 'Corrective Action berjaya dikemas kini.';
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
    $action = cpmsActionFind($conn, $propertyId, $actionId);
    $link = cpmsAssignmentLinkByAction($conn, $propertyId, $actionId);
}

$images = cpmsActionImages($conn, $propertyId, $actionId);
$afterImagesBySource = [];
$unpairedAfterImages = [];
foreach ($images as $image) {
    if ((string) ($image['image_phase'] ?? '') !== 'After') {
        continue;
    }
    $sourceImageId = (int) ($image['source_inspection_image_id'] ?? 0);
    if ($sourceImageId > 0) {
        $afterImagesBySource[$sourceImageId][] = $image;
    } else {
        $unpairedAfterImages[] = $image;
    }
}
$originalImages = $link
    ? cpmsAssignmentOriginalImages(
        $conn,
        $propertyId,
        (int) $link['inspection_id'],
        (int) $link['finding_id']
    )
    : [];
$progress = cpmsAssignmentProgress($conn, $propertyId, $actionId);
$counts = cpmsAssignmentEvidenceCounts($conn, $propertyId, $actionId);
$pageTitle = 'Corrective Action ' . (string) $action['action_no'];
$activeMenu = 'hq_inspection';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.ca-wrap{max-width:1150px;margin:0 auto;padding:24px}.ca-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:22px;margin-bottom:18px}.ca-head{display:flex;justify-content:space-between;gap:15px;align-items:start}.ca-head-actions{display:flex;gap:8px;flex-wrap:wrap}.ca-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.ca-grid div{background:#f8fafc;padding:13px;border-radius:10px}.ca-grid span{display:block;color:#64748b;font-size:12px;margin-bottom:4px}.ca-form{display:grid;gap:12px}.ca-form textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:9px}.ca-btn{display:inline-flex;padding:10px 14px;border:0;border-radius:9px;background:#173b73;color:#fff;text-decoration:none;font-weight:800;cursor:pointer}.ca-btn.secondary{background:#e2e8f0;color:#334155}.ca-btn.approve{background:#166534}.ca-btn.reject{background:#b91c1c}.ca-alert{padding:12px;border-radius:9px;margin:12px 0;background:#dcfce7;color:#166534}.ca-error{background:#fee2e2;color:#991b1b}.ca-photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.ca-photos figure{margin:0;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}.ca-photos img{display:block;width:100%;height:180px;object-fit:cover}.ca-photos figcaption{padding:10px;font-size:13px}.ca-source{border-left:5px solid #f97316}.ca-pipeline{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.ca-step{padding:12px;border-radius:10px;background:#e2e8f0;color:#475569}.ca-step.done{background:#dcfce7;color:#166534}.ca-step.current{background:#dbeafe;color:#1d4ed8}.ca-step strong{display:block}.ca-history{border-left:3px solid #cbd5e1;padding:0 0 14px 14px;margin-left:6px}.ca-history small{color:#64748b}.ca-evidence-count{display:flex;gap:8px;flex-wrap:wrap}.ca-evidence-count span{padding:6px 10px;border-radius:999px;background:#e2e8f0;font-weight:800;font-size:12px}.ca-wait{padding:14px;border-radius:10px;background:#fff7ed;color:#9a3412}@media(max-width:750px){.ca-grid,.ca-pipeline{grid-template-columns:1fr}.ca-head{flex-direction:column}.ca-wrap{padding:14px}}
.ca-comparison{display:grid;grid-template-columns:1fr 1fr;gap:14px}.ca-compare-column{border:1px solid #e2e8f0;border-radius:12px;padding:12px;min-width:0}.ca-compare-column.before{border-top:5px solid #f97316}.ca-compare-column.after{border-top:5px solid #16a34a}.ca-compare-column h3{display:flex;justify-content:space-between;gap:8px}.ca-compare-column h3 span{font-size:12px;color:#64748b}.ca-compare-empty{padding:24px 10px;text-align:center;background:#f8fafc;border-radius:8px;color:#64748b}@media(max-width:750px){.ca-comparison{grid-template-columns:1fr}}
.ca-legacy-card{border-left:5px solid #b45309}.ca-legacy-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.ca-legacy-item{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fffbeb}.ca-legacy-item>img{display:block;width:100%;height:190px;object-fit:contain;background:#f1f5f9;border-radius:9px}.ca-legacy-caption{font-size:12px;color:#64748b;margin:8px 0;overflow-wrap:anywhere}.ca-map-select{display:block;width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;margin-top:6px}.ca-before-preview{margin-top:10px;padding:8px;border-radius:8px;background:#fff}.ca-before-preview span{display:block;font-size:11px;font-weight:800;color:#64748b;margin-bottom:5px}.ca-before-preview img{display:block;width:100%;height:130px;object-fit:contain;background:#f1f5f9;border-radius:7px}.ca-map-submit{margin-top:16px;background:#b45309}@media(max-width:600px){.ca-legacy-grid{grid-template-columns:1fr}}
</style>
<div class="ca-wrap">
    <div class="ca-head">
        <div><small>PROPERTY CORRECTIVE ACTION CONTROL</small><h1><?php echo cpmsActionEscape((string) $action['action_no']); ?></h1><p><?php echo cpmsActionEscape((string) $action['title']); ?></p></div>
        <div class="ca-head-actions"><a class="ca-btn secondary" href="hq_inspection_inbox.php">HQ Inbox</a><a class="ca-btn secondary" href="inspection_hq_findings.php?id=<?php echo (int) $action['inspection_id']; ?>">Inspection Report</a></div>
    </div>
    <?php if (isset($_GET['created'])): ?><div class="ca-alert">Finding berjaya diagihkan kepada <?php echo cpmsActionEscape((string) $action['assigned_name']); ?>.</div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="ca-alert"><?php echo cpmsActionEscape($success); ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="ca-alert ca-error"><?php echo cpmsActionEscape($error); ?></div><?php endforeach; ?>

    <?php if ($link): ?>
    <?php
    $staffDone = in_array((string) $action['status'], ['Rectified', 'Verified', 'Closed'], true);
    $supervisorDone = (string) $link['supervisor_status'] === 'Approved';
    $hqDone = in_array((string) $action['status'], ['Verified', 'Closed'], true);
    ?>
    <section class="ca-card">
        <h2>Workflow Progress</h2>
        <div class="ca-pipeline">
            <div class="ca-step done"><strong>1. Assigned</strong>Staff/Contractor menerima tugasan</div>
            <div class="ca-step <?php echo $staffDone ? 'done' : 'current'; ?>"><strong>2. Rectification</strong>Before / During / After</div>
            <div class="ca-step <?php echo $supervisorDone ? 'done' : ((string) $link['supervisor_status'] === 'Pending Review' ? 'current' : ''); ?>"><strong>3. Supervisor</strong><?php echo cpmsActionEscape((string) $link['supervisor_status']); ?></div>
            <div class="ca-step <?php echo $hqDone ? 'done' : ($supervisorDone ? 'current' : ''); ?>"><strong>4. HQ Verification</strong><?php echo $hqDone ? 'Verified' : 'Waiting'; ?></div>
        </div>
    </section>
    <?php endif; ?>

    <section class="ca-card">
        <div class="ca-grid">
            <div><span>Staff Status</span><strong><?php echo cpmsActionEscape((string) $action['status']); ?></strong></div>
            <div><span>Supervisor Status</span><strong><?php echo cpmsActionEscape((string) ($link['supervisor_status'] ?? 'Legacy Action')); ?></strong></div>
            <div><span>Assigned To</span><strong><?php echo cpmsActionEscape((string) ($action['assigned_name'] ?: '-')); ?></strong></div>
            <div><span>Due Date</span><strong><?php echo cpmsActionEscape((string) ($action['due_date'] ?: '-')); ?></strong></div>
            <div><span>Priority</span><strong><?php echo cpmsActionEscape((string) $action['priority']); ?></strong></div>
            <div><span>Inspection</span><strong><?php echo cpmsActionEscape((string) $action['inspection_no']); ?></strong></div>
        </div>
        <h3>Required Rectification</h3><p><?php echo nl2br(cpmsActionEscape((string) $action['description'])); ?></p>
        <?php if (!empty($action['rectification_notes'])): ?><h3>Staff Work Notes</h3><p><?php echo nl2br(cpmsActionEscape((string) $action['rectification_notes'])); ?></p><?php endif; ?>
    </section>

    <?php if ($link): ?>
    <section class="ca-card ca-source">
        <h2>Original HQ Finding</h2>
        <div class="ca-grid">
            <div><span>Finding</span><strong><?php echo cpmsAssignmentEscape((string) $link['finding_name']); ?></strong></div>
            <div><span>Category</span><strong><?php echo cpmsAssignmentEscape((string) $link['finding_category']); ?></strong></div>
            <div><span>Severity / Location</span><strong><?php echo cpmsAssignmentEscape((string) $link['finding_severity']); ?> · <?php echo cpmsAssignmentEscape((string) $link['finding_location']); ?></strong></div>
        </div>
        <?php if (trim((string) ($link['finding_remarks'] ?? '')) !== ''): ?><h3>Inspector Remarks</h3><p><?php echo nl2br(cpmsAssignmentEscape((string) $link['finding_remarks'])); ?></p><?php endif; ?>
        <?php if (trim((string) ($link['finding_recommendation'] ?? '')) !== ''): ?><h3>Recommendation</h3><p><?php echo nl2br(cpmsAssignmentEscape((string) $link['finding_recommendation'])); ?></p><?php endif; ?>
        <h3>Original Photos (<?php echo count($originalImages); ?>)</h3>
        <div class="ca-photos"><?php foreach ($originalImages as $image): ?><figure><a href="../<?php echo cpmsActionEscape((string) $image['image_path']); ?>" target="_blank" rel="noopener"><img src="../<?php echo cpmsActionEscape((string) $image['image_path']); ?>" alt="Original HQ finding"></a><figcaption><?php echo cpmsActionEscape((string) ($image['caption'] ?: $image['original_name'])); ?></figcaption></figure><?php endforeach; ?></div>
    </section>
    <?php endif; ?>

    <?php if ($link): ?>
    <section class="ca-card">
        <h2>Before / After Comparison</h2>
        <p>Setiap gambar Inspector dipaparkan tepat di sebelah gambar After yang dipadankan oleh Staff.</p>
        <?php if (!$originalImages): ?><div class="ca-compare-empty">Tiada gambar Inspector.</div><?php endif; ?>
        <?php foreach ($originalImages as $photoIndex => $beforeImage): ?>
            <?php
            $sourceImageId = (int) ($beforeImage['id'] ?? 0);
            $pairedAfterImages = $afterImagesBySource[$sourceImageId] ?? [];
            ?>
            <h3>Pasangan Gambar <?php echo ($photoIndex + 1); ?></h3>
            <div class="ca-comparison">
                <div class="ca-compare-column before">
                    <h3>Before <span>Inspector/HQ</span></h3>
                    <div class="ca-photos">
                        <figure>
                            <a href="../<?php echo cpmsActionEscape((string) $beforeImage['image_path']); ?>" target="_blank" rel="noopener">
                                <img src="../<?php echo cpmsActionEscape((string) $beforeImage['image_path']); ?>" alt="Before - Inspector finding">
                            </a>
                            <figcaption><?php echo cpmsActionEscape((string) ($beforeImage['caption'] ?: $beforeImage['original_name'])); ?></figcaption>
                        </figure>
                    </div>
                </div>

                <div class="ca-compare-column after">
                    <h3>After <span>Staff/Property · <?php echo count($pairedAfterImages); ?></span></h3>
                    <?php if (!$pairedAfterImages): ?>
                        <div class="ca-compare-empty">Menunggu gambar After yang sepadan.</div>
                    <?php else: ?>
                        <div class="ca-photos">
                            <?php foreach ($pairedAfterImages as $image): ?>
                                <figure>
                                    <a href="../<?php echo cpmsActionEscape((string) $image['image_path']); ?>" target="_blank" rel="noopener">
                                        <img src="../<?php echo cpmsActionEscape((string) $image['image_path']); ?>" alt="After - rectification evidence">
                                    </a>
                                    <figcaption><?php echo cpmsActionEscape((string) ($image['caption'] ?: $image['original_name'])); ?></figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($unpairedAfterImages): ?>
            <h3>Gambar After Lama Belum Dipadankan</h3>
            <div class="ca-photos">
                <?php foreach ($unpairedAfterImages as $image): ?>
                    <figure>
                        <a href="../<?php echo cpmsActionEscape((string) $image['image_path']); ?>" target="_blank" rel="noopener">
                            <img src="../<?php echo cpmsActionEscape((string) $image['image_path']); ?>" alt="Unpaired After evidence">
                        </a>
                        <figcaption><?php echo cpmsActionEscape((string) ($image['caption'] ?: $image['original_name'])); ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($link && $unpairedAfterImages && cpmsCan('inspection.action.verify', $conn)): ?>
    <section class="ca-card ca-legacy-card">
        <h2>Padankan Gambar After Lama</h2>
        <p>
            Gambar di bawah telah dimuat naik sebelum fungsi padanan diperkenalkan.
            Pilih gambar Before Inspector yang betul bagi setiap gambar, kemudian
            simpan semua padanan sekali. Fail gambar asal tidak akan diubah atau
            dimuat naik semula.
        </p>
        <form method="post" id="legacyPairForm">
            <input type="hidden" name="csrf_token"
                   value="<?php echo cpmsActionEscape(cpmsActionCsrfToken()); ?>">
            <input type="hidden" name="action_id"
                   value="<?php echo $actionId; ?>">
            <input type="hidden" name="pair_legacy_images" value="1">

            <div class="ca-legacy-grid">
                <?php foreach ($unpairedAfterImages as $legacyIndex => $image): ?>
                    <?php $legacyImageId = (int) ($image['id'] ?? 0); ?>
                    <article class="ca-legacy-item">
                        <img src="../<?php echo cpmsActionEscape((string) $image['image_path']); ?>"
                             alt="Legacy After evidence" loading="lazy">
                        <div class="ca-legacy-caption">
                            <strong>After Lama <?php echo ($legacyIndex + 1); ?></strong><br>
                            <?php echo cpmsActionEscape((string) ($image['caption'] ?: $image['original_name'])); ?>
                        </div>
                        <label for="legacy-source-<?php echo $legacyImageId; ?>">
                            Pilih pasangan Before Inspector
                        </label>
                        <select class="ca-map-select"
                                id="legacy-source-<?php echo $legacyImageId; ?>"
                                name="source_image_ids[<?php echo $legacyImageId; ?>]"
                                required>
                            <option value="">-- Pilih Photo Pair --</option>
                            <?php foreach ($originalImages as $sourceIndex => $sourceImage): ?>
                                <?php
                                $sourceId = (int) ($sourceImage['id'] ?? 0);
                                $sourceHasAfter = !empty(
                                    $afterImagesBySource[$sourceId] ?? []
                                );
                                ?>
                                <option value="<?php echo $sourceId; ?>"
                                        data-preview="../<?php echo cpmsActionEscape((string) $sourceImage['image_path']); ?>">
                                    Photo Pair <?php echo ($sourceIndex + 1); ?> —
                                    <?php echo cpmsActionEscape((string) ($sourceImage['caption'] ?: $sourceImage['original_name'])); ?>
                                    <?php echo $sourceHasAfter ? ' (After sudah ada)' : ' (Menunggu After)'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="ca-before-preview" hidden>
                            <span>Before yang dipilih</span>
                            <img src="" alt="Selected Inspector Before">
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <button class="ca-btn ca-map-submit" type="submit"
                    onclick="return confirm('Simpan semua padanan gambar lama ini?');">
                Simpan Semua Padanan
            </button>
        </form>
    </section>
    <?php endif; ?>

    <section class="ca-card">
        <h2>Staff Work Evidence (<?php echo count($images); ?>)</h2>
        <div class="ca-evidence-count"><span>Before <?php echo $counts['Before']; ?></span><span>During <?php echo $counts['During']; ?></span><span>After <?php echo $counts['After']; ?></span><span>Other <?php echo $counts['Evidence']; ?></span></div>
        <?php if (!$images): ?><p>No evidence uploaded yet.</p><?php endif; ?>
        <div class="ca-photos"><?php foreach ($images as $image): ?><figure><a href="../<?php echo cpmsActionEscape((string) $image['image_path']); ?>" target="_blank" rel="noopener"><img src="../<?php echo cpmsActionEscape((string) $image['image_path']); ?>" alt="Work evidence"></a><figcaption><strong><?php echo cpmsActionEscape((string) $image['image_phase']); ?></strong><br><?php echo cpmsActionEscape((string) ($image['caption'] ?: $image['original_name'])); ?><br><small><?php echo cpmsActionEscape((string) $image['created_at']); ?></small></figcaption></figure><?php endforeach; ?></div>
    </section>

    <?php if ($link && (string) $action['status'] === 'Rectified' && (string) $link['supervisor_status'] === 'Pending Review' && cpmsCan('inspection.action.verify', $conn)): ?>
    <section class="ca-card">
        <h2>Supervisor Review</h2><p>Bandingkan gambar asal HQ dengan bukti After. Approve akan menghantar kepada HQ Inspector; Reject akan mengembalikan tugasan kepada staff.</p>
        <form method="post" class="ca-form">
            <input type="hidden" name="csrf_token" value="<?php echo cpmsActionEscape(cpmsActionCsrfToken()); ?>"><input type="hidden" name="action_id" value="<?php echo $actionId; ?>">
            <textarea name="remarks" rows="4" placeholder="Catatan kelulusan atau sebab penolakan"></textarea>
            <div class="ca-head-actions"><button class="ca-btn approve" type="submit" name="decision" value="Approved" onclick="return confirm('Approve pembaikan dan hantar kepada HQ?');">✓ Approve & Send to HQ</button><button class="ca-btn reject" type="submit" name="decision" value="Rejected" onclick="return confirm('Reject dan kembalikan kepada staff?');">✕ Reject to Staff</button></div>
            <input type="hidden" name="supervisor_review" value="1">
        </form>
    </section>
    <?php elseif ($link && (string) $link['supervisor_status'] === 'Approved' && !in_array((string) $action['status'], ['Verified', 'Closed'], true)): ?>
        <div class="ca-wait">Supervisor telah meluluskan pembaikan. Rekod kini menunggu HQ Inspector untuk verification akhir.</div>
    <?php elseif ($link && (string) $link['supervisor_status'] === 'Rejected'): ?>
        <div class="ca-alert ca-error">Supervisor menolak bukti ini. Tugasan telah dikembalikan kepada staff untuk pembaikan semula.</div>
    <?php endif; ?>

    <?php if (!$link && cpmsCan('inspection.action.manage', $conn)): ?>
    <section class="ca-card"><h2>Legacy Action Update</h2><form method="post" class="ca-form"><input type="hidden" name="csrf_token" value="<?php echo cpmsActionEscape(cpmsActionCsrfToken()); ?>"><input type="hidden" name="action_id" value="<?php echo $actionId; ?>"><select name="status"><option>Open</option><option>In Progress</option><option>Rectified</option><?php if (cpmsCan('inspection.action.verify', $conn)): ?><option>Verified</option><option>Closed</option><?php endif; ?></select><textarea name="notes" rows="4" placeholder="Progress or approval notes"></textarea><button class="ca-btn" name="legacy_update" value="1">Update Status</button></form></section>
    <?php endif; ?>

    <section class="ca-card"><h2>Progress History</h2><?php if (!$progress): ?><p>No v3.2.6.6 progress event recorded yet.</p><?php endif; ?><?php foreach ($progress as $event): ?><div class="ca-history"><strong><?php echo cpmsAssignmentEscape(ucwords(str_replace('_', ' ', (string) $event['event_type']))); ?></strong> · <?php echo cpmsAssignmentEscape((string) $event['actor_name']); ?> (<?php echo cpmsAssignmentEscape((string) $event['actor_role']); ?>)<br><small><?php echo cpmsAssignmentEscape((string) $event['created_at']); ?><?php if ($event['new_status']): ?> · <?php echo cpmsAssignmentEscape((string) $event['old_status']); ?> → <?php echo cpmsAssignmentEscape((string) $event['new_status']); ?><?php endif; ?></small><?php if (trim((string) ($event['notes'] ?? '')) !== ''): ?><p><?php echo nl2br(cpmsAssignmentEscape((string) $event['notes'])); ?></p><?php endif; ?></div><?php endforeach; ?></section>
</div>
<script>
(function () {
    'use strict';
    var selects = document.querySelectorAll('.ca-map-select');
    Array.prototype.forEach.call(selects, function (select) {
        select.addEventListener('change', function () {
            var item = select.closest('.ca-legacy-item');
            var previewBox = item
                ? item.querySelector('.ca-before-preview')
                : null;
            var previewImage = previewBox
                ? previewBox.querySelector('img')
                : null;
            var option = select.options[select.selectedIndex];
            var previewPath = option
                ? option.getAttribute('data-preview')
                : '';
            if (!previewBox || !previewImage) return;
            if (previewPath) {
                previewImage.src = previewPath;
                previewBox.hidden = false;
            } else {
                previewImage.removeAttribute('src');
                previewBox.hidden = true;
            }
        });
    });
}());
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
