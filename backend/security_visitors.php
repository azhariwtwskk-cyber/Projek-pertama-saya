<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/cpms/db.php';
require_once __DIR__ . '/cpms/cpms/includes/visitor_service.php';
require_once __DIR__ . '/cpms/cpms/includes/resident_notification_service.php';

function cpmsSecurityVisitorEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsSecurityVisitorExecute(
    mysqli_stmt $stmt,
    string $message
): void {
    if (!$stmt->execute()) {
        throw new RuntimeException($message);
    }
}

function cpmsSecurityVisitorBind(
    mysqli_stmt $stmt,
    string $types,
    array &$values
): void {
    $arguments = [$types];
    foreach ($values as $index => &$value) {
        $arguments[] = &$value;
    }
    unset($value);
    call_user_func_array([$stmt, 'bind_param'], $arguments);
}

function cpmsSecurityVisitorLanguage(): string
{
    $requested = strtolower(trim((string) ($_GET['lang'] ?? '')));
    if (in_array($requested, ['bm', 'en'], true)) {
        $_SESSION['cpms_security_visitor_language'] = $requested;
    }
    $language = (string) ($_SESSION['cpms_security_visitor_language'] ?? 'bm');
    return in_array($language, ['bm', 'en'], true) ? $language : 'bm';
}

function cpmsSecurityVisitorText(string $key): string
{
    $copy = [
        'bm' => [
            'title' => 'Daftar Pelawat Security',
            'intro' => 'Semak pas, daftar masuk, daftar keluar dan rekod walk-in.',
            'dashboard' => 'Dashboard Security',
            'today' => 'Hari Ini',
            'today_total' => 'Jumlah Hari Ini',
            'expected' => 'Dijangka',
            'inside' => 'Dalam Premis',
            'completed' => 'Selesai',
            'checked_out' => 'Telah Keluar',
            'checked_in' => 'Sudah Masuk',
            'cancelled' => 'Dibatalkan',
            'denied' => 'Ditolak',
            'watchlist' => 'Padanan Watchlist',
            'overdue' => 'Lewat Keluar',
            'all_statuses' => 'Semua Status',
            'live_operations' => 'Operasi Pelawat Hari Ini',
            'manual_confirmation' => 'QR hanya membuka rekod untuk semakan. Tekan Check-In selepas identiti disahkan.',
            'qr_not_found' => 'QR tidak sah atau pas bukan milik property ini.',
            'inside_all_dates' => 'Semua pelawat yang masih berada dalam property',
            'attention' => 'Perlu Perhatian Security',
            'overdue_attention' => 'pelawat masih belum check-out selepas waktu dijangka',
            'watchlist_attention' => 'rekod hari ini sepadan dengan watchlist',
            'checkin_blocked' => 'Check-in disekat oleh watchlist. Hubungi pengurusan.',
            'visit_date' => 'Tarikh Lawatan',
            'expected_end' => 'Jangkaan Keluar',
            'denial_reason' => 'Alasan Penolakan',
            'qr_found' => 'Rekod QR ditemui. Sahkan identiti dan butiran pelawat sebelum tindakan manual.',
            'records' => 'rekod',
            'visitor_register' => 'Daftar Pelawat',
            'status' => 'Status',
            'search' => 'Cari kod pas, nama, kenderaan atau unit',
            'show' => 'Cari',
            'scan_qr' => 'Imbas QR Pelawat',
            'stop_scan' => 'Hentikan Kamera',
            'scan_help' => 'Benarkan kamera dan halakan kepada QR pas pelawat.',
            'scan_unavailable' => 'Imbasan kamera tidak disokong. Gunakan kod pas dalam carian.',
            'walkin' => 'Daftar Walk-In',
            'host' => 'Resident / Unit Host',
            'visitor_name' => 'Nama pelawat',
            'phone' => 'Telefon',
            'vehicle' => 'No. kenderaan',
            'purpose' => 'Tujuan',
            'notes' => 'Catatan Security',
            'register_in' => 'Daftar & Check-In',
            'checkin' => 'Check-In',
            'checkout' => 'Check-Out',
            'deny' => 'Tolak Kemasukan',
            'watchlist_alert' => 'AMARAN WATCHLIST',
            'no_visitors' => 'Tiada rekod pelawat bagi tarikh ini.',
            'logout' => 'Log Keluar',
        ],
        'en' => [
            'title' => 'Security Visitor Register',
            'intro' => 'Verify passes, check visitors in or out, and record walk-ins.',
            'dashboard' => 'Security Dashboard',
            'today' => 'Today',
            'today_total' => 'Today Total',
            'expected' => 'Expected',
            'inside' => 'On Premises',
            'completed' => 'Completed',
            'checked_out' => 'Checked Out',
            'checked_in' => 'Checked In',
            'cancelled' => 'Cancelled',
            'denied' => 'Denied',
            'watchlist' => 'Watchlist Matches',
            'overdue' => 'Overdue Exit',
            'all_statuses' => 'All Statuses',
            'live_operations' => 'Today Visitor Operations',
            'manual_confirmation' => 'QR only opens the record for verification. Press Check-In after confirming identity.',
            'qr_not_found' => 'Invalid QR or the pass does not belong to this property.',
            'inside_all_dates' => 'All visitors currently remaining on the property',
            'attention' => 'Security Attention Required',
            'overdue_attention' => 'visitors remain checked in past the expected time',
            'watchlist_attention' => 'today records match the watchlist',
            'checkin_blocked' => 'Check-in is blocked by the watchlist. Contact management.',
            'visit_date' => 'Visit Date',
            'expected_end' => 'Expected Exit',
            'denial_reason' => 'Denial Reason',
            'qr_found' => 'QR record found. Verify identity and visitor details before taking a manual action.',
            'records' => 'records',
            'visitor_register' => 'Visitor Register',
            'status' => 'Status',
            'search' => 'Search pass code, name, vehicle or unit',
            'show' => 'Search',
            'scan_qr' => 'Scan Visitor QR',
            'stop_scan' => 'Stop Camera',
            'scan_help' => 'Allow camera access and point it at the visitor pass QR.',
            'scan_unavailable' => 'Camera scanning is unavailable. Use the pass code search.',
            'walkin' => 'Register Walk-In',
            'host' => 'Resident / Host Unit',
            'visitor_name' => 'Visitor name',
            'phone' => 'Phone',
            'vehicle' => 'Vehicle no.',
            'purpose' => 'Purpose',
            'notes' => 'Security notes',
            'register_in' => 'Register & Check-In',
            'checkin' => 'Check-In',
            'checkout' => 'Check-Out',
            'deny' => 'Deny Entry',
            'watchlist_alert' => 'WATCHLIST ALERT',
            'no_visitors' => 'There are no visitor records for this date.',
            'logout' => 'Logout',
        ],
    ];
    $language = cpmsSecurityVisitorLanguage();
    return (string) ($copy[$language][$key] ?? $copy['bm'][$key] ?? $key);
}

