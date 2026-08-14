<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$canNewsletter = cpmsCan('reports.view', $conn)
    || cpmsCan('resident.announcement.manage', $conn)
    || cpmsCan('settings.manage', $conn);
if (!$canNewsletter) {
    http_response_code(403);
    exit('Newsletter access is not allowed.');
}

function cpmsNewsletterEnglishEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsNewsletterEnglishPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return '../' . ltrim($path, '/');
}

$newsletterId = max(0, (int) ($_GET['id'] ?? 0));
if ($newsletterId < 1) {
    http_response_code(404);
    exit('Newsletter not found.');
}

$stmt = $conn->prepare('SELECT * FROM cpms_newsletters WHERE id=? AND property_id=? LIMIT 1');
$stmt->bind_param('ii', $newsletterId, $currentPropertyId);
$stmt->execute();
$newsletter = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$newsletter) {
    http_response_code(404);
    exit('Newsletter not found for this property.');
}

$mediaStmt = $conn->prepare(
    'SELECT file_path,caption FROM cpms_newsletter_media '
    . 'WHERE newsletter_id=? AND property_id=? ORDER BY sort_order,id'
);
$mediaStmt->bind_param('ii', $newsletterId, $currentPropertyId);
$mediaStmt->execute();
$media = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$mediaStmt->close();

$statistics = json_decode((string) ($newsletter['statistics_json'] ?? ''), true);
if (!is_array($statistics)) $statistics = [];
$stats = [
    'Complaints Received' => (int) ($statistics['complaints'] ?? 0),
    'Resolved Complaints' => (int) ($statistics['resolved'] ?? 0),
    'Work Orders' => (int) ($statistics['work_orders'] ?? 0),
    'Daily Work' => (int) ($statistics['daily_work'] ?? 0),
    'PM This Month' => (int) ($statistics['pm'] ?? 0),
];

