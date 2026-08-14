<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';
require_once dirname(__DIR__) . '/includes/inspection_finding_service.php';
require_once dirname(__DIR__) . '/includes/inspection_assignment_service.php';

cpmsRequire('inspection.view', $conn);

if (!isset($propertyPortalUser) || !is_array($propertyPortalUser)) {
    $propertyPortalUser = [];
}

$propertyId = cpmsInspectionPropertyId($propertyPortalUser);
$inspectionId = (int) ($_GET['id'] ?? 0);
$inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);

if ($propertyId < 1 || !$inspection) {
    http_response_code(404);
    exit('Inspection record not found for this property.');
}

if (!cpmsFindingTablesReady($conn)) {
    http_response_code(503);
    exit('Inspection Finding module is not ready.');
}

if (!cpmsAssignmentTablesReady($conn)) {
    http_response_code(503);
    exit('Property/Staff workflow is not ready. Run migration 20260810_0061.');
}

$findings = cpmsFindingList($conn, $propertyId, $inspectionId);
$actionMap = cpmsAssignmentFindingMap($conn, $propertyId, $inspectionId);
$findingImages = [];
foreach ($findings as $finding) {
    $findingImages[(int) $finding['id']] = cpmsFindingImages(
        $conn,
        $propertyId,
        $inspectionId,
        (int) $finding['id']
    );
}
$afterImagesBySource = [];
$unpairedAfterByFinding = [];
foreach ($actionMap as $findingId => $linkedAction) {
    $actionImages = cpmsHqActionImages(
        $conn,
        $propertyId,
        (int) $linkedAction['action_id']
    );
    foreach ($actionImages as $image) {
        if ((string) ($image['image_phase'] ?? '') === 'After') {
            $sourceImageId = (int) (
                $image['source_inspection_image_id'] ?? 0
            );
            if ($sourceImageId > 0) {
                $afterImagesBySource[$sourceImageId][] = $image;
            } else {
                $unpairedAfterByFinding[(int) $findingId][] = $image;
            }
        }
    }
}

