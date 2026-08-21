<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$inspectionId = (int) ($_GET['id'] ?? 0);
$propertyId = (int) ($_GET['property_id'] ?? 0);
$inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
if (!$inspection
    || (int) ($inspection['reported_by_id'] ?? 0) !== (int) $hqInspector['id']
) {
    hqiRedirect('inspections.php');
}

$property = null;
$propertyStmt = $conn->prepare(
    'SELECT property_code, property_name
     FROM cpms_properties WHERE id = ? LIMIT 1'
);
if ($propertyStmt) {
    $propertyStmt->bind_param('i', $propertyId);
    $propertyStmt->execute();
    $property = $propertyStmt->get_result()->fetch_assoc();
    $propertyStmt->close();
}
$property = $property ?: [];

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
$actionImages = [];
$actionsByFinding = [];
$afterImagesBySource = [];
$unpairedAfterByFinding = [];
foreach ($actions as $action) {
    $actionId = (int) $action['id'];
    $sourceFindingId = (int) ($action['source_finding_id'] ?? 0);
    $images = cpmsHqActionImages(
        $conn,
        $propertyId,
        $actionId
    );
    $actionImages[$actionId] = $images;

    if ($sourceFindingId > 0) {
        $actionsByFinding[$sourceFindingId][] = $action;
        foreach ($images as $image) {
            if ((string) ($image['image_phase'] ?? '') === 'After') {
                $sourceImageId = (int) (
                    $image['source_inspection_image_id'] ?? 0
                );
                if ($sourceImageId > 0) {
                    $afterImagesBySource[$sourceImageId][] = $image;
                } else {
                    $unpairedAfterByFinding[$sourceFindingId][] = $image;
                }
            }
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

$totalFindings = count($findings);
$totalActions = count($actions);
$totalBeforePhotos = count($photoPairs);
$totalAfterPhotos = 0;
foreach ($actionImages as $images) {
    foreach ($images as $image) {
        if ((string) ($image['image_phase'] ?? '') === 'After') {
            $totalAfterPhotos++;
        }
    }
}
$openActions = 0;
foreach ($actions as $action) {
    $actionStatus = strtolower(trim((string) ($action['status'] ?? '')));
    if (!in_array($actionStatus, ['completed', 'closed', 'verified', 'resolved'], true)) {
        $openActions++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo hqiEscape((string) $inspection['inspection_no']); ?> | Inspection Report</title>
    <style>
        :root{--primary:#6366F1;--primary-hover:#4F46E5;--bg:#FAFAFA;--surface:#FFFFFF;--text:#0A0A0A;--text2:#6B6B6B;--neutral:#9C9C9C;--border:#E8E8EC;--success:#10B981;--warning:#F59E0B;--error:#EF4444}
        *{box-sizing:border-box}html{background:var(--bg)}body{font-family:"DM Sans",Arial,sans-serif;margin:0;background:var(--bg);color:var(--text);font-size:13px}
        .toolbar{position:sticky;top:0;z-index:20;display:flex;justify-content:center;gap:8px;padding:10px;background:rgba(250,250,250,.92);backdrop-filter:blur(14px);border-bottom:1px solid var(--border)}
        .toolbar a,.toolbar button{height:38px;padding:0 16px;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text);font:600 12px "DM Sans",Arial,sans-serif;text-decoration:none;cursor:pointer}
        .toolbar button{background:var(--primary);border-color:var(--primary);color:#fff}.toolbar button:hover{background:var(--primary-hover)}
        .report{width:min(210mm,calc(100% - 28px));margin:20px auto;background:var(--surface);padding:10mm;box-shadow:0 8px 30px rgba(0,0,0,.08);border:1px solid var(--border)}
        .report-cover{padding-bottom:7mm;border-bottom:1px solid var(--border)}
        .brand-row{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:8mm}
        .brand{display:flex;align-items:center;gap:10px}.brand-mark{display:grid;place-items:center;width:38px;height:38px;border-radius:9px;background:var(--primary);color:#fff;font-weight:800;font-size:13px}.brand-copy strong{display:block;font-size:13px}.brand-copy small{display:block;margin-top:2px;color:var(--text2);font-size:9px;letter-spacing:.08em}
        .report-chip{display:inline-flex;align-items:center;padding:5px 9px;border-radius:9999px;background:#F4F4F5;color:var(--text2);font-size:9px;font-weight:700;letter-spacing:.05em}
        .head small{display:block;margin-bottom:4px;color:var(--text2);font-size:9px;font-weight:700;letter-spacing:.12em}.head h1{margin:0;font-size:27px;line-height:1.05;letter-spacing:-.035em}.head strong{display:block;margin-top:7px;font-size:13px;font-weight:500;color:var(--text2)}
        .meta{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:7mm 0 0}.meta div,.summary-card{border:1px solid var(--border);border-radius:8px;background:#fff;padding:10px}.meta small,.summary-card small{display:block;margin-bottom:4px;color:var(--text2);font-size:9px}.meta strong{font-size:11px;font-weight:600}
        .summary{display:grid;grid-template-columns:repeat(5,1fr);gap:7px;margin:6mm 0}.summary-card strong{display:block;font-size:20px;line-height:1}.summary-card em{display:block;margin-top:5px;color:var(--neutral);font-size:8px;font-style:normal}
        .section-title{display:flex;justify-content:space-between;align-items:end;gap:12px;margin:6mm 0 3mm}.section-title h2{margin:0;font-size:14px;letter-spacing:-.02em}.section-title span{color:var(--text2);font-size:9px}
        .notes{padding:11px;border:1px solid var(--border);border-radius:8px;background:#FAFAFA;line-height:1.5;color:var(--text2)}
        .muted{color:var(--text2)}
        .evidence-page{break-after:page;page-break-after:always}.evidence-page:last-of-type{break-after:auto;page-break-after:auto}
        .evidence-head{display:flex;justify-content:space-between;align-items:end;margin:5mm 0 3mm}.evidence-head h2{font-size:14px;margin:0;letter-spacing:-.02em}.evidence-head span{font-size:9px;color:var(--text2)}
        .pair-grid{display:grid;grid-template-rows:repeat(3,1fr);gap:4mm}.pair-row{display:grid;grid-template-columns:1fr 1fr;gap:4mm;min-height:66mm;break-inside:avoid}
        .photo-cell{border:1px solid var(--border);border-radius:8px;padding:4px;min-width:0;background:#fff}.photo-cell.before{border-top:3px solid var(--warning)}.photo-cell.after{border-top:3px solid var(--success)}
        .photo-title{display:flex;justify-content:space-between;gap:8px;padding:1px 2px 4px;font-size:8px;font-weight:700;letter-spacing:.04em}.photo-cell img{display:block;width:100%;height:58mm;object-fit:cover;object-position:center;border-radius:6px;background:#F4F4F5;border:1px solid var(--border)}
        .photo-empty{width:100%;height:58mm;display:grid;place-items:center;padding:10px;background:#FAFAFA;border:1px dashed var(--border);border-radius:6px;color:var(--neutral);font-size:9px;text-align:center}
        .issue-mini{font-size:7.5px;margin-top:4px;line-height:1.3;color:var(--text2)}.issue-mini strong{color:var(--text)}.issue-mini span{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.caption{font-size:7.5px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:3px}
        .footer{margin-top:7mm;border-top:1px solid var(--border);padding-top:3mm;font-size:8px;color:var(--neutral);display:flex;justify-content:space-between;gap:12px}
        @page{size:A4 portrait;margin:7mm}
        @media(max-width:700px){.report{padding:18px}.brand-row{align-items:flex-start}.meta{grid-template-columns:1fr 1fr}.summary{grid-template-columns:repeat(2,1fr)}.pair-row{grid-template-columns:1fr}.photo-cell img,.photo-empty{height:210px}}
        @media print{html,body{background:#fff}.toolbar{display:none}.report{width:100%;max-width:none;margin:0;padding:0;border:0;box-shadow:none}.report-cover{padding-bottom:4mm}.brand-row{margin-bottom:5mm}.meta{margin-top:4mm}.summary{margin:4mm 0}.evidence-head{margin:3mm 0 2mm}.pair-grid{gap:2mm}.pair-row{gap:3mm;min-height:66mm}.photo-cell{padding:3px}.photo-cell img,.photo-empty{height:58mm}.footer{margin-top:4mm}}
    </style>
</head>
<body>
<div class="toolbar">
    <a href="inspection_view.php?id=<?php echo $inspectionId; ?>&property_id=<?php echo $propertyId; ?>">← Back</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>
<main class="report">
    <section class="report-cover">
        <div class="brand-row">
            <div class="brand">
                <div class="brand-mark">CP</div>
                <div class="brand-copy"><strong>CPMSPro</strong><small>HQ INSPECTION & COMPLIANCE</small></div>
            </div>
            <span class="report-chip">OFFICIAL INSPECTION REPORT</span>
        </div>
        <header class="head">
            <small>INSPECTION REPORT</small>
            <h1><?php echo hqiEscape((string) $inspection['inspection_no']); ?></h1>
            <strong><?php echo hqiEscape((string) ($property['property_name'] ?? '')); ?></strong>
        </header>

        <section class="meta">
            <div><small>Status</small><strong><?php echo hqiEscape((string) $inspection['status']); ?></strong></div>
            <div><small>Inspection Date</small><strong><?php echo hqiEscape((string) $inspection['inspection_date']); ?></strong></div>
            <div><small>Inspector</small><strong><?php echo hqiEscape((string) ($inspection['reported_by_name'] ?? '')); ?></strong></div>
        </section>
    </section>

    <section class="summary">
        <div class="summary-card"><small>Findings</small><strong><?php echo $totalFindings; ?></strong><em>Recorded issues</em></div>
        <div class="summary-card"><small>Corrective Actions</small><strong><?php echo $totalActions; ?></strong><em>Action records</em></div>
        <div class="summary-card"><small>Open Actions</small><strong><?php echo $openActions; ?></strong><em>Pending closure</em></div>
        <div class="summary-card"><small>Before Photos</small><strong><?php echo $totalBeforePhotos; ?></strong><em>Inspection evidence</em></div>
        <div class="summary-card"><small>After Photos</small><strong><?php echo $totalAfterPhotos; ?></strong><em>Rectification evidence</em></div>
    </section>

    <?php if (trim((string) ($inspection['description'] ?? '')) !== ''): ?>
        <div class="section-title"><h2>General Notes</h2><span>Inspector remarks</span></div>
        <div class="notes"><?php echo nl2br(hqiEscape((string) $inspection['description'])); ?></div>
    <?php endif; ?>

    <?php if (!$photoPairs): ?><p class="muted">No photo evidence recorded.</p><?php endif; ?>
    <?php foreach ($photoPages as $pageIndex => $pagePairs): ?>
        <section class="evidence-page">
            <div class="evidence-head">
                <h2>Photo Evidence</h2>
                <span>Before / After verification · Page <?php echo $pageIndex + 1; ?> of <?php echo count($photoPages); ?></span>
            </div>
            <div class="pair-grid">
                <?php foreach ($pagePairs as $pairIndex => $pair): ?>
                    <?php
                    $finding = $pair['finding'];
                    $beforeImage = $pair['before'];
                    $afterImage = $pair['after'];
                    $remarks = trim((string) ($finding['remarks'] ?? ''));
                    $issueLine = 'Issue: ' . (string) $finding['finding_name']
                        . ' | Location: ' . (string) $finding['location']
                        . ' | Remarks: ' . ($remarks !== '' ? $remarks : '-');
                    ?>
                    <div class="pair-row">
                        <section class="photo-cell before">
                            <div class="photo-title">
                                <span>BEFORE</span>
                                <span><?php echo hqiEscape((string) $finding['severity']); ?></span>
                            </div>
                            <a href="../<?php echo hqiEscape((string) $beforeImage['image_path']); ?>" target="_blank" rel="noopener">
                                <img src="../<?php echo hqiEscape((string) $beforeImage['image_path']); ?>" alt="Before inspection photo">
                            </a>
                            <div class="issue-mini" title="<?php echo hqiEscape($issueLine); ?>">
                                <span><strong>Issue:</strong> <?php echo hqiEscape((string) $finding['finding_name']); ?> | <strong>Location:</strong> <?php echo hqiEscape((string) $finding['location']); ?> | <strong>Remarks:</strong> <?php echo hqiEscape($remarks !== '' ? $remarks : '-'); ?></span>
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
                                <a href="../<?php echo hqiEscape((string) $afterImage['image_path']); ?>" target="_blank" rel="noopener">
                                    <img src="../<?php echo hqiEscape((string) $afterImage['image_path']); ?>" alt="After rectification photo">
                                </a>
                                <div class="caption"><?php echo hqiEscape((string) ($afterImage['caption'] ?: $afterImage['original_name'])); ?></div>
                            <?php endif; ?>
                        </section>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="footer"><span>Generated from CPMSPro · <?php echo date('Y-m-d H:i:s'); ?></span><span>System record at time of generation</span></div>
</main>
</body>
</html>
