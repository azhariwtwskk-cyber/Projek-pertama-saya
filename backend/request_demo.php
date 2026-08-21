<?php
declare(strict_types=1);

session_start();

$lang = strtolower(trim((string) ($_GET['lang'] ?? $_POST['lang'] ?? 'ms')));
$lang = $lang === 'en' ? 'en' : 'ms';
$isEn = $lang === 'en';

function demoEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['cpms_demo_token'])) {
    $_SESSION['cpms_demo_token'] = bin2hex(random_bytes(24));
}

$success = false;
$error = '';
$values = [
    'name' => '',
    'company' => '',
    'property' => '',
    'email' => '',
    'phone' => '',
    'units' => '',
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $unused) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $token = (string) ($_POST['token'] ?? '');
    $honeypot = trim((string) ($_POST['website'] ?? ''));

    if ($honeypot !== '') {
        $success = true;
    } elseif (!hash_equals((string) $_SESSION['cpms_demo_token'], $token)) {
        $error = $isEn ? 'Your session has expired. Please refresh and try again.' : 'Sesi borang telah tamat. Sila refresh dan cuba semula.';
    } elseif ($values['name'] === '' || $values['property'] === '' || $values['email'] === '') {
        $error = $isEn ? 'Please complete all required fields.' : 'Sila lengkapkan semua ruangan wajib.';
    } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $error = $isEn ? 'Please enter a valid email address.' : 'Sila masukkan alamat e-mel yang sah.';
    } else {
        $subject = 'CPMSPro Demo Request - ' . preg_replace('/[\r\n]+/', ' ', $values['property']);
        $body = "New CPMSPro demo request\n\n"
            . "Name: {$values['name']}\n"
            . "Company: {$values['company']}\n"
            . "Property: {$values['property']}\n"
            . "Email: {$values['email']}\n"
            . "Phone: {$values['phone']}\n"
            . "Units: {$values['units']}\n\n"
            . "Message:\n{$values['message']}\n";
        $headers = [
            'From: CPMSPro Website <info@cpmspro.my>',
            'Reply-To: ' . preg_replace('/[\r\n]+/', '', $values['email']),
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $success = mail('info@cpmspro.my', $subject, $body, implode("\r\n", $headers));

        if ($success) {
            $_SESSION['cpms_demo_token'] = bin2hex(random_bytes(24));
            foreach ($values as $key => $unused) {
                $values[$key] = '';
            }
        } else {
            $error = $isEn
                ? 'The request could not be sent. Please email info@cpmspro.my.'
                : 'Permohonan tidak dapat dihantar. Sila e-mel info@cpmspro.my.';
        }
    }
}

