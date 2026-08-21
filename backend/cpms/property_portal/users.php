<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/property_user_sync.php';

cpmsPropertyRequire('settings.manage');

$allowedRoles = [
    'property_admin' => 'Property Administrator',
    'manager' => 'Manager',
    'clerk' => 'Clerk',
];

$allowedStatuses = [
    'active' => 'Active',
    'inactive' => 'Inactive',
    'suspended' => 'Suspended',
];

$message = '';
$messageType = 'success';

function propertyUserFlash(string $type, string $message): void
{
    $_SESSION['property_user_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function propertyUserPullFlash(): ?array
{
    $flash = $_SESSION['property_user_flash'] ?? null;
    unset($_SESSION['property_user_flash']);

    return is_array($flash) ? $flash : null;
}

function propertyUserRedirect(): void
{
    propertyPortalRedirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!propertyPortalVerifyCsrf(
        is_string($token) ? $token : null
    )) {
        propertyUserFlash(
            'danger',
            'Invalid security token. Please try again.'
        );
        propertyUserRedirect();
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $positionTitle = trim(
            (string) ($_POST['position_title'] ?? '')
        );
        $role = trim((string) ($_POST['role'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $status = 'active';

        if (
            $fullName === ''
            || $username === ''
            || !isset($allowedRoles[$role])
            || strlen($password) < 8
        ) {
            propertyUserFlash(
                'danger',
                'Full name, username, valid role and a password of at least 8 characters are required.'
            );
            propertyUserRedirect();
        }

        if (
            !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)
        ) {
            propertyUserFlash(
                'danger',
                'Username may only contain letters, numbers, dots, underscores and hyphens.'
            );
            propertyUserRedirect();
        }

        if (
            $email !== ''
            && !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            propertyUserFlash(
                'danger',
                'The email address is not valid.'
            );
            propertyUserRedirect();
        }

        $check = $conn->prepare(
            "SELECT id
             FROM property_admins
             WHERE username = ?
             LIMIT 1"
        );
        $check->bind_param('s', $username);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            propertyUserFlash(
                'danger',
                'That username is already in use.'
            );
            propertyUserRedirect();
        }

        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "INSERT INTO property_admins (
                    property_id,full_name,username,email,phone,
                    position_title,password_hash,role,status
                 ) VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''),
                    NULLIF(?, ''), ?, ?, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the user account.');
            }
            $stmt->bind_param(
                'issssssss',
                $currentPropertyId,
                $fullName,
                $username,
                $email,
                $phone,
                $positionTitle,
                $passwordHash,
                $role,
                $status
            );
            if (!$stmt->execute()) {
                $duplicate = $stmt->errno === 1062;
                $stmt->close();
                throw new RuntimeException(
                    $duplicate
                        ? 'That username or email is already in use.'
                        : 'The property user account could not be created.'
                );
            }
            $legacyId = (int) $conn->insert_id;
            $stmt->close();
            cpmsPropertyUserSyncLegacyAccount(
                $conn,
                $legacyId,
                $currentPropertyId
            );
            $conn->commit();
            propertyUserFlash(
                'success',
                'The property user and Unified Login account were created successfully.'
            );
        } catch (Throwable $exception) {
            $conn->rollback();
            propertyUserFlash('danger', $exception->getMessage());
        }
        propertyUserRedirect();
    }

    if ($action === 'status') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));

        if (
            $targetId <= 0
            || !isset($allowedStatuses[$status])
        ) {
            propertyUserFlash(
                'danger',
                'Invalid account status request.'
            );
            propertyUserRedirect();
        }

        if ($targetId === (int) $propertyPortalUser['id']) {
            propertyUserFlash(
                'danger',
                'You cannot change the status of your own active session.'
            );
            propertyUserRedirect();
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "UPDATE property_admins SET status=?
                 WHERE id=? AND property_id=?"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to update account status.');
            }
            $stmt->bind_param('sii', $status, $targetId, $currentPropertyId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to update account status.');
            }
            $stmt->close();
            cpmsPropertyUserSyncLegacyAccount(
                $conn,
                $targetId,
                $currentPropertyId
            );
            $conn->commit();
            propertyUserFlash(
                'success',
                'The Property Portal and Unified Login status were updated.'
            );
        } catch (Throwable $exception) {
            $conn->rollback();
            propertyUserFlash('danger', $exception->getMessage());
        }
        propertyUserRedirect();
    }

    if ($action === 'reset_password') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $newPassword = (string) (
            $_POST['new_password'] ?? ''
        );

        if ($targetId <= 0 || strlen($newPassword) < 8) {
            propertyUserFlash(
                'danger',
                'A new password of at least 8 characters is required.'
            );
            propertyUserRedirect();
        }

        $passwordHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "UPDATE property_admins SET password_hash=?,
                    must_change_password=1,password_changed_at=NOW()
                 WHERE id=? AND property_id=?"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to reset the password.');
            }
            $stmt->bind_param(
                'sii',
                $passwordHash,
                $targetId,
                $currentPropertyId
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to reset the password.');
            }
            $stmt->close();
            cpmsPropertyUserSyncLegacyAccount(
                $conn,
                $targetId,
                $currentPropertyId
            );
            $conn->commit();
            propertyUserFlash(
                'success',
                'The password was synchronized. The user must change it after login.'
            );
        } catch (Throwable $exception) {
            $conn->rollback();
            propertyUserFlash('danger', $exception->getMessage());
        }
        propertyUserRedirect();
    }
}