function cpmsSecurityVisitorCsrf(): string
{
    if (empty($_SESSION['cpms_security_visitor_csrf'])) {
        $_SESSION['cpms_security_visitor_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cpms_security_visitor_csrf'];
}

function cpmsSecurityVisitorFlash(string $type, string $message): void
{
    $_SESSION['cpms_security_visitor_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function cpmsSecurityVisitorRedirect(): void
{
    $language = cpmsSecurityVisitorLanguage();
    header('Location: security_visitors.php?lang=' . rawurlencode($language));
    exit;
}

$guardId = (int) ($_SESSION['security_guard_id'] ?? 0);
$lastActivity = (int) ($_SESSION['security_guard_last_activity'] ?? 0);
if ($lastActivity > 0 && (time() - $lastActivity) > 1800) {
    unset(
        $_SESSION['security_guard_id'],
        $_SESSION['security_guard_name'],
        $_SESSION['security_guard_type'],
        $_SESSION['security_guard_property_id'],
        $_SESSION['cpms_user_id'],
        $_SESSION['cpms_user_role'],
        $_SESSION['cpms_property_id']
    );
    session_regenerate_id(true);
    header('Location: cpms/login.php?expired=1');
    exit;
}
$_SESSION['security_guard_last_activity'] = time();
$sessionPropertyId = (int) (
    $_SESSION['security_guard_property_id']
    ?? $_SESSION['cpms_property_id']
    ?? 0
);
if ($guardId < 1) {
    header('Location: cpms/login.php');
    exit;
}

$stmt = $conn->prepare(
    "SELECT g.id,g.property_id,g.full_name,g.account_status,
            p.property_name,p.property_code
     FROM security_guards g
     INNER JOIN cpms_properties p ON p.id=g.property_id
     WHERE g.id=? LIMIT 1"
);
if (!$stmt) {
    throw new RuntimeException('Security account query failed.');
}
$stmt->bind_param('i', $guardId);
cpmsSecurityVisitorExecute($stmt, 'Security account query failed.');
$guard = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (
    !$guard || (string) $guard['account_status'] !== 'Active'
    || ($sessionPropertyId > 0 && (int) $guard['property_id'] !== $sessionPropertyId)
) {
    http_response_code(403);
    exit('Security account is inactive or property assignment is invalid.');
}
$propertyId = (int) $guard['property_id'];
$_SESSION['security_guard_property_id'] = $propertyId;
$_SESSION['cpms_property_id'] = $propertyId;
$actorUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$language = cpmsSecurityVisitorLanguage();

$moduleStmt = $conn->prepare(
    "SELECT is_enabled FROM cpms_property_modules
     WHERE property_id=? AND module_key='visitor_management' LIMIT 1"
);
if (!$moduleStmt) {
    throw new RuntimeException('Visitor module query failed.');
}
$moduleStmt->bind_param('i', $propertyId);
cpmsSecurityVisitorExecute($moduleStmt, 'Visitor module query failed.');
$module = $moduleStmt->get_result()->fetch_assoc();
$moduleStmt->close();
if (!$module || (int) $module['is_enabled'] !== 1) {
    http_response_code(403);
    exit('Visitor Management is not enabled for this property.');
}
$watchlistRows = cpmsVisitorWatchlistRows($conn, $propertyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if ($token === '' || !hash_equals(cpmsSecurityVisitorCsrf(), $token)) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'Invalid security token. Refresh and try again.'
                    : 'Token keselamatan tidak sah. Muat semula dan cuba lagi.'
            );
        }
        $action = trim((string) ($_POST['action'] ?? ''));

        if (in_array($action, ['checkin', 'checkout', 'deny'], true)) {
            $passId = (int) ($_POST['pass_id'] ?? 0);
            $eventNotes = trim((string) ($_POST['event_notes'] ?? ''));
            if ($passId < 1) {
                throw new RuntimeException('Invalid visitor pass.');
            }
            if (strlen($eventNotes) > 500) {
                throw new RuntimeException('Security note is too long.');
            }
            $conn->begin_transaction();
            $transactionStarted = true;
            $stmt = $conn->prepare(
                "SELECT * FROM cpms_visitor_passes
                 WHERE id=? AND property_id=? LIMIT 1 FOR UPDATE"
            );
            if (!$stmt) {
                throw new RuntimeException('Visitor pass query failed.');
            }
            $stmt->bind_param('ii', $passId, $propertyId);
            cpmsSecurityVisitorExecute($stmt, 'Visitor pass query failed.');
            $pass = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$pass) {
                throw new RuntimeException('Visitor pass not found.');
            }

            if ($action === 'checkin') {
                if (
                    (string) $pass['visitor_status'] !== 'Expected'
                    || (string) $pass['visit_date'] !== date('Y-m-d')
                ) {
                    throw new RuntimeException(
                        $language === 'en'
                            ? 'Only an expected pass for today can be checked in.'
                            : 'Hanya pas Dijangka untuk hari ini boleh didaftar masuk.'
                    );
                }
                $watchMatch = cpmsVisitorWatchlistMatch(
                    $watchlistRows,
                    (string) $pass['visitor_name'],
                    (string) ($pass['visitor_phone'] ?? ''),
                    (string) ($pass['vehicle_no'] ?? '')
                );
                if ($watchMatch !== null) {
                    throw new RuntimeException(
                        ($language === 'en'
                            ? 'Watchlist match. Do not check in; contact management. '
                            : 'Padanan watchlist. Jangan check-in; hubungi pengurusan. ')
                        . '[' . (string) $watchMatch['risk_level'] . '] '
                        . (string) $watchMatch['reason']
                    );
                }
                $stmt = $conn->prepare(
                    "UPDATE cpms_visitor_passes SET
                        visitor_status='Checked In',checkin_at=NOW(),
                        checked_in_by_guard_id=?
                     WHERE id=? AND property_id=? AND visitor_status='Expected'"
                );
                if (!$stmt) {
                    throw new RuntimeException('Visitor check-in failed.');
                }
                $stmt->bind_param('iii', $guardId, $passId, $propertyId);
                cpmsSecurityVisitorExecute($stmt, 'Visitor check-in failed.');
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException('Visitor pass status changed.');
                }
                $stmt->close();
                cpmsVisitorEvent(
                    $conn,
                    $propertyId,
                    $passId,
                    'Checked In',
                    $actorUserId,
                    $guardId,
                    $eventNotes
                );
                cpmsResidentNotify(
                    $conn,
                    $propertyId,
                    (int) $pass['resident_id'],
                    'visitor',
                    'Pelawat telah masuk',
                    $pass['visitor_name'] . ' telah check-in menggunakan '
                        . $pass['pass_code'] . '.',
                    'visitor_passes.php'
                );
                $success = $language === 'en'
                    ? 'Visitor checked in successfully.'
                    : 'Pelawat berjaya didaftar masuk.';
            } elseif ($action === 'checkout') {
                if ((string) $pass['visitor_status'] !== 'Checked In') {
                    throw new RuntimeException(
                        $language === 'en'
                            ? 'Only a checked-in visitor can be checked out.'
                            : 'Hanya pelawat yang sudah masuk boleh didaftar keluar.'
                    );
                }
                $stmt = $conn->prepare(
                    "UPDATE cpms_visitor_passes SET
                        visitor_status='Checked Out',checkout_at=NOW(),
                        checked_out_by_guard_id=?
                     WHERE id=? AND property_id=?
                       AND visitor_status='Checked In'"
                );
                if (!$stmt) {
                    throw new RuntimeException('Visitor check-out failed.');
                }
                $stmt->bind_param('iii', $guardId, $passId, $propertyId);
                cpmsSecurityVisitorExecute($stmt, 'Visitor check-out failed.');
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException('Visitor pass status changed.');
                }
                $stmt->close();
                cpmsVisitorEvent(
                    $conn,
                    $propertyId,
                    $passId,
                    'Checked Out',
                    $actorUserId,
                    $guardId,
                    $eventNotes
                );
                cpmsResidentNotify(
                    $conn,
                    $propertyId,
                    (int) $pass['resident_id'],
                    'visitor',
                    'Pelawat telah keluar',
                    $pass['visitor_name'] . ' telah check-out.',
                    'visitor_passes.php'
                );
                $success = $language === 'en'
                    ? 'Visitor checked out successfully.'
                    : 'Pelawat berjaya didaftar keluar.';
            } else {
                if ((string) $pass['visitor_status'] !== 'Expected') {
                    throw new RuntimeException(
                        $language === 'en'
                            ? 'Only an expected visitor can be denied.'
                            : 'Hanya pelawat berstatus Dijangka boleh ditolak.'
                    );
                }
                if ($eventNotes === '') {
                    throw new RuntimeException(
                        $language === 'en'
                            ? 'A denial reason is required.'
                            : 'Alasan penolakan diperlukan.'
                    );
                }
                $stmt = $conn->prepare(
                    "UPDATE cpms_visitor_passes SET
                        visitor_status='Denied'
                     WHERE id=? AND property_id=? AND visitor_status='Expected'"
                );
                if (!$stmt) {
                    throw new RuntimeException('Visitor denial failed.');
                }
                $stmt->bind_param('ii', $passId, $propertyId);
                cpmsSecurityVisitorExecute($stmt, 'Visitor denial failed.');
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException('Visitor pass status changed.');
                }
                $stmt->close();
                cpmsVisitorEvent(
                    $conn,
                    $propertyId,
                    $passId,
                    'Entry Denied',
                    $actorUserId,
                    $guardId,
                    $eventNotes
                );
                cpmsResidentNotify(
                    $conn,
                    $propertyId,
                    (int) $pass['resident_id'],
                    'visitor',
                    'Kemasukan pelawat ditolak',
                    $pass['visitor_name'] . ' tidak dibenarkan masuk. '
                        . 'Sila hubungi pengurusan jika penjelasan diperlukan.',
                    'visitor_passes.php'
                );
                $success = $language === 'en'
                    ? 'Visitor entry has been denied.'
                    : 'Kemasukan pelawat telah ditolak.';
            }
            $conn->commit();
            $transactionStarted = false;
            cpmsSecurityVisitorFlash('success', $success);
            cpmsSecurityVisitorRedirect();
        }

        if ($action === 'walkin') {
            $residentId = (int) ($_POST['resident_id'] ?? 0);
            $visitorName = trim((string) ($_POST['visitor_name'] ?? ''));
            $visitorPhone = trim((string) ($_POST['visitor_phone'] ?? ''));
            $vehicleNo = strtoupper(trim((string) ($_POST['vehicle_no'] ?? '')));
            $purpose = trim((string) ($_POST['visit_purpose'] ?? ''));
            $notes = trim((string) ($_POST['security_notes'] ?? ''));
            if (
                $residentId < 1 || $visitorName === ''
                || strlen($visitorName) > 180 || strlen($visitorPhone) > 40
                || strlen($vehicleNo) > 30 || $purpose === ''
                || strlen($purpose) > 250 || strlen($notes) > 500
            ) {
                throw new RuntimeException(
                    $language === 'en'
                        ? 'Complete the valid walk-in information.'
                        : 'Lengkapkan maklumat walk-in yang sah.'
                );
            }
            $watchMatch = cpmsVisitorWatchlistMatch(
                $watchlistRows,
                $visitorName,
                $visitorPhone,
                $vehicleNo
            );
            if ($watchMatch !== null) {
                throw new RuntimeException(
                    ($language === 'en'
                        ? 'Walk-in blocked by watchlist. Contact management. '
                        : 'Walk-in disekat oleh watchlist. Hubungi pengurusan. ')
                    . '[' . (string) $watchMatch['risk_level'] . '] '
                    . (string) $watchMatch['reason']
                );
            }
            $conn->begin_transaction();
            $transactionStarted = true;
            $stmt = $conn->prepare(
                "SELECT id,block_name,unit_no FROM cpms_residents
                 WHERE id=? AND property_id=? AND is_active=1
                 LIMIT 1 FOR UPDATE"
            );
            if (!$stmt) {
                throw new RuntimeException('Resident host query failed.');
            }
            $stmt->bind_param('ii', $residentId, $propertyId);
            cpmsSecurityVisitorExecute($stmt, 'Resident host query failed.');
            $resident = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$resident) {
                throw new RuntimeException('Resident host not found for this property.');
            }

            $passCode = cpmsVisitorReference($conn, $propertyId);
            $passToken = cpmsVisitorToken();
            $today = date('Y-m-d');
            $nowTime = date('H:i:s');
            $hostBlock = (string) $resident['block_name'];
            $hostUnit = (string) $resident['unit_no'];
            $stmt = $conn->prepare(
                "INSERT INTO cpms_visitor_passes (
                    property_id,resident_id,pass_code,pass_token,
                    visitor_name,visitor_phone,vehicle_no,host_block,host_unit,
                    visit_date,expected_start_time,visit_purpose,security_notes,
                    registration_source,visitor_status,checkin_at,
                    checked_in_by_guard_id,created_by_system_user_id
                 ) VALUES (
                    ?,?,?,?,
                    ?,NULLIF(?,''),NULLIF(?,''),?,?,
                    ?,?,?,NULLIF(?,''),
                    'Walk-In','Checked In',NOW(),?,NULLIF(?,0)
                 )"
            );
            if (!$stmt) {
                throw new RuntimeException('Walk-in insert failed.');
            }
            $stmt->bind_param(
                'iisssssssssssii',
                $propertyId,
                $residentId,
                $passCode,
                $passToken,
                $visitorName,
                $visitorPhone,
                $vehicleNo,
                $hostBlock,
                $hostUnit,
                $today,
                $nowTime,
                $purpose,
                $notes,
                $guardId,
                $actorUserId
            );
            cpmsSecurityVisitorExecute($stmt, 'Walk-in insert failed.');
            $passId = (int) $conn->insert_id;
            $stmt->close();
            cpmsVisitorEvent(
                $conn,
                $propertyId,
                $passId,
                'Walk-In Checked In',
                $actorUserId,
                $guardId,
                $notes
            );
            cpmsResidentNotify(
                $conn,
                $propertyId,
                $residentId,
                'visitor',
                'Pelawat walk-in telah masuk',
                $visitorName . ' telah didaftarkan oleh Security untuk unit anda.',
                'visitor_passes.php'
            );
            $conn->commit();
            $transactionStarted = false;
            cpmsSecurityVisitorFlash(
                'success',
                ($language === 'en'
                    ? 'Walk-in visitor checked in: '
                    : 'Pelawat walk-in berjaya didaftar masuk: ')
                    . $passCode
            );
            cpmsSecurityVisitorRedirect();
        }

        throw new RuntimeException('Invalid visitor action.');
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        cpmsSecurityVisitorFlash('danger', $exception->getMessage());
        cpmsSecurityVisitorRedirect();
    }
}

