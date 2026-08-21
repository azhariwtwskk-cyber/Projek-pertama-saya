<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cpmsPropertyRequire('settings.manage');

$guardTypes = [
    'Permanent' => 'Permanent',
    'Buffer' => 'Buffer',
    'Relief' => 'Relief',
    'Supervisor' => 'Supervisor',
];

$statusMap = [
    'Active' => 'Active',
    'Inactive' => 'Inactive',
    'Suspended' => 'Suspended',
];

function cpmsSecurityGuardFlash(string $type, string $message): void
{
    $_SESSION['property_security_guard_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function cpmsSecurityGuardPullFlash(): ?array
{
    $flash = $_SESSION['property_security_guard_flash'] ?? null;
    unset($_SESSION['property_security_guard_flash']);

    return is_array($flash) ? $flash : null;
}

function cpmsSecurityGuardRedirect(): void
{
    propertyPortalRedirect('security_guards.php');
}

function cpmsSecurityGuardColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "`");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $columns[(string) $row['Field']] = strtolower((string) $row['Type']);
        }
    }

    return $columns;
}

function cpmsSecurityGuardTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsSecurityGuardBindAndExecute(mysqli_stmt $stmt, string $types, array $values): bool
{
    $bind = [$types];
    foreach ($values as $index => $value) {
        $bind[] = &$values[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);

    return $stmt->execute();
}

function cpmsSecurityGuardInsert(mysqli $conn, string $table, array $data, array $columns): int
{
    $data = array_intersect_key($data, $columns);
    if (!$data) {
        throw new RuntimeException('No compatible security guard fields were found.');
    }

    $names = array_keys($data);
    $quoted = array_map(
        static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`',
        $names
    );
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $types = '';
    $values = [];

    foreach ($names as $name) {
        $type = (string) ($columns[$name] ?? '');
        $value = $data[$name];
        if (is_int($value) || preg_match('/int|bit|bool/', $type)) {
            $types .= 'i';
            $values[] = (int) $value;
        } else {
            $types .= 's';
            $values[] = (string) $value;
        }
    }

    $sql = 'INSERT INTO `' . str_replace('`', '``', $table) . '` ('
        . implode(',', $quoted)
        . ') VALUES ('
        . $placeholders
        . ')';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare security guard creation.');
    }
    if (!cpmsSecurityGuardBindAndExecute($stmt, $types, $values)) {
        $error = $stmt->errno === 1062
            ? 'Guard code or username is already in use.'
            : 'Security guard account could not be created.';
        $stmt->close();
        throw new RuntimeException($error);
    }
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function cpmsSecurityGuardSyncUnifiedAccount(
    mysqli $conn,
    int $guardId,
    int $propertyId
): int {
    $stmt = $conn->prepare(
        "SELECT id,property_id,guard_code,full_name,username,password,
                guard_type,account_status
         FROM security_guards
         WHERE id=? LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read security guard account.');
    }
    $stmt->bind_param('i', $guardId);
    $stmt->execute();
    $guard = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$guard || (int) ($guard['property_id'] ?? 0) !== $propertyId) {
        throw new RuntimeException('Security guard property scope is invalid.');
    }

    $username = trim((string) ($guard['username'] ?? ''));
    $passwordHash = (string) ($guard['password'] ?? '');
    $fullName = trim((string) ($guard['full_name'] ?? ''));
    if ($username === '' || $passwordHash === '') {
        throw new RuntimeException('Security guard identity is incomplete.');
    }
    if ($fullName === '') {
        $fullName = $username;
    }

    $statusValue = strtolower((string) ($guard['account_status'] ?? 'Active'));
    $status = $statusValue === 'active' ? 'active' : 'inactive';

    $sourceStmt = $conn->prepare(
        "SELECT id,source_table,source_id
         FROM system_users
         WHERE source_table='security_guards' AND source_id=?
         LIMIT 1"
    );
    if (!$sourceStmt) {
        throw new RuntimeException('Unified security identity query failed.');
    }
    $sourceStmt->bind_param('i', $guardId);
    $sourceStmt->execute();
    $sourceUser = $sourceStmt->get_result()->fetch_assoc();
    $sourceStmt->close();

    $nameStmt = $conn->prepare(
        "SELECT id,source_table,source_id
         FROM system_users
         WHERE username=? LIMIT 1"
    );
    if (!$nameStmt) {
        throw new RuntimeException('Unified username query failed.');
    }
    $nameStmt->bind_param('s', $username);
    $nameStmt->execute();
    $nameUser = $nameStmt->get_result()->fetch_assoc();
    $nameStmt->close();

    if (
        $sourceUser
        && $nameUser
        && (int) $sourceUser['id'] !== (int) $nameUser['id']
    ) {
        throw new RuntimeException('Unified identity conflict for this username.');
    }

    if ($nameUser) {
        $sourceTable = trim((string) ($nameUser['source_table'] ?? ''));
        $sourceId = (int) ($nameUser['source_id'] ?? 0);
        if (
            $sourceTable !== ''
            && ($sourceTable !== 'security_guards' || $sourceId !== $guardId)
        ) {
            throw new RuntimeException('Username is already linked to another account.');
        }
    }

    $unifiedId = (int) (($sourceUser ?: $nameUser)['id'] ?? 0);
    if ($unifiedId > 0) {
        $stmt = $conn->prepare(
            "UPDATE system_users
             SET property_id=?,username=?,password_hash=?,full_name=?,
                 phone=NULL,status=?,source_table='security_guards',
                 source_id=?
             WHERE id=?"
        );
        if (!$stmt) {
            throw new RuntimeException('Unified security identity update failed.');
        }
        $stmt->bind_param(
            'issssii',
            $propertyId,
            $username,
            $passwordHash,
            $fullName,
            $status,
            $guardId,
            $unifiedId
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO system_users (
                property_id,username,password_hash,full_name,status,
                source_table,source_id
             ) VALUES (?, ?, ?, ?, ?, 'security_guards', ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unified security identity creation failed.');
        }
        $stmt->bind_param(
            'issssi',
            $propertyId,
            $username,
            $passwordHash,
            $fullName,
            $status,
            $guardId
        );
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unified security identity synchronization failed.');
    }
    if ($unifiedId < 1) {
        $unifiedId = (int) $conn->insert_id;
    }
    $stmt->close();

    $roleStmt = $conn->prepare(
        "SELECT id FROM roles
         WHERE role_code='security' AND status='active'
         LIMIT 1"
    );
    if (!$roleStmt) {
        throw new RuntimeException('Unified security role query failed.');
    }
    $roleStmt->execute();
    $role = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();
    $roleId = (int) ($role['id'] ?? 0);
    if ($roleId < 1) {
        throw new RuntimeException('Unified role "security" is missing or inactive.');
    }

    $deactivate = $conn->prepare(
        "UPDATE user_roles ur
         INNER JOIN roles r ON r.id=ur.role_id
         SET ur.status='inactive'
         WHERE ur.system_user_id=?
           AND r.role_code IN ('property_admin','manager','clerk','staff','security')
           AND (
                ur.property_id IS NULL
                OR ur.property_id<>?
                OR r.role_code<>'security'
           )"
    );
    if (!$deactivate) {
        throw new RuntimeException('Unified security role cleanup failed.');
    }
    $deactivate->bind_param('ii', $unifiedId, $propertyId);
    if (!$deactivate->execute()) {
        $deactivate->close();
        throw new RuntimeException('Unified security role cleanup failed.');
    }
    $deactivate->close();

    $assignment = $conn->prepare(
        "INSERT INTO user_roles
            (system_user_id,role_id,property_id,status)
         VALUES (?,?,?,'active')
         ON DUPLICATE KEY UPDATE status='active',expires_at=NULL"
    );
    if (!$assignment) {
        throw new RuntimeException('Unified security role assignment failed.');
    }
    $assignment->bind_param('iii', $unifiedId, $roleId, $propertyId);
    if (!$assignment->execute()) {
        $assignment->close();
        throw new RuntimeException('Unified security role assignment failed.');
    }
    $assignment->close();

    return $unifiedId;
}

if (!cpmsSecurityGuardTableExists($conn, 'security_guards')) {
    cpmsSecurityGuardFlash(
        'danger',
        'security_guards table is missing. Run the security guard migration first.'
    );
}

$guardColumns = cpmsSecurityGuardColumns($conn, 'security_guards');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!propertyPortalVerifyCsrf(is_string($token) ? $token : null)) {
        cpmsSecurityGuardFlash('danger', 'Invalid security token. Please try again.');
        cpmsSecurityGuardRedirect();
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create') {
        $guardCode = strtoupper(trim((string) ($_POST['guard_code'] ?? '')));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $guardType = trim((string) ($_POST['guard_type'] ?? 'Permanent'));

        if (
            $guardCode === ''
            || $fullName === ''
            || $username === ''
            || strlen($password) < 8
            || !isset($guardTypes[$guardType])
        ) {
            cpmsSecurityGuardFlash(
                'danger',
                'Guard code, full name, username, valid type and minimum 8-character password are required.'
            );
            cpmsSecurityGuardRedirect();
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
            cpmsSecurityGuardFlash(
                'danger',
                'Username may only contain letters, numbers, dots, underscores and hyphens.'
            );
            cpmsSecurityGuardRedirect();
        }

        if (!isset($guardColumns['property_id'])) {
            cpmsSecurityGuardFlash(
                'danger',
                'security_guards.property_id is missing. Run migration before creating security accounts.'
            );
            cpmsSecurityGuardRedirect();
        }

        $conn->begin_transaction();
        try {
            $guardId = cpmsSecurityGuardInsert(
                $conn,
                'security_guards',
                [
                    'property_id' => $currentPropertyId,
                    'guard_code' => $guardCode,
                    'full_name' => $fullName,
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'guard_type' => $guardType,
                    'account_status' => 'Active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                $guardColumns
            );
            cpmsSecurityGuardSyncUnifiedAccount($conn, $guardId, $currentPropertyId);
            $conn->commit();
            cpmsSecurityGuardFlash(
                'success',
                'Security ID created and linked to Unified Login successfully.'
            );
        } catch (Throwable $exception) {
            $conn->rollback();
            cpmsSecurityGuardFlash('danger', $exception->getMessage());
        }
        cpmsSecurityGuardRedirect();
    }

    if ($action === 'status') {
        $guardId = (int) ($_POST['guard_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));
        if ($guardId < 1 || !isset($statusMap[$status])) {
            cpmsSecurityGuardFlash('danger', 'Invalid security status request.');
            cpmsSecurityGuardRedirect();
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "UPDATE security_guards
                 SET account_status=?
                 WHERE id=? AND property_id=?"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to update security status.');
            }
            $stmt->bind_param('sii', $status, $guardId, $currentPropertyId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to update security status.');
            }
            $stmt->close();
            cpmsSecurityGuardSyncUnifiedAccount($conn, $guardId, $currentPropertyId);
            $conn->commit();
            cpmsSecurityGuardFlash('success', 'Security status updated.');
        } catch (Throwable $exception) {
            $conn->rollback();
            cpmsSecurityGuardFlash('danger', $exception->getMessage());
        }
        cpmsSecurityGuardRedirect();
    }

    if ($action === 'reset_password') {
        $guardId = (int) ($_POST['guard_id'] ?? 0);
        $password = (string) ($_POST['new_password'] ?? '');
        if ($guardId < 1 || strlen($password) < 8) {
            cpmsSecurityGuardFlash(
                'danger',
                'A new password of at least 8 characters is required.'
            );
            cpmsSecurityGuardRedirect();
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "UPDATE security_guards
                 SET password=?
                 WHERE id=? AND property_id=?"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to reset security password.');
            }
            $stmt->bind_param('sii', $passwordHash, $guardId, $currentPropertyId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to reset security password.');
            }
            $stmt->close();
            cpmsSecurityGuardSyncUnifiedAccount($conn, $guardId, $currentPropertyId);
            $conn->commit();
            cpmsSecurityGuardFlash('success', 'Security password reset and synchronized.');
        } catch (Throwable $exception) {
            $conn->rollback();
            cpmsSecurityGuardFlash('danger', $exception->getMessage());
        }
        cpmsSecurityGuardRedirect();
    }
}

$guards = [];
if ($guardColumns) {
    $stmt = $conn->prepare(
        "SELECT id,guard_code,full_name,username,guard_type,account_status
         FROM security_guards
         WHERE property_id=?
         ORDER BY full_name,guard_code"
    );
    if ($stmt) {
        $stmt->bind_param('i', $currentPropertyId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $guards[] = $row;
        }
        $stmt->close();
    }
}

$flash = cpmsSecurityGuardPullFlash();
$pageTitle = 'Security Guards';
$activeMenu = 'security_guards';

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo propertyPortalEscape($flash['type']); ?>">
        <?php echo propertyPortalEscape($flash['message']); ?>
    </div>
<?php endif; ?>

<section class="page-heading">
    <div>
        <span class="section-label">SECURITY ACCESS</span>
        <h1>Security Guards</h1>
        <p>
            Create and manage security IDs for
            <strong><?php echo propertyPortalEscape($currentPropertyName); ?></strong>.
            Every account is automatically scoped to this property.
        </p>
    </div>
    <div class="record-total">
        <span>Security IDs</span>
        <strong><?php echo count($guards); ?></strong>
    </div>
</section>

<section class="administration-grid">
    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">NEW SECURITY ID</span>
                <h2>Create Security Account</h2>
            </div>
        </div>

        <form method="post" class="administration-form">
            <input type="hidden" name="csrf_token" value="<?php echo propertyPortalEscape(propertyPortalCsrfToken()); ?>">
            <input type="hidden" name="action" value="create">

            <div class="two-column-fields">
                <div class="field-group">
                    <label for="guard_code">Guard Code</label>
                    <input id="guard_code" name="guard_code" type="text" maxlength="50" placeholder="SEC-001" required>
                </div>
                <div class="field-group">
                    <label for="guard_type">Guard Type</label>
                    <select id="guard_type" name="guard_type" required>
                        <?php foreach ($guardTypes as $value => $label): ?>
                            <option value="<?php echo propertyPortalEscape($value); ?>"><?php echo propertyPortalEscape($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="two-column-fields">
                <div class="field-group">
                    <label for="full_name">Full Name</label>
                    <input id="full_name" name="full_name" type="text" maxlength="150" required>
                </div>
                <div class="field-group">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" maxlength="80" autocomplete="off" required>
                </div>
            </div>

            <div class="field-group">
                <label for="password">Temporary Password</label>
                <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                <small>Security will login through Unified Login using this username and password.</small>
            </div>

            <button class="button button-primary" type="submit">Create Security ID</button>
        </form>
    </article>

    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">LOGIN INFO</span>
                <h2>Security Access</h2>
            </div>
        </div>
        <div class="role-guide">
            <div>
                <strong>Login URL</strong>
                <p>https://cpmspro.my/cpms/login.php</p>
            </div>
            <div>
                <strong>Property Scope</strong>
                <p>Security can only access patrol, visitor, attendance and enabled security modules for this property.</p>
            </div>
        </div>
    </article>
</section>

<section class="panel user-list-panel">
    <div class="panel-heading">
        <div>
            <span class="section-label">SECURITY LIST</span>
            <h2>Current Security Guards</h2>
        </div>
    </div>

    <?php if (!$guards): ?>
        <div class="empty-state">
            <strong>No security ID found for this property.</strong>
            <p>Create the first security account using the form above.</p>
        </div>
    <?php else: ?>
        <div class="user-card-grid">
            <?php foreach ($guards as $guard): ?>
                <article class="user-card">
                    <div class="user-card-heading">
                        <div>
                            <strong><?php echo propertyPortalEscape($guard['full_name']); ?></strong>
                            <span>@<?php echo propertyPortalEscape($guard['username']); ?></span>
                        </div>
                        <span class="status-pill status-<?php echo strtolower(propertyPortalEscape($guard['account_status'])); ?>">
                            <?php echo propertyPortalEscape($guard['account_status']); ?>
                        </span>
                    </div>

                    <dl class="user-meta">
                        <div>
                            <dt>Guard Code</dt>
                            <dd><?php echo propertyPortalEscape($guard['guard_code']); ?></dd>
                        </div>
                        <div>
                            <dt>Type</dt>
                            <dd><?php echo propertyPortalEscape($guard['guard_type']); ?></dd>
                        </div>
                    </dl>

                    <div class="user-card-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo propertyPortalEscape(propertyPortalCsrfToken()); ?>">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="guard_id" value="<?php echo (int) $guard['id']; ?>">
                            <select name="status">
                                <?php foreach ($statusMap as $value => $label): ?>
                                    <option value="<?php echo propertyPortalEscape($value); ?>" <?php echo (string) $guard['account_status'] === $value ? 'selected' : ''; ?>>
                                        <?php echo propertyPortalEscape($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="button button-secondary" type="submit">Update Status</button>
                        </form>

                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo propertyPortalEscape(propertyPortalCsrfToken()); ?>">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="guard_id" value="<?php echo (int) $guard['id']; ?>">
                            <input name="new_password" type="password" minlength="8" placeholder="New password" required>
                            <button class="button button-secondary" type="submit">Reset Password</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
