<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
cpmsRequire('visitor.monitor', $conn);
if (!cpmsModuleEnabled('visitor_management', $currentPropertyModules)) {
    http_response_code(403);
    exit('Visitor Management is not enabled for this property.');
}
require_once dirname(__DIR__) . '/cpms/includes/visitor_service.php';
require_once dirname(__DIR__) . '/cpms/includes/resident_notification_service.php';

function cpmsVisitorAdminEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsVisitorAdminExecute(mysqli_stmt $stmt, string $message): void
{
    if (!$stmt->execute()) {
        throw new RuntimeException($message);
    }
}

function cpmsVisitorAdminRedirect(string $date): void
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        $date = date('Y-m-d');
    }
    header('Location: visitors.php?date=' . rawurlencode($date));
    exit;
}

$selectedDate = trim((string) ($_GET['date'] ?? $_POST['return_date'] ?? date('Y-m-d')));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) !== 1) {
    $selectedDate = date('Y-m-d');
}
$selectedStatus = trim((string) ($_GET['status'] ?? 'All'));
$allowedStatuses = [
    'All',
    'Expected',
    'Checked In',
    'Checked Out',
    'Cancelled',
    'Denied',
];
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = 'All';
}
$search = trim((string) ($_GET['q'] ?? ''));
$actorUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$canViewWatchlist = cpmsCan('visitor.watchlist.view', $conn);
$canExportVisitors = cpmsCan('visitor.export', $conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        if (!propertyPortalVerifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Token keselamatan tidak sah.');
        }
        $action = trim((string) ($_POST['action'] ?? ''));
        $passId = (int) ($_POST['pass_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($action !== 'cancel' || $passId < 1 || strlen($reason) > 500) {
            throw new RuntimeException('Tindakan pelawat tidak sah.');
        }

        $conn->begin_transaction();
        $transactionStarted = true;
        $stmt = $conn->prepare(
            "SELECT id,resident_id,pass_code,visitor_name,visitor_status
             FROM cpms_visitor_passes
             WHERE id=? AND property_id=? LIMIT 1 FOR UPDATE"
        );
        if (!$stmt) {
            throw new RuntimeException('Visitor pass query failed.');
        }
        $stmt->bind_param('ii', $passId, $currentPropertyId);
        cpmsVisitorAdminExecute($stmt, 'Visitor pass query failed.');
        $pass = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pass || (string) $pass['visitor_status'] !== 'Expected') {
            throw new RuntimeException('Hanya pas Expected boleh dibatalkan.');
        }

        $stmt = $conn->prepare(
            "UPDATE cpms_visitor_passes SET
                visitor_status='Cancelled',cancelled_at=NOW(),
                cancelled_by_system_user_id=NULLIF(?,0),security_notes=
                    CASE WHEN ?='' THEN security_notes ELSE ? END
             WHERE id=? AND property_id=? AND visitor_status='Expected'"
        );
        if (!$stmt) {
            throw new RuntimeException('Visitor pass cancellation failed.');
        }
        $stmt->bind_param(
            'issii',
            $actorUserId,
            $reason,
            $reason,
            $passId,
            $currentPropertyId
        );
        cpmsVisitorAdminExecute($stmt, 'Visitor pass cancellation failed.');
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Status pas pelawat telah berubah.');
        }
        $stmt->close();
        cpmsVisitorEvent(
            $conn,
            $currentPropertyId,
            $passId,
            'Cancelled by Management',
            $actorUserId,
            0,
            $reason
        );
        cpmsResidentNotify(
            $conn,
            $currentPropertyId,
            (int) $pass['resident_id'],
            'visitor',
            'Pas pelawat dibatalkan',
            'Pas ' . $pass['pass_code'] . ' untuk '
                . $pass['visitor_name'] . ' telah dibatalkan oleh pengurusan.',
            'visitor_passes.php'
        );
        $conn->commit();
        $transactionStarted = false;
        $_SESSION['visitor_admin_flash'] = [
            'type' => 'success',
            'message' => 'Pas pelawat telah dibatalkan.',
        ];
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        $_SESSION['visitor_admin_flash'] = [
            'type' => 'danger',
            'message' => $exception->getMessage(),
        ];
    }
    cpmsVisitorAdminRedirect($selectedDate);
}