$residents = [];
$stmt = $conn->prepare(
    "SELECT id,full_name,block_name,unit_no FROM cpms_residents
     WHERE property_id=? AND is_active=1
     ORDER BY block_name,unit_no,full_name LIMIT 1000"
);
if ($stmt) {
    $stmt->bind_param('i', $propertyId);
    if ($stmt->execute()) {
        $residents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

$selectedDate = trim((string) ($_GET['date'] ?? date('Y-m-d')));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) !== 1) {
    $selectedDate = date('Y-m-d');
}

$allowedStatuses = [
    'All',
    'Expected',
    'Checked In',
    'Checked Out',
    'Denied',
    'Cancelled',
];
$selectedStatus = trim((string) ($_GET['status'] ?? 'All'));
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = 'All';
}
$insideScope = $selectedStatus === 'Checked In'
    && (string) ($_GET['scope'] ?? '') === 'inside';

$scanToken = strtolower(trim((string) ($_GET['scan'] ?? '')));
$invalidQrFormat = $scanToken !== ''
    && preg_match('/^[a-f0-9]{64}$/', $scanToken) !== 1;
if ($invalidQrFormat) {
    $scanToken = '';
}
$search = trim((string) ($_GET['q'] ?? ''));

$visitorSql = "SELECT v.*,r.full_name AS resident_name,
        (SELECT e.event_notes
         FROM cpms_visitor_events e
         WHERE e.property_id=v.property_id
           AND e.pass_id=v.id
           AND e.event_type='Entry Denied'
         ORDER BY e.event_at DESC,e.id DESC LIMIT 1) AS denial_reason
    FROM cpms_visitor_passes v
    INNER JOIN cpms_residents r
       ON r.id=v.resident_id AND r.property_id=v.property_id
    WHERE v.property_id=?";
