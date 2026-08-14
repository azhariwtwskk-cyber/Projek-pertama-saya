<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/system_settings.php';
require_once dirname(__DIR__) . '/includes/system_branding_upload.php';

const CPMS_GLOBAL_SETTINGS_VERSION = '3.4.11';

$textKeys = [
    'system_name',
    'system_short_name',
    'company_name',
    'system_tagline',
    'primary_color',
    'secondary_color',
    'contact_phone',
    'contact_email',
    'address',
    'powered_by',
    'default_language',
    'login_secure_label_ms',
    'login_secure_label_en',
    'login_eyebrow_ms',
    'login_eyebrow_en',
    'login_brand_title_ms',
    'login_brand_title_en',
    'login_brand_description_ms',
    'login_brand_description_en',
    'login_title_ms',
    'login_title_en',
    'login_description_ms',
    'login_description_en',
    'login_footer_ms',
    'login_footer_en',
];

$successMessage = '';
$errorMessage = '';
$csrfToken = systemOwnerCsrfToken();
$settings = loadSystemSettings($conn);

function soSetting(array $settings, string $key, string $default = ''): string
{
    return (string) ($settings[$key] ?? $default);
}

function soAssetPreview(array $settings, string $key): string
{
    $path = cpmsSystemSettingAsset(soSetting($settings, $key));

    return $path === '' ? '' : '../' . $path;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!systemOwnerVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Permintaan tidak sah. Sila muat semula halaman.';
    } else {
        try {
            $values = [];
            foreach ($textKeys as $key) {
                $values[$key] = trim((string) ($_POST[$key] ?? ''));
            }

            foreach (['system_name', 'system_short_name', 'company_name'] as $requiredKey) {
                if ($values[$requiredKey] === '') {
                    throw new RuntimeException('Nama sistem, nama pendek dan syarikat wajib diisi.');
                }
            }

            if (strlen($values['system_short_name']) > 24) {
                throw new RuntimeException('Nama pendek mestilah 24 aksara atau kurang.');
            }

            foreach ($values as $key => $value) {
                if (strlen($value) > 1000) {
                    throw new RuntimeException('Salah satu kandungan tetapan terlalu panjang.');
                }
            }

            if (preg_match('/^#[0-9a-fA-F]{6}$/', $values['primary_color']) !== 1
                || preg_match('/^#[0-9a-fA-F]{6}$/', $values['secondary_color']) !== 1
            ) {
                throw new RuntimeException('Kod warna global tidak sah.');
            }

            if (!in_array($values['default_language'], ['ms', 'en'], true)) {
                $values['default_language'] = 'ms';
            }

            if ($values['contact_email'] !== ''
                && filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL) === false
            ) {
                throw new RuntimeException('Alamat e-mel tidak sah.');
            }

            $assetFields = [
                'system_logo' => ['key' => 'logo_path', 'label' => 'logo sistem'],
                'system_favicon' => ['key' => 'favicon_path', 'label' => 'favicon'],
                'login_background' => ['key' => 'background_path', 'label' => 'latar login'],
            ];

            foreach ($assetFields as $field => $asset) {
                $assetKey = (string) $asset['key'];
                $removeKey = 'remove_' . $assetKey;
                $currentValue = soSetting($settings, $assetKey);
                $values[$assetKey] = isset($_POST[$removeKey]) ? '' : $currentValue;

                if (isset($_FILES[$field]) && is_array($_FILES[$field])) {
                    $uploadedPath = cpmsSystemBrandingUpload(
                        $_FILES[$field],
                        (string) $asset['label']
                    );
                    if ($uploadedPath !== null) {
                        $values[$assetKey] = $uploadedPath;
                    }
                }
            }

            $values['software_version'] = CPMS_GLOBAL_SETTINGS_VERSION;
            $stmt = $conn->prepare(
                'INSERT INTO system_settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );

            if (!$stmt) {
                throw new RuntimeException('Tetapan sistem tidak dapat disediakan.');
            }

            $conn->begin_transaction();
            try {
                foreach ($values as $key => $value) {
                    $stmt->bind_param('ss', $key, $value);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Tetapan gagal disimpan.');
                    }
                }
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            } finally {
                $stmt->close();
            }

            $settings = loadSystemSettings($conn);
            $successMessage = 'Tetapan dan Unified Login berjaya dikemas kini.';
        } catch (Throwable $error) {
            $errorMessage = $error->getMessage() !== ''
                ? $error->getMessage()
                : 'Tetapan gagal disimpan.';
            cpmsFoundationLog('System Owner settings update failed: ' . $error->getMessage());
        }
    }
}

