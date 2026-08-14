<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/cpms_bootstrap.php';
require_once __DIR__ . '/../includes/permission_engine.php';
require_once __DIR__ . '/../includes/password_recovery_service.php';
require_once __DIR__ . '/../includes/unified_auth_audit.php';

$ownerId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$isOwner = (string) ($_SESSION['cpms_user_role'] ?? '') === 'system_owner'
    || isset($_SESSION['system_owner_id']);
if (!$isOwner || $ownerId < 1) {
    http_response_code(403);
    exit('System Owner access required.');
}
cpmsRequire('password_recovery.manual_reset', $conn);

$search = trim((string) ($_GET['q'] ?? ''));
$error = '';
$success = '';
$issuedPassword = '';
$temporaryPassword = 'Cpms9-' . substr(bin2hex(random_bytes(8)), 0, 10);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    $targetId = (int) ($_POST['system_user_id'] ?? 0);
    $temporaryPassword = (string) ($_POST['temporary_password'] ?? '');
    if (!cpmsPasswordRecoveryVerifyCsrf(
        'cpms_manual_reset_csrf',
        is_string($csrf) ? $csrf : null
    )) {
        $error = 'Sesi keselamatan tidak sah.';
    } elseif ($targetId < 1 || $targetId === $ownerId) {
        $error = 'Akaun sasaran tidak sah. Akaun sendiri tidak boleh direset di sini.';
    } else {
        $passwordErrors = cpmsPasswordRecoveryValidatePassword(
            $temporaryPassword
        );
        if ($passwordErrors) {
            $error = implode(' ', $passwordErrors);
        } else {
            $lookup = $conn->prepare(
                'SELECT username, email, full_name, status
                 FROM system_users WHERE id = ? LIMIT 1'
            );
            $lookup->bind_param('i', $targetId);
            $lookup->execute();
            $target = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if (!is_array($target) || (string) $target['status'] !== 'active') {
                $error = 'Pengguna tidak dijumpai atau tidak aktif.';
            } else {
                $hash = password_hash(
                    $temporaryPassword,
                    PASSWORD_DEFAULT
                );
                $conn->begin_transaction();
                try {
                    $update = $conn->prepare(
                        'UPDATE system_users SET password_hash = ?,
                         must_change_password = 1, updated_at = NOW()
                         WHERE id = ?'
                    );
                    $update->bind_param('si', $hash, $targetId);
                    $update->execute();
                    $update->close();
                    cpmsPropertyUserMirrorPasswordFromSystem(
                        $conn,
                        $targetId
                    );

                    $type = 'system_user';
                    $revoke = $conn->prepare(
                        'UPDATE cpms_auth_sessions SET revoked_at = NOW()
                         WHERE user_type = ? AND user_id = ?
                           AND revoked_at IS NULL'
                    );
                    $revoke->bind_param('si', $type, $targetId);
                    $revoke->execute();
                    $revoke->close();

                    $tokens = $conn->prepare(
                        'UPDATE cpms_password_reset_tokens SET used_at = NOW()
                         WHERE system_user_id = ? AND used_at IS NULL'
                    );
                    $tokens->bind_param('i', $targetId);
                    $tokens->execute();
                    $tokens->close();

                    foreach ([
                        (string) $target['username'],
                        (string) ($target['email'] ?? ''),
                    ] as $loginName) {
                        if ($loginName === '') {
                            continue;
                        }
                        $loginHash = hash(
                            'sha256',
                            strtolower(trim($loginName))
                        );
                        $clear = $conn->prepare(
                            'DELETE FROM cpms_auth_login_attempts
                             WHERE login_hash = ?'
                        );
                        $clear->bind_param('s', $loginHash);
                        $clear->execute();
                        $clear->close();
                    }
                    $conn->commit();

                    cpmsUnifiedAuditEvent(
                        $conn,
                        'manual_password_reset',
                        $ownerId,
                        null,
                        'system_owner',
                        'System Owner issued a temporary password.',
                        [
                            'target_user_id' => $targetId,
                            'target_username' => (string) $target['username'],
                        ]
                    );
                    $success = 'Kata laluan sementara untuk '
                        . (string) $target['username']
                        . ' telah disimpan. Salin kata laluan di bawah '
                        . 'dan berikan terus kepada pengguna.';
                    $issuedPassword = $temporaryPassword;
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = 'Reset tidak dapat diselesaikan.';
                }
            }
        }
    }
}

