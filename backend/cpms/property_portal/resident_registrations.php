<?php
declare(strict_types=1);

/*
 * CPMS v3.6.0.9 — Property-scoped Resident Registration Approval
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/auth.php';
cpmsRequire('resident.registration.manage', $conn);

function cpmsResidentApplicationEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsResidentApplicationFlash(string $type, string $message): void
{
    $_SESSION['resident_registration_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function cpmsResidentApplicationPullFlash(): ?array
{
    $flash = $_SESSION['resident_registration_flash'] ?? null;
    unset($_SESSION['resident_registration_flash']);
    return is_array($flash) ? $flash : null;
}

function cpmsResidentApplicationRedirect(string $status = 'Pending'): void
{
    $allowed = ['Pending', 'Approved', 'Rejected', 'All'];
    if (!in_array($status, $allowed, true)) {
        $status = 'Pending';
    }
    header('Location: resident_registrations.php?status=' . rawurlencode($status));
    exit;
}

function cpmsResidentApplicationExecute(
    mysqli_stmt $stmt,
    string $message
): void {
    if (!$stmt->execute()) {
        throw new RuntimeException($message);
    }
}

function cpmsResidentApplicationCode(
    mysqli $conn,
    string $propertyCode
): string {
    $prefix = strtoupper(
        (string) preg_replace('/[^A-Za-z0-9]/', '', $propertyCode)
    );
    $prefix = substr($prefix !== '' ? $prefix : 'CPMS', 0, 8);

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = $prefix . '-R' . date('ymd')
            . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $conn->prepare(
            "SELECT resident_code AS code
             FROM cpms_residents
             WHERE resident_code=?
             UNION ALL
             SELECT username AS code
             FROM system_users
             WHERE username=?
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Resident code check failed.');
        }
        $stmt->bind_param('ss', $code, $code);
        cpmsResidentApplicationExecute(
            $stmt,
            'Resident code check failed.'
        );
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $code;
        }
    }

    throw new RuntimeException('Resident code could not be generated.');
}

function cpmsResidentApplicationUsername(string $value): string
{
    return strtolower(trim($value));
}

function cpmsResidentApplicationUsernameValid(string $username): bool
{
    return preg_match(
        '/^[a-z0-9][a-z0-9._]{3,28}[a-z0-9]$/',
        $username
    ) === 1;
}

function cpmsResidentApplicationUsernameReserved(string $username): bool
{
    return in_array($username, [
        'admin',
        'administrator',
        'clerk',
        'cpms',
        'manager',
        'owner',
        'resident',
        'root',
        'security',
        'support',
        'system',
    ], true);
}

function cpmsResidentApplicationLoadForReview(
    mysqli $conn,
    int $applicationId,
    int $propertyId
): array {
    $stmt = $conn->prepare(
        "SELECT *
         FROM cpms_resident_registrations
         WHERE id=? AND property_id=?
         LIMIT 1 FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Application query failed.');
    }
    $stmt->bind_param('ii', $applicationId, $propertyId);
    cpmsResidentApplicationExecute($stmt, 'Application query failed.');
    $application = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$application) {
        throw new RuntimeException('Permohonan tidak dijumpai.');
    }
    if ((string) ($application['status'] ?? '') !== 'Pending') {
        throw new RuntimeException('Permohonan ini sudah diproses.');
    }

    return $application;
}

function cpmsResidentApplicationApprove(
    mysqli $conn,
    int $applicationId,
    int $propertyId,
    string $propertyCode,
    int $actorUserId,
    string $reviewNotes
): array {
    $conn->begin_transaction();

    try {
        $application = cpmsResidentApplicationLoadForReview(
            $conn,
            $applicationId,
            $propertyId
        );
        $passwordHash = (string) ($application['password_hash'] ?? '');
        if ($passwordHash === '') {
            throw new RuntimeException(
                'Kata laluan permohonan tidak tersedia. Minta resident memohon semula.'
            );
        }

        $phone = (string) $application['phone'];
        $block = (string) $application['block_name'];
        $unit = (string) $application['unit_no'];
        $email = trim((string) ($application['email'] ?? ''));

        $stmt = $conn->prepare(
            "SELECT id
             FROM cpms_residents
             WHERE property_id=?
               AND (
                    phone=?
                    OR (LOWER(block_name)=LOWER(?)
                        AND LOWER(unit_no)=LOWER(?))
               )
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Resident duplicate check failed.');
        }
        $stmt->bind_param('isss', $propertyId, $phone, $block, $unit);
        cpmsResidentApplicationExecute(
            $stmt,
            'Resident duplicate check failed.'
        );
        $duplicateResident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicateResident) {
            throw new RuntimeException(
                'Nombor telefon atau unit ini sudah mempunyai akaun resident.'
            );
        }

        if ($email !== '') {
            $stmt = $conn->prepare(
                'SELECT id FROM system_users WHERE LOWER(email)=LOWER(?) LIMIT 1'
            );
            if (!$stmt) {
                throw new RuntimeException('Email duplicate check failed.');
            }
            $stmt->bind_param('s', $email);
            cpmsResidentApplicationExecute(
                $stmt,
                'Email duplicate check failed.'
            );
            $duplicateEmail = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($duplicateEmail) {
                throw new RuntimeException(
                    'E-mel ini sudah digunakan oleh akaun CPMS lain.'
                );
            }
        }

        $residentCode = cpmsResidentApplicationCode(
            $conn,
            $propertyCode
        );
        $requestedUsername = cpmsResidentApplicationUsername(
            (string) ($application['requested_username'] ?? '')
        );
        $username = $requestedUsername !== ''
            ? $requestedUsername
            : $residentCode;

        if (
            $requestedUsername !== ''
            && (
                !cpmsResidentApplicationUsernameValid($requestedUsername)
                || cpmsResidentApplicationUsernameReserved($requestedUsername)
            )
        ) {
            throw new RuntimeException(
                'Username pilihan resident tidak sah. Minta resident memohon semula.'
            );
        }

        $stmt = $conn->prepare(
            'SELECT id
             FROM system_users
             WHERE LOWER(username)=LOWER(?)
             LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) {
            throw new RuntimeException('Username duplicate check failed.');
        }
        $stmt->bind_param('s', $username);
        cpmsResidentApplicationExecute(
            $stmt,
            'Username duplicate check failed.'
        );
        $duplicateUsername = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($duplicateUsername) {
            throw new RuntimeException(
                'Username pilihan sudah digunakan. Tolak permohonan dan minta resident memilih username lain.'
            );
        }

        $fullName = (string) $application['full_name'];
        $residentType = (string) $application['resident_type'];

        $stmt = $conn->prepare(
            "INSERT INTO cpms_residents (
                property_id,resident_code,full_name,email,phone,
                block_name,unit_no,resident_type,password_hash,is_active
             ) VALUES (?,?,?,NULLIF(?,''),?,?,?,?,?,1)"
        );
        if (!$stmt) {
            throw new RuntimeException('Resident account insert failed.');
        }
        $stmt->bind_param(
            'issssssss',
            $propertyId,
            $residentCode,
            $fullName,
            $email,
            $phone,
            $block,
            $unit,
            $residentType,
            $passwordHash
        );
        cpmsResidentApplicationExecute(
            $stmt,
            'Resident account insert failed.'
        );
        $residentId = (int) $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO system_users (
                property_id,username,email,password_hash,full_name,phone,
                status,must_change_password,source_table,source_id,
                created_by_user_id
             ) VALUES (
                ?,?,NULLIF(?,''),?,?,?,'active',0,
                'cpms_residents',?,NULLIF(?,0)
             )"
        );
        if (!$stmt) {
            throw new RuntimeException('Unified resident account insert failed.');
        }
        $stmt->bind_param(
            'isssssii',
            $propertyId,
            $username,
            $email,
            $passwordHash,
            $fullName,
            $phone,
            $residentId,
            $actorUserId
        );
        cpmsResidentApplicationExecute(
            $stmt,
            'Unified resident account insert failed.'
        );
        $systemUserId = (int) $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT id FROM roles
             WHERE role_code='resident' AND status='active'
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Resident role query failed.');
        }
        cpmsResidentApplicationExecute($stmt, 'Resident role query failed.');
        $role = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $residentRoleId = (int) ($role['id'] ?? 0);
        if ($residentRoleId < 1) {
            throw new RuntimeException('Resident role is not active.');
        }

        $stmt = $conn->prepare(
            "INSERT INTO user_roles (
                system_user_id,role_id,property_id,
                assigned_by_user_id,status
             ) VALUES (?,?,?,NULLIF(?,0),'active')
             ON DUPLICATE KEY UPDATE
                status='active',assigned_by_user_id=VALUES(assigned_by_user_id)"
        );
        if (!$stmt) {
            throw new RuntimeException('Resident role assignment failed.');
        }
        $stmt->bind_param(
            'iiii',
            $systemUserId,
            $residentRoleId,
            $propertyId,
            $actorUserId
        );
        cpmsResidentApplicationExecute(
            $stmt,
            'Resident role assignment failed.'
        );
        $stmt->close();

        $stmt = $conn->prepare(
            "UPDATE cpms_resident_registrations
             SET status='Approved',review_notes=NULLIF(?,''),
                 reviewed_by_system_user_id=NULLIF(?,0),reviewed_at=NOW(),
                 approved_resident_id=?,assigned_resident_code=?,
                 assigned_username=?,
                 password_hash=''
             WHERE id=? AND property_id=? AND status='Pending'"
        );
        if (!$stmt) {
            throw new RuntimeException('Application update failed.');
        }
        $stmt->bind_param(
            'siissii',
            $reviewNotes,
            $actorUserId,
            $residentId,
            $residentCode,
            $username,
            $applicationId,
            $propertyId
        );
        cpmsResidentApplicationExecute($stmt, 'Application update failed.');
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Application approval was not saved.');
        }
        $stmt->close();

        $conn->commit();
        return [
            'resident_code' => $residentCode,
            'username' => $username,
        ];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function cpmsResidentApplicationReject(
    mysqli $conn,
    int $applicationId,
    int $propertyId,
    int $actorUserId,
    string $reviewNotes
): void {
    if (strlen($reviewNotes) < 3) {
        throw new RuntimeException('Masukkan sebab permohonan ditolak.');
    }

    $stmt = $conn->prepare(
        "UPDATE cpms_resident_registrations
         SET status='Rejected',review_notes=?,
             reviewed_by_system_user_id=NULLIF(?,0),reviewed_at=NOW(),
             password_hash=''
         WHERE id=? AND property_id=? AND status='Pending'"
    );
    if (!$stmt) {
        throw new RuntimeException('Application rejection failed.');
    }
    $stmt->bind_param(
        'siii',
        $reviewNotes,
        $actorUserId,
        $applicationId,
        $propertyId
    );
    cpmsResidentApplicationExecute($stmt, 'Application rejection failed.');
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Permohonan tidak dijumpai atau sudah diproses.');
    }
    $stmt->close();
}

$allowedStatuses = ['Pending', 'Approved', 'Rejected', 'All'];
$selectedStatus = trim((string) ($_GET['status'] ?? 'Pending'));
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = 'Pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = trim((string) ($_POST['action'] ?? ''));
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));
    $returnStatus = trim((string) ($_POST['return_status'] ?? 'Pending'));

    if (!propertyPortalVerifyCsrf($token)) {
        cpmsResidentApplicationFlash(
            'danger',
            'Token keselamatan tidak sah. Sila cuba semula.'
        );
        cpmsResidentApplicationRedirect($returnStatus);
    }

    try {
        if ($applicationId < 1) {
            throw new RuntimeException('Permohonan tidak sah.');
        }

        $actorUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);
        if ($action === 'approve') {
            $approval = cpmsResidentApplicationApprove(
                $conn,
                $applicationId,
                $currentPropertyId,
                $currentPropertyCode,
                $actorUserId,
                $reviewNotes
            );
            cpmsResidentApplicationFlash(
                'success',
                'Permohonan diluluskan. Username login: '
                . (string) $approval['username']
                . ' · Kod Resident: '
                . (string) $approval['resident_code']
            );
        } elseif ($action === 'reject') {
            cpmsResidentApplicationReject(
                $conn,
                $applicationId,
                $currentPropertyId,
                $actorUserId,
                $reviewNotes
            );
            cpmsResidentApplicationFlash(
                'success',
                'Permohonan telah ditolak.'
            );
        } else {
            throw new RuntimeException('Tindakan tidak sah.');
        }
    } catch (Throwable $error) {
        error_log(
            'CPMS resident application review: ' . $error->getMessage()
        );
        cpmsResidentApplicationFlash('danger', $error->getMessage());
    }

    cpmsResidentApplicationRedirect($returnStatus);
}

$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
$stmt = $conn->prepare(
    "SELECT status,COUNT(*) AS total
     FROM cpms_resident_registrations
     WHERE property_id=?
     GROUP BY status"
);
if ($stmt) {
    $stmt->bind_param('i', $currentPropertyId);
    if ($stmt->execute()) {
        $countResult = $stmt->get_result();
        while ($row = $countResult->fetch_assoc()) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['total'] ?? 0);
            }
        }
    }
    $stmt->close();
}

$applications = [];
if ($selectedStatus === 'All') {
    $stmt = $conn->prepare(
        "SELECT r.*,u.full_name AS reviewer_name
         FROM cpms_resident_registrations r
         LEFT JOIN system_users u ON u.id=r.reviewed_by_system_user_id
         WHERE r.property_id=?
         ORDER BY
            CASE r.status WHEN 'Pending' THEN 0 ELSE 1 END,
            r.submitted_at DESC
         LIMIT 300"
    );
    if ($stmt) {
        $stmt->bind_param('i', $currentPropertyId);
    }
} else {
    $stmt = $conn->prepare(
        "SELECT r.*,u.full_name AS reviewer_name
         FROM cpms_resident_registrations r
         LEFT JOIN system_users u ON u.id=r.reviewed_by_system_user_id
         WHERE r.property_id=? AND r.status=?
         ORDER BY r.submitted_at DESC
         LIMIT 300"
    );
    if ($stmt) {
        $stmt->bind_param('is', $currentPropertyId, $selectedStatus);
    }
}

if ($stmt && $stmt->execute()) {
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
if ($stmt) {
    $stmt->close();
}

$flash = cpmsResidentApplicationPullFlash();
$pageTitle = 'Resident Registrations';
$activeMenu = 'resident_registrations';
$pageStyles = ['assets/resident-registrations.css?v=3570'];

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<section class="registration-admin-heading">
    <div>
        <span class="section-label">RESIDENT ACCOUNT CONTROL</span>
        <h1>Resident Registrations</h1>
        <p>Semak dan luluskan permohonan untuk <?php echo cpmsResidentApplicationEscape($currentPropertyName); ?> sahaja.</p>
    </div>
    <a class="registration-public-link"
       href="../../resident_register.php?property=<?php echo rawurlencode($currentPropertyCode); ?>"
       target="_blank" rel="noopener">Buka Borang Awam ↗</a>
</section>

<?php if ($flash): ?>
    <div class="registration-admin-alert registration-admin-alert--<?php echo cpmsResidentApplicationEscape((string) ($flash['type'] ?? 'success')); ?>" role="alert">
        <?php echo cpmsResidentApplicationEscape((string) ($flash['message'] ?? '')); ?>
    </div>
<?php endif; ?>

<section class="registration-stat-grid">
    <?php foreach ($counts as $status => $total): ?>
        <a href="resident_registrations.php?status=<?php echo rawurlencode($status); ?>"
           class="registration-stat-card <?php echo $selectedStatus === $status ? 'is-active' : ''; ?>">
            <span><?php echo cpmsResidentApplicationEscape($status); ?></span>
            <strong><?php echo (int) $total; ?></strong>
        </a>
    <?php endforeach; ?>
    <a href="resident_registrations.php?status=All"
       class="registration-stat-card <?php echo $selectedStatus === 'All' ? 'is-active' : ''; ?>">
        <span>All</span>
        <strong><?php echo array_sum($counts); ?></strong>
    </a>
</section>

<section class="registration-admin-panel">
    <div class="registration-admin-panel-head">
        <div>
            <span class="section-label"><?php echo cpmsResidentApplicationEscape(strtoupper($selectedStatus)); ?></span>
            <h2>Applications</h2>
        </div>
        <span><?php echo count($applications); ?> rekod</span>
    </div>

    <?php if (!$applications): ?>
        <div class="registration-admin-empty">
            <span>✓</span>
            <strong>Tiada permohonan <?php echo cpmsResidentApplicationEscape(strtolower($selectedStatus)); ?></strong>
        </div>
    <?php else: ?>
        <div class="registration-application-list">
            <?php foreach ($applications as $application): ?>
                <?php $applicationStatus = (string) $application['status']; ?>
                <article class="registration-application-card status-<?php echo cpmsResidentApplicationEscape(strtolower($applicationStatus)); ?>">
                    <div class="registration-application-top">
                        <div>
                            <span class="registration-reference"><?php echo cpmsResidentApplicationEscape($application['application_reference']); ?></span>
                            <h3><?php echo cpmsResidentApplicationEscape($application['full_name']); ?></h3>
                        </div>
                        <span class="registration-status"><?php echo cpmsResidentApplicationEscape($applicationStatus); ?></span>
                    </div>

                    <dl class="registration-detail-grid">
                        <div><dt>Unit</dt><dd><?php echo cpmsResidentApplicationEscape($application['block_name'] . ' / ' . $application['unit_no']); ?></dd></div>
                        <div><dt>Jenis</dt><dd><?php echo cpmsResidentApplicationEscape($application['resident_type']); ?></dd></div>
                        <div><dt>Telefon</dt><dd><?php echo cpmsResidentApplicationEscape($application['phone']); ?></dd></div>
                        <div><dt>E-mel</dt><dd><?php echo cpmsResidentApplicationEscape($application['email'] ?: '-'); ?></dd></div>
                        <div><dt>Username Dipilih</dt><dd><?php echo cpmsResidentApplicationEscape(($application['requested_username'] ?? '') ?: '-'); ?></dd></div>
                        <div><dt>Dihantar</dt><dd><?php echo cpmsResidentApplicationEscape(date('d/m/Y g:i A', strtotime((string) $application['submitted_at']))); ?></dd></div>
                        <div><dt>Kod Resident</dt><dd><?php echo cpmsResidentApplicationEscape($application['assigned_resident_code'] ?: '-'); ?></dd></div>
                        <div><dt>Username Login</dt><dd><?php echo cpmsResidentApplicationEscape(($application['assigned_username'] ?? '') ?: '-'); ?></dd></div>
                    </dl>

                    <?php if ($applicationStatus === 'Pending'): ?>
                        <div class="registration-review-grid">
                            <form method="post" class="registration-review-form registration-review-form--approve">
                                <input type="hidden" name="csrf_token" value="<?php echo cpmsResidentApplicationEscape(propertyPortalCsrfToken()); ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>">
                                <input type="hidden" name="return_status" value="<?php echo cpmsResidentApplicationEscape($selectedStatus); ?>">
                                <label>Catatan kelulusan (pilihan)<textarea name="review_notes" maxlength="500" placeholder="Contoh: Unit telah disahkan"></textarea></label>
                                <button type="submit">✓ Lulus & Aktifkan Akaun</button>
                            </form>

                            <form method="post" class="registration-review-form registration-review-form--reject">
                                <input type="hidden" name="csrf_token" value="<?php echo cpmsResidentApplicationEscape(propertyPortalCsrfToken()); ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>">
                                <input type="hidden" name="return_status" value="<?php echo cpmsResidentApplicationEscape($selectedStatus); ?>">
                                <label>Sebab ditolak<textarea name="review_notes" maxlength="500" placeholder="Nyatakan sebab" required></textarea></label>
                                <button type="submit">Tolak Permohonan</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="registration-review-history">
                            <strong>Disemak oleh <?php echo cpmsResidentApplicationEscape($application['reviewer_name'] ?: 'System User'); ?></strong>
                            <span><?php echo cpmsResidentApplicationEscape($application['reviewed_at'] ?: '-'); ?></span>
                            <?php if (!empty($application['review_notes'])): ?><p><?php echo nl2br(cpmsResidentApplicationEscape($application['review_notes'])); ?></p><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