$monthNumber = max(1, min(12, (int) ($newsletter['newsletter_month'] ?? 1)));
$year = (int) ($newsletter['newsletter_year'] ?? date('Y'));
$monthName = date('F', mktime(0, 0, 0, $monthNumber, 1));
$status = strtolower((string) ($newsletter['status'] ?? 'draft'));
$isPublished = $status === 'published';
$logo = cpmsNewsletterEnglishPath((string) ($propertyPortalUser['logo_path'] ?? ''));
$primary = trim((string) ($propertyPortalUser['primary_color'] ?? '#173a70'));
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#173a70';
$secondary = trim((string) ($propertyPortalUser['secondary_color'] ?? '#249ad8'));
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $secondary)) $secondary = '#249ad8';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo cpmsNewsletterEnglishEscape($newsletter['title']); ?> - Review</title>
<style>
:root{--primary:<?php echo cpmsNewsletterEnglishEscape($primary); ?>;--secondary:<?php echo cpmsNewsletterEnglishEscape($secondary); ?>;--ink:#17233b;--muted:#667085;--line:#dbe3ef;--bg:#edf2f8}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:var(--ink);line-height:1.55}.toolbar{position:sticky;top:0;z-index:50;display:flex;gap:10px;justify-content:center;padding:12px;background:#0f1b30}.toolbar button,.toolbar a{border:0;border-radius:9px;padding:11px 17px;font-weight:700;text-decoration:none;cursor:pointer}.toolbar button{background:#fff;color:#0f1b30}.toolbar a{background:#253552;color:#fff}.sheet{position:relative;width:min(920px,calc(100% - 28px));margin:28px auto;background:#fff;box-shadow:0 18px 55px rgba(25,45,80,.13);overflow:hidden}.watermark{position:fixed;z-index:3;left:50%;top:49%;transform:translate(-50%,-50%) rotate(-28deg);font-weight:900;font-size:68px;letter-spacing:7px;color:rgba(177,28,28,.075);white-space:nowrap;pointer-events:none}.masthead{padding:48px 54px 42px;color:#fff;background:linear-gradient(135deg,var(--primary),#0b2347 75%)}.brand{display:flex;align-items:center;gap:18px;margin-bottom:34px}.brand img{width:78px;height:78px;object-fit:contain;border-radius:14px;background:#fff;padding:7px}.property,.eyebrow{font-size:11px;letter-spacing:1.5px;font-weight:800;text-transform:uppercase}.property{opacity:.82}.masthead h1{margin:4px 0 6px;font-size:42px;line-height:1.1}.edition{font-size:18px;color:#dbeafe}.status{display:inline-block;margin-top:22px;padding:7px 11px;border:1px solid rgba(255,255,255,.35);border-radius:999px;font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase}.content{padding:42px 54px 50px}.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:36px}.stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 14px}.stat small{display:block;color:var(--muted);font-size:11px;min-height:34px}.stat strong{font-size:27px;color:var(--primary)}.section{padding:24px 0;border-bottom:1px solid var(--line);break-inside:avoid}.section:last-of-type{border-bottom:0}.eyebrow{color:var(--secondary)}h2{margin:5px 0 12px;font-size:25px}.text{white-space:pre-line;color:#344054}.gallery{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;margin-top:18px}.gallery figure{margin:0;border:1px solid var(--line);border-radius:13px;overflow:hidden;background:#f8fafc;break-inside:avoid}.gallery img{width:100%;height:260px;object-fit:cover;display:block}.gallery figcaption{padding:11px 13px;font-size:12px;color:#475467}.footer{padding:25px 54px 34px;border-top:1px solid var(--line);font-size:11px;color:var(--muted);display:flex;justify-content:space-between;gap:20px}.review-note{margin-top:24px;padding:14px;border-radius:10px;background:#fff5f5;border:1px solid #fecaca;color:#991b1b;font-size:12px;font-weight:700}@media(max-width:700px){.masthead,.content,.footer{padding-left:24px;padding-right:24px}.masthead h1{font-size:32px}.stats{grid-template-columns:repeat(2,1fr)}.gallery{grid-template-columns:1fr}.brand img{width:60px;height:60px}.footer{display:block}}@media print{@page{size:A4;margin:10mm}body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}.toolbar{display:none}.sheet{width:100%;margin:0;box-shadow:none}.masthead{padding:28px 34px 42px}.content{padding:34px}.footer{padding:22px 34px}.stats,.section,.gallery figure{break-inside:avoid}.watermark{font-size:54px}.gallery img{height:210px}}
</style>
</head>
<body>
<div class="toolbar">
    <a href="newsletter.php?id=<?php echo (int) $newsletterId; ?>">← Back</a>
    <button type="button" onclick="window.print()">Print / Save PDF</button>
</div>
<?php if (!$isPublished): ?><div class="watermark">DRAFT · FOR REVIEW</div><?php endif; ?>
<main class="sheet">
    <header class="masthead">
        <div class="brand">
            <?php if ($logo !== ''): ?><img src="<?php echo cpmsNewsletterEnglishEscape($logo); ?>" alt="Property logo"><?php endif; ?>
            <div><div class="property"><?php echo cpmsNewsletterEnglishEscape($currentPropertyName); ?></div><div><?php echo cpmsNewsletterEnglishEscape((string) ($propertyPortalUser['company_name'] ?? '')); ?></div></div>
        </div>
        <div class="property">Monthly Newsletter</div>
        <h1><?php echo cpmsNewsletterEnglishEscape($newsletter['title']); ?></h1>
        <div class="edition"><?php echo cpmsNewsletterEnglishEscape($monthName . ' ' . $year); ?></div>
        <span class="status"><?php echo cpmsNewsletterEnglishEscape($isPublished ? 'Published' : 'Review Copy'); ?></span>
    </header>
    <div class="content">
        <div class="stats">
        <?php foreach ($stats as $label => $value): ?><div class="stat"><small><?php echo cpmsNewsletterEnglishEscape($label); ?></small><strong><?php echo (int) $value; ?></strong></div><?php endforeach; ?>
        </div>
        <section class="section"><div class="eyebrow">PROPERTY MANAGER</div><h2>Property Manager Message</h2><div class="text"><?php echo nl2br(cpmsNewsletterEnglishEscape((string) ($newsletter['manager_message'] ?? ''))); ?></div></section>
        <section class="section"><div class="eyebrow">MONTHLY HIGHLIGHTS</div><h2>Monthly Highlights</h2><div class="text"><?php echo nl2br(cpmsNewsletterEnglishEscape((string) ($newsletter['highlights'] ?? ''))); ?></div></section>
        <section class="section"><div class="eyebrow">NEXT MONTH</div><h2>Upcoming Activities / Plans</h2><div class="text"><?php echo nl2br(cpmsNewsletterEnglishEscape((string) ($newsletter['upcoming_activities'] ?? ''))); ?></div></section>
        <?php if ($media): ?><section class="section"><div class="eyebrow">PHOTO REPORT</div><h2>Photo Activities</h2><div class="gallery">
        <?php foreach ($media as $image): ?><figure><img src="<?php echo cpmsNewsletterEnglishEscape(cpmsNewsletterEnglishPath((string) $image['file_path'])); ?>" alt="Newsletter photo"><figcaption><?php echo cpmsNewsletterEnglishEscape((string) ($image['caption'] ?? '')); ?></figcaption></figure><?php endforeach; ?>
        </div></section><?php endif; ?>
        <?php if (!$isPublished): ?><div class="review-note">DRAFT – FOR REVIEW ONLY. This document has not been published to residents/public.</div><?php endif; ?>
    </div>
    <footer class="footer"><span><?php echo cpmsNewsletterEnglishEscape($currentPropertyName); ?> · CPMSPro Monthly Newsletter</span><span>Generated: <?php echo cpmsNewsletterEnglishEscape(date('d M Y, h:i A')); ?></span></footer>
</main>
</body></html>