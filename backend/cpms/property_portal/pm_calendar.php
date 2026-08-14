<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__)
    . '/includes/preventive_maintenance_service.php';

cpmsRequire('maintenance.view', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$requestedMonth = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $requestedMonth)) {
    $requestedMonth = date('Y-m');
}

$month = DateTimeImmutable::createFromFormat(
    '!Y-m',
    $requestedMonth
);
if (!$month) {
    $month = new DateTimeImmutable('first day of this month');
}

$monthStart = $month->format('Y-m-01');
$monthEnd = $month->format('Y-m-t');
$previousMonth = $month->modify('-1 month')->format('Y-m');
$nextMonth = $month->modify('+1 month')->format('Y-m');
$monthLabel = $month->format('F Y');
$daysInMonth = (int) $month->format('t');
$firstWeekday = (int) $month->format('N');
$today = date('Y-m-d');

$events = [];
$statistics = [
    'scheduled' => 0,
    'completed' => 0,
    'overdue' => 0,
    'critical' => 0,
];

$scheduleStmt = $conn->prepare(
    "SELECT id, schedule_name, asset_name, next_due_date,
            priority, assigned_name, vendor_name, status
     FROM cpms_pm_schedules
     WHERE property_id = ?
       AND next_due_date BETWEEN ? AND ?
     ORDER BY next_due_date, priority DESC, id"
);
if ($scheduleStmt) {
    $scheduleStmt->bind_param(
        'iss',
        $propertyId,
        $monthStart,
        $monthEnd
    );
    $scheduleStmt->execute();
    $result = $scheduleStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $date = (string) $row['next_due_date'];
        $status = (string) $row['status'];
        $eventClass = 'calendar-scheduled';
        $eventLabel = 'Scheduled';
        if ($status === 'active' && $date < $today) {
            $eventClass = 'calendar-overdue';
            $eventLabel = 'Overdue';
            $statistics['overdue']++;
        } elseif (
            $status === 'active'
            && $date <= date('Y-m-d', strtotime('+7 days'))
        ) {
            $eventClass = 'calendar-due';
            $eventLabel = 'Due Soon';
        }
        if ((string) $row['priority'] === 'Critical') {
            $statistics['critical']++;
        }
        $statistics['scheduled']++;
        $events[$date][] = [
            'type' => 'schedule',
            'class' => $eventClass,
            'label' => $eventLabel,
            'id' => (int) $row['id'],
            'title' => (string) $row['schedule_name'],
            'asset' => (string) $row['asset_name'],
            'priority' => (string) $row['priority'],
            'assignee' => (string) (
                $row['assigned_name']
                ?: $row['vendor_name']
                ?: 'Unassigned'
            ),
        ];
    }
    $scheduleStmt->close();
}

$completionStmt = $conn->prepare(
    "SELECT l.id, l.schedule_id, l.completed_date,
            l.result, l.performed_by_name,
            s.schedule_name, s.asset_name
     FROM cpms_pm_work_logs l
     INNER JOIN cpms_pm_schedules s
        ON s.id = l.schedule_id
       AND s.property_id = l.property_id
     WHERE l.property_id = ?
       AND l.completed_date BETWEEN ? AND ?
     ORDER BY l.completed_date, l.id"
);
if ($completionStmt) {
    $completionStmt->bind_param(
        'iss',
        $propertyId,
        $monthStart,
        $monthEnd
    );
    $completionStmt->execute();
    $result = $completionStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $date = (string) $row['completed_date'];
        $statistics['completed']++;
        $events[$date][] = [
            'type' => 'completion',
            'class' => 'calendar-completed',
            'label' => 'Completed',
            'id' => (int) $row['schedule_id'],
            'title' => (string) $row['schedule_name'],
            'asset' => (string) $row['asset_name'],
            'priority' => (string) $row['result'],
            'assignee' => (string) (
                $row['performed_by_name'] ?: 'CPMS User'
            ),
        ];
    }
    $completionStmt->close();
}