$counts = [
    'Expected' => 0,
    'Checked In' => 0,
    'Checked Out' => 0,
    'Cancelled' => 0,
    'Denied' => 0,
];
$stmt = $conn->prepare(
    "SELECT visitor_status,COUNT(*) AS total
     FROM cpms_visitor_passes
     WHERE property_id=? AND visit_date=? GROUP BY visitor_status"
);
if ($stmt) {
    $stmt->bind_param('is', $currentPropertyId, $selectedDate);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $status = (string) $row['visitor_status'];
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['total'];
            }
        }
    }
    $stmt->close();
}

$sql = "SELECT v.*,r.full_name AS resident_name,g1.full_name AS checkin_guard,
               g2.full_name AS checkout_guard,
               (SELECT e.event_notes
                FROM cpms_visitor_events e
                WHERE e.property_id=v.property_id
                  AND e.pass_id=v.id
                  AND e.event_type='Entry Denied'
                ORDER BY e.event_at DESC,e.id DESC LIMIT 1
               ) AS denial_reason
        FROM cpms_visitor_passes v
        INNER JOIN cpms_residents r
           ON r.id=v.resident_id AND r.property_id=v.property_id
        LEFT JOIN security_guards g1
           ON g1.id=v.checked_in_by_guard_id AND g1.property_id=v.property_id
        LEFT JOIN security_guards g2
           ON g2.id=v.checked_out_by_guard_id AND g2.property_id=v.property_id
        WHERE v.property_id=? AND v.visit_date=?";
$hasStatusFilter = $selectedStatus !== 'All';
$hasSearchFilter = $search !== '';
if ($hasStatusFilter) {
    $sql .= ' AND v.visitor_status=?';
}
if ($hasSearchFilter) {
    $sql .= " AND (v.pass_code LIKE ? OR v.visitor_name LIKE ?
                    OR v.vehicle_no LIKE ? OR v.host_unit LIKE ?)";
    $like = '%' . $search . '%';
}
$sql .= " ORDER BY CASE v.visitor_status
    WHEN 'Checked In' THEN 0 WHEN 'Expected' THEN 1 ELSE 2 END,
    v.expected_start_time LIMIT 500";