$pageTitle = 'HQ Findings - ' . (string) $inspection['inspection_no'];
$activeMenu = 'hq_inspection';

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/inspection-module.css">
<style>
    .hqf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,200px));gap:10px}.hqf-grid img{width:100%;height:140px;object-fit:cover;border-radius:8px}.hqf-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.hqf-meta div{background:#f8fafc;border-radius:8px;padding:10px}.hqf-meta small{display:block;color:#64748b}.hqf-finding{border-left:5px solid #94a3b8}.hqf-finding.High{border-left-color:#f97316}.hqf-finding.Critical{border-left-color:#dc2626}.hqf-finding.Low{border-left-color:#22c55e}.hqf-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px}.hqf-severity,.hqf-status{display:inline-block;border-radius:999px;background:#e2e8f0;padding:4px 9px;font-size:12px;font-weight:800}.hqf-assignment{background:#f8fafc;border:1px solid #dbeafe;border-radius:12px;padding:14px;margin-top:15px}.hqf-assignment-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.hqf-assignment-grid small{display:block;color:#64748b}.hqf-pending{color:#92400e}.hqf-approved{color:#166534}.hqf-rejected{color:#991b1b}
    .hqf-comparison{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}.hqf-compare{border:1px solid #e2e8f0;border-radius:12px;padding:12px;min-width:0}.hqf-compare.before{border-top:5px solid #f97316}.hqf-compare.after{border-top:5px solid #16a34a}.hqf-compare-head{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:10px}.hqf-compare-head h3{margin:0}.hqf-compare-head span{font-size:11px;font-weight:800;color:#64748b}.hqf-compare .hqf-grid{grid-template-columns:repeat(auto-fit,minmax(125px,1fr))}.hqf-empty{padding:24px 10px;text-align:center;background:#f8fafc;border-radius:8px;color:#64748b}@media(max-width:760px){.hqf-comparison{grid-template-columns:1fr}}
</style>

<div class="inspection-wrap">
    <div class="inspection-page-head">
        <div>
            <span class="inspection-eyebrow">HQ INSPECTION FINDINGS</span>
            <h1><?php echo cpmsInspectionEscape((string) $inspection['inspection_no']); ?></h1>
            <p><?php echo cpmsInspectionEscape((string) $inspection['location']); ?></p>
        </div>
        <div class="inspection-head-actions">
            <a class="inspection-btn" href="hq_inspection_inbox.php">← HQ Inbox</a>
            <a class="inspection-btn" href="inspection_view.php?id=<?php echo $inspectionId; ?>">Full Inspection</a>
        </div>
    </div>

    <section class="inspection-panel">
        <div class="hqf-meta">
            <div><small>Status</small><strong><?php echo cpmsInspectionEscape((string) $inspection['status']); ?></strong></div>
            <div><small>Date</small><strong><?php echo cpmsInspectionEscape((string) $inspection['inspection_date']); ?></strong></div>
            <div><small>Inspector</small><strong><?php echo cpmsInspectionEscape((string) ($inspection['reported_by_name'] ?? '-')); ?></strong></div>
            <div><small>Highest Severity</small><strong><?php echo cpmsInspectionEscape((string) $inspection['priority']); ?></strong></div>
            <div><small>Total Findings</small><strong><?php echo count($findings); ?></strong></div>
        </div>
    </section>

    <?php if (!$findings): ?>
        <section class="inspection-panel"><div class="inspection-empty">No HQ finding recorded.</div></section>
    <?php endif; ?>

    <?php foreach ($findings as $index => $finding): ?>
        <?php $linkedAction = $actionMap[(int) $finding['id']] ?? null; ?>
        <section class="inspection-panel hqf-finding <?php echo cpmsInspectionEscape((string) $finding['severity']); ?>">
            <div class="inspection-panel-head">
                <div>
                    <span class="inspection-eyebrow">FINDING <?php echo ($index + 1); ?></span>
                    <h2><?php echo cpmsInspectionEscape((string) $finding['finding_name']); ?></h2>
                </div>
                <span class="hqf-severity"><?php echo cpmsInspectionEscape((string) $finding['severity']); ?></span>
            </div>

            <p><strong>Category:</strong> <?php echo cpmsInspectionEscape((string) $finding['category']); ?><br>
               <strong>Location:</strong> <?php echo cpmsInspectionEscape((string) $finding['location']); ?><br>
               <strong>Finding Status:</strong> <?php echo cpmsInspectionEscape((string) $finding['status']); ?></p>

            <?php if (trim((string) ($finding['remarks'] ?? '')) !== ''): ?>
                <h3>Inspector Remarks</h3>
                <p><?php echo nl2br(cpmsInspectionEscape((string) $finding['remarks'])); ?></p>
            <?php endif; ?>

            <?php if (trim((string) ($finding['recommendation'] ?? '')) !== ''): ?>
                <h3>Recommendation</h3>
                <p><?php echo nl2br(cpmsInspectionEscape((string) $finding['recommendation'])); ?></p>
            <?php endif; ?>

            <?php
            $findingId = (int) $finding['id'];
            $beforeImages = $findingImages[$findingId] ?? [];
            $unpairedAfterImages = $unpairedAfterByFinding[$findingId] ?? [];
            ?>
            <?php if (!$beforeImages): ?>
                <div class="hqf-empty">Tiada gambar Inspector.</div>
            <?php endif; ?>

            <?php foreach ($beforeImages as $photoIndex => $beforeImage): ?>
                <?php
                $sourceImageId = (int) ($beforeImage['id'] ?? 0);
                $pairedAfterImages = $afterImagesBySource[$sourceImageId] ?? [];
                ?>
                <h3>Pasangan Gambar <?php echo ($photoIndex + 1); ?></h3>
                <div class="hqf-comparison">
                    <section class="hqf-compare before">
                        <div class="hqf-compare-head">
                            <h3>Before</h3>
                            <span>Inspector/HQ</span>
                        </div>
                        <div class="hqf-grid">
                            <figure>
                                <a href="../<?php echo cpmsInspectionEscape((string) $beforeImage['image_path']); ?>" target="_blank" rel="noopener">
                                    <img src="../<?php echo cpmsInspectionEscape((string) $beforeImage['image_path']); ?>" alt="Before - Inspector finding">
                                </a>
                                <figcaption><?php echo cpmsInspectionEscape((string) ($beforeImage['caption'] ?: $beforeImage['original_name'])); ?></figcaption>
                            </figure>
                        </div>
                    </section>

                    <section class="hqf-compare after">
                        <div class="hqf-compare-head">
                            <h3>After</h3>
                            <span>Staff/Property · <?php echo count($pairedAfterImages); ?></span>
                        </div>
                        <?php if (!$pairedAfterImages): ?>
                            <div class="hqf-empty">Menunggu gambar After yang sepadan.</div>
                        <?php else: ?>
                            <div class="hqf-grid">
                                <?php foreach ($pairedAfterImages as $image): ?>
                                    <figure>
                                        <a href="../<?php echo cpmsInspectionEscape((string) $image['image_path']); ?>" target="_blank" rel="noopener">
                                            <img src="../<?php echo cpmsInspectionEscape((string) $image['image_path']); ?>" alt="After - rectification evidence">
                                        </a>
                                        <figcaption><?php echo cpmsInspectionEscape((string) ($image['caption'] ?: $image['original_name'])); ?></figcaption>
                                    </figure>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endforeach; ?>

            <?php if ($unpairedAfterImages): ?>
                <h3>Gambar After Lama Belum Dipadankan</h3>
                <div class="hqf-grid">
                    <?php foreach ($unpairedAfterImages as $image): ?>
                        <figure>
                            <a href="../<?php echo cpmsInspectionEscape((string) $image['image_path']); ?>" target="_blank" rel="noopener">
                                <img src="../<?php echo cpmsInspectionEscape((string) $image['image_path']); ?>" alt="Unpaired After evidence">
                            </a>
                            <figcaption><?php echo cpmsInspectionEscape((string) ($image['caption'] ?: $image['original_name'])); ?></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($linkedAction): ?>
                <div class="hqf-assignment">
                    <div class="hqf-assignment-grid">
                        <div><small>Corrective Action</small><strong><?php echo cpmsAssignmentEscape((string) $linkedAction['action_no']); ?></strong></div>
                        <div><small>Assigned To</small><strong><?php echo cpmsAssignmentEscape((string) ($linkedAction['assigned_name'] ?: '-')); ?></strong></div>
                        <div><small>Due Date</small><strong><?php echo cpmsAssignmentEscape((string) ($linkedAction['due_date'] ?: '-')); ?></strong></div>
                        <div><small>Staff Status</small><strong><?php echo cpmsAssignmentEscape((string) $linkedAction['action_status']); ?></strong></div>
                        <div><small>Supervisor Review</small><strong class="hqf-<?php echo strtolower(str_replace(' ', '-', (string) $linkedAction['supervisor_status'])); ?>"><?php echo cpmsAssignmentEscape((string) $linkedAction['supervisor_status']); ?></strong></div>
                        <div><small>Work Evidence</small><strong><?php echo (int) $linkedAction['evidence_count']; ?> photo(s)</strong></div>
                    </div>
                    <div class="hqf-actions">
                        <a class="inspection-btn inspection-btn-primary" href="inspection_action_view.php?id=<?php echo (int) $linkedAction['action_id']; ?>">View Action & Review</a>
                    </div>
                </div>
            <?php elseif (cpmsCan('inspection.action.manage', $conn)): ?>
                <div class="hqf-actions">
                    <a class="inspection-btn inspection-btn-primary"
                       href="inspection_action_create.php?inspection_id=<?php echo $inspectionId; ?>&source_finding_id=<?php echo (int) $finding['id']; ?>">
                        Assign this Finding to Staff / Contractor
                    </a>
                </div>
            <?php else: ?>
                <div class="hqf-actions"><span class="hqf-status">Not assigned</span></div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
