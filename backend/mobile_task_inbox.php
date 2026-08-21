<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/notification_service.php';
require_once __DIR__ . '/cpms/includes/mobile_task_inbox_service.php';

$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$role = (string) ($_SESSION['cpms_user_role'] ?? '');
if ($userId < 1 || $propertyId < 1
    || !in_array($role, ['staff', 'security'], true)) {
    header('Location: cpms/login.php');
    exit;
}
cpmsRequire('mobile_inbox.view', $conn);

function cpmsInboxEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsInboxSafeUrl(string $url, string $role): string
{
    $fallback = $role === 'security'
        ? 'security_dashboard.php' : 'staff_dashboard.php';
    $url = trim($url);
    if ($url === '' || preg_match('#^(?:https?:|//|javascript:)#i', $url)
        || strpos($url, '..') !== false) {
        return $fallback;
    }
    return ltrim($url, '/');
}

cpmsMobileInboxSync($conn, $propertyId, $userId, $role);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    if (!cpmsNotificationVerifyCsrf(
        is_string($csrf) ? $csrf : null
    )) {
        http_response_code(403);
        exit('Invalid security token.');
    }
    if (isset($_POST['mark_all'])) {
        cpmsNotificationMarkAllRead($conn, $propertyId, $userId);
        header('Location: mobile_task_inbox.php?filter=unread');
        exit;
    }
    $notificationId = (int) ($_POST['notification_id'] ?? 0);
    if ($notificationId > 0) {
        $lookup = $conn->prepare(
            'SELECT target_url FROM cpms_user_notifications
             WHERE id = ? AND property_id = ?
               AND recipient_system_user_id = ? LIMIT 1'
        );
        $lookup->bind_param(
            'iii', $notificationId, $propertyId, $userId
        );
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (is_array($row)) {
            cpmsNotificationMarkRead(
                $conn, $propertyId, $userId, $notificationId
            );
            header(
                'Location: ' . cpmsInboxSafeUrl(
                    (string) ($row['target_url'] ?? ''), $role
                )
            );
            exit;
        }
    }
}

$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unread', 'overdue'], true)) {
    $filter = 'all';
}
$where = '';
if ($filter === 'unread') {
    $where = ' AND is_read = 0';
} elseif ($filter === 'overdue') {
    $where = " AND severity = 'danger' AND is_read = 0";
}
$stmt = $conn->prepare(
    "SELECT id, notification_type, title, message, target_url,
            related_type, related_id,
            severity, is_read, created_at, updated_at
     FROM cpms_user_notifications
     WHERE property_id = ? AND recipient_system_user_id = ? {$where}
     ORDER BY is_read ASC,
       FIELD(severity, 'danger', 'warning', 'info', 'success'),
       updated_at DESC, id DESC LIMIT 100"
);
$stmt->bind_param('ii', $propertyId, $userId);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

$count = $conn->prepare(
    "SELECT COUNT(*) total,
      SUM(is_read = 0) unread,
      SUM(is_read = 0 AND severity = 'danger') overdue
     FROM cpms_user_notifications
     WHERE property_id = ? AND recipient_system_user_id = ?"
);
$count->bind_param('ii', $propertyId, $userId);
$count->execute();
$statistics = $count->get_result()->fetch_assoc();
$count->close();

$propertyName = 'Property ' . $propertyId;
$property = $conn->prepare(
    'SELECT property_name FROM cpms_properties WHERE id = ? LIMIT 1'
);
if ($property) {
    $property->bind_param('i', $propertyId);
    $property->execute();
    $propertyRow = $property->get_result()->fetch_assoc();
    $property->close();
    $propertyName = (string) (
        $propertyRow['property_name'] ?? $propertyName
    );
}
$csrfToken = cpmsNotificationCsrfToken();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f2342">
<title>Task Inbox | CPMS</title>
<link rel="manifest" href="<?= $role === 'security'
    ? 'security_manifest.php' : 'staff_manifest.php' ?>">
