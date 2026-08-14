<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/user_permission_service.php';

cpmsRequire('clerk.modules.assign', $conn);

function clerkAccessRedirect(int $legacyUserId): void
{
    header(
        'Location: user_module_access.php?user_id='
        . max(0, $legacyUserId)
    );
    exit;
}

function clerkAccessFlash(string $type, string $message): void
{
    $_SESSION['clerk_access_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

$clerks = cpmsDelegatedPropertyClerks($conn, $currentPropertyId);
$requestedUserId = (int) (
    $_GET['user_id']
    ?? $_POST['target_user_id']
    ?? 0
);

if ($requestedUserId <= 0 && $clerks) {
    $requestedUserId = (int) $clerks[0]['id'];
}

$target = $requestedUserId > 0
    ? cpmsDelegatedClerk(
        $conn,
        $currentPropertyId,
        $requestedUserId
    )
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!propertyPortalVerifyCsrf($token)) {
        clerkAccessFlash(
            'danger',
            'Token keselamatan tidak sah. Sila cuba semula.'
        );
        clerkAccessRedirect($requestedUserId);
    }

    if (!$target) {
        clerkAccessFlash(
            'danger',
            'Akaun Kerani tidak dijumpai dalam property ini.'
        );
        clerkAccessRedirect(0);
    }

    $targetSystemUserId = (int) ($target['system_user_id'] ?? 0);
    $modules = $_POST['modules'] ?? [];
    if (!is_array($modules)) {
        $modules = [];
    }

    try {
        cpmsSaveDelegatedModules(
            $conn,
            $currentPropertyId,
            $targetSystemUserId,
            (int) ($_SESSION['cpms_user_id'] ?? 0),
            $modules
        );
        clerkAccessFlash(
            'success',
            'Akses modul untuk ' . (string) $target['full_name']
            . ' telah dikemas kini.'
        );
    } catch (Throwable $exception) {
        clerkAccessFlash('danger', $exception->getMessage());
    }

    clerkAccessRedirect($requestedUserId);
}

$grantedCodes = [];
if ($target && (int) ($target['system_user_id'] ?? 0) > 0) {
    $grantedCodes = cpmsDelegatedGrantedCodes(
        $conn,
        $currentPropertyId,
        (int) $target['system_user_id']
    );
}
$selectedModules = cpmsDelegatedSelectedModules($grantedCodes);
$moduleCatalog = cpmsDelegatedModuleCatalog();

$flash = $_SESSION['clerk_access_flash'] ?? null;
unset($_SESSION['clerk_access_flash']);

$pageTitle = 'Clerk Module Access';
$activeMenu = 'clerk_access';
$pageStyles = ['assets/delegated-access.css?v=3604'];

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<?php if (is_array($flash)): ?>
    <div class="alert alert-<?php echo propertyPortalEscape(
        (string) ($flash['type'] ?? 'success')
    ); ?>">
        <?php echo propertyPortalEscape(
            (string) ($flash['message'] ?? '')
        ); ?>
    </div>
<?php endif; ?>

<section class="page-heading">
    <div>
        <span class="section-label">DELEGATED ACCESS</span>
        <h1>Clerk Module Access</h1>
        <p>
            Beri tugas tambahan kepada Kerani tertentu untuk
            <strong><?php echo propertyPortalEscape(
                $currentPropertyName
            ); ?></strong> sahaja.
        </p>
    </div>
</section>

<section class="panel delegated-selector">
    <div class="panel-heading">
        <div>
            <span class="section-label">SELECT CLERK</span>
            <h2>Pilih Akaun Kerani</h2>
        </div>
    </div>

    <?php if (!$clerks): ?>
        <div class="delegated-empty">
            Tiada akaun Kerani untuk property ini. Property Admin perlu
            mencipta akaun Kerani terlebih dahulu.
        </div>
    <?php else: ?>
        <form method="get" class="delegated-select-form">
            <label for="user_id">Kerani</label>
            <select id="user_id" name="user_id">
                <?php foreach ($clerks as $clerk): ?>
                    <option value="<?php echo (int) $clerk['id']; ?>"
                        <?php echo (int) $clerk['id'] === $requestedUserId
                            ? 'selected'
                            : ''; ?>>
                        <?php echo propertyPortalEscape(
                            (string) $clerk['full_name']
                            . ' (@' . (string) $clerk['username'] . ')'
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="button button-secondary" type="submit">
                Buka Akses
            </button>
        </form>
    <?php endif; ?>
</section>

<?php if ($target): ?>
    <section class="panel">
        <div class="delegated-user-summary">
            <div>
                <span class="section-label">CURRENT SELECTION</span>
                <h2><?php echo propertyPortalEscape(
                    (string) $target['full_name']
                ); ?></h2>
                <p>
                    @<?php echo propertyPortalEscape(
                        (string) $target['username']
                    ); ?> · Status:
                    <?php echo propertyPortalEscape(
                        (string) $target['status']
                    ); ?>
                </p>
            </div>
            <span class="delegated-property-badge">
                Property <?php echo (int) $currentPropertyId; ?>
            </span>
        </div>

        <?php if ((int) ($target['system_user_id'] ?? 0) <= 0): ?>
            <div class="alert alert-danger">
                Unified Login Kerani ini belum diselaraskan. Jalankan
                Migration 0052 terlebih dahulu sebelum memberi modul.
            </div>
        <?php else: ?>
            <form method="post" class="delegated-module-form">
                <input type="hidden"
                       name="csrf_token"
                       value="<?php echo propertyPortalEscape(
                           propertyPortalCsrfToken()
                       ); ?>">
                <input type="hidden"
                       name="target_user_id"
                       value="<?php echo (int) $target['id']; ?>">

                <div class="delegated-module-grid">
                    <?php foreach ($moduleCatalog as $key => $module): ?>
                        <label class="delegated-module-card">
                            <input type="checkbox"
                                   name="modules[]"
                                   value="<?php echo propertyPortalEscape(
                                       (string) $key
                                   ); ?>"
                                   <?php echo !empty(
                                       $selectedModules[(string) $key]
                                   ) ? 'checked' : ''; ?>>
                            <span>
                                <strong><?php echo propertyPortalEscape(
                                    (string) $module['label']
                                ); ?></strong>
                                <small><?php echo propertyPortalEscape(
                                    (string) $module['description']
                                ); ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="delegated-safety-note">
                    Modul di atas tidak memberi akses kepada Branding,
                    Property Users, Permission Settings atau property lain.
                </div>

                <button class="button button-primary" type="submit">
                    Simpan Akses Kerani
                </button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