$pageTitle = 'Maintenance Calendar';
$activeMenu = 'preventive_maintenance';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.calendar-wrap{padding:24px}.calendar-header{display:flex;justify-content:space-between;align-items:center;gap:15px}.calendar-actions{display:flex;gap:8px;align-items:center}.calendar-btn{display:inline-block;background:#173b73;color:#fff;padding:10px 14px;border-radius:9px;text-decoration:none;font-weight:800}.calendar-btn.secondary{background:#e8eef7;color:#173b73}.calendar-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}.calendar-kpi,.calendar-panel{background:#fff;border:1px solid #e2e8f0;border-radius:14px}.calendar-kpi{padding:16px}.calendar-kpi strong{display:block;font-size:28px;color:#173b73}.calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border-left:1px solid #e2e8f0;border-top:1px solid #e2e8f0}.calendar-weekday{background:#edf2f9;padding:11px;text-align:center;font-weight:800}.calendar-day,.calendar-empty{min-height:145px;padding:9px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0}.calendar-empty{background:#f8fafc}.calendar-date{display:flex;justify-content:space-between;font-weight:800;margin-bottom:8px}.calendar-today .calendar-date span{background:#173b73;color:#fff;border-radius:999px;min-width:27px;height:27px;display:inline-flex;align-items:center;justify-content:center}.calendar-event{display:block;padding:7px;margin:6px 0;border-radius:7px;text-decoration:none;color:#0f172a;font-size:12px;border-left:4px solid #64748b;background:#f1f5f9}.calendar-event strong,.calendar-event small{display:block}.calendar-scheduled{border-color:#2563eb;background:#dbeafe}.calendar-due{border-color:#d97706;background:#fef3c7}.calendar-overdue{border-color:#dc2626;background:#fee2e2}.calendar-completed{border-color:#16a34a;background:#dcfce7}.calendar-legend{display:flex;gap:14px;flex-wrap:wrap;padding:15px}.calendar-legend span:before{content:"";display:inline-block;width:11px;height:11px;border-radius:3px;margin-right:5px;background:#64748b}.calendar-legend .due:before{background:#d97706}.calendar-legend .overdue:before{background:#dc2626}.calendar-legend .completed:before{background:#16a34a}.calendar-agenda{display:none}.agenda-date{padding:12px;background:#edf2f9;font-weight:800}.agenda-event{padding:12px;border-bottom:1px solid #e2e8f0}.agenda-event a{font-weight:800;color:#173b73;text-decoration:none}@media(max-width:900px){.calendar-wrap{padding:14px}.calendar-header{align-items:flex-start;flex-direction:column}.calendar-kpis{grid-template-columns:repeat(2,1fr)}.calendar-desktop{display:none}.calendar-agenda{display:block}.calendar-panel{overflow:hidden}.calendar-actions{flex-wrap:wrap}}
</style>
<div class="calendar-wrap">
    <div class="calendar-header">
        <div>
            <a href="preventive_maintenance.php">← Preventive Maintenance</a>
            <h1>Maintenance Calendar</h1>
            <p>Monthly schedule and completed maintenance view.</p>
        </div>
        <div class="calendar-actions">
            <a class="calendar-btn secondary"
               href="?month=<?php echo cpmsPmEscape($previousMonth); ?>">←</a>
            <strong><?php echo cpmsPmEscape($monthLabel); ?></strong>
            <a class="calendar-btn secondary"
               href="?month=<?php echo cpmsPmEscape($nextMonth); ?>">→</a>
            <a class="calendar-btn"
               href="?month=<?php echo date('Y-m'); ?>">Today</a>
        </div>
    </div>

    <section class="calendar-kpis">
        <div class="calendar-kpi"><strong><?php echo $statistics['scheduled']; ?></strong>Scheduled</div>
        <div class="calendar-kpi"><strong><?php echo $statistics['completed']; ?></strong>Completed</div>
        <div class="calendar-kpi"><strong><?php echo $statistics['overdue']; ?></strong>Overdue</div>
        <div class="calendar-kpi"><strong><?php echo $statistics['critical']; ?></strong>Critical</div>
    </section>

    <section class="calendar-panel calendar-desktop">
        <div class="calendar-legend">
            <span>Scheduled</span><span class="due">Due Soon</span>
            <span class="overdue">Overdue</span>
            <span class="completed">Completed</span>
        </div>
        <div class="calendar-grid">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday): ?>
                <div class="calendar-weekday"><?php echo $weekday; ?></div>
            <?php endforeach; ?>
            <?php for ($blank = 1; $blank < $firstWeekday; $blank++): ?>
                <div class="calendar-empty"></div>
            <?php endfor; ?>
            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                $date = $month->format('Y-m-')
                    . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                $dayEvents = $events[$date] ?? [];
            ?>
                <div class="calendar-day <?php echo $date === $today ? 'calendar-today' : ''; ?>">
                    <div class="calendar-date"><span><?php echo $day; ?></span></div>
                    <?php foreach ($dayEvents as $event): ?>
                        <a class="calendar-event <?php echo cpmsPmEscape($event['class']); ?>"
                           href="pm_schedule_view.php?id=<?php echo (int) $event['id']; ?>">
                            <strong><?php echo cpmsPmEscape($event['title']); ?></strong>
                            <small><?php echo cpmsPmEscape($event['asset']); ?></small>
                            <small><?php echo cpmsPmEscape($event['label']); ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>
    </section>

    <section class="calendar-panel calendar-agenda">
        <?php if (!$events): ?>
            <div class="agenda-event">No maintenance event this month.</div>
        <?php endif; ?>
        <?php ksort($events); foreach ($events as $date => $dayEvents): ?>
            <div class="agenda-date"><?php echo cpmsPmEscape(
                date('d M Y', strtotime($date))
            ); ?></div>
            <?php foreach ($dayEvents as $event): ?>
                <div class="agenda-event">
                    <a href="pm_schedule_view.php?id=<?php echo (int) $event['id']; ?>">
                        <?php echo cpmsPmEscape($event['title']); ?>
                    </a>
                    <div><?php echo cpmsPmEscape($event['asset']); ?>
                        · <?php echo cpmsPmEscape($event['label']); ?></div>
                    <small><?php echo cpmsPmEscape($event['assignee']); ?></small>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