$users = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare(
        "SELECT u.id, u.username, u.full_name, u.email, u.property_id,
                GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code
                    SEPARATOR ', ') AS roles
         FROM system_users u
         LEFT JOIN user_roles ur
            ON ur.system_user_id = u.id AND ur.status = 'active'
         LEFT JOIN roles r ON r.id = ur.role_id
         WHERE u.status = 'active' AND u.id <> ?
           AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)
         GROUP BY u.id, u.username, u.full_name, u.email, u.property_id
         ORDER BY u.full_name LIMIT 30"
    );
    $stmt->bind_param('isss', $ownerId, $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
}
$csrfToken = cpmsPasswordRecoveryCsrf('cpms_manual_reset_csrf');
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manual Password Reset | CPMS</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;
font:15px Arial;padding:24px}main{max-width:1050px;margin:auto}.card{background:#fff;
padding:24px;border-radius:16px;margin-bottom:18px}input,select{width:100%;padding:12px;
border:1px solid #cbd5e1;border-radius:9px;font-size:15px}label{display:block;
font-weight:700;margin:14px 0 7px}button{padding:12px 18px;border:0;border-radius:9px;
background:#174789;color:#fff;font-weight:800;margin-top:14px}.ok,.bad{padding:13px;
border-radius:9px}.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;
color:#991b1b}table{width:100%;border-collapse:collapse}th,td{padding:11px;
border-bottom:1px solid #e2e8f0;text-align:left}.reset-form{display:grid;
grid-template-columns:1fr 1.2fr auto;gap:10px;align-items:end}
.reset-form button{margin:0}@media(max-width:700px){.reset-form{grid-template-columns:1fr}
table{display:block;overflow-x:auto}}</style></head><body><main>
<section class="card"><h1>Reset Kata Laluan Pengguna</h1>
<p>Gunakan apabila e-mel reset tidak dapat dihantar. Cari pengguna dan berikan
kata laluan sementara secara peribadi.</p>
<?php if ($success): ?><p class="ok"><?= cpmsPasswordRecoveryEscape($success) ?></p><?php endif; ?>
<?php if ($issuedPassword !== ''): ?>
<p class="ok">Kata laluan sementara:
<strong style="font-size:20px"><?= cpmsPasswordRecoveryEscape($issuedPassword) ?></strong>
— ia hanya dipaparkan pada halaman ini.</p>
<?php endif; ?>
<?php if ($error): ?><p class="bad"><?= cpmsPasswordRecoveryEscape($error) ?></p><?php endif; ?>
<form method="get"><label>Cari username, nama atau e-mel</label>
<input name="q" value="<?= cpmsPasswordRecoveryEscape($search) ?>" required>
<button type="submit">Cari Pengguna</button></form></section>
<?php if ($users): ?><section class="card"><table><tr><th>Pengguna</th>
<th>Role / Property</th><th>Reset</th></tr>
<?php foreach ($users as $user): ?><tr><td><strong>
<?= cpmsPasswordRecoveryEscape((string) $user['full_name']) ?></strong><br>
<?= cpmsPasswordRecoveryEscape((string) $user['username']) ?><br>
<?= cpmsPasswordRecoveryEscape((string) $user['email']) ?></td>
<td><?= cpmsPasswordRecoveryEscape((string) $user['roles']) ?><br>
Property <?= (int) $user['property_id'] ?></td><td>
<form method="post" class="reset-form">
<input type="hidden" name="csrf_token" value="<?= cpmsPasswordRecoveryEscape($csrfToken) ?>">
<input type="hidden" name="system_user_id" value="<?= (int) $user['id'] ?>">
<label>Kata laluan sementara
<input name="temporary_password" value="<?= cpmsPasswordRecoveryEscape($temporaryPassword) ?>" required></label>
<button type="submit">Reset Akaun Ini</button></form></td></tr>
<?php endforeach; ?></table></section><?php elseif ($search !== ''): ?>
<section class="card"><p>Tiada pengguna dijumpai.</p></section><?php endif; ?>
</main></body></html>
