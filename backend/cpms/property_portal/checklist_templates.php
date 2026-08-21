<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/checklist_service.php';
cpmsRequire('inspection.checklist.view', $conn);
$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$templates = cpmsChecklistTemplates($conn, $propertyId);
$pageTitle = 'Checklist Templates';
$activeMenu = 'inspection';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.ck-wrap{padding:24px}.ck-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.ck-btn{display:inline-block;background:#173b73;color:#fff;padding:10px 14px;border-radius:9px;text-decoration:none;font-weight:800}.ck-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:20px}.ck-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px}.ck-card h2{margin-top:0}.ck-meta{color:#64748b;font-size:14px}@media(max-width:800px){.ck-grid{grid-template-columns:1fr}.ck-head{align-items:start;flex-direction:column}}
</style>
<div class="ck-wrap">
<div class="ck-head"><div><h1>Inspection Checklist Templates</h1><p>Reusable weighted checklists for this property.</p></div>
<div><a class="ck-btn" href="inspections.php">Inspections</a>
<?php if (cpmsCan('inspection.checklist.manage', $conn)): ?><a class="ck-btn" href="checklist_template_create.php">+ New Template</a><?php endif; ?></div></div>
<section class="ck-grid">
<?php if (!$templates): ?><article class="ck-card">No checklist template created.</article><?php endif; ?>
<?php foreach ($templates as $template): ?><article class="ck-card">
<h2><?php echo cpmsChecklistEscape((string) $template['template_name']); ?></h2>
<p><?php echo cpmsChecklistEscape((string) ($template['description'] ?: 'No description')); ?></p>
<div class="ck-meta">Category: <?php echo cpmsChecklistEscape((string) $template['category']); ?><br>
Items: <?php echo (int) $template['item_count']; ?><br>
Passing score: <?php echo cpmsChecklistEscape((string) $template['passing_score']); ?>%</div>
</article><?php endforeach; ?>
</section></div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