$visitorTypes = 'i';
$visitorParams = [$propertyId];

if ($scanToken !== '') {
    $visitorSql .= ' AND v.pass_token=?';
    $visitorTypes .= 's';
    $visitorParams[] = $scanToken;
} else {
    if (!$insideScope) {
        $visitorSql .= ' AND v.visit_date=?';
        $visitorTypes .= 's';
        $visitorParams[] = $selectedDate;
    }
    if ($selectedStatus !== 'All') {
        $visitorSql .= ' AND v.visitor_status=?';
        $visitorTypes .= 's';
        $visitorParams[] = $selectedStatus;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $visitorSql .= " AND (
            v.pass_code LIKE ? OR v.visitor_name LIKE ?
            OR v.vehicle_no LIKE ? OR v.host_unit LIKE ?
        )";
        $visitorTypes .= 'ssss';
        $visitorParams[] = $like;
        $visitorParams[] = $like;
        $visitorParams[] = $like;
        $visitorParams[] = $like;
    }
}

$visitorSql .= " ORDER BY CASE v.visitor_status
    WHEN 'Checked In' THEN 0 WHEN 'Expected' THEN 1 ELSE 2 END,
    v.visit_date DESC,v.expected_start_time LIMIT 300";
$stmt = $conn->prepare($visitorSql);
if (!$stmt) {
    throw new RuntimeException('Visitor list query failed.');
}
cpmsSecurityVisitorBind($stmt, $visitorTypes, $visitorParams);
cpmsSecurityVisitorExecute($stmt, 'Visitor list query failed.');
$visitors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = [
    'Expected' => 0,
    'Checked In' => 0,
    'Checked Out' => 0,
    'Denied' => 0,
];
$stmt = $conn->prepare(
    "SELECT visitor_status,COUNT(*) AS total
     FROM cpms_visitor_passes
     WHERE property_id=? AND visit_date=CURDATE()
     GROUP BY visitor_status"
);
if ($stmt) {
    $stmt->bind_param('i', $propertyId);
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

$onPremisesCount = 0;
$overdueCount = 0;
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN visit_date<CURDATE()
                OR (visit_date=CURDATE()
                    AND expected_end_time IS NOT NULL
                    AND expected_end_time<CURTIME())
                THEN 1 ELSE 0 END) AS overdue
     FROM cpms_visitor_passes
     WHERE property_id=? AND visitor_status='Checked In'"
);
if ($stmt) {
    $stmt->bind_param('i', $propertyId);
    if ($stmt->execute()) {
        $insideRow = $stmt->get_result()->fetch_assoc();
        $onPremisesCount = (int) ($insideRow['total'] ?? 0);
        $overdueCount = (int) ($insideRow['overdue'] ?? 0);
    }
    $stmt->close();
}

