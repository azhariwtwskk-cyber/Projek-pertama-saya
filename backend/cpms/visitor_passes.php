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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        cpmsVisitorVerifyCsrf();
        $passId = (int) ($_POST['pass_id'] ?? 0);
        if ($passId < 1) {
            throw new RuntimeException('Invalid visitor pass.');
        }
        $conn->begin_transaction();
        $transactionStarted = true;
        $stmt = $conn->prepare(
            "SELECT pass_code,visitor_status,visit_date
             FROM cpms_visitor_passes
             WHERE id=? AND property_id=? AND resident_id=?
             LIMIT 1 FOR UPDATE"
        );
        if (!$stmt) {
            throw new RuntimeException('Visitor pass query failed.');
        }
        $stmt->bind_param('iii', $passId, $propertyId, $residentId);
        cpmsVisitorExecute($stmt, 'Visitor pass query failed.');
        $pass = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (
            !$pass || (string) $pass['visitor_status'] !== 'Expected'
            || (string) $pass['visit_date'] < date('Y-m-d')
        ) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'This visitor pass can no longer be cancelled.'
                    : 'Pas pelawat ini tidak lagi boleh dibatalkan.'
            );
        }
        $stmt = $conn->prepare(
            "UPDATE cpms_visitor_passes SET
                visitor_status='Cancelled',cancelled_at=NOW(),
                cancelled_by_system_user_id=NULLIF(?,0)
             WHERE id=? AND property_id=? AND resident_id=?
               AND visitor_status='Expected'"
        );
        if (!$stmt) {
            throw new RuntimeException('Visitor pass cancellation failed.');
        }
        $stmt->bind_param('iiii', $systemUserId, $passId, $propertyId, $residentId);
        cpmsVisitorExecute($stmt, 'Visitor pass cancellation failed.');
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Visitor pass status changed.');
        }
        $stmt->close();
        cpmsVisitorEvent(
            $conn,
            $propertyId,
            $passId,
            'Cancelled',
            $systemUserId,
            0,
            'Cancelled by resident.'
        );
        $conn->commit();
        $transactionStarted = false;
        cpmsVisitorFlash(
            'success',
            $language === 'en'
                ? 'Visitor pass cancelled.'
                : 'Pas pelawat telah dibatalkan.'
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
        cpmsVisitorFlash('danger', $exception->getMessage());
        header('Location: ' . cpmsVisitorUrl('visitor_passes.php'));
        exit;
    }
}