$stmt = $conn->prepare($sql);
$visitors = [];
if ($stmt) {
    if ($hasStatusFilter && $hasSearchFilter) {
        $stmt->bind_param(
            'issssss',
            $currentPropertyId,
            $selectedDate,
            $selectedStatus,
            $like,
            $like,
            $like,
            $like
        );
    } elseif ($hasStatusFilter) {
        $stmt->bind_param(
            'iss',
            $currentPropertyId,
            $selectedDate,
            $selectedStatus
        );
    } elseif ($hasSearchFilter) {
        $stmt->bind_param(
            'isssss',
            $currentPropertyId,
            $selectedDate,
            $like,
            $like,
            $like,
            $like
        );
    } else {
        $stmt->bind_param('is', $currentPropertyId, $selectedDate);
    }
    if ($stmt->execute()) {
        $visitors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

$flash = $_SESSION['visitor_admin_flash'] ?? null;
unset($_SESSION['visitor_admin_flash']);
$pageTitle = 'Visitor Management';
$activeMenu = 'visitors';
$pageStyles = ['assets/visitor-management.css?v=3600'];
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<section class="visitor-admin-heading"><div><span class="visitor-admin-label">PROPERTY VISITOR CONTROL</span><h1>Visitor Management</h1><p>Pantau ketibaan, pelawat di dalam premis dan rekod keluar untuk <?php echo cpmsVisitorAdminEscape($currentPropertyName); ?> sahaja.</p></div><div class="visitor-admin-heading-actions"><?php if ($canViewWatchlist): ?><a href="visitor_watchlist.php">Visitor Watchlist</a><?php endif; ?><?php if ($canExportVisitors): ?><a href="visitor_export.php?date=<?php echo rawurlencode($selectedDate); ?>&amp;status=<?php echo rawurlencode($selectedStatus); ?>">Export CSV</a><?php endif; ?><a href="../../security_visitors.php" target="_blank" rel="noopener">Buka Security Register ↗</a></div></section>
<?php if (is_array($flash)): ?><div class="visitor-admin-alert visitor-admin-alert--<?php echo cpmsVisitorAdminEscape((string) ($flash['type'] ?? 'success')); ?>"><?php echo cpmsVisitorAdminEscape((string) ($flash['message'] ?? '')); ?></div><?php endif; ?>
<section class="visitor-admin-stats"><?php foreach ($counts as $status => $total): ?><a href="visitors.php?date=<?php echo rawurlencode($selectedDate); ?>&amp;status=<?php echo rawurlencode($status); ?>" class="<?php echo $selectedStatus === $status ? 'active' : ''; ?>"><span><?php echo cpmsVisitorAdminEscape($status); ?></span><strong><?php echo $total; ?></strong></a><?php endforeach; ?><a href="visitors.php?date=<?php echo rawurlencode($selectedDate); ?>&amp;status=All" class="<?php echo $selectedStatus === 'All' ? 'active' : ''; ?>"><span>All</span><strong><?php echo array_sum($counts); ?></strong></a></section>
<form class="visitor-admin-filter" method="get"><label>Tarikh<input type="date" name="date" value="<?php echo cpmsVisitorAdminEscape($selectedDate); ?>"></label><label>Status<select name="status"><?php foreach ($allowedStatuses as $status): ?><option <?php echo $selectedStatus === $status ? 'selected' : ''; ?>><?php echo cpmsVisitorAdminEscape($status); ?></option><?php endforeach; ?></select></label><label>Carian<input name="q" value="<?php echo cpmsVisitorAdminEscape($search); ?>" placeholder="Kod, nama, kenderaan atau unit"></label><button>Papar</button></form>
<section class="visitor-admin-panel"><div class="visitor-admin-panel-head"><div><span class="visitor-admin-label"><?php echo cpmsVisitorAdminEscape(strtoupper($selectedStatus)); ?></span><h2>Visitor Register</h2></div><span><?php echo count($visitors); ?> rekod</span></div><?php if (!$visitors): ?><div class="visitor-admin-empty">Tiada rekod pelawat bagi pilihan ini.</div><?php else: ?><div class="visitor-admin-list"><?php foreach ($visitors as $visitor): ?><article class="visitor-admin-card status-<?php echo cpmsVisitorAdminEscape(cpmsVisitorStatusClass((string) $visitor['visitor_status'])); ?>"><div class="visitor-admin-card-top"><div><span><?php echo cpmsVisitorAdminEscape($visitor['pass_code']); ?></span><h3><?php echo cpmsVisitorAdminEscape($visitor['visitor_name']); ?></h3><p><?php echo cpmsVisitorAdminEscape($visitor['vehicle_no'] ?: ($visitor['visitor_phone'] ?: '-')); ?></p></div><strong><?php echo cpmsVisitorAdminEscape($visitor['visitor_status']); ?></strong></div><dl><div><dt>Host Unit</dt><dd><?php echo cpmsVisitorAdminEscape($visitor['host_block'] . ' / ' . $visitor['host_unit']); ?></dd></div><div><dt>Resident</dt><dd><?php echo cpmsVisitorAdminEscape($visitor['resident_name']); ?></dd></div><div><dt>Expected</dt><dd><?php echo cpmsVisitorAdminEscape(substr((string) $visitor['expected_start_time'], 0, 5)); ?></dd></div><div><dt>Source</dt><dd><?php echo cpmsVisitorAdminEscape($visitor['registration_source']); ?></dd></div></dl><p class="visitor-admin-purpose"><?php echo cpmsVisitorAdminEscape($visitor['visit_purpose']); ?></p><div class="visitor-admin-timeline"><span>IN: <?php echo !empty($visitor['checkin_at']) ? cpmsVisitorAdminEscape(date('H:i', strtotime((string) $visitor['checkin_at'])) . ' · ' . ($visitor['checkin_guard'] ?: 'Security')) : '-'; ?></span><span>OUT: <?php echo !empty($visitor['checkout_at']) ? cpmsVisitorAdminEscape(date('H:i', strtotime((string) $visitor['checkout_at'])) . ' · ' . ($visitor['checkout_guard'] ?: 'Security')) : '-'; ?></span></div><?php if (!empty($visitor['security_notes'])): ?><p class="visitor-admin-note"><strong>Security:</strong> <?php echo cpmsVisitorAdminEscape($visitor['security_notes']); ?></p><?php endif; ?><?php if ((string) $visitor['visitor_status'] === 'Expected'): ?><form method="post" class="visitor-admin-cancel"><input type="hidden" name="csrf_token" value="<?php echo cpmsVisitorAdminEscape(propertyPortalCsrfToken()); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="pass_id" value="<?php echo (int) $visitor['id']; ?>"><input type="hidden" name="return_date" value="<?php echo cpmsVisitorAdminEscape($selectedDate); ?>"><input name="reason" maxlength="500" placeholder="Alasan (pilihan)"><button>Batalkan Pas</button></form><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
