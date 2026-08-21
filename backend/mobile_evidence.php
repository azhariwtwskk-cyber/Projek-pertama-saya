<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/notification_service.php';
require_once __DIR__ . '/cpms/includes/mobile_evidence_service.php';

$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$role = (string) ($_SESSION['cpms_user_role'] ?? '');
if ($userId < 1 || $propertyId < 1
    || !in_array($role, ['staff', 'security'], true)) {
    header('Location: cpms/login.php');
    exit;
}
cpmsRequire('mobile_evidence.create', $conn);
$type = trim((string) ($_GET['type'] ?? ''));
$taskId = (int) ($_GET['id'] ?? 0);
$task = cpmsEvidenceTask($conn, $propertyId, $userId, $type, $taskId);
if ($task === null) {
    http_response_code(403);
    exit('Tugasan ini tidak dijumpai atau tidak diberikan kepada akaun anda.');
}
$evidence = [];
$stmt = $conn->prepare(
    'SELECT evidence_phase, file_path, caption, captured_at, uploaded_at
     FROM cpms_mobile_evidence
     WHERE property_id = ? AND system_user_id = ?
       AND task_type = ? AND task_id = ?
     ORDER BY uploaded_at DESC, id DESC LIMIT 30'
);
$stmt->bind_param('iisi', $propertyId, $userId, $type, $taskId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $evidence[] = $row;
}
$stmt->close();
$back = $role === 'security' ? 'security_dashboard.php' : 'staff_dashboard.php';
$csrf = cpmsNotificationCsrfToken();
function cpmsEv(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f2342">
<title>Bukti Gambar | CPMS</title>
<link rel="stylesheet" href="pwa/cpms-mobile.css?v=345">
<style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}
.top{background:linear-gradient(135deg,#0f2342,#174789);color:#fff;padding:22px 16px 38px}
.wrap{max-width:760px;margin:auto}.top a{color:#fff;text-decoration:none}.top h1{margin:12px 0 4px}
.main{max-width:760px;margin:-20px auto 0;padding:0 14px 80px}.card{background:#fff;border-radius:16px;
padding:17px;margin-bottom:14px;box-shadow:0 8px 24px rgba(15,35,66,.1)}
label{display:block;font-weight:800;margin:13px 0 6px}input,select,textarea{width:100%;
padding:12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit}textarea{min-height:78px}
.preview{display:none;width:100%;max-height:320px;object-fit:contain;border-radius:12px;margin-top:12px}
.primary{width:100%;border:0;background:#174789;color:#fff;padding:13px;border-radius:11px;
font-weight:800;margin-top:14px}.status{margin-top:12px;padding:11px;border-radius:10px;background:#eef3f9}
.status.good{background:#dcfce7;color:#166534}.status.bad{background:#fee2e2;color:#991b1b}
.queue{font-weight:800}.gallery{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.photo{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}.photo img{width:100%;height:150px;
object-fit:cover}.photo div{padding:9px;font-size:12px}@media(min-width:650px){.gallery{grid-template-columns:repeat(3,1fr)}}
</style></head><body data-cpms-pwa="<?= cpmsEv($role) ?>">
<header class="top"><div class="wrap"><a href="<?= $back ?>">← Dashboard</a>
<h1>Bukti Gambar</h1><div><?= cpmsEv((string) $task['type_label']) ?> ·
<?= cpmsEv((string) $task['title']) ?></div></div></header>
<main class="main">
<section class="card"><strong>Kamera atau Gallery</strong>
<p>Jika internet terputus, gambar disimpan selamat dalam telefon ini dan dihantar semula apabila online.</p>
<form id="evidence-form">
<input type="hidden" name="csrf_token" value="<?= cpmsEv($csrf) ?>">
<input type="hidden" name="queue_owner_id" value="<?= $userId ?>">
<input type="hidden" name="task_type" value="<?= cpmsEv($type) ?>">
<input type="hidden" name="task_id" value="<?= $taskId ?>">
<label for="evidence">Pilih gambar</label>
<input id="evidence" name="evidence" type="file" accept="image/jpeg,image/png,image/webp,image/*" required>
<img id="evidence-preview" class="preview" alt="Pratonton gambar">
<label for="phase">Peringkat bukti</label>
<select id="phase" name="evidence_phase"><option>Before</option><option selected>Progress</option>
<option>After</option><option>Issue</option><option>Supporting</option></select>
<label for="caption">Catatan</label><textarea id="caption" name="caption" maxlength="500"
placeholder="Terangkan kerja atau keadaan dalam gambar"></textarea>
<button class="primary" type="submit">Simpan / Hantar Bukti</button>
</form>
<div id="evidence-status" class="status" role="status">Sedia.</div>
<p class="queue">Queue belum dihantar: <span id="evidence-queue-count">0</span></p>
</section>
<section class="card"><h2>Bukti Telah Dihantar</h2>
<?php if (!$evidence): ?><p>Belum ada bukti dihantar untuk tugasan ini.</p>
<?php else: ?><div class="gallery"><?php foreach ($evidence as $photo): ?>
<article class="photo"><a href="<?= cpmsEv((string) $photo['file_path']) ?>" target="_blank">
<img src="<?= cpmsEv((string) $photo['file_path']) ?>" alt="Bukti"></a>
<div><strong><?= cpmsEv((string) $photo['evidence_phase']) ?></strong><br>
<?= cpmsEv((string) ($photo['caption'] ?: 'Tiada catatan')) ?></div></article>
<?php endforeach; ?></div><?php endif; ?></section>
</main>
<script>window.CPMS_EVIDENCE={
endpoint:"mobile_evidence_upload.php",ownerUserId:<?= $userId ?>,
taskType:<?= json_encode($type) ?>,taskId:<?= $taskId ?>
};</script>
<script src="pwa/cpms-mobile.js?v=345" defer></script>
<script src="pwa/cpms-evidence.js?v=344" defer></script>
</body></html>