$stmt = $conn->prepare(
    "SELECT * FROM cpms_visitor_passes
     WHERE property_id=? AND resident_id=?
     ORDER BY (visit_date>=CURDATE()) DESC,visit_date DESC,
              expected_start_time DESC LIMIT 300"
);
if (!$stmt) {
    throw new RuntimeException('Visitor pass query failed.');
}
$stmt->bind_param('ii', $propertyId, $residentId);
cpmsVisitorExecute($stmt, 'Visitor pass query failed.');
$passes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$flash = cpmsVisitorPullFlash();
$qrPasses = [];
foreach ($passes as $qrPass) {
    $qrStatus = (string) $qrPass['visitor_status'];
    if (
        $qrStatus === 'Checked In'
        || ($qrStatus === 'Expected' && (string) $qrPass['visit_date'] >= date('Y-m-d'))
    ) {
        $qrPasses[(string) $qrPass['pass_code']] = (string) $qrPass['pass_token'];
    }
}
?>
<!doctype html><html lang="<?php echo $language === 'en' ? 'en' : 'ms'; ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo cpmsVisitorEscape(cpmsVisitorText('my_title')); ?> | CPMS</title><link rel="stylesheet" href="resident_portal.css?v=3600"><link rel="stylesheet" href="visitor-resident.css?v=3600"><script src="assets/vendor/qrcodejs/qrcode.min.js" defer></script></head><body>
<header class="rp-head visitor-hero"><div class="rp-wrap visitor-hero-row"><div><small><?php echo cpmsVisitorEscape(cpmsVisitorText('portal')); ?></small><h1><?php echo cpmsVisitorEscape(cpmsVisitorText('my_title')); ?></h1><p><?php echo cpmsVisitorEscape(cpmsVisitorText('my_intro')); ?></p></div><div class="visitor-language"><a class="<?php echo $language === 'bm' ? 'active' : ''; ?>" href="visitor_passes.php?lang=bm">BM</a><a class="<?php echo $language === 'en' ? 'active' : ''; ?>" href="visitor_passes.php?lang=en">EN</a></div></div></header>
<nav class="rp-nav visitor-nav"><a href="resident_dashboard.php"><?php echo cpmsVisitorEscape(cpmsVisitorText('dashboard')); ?></a><a href="<?php echo cpmsVisitorEscape(cpmsVisitorUrl('visitor_pre_register.php')); ?>"><?php echo cpmsVisitorEscape(cpmsVisitorText('register')); ?></a><a class="active" href="<?php echo cpmsVisitorEscape(cpmsVisitorUrl('visitor_passes.php')); ?>"><?php echo cpmsVisitorEscape(cpmsVisitorText('passes')); ?></a><a href="resident_notifications.php"><?php echo cpmsVisitorEscape(cpmsVisitorText('notifications')); ?></a></nav>
<main class="rp-main rp-wrap visitor-main"><?php if ($flash): ?><div class="visitor-alert visitor-alert--<?php echo cpmsVisitorEscape((string) ($flash['type'] ?? 'success')); ?>"><?php echo cpmsVisitorEscape((string) ($flash['message'] ?? '')); ?></div><?php endif; ?><div class="visitor-page-actions"><span><?php echo count($passes); ?> <?php echo cpmsVisitorEscape(cpmsVisitorText('passes')); ?></span><a class="rp-btn" href="<?php echo cpmsVisitorEscape(cpmsVisitorUrl('visitor_pre_register.php')); ?>">+ <?php echo cpmsVisitorEscape(cpmsVisitorText('new_pass')); ?></a></div>
<?php if (!$passes): ?><section class="rp-card visitor-empty"><span>◎</span><h2><?php echo cpmsVisitorEscape(cpmsVisitorText('no_passes')); ?></h2></section><?php else: ?><section class="visitor-pass-list"><?php foreach ($passes as $pass): ?><?php $displayStatus = (string) $pass['visitor_status']; if ($displayStatus === 'Expected' && (string) $pass['visit_date'] < date('Y-m-d')) { $displayStatus = 'Expired'; } ?><article class="rp-card visitor-pass-card status-<?php echo cpmsVisitorEscape(cpmsVisitorStatusClass($displayStatus)); ?>"><div class="visitor-pass-top"><div><small><?php echo cpmsVisitorEscape(cpmsVisitorText('pass_code')); ?></small><strong><?php echo cpmsVisitorEscape($pass['pass_code']); ?></strong></div><span class="visitor-status status-<?php echo cpmsVisitorEscape(cpmsVisitorStatusClass($displayStatus)); ?>"><?php echo cpmsVisitorEscape(cpmsVisitorStatusLabel($displayStatus)); ?></span></div><div class="visitor-name-row"><div class="visitor-avatar"><?php echo cpmsVisitorEscape(strtoupper(substr((string) $pass['visitor_name'], 0, 1))); ?></div><div><h2><?php echo cpmsVisitorEscape($pass['visitor_name']); ?></h2><p><?php echo cpmsVisitorEscape($pass['vehicle_no'] ?: ($pass['visitor_phone'] ?: '-')); ?></p></div></div><dl class="visitor-pass-details"><div><dt><?php echo cpmsVisitorEscape(cpmsVisitorText('visit_date')); ?></dt><dd><?php echo cpmsVisitorEscape(date('d/m/Y', strtotime((string) $pass['visit_date']))); ?></dd></div><div><dt><?php echo cpmsVisitorEscape(cpmsVisitorText('expected')); ?></dt><dd><?php echo cpmsVisitorEscape(substr((string) $pass['expected_start_time'], 0, 5)); ?><?php echo !empty($pass['expected_end_time']) ? '–' . cpmsVisitorEscape(substr((string) $pass['expected_end_time'], 0, 5)) : ''; ?></dd></div><div><dt>Host</dt><dd><?php echo cpmsVisitorEscape($pass['host_block'] . ' / ' . $pass['host_unit']); ?></dd></div><div><dt>Source</dt><dd><?php echo cpmsVisitorEscape($pass['registration_source']); ?></dd></div></dl><p class="visitor-purpose"><?php echo cpmsVisitorEscape($pass['visit_purpose']); ?></p><?php if (!empty($pass['checkin_at'])): ?><div class="visitor-timeline"><span>● <?php echo cpmsVisitorEscape(cpmsVisitorText('checked_in')); ?>: <?php echo cpmsVisitorEscape(date('d/m/Y H:i', strtotime((string) $pass['checkin_at']))); ?></span><?php if (!empty($pass['checkout_at'])): ?><span>✓ <?php echo cpmsVisitorEscape(cpmsVisitorText('checked_out')); ?>: <?php echo cpmsVisitorEscape(date('d/m/Y H:i', strtotime((string) $pass['checkout_at']))); ?></span><?php endif; ?></div><?php endif; ?><?php if ((string) $pass['visitor_status'] === 'Expected' && (string) $pass['visit_date'] >= date('Y-m-d')): ?><form method="post" class="visitor-cancel-form" onsubmit="return confirm('<?php echo cpmsVisitorEscape(cpmsVisitorText('cancel_confirm')); ?>')"><input type="hidden" name="csrf_token" value="<?php echo cpmsVisitorEscape(cpmsVisitorCsrfToken()); ?>"><input type="hidden" name="pass_id" value="<?php echo (int) $pass['id']; ?>"><button type="submit"><?php echo cpmsVisitorEscape(cpmsVisitorText('cancel')); ?></button></form><?php endif; ?></article><?php endforeach; ?></section><?php endif; ?></main>
<script>document.addEventListener('DOMContentLoaded',function(){var passes=<?php echo json_encode($qrPasses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;if(typeof QRCode==='undefined'){return;}document.querySelectorAll('.visitor-pass-card').forEach(function(card){var codeNode=card.querySelector('.visitor-pass-top strong');if(!codeNode){return;}var token=passes[codeNode.textContent.trim()];if(!token){return;}var box=document.createElement('div');box.className='visitor-qr-pass';var canvas=document.createElement('div');canvas.className='visitor-qr-canvas';var copy=document.createElement('div');var title=document.createElement('strong');title.textContent=<?php echo json_encode(cpmsVisitorText('qr_pass')); ?>;var note=document.createElement('p');note.textContent=<?php echo json_encode(cpmsVisitorText('qr_instruction')); ?>;copy.appendChild(title);copy.appendChild(note);box.appendChild(canvas);box.appendChild(copy);var purpose=card.querySelector('.visitor-purpose');if(purpose){purpose.insertAdjacentElement('afterend',box);}else{card.appendChild(box);}var scanUrl=new URL('../security_visitors.php?scan='+encodeURIComponent(token),window.location.href).toString();new QRCode(canvas,{text:scanUrl,width:124,height:124,colorDark:'#0c294d',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});});});</script>
<footer class="rp-footer"><a href="resident_logout.php"><?php echo cpmsVisitorEscape(cpmsVisitorText('logout')); ?></a> · <?php echo cpmsVisitorEscape(cpmsVisitorText('footer')); ?></footer></body></html>
