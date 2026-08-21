<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

$user = cpmsV2User();

if (!$user) {
    http_response_code(401);
    exit('Login required.');
}

$role = strtolower((string) ($user['role'] ?? ''));
$isSystemOwner = $role === 'system_owner';
$isPropertyAdmin = in_array(
    $role,
    ['property_admin', 'manager', 'admin'],
    true
);

if (!$isSystemOwner && !$isPropertyAdmin) {
    http_response_code(403);
    exit('Access denied.');
}

$db = cpmsV2Database();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['cpms_guard_register_csrf'])) {
    $_SESSION['cpms_guard_register_csrf'] = bin2hex(random_bytes(24));
}

function cpmsSgEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsSgColumns(mysqli $db, string $table): array
{
    $result = $db->query(
        "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = '"
        . $db->real_escape_string($table)
        . "'
         ORDER BY ORDINAL_POSITION"
    );

    $columns = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[(string) $row['COLUMN_NAME']] = $row;
        }
    }

    return $columns;
}

function cpmsSgFirstColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

function cpmsSgPropertyId(array $user): int
{
    foreach (['property_id', 'current_property_id', 'active_property_id'] as $key) {
        $value = (int) ($user[$key] ?? 0);

        if ($value > 0) {
            return $value;
        }
    }

    if (isset($_SESSION['property_id'])) {
        return (int) $_SESSION['property_id'];
    }

    if (isset($_SESSION['cpms_property_id'])) {
        return (int) $_SESSION['cpms_property_id'];
    }

    return 0;
}

$columns = cpmsSgColumns($db, 'security_guards');

if (!$columns || !isset($columns['property_id'])) {
    http_response_code(500);
    exit('security_guards.property_id is required.');
}

$nameColumn = cpmsSgFirstColumn(
    $columns,
    ['name', 'full_name', 'guard_name']
);
$usernameColumn = cpmsSgFirstColumn(
    $columns,
    ['username', 'user_name', 'login_name']
);
$emailColumn = cpmsSgFirstColumn(
    $columns,
    ['email', 'email_address']
);
$phoneColumn = cpmsSgFirstColumn(
    $columns,
    ['phone', 'phone_number', 'mobile', 'contact_no']
);
$passwordColumn = cpmsSgFirstColumn(
    $columns,
    ['password', 'password_hash']
);
$statusColumn = cpmsSgFirstColumn(
    $columns,
    ['status', 'is_active', 'active']
);

if ($nameColumn === null) {
    http_response_code(500);
    exit('No supported guard name column was found.');
}

$properties = [];
$propertyResult = $db->query(
    'SELECT id, property_name
     FROM cpms_properties
     ORDER BY property_name ASC, id ASC'
);

if ($propertyResult) {
    while ($row = $propertyResult->fetch_assoc()) {
        $properties[] = $row;
    }
}

