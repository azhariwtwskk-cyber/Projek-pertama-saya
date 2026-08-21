<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cpmsPropertyRequire('settings.manage');
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/includes/branding_upload.php';

if (!cpmsPropertyCan('settings.manage')) {
    propertyPortalRedirect('access_denied.php');
}

$pageTitle = 'Branding Settings';
$activeMenu = 'branding';

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (
        !propertyPortalVerifyCsrf(
            is_string($token) ? $token : null
        )
    ) {
        $errors[] = 'The security session is invalid.';
    }

    $systemName = trim((string) ($_POST['system_name'] ?? ''));
    $propertyName = trim((string) ($_POST['property_name'] ?? ''));
    $companyName = trim((string) ($_POST['company_name'] ?? ''));
    $tagline = trim((string) ($_POST['tagline'] ?? ''));
    $primaryColor = cpmsBrandingHex(
        $_POST['primary_color'] ?? null,
        '#2563eb'
    );
    $secondaryColor = cpmsBrandingHex(
        $_POST['secondary_color'] ?? null,
        '#0f172a'
    );
    $address = trim((string) ($_POST['address'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $operatingHours = trim(
        (string) ($_POST['operating_hours'] ?? '')
    );
    $footerText = trim((string) ($_POST['footer_text'] ?? ''));
    $showCpms = isset($_POST['show_cpms_branding']) ? 1 : 0;

    if ($systemName === '') {
        $errors[] = 'System name is required.';
    }

    if ($propertyName === '') {
        $errors[] = 'Property name is required.';
    }

    if (
        $email !== ''
        && !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'Enter a valid property email address.';
    }

    $logoPath = (string) ($propertyPortalUser['logo_path'] ?? '');
    $faviconPath = (string) (
        $propertyPortalUser['favicon_path'] ?? ''
    );
    $backgroundPath = (string) (
        $propertyPortalUser['background_path'] ?? ''
    );
    $dashboardBannerPath = (string) (
        $propertyPortalUser['dashboard_banner_path'] ?? ''
    );

    if (!$errors) {
        try {
            $uploadedLogo = cpmsBrandingUpload(
                $_FILES['logo'] ?? [],
                'logo',
                $currentPropertyId
            );
            $uploadedFavicon = cpmsBrandingUpload(
                $_FILES['favicon'] ?? [],
                'favicon',
                $currentPropertyId
            );
            $uploadedBackground = cpmsBrandingUpload(
                $_FILES['background'] ?? [],
                'background',
                $currentPropertyId
            );
            $uploadedBanner = cpmsBrandingUpload(
                $_FILES['dashboard_banner'] ?? [],
                'dashboard_banner',
                $currentPropertyId
            );

            if ($uploadedLogo !== null) {
                $logoPath = $uploadedLogo;
            }

            if ($uploadedFavicon !== null) {
                $faviconPath = $uploadedFavicon;
            }

            if ($uploadedBackground !== null) {
                $backgroundPath = $uploadedBackground;
            }

            if ($uploadedBanner !== null) {
                $dashboardBannerPath = $uploadedBanner;
            }
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if (!$errors) {
        $stmt = $conn->prepare(
            "UPDATE cpms_properties
             SET
                system_name = ?,
                property_name = ?,
                company_name = ?,
                tagline = ?,
                primary_color = ?,
                secondary_color = ?,
                address = ?,
                phone = ?,
                email = ?,
                operating_hours = ?,
                footer_text = ?,
                show_cpms_branding = ?,
                logo_path = ?,
                favicon_path = ?,
                background_path = ?,
                dashboard_banner_path = ?
             WHERE id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            $errors[] = 'Branding settings could not be prepared.';
        } else {
            $stmt->bind_param(
                'sssssssssssissssi',
                $systemName,
                $propertyName,
                $companyName,
                $tagline,
                $primaryColor,
                $secondaryColor,
                $address,
                $phone,
                $email,
                $operatingHours,
                $footerText,
                $showCpms,
                $logoPath,
                $faviconPath,
                $backgroundPath,
                $dashboardBannerPath,
                $currentPropertyId
            );

            if ($stmt->execute()) {
                $success = 'Branding settings have been updated.';
            } else {
                $errors[] = 'Branding settings could not be saved.';
            }

            $stmt->close();
        }
    }

    if ($success !== '') {
        $refresh = $conn->prepare(
            "SELECT *
             FROM cpms_properties
             WHERE id = ?
             LIMIT 1"
        );

        if ($refresh) {
            $refresh->bind_param('i', $currentPropertyId);
            $refresh->execute();
            $property = $refresh->get_result()->fetch_assoc();
            $refresh->close();

            if ($property) {
                $propertyPortalUser = array_merge(
                    $propertyPortalUser,
                    $property
                );
                $currentPropertyName = (string) (
                    $property['property_name']
                    ?? $currentPropertyName
                );
            }
        }
    }
}

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<section class="page-heading">
    <div>
        <span class="page-eyebrow">WHITE LABEL ENGINE</span>
        <h1>Branding Settings</h1>
        <p>
            Manage one unified background for the landing page and property login portal.
        </p>
    </div>
</section>

<?php if ($success !== ''): ?>
    <div class="branding-alert branding-alert-success">
        <?php echo propertyPortalEscape($success); ?>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="branding-alert branding-alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?php echo propertyPortalEscape($error); ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form
    method="post"
    enctype="multipart/form-data"
    class="branding-settings-grid"
>
    <input
        type="hidden"
        name="csrf_token"
        value="<?php echo propertyPortalEscape(
            propertyPortalCsrfToken()
        ); ?>"
    >

    <section class="panel branding-form-panel">
        <div class="panel-heading">
            <div>
                <span>Identity</span>
                <h2>Property Branding</h2>
            </div>
        </div>

        <div class="branding-form-grid">
            <label>
                <span>System Name</span>
                <input
                    type="text"
                    name="system_name"
                    required
                    value="<?php echo propertyPortalEscape(
                        cpmsBrandingValue(
                            $propertyPortalUser,
                            'system_name',
                            'Property Management System'
                        )
                    ); ?>"
                >
            </label>

            <label>
                <span>Property Name</span>
                <input
                    type="text"
                    name="property_name"
                    required
                    value="<?php echo propertyPortalEscape(
                        $propertyPortalUser['property_name'] ?? ''
                    ); ?>"
                >
            </label>

            <label>
                <span>Company Name</span>
                <input
                    type="text"
                    name="company_name"
                    value="<?php echo propertyPortalEscape(
                        $propertyPortalUser['company_name'] ?? ''
                    ); ?>"
                >
            </label>

            <label class="branding-span-two">
                <span>Tagline</span>
                <input
                    type="text"
                    name="tagline"
                    value="<?php echo propertyPortalEscape(
                        $propertyPortalUser['tagline'] ?? ''
                    ); ?>"
                >
            </label>

            <label>
                <span>Primary Colour</span>
                <div class="branding-colour-field">
                    <input
                        type="color"
                        name="primary_color"
                        value="<?php echo propertyPortalEscape(
                            cpmsBrandingHex(
                                $propertyPortalUser['primary_color']
                                    ?? null,
                                '#2563eb'
                            )
                        ); ?>"
                    >
                </div>
            </label>

            <label>
                <span>Secondary Colour</span>
                <div class="branding-colour-field">
                    <input
                        type="color"
                        name="secondary_color"
                        value="<?php echo propertyPortalEscape(
                            cpmsBrandingHex(
                                $propertyPortalUser['secondary_color']
                                    ?? null,
                                '#0f172a'
                            )
                        ); ?>"
                    >
                </div>
            </label>

            <label class="branding-span-two">
                <span>Address</span>
                <textarea
                    name="address"
                    rows="3"
                ><?php echo propertyPortalEscape(
                    $propertyPortalUser['address'] ?? ''
                ); ?></textarea>
            </label>

            <label>
                <span>Phone</span>
                <input
                    type="text"
                    name="phone"
                    value="<?php echo propertyPortalEscape(
                        $propertyPortalUser['property_phone']
                            ?? $propertyPortalUser['phone']
                            ?? ''
                    ); ?>"
                >
            </label>

            <label>
                <span>Email</span>
                <input
                    type="email"
                    name="email"
                    value="<?php echo propertyPortalEscape(
                        $propertyPortalUser['property_email']
                            ?? $propertyPortalUser['email']
                            ?? ''
                    ); ?>"
                >
            </label>

            <label>
                <span>Operating Hours</span>
                <input
                    type="text"
                    name="operating_hours"
                    placeholder="Monday–Friday, 8:00 AM–5:00 PM"
                    value="<?php echo propertyPortalEscape(
                        $propertyPortalUser['operating_hours'] ?? ''
                    ); ?>"
                >
            </label>

            <label>
                <span>Footer Text</span>
                <input
                    type="text"
                    name="footer_text"
                    value="<?php echo propertyPortalEscape(
                        $propertyPortalUser['footer_text'] ?? ''
                    ); ?>"
                >
            </label>
        </div>
    </section>

    <section class="panel branding-form-panel">
        <div class="panel-heading">
            <div>
                <span>Media</span>
                <h2>Brand Assets</h2>
            </div>
        </div>

        <div class="branding-upload-list">
            <?php
            $assetsList = [
                [
                    'name' => 'logo',
                    'label' => 'Property Logo',
                    'current' => $propertyPortalUser['logo_path'] ?? '',
                    'accept' => '.png,.jpg,.jpeg,.webp',
                ],
                [
                    'name' => 'favicon',
                    'label' => 'Browser Favicon',
                    'current' => $propertyPortalUser['favicon_path'] ?? '',
                    'accept' => '.png,.ico,.jpg,.jpeg,.webp',
                ],
                [
                    'name' => 'background',
                    'label' => 'Portal Background',
                    'current' => (
                        $propertyPortalUser['background_path']
                        ?? ''
                    ),
                    'accept' => '.png,.jpg,.jpeg,.webp',
                ],
                [
                    'name' => 'dashboard_banner',
                    'label' => 'Dashboard Banner',
                    'current' => (
                        $propertyPortalUser['dashboard_banner_path']
                        ?? ''
                    ),
                    'accept' => '.png,.jpg,.jpeg,.webp',
                ],
            ];
            ?>

            <?php foreach ($assetsList as $asset): ?>
                <label class="branding-upload-card">
                    <div class="branding-upload-preview">
                        <?php if ($asset['current'] !== ''): ?>
                            <img
                                data-branding-image="1"
                                src="<?php echo propertyPortalEscape(
                                    cpmsBrandingAssetUrl(
                                        $asset['current']
                                    )
                                ); ?>"
                                alt=""
                            >
                        <?php else: ?>
                            <span>No image</span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <strong>
                            <?php echo propertyPortalEscape(
                                $asset['label']
                            ); ?>
                        </strong>
                        <small>PNG, JPG, WEBP; maximum 5 MB</small>
                        <input
                            type="file"
                            name="<?php echo propertyPortalEscape(
                                $asset['name']
                            ); ?>"
                            accept="<?php echo propertyPortalEscape(
                                $asset['accept']
                            ); ?>"
                        >
                    </div>
                </label>
            <?php endforeach; ?>
        </div>

        <label class="branding-switch-row">
            <input
                type="checkbox"
                name="show_cpms_branding"
                value="1"
                <?php echo cpmsBrandingShowCpms(
                    $propertyPortalUser
                ) ? 'checked' : ''; ?>
            >
            <span>
                <strong>Show CPMS branding</strong>
                <small>
                    Disable this for a fully white-labelled client portal.
                </small>
            </span>
        </label>

        <button type="submit" class="branding-save-button">
            Save Branding Settings
        </button>
    </section>
</form>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