$text = $isEn ? [
    'back' => 'Back to CPMSPro', 'kicker' => 'REQUEST A DEMO', 'title' => 'See CPMSPro in action.',
    'intro' => 'Tell us about your property. We will use these details to understand the modules that suit your operation.',
    'name' => 'Full Name', 'company' => 'Company / Management', 'property' => 'Property / Project',
    'email' => 'Email', 'phone' => 'Phone Number', 'units' => 'Number of Units', 'message' => 'What would you like to manage?',
    'send' => 'Send Demo Request', 'required' => 'Required',
    'success' => 'Thank you. Your demo request has been sent to CPMSPro.',
    'privacy' => 'By submitting, you agree that CPMSPro may contact you regarding this enquiry.',
] : [
    'back' => 'Kembali ke CPMSPro', 'kicker' => 'REQUEST DEMO', 'title' => 'Lihat bagaimana CPMSPro berfungsi.',
    'intro' => 'Beritahu kami tentang property anda. Maklumat ini membantu kami mengenal pasti modul yang sesuai untuk operasi anda.',
    'name' => 'Nama Penuh', 'company' => 'Syarikat / Pengurusan', 'property' => 'Property / Projek',
    'email' => 'E-mel', 'phone' => 'No. Telefon', 'units' => 'Bilangan Unit', 'message' => 'Apakah operasi yang anda mahu uruskan?',
    'send' => 'Hantar Permohonan Demo', 'required' => 'Wajib',
    'success' => 'Terima kasih. Permohonan demo anda telah dihantar kepada CPMSPro.',
    'privacy' => 'Dengan menghantar borang ini, anda bersetuju CPMSPro menghubungi anda berkaitan pertanyaan ini.',
];
?>
<!doctype html>
<html lang="<?= demoEscape($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= demoEscape($text['kicker']) ?> | CPMSPro</title>
    <style>
        :root{--navy:#07162f;--blue:#1769ff;--cyan:#34c6e8;--ink:#12203a;--muted:#66758f;--line:#dce4ef;--soft:#f3f6fb;--white:#fff;--green:#0f9c72;--red:#b7343f}*{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#eef3fa,#f9fbfe);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.shell{width:min(1120px,calc(100% - 32px));margin:auto}.top{height:78px;display:flex;align-items:center;justify-content:space-between}.brand{display:flex;align-items:center;gap:11px;font-weight:900}.mark{width:42px;height:42px;border-radius:13px;background:linear-gradient(145deg,var(--cyan),var(--blue));display:grid;place-items:center;color:#fff}.back{font-size:13px;font-weight:800;color:#53627b;text-decoration:none}.layout{display:grid;grid-template-columns:.85fr 1.15fr;gap:38px;padding:36px 0 70px}.intro{padding:30px 20px 20px 0}.kicker{font-size:11px;letter-spacing:2px;font-weight:900;color:var(--blue)}h1{font-size:clamp(42px,6vw,66px);line-height:1.02;letter-spacing:-3px;margin:18px 0 22px}.intro p{font-size:17px;line-height:1.7;color:var(--muted);max-width:480px}.email{display:inline-flex;margin-top:18px;color:#184eaa;font-weight:800;text-decoration:none}.card{background:#fff;border:1px solid var(--line);border-radius:26px;padding:30px;box-shadow:0 24px 70px rgba(21,45,83,.10)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.field.full{grid-column:1/-1}.field label{display:flex;justify-content:space-between;gap:10px;font-size:12px;font-weight:850;margin-bottom:8px}.req{font-size:9px;letter-spacing:1px;color:#8b99ad}.input{width:100%;border:1px solid #cfd9e7;border-radius:13px;background:#fff;padding:13px 14px;font:inherit;font-size:14px;color:var(--ink);outline:none}.input:focus{border-color:#67a1ff;box-shadow:0 0 0 4px rgba(23,105,255,.08)}textarea.input{min-height:120px;resize:vertical}.hp{position:absolute!important;left:-9999px!important}.alert{padding:13px 15px;border-radius:12px;margin-bottom:18px;font-size:13px;font-weight:750}.ok{background:#e7f8f2;color:#087755}.bad{background:#fff0f1;color:#a32934}.submit{border:0;border-radius:14px;background:linear-gradient(135deg,#1b78ff,#155fdc);color:#fff;min-height:52px;padding:0 20px;font-weight:900;font-size:14px;cursor:pointer;width:100%;margin-top:20px;box-shadow:0 13px 30px rgba(23,105,255,.22)}.privacy{font-size:10px;color:#8190a6;line-height:1.5;text-align:center;margin:12px 10px 0}.lang{display:flex;gap:6px}.lang a{border:1px solid var(--line);border-radius:9px;padding:6px 8px;font-size:10px;font-weight:900;text-decoration:none;color:#52617a;background:#fff}@media(max-width:820px){.layout{grid-template-columns:1fr;gap:10px;padding-top:10px}.intro{padding:20px 0}.grid{grid-template-columns:1fr}.field.full{grid-column:auto}.card{padding:22px}h1{letter-spacing:-2px}.top{height:68px}}
    </style>
</head>
<body>
<div class="shell">
    <header class="top"><div class="brand"><span class="mark">C</span><span>CPMSPro</span></div><div style="display:flex;align-items:center;gap:12px"><div class="lang"><a href="?lang=ms">BM</a><a href="?lang=en">EN</a></div><a class="back" href="/?lang=<?= demoEscape($lang) ?>">← <?= demoEscape($text['back']) ?></a></div></header>
    <main class="layout">
        <section class="intro"><div class="kicker"><?= demoEscape($text['kicker']) ?></div><h1><?= demoEscape($text['title']) ?></h1><p><?= demoEscape($text['intro']) ?></p><a class="email" href="mailto:info@cpmspro.my">info@cpmspro.my</a></section>
        <section class="card">
            <?php if ($success): ?><div class="alert ok"><?= demoEscape($text['success']) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert bad"><?= demoEscape($error) ?></div><?php endif; ?>
            <form method="post" action="/request_demo.php?lang=<?= demoEscape($lang) ?>">
                <input type="hidden" name="lang" value="<?= demoEscape($lang) ?>"><input type="hidden" name="token" value="<?= demoEscape((string) $_SESSION['cpms_demo_token']) ?>">
                <div class="hp"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
                <div class="grid">
                    <div class="field"><label><?= demoEscape($text['name']) ?><span class="req"><?= demoEscape($text['required']) ?></span></label><input class="input" name="name" maxlength="120" required value="<?= demoEscape($values['name']) ?>"></div>
                    <div class="field"><label><?= demoEscape($text['company']) ?></label><input class="input" name="company" maxlength="160" value="<?= demoEscape($values['company']) ?>"></div>
                    <div class="field full"><label><?= demoEscape($text['property']) ?><span class="req"><?= demoEscape($text['required']) ?></span></label><input class="input" name="property" maxlength="180" required value="<?= demoEscape($values['property']) ?>"></div>
                    <div class="field"><label><?= demoEscape($text['email']) ?><span class="req"><?= demoEscape($text['required']) ?></span></label><input class="input" type="email" name="email" maxlength="180" required value="<?= demoEscape($values['email']) ?>"></div>
                    <div class="field"><label><?= demoEscape($text['phone']) ?></label><input class="input" type="tel" name="phone" maxlength="50" value="<?= demoEscape($values['phone']) ?>"></div>
                    <div class="field"><label><?= demoEscape($text['units']) ?></label><input class="input" type="number" min="1" max="100000" name="units" value="<?= demoEscape($values['units']) ?>"></div>
                    <div class="field full"><label><?= demoEscape($text['message']) ?></label><textarea class="input" name="message" maxlength="3000"><?= demoEscape($values['message']) ?></textarea></div>
                </div>
                <button class="submit" type="submit"><?= demoEscape($text['send']) ?> →</button><p class="privacy"><?= demoEscape($text['privacy']) ?></p>
            </form>
        </section>
    </main>
</div>
</body>
</html>