$watchlistTodayCount = 0;
$stmt = $conn->prepare(
    "SELECT visitor_name,visitor_phone,vehicle_no
     FROM cpms_visitor_passes
     WHERE property_id=? AND visit_date=CURDATE()
       AND visitor_status IN ('Expected','Denied')"
);
if ($stmt) {
    $stmt->bind_param('i', $propertyId);
    if ($stmt->execute()) {
        $watchResult = $stmt->get_result();
        while ($watchVisitor = $watchResult->fetch_assoc()) {
            if (cpmsVisitorWatchlistMatch(
                $watchlistRows,
                (string) $watchVisitor['visitor_name'],
                (string) ($watchVisitor['visitor_phone'] ?? ''),
                (string) ($watchVisitor['vehicle_no'] ?? '')
            ) !== null) {
                $watchlistTodayCount++;
            }
        }
    }
    $stmt->close();
}

$qrLookupAttempted = $invalidQrFormat || $scanToken !== '';
$qrRecordFound = $scanToken !== '' && count($visitors) === 1;
$todayTotal = array_sum($counts);
$flash = $_SESSION['cpms_security_visitor_flash'] ?? null;
unset($_SESSION['cpms_security_visitor_flash']);
?>
<!doctype html>
<html lang="<?php echo $language === 'en' ? 'en' : 'ms'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('title')); ?> | CPMS</title>
    <link rel="stylesheet" href="css/security-visitors.css?v=3606">
    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head>
<body>
<header class="security-visitor-header">
    <div>
        <small>CPMS SECURITY · <?php echo cpmsSecurityVisitorEscape($guard['property_code']); ?></small>
        <h1><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('title')); ?></h1>
        <p><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('intro')); ?></p>
    </div>
    <div class="security-visitor-tools">
        <div class="security-language" aria-label="Language">
            <a class="<?php echo $language === 'bm' ? 'active' : ''; ?>" href="security_visitors.php?lang=bm">BM</a>
            <a class="<?php echo $language === 'en' ? 'active' : ''; ?>" href="security_visitors.php?lang=en">EN</a>
        </div>
        <a href="security_dashboard.php"><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('dashboard')); ?></a>
    </div>