$fixedPropertyId = $isPropertyAdmin
    ? cpmsSgPropertyId($user)
    : 0;

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['cpms_guard_register_csrf'], $token)) {
        $message = 'Invalid security token. Refresh and try again.';
        $messageType = 'error';
    } else {
        $propertyId = $isSystemOwner
            ? (int) ($_POST['property_id'] ?? 0)
            : $fixedPropertyId;

        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($propertyId <= 0) {
            $message = $isSystemOwner
                ? 'Select a property.'
                : 'Your account has no active property assignment.';
            $messageType = 'error';
        } elseif ($name === '') {
            $message = 'Guard name is required.';
            $messageType = 'error';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Enter a valid email address.';
            $messageType = 'error';
        } else {
            $propertyCheck = $db->prepare(
                'SELECT COUNT(*) AS total
                 FROM cpms_properties
                 WHERE id = ?'
            );

            if (!$propertyCheck) {
                $message = $db->error;
                $messageType = 'error';
            } else {
                $propertyCheck->bind_param('i', $propertyId);
                $propertyCheck->execute();
                $propertyResult = $propertyCheck->get_result();
                $propertyRow = $propertyResult
                    ? $propertyResult->fetch_assoc()
                    : [];
                $propertyCheck->close();

                if ((int) ($propertyRow['total'] ?? 0) !== 1) {
                    $message = 'The selected property does not exist.';
                    $messageType = 'error';
                } else {
                    $insertColumns = ['property_id', $nameColumn];
                    $values = [$propertyId, $name];
                    $types = 'is';

                    if ($usernameColumn !== null && $username !== '') {
                        $insertColumns[] = $usernameColumn;
                        $values[] = $username;
                        $types .= 's';
                    }

                    if ($emailColumn !== null && $email !== '') {
                        $insertColumns[] = $emailColumn;
                        $values[] = $email;
                        $types .= 's';
                    }

                    if ($phoneColumn !== null && $phone !== '') {
                        $insertColumns[] = $phoneColumn;
                        $values[] = $phone;
                        $types .= 's';
                    }

                    if ($passwordColumn !== null && $password !== '') {
                        $insertColumns[] = $passwordColumn;
                        $values[] = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );
                        $types .= 's';
                    }

                    if ($statusColumn !== null) {
                        $insertColumns[] = $statusColumn;

                        $statusDataType = strtolower(
                            (string) ($columns[$statusColumn]['DATA_TYPE'] ?? '')
                        );

                        if (in_array(
                            $statusDataType,
                            ['tinyint', 'smallint', 'int', 'bigint', 'boolean'],
                            true
                        )) {
                            $values[] = 1;
                            $types .= 'i';
                        } else {
                            $values[] = 'active';
                            $types .= 's';
                        }
                    }

                    $quotedColumns = array_map(
                        static function (string $column): string {
                            return '`'
                                . str_replace('`', '``', $column)
                                . '`';
                        },
                        $insertColumns
                    );

                    $placeholders = implode(
                        ', ',
                        array_fill(0, count($insertColumns), '?')
                    );

                    $sql = 'INSERT INTO security_guards ('
                        . implode(', ', $quotedColumns)
                        . ') VALUES ('
                        . $placeholders
                        . ')';

                    try {
                        $stmt = $db->prepare($sql);

                        if (!$stmt) {
                            throw new RuntimeException($db->error);
                        }

                        $bind = [$types];

                        foreach ($values as $index => $value) {
                            $bind[] = &$values[$index];
                        }

                        call_user_func_array(
                            [$stmt, 'bind_param'],
                            $bind
                        );

                        if (!$stmt->execute()) {
                            throw new RuntimeException($stmt->error);
                        }

                        $newId = $stmt->insert_id;
                        $stmt->close();

                        $message = 'Security guard registered successfully. '
                            . 'Guard ID: '
                            . $newId
                            . '. Property ID was assigned automatically.';
                        $messageType = 'success';
                    } catch (Throwable $exception) {
                        $message = 'Registration failed: '
                            . $exception->getMessage();
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

$fixedPropertyName = '';

if ($fixedPropertyId > 0) {
    foreach ($properties as $property) {
        if ((int) $property['id'] === $fixedPropertyId) {
            $fixedPropertyName = (string) $property['property_name'];
            break;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Register Security Guard</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:760px;margin:30px auto;background:#fff;border:1px solid #dce4ef;border-radius:18px;padding:26px}
h1{margin:0 0 6px}.muted{color:#61718a}
.notice{padding:14px;border-radius:10px;margin:16px 0}
.success{background:#eaf8ef;color:#146c34}.warning{background:#fff7e5;color:#805800}.error{background:#fdecec;color:#a51d18}
label{display:block;font-weight:700;margin:15px 0 6px}
input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:8px}
.locked{background:#f1f5f9}
button{margin-top:20px;border:0;background:#10213d;color:#fff;padding:12px 18px;border-radius:9px;font-weight:700;cursor:pointer}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:680px){main{margin:10px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
<h1>Register Security Guard</h1>
<p class="muted">
Property-aware registration. Property Admin accounts cannot register guards under another property.
</p>

<?php if ($message !== ''): ?>
<div class="notice <?= cpmsSgEsc($messageType) ?>">
<?= cpmsSgEsc($message) ?>
</div>
<?php endif; ?>

<?php if ($isPropertyAdmin && $fixedPropertyId <= 0): ?>
<div class="notice error">
Your account has no property_id. Registration is disabled until the account assignment is corrected.
</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsSgEsc($_SESSION['cpms_guard_register_csrf']) ?>">

<label>Property</label>
<?php if ($isSystemOwner): ?>
<select name="property_id" required>
<option value="">Select property</option>
<?php foreach ($properties as $property): ?>
<option value="<?= (int) $property['id'] ?>">
<?= cpmsSgEsc($property['property_name']) ?>
— ID <?= (int) $property['id'] ?>
</option>
<?php endforeach; ?>
</select>
<?php else: ?>
<input
    class="locked"
    type="text"
    value="<?= cpmsSgEsc(
        $fixedPropertyName !== ''
            ? $fixedPropertyName . ' — ID ' . $fixedPropertyId
            : 'No property assigned'
    ) ?>"
    readonly
>
<?php endif; ?>

<label>Guard Name</label>
<input type="text" name="name" required>

<div class="grid">
<?php if ($usernameColumn !== null): ?>
<div>
<label>Username</label>
<input type="text" name="username">
</div>
<?php endif; ?>

<?php if ($phoneColumn !== null): ?>
<div>
<label>Phone</label>
<input type="text" name="phone">
</div>
<?php endif; ?>
</div>

<div class="grid">
<?php if ($emailColumn !== null): ?>
<div>
<label>Email</label>
<input type="email" name="email">
</div>
<?php endif; ?>

<?php if ($passwordColumn !== null): ?>
<div>
<label>Password</label>
<input type="password" name="password">
</div>
<?php endif; ?>
</div>

<button
    type="submit"
    <?= ($isPropertyAdmin && $fixedPropertyId <= 0) ? 'disabled' : '' ?>
>
Register Security Guard
</button>
</form>

<p class="muted">
System Owner may select a property. Property Admin property assignment is automatic and locked.
</p>
</main>
</body>
</html>
