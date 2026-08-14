<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/inspection_service.php';
require_once dirname(__DIR__) . '/includes/inspection_finding_service.php';

cpmsRequire('inspection.report.export', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
if ($propertyId < 1) {
    $propertyId = (int) ($_SESSION['property_admin_property_id'] ?? 0);
}

$inspectionId = (int) ($_GET['id'] ?? 0);
$inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
if (!$inspection) {
    http_response_code(404);
    exit('Inspection record not found.');
}

$findings = cpmsFindingTablesReady($conn)
    ? cpmsFindingList($conn, $propertyId, $inspectionId)
    : [];

$findingImages = [];
foreach ($findings as $finding) {
    $findingImages[(int) $finding['id']] = cpmsFindingImages(
        $conn,
        $propertyId,
        $inspectionId,
        (int) $finding['id']
    );
}

$actions = cpmsHqActionsByInspection($conn, $propertyId, $inspectionId);
$afterImagesBySource = [];
foreach ($actions as $action) {
    foreach (cpmsHqActionImages($conn, $propertyId, (int) $action['id']) as $image) {
        if ((string) ($image['image_phase'] ?? '') !== 'After') {
            continue;
        }
        $sourceImageId = (int) ($image['source_inspection_image_id'] ?? 0);
        if ($sourceImageId > 0) {
            $afterImagesBySource[$sourceImageId][] = $image;
        }
    }
}

$photoPairs = [];
foreach ($findings as $finding) {
    $findingId = (int) $finding['id'];
    foreach (($findingImages[$findingId] ?? []) as $beforeImage) {
        $sourceImageId = (int) ($beforeImage['id'] ?? 0);
        $pairedAfterImages = $afterImagesBySource[$sourceImageId] ?? [];
        $photoPairs[] = [
            'finding' => $finding,
            'before' => $beforeImage,
            'after' => $pairedAfterImages[0] ?? null,
            'after_count' => count($pairedAfterImages),
        ];
    }
}

$photoPages = array_chunk($photoPairs, 3);
$propertyName = (string) ($propertyPortalUser['property_name'] ?? 'CPMS Property');
$propertyCode = (string) ($propertyPortalUser['property_code'] ?? '');

function reportE(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo reportE((string) $inspection['inspection_no']); ?> | Inspection Report</title>
    <style>
        *{box-sizing:border-box}body{font-family:Arial,sans-serif;margin:0;background:#e2e8f0;color:#0f172a}.toolbar{position:sticky;top:0;background:#0f172a;padding:12px;display:flex;justify-content:center;gap:10px;z-index:10}.toolbar a,.toolbar button{background:#fff;color:#0f172a;border:0;border-radius:8px;padding:10px 14px;text-decoration:none;font-weight:800;cursor:pointer}.report{width:min(210mm,calc(100% - 24px));margin:18px auto;background:#fff;padding:5mm;box-shadow:0 12px 35px #0002}.head{border-bottom:3px solid #0f172a;padding-bottom:8px}.head h1{margin:3px 0;font-size:24px}.head strong{display:block}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin:10px 0}.meta div{background:#f8fafc;padding:7px;border-radius:8px}.meta small{display:block;color:#64748b;margin-bottom:3px;font-size:10px}.meta strong{font-size:12px}.muted{color:#64748b}.footer{margin-top:14px;border-top:1px solid #cbd5e1;padding-top:8px;font-size:10px;color:#64748b}@page{size:A4 portrait;margin:5mm}@media(max-width:700px){.report{padding:18px}.meta{grid-template-columns:1fr 1fr}}@media print{body{background:#fff}.toolbar{display:none}.report{width:100%;max-width:none;margin:0;padding:0;box-shadow:none}.head{padding-bottom:5px}.head h1{font-size:20px}.meta{margin:6px 0}.meta div{padding:5px}}
        .evidence-page{break-after:page;page-break-after:always}.evidence-page:last-of-type{break-after:auto;page-break-after:auto}.evidence-head{display:flex;justify-content:space-between;align-items:end;margin:6px 0}.evidence-head h2{font-size:14px;margin:0}.pair-grid{display:grid;grid-template-rows:repeat(3,1fr);gap:4mm}.pair-row{display:grid;grid-template-columns:1fr 1fr;gap:4mm;min-height:66mm;break-inside:avoid}.photo-cell{border:1px solid #cbd5e1;border-radius:7px;padding:4px;min-width:0}.photo-cell.before{border-top:4px solid #f97316}.photo-cell.after{border-top:4px solid #16a34a}.photo-title{display:flex;justify-content:space-between;gap:8px;margin-bottom:3px;font-size:9px;font-weight:900}.photo-cell img{display:block;width:100%;height:60mm;object-fit:cover;object-position:center;border-radius:5px;background:#f1f5f9;border:1px solid #e2e8f0}.photo-empty{width:100%;height:60mm;display:grid;place-items:center;background:#f8fafc;border-radius:5px;color:#64748b;font-size:10px;text-align:center}.issue-mini{font-size:8px;margin-top:3px;line-height:1.2}.issue-mini strong{color:#9a3412}.issue-mini span{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.caption{font-size:8px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}@media(max-width:700px){.pair-row{grid-template-columns:1fr}.photo-cell img,.photo-empty{width:100%;height:210px}}@media print{.evidence-head{margin:3px 0}.evidence-head h2{font-size:11px}.pair-grid{gap:2mm}.pair-row{gap:3mm;min-height:66mm}.photo-cell{padding:3px}.photo-cell img,.photo-empty{height:60mm}.issue-mini{font-size:7px}.caption{font-size:7px}}
    </style>
</head>
<body>
<div class="toolbar">
    <a href="inspection_view.php?id=<?php echo $inspectionId; ?>">← Back</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>
<main class="report">
    <header class="head">
        <small>CPMSPRO · HQ INSPECTION REPORT</small>
        <h1><?php echo reportE((string) $inspection['inspection_no']); ?></h1>
        <strong><?php echo reportE($propertyCode !== '' ? $propertyCode . ' — ' . $propertyName : $propertyName); ?></strong>
    </header>

    <section class="meta">
        <div><small>Status</small><strong><?php echo reportE((string) $inspection['status']); ?></strong></div>
        <div><small>Inspection Date</small><strong><?php echo reportE((string) $inspection['inspection_date']); ?></strong></div>
        <div><small>Inspector</small><strong><?php echo reportE((string) ($inspection['reported_by_name'] ?? '-')); ?></strong></div>
    </section>

    <?php if (!$photoPairs): ?><p class="muted">No photo evidence recorded.</p><?php endif; ?>
    <?php foreach ($photoPages as $pagePairs): ?>
        <section class="evidence-page">
            <div class="evidence-head">
                <h2>Photo Evidence</h2>
                <span class="muted">A4 portrait · 3 Before + 3 After</span>
            </div>
            <div class="pair-grid">
                <?php foreach ($pagePairs as $pair): ?>
                    <?php
                    $finding = $pair['finding'];
                    $beforeImage = $pair['before'];
                    $afterImage = $pair['after'];
                    $remarks = trim((string) ($finding['remarks'] ?? ''));
                    ?>
                    <div class="pair-row">
                        <section class="photo-cell before">
                            <div class="photo-title">
                                <span>BEFORE</span>
                                <span><?php echo reportE((string) $finding['severity']); ?></span>
                            </div>
                            <a href="../<?php echo reportE((string) $beforeImage['image_path']); ?>" target="_blank" rel="noopener">
                                <img src="../<?php echo reportE((string) $beforeImage['image_path']); ?>" alt="Before inspection photo">
                            </a>
                            <div class="issue-mini">
                                <span><strong>Issue:</strong> <?php echo reportE((string) $finding['finding_name']); ?> | <strong>Location:</strong> <?php echo reportE((string) $finding['location']); ?> | <strong>Remarks:</strong> <?php echo reportE($remarks !== '' ? $remarks : '-'); ?></span>
                            </div>
                        </section>

                        <section class="photo-cell after">
                            <div class="photo-title">
                                <span>AFTER</span>
                                <span><?php echo (int) $pair['after_count']; ?> photo(s)</span>
                            </div>
                            <?php if (!$afterImage): ?>
                                <div class="photo-empty">Waiting for matching After photo.</div>
                            <?php else: ?>
                                <a href="../<?php echo reportE((string) $afterImage['image_path']); ?>" target="_blank" rel="noopener">
                                    <img src="../<?php echo reportE((string) $afterImage['image_path']); ?>" alt="After rectification photo">
                                </a>
                                <div class="caption"><?php echo reportE((string) ($afterImage['caption'] ?: $afterImage['original_name'])); ?></div>
                            <?php endif; ?>
                        </section>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="footer">Generated from CPMSPro on <?php echo date('Y-m-d H:i:s'); ?>. This report reflects the system records at the time of generation.</div>
</main>
</body>
</html>