</header>
<main class="security-visitor-main">
    <section class="security-identity">
        <div>
            <span><?php echo cpmsSecurityVisitorEscape(strtoupper(substr((string) $guard['full_name'], 0, 1))); ?></span>
            <div><small>SECURITY ON DUTY</small><strong><?php echo cpmsSecurityVisitorEscape($guard['full_name']); ?></strong></div>
        </div>
        <strong><?php echo cpmsSecurityVisitorEscape($guard['property_name']); ?></strong>
    </section>

    <?php if (is_array($flash)): ?>
        <div class="security-alert security-alert--<?php echo cpmsSecurityVisitorEscape((string) ($flash['type'] ?? 'success')); ?>">
            <?php echo cpmsSecurityVisitorEscape((string) ($flash['message'] ?? '')); ?>
        </div>
    <?php endif; ?>

    <section class="security-stats" aria-label="<?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('live_operations')); ?>">
        <a href="security_visitors.php?lang=<?php echo rawurlencode($language); ?>&amp;date=<?php echo date('Y-m-d'); ?>&amp;status=All" class="<?php echo !$insideScope && $selectedDate === date('Y-m-d') && $selectedStatus === 'All' ? 'active' : ''; ?>">
            <span><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('today_total')); ?></span><strong><?php echo $todayTotal; ?></strong>
        </a>
        <a href="security_visitors.php?lang=<?php echo rawurlencode($language); ?>&amp;date=<?php echo date('Y-m-d'); ?>&amp;status=Expected" class="<?php echo !$insideScope && $selectedDate === date('Y-m-d') && $selectedStatus === 'Expected' ? 'active' : ''; ?>">
            <span><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('expected')); ?></span><strong><?php echo $counts['Expected']; ?></strong>
        </a>
        <a href="security_visitors.php?lang=<?php echo rawurlencode($language); ?>&amp;status=Checked%20In&amp;scope=inside" class="<?php echo $insideScope ? 'active' : ''; ?>">
            <span><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('inside')); ?></span><strong><?php echo $onPremisesCount; ?></strong>
        </a>
        <a href="security_visitors.php?lang=<?php echo rawurlencode($language); ?>&amp;date=<?php echo date('Y-m-d'); ?>&amp;status=Checked%20Out" class="<?php echo !$insideScope && $selectedDate === date('Y-m-d') && $selectedStatus === 'Checked Out' ? 'active' : ''; ?>">
            <span><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('checked_out')); ?></span><strong><?php echo $counts['Checked Out']; ?></strong>
        </a>
        <a href="security_visitors.php?lang=<?php echo rawurlencode($language); ?>&amp;date=<?php echo date('Y-m-d'); ?>&amp;status=Denied" class="<?php echo !$insideScope && $selectedDate === date('Y-m-d') && $selectedStatus === 'Denied' ? 'active' : ''; ?>">
            <span><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('denied')); ?></span><strong><?php echo $counts['Denied']; ?></strong>
        </a>
        <a href="security_visitors.php?lang=<?php echo rawurlencode($language); ?>&amp;date=<?php echo date('Y-m-d'); ?>&amp;status=All" class="security-stat-warning">
            <span><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('watchlist')); ?></span><strong><?php echo $watchlistTodayCount; ?></strong>
        </a>
    </section>

    <?php if ($overdueCount > 0 || $watchlistTodayCount > 0): ?>
        <section class="security-attention">
            <strong>⚠ <?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('attention')); ?></strong>
            <div>
                <?php if ($overdueCount > 0): ?>
                    <a href="security_visitors.php?lang=<?php echo rawurlencode($language); ?>&amp;status=Checked%20In&amp;scope=inside"><b><?php echo $overdueCount; ?></b> <?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('overdue_attention')); ?></a>
                <?php endif; ?>
                <?php if ($watchlistTodayCount > 0): ?>
                    <a href="security_visitors.php?lang=<?php echo rawurlencode($language); ?>&amp;date=<?php echo date('Y-m-d'); ?>&amp;status=All"><b><?php echo $watchlistTodayCount; ?></b> <?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('watchlist_attention')); ?></a>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($qrLookupAttempted): ?>
        <div class="security-qr-result security-qr-result--<?php echo $qrRecordFound ? 'found' : 'missing'; ?>">
            <strong><?php echo $qrRecordFound ? 'QR VERIFIED' : 'QR ERROR'; ?></strong>
            <span><?php echo cpmsSecurityVisitorEscape($qrRecordFound ? cpmsSecurityVisitorText('qr_found') : cpmsSecurityVisitorText('qr_not_found')); ?></span>
            <?php if ($qrRecordFound): ?><small><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('manual_confirmation')); ?></small><?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="security-qr-scanner">
        <div><strong><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('scan_qr')); ?></strong><p><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('scan_help')); ?></p></div>
        <button type="button" id="visitorQrStart"><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('scan_qr')); ?></button>
        <button type="button" id="visitorQrStop" hidden><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('stop_scan')); ?></button>
        <video id="visitorQrVideo" playsinline muted hidden></video>
        <p id="visitorQrMessage" class="security-qr-message" hidden></p>
    </section>

    <form class="security-search" method="get">
        <input type="hidden" name="lang" value="<?php echo cpmsSecurityVisitorEscape($language); ?>">
        <input type="date" name="date" value="<?php echo cpmsSecurityVisitorEscape($selectedDate); ?>" aria-label="<?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('visit_date')); ?>">
        <select name="status" aria-label="<?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('status')); ?>">
            <?php foreach ($allowedStatuses as $statusOption): ?>
                <?php
                $statusLabels = [
                    'All' => cpmsSecurityVisitorText('all_statuses'),
                    'Expected' => cpmsSecurityVisitorText('expected'),
                    'Checked In' => cpmsSecurityVisitorText('checked_in'),
                    'Checked Out' => cpmsSecurityVisitorText('checked_out'),
                    'Denied' => cpmsSecurityVisitorText('denied'),
                    'Cancelled' => cpmsSecurityVisitorText('cancelled'),
                ];
                ?>
                <option value="<?php echo cpmsSecurityVisitorEscape($statusOption); ?>" <?php echo $selectedStatus === $statusOption ? 'selected' : ''; ?>><?php echo cpmsSecurityVisitorEscape($statusLabels[$statusOption]); ?></option>
            <?php endforeach; ?>
        </select>
        <input name="q" value="<?php echo cpmsSecurityVisitorEscape($search); ?>" placeholder="<?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('search')); ?>">
        <button><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('show')); ?></button>
    </form>

    <section class="security-workspace">
        <div class="security-list-panel">
            <div class="security-section-head">
                <div>
                    <small><?php echo cpmsSecurityVisitorEscape($insideScope ? cpmsSecurityVisitorText('inside_all_dates') : $selectedDate); ?></small>
                    <h2><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('visitor_register')); ?></h2>
                </div>
                <span><?php echo count($visitors); ?> <?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('records')); ?></span>
            </div>
            <?php if (!$visitors): ?>
                <div class="security-empty"><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('no_visitors')); ?></div>
            <?php else: ?>
                <div class="security-visitor-list">
                    <?php foreach ($visitors as $visitor): ?>
                        <?php
                        $watchMatch = cpmsVisitorWatchlistMatch(
                            $watchlistRows,
                            (string) $visitor['visitor_name'],
                            (string) ($visitor['visitor_phone'] ?? ''),
                            (string) ($visitor['vehicle_no'] ?? '')
                        );
                        $isQrResult = $scanToken !== ''
                            && hash_equals($scanToken, (string) $visitor['pass_token']);
                        $isOverdue = (string) $visitor['visitor_status'] === 'Checked In'
                            && (
                                (string) $visitor['visit_date'] < date('Y-m-d')
                                || (
                                    (string) $visitor['visit_date'] === date('Y-m-d')
                                    && !empty($visitor['expected_end_time'])
                                    && substr((string) $visitor['expected_end_time'], 0, 5) < date('H:i')
                                )
                            );
                        ?>
                        <article class="security-visitor-card status-<?php echo cpmsSecurityVisitorEscape(cpmsVisitorStatusClass((string) $visitor['visitor_status'])); ?><?php echo $isQrResult ? ' is-qr-result' : ''; ?><?php echo $isOverdue ? ' is-overdue' : ''; ?>">
                            <div class="security-card-top">
                                <div><span><?php echo cpmsSecurityVisitorEscape($visitor['pass_code']); ?></span><h3><?php echo cpmsSecurityVisitorEscape($visitor['visitor_name']); ?></h3><p><?php echo cpmsSecurityVisitorEscape($visitor['vehicle_no'] ?: ($visitor['visitor_phone'] ?: '-')); ?></p></div>
                                <div class="security-card-badges">
                                    <?php if ($isOverdue): ?><em><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('overdue')); ?></em><?php endif; ?>
                                    <strong><?php echo cpmsSecurityVisitorEscape($visitor['visitor_status']); ?></strong>
                                </div>
                            </div>
                            <dl>
                                <div><dt>Host</dt><dd><?php echo cpmsSecurityVisitorEscape($visitor['host_block'] . ' / ' . $visitor['host_unit']); ?></dd></div>
                                <div><dt>Resident</dt><dd><?php echo cpmsSecurityVisitorEscape($visitor['resident_name']); ?></dd></div>
                                <div><dt><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('visit_date')); ?></dt><dd><?php echo cpmsSecurityVisitorEscape(date('d/m/Y', strtotime((string) $visitor['visit_date']))); ?></dd></div>
                                <div><dt><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('expected')); ?></dt><dd><?php echo cpmsSecurityVisitorEscape(substr((string) $visitor['expected_start_time'], 0, 5)); ?></dd></div>
                                <div><dt><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('expected_end')); ?></dt><dd><?php echo !empty($visitor['expected_end_time']) ? cpmsSecurityVisitorEscape(substr((string) $visitor['expected_end_time'], 0, 5)) : '-'; ?></dd></div>
                                <div><dt>Source</dt><dd><?php echo cpmsSecurityVisitorEscape($visitor['registration_source']); ?></dd></div>
                            </dl>
                            <p class="security-purpose"><?php echo cpmsSecurityVisitorEscape($visitor['visit_purpose']); ?></p>
                            <?php if ($isQrResult): ?><p class="security-manual-confirmation">✓ <?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('manual_confirmation')); ?></p><?php endif; ?>
                            <?php if ($watchMatch !== null): ?>
                                <div class="security-watchlist-alert"><strong>⚠ <?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('watchlist_alert')); ?> · <?php echo cpmsSecurityVisitorEscape($watchMatch['risk_level']); ?></strong><span>Match: <?php echo cpmsSecurityVisitorEscape($watchMatch['matched_by']); ?></span><p><?php echo cpmsSecurityVisitorEscape($watchMatch['reason']); ?></p></div>
                            <?php endif; ?>
                            <?php if (!empty($visitor['denial_reason'])): ?><p class="security-denial-reason"><strong><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('denial_reason')); ?>:</strong> <?php echo cpmsSecurityVisitorEscape($visitor['denial_reason']); ?></p><?php endif; ?>
                            <?php if (!empty($visitor['security_notes'])): ?><p class="security-note"><strong>Note:</strong> <?php echo cpmsSecurityVisitorEscape($visitor['security_notes']); ?></p><?php endif; ?>

                            <?php if ((string) $visitor['visitor_status'] === 'Expected'): ?>
                                <?php if ($watchMatch !== null): ?>
                                    <div class="security-checkin-blocked">⛔ <?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('checkin_blocked')); ?></div>
                                <?php elseif ((string) $visitor['visit_date'] === date('Y-m-d')): ?>
                                    <form method="post" class="security-action-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorCsrf()); ?>">
                                        <input type="hidden" name="action" value="checkin">
                                        <input type="hidden" name="pass_id" value="<?php echo (int) $visitor['id']; ?>">
                                        <input name="event_notes" maxlength="500" placeholder="Security note (optional)">
                                        <button class="checkin"><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('checkin')); ?> →</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" class="security-action-form security-deny-form" onsubmit="return confirm('<?php echo $language === 'en' ? 'Deny this visitor entry?' : 'Tolak kemasukan pelawat ini?'; ?>')">
                                    <input type="hidden" name="csrf_token" value="<?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorCsrf()); ?>">
                                    <input type="hidden" name="action" value="deny">
                                    <input type="hidden" name="pass_id" value="<?php echo (int) $visitor['id']; ?>">
                                    <input name="event_notes" maxlength="500" placeholder="<?php echo $language === 'en' ? 'Denial reason (required)' : 'Alasan penolakan (wajib)'; ?>" required>
                                    <button class="deny"><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('deny')); ?></button>
                                </form>
                            <?php elseif ((string) $visitor['visitor_status'] === 'Checked In'): ?>
                                <form method="post" class="security-action-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorCsrf()); ?>">
                                    <input type="hidden" name="action" value="checkout">
                                    <input type="hidden" name="pass_id" value="<?php echo (int) $visitor['id']; ?>">
                                    <input name="event_notes" maxlength="500" placeholder="Security note (optional)">
                                    <button class="checkout"><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('checkout')); ?> →</button>
                                </form>
                            <?php else: ?>
                                <div class="security-time-record">
                                    <?php if (!empty($visitor['checkin_at'])): ?><span>IN <?php echo cpmsSecurityVisitorEscape(date('H:i', strtotime((string) $visitor['checkin_at']))); ?></span><?php endif; ?>
                                    <?php if (!empty($visitor['checkout_at'])): ?><span>OUT <?php echo cpmsSecurityVisitorEscape(date('H:i', strtotime((string) $visitor['checkout_at']))); ?></span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <aside class="security-walkin">
            <div class="security-section-head"><div><small>MANUAL ENTRY</small><h2><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('walkin')); ?></h2></div></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorCsrf()); ?>">
                <input type="hidden" name="action" value="walkin">
                <label><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('host')); ?> *<select name="resident_id" required><option value="">-- Select unit --</option><?php foreach ($residents as $resident): ?><option value="<?php echo (int) $resident['id']; ?>"><?php echo cpmsSecurityVisitorEscape($resident['block_name'] . ' / ' . $resident['unit_no'] . ' — ' . $resident['full_name']); ?></option><?php endforeach; ?></select></label>
                <label><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('visitor_name')); ?> *<input name="visitor_name" maxlength="180" required></label>
                <label><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('phone')); ?><input name="visitor_phone" maxlength="40" inputmode="tel"></label>
                <label><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('vehicle')); ?><input name="vehicle_no" maxlength="30"></label>
                <label><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('purpose')); ?> *<textarea name="visit_purpose" maxlength="250" rows="3" required></textarea></label>
                <label><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('notes')); ?><textarea name="security_notes" maxlength="500" rows="3"></textarea></label>
                <button><?php echo cpmsSecurityVisitorEscape(cpmsSecurityVisitorText('register_in')); ?> →</button>
            </form>
        </aside>
    </section>
