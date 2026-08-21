<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/compliance_service.php';
cpmsRequire('compliance.view', $conn);
if (!isset($propertyPortalUser) || !is_array($propertyPortalUser)) { $propertyPortalUser = []; }
$propertyId = cpmsCompliancePropertyId($propertyPortalUser);
if ($propertyId < 1) { http_response_code(403); exit('Property context is unavailable.'); }
if (!cpmsComplianceTablesReady($conn)) { exit('Compliance tables are not ready. Import compliance_sprint_2_6.sql first.'); }
$pageTitle = 'Compliance';
$activeMenu = 'compliance';
$filter = trim((string) ($_GET['filter'] ?? ''));
if (!in_array($filter, ['', 'overdue', 'due_30', 'upcoming', 'inactive'], true)) { $filter = ''; }
$summary = cpmsComplianceSummary($conn, $propertyId);
$records = cpmsComplianceList($conn, $propertyId, $filter);
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/compliance-module.css">
<div class="compliance-wrap">
 <div class="compliance-head"><div><span class="compliance-eyebrow">COMPLIANCE MANAGEMENT</span><h1>Compliance Calendar</h1><p>Track mandatory checks, due dates and completion history.</p></div><?php if(cpmsCan('compliance.create',$conn)):?><a class="compliance-btn compliance-btn-primary" href="compliance_create.php">+ New Compliance Item</a><?php endif;?></div>
 <div class="compliance-grid">
  <a class="compliance-kpi" href="compliance.php"><strong><?php echo $summary['total']; ?></strong><span>Total</span></a>
  <a class="compliance-kpi" href="compliance.php?filter=overdue"><strong><?php echo $summary['overdue']; ?></strong><span>Overdue</span></a>
  <a class="compliance-kpi" href="compliance.php?filter=due_30"><strong><?php echo $summary['due_30']; ?></strong><span>Due within 30 days</span></a>
  <a class="compliance-kpi" href="compliance.php?filter=upcoming"><strong><?php echo $summary['upcoming']; ?></strong><span>Upcoming</span></a>
  <a class="compliance-kpi" href="compliance.php?filter=inactive"><strong><?php echo $summary['inactive']; ?></strong><span>Inactive</span></a>
 </div>
 <div class="compliance-panel"><div class="compliance-panel-head"><strong>Compliance Items</strong><span><?php echo count($records); ?> record(s)</span></div>
 <?php if (!$records): ?><div class="compliance-empty">No compliance item found.</div><?php else: ?><div class="compliance-table-wrap"><table class="compliance-table"><thead><tr><th>Item</th><th>Category</th><th>Location</th><th>Frequency</th><th>Next Due</th><th>Status</th><th></th></tr></thead><tbody>
 <?php foreach ($records as $record): $due=(string)$record['next_due_date']; $today=date('Y-m-d'); $class=$due<$today?'is-overdue':($due<=date('Y-m-d',strtotime('+30 days'))?'is-due':'is-upcoming'); ?>
 <tr><td><strong><?php echo cpmsComplianceEscape($record['title']); ?></strong><br><small><?php echo cpmsComplianceEscape($record['responsible_person']); ?></small></td><td><?php echo cpmsComplianceEscape($record['category']); ?></td><td><?php echo cpmsComplianceEscape($record['location']); ?></td><td><?php echo cpmsComplianceEscape(str_replace('_',' ',(string)$record['frequency_type'])); ?></td><td><span class="compliance-badge <?php echo $record['status']==='active'?$class:''; ?>"><?php echo cpmsComplianceEscape(date('d/m/Y',strtotime($due))); ?></span></td><td><?php echo cpmsComplianceEscape($record['status']); ?></td><td><a href="compliance_view.php?id=<?php echo (int)$record['id']; ?>">View</a></td></tr>
 <?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