$users = [];
$stmt = $conn->prepare(
    "SELECT
        id,
        full_name,
        username,
        email,
        phone,
        position_title,
        role,
        status,
        last_login_at,
        created_at
     FROM property_admins
     WHERE property_id = ?
     ORDER BY
        FIELD(role, 'property_admin', 'manager', 'clerk'),
        full_name"
);
$stmt->bind_param('i', $currentPropertyId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

$flash = propertyUserPullFlash();

$pageTitle = 'Property Users';
$activeMenu = 'users';

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?php
        echo propertyPortalEscape($flash['type']);
    ?>">
        <?php echo propertyPortalEscape($flash['message']); ?>
    </div>
<?php endif; ?>

<section class="page-heading">
    <div>
        <span class="section-label">ADMINISTRATION SUITE</span>
        <h1>Property Users</h1>
        <p>
            Create and manage administrators, managers and clerks for
            <strong><?php echo propertyPortalEscape(
                $currentPropertyName
            ); ?></strong>.
        </p>
    </div>

    <div class="record-total">
        <span>Accounts</span>
        <strong><?php echo count($users); ?></strong>
    </div>
</section>

<section class="administration-grid">
    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">NEW ACCOUNT</span>
                <h2>Add Property User</h2>
            </div>
        </div>

        <form method="post" class="administration-form">
            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo propertyPortalEscape(
                       propertyPortalCsrfToken()
                   ); ?>">
            <input type="hidden" name="action" value="create">

            <div class="two-column-fields">
                <div class="field-group">
                    <label for="full_name">Full Name</label>
                    <input id="full_name"
                           name="full_name"
                           type="text"
                           maxlength="150"
                           required>
                </div>

                <div class="field-group">
                    <label for="username">Username</label>
                    <input id="username"
                           name="username"
                           type="text"
                           maxlength="80"
                           autocomplete="off"
                           required>
                </div>
            </div>

            <div class="two-column-fields">
                <div class="field-group">
                    <label for="email">Email</label>
                    <input id="email"
                           name="email"
                           type="email"
                           maxlength="190">
                </div>

                <div class="field-group">
                    <label for="phone">Phone</label>
                    <input id="phone"
                           name="phone"
                           type="text"
                           maxlength="50">
                </div>
            </div>

            <div class="two-column-fields">
                <div class="field-group">
                    <label for="position_title">
                        Position Title
                    </label>
                    <input id="position_title"
                           name="position_title"
                           type="text"
                           maxlength="100"
                           placeholder="e.g. Site Manager">
                </div>

                <div class="field-group">
                    <label for="role">System Role</label>
                    <select id="role" name="role" required>
                        <?php foreach (
                            $allowedRoles as $role => $label
                        ): ?>
                            <option value="<?php
                                echo propertyPortalEscape($role);
                            ?>">
                                <?php echo propertyPortalEscape(
                                    $label
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field-group">
                <label for="password">
                    Temporary Password
                </label>
                <input id="password"
                       name="password"
                       type="password"
                       minlength="8"
                       autocomplete="new-password"
                       required>
                <small>
                    Minimum eight characters. Share it securely with
                    the new user.
                </small>
            </div>

            <button class="button button-primary" type="submit">
                Create Account
            </button>
        </form>
    </article>

    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">ROLE GUIDE</span>
                <h2>Access Levels</h2>
            </div>
        </div>

        <div class="role-guide">
            <div>
                <strong>Property Administrator</strong>
                <p>
                    Full operational and configuration access for this
                    property.
                </p>
            </div>
            <div>
                <strong>Manager</strong>
                <p>
                    Complaints, work orders, operations and reports.
                </p>
            </div>
            <div>
                <strong>Clerk</strong>
                <p>
                    Complaint and resident administration only.
                </p>
            </div>
        </div>
    </article>
</section>

<section class="panel user-list-panel">
    <div class="panel-heading">
        <div>
            <span class="section-label">ACCOUNTS</span>
            <h2>Current Property Users</h2>
        </div>
    </div>

    <?php if (!$users): ?>
        <div class="empty-state">
            <strong>No property users found</strong>
        </div>
    <?php else: ?>
        <div class="user-card-grid">
            <?php foreach ($users as $user): ?>
                <article class="user-card">
                    <div class="user-card-heading">
                        <div>
                            <strong><?php echo propertyPortalEscape(
                                $user['full_name']
                            ); ?></strong>
                            <span>@<?php echo propertyPortalEscape(
                                $user['username']
                            ); ?></span>
                        </div>

                        <span class="account-status status-<?php
                            echo propertyPortalEscape($user['status']);
                        ?>">
                            <?php echo propertyPortalEscape(
                                $allowedStatuses[$user['status']]
                                ?? $user['status']
                            ); ?>
                        </span>
                    </div>

                    <dl>
                        <div>
                            <dt>Role</dt>
                            <dd><?php echo propertyPortalEscape(
                                $allowedRoles[$user['role']]
                                ?? $user['role']
                            ); ?></dd>
                        </div>
                        <div>
                            <dt>Position</dt>
                            <dd><?php echo propertyPortalEscape(
                                $user['position_title'] ?: '-'
                            ); ?></dd>
                        </div>
                        <div>
                            <dt>Email</dt>
                            <dd><?php echo propertyPortalEscape(
                                $user['email'] ?: '-'
                            ); ?></dd>
                        </div>
                        <div>
                            <dt>Last Login</dt>
                            <dd><?php echo propertyPortalEscape(
                                $user['last_login_at'] ?: 'Never'
                            ); ?></dd>
                        </div>
                    </dl>

                    <?php if (
                        (int) $user['id']
                        !== (int) $propertyPortalUser['id']
                    ): ?>
                        <div class="user-actions">
                            <?php if ($user['role'] === 'clerk'): ?>
                                <a class="button button-primary"
                                   href="user_module_access.php?user_id=<?php
                                        echo (int) $user['id'];
                                   ?>">
                                    Assign Modules
                                </a>
                            <?php endif; ?>

                            <form method="post">
                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?php echo propertyPortalEscape(
                                           propertyPortalCsrfToken()
                                       ); ?>">
                                <input type="hidden"
                                       name="action"
                                       value="status">
                                <input type="hidden"
                                       name="user_id"
                                       value="<?php echo (int) $user['id']; ?>">

                                <select name="status">
                                    <?php foreach (
                                        $allowedStatuses
                                        as $status => $label
                                    ): ?>
                                        <option
                                            value="<?php echo propertyPortalEscape(
                                                $status
                                            ); ?>"
                                            <?php echo $user['status'] === $status
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?php echo propertyPortalEscape(
                                                $label
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <button class="button button-secondary"
                                        type="submit">
                                    Update Status
                                </button>
                            </form>

                            <form method="post">
                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?php echo propertyPortalEscape(
                                           propertyPortalCsrfToken()
                                       ); ?>">
                                <input type="hidden"
                                       name="action"
                                       value="reset_password">
                                <input type="hidden"
                                       name="user_id"
                                       value="<?php echo (int) $user['id']; ?>">

                                <input name="new_password"
                                       type="password"
                                       minlength="8"
                                       placeholder="New password"
                                       required>

                                <button class="button button-secondary"
                                        type="submit">
                                    Reset Password
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="current-account-note">
                            This is your current account.
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