</main>
<script>
(function(){
var start=document.getElementById('visitorQrStart');
var stop=document.getElementById('visitorQrStop');
var video=document.getElementById('visitorQrVideo');
var message=document.getElementById('visitorQrMessage');
var stream=null;
var timer=0;
function showMessage(text){message.textContent=text;message.hidden=false;}
function stopCamera(){if(timer){window.clearTimeout(timer);timer=0;}if(stream){stream.getTracks().forEach(function(track){track.stop();});stream=null;}video.srcObject=null;video.hidden=true;stop.hidden=true;start.hidden=false;}
function openResult(raw){var token='';try{var url=new URL(raw,window.location.href);token=url.searchParams.get('scan')||'';}catch(error){token=raw;}if(/^[a-f0-9]{64}$/i.test(token)){stopCamera();window.location.href='security_visitors.php?lang=<?php echo cpmsSecurityVisitorEscape($language); ?>&scan='+encodeURIComponent(token);return true;}return false;}
async function detectLoop(detector){if(!stream){return;}try{var codes=await detector.detect(video);if(codes.length&&openResult(codes[0].rawValue)){return;}}catch(error){}timer=window.setTimeout(function(){detectLoop(detector);},350);}
start.addEventListener('click',async function(){if(!('BarcodeDetector' in window)||!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){showMessage(<?php echo json_encode(cpmsSecurityVisitorText('scan_unavailable')); ?>);return;}try{var formats=await BarcodeDetector.getSupportedFormats();if(formats.indexOf('qr_code')===-1){showMessage(<?php echo json_encode(cpmsSecurityVisitorText('scan_unavailable')); ?>);return;}stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});video.srcObject=stream;video.hidden=false;start.hidden=true;stop.hidden=false;message.hidden=true;await video.play();detectLoop(new BarcodeDetector({formats:['qr_code']}));}catch(error){stopCamera();showMessage(<?php echo json_encode(cpmsSecurityVisitorText('scan_unavailable')); ?>);}});
stop.addEventListener('click',stopCamera);
window.addEventListener('pagehide',stopCamera);
})();
</script>
</body></html>