$primaryColor = cpmsSystemSettingHex(soSetting($settings, 'primary_color'), '#0f2342');
$secondaryColor = cpmsSystemSettingHex(soSetting($settings, 'secondary_color'), '#d6a84b');
$logoPreview = soAssetPreview($settings, 'logo_path');
$faviconPreview = soAssetPreview($settings, 'favicon_path');
$backgroundPreview = soAssetPreview($settings, 'background_path');
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tetapan Global | CPMS System Owner</title>
<link rel="stylesheet" href="assets/portal.css">
<style>
:root{--brand-primary:<?php echo systemOwnerEscape($primaryColor); ?>;--brand-secondary:<?php echo systemOwnerEscape($secondaryColor); ?>}
*{box-sizing:border-box}.so-page{max-width:1180px;margin:0 auto;padding:24px}.so-topbar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px}.so-topbar-actions,.so-actions{display:flex;flex-wrap:wrap;gap:10px}.so-version{display:inline-flex;align-items:center;padding:7px 11px;border:1px solid #dbe4ef;border-radius:999px;background:#fff;color:#475569;font-size:13px;font-weight:800}.so-hero{padding:26px;border-radius:20px;color:#fff;background:linear-gradient(125deg,var(--brand-primary),#183d6d 68%,var(--brand-secondary));box-shadow:0 18px 45px rgba(15,35,66,.18);margin-bottom:18px}.so-hero h1{font-size:clamp(26px,4vw,40px);margin:0 0 8px}.so-hero p{max-width:760px;margin:0;line-height:1.65;color:rgba(255,255,255,.86)}.so-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:18px;align-items:start}.so-card{background:#fff;border:1px solid #dfe7f0;border-radius:16px;padding:22px;box-shadow:0 8px 24px rgba(15,35,66,.06);margin-bottom:18px}.so-card h2{font-size:19px;margin:0 0 6px;color:#0f2342}.so-card-intro{margin:0 0 18px;color:#64748b;font-size:14px;line-height:1.55}.so-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.so-field{display:flex;flex-direction:column;gap:7px}.so-field.full{grid-column:1/-1}.so-field label{font-size:13px;font-weight:850;color:#334155}.so-field small{color:#64748b;line-height:1.4}.so-field input,.so-field textarea,.so-field select{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#0f172a;font:inherit;transition:border-color .2s,box-shadow .2s}.so-field input:focus,.so-field textarea:focus,.so-field select:focus{outline:0;border-color:var(--brand-primary);box-shadow:0 0 0 3px rgba(29,79,145,.12)}.so-field textarea{min-height:94px;resize:vertical}.so-field textarea.tall{min-height:124px}.so-color-row{display:grid;grid-template-columns:54px 1fr;gap:8px}.so-color-row input[type=color]{padding:4px;height:44px}.so-asset{display:grid;grid-template-columns:92px minmax(0,1fr);gap:14px;align-items:center;padding:12px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc}.so-preview{width:92px;height:72px;display:flex;align-items:center;justify-content:center;overflow:hidden;border-radius:10px;background:#e8eef5;color:#64748b;font-size:12px;font-weight:800;text-align:center}.so-preview img{width:100%;height:100%;object-fit:contain}.so-preview.bg img{object-fit:cover}.so-remove{display:flex;align-items:center;gap:7px;margin-top:8px;font-size:12px;color:#64748b}.so-remove input{width:auto}.so-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:9px;padding:11px 16px;font-weight:850;cursor:pointer;text-decoration:none}.so-btn.primary{background:var(--brand-primary);color:#fff}.so-btn.secondary{background:#e9eef5;color:#334155}.notice{padding:13px 14px;border-radius:10px;margin-bottom:16px;font-weight:700}.notice.ok{background:#dcfce7;color:#166534}.notice.err{background:#fee2e2;color:#991b1b}.so-sticky{position:sticky;top:18px}.so-login-preview{min-height:420px;border-radius:16px;overflow:hidden;color:#fff;background:linear-gradient(145deg,rgba(15,35,66,.94),rgba(29,79,145,.82)),var(--preview-bg,none);background-size:cover;background-position:center;padding:24px;display:flex;flex-direction:column;justify-content:space-between}.so-login-mark{width:52px;height:52px;border-radius:15px;display:flex;align-items:center;justify-content:center;background:#fff;color:var(--brand-primary);font-size:18px;font-weight:900;overflow:hidden}.so-login-mark img{width:100%;height:100%;object-fit:contain}.so-login-eyebrow{font-size:11px;letter-spacing:.12em;font-weight:900;color:var(--brand-secondary);margin:24px 0 10px}.so-login-preview h3{font-size:31px;line-height:1.08;margin:0 0 13px}.so-login-preview p{font-size:13px;line-height:1.6;color:rgba(255,255,255,.8);margin:0}.so-login-footer{font-size:11px;color:rgba(255,255,255,.65)}.so-note{padding:14px;border-left:4px solid var(--brand-secondary);border-radius:8px;background:#fff8e7;color:#6b5219;font-size:13px;line-height:1.55;margin-bottom:18px}.so-savebar{display:flex;justify-content:flex-end;padding:16px;background:#fff;border:1px solid #dfe7f0;border-radius:14px;box-shadow:0 8px 24px rgba(15,35,66,.06)}@media(max-width:960px){.so-layout{grid-template-columns:1fr}.so-sticky{position:static}.so-login-preview{min-height:340px}}@media(max-width:680px){.so-page{padding:14px}.so-topbar{align-items:flex-start;flex-direction:column}.so-grid{grid-template-columns:1fr}.so-field.full{grid-column:auto}.so-card{padding:17px}.so-asset{grid-template-columns:72px minmax(0,1fr)}.so-preview{width:72px}}
</style>
</head>
<body>
<main class="so-page">
<div class="so-topbar">
    <div class="so-topbar-actions">
        <a class="so-btn secondary" href="dashboard.php">&larr; Dashboard</a>
        <a class="so-btn secondary" href="modules.php">Module Manager</a>
    </div>
    <span class="so-version">CPMS v<?php echo systemOwnerEscape(CPMS_GLOBAL_SETTINGS_VERSION); ?></span>
</div>

<section class="so-hero">
    <h1>Unified Global Branding</h1>
    <p>Kawal identiti utama CPMS dan paparan Unified Login daripada satu tempat. Semua perubahan di sini digunakan secara global, manakala nama, logo dan warna setiap property kekal diurus secara berasingan.</p>
</section>

<?php if ($successMessage !== ''): ?>
    <div class="notice ok"><?php echo systemOwnerEscape($successMessage); ?></div>
<?php endif; ?>
<?php if ($errorMessage !== ''): ?>
    <div class="notice err"><?php echo systemOwnerEscape($errorMessage); ?></div>
<?php endif; ?>

<div class="so-note"><strong>Pemisahan branding:</strong> halaman ini tidak mengubah rekod branding dalam property. Ia hanya mengawal jenama induk CPMS dan halaman Unified Login.</div>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?php echo systemOwnerEscape($csrfToken); ?>">
<div class="so-layout">
<div>
    <section class="so-card">
        <h2>Identiti sistem</h2>
        <p class="so-card-intro">Nama rasmi yang digunakan pada login dan maklumat sistem.</p>
        <div class="so-grid">
            <div class="so-field full"><label for="system_name">Nama penuh CPMS</label><input id="system_name" name="system_name" maxlength="180" required value="<?php echo systemOwnerEscape(soSetting($settings, 'system_name')); ?>"></div>
            <div class="so-field"><label for="system_short_name">Nama pendek</label><input id="system_short_name" name="system_short_name" maxlength="24" required value="<?php echo systemOwnerEscape(soSetting($settings, 'system_short_name')); ?>"></div>
            <div class="so-field"><label for="company_name">Syarikat / pemilik sistem</label><input id="company_name" name="company_name" maxlength="180" required value="<?php echo systemOwnerEscape(soSetting($settings, 'company_name')); ?>"></div>
            <div class="so-field full"><label for="system_tagline">Tagline</label><input id="system_tagline" name="system_tagline" maxlength="180" value="<?php echo systemOwnerEscape(soSetting($settings, 'system_tagline')); ?>"></div>
            <div class="so-field"><label for="powered_by">Powered by</label><input id="powered_by" name="powered_by" maxlength="180" value="<?php echo systemOwnerEscape(soSetting($settings, 'powered_by')); ?>"></div>
            <div class="so-field"><label for="default_language">Bahasa lalai</label><select id="default_language" name="default_language"><option value="ms"<?php echo soSetting($settings, 'default_language') === 'ms' ? ' selected' : ''; ?>>Bahasa Melayu</option><option value="en"<?php echo soSetting($settings, 'default_language') === 'en' ? ' selected' : ''; ?>>English</option></select></div>
            <div class="so-field"><label>Versi sistem semasa</label><input value="<?php echo systemOwnerEscape(CPMS_GLOBAL_SETTINGS_VERSION); ?>" readonly><small>Versi dikawal oleh pakej sistem.</small></div>
        </div>
    </section>

    <section class="so-card">
        <h2>Warna global</h2>
        <p class="so-card-intro">Warna ini digunakan terus pada Unified Login.</p>
        <div class="so-grid">
            <div class="so-field"><label for="primary_color">Warna utama</label><div class="so-color-row"><input type="color" id="primary_color_picker" value="<?php echo systemOwnerEscape($primaryColor); ?>" data-color-target="primary_color"><input id="primary_color" name="primary_color" pattern="#[0-9A-Fa-f]{6}" required value="<?php echo systemOwnerEscape($primaryColor); ?>"></div></div>
            <div class="so-field"><label for="secondary_color">Warna aksen</label><div class="so-color-row"><input type="color" id="secondary_color_picker" value="<?php echo systemOwnerEscape($secondaryColor); ?>" data-color-target="secondary_color"><input id="secondary_color" name="secondary_color" pattern="#[0-9A-Fa-f]{6}" required value="<?php echo systemOwnerEscape($secondaryColor); ?>"></div></div>
        </div>
    </section>

    <section class="so-card">
        <h2>Aset visual</h2>
        <p class="so-card-intro">PNG, JPG, WEBP atau ICO, maksimum 5 MB bagi setiap fail.</p>
        <div class="so-grid">
            <div class="so-field full">
                <label for="system_logo">Logo CPMS</label>
                <div class="so-asset"><div class="so-preview"><?php if ($logoPreview !== ''): ?><img src="<?php echo systemOwnerEscape($logoPreview); ?>" alt="Logo semasa"><?php else: ?>Tiada logo<?php endif; ?></div><div><input type="file" id="system_logo" name="system_logo" accept=".png,.jpg,.jpeg,.webp,.ico,image/*"><label class="so-remove"><input type="checkbox" name="remove_logo_path" value="1"> Buang logo semasa</label></div></div>
            </div>
            <div class="so-field full">
                <label for="system_favicon">Favicon browser</label>
                <div class="so-asset"><div class="so-preview"><?php if ($faviconPreview !== ''): ?><img src="<?php echo systemOwnerEscape($faviconPreview); ?>" alt="Favicon semasa"><?php else: ?>Tiada favicon<?php endif; ?></div><div><input type="file" id="system_favicon" name="system_favicon" accept=".png,.jpg,.jpeg,.webp,.ico,image/*"><label class="so-remove"><input type="checkbox" name="remove_favicon_path" value="1"> Buang favicon semasa</label></div></div>
            </div>
            <div class="so-field full">
                <label for="login_background">Latar belakang Unified Login</label>
                <div class="so-asset"><div class="so-preview bg"><?php if ($backgroundPreview !== ''): ?><img src="<?php echo systemOwnerEscape($backgroundPreview); ?>" alt="Latar semasa"><?php else: ?>Tiada latar<?php endif; ?></div><div><input type="file" id="login_background" name="login_background" accept=".png,.jpg,.jpeg,.webp,image/*"><label class="so-remove"><input type="checkbox" name="remove_background_path" value="1"> Buang latar semasa</label></div></div>
            </div>
        </div>
    </section>

    <section class="so-card">
        <h2>Kandungan Unified Login</h2>
        <p class="so-card-intro">Semua tajuk dan penerangan mempunyai versi Bahasa Melayu dan English.</p>
        <div class="so-grid">
            <div class="so-field"><label for="login_secure_label_ms">Label selamat (BM)</label><input id="login_secure_label_ms" name="login_secure_label_ms" maxlength="120" value="<?php echo systemOwnerEscape(soSetting($settings, 'login_secure_label_ms')); ?>"></div>
            <div class="so-field"><label for="login_secure_label_en">Secure label (EN)</label><input id="login_secure_label_en" name="login_secure_label_en" maxlength="120" value="<?php echo systemOwnerEscape(soSetting($settings, 'login_secure_label_en')); ?>"></div>
            <div class="so-field"><label for="login_eyebrow_ms">Eyebrow (BM)</label><input id="login_eyebrow_ms" name="login_eyebrow_ms" maxlength="180" value="<?php echo systemOwnerEscape(soSetting($settings, 'login_eyebrow_ms')); ?>"></div>
            <div class="so-field"><label for="login_eyebrow_en">Eyebrow (EN)</label><input id="login_eyebrow_en" name="login_eyebrow_en" maxlength="180" value="<?php echo systemOwnerEscape(soSetting($settings, 'login_eyebrow_en')); ?>"></div>
            <div class="so-field"><label for="login_brand_title_ms">Tajuk jenama (BM)</label><textarea id="login_brand_title_ms" name="login_brand_title_ms"><?php echo systemOwnerEscape(soSetting($settings, 'login_brand_title_ms')); ?></textarea></div>
            <div class="so-field"><label for="login_brand_title_en">Brand title (EN)</label><textarea id="login_brand_title_en" name="login_brand_title_en"><?php echo systemOwnerEscape(soSetting($settings, 'login_brand_title_en')); ?></textarea></div>
            <div class="so-field"><label for="login_brand_description_ms">Penerangan jenama (BM)</label><textarea class="tall" id="login_brand_description_ms" name="login_brand_description_ms"><?php echo systemOwnerEscape(soSetting($settings, 'login_brand_description_ms')); ?></textarea></div>
            <div class="so-field"><label for="login_brand_description_en">Brand description (EN)</label><textarea class="tall" id="login_brand_description_en" name="login_brand_description_en"><?php echo systemOwnerEscape(soSetting($settings, 'login_brand_description_en')); ?></textarea></div>
            <div class="so-field"><label for="login_title_ms">Tajuk borang (BM)</label><input id="login_title_ms" name="login_title_ms" maxlength="180" value="<?php echo systemOwnerEscape(soSetting($settings, 'login_title_ms')); ?>"></div>
            <div class="so-field"><label for="login_title_en">Form title (EN)</label><input id="login_title_en" name="login_title_en" maxlength="180" value="<?php echo systemOwnerEscape(soSetting($settings, 'login_title_en')); ?>"></div>
            <div class="so-field"><label for="login_description_ms">Penerangan borang (BM)</label><textarea id="login_description_ms" name="login_description_ms"><?php echo systemOwnerEscape(soSetting($settings, 'login_description_ms')); ?></textarea></div>
            <div class="so-field"><label for="login_description_en">Form description (EN)</label><textarea id="login_description_en" name="login_description_en"><?php echo systemOwnerEscape(soSetting($settings, 'login_description_en')); ?></textarea></div>
            <div class="so-field"><label for="login_footer_ms">Footer login (BM)</label><input id="login_footer_ms" name="login_footer_ms" maxlength="180" value="<?php echo systemOwnerEscape(soSetting($settings, 'login_footer_ms')); ?>"></div>
            <div class="so-field"><label for="login_footer_en">Login footer (EN)</label><input id="login_footer_en" name="login_footer_en" maxlength="180" value="<?php echo systemOwnerEscape(soSetting($settings, 'login_footer_en')); ?>"></div>
        </div>
    </section>

    <section class="so-card">
        <h2>Maklumat hubungan</h2>
        <p class="so-card-intro">Maklumat pemilik sistem untuk rujukan pentadbiran.</p>
        <div class="so-grid">
            <div class="so-field"><label for="contact_phone">Telefon</label><input id="contact_phone" name="contact_phone" maxlength="80" value="<?php echo systemOwnerEscape(soSetting($settings, 'contact_phone')); ?>"></div>
            <div class="so-field"><label for="contact_email">E-mel</label><input type="email" id="contact_email" name="contact_email" maxlength="180" value="<?php echo systemOwnerEscape(soSetting($settings, 'contact_email')); ?>"></div>
            <div class="so-field full"><label for="address">Alamat</label><textarea id="address" name="address"><?php echo systemOwnerEscape(soSetting($settings, 'address')); ?></textarea></div>
        </div>
    </section>

    <div class="so-savebar"><button class="so-btn primary" type="submit">Simpan &amp; Kemas Kini Unified Login</button></div>
</div>

<aside class="so-sticky">
    <section class="so-card">
        <h2>Pratonton ringkas</h2>
        <p class="so-card-intro">Pratonton menggunakan tetapan yang telah disimpan.</p>
        <div class="so-login-preview"<?php if ($backgroundPreview !== ''): ?> style="--preview-bg:url('<?php echo systemOwnerEscape($backgroundPreview); ?>')"<?php endif; ?>>
            <div>
                <div class="so-login-mark"><?php if ($logoPreview !== ''): ?><img src="<?php echo systemOwnerEscape($logoPreview); ?>" alt="Logo"><?php else: ?><?php echo systemOwnerEscape(strtoupper(substr(soSetting($settings, 'system_short_name', 'CP'), 0, 2))); ?><?php endif; ?></div>
                <div class="so-login-eyebrow"><?php echo systemOwnerEscape(soSetting($settings, 'login_eyebrow_ms')); ?></div>
                <h3><?php echo nl2br(systemOwnerEscape(soSetting($settings, 'login_brand_title_ms'))); ?></h3>
                <p><?php echo systemOwnerEscape(soSetting($settings, 'login_brand_description_ms')); ?></p>
            </div>
            <div class="so-login-footer"><?php echo systemOwnerEscape(soSetting($settings, 'company_name')); ?> &middot; v<?php echo systemOwnerEscape(CPMS_GLOBAL_SETTINGS_VERSION); ?></div>
        </div>
    </section>
</aside>
</div>
</form>
</main>
<script>
document.querySelectorAll('[data-color-target]').forEach(function (picker) {
    var target = document.getElementById(picker.getAttribute('data-color-target'));
    if (!target) return;
    picker.addEventListener('input', function () { target.value = picker.value; });
    target.addEventListener('input', function () {
        if (/^#[0-9a-fA-F]{6}$/.test(target.value)) picker.value = target.value;
    });
});
</script>
</body>
</html>
