<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/preventive_maintenance_service.php';
cpmsRequire('maintenance.view', $conn);
$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$filter = trim((string) ($_GET['filter'] ?? ''));
$summary = cpmsPmSummary($conn, $propertyId);
$schedules = cpmsPmSchedules($conn, $propertyId, $filter);
$pageTitle = 'Preventive Maintenance'; $activeMenu = 'preventive_maintenance';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.pm-wrap{padding:24px}.pm-head{display:flex;justify-content:space-between;gap:15px;align-items:center}.pm-btn{display:inline-block;background:#173b73;color:#fff;padding:10px 14px;border-radius:9px;text-decoration:none;font-weight:800}.pm-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}.pm-kpi,.pm-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px}.pm-kpi{text-decoration:none;color:inherit}.pm-kpi strong{display:block;font-size:30px;color:#173b73}.pm-table{width:100%;border-collapse:collapse}.pm-table th,.pm-table td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left}.pm-badge{display:inline-block;padding:5px 9px;border-radius:999px;background:#e2e8f0;font-weight:800;font-size:12px}.pm-overdue{background:#fee2e2;color:#991b1b}.pm-due{background:#fef3c7;color:#92400e}.pm-active{background:#dcfce7;color:#166534}@media(max-width:800px){.pm-wrap{padding:14px}.pm-head{align-items:start;flex-direction:column}.pm-grid{grid-template-columns:repeat(2,1fr)}.pm-table{display:block;overflow:auto}}
</style>
<div class="pm-wrap"><div class="pm-head"><div><h1>Preventive Maintenance</h1><p>Recurring asset service schedules and due-date monitoring.</p></div>
<div><a class="pm-btn" href="pm_calendar.php">Monthly Calendar</a>
<?php if (cpmsCan('maintenance.analytics', $conn)): ?><a class="pm-btn" href="pm_analytics.php">Performance Analytics</a><?php endif; ?>
<?php if (cpmsCan('maintenance.budget', $conn)): ?><a class="pm-btn" href="pm_budget.php">Budget Control</a><?php endif; ?>
<?php if (cpmsCan('maintenance.report', $conn)): ?><a class="pm-btn" href="pm_final_report.php">Final Report</a><a class="pm-btn" href="pm_health.php">Module Health</a><?php endif; ?>
<?php if (cpmsCan('maintenance.manage', $conn)): ?><a class="pm-btn" href="pm_schedule_create.php">+ New Schedule</a><?php endif; ?></div></div>
<section class="pm-grid">
<a class="pm-kpi" href="preventive_maintenance.php"><strong><?php echo $summary['total']; ?></strong><span>Total Schedules</span></a>
<a class="pm-kpi" href="?filter=active"><strong><?php echo $summary['active']; ?></strong><span>Active</span></a>
<a class="pm-kpi" href="?filter=due_soon"><strong><?php echo $summary['due_soon']; ?></strong><span>Due within 7 days</span></a>
<a class="pm-kpi" href="?filter=overdue"><strong><?php echo $summary['overdue']; ?></strong><span>Overdue</span></a>
</section>
<section class="pm-card"><table class="pm-table"><thead><tr><th>Asset / Schedule</th><th>Frequency</th><th>Assigned To</th><th>Next Due</th><th>Priority</th><th>Status</th><th></th></tr></thead><tbody>
<?php if (!$schedules): ?><tr><td colspan="7">No maintenance schedule found.</td></tr><?php endif; ?>
<?php foreach ($schedules as $schedule): ?><tr>
<td><strong><?php echo cpmsPmEscape((string) $schedule['asset_name']); ?></strong><br><?php echo cpmsPmEscape((string) $schedule['schedule_name']); ?></td>
<td>Every <?php echo (int) $schedule['frequency_interval']; ?> <?php echo cpmsPmEscape((string) $schedule['frequency_unit']); ?></td>
<td><?php echo cpmsPmEscape((string) ($schedule['assigned_name'] ?: $schedule['vendor_name'] ?: '-')); ?></td>
<td><?php echo cpmsPmEscape((string) $schedule['next_due_date']); ?></td><td><?php echo cpmsPmEscape((string) $schedule['priority']); ?></td>
<td><span class="pm-badge <?php echo $schedule['due_status'] === 'Overdue' ? 'pm-overdue' : ($schedule['due_status'] === 'Due Soon' ? 'pm-due' : 'pm-active'); ?>"><?php echo cpmsPmEscape((string) $schedule['due_status']); ?></span></td>
<td><a href="pm_schedule_view.php?id=<?php echo (int) $schedule['id']; ?>">View</a></td></tr><?php endforeach; ?>
</tbody></table></section></div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
