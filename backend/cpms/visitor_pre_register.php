<?php
declare(strict_types=1);

require_once __DIR__ . '/cpms/includes/resident_session.php';
cpmsResidentSessionStart();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cpms/includes/resident_portal_service.php';
require_once __DIR__ . '/cpms/includes/visitor_service.php';
require_once __DIR__ . '/cpms/includes/visitor_portal_helper.php';

$resident = cpmsResidentRequire($conn);
$propertyId = (int) $resident['property_id'];
$residentId = (int) $resident['id'];
$systemUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$language = cpmsVisitorLanguage();
$error = '';

$moduleStmt = $conn->prepare(
    "SELECT is_enabled FROM cpms_property_modules
     WHERE property_id=? AND module_key='visitor_management' LIMIT 1"
);
if (!$moduleStmt) {
    throw new RuntimeException('Visitor module query failed.');
}
$moduleStmt->bind_param('i', $propertyId);
cpmsVisitorExecute($moduleStmt, 'Visitor module query failed.');
$module = $moduleStmt->get_result()->fetch_assoc();
$moduleStmt->close();
if (!$module || (int) $module['is_enabled'] !== 1) {
    http_response_code(403);
    exit($language === 'en'
        ? 'Visitor Management is not enabled for this property.'
        : 'Visitor Management belum diaktifkan untuk hartanah ini.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        cpmsVisitorVerifyCsrf();
        $visitorName = trim((string) ($_POST['visitor_name'] ?? ''));
        $visitorPhone = trim((string) ($_POST['visitor_phone'] ?? ''));
        $vehicleNo = strtoupper(trim((string) ($_POST['vehicle_no'] ?? '')));
        $visitDate = trim((string) ($_POST['visit_date'] ?? ''));
        $startTime = trim((string) ($_POST['expected_start_time'] ?? ''));
        $endTime = trim((string) ($_POST['expected_end_time'] ?? ''));
        $purpose = trim((string) ($_POST['visit_purpose'] ?? ''));
        $notes = trim((string) ($_POST['security_notes'] ?? ''));

        $dateObject = DateTime::createFromFormat('!Y-m-d', $visitDate);
        $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate) === 1
            && $dateObject instanceof DateTime
            && $dateObject->format('Y-m-d') === $visitDate;
        $validStart = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime) === 1;
        $validEnd = $endTime === ''
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime) === 1;

        if (
            $visitorName === '' || strlen($visitorName) > 180
            || strlen($visitorPhone) > 40 || strlen($vehicleNo) > 30
            || !$validDate || !$validStart || !$validEnd
            || ($endTime !== '' && $endTime <= $startTime)
            || $purpose === '' || strlen($purpose) > 250
            || strlen($notes) > 500
        ) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'The visitor information is incomplete or invalid.'
                    : 'Maklumat pelawat tidak lengkap atau tidak sah.'
            );
        }
        $today = date('Y-m-d');
        $maximumDate = date('Y-m-d', strtotime('+90 days'));
        if ($visitDate < $today || $visitDate > $maximumDate) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'The visit date must be within the next 90 days.'
                    : 'Tarikh lawatan mestilah dalam tempoh 90 hari akan datang.'
            );
        }

        $conn->begin_transaction();
        $transactionStarted = true;
        $stmt = $conn->prepare(
            "SELECT id FROM cpms_residents
             WHERE id=? AND property_id=? AND is_active=1
             LIMIT 1 FOR UPDATE"
        );
        if (!$stmt) {
            throw new RuntimeException('Resident validation failed.');
        }
        $stmt->bind_param('ii', $residentId, $propertyId);
        cpmsVisitorExecute($stmt, 'Resident validation failed.');
        $activeResident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$activeResident) {
            throw new RuntimeException('Resident account is no longer active.');
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM cpms_visitor_passes
             WHERE property_id=? AND resident_id=? AND visit_date=?
               AND visitor_status IN ('Expected','Checked In')"
        );
        if (!$stmt) {
            throw new RuntimeException('Visitor limit query failed.');
        }
        $stmt->bind_param('iis', $propertyId, $residentId, $visitDate);
        cpmsVisitorExecute($stmt, 'Visitor limit query failed.');
        $activeTotal = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($activeTotal >= 10) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'The daily limit of 10 active visitor passes has been reached.'
                    : 'Had 10 pas pelawat aktif sehari telah dicapai.'
            );
        }

        $passCode = cpmsVisitorReference($conn, $propertyId);
        $passToken = cpmsVisitorToken();
        $hostBlock = trim((string) ($resident['block_name'] ?? ''));
        $hostUnit = trim((string) ($resident['unit_no'] ?? ''));

        $stmt = $conn->prepare(
            "INSERT INTO cpms_visitor_passes (
                property_id,resident_id,pass_code,pass_token,
                visitor_name,visitor_phone,vehicle_no,host_block,host_unit,
                visit_date,expected_start_time,expected_end_time,
                visit_purpose,security_notes,registration_source,
                visitor_status,created_by_system_user_id
             ) VALUES (
                ?,?,?,?, ?,NULLIF(?,''),NULLIF(?,''),?,?, ?,?,NULLIF(?,''),
                ?,NULLIF(?,''),'Resident','Expected',NULLIF(?,0)
             )"
        );
        if (!$stmt) {
            throw new RuntimeException('Visitor pass insert failed.');
        }
        $stmt->bind_param(
            'iissssssssssssi',
            $propertyId,
            $residentId,
            $passCode,
            $passToken,
            $visitorName,
            $visitorPhone,
            $vehicleNo,
            $hostBlock,
            $hostUnit,
            $visitDate,
            $startTime,
            $endTime,
            $purpose,
            $notes,
            $systemUserId
        );
        cpmsVisitorExecute($stmt, 'Visitor pass insert failed.');
        $passId = (int) $conn->insert_id;
        $stmt->close();
        cpmsVisitorEvent(
            $conn,
            $propertyId,
            $passId,
            'Pre-Registered',
            $systemUserId,
            0,
            'Created by resident.'
        );
        $conn->commit();
        $transactionStarted = false;
        cpmsVisitorFlash(
            'success',
            $language === 'en'
                ? 'Visitor pass created: ' . $passCode
                : 'Pas pelawat berjaya dijana: ' . $passCode
        );
        header('Location: ' . cpmsVisitorUrl('visitor_passes.php'));
        exit;
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        $error = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="<?php echo $language === 'en' ? 'en' : 'ms'; ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo cpmsVisitorEscape(cpmsVisitorText('title')); ?> | CPMS</title><link rel="stylesheet" href="resident_portal.css?v=3600"><link rel="stylesheet" href="visitor-resident.css?v=3600"></head>
<body>
<header class="rp-head visitor-hero"><div class="rp-wrap visitor-hero-row"><div><small><?php echo cpmsVisitorEscape(cpmsVisitorText('portal')); ?></small><h1><?php echo cpmsVisitorEscape(cpmsVisitorText('title')); ?></h1><p><?php echo cpmsVisitorEscape(cpmsVisitorText('intro')); ?></p></div><div class="visitor-language"><a class="<?php echo $language === 'bm' ? 'active' : ''; ?>" href="visitor_pre_register.php?lang=bm">BM</a><a class="<?php echo $language === 'en' ? 'active' : ''; ?>" href="visitor_pre_register.php?lang=en">EN</a></div></div></header>
<nav class="rp-nav visitor-nav"><a href="resident_dashboard.php"><?php echo cpmsVisitorEscape(cpmsVisitorText('dashboard')); ?></a><a class="active" href="<?php echo cpmsVisitorEscape(cpmsVisitorUrl('visitor_pre_register.php')); ?>"><?php echo cpmsVisitorEscape(cpmsVisitorText('register')); ?></a><a href="<?php echo cpmsVisitorEscape(cpmsVisitorUrl('visitor_passes.php')); ?>"><?php echo cpmsVisitorEscape(cpmsVisitorText('passes')); ?></a><a href="resident_notifications.php"><?php echo cpmsVisitorEscape(cpmsVisitorText('notifications')); ?></a></nav>
<main class="rp-main rp-wrap visitor-main">
    <?php if ($error !== ''): ?><div class="visitor-alert visitor-alert--danger"><?php echo cpmsVisitorEscape($error); ?></div><?php endif; ?>
    <section class="visitor-layout"><form method="post" class="rp-card visitor-form"><input type="hidden" name="csrf_token" value="<?php echo cpmsVisitorEscape(cpmsVisitorCsrfToken()); ?>"><div class="visitor-form-heading"><span>01</span><div><small>VISITOR PASS</small><h2><?php echo cpmsVisitorEscape(cpmsVisitorText('register')); ?></h2></div></div><div class="visitor-grid"><label><?php echo cpmsVisitorEscape(cpmsVisitorText('visitor_name')); ?> *<input class="rp-field" name="visitor_name" maxlength="180" value="<?php echo cpmsVisitorEscape($_POST['visitor_name'] ?? ''); ?>" required></label><label><?php echo cpmsVisitorEscape(cpmsVisitorText('visitor_phone')); ?><input class="rp-field" name="visitor_phone" maxlength="40" inputmode="tel" value="<?php echo cpmsVisitorEscape($_POST['visitor_phone'] ?? ''); ?>"></label><label><?php echo cpmsVisitorEscape(cpmsVisitorText('vehicle_no')); ?><input class="rp-field" name="vehicle_no" maxlength="30" value="<?php echo cpmsVisitorEscape($_POST['vehicle_no'] ?? ''); ?>" placeholder="SAA 1234 A"></label><label><?php echo cpmsVisitorEscape(cpmsVisitorText('visit_date')); ?> *<input class="rp-field" type="date" name="visit_date" min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d', strtotime('+90 days')); ?>" value="<?php echo cpmsVisitorEscape($_POST['visit_date'] ?? date('Y-m-d')); ?>" required></label><label><?php echo cpmsVisitorEscape(cpmsVisitorText('start_time')); ?> *<input class="rp-field" type="time" name="expected_start_time" value="<?php echo cpmsVisitorEscape($_POST['expected_start_time'] ?? ''); ?>" required></label><label><?php echo cpmsVisitorEscape(cpmsVisitorText('end_time')); ?><input class="rp-field" type="time" name="expected_end_time" value="<?php echo cpmsVisitorEscape($_POST['expected_end_time'] ?? ''); ?>"></label><label class="visitor-wide"><?php echo cpmsVisitorEscape(cpmsVisitorText('purpose')); ?> *<textarea class="rp-field" name="visit_purpose" rows="3" maxlength="250" required><?php echo cpmsVisitorEscape($_POST['visit_purpose'] ?? ''); ?></textarea></label><label class="visitor-wide"><?php echo cpmsVisitorEscape(cpmsVisitorText('notes')); ?><textarea class="rp-field" name="security_notes" rows="3" maxlength="500"><?php echo cpmsVisitorEscape($_POST['security_notes'] ?? ''); ?></textarea></label></div><button class="rp-btn visitor-primary" type="submit"><?php echo cpmsVisitorEscape(cpmsVisitorText('submit')); ?> →</button></form>
        <aside class="visitor-side"><div class="visitor-host-card"><small>HOST / RESIDENT</small><h2><?php echo cpmsVisitorEscape($resident['full_name']); ?></h2><p><?php echo cpmsVisitorEscape(($resident['block_name'] ?: '-') . ' / Unit ' . ($resident['unit_no'] ?: '-')); ?></p></div><div class="visitor-privacy-card"><span>✓</span><div><strong>Privasi / Privacy</strong><p><?php echo cpmsVisitorEscape(cpmsVisitorText('privacy')); ?></p></div></div><ol class="visitor-steps"><li><span>1</span><p>Resident daftar maklumat pelawat.</p></li><li><span>2</span><p>Pelawat tunjuk kod pas di pondok pengawal.</p></li><li><span>3</span><p>Security merekod masa masuk dan keluar.</p></li></ol></aside>
    </section>
</main>
<footer class="rp-footer"><a href="resident_logout.php"><?php echo cpmsVisitorEscape(cpmsVisitorText('logout')); ?></a> · <?php echo cpmsVisitorEscape(cpmsVisitorText('footer')); ?></footer>
</body></html>