<link rel="stylesheet" href="pwa/cpms-mobile.css?v=343">
<style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;
font:15px Arial}.top{background:linear-gradient(135deg,#0f2342,#174789);
color:#fff;padding:22px 18px 34px}.top-inner,.content{max-width:760px;margin:auto}
.top h1{margin:6px 0}.top p{margin:0;color:#dbeafe}.back{color:#fff;text-decoration:none}
.content{padding:0 14px 90px;margin-top:-18px}.stats{display:grid;
grid-template-columns:repeat(3,1fr);gap:9px}.stat{background:#fff;padding:15px;
border-radius:14px;box-shadow:0 8px 22px rgba(15,35,66,.1)}.stat strong{
display:block;font-size:24px}.filters{display:flex;gap:8px;overflow:auto;padding:16px 0}
.filters a{white-space:nowrap;text-decoration:none;padding:9px 13px;border-radius:999px;
background:#fff;color:#334155;font-weight:700}.filters a.active{background:#174789;color:#fff}
.toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.toolbar h2{margin:0}.toolbar button{border:0;background:transparent;color:#174789;
font-weight:800}.item{width:100%;text-align:left;border:0;background:#fff;margin:9px 0;
padding:16px;border-radius:14px;box-shadow:0 5px 16px rgba(15,35,66,.08);color:#10213d}
.item.unread{border-left:5px solid #174789}.item.danger{border-left-color:#dc2626}
.item.warning{border-left-color:#d97706}.item-title{display:flex;gap:10px;
justify-content:space-between;font-weight:800;font-size:16px}.badge{font-size:11px;
padding:4px 7px;border-radius:999px;background:#e2e8f0}.message{color:#52637a;
line-height:1.45;margin:8px 0}.meta{font-size:12px;color:#8491a3}.empty{background:#fff;
padding:28px;text-align:center;border-radius:14px}@media(max-width:420px){
.stats{grid-template-columns:1fr 1fr 1fr}.stat{padding:12px}.stat strong{font-size:20px}}
.evidence-link{display:inline-block;margin:-3px 0 12px 12px;color:#174789;
font-weight:800;text-decoration:none}
</style></head>
<body data-cpms-pwa="<?= cpmsInboxEscape($role) ?>">
<header class="top"><div class="top-inner">
<a class="back" href="<?= $role === 'security'
    ? 'security_dashboard.php' : 'staff_dashboard.php' ?>">← Dashboard</a>
<h1>Task Inbox</h1><p><?= cpmsInboxEscape($propertyName) ?></p>
</div></header><main class="content">
<section class="stats">
<div class="stat"><strong><?= (int) ($statistics['total'] ?? 0) ?></strong>Semua</div>
<div class="stat"><strong><?= (int) ($statistics['unread'] ?? 0) ?></strong>Belum baca</div>
<div class="stat"><strong><?= (int) ($statistics['overdue'] ?? 0) ?></strong>Lewat</div>
</section>
<nav class="filters">
<?php foreach (['all' => 'Semua', 'unread' => 'Belum Dibaca', 'overdue' => 'Lewat'] as $key => $label): ?>
<a class="<?= $filter === $key ? 'active' : '' ?>"
href="?filter=<?= $key ?>"><?= $label ?></a><?php endforeach; ?>
</nav>
<div class="toolbar"><h2>Tugasan & Peringatan</h2>
<?php if ((int) ($statistics['unread'] ?? 0) > 0): ?>
<form method="post"><input type="hidden" name="csrf_token"
value="<?= cpmsInboxEscape($csrfToken) ?>"><button name="mark_all" value="1">
Tandakan semua dibaca</button></form><?php endif; ?></div>
<?php if (!$items): ?><div class="empty">Tiada tugasan dalam bahagian ini.</div>
<?php else: ?><?php foreach ($items as $item): ?>
<form method="post"><input type="hidden" name="csrf_token"
value="<?= cpmsInboxEscape($csrfToken) ?>">
<input type="hidden" name="notification_id" value="<?= (int) $item['id'] ?>">
<button class="item <?= !(int) $item['is_read'] ? 'unread ' : '' ?>
<?= cpmsInboxEscape((string) $item['severity']) ?>" type="submit">
<span class="item-title"><span><?= cpmsInboxEscape((string) $item['title']) ?></span>
<?php if (!(int) $item['is_read']): ?><span class="badge">BARU</span><?php endif; ?>
</span><span class="message"><?= cpmsInboxEscape((string) $item['message']) ?></span>
<span class="meta"><?= cpmsInboxEscape(date(
    'd/m/Y h:i A', strtotime((string) $item['updated_at'])
)) ?></span></button></form>
<?php $evidenceTypes = [
    'work_order', 'preventive_maintenance',
    'corrective_action', 'security_patrol',
]; ?>
<?php if ((int) ($item['related_id'] ?? 0) > 0
    && in_array((string) ($item['related_type'] ?? ''), $evidenceTypes, true)): ?>
<a class="evidence-link" href="mobile_evidence.php?type=<?= cpmsInboxEscape(
    (string) $item['related_type']
) ?>&amp;id=<?= (int) $item['related_id'] ?>">📷 Tambah bukti gambar</a>
<?php endif; ?>
<?php endforeach; ?><?php endif; ?></main>
<script src="pwa/cpms-mobile.js?v=345" defer></script>
</body></html>
