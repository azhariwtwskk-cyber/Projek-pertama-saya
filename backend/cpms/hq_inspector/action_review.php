<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$actionId = (int) ($_GET['id'] ?? $_POST['action_id'] ?? 0);
$propertyId = (int) ($_GET['property_id'] ?? $_POST['property_id'] ?? 0);
$inspectorId = (int) $hqInspector['id'];
$inspectorName = (string) $hqInspector['inspector_code']
    . ' - ' . (string) $hqInspector['full_name'];
$action = cpmsHqActionFind($conn, $propertyId, $actionId, $inspectorId);

if (!$action) {
    hqiRedirect('dashboard.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hqiVerify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security session. Please refresh the page.';
    } else {
        try {
            $imageDecision = trim((string) ($_POST['image_decision'] ?? ''));
            if ($imageDecision !== '') {
                cpmsHqReviewActionImage(
                    $conn,
                    $propertyId,
                    $actionId,
                    (int) ($_POST['image_id'] ?? 0),
                    $inspectorId,
                    $inspectorName,
                    $imageDecision,
                    (string) ($_POST['image_remarks'] ?? '')
                );
                hqiRedirect(
                    'action_review.php?id=' . $actionId
                    . '&property_id=' . $propertyId
                    . '&image_reviewed=' . strtolower($imageDecision)
                );
            }

            $decision = trim((string) ($_POST['decision'] ?? ''));
            if (
                $decision === 'Verified'
                && (int) ($action['source_finding_id'] ?? 0) > 0
            ) {
                if (!cpmsAssignmentPhotoPairingReady($conn)) {
                    throw new RuntimeException(
                        'Run migration 20260810_0062 before HQ verification.'
                    );
                }
                $pairSummary = cpmsAssignmentPhotoPairSummary(
                    $conn,
                    $propertyId,
                    $actionId
                );
                if ((int) $pairSummary['remaining'] > 0) {
                    throw new RuntimeException(
                        'Verification blocked: '
                        . (int) $pairSummary['remaining']
                        . ' inspector photo(s) still do not have matching After evidence.'
                    );
                }
                if (cpmsHqActionImageReviewReady($conn)) {
                    $pendingPhotos = 0;
                    $rejectedPhotos = 0;
                    foreach (cpmsHqActionImages($conn, $propertyId, $actionId) as $reviewImage) {
                        if ((string) ($reviewImage['image_phase'] ?? '') !== 'After') {
                            continue;
                        }
                        $status = (string) ($reviewImage['hq_review_status'] ?? 'Pending');
                        if ($status === 'Rejected') {
                            $rejectedPhotos++;
                        } elseif ($status !== 'Accepted') {
                            $pendingPhotos++;
                        }
                    }
                    if ($pendingPhotos > 0 || $rejectedPhotos > 0) {
                        throw new RuntimeException(
                            'Verification blocked: some After photos are still Pending or Rejected.'
                        );
                    }
                }
            }
            cpmsHqReviewAction(
                $conn,
                $propertyId,
                $actionId,
                $inspectorId,
                $inspectorName,
                $decision,
                (string) ($_POST['remarks'] ?? '')
            );
            hqiRedirect(
                'action_review.php?id=' . $actionId
                . '&property_id=' . $propertyId
                . '&reviewed=' . strtolower($decision)
            );
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$action = cpmsHqActionFind($conn, $propertyId, $actionId, $inspectorId);
$images = cpmsHqActionImages($conn, $propertyId, $actionId);
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
$originalImages = [];
if (
    (int) ($action['source_finding_id'] ?? 0) > 0
    && function_exists('cpmsFindingImages')
) {
    $originalImages = cpmsFindingImages(
        $conn,
        $propertyId,
        (int) $action['inspection_id'],
        (int) $action['source_finding_id']
    );
}
$reviews = cpmsHqActionReviews($conn, $propertyId, $actionId);
$isOverdue = !empty($action['due_date'])
    && (string) $action['due_date'] < date('Y-m-d')
    && !in_array((string) $action['status'], ['Verified', 'Closed'], true);
$requiresSupervisor = (int) ($action['source_finding_id'] ?? 0) > 0;
$supervisorApproved = !$requiresSupervisor
    || (string) ($action['supervisor_status'] ?? '') === 'Approved';

$pageTitle = 'HQ Review ' . (string) $action['action_no'];
require __DIR__ . '/header.php';
?>
<style>
    .review-head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px}.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.meta div{background:#f8fafc;padding:12px;border-radius:10px}.meta small{display:block;color:#64748b;margin-bottom:4px}.status{display:inline-block;padding:5px 9px;border-radius:999px;background:#e2e8f0;font-weight:800}.status.Rectified{background:#ede9fe;color:#6d28d9}.status.Verified,.status.Closed{background:#dcfce7;color:#166534}.overdue{color:#b91c1c}.photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.photos figure{margin:0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}.photos img{display:block;width:100%;height:170px;object-fit:cover}.photos figcaption{padding:10px}.review-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.decision-actions{display:flex;gap:10px;flex-wrap:wrap}.verify{background:#166534}.reject{background:#b91c1c}.history{border-left:3px solid #cbd5e1;padding:0 0 14px 14px;margin-left:6px}.notice{padding:11px;border-radius:9px;margin-bottom:12px;background:#dcfce7;color:#166534}.notice.warn{background:#fff7ed;color:#9a3412}@media(max-width:680px){.review-head{flex-direction:column}.review-grid{grid-template-columns:1fr}.decision-actions button{flex:1}}
    .comparison{display:grid;grid-template-columns:1fr 1fr;gap:14px}.compare-column{border:1px solid #e2e8f0;border-radius:12px;padding:12px;min-width:0}.compare-column.before{border-top:5px solid #f97316}.compare-column.after{border-top:5px solid #16a34a}.compare-column h3{display:flex;justify-content:space-between;gap:8px}.compare-column h3 span{font-size:12px;color:#64748b}.compare-empty{padding:24px 10px;text-align:center;background:#f8fafc;border-radius:8px;color:#64748b}@media(max-width:680px){.comparison{grid-template-columns:1fr}}
    .image-status{display:inline-block;padding:4px 8px;border-radius:999px;background:#e2e8f0;font-size:11px;font-weight:900}.image-status.Accepted{background:#dcfce7;color:#166534}.image-status.Rejected{background:#fee2e2;color:#991b1b}.image-review{display:grid;gap:8px;padding:10px;border-top:1px solid #e2e8f0;background:#f8fafc}.image-review textarea{width:100%;min-height:54px;border:1px solid #cbd5e1;border-radius:8px;padding:8px}.image-review-actions{display:flex;gap:7px;flex-wrap:wrap}.image-review-actions button{font-size:12px;padding:8px 10px}
</style>

<div class="review-head">
    <div>
        <small>HQ CORRECTIVE ACTION REVIEW</small>
        <h1><?php echo hqiEscape((string) $action['action_no']); ?></h1>
        <p class="muted"><?php echo hqiEscape((string) $action['property_code']); ?> — <?php echo hqiEscape((string) $action['property_name']); ?></p>
    </div>
    <div>
        <a class="btn" href="inspection_view.php?id=<?php echo (int) $action['inspection_id']; ?>&property_id=<?php echo $propertyId; ?>">← Inspection</a>
        <a class="btn" href="dashboard.php">Dashboard</a>
    </div>
</div>

<?php if (isset($_GET['reviewed'])): ?>
    <div class="notice">HQ review saved: <?php echo hqiEscape(ucfirst((string) $_GET['reviewed'])); ?>.</div>
<?php endif; ?>
<?php if (isset($_GET['image_reviewed'])): ?>
    <div class="notice">Photo review saved: <?php echo hqiEscape(ucfirst((string) $_GET['image_reviewed'])); ?>.</div>
<?php endif; ?>
<?php if (!cpmsHqOperationalTablesReady($conn)): ?>
    <div class="notice warn">Run migration 20260810_0060 before verification.</div>
<?php endif; ?>
<?php foreach ($errors as $error): ?><div class="err"><?php echo hqiEscape((string) $error); ?></div><?php endforeach; ?>

<section class="card genesis-action-card">
    <div class="meta">
        <div><small>Status</small><strong class="status <?php echo hqiEscape((string) $action['status']); ?>"><?php echo hqiEscape((string) $action['status']); ?></strong></div>
        <div><small>Inspection</small><strong><?php echo hqiEscape((string) $action['inspection_no']); ?></strong></div>
        <div><small>Assigned To</small><strong><?php echo hqiEscape((string) ($action['assigned_name'] ?: '-')); ?></strong></div>
        <div><small>Priority</small><strong><?php echo hqiEscape((string) $action['priority']); ?></strong></div>
        <div><small>Due Date</small><strong class="<?php echo $isOverdue ? 'overdue' : ''; ?>"><?php echo hqiEscape((string) ($action['due_date'] ?: '-')); ?><?php echo $isOverdue ? ' · OVERDUE' : ''; ?></strong></div>
        <div><small>Evidence</small><strong><?php echo count($images); ?> image(s)</strong></div>
        <div><small>Property Supervisor</small><strong><?php echo hqiEscape((string) ($action['supervisor_status'] ?: ($requiresSupervisor ? 'Not Submitted' : 'Legacy Action'))); ?></strong></div>
    </div>
    <h2><?php echo hqiEscape((string) $action['title']); ?></h2>
    <h3>Required Rectification</h3>
    <p><?php echo nl2br(hqiEscape((string) $action['description'])); ?></p>
    <?php if (trim((string) ($action['rectification_notes'] ?? '')) !== ''): ?>
        <h3>Rectification Notes</h3>
        <p><?php echo nl2br(hqiEscape((string) $action['rectification_notes'])); ?></p>
    <?php endif; ?>
    <?php if ($requiresSupervisor): ?>
        <h3>Source Finding</h3>
        <p><strong><?php echo hqiEscape((string) $action['source_finding_name']); ?></strong> · <?php echo hqiEscape((string) $action['source_finding_severity']); ?> · <?php echo hqiEscape((string) $action['source_finding_location']); ?></p>
        <?php if (trim((string) ($action['supervisor_remarks'] ?? '')) !== ''): ?><h3>Supervisor Remarks</h3><p><?php echo nl2br(hqiEscape((string) $action['supervisor_remarks'])); ?></p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Before / After Comparison</h2>
    <p class="muted">Each inspector photo is matched with the exact After evidence for the same location.</p>
    <?php if (!$originalImages): ?><div class="compare-empty">No inspector photos.</div><?php endif; ?>
    <?php foreach ($originalImages as $photoIndex => $beforeImage): ?>
        <?php
        $sourceImageId = (int) ($beforeImage['id'] ?? 0);
        $pairedAfterImages = $afterImagesBySource[$sourceImageId] ?? [];
        ?>
        <h3>Photo Pair <?php echo ($photoIndex + 1); ?></h3>
        <div class="comparison">
            <div class="compare-column before">
                <h3>Before <span>Inspector/HQ</span></h3>
                <div class="photos">
                    <figure>
                        <a href="../<?php echo hqiEscape((string) $beforeImage['image_path']); ?>" target="_blank" rel="noopener">
                            <img src="../<?php echo hqiEscape((string) $beforeImage['image_path']); ?>" alt="Before - Inspector finding">
                        </a>
                        <figcaption><?php echo hqiEscape((string) ($beforeImage['caption'] ?: $beforeImage['original_name'])); ?></figcaption>
                    </figure>
                </div>
            </div>

            <div class="compare-column after">
                <h3>After <span>Staff/Property · <?php echo count($pairedAfterImages); ?></span></h3>
                <?php if (!$pairedAfterImages): ?>
                    <div class="compare-empty">Waiting for matching After evidence.</div>
                <?php else: ?>
                    <div class="photos">
                        <?php foreach ($pairedAfterImages as $image): ?>
                            <?php $imageStatus = (string) ($image['hq_review_status'] ?? 'Pending'); ?>
                            <figure>
                                <a href="../<?php echo hqiEscape((string) $image['image_path']); ?>" target="_blank" rel="noopener">
                                    <img src="../<?php echo hqiEscape((string) $image['image_path']); ?>" alt="After - rectification evidence">
                                </a>
                                <figcaption>
                                    <span class="image-status <?php echo hqiEscape($imageStatus); ?>"><?php echo hqiEscape($imageStatus); ?></span><br>
                                    <?php echo hqiEscape((string) ($image['caption'] ?: $image['original_name'])); ?><br>
                                    <small><?php echo hqiEscape((string) $image['created_at']); ?></small>
                                    <?php if (trim((string) ($image['hq_review_remarks'] ?? '')) !== ''): ?>
                                        <br><small>HQ: <?php echo hqiEscape((string) $image['hq_review_remarks']); ?></small>
                                    <?php endif; ?>
                                </figcaption>
                                <?php if (cpmsHqActionImageReviewReady($conn) && (string) $action['status'] === 'Rectified' && $supervisorApproved): ?>
                                    <form class="image-review" method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf()); ?>">
                                        <input type="hidden" name="action_id" value="<?php echo $actionId; ?>">
                                        <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
                                        <input type="hidden" name="image_id" value="<?php echo (int) $image['id']; ?>">
                                        <textarea name="image_remarks" placeholder="Remarks required when rejecting this photo"><?php echo hqiEscape((string) ($image['hq_review_remarks'] ?? '')); ?></textarea>
                                        <div class="image-review-actions">
                                            <button class="verify" type="submit" name="image_decision" value="Accepted">Accept Photo</button>
                                            <button class="reject" type="submit" name="image_decision" value="Rejected">Reject Photo</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($unpairedAfterImages): ?>
        <h3>Legacy Unmatched After Photos</h3>
        <div class="photos">
            <?php foreach ($unpairedAfterImages as $image): ?>
                <figure>
                    <a href="../<?php echo hqiEscape((string) $image['image_path']); ?>" target="_blank" rel="noopener">
                        <img src="../<?php echo hqiEscape((string) $image['image_path']); ?>" alt="Unpaired After evidence">
                    </a>
                    <figcaption><?php echo hqiEscape((string) ($image['caption'] ?: $image['original_name'])); ?></figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    $supportingImages = array_values(array_filter(
        $images,
        static function (array $image): bool {
            return (string) ($image['image_phase'] ?? '') !== 'After';
        }
    ));
    ?>
    <?php if ($supportingImages): ?>
        <h3>Supporting Before / During / Other Evidence</h3>
        <div class="photos">
            <?php foreach ($supportingImages as $image): ?>
                <figure>
                    <a href="../<?php echo hqiEscape((string) $image['image_path']); ?>" target="_blank" rel="noopener">
                        <img src="../<?php echo hqiEscape((string) $image['image_path']); ?>" alt="Supporting evidence">
                    </a>
                    <figcaption><strong><?php echo hqiEscape((string) $image['image_phase']); ?></strong><br><?php echo hqiEscape((string) ($image['caption'] ?: $image['original_name'])); ?></figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ((string) $action['status'] === 'Rectified' && !$supervisorApproved): ?>
    <div class="notice warn">This rectification is still waiting for Property Supervisor approval. HQ verification is locked until the Supervisor approves it.</div>
<?php endif; ?>

<?php if ((string) $action['status'] === 'Rectified' && $supervisorApproved && cpmsHqOperationalTablesReady($conn)): ?>
<section class="card">
    <h2>HQ Verification</h2>
    <p>Review the rectification evidence. Select Verify if acceptable, or Reject to return it to the staff/contractor.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo hqiEscape(hqiCsrf()); ?>">
        <input type="hidden" name="action_id" value="<?php echo $actionId; ?>">
        <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
        <label>HQ Review Remarks</label>
        <textarea name="remarks" rows="4" placeholder="Verification remarks or rejection reason"></textarea>
        <div class="decision-actions">
            <button class="verify" type="submit" name="decision" value="Verified" onclick="return confirm('Confirm this rectification as Verified?');">✓ Verify Rectification</button>
            <button class="reject" type="submit" name="decision" value="Rejected" onclick="return confirm('Reject this rectification and return it to In Progress?');">✕ Reject Rectification</button>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2>HQ Review History</h2>
    <?php if (!$reviews): ?><p class="muted">No HQ review records yet.</p><?php endif; ?>
    <?php foreach ($reviews as $review): ?>
        <div class="history">
            <strong><?php echo hqiEscape((string) $review['decision']); ?></strong>
            · <?php echo hqiEscape((string) $review['inspector_name']); ?>
            · <?php echo hqiEscape((string) $review['created_at']); ?>
            <?php if (trim((string) ($review['remarks'] ?? '')) !== ''): ?><p><?php echo nl2br(hqiEscape((string) $review['remarks'])); ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
