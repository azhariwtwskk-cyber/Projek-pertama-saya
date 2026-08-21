<?php
declare(strict_types=1);

/*
 * Upload to:
 * htdocs/cpms/system_owner/unified_auth_test.php
 *
 * System Owner authentication is required before this diagnostic can run.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$engineFile = dirname(__DIR__) . '/includes/unified_auth.php';

if (!is_file($engineFile)) {
    http_response_code(500);
    exit('Unified authentication engine is missing.');
}

require_once $engineFile;

$errors = [];
$result = null;
$loginValue = '';
$propertyIdValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginValue = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $propertyIdValue = trim((string) ($_POST['property_id'] ?? ''));
    $csrf = $_POST['csrf_token'] ?? null;

    if (!systemOwnerVerifyCsrf(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Sesi keselamatan tidak sah. Muat semula halaman.';
    }

    if ($loginValue === '' || $password === '') {
        $errors[] = 'Masukkan username/e-mel dan kata laluan.';
    }

    $requestedPropertyId = null;

    if ($propertyIdValue !== '') {
        $requestedPropertyId = filter_var(
            $propertyIdValue,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($requestedPropertyId === false) {
            $errors[] = 'Property ID mesti nombor yang sah.';
        }
    }

    if (!$errors) {
        try {
            $result = cpmsUnifiedAuthenticate(
                $conn,
                $loginValue,
                $password,
                is_int($requestedPropertyId)
                    ? $requestedPropertyId
                    : null
            );
        } catch (Throwable $exception) {
            if (function_exists('cpmsFoundationLog')) {
                cpmsFoundationLog(
                    'Unified auth test failed: '
                    . $exception->getMessage()
                );
            }

            $errors[] = 'Ujian tidak dapat diproses.';
        }
    }
}

$csrfToken = systemOwnerCsrfToken();
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPMS v3.0.4 Unified Auth Test</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f4f7fb;
            color: #172033;
            font-family: Arial, sans-serif;
        }
        .wrap { width: min(760px, 94%); margin: 36px auto; }
        .card {
            background: #fff;
            border: 1px solid #dfe6f0;
            border-radius: 16px;
            padding: 26px;
            box-shadow: 0 8px 28px rgba(25, 40, 72, .07);
        }
        h1 { margin: 0 0 8px; font-size: 25px; }
        .lead { margin: 0 0 22px; color: #5b667a; }
        label { display: block; margin: 14px 0 7px; font-weight: 700; }
        input {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            font-size: 15px;
        }
        button {
            margin-top: 18px;
            padding: 12px 17px;
            border: 0;
            border-radius: 9px;
            background: #173b73;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        .alert {
            margin: 16px 0;
            padding: 13px 15px;
            border-radius: 9px;
        }
        .error { background: #fee2e2; color: #991b1b; }
        .success { background: #dcfce7; color: #166534; }
        .warning { background: #fef3c7; color: #92400e; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td {
            padding: 11px 8px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }
        th { width: 42%; color: #4b5563; }
        .note { margin-top: 18px; font-size: 13px; color: #64748b; }
    </style>
</head>
<body>
<main class="wrap">
    <section class="card">
        <h1>CPMS v3.0.4 — Unified Auth Test</h1>
        <p class="lead">
            Uji akaun Unified User Engine tanpa membuka session portal baharu.
        </p>

        <?php foreach ($errors as $error): ?>
            <div class="alert error">
                <?php echo systemOwnerEscape($error); ?>
            </div>
        <?php endforeach; ?>

        <?php if (is_array($result) && !empty($result['valid'])): ?>
            <?php
            $user = $result['user'];
            $context = $result['context'];
            $passwordState = $result['password'];
            ?>
            <div class="alert success">
                Authentication PASS — akaun Unified User sah.
            </div>

            <?php if (!empty($passwordState['legacy_plaintext'])): ?>
                <div class="alert warning">
                    Akaun masih menggunakan kata laluan legacy dan perlu
                    dinaik taraf kepada password hash.
                </div>
            <?php endif; ?>

            <table>
                <tr>
                    <th>System User ID</th>
                    <td><?php echo (int) $user['id']; ?></td>
                </tr>
                <tr>
                    <th>Nama</th>
                    <td>
                        <?php echo systemOwnerEscape((string) $user['full_name']); ?>
                    </td>
                </tr>
                <tr>
                    <th>Username</th>
                    <td>
                        <?php echo systemOwnerEscape((string) $user['username']); ?>
                    </td>
                </tr>
                <tr>
                    <th>Role</th>
                    <td>
                        <?php echo systemOwnerEscape((string) $context['role']); ?>
                    </td>
                </tr>
                <tr>
                    <th>Property ID</th>
                    <td>
                        <?php
                        echo $context['property_id'] === null
                            ? 'Global'
                            : (int) $context['property_id'];
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Portal</th>
                    <td>
                        <?php echo systemOwnerEscape((string) $context['portal']); ?>
                    </td>
                </tr>
                <tr>
                    <th>Legacy source</th>
                    <td>
                        <?php
                        echo systemOwnerEscape(
                            (string) ($user['source_table'] ?? '-')
                        );
                        ?>
                        #
                        <?php echo (int) ($user['source_id'] ?? 0); ?>
                    </td>
                </tr>
            </table>
        <?php elseif (is_array($result)): ?>
            <div class="alert error">
                Authentication FAIL:
                <?php echo systemOwnerEscape((string) ($result['code'] ?? 'unknown')); ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo systemOwnerEscape($csrfToken); ?>"
            >

            <label for="login">Username atau e-mel</label>
            <input
                id="login"
                name="login"
                type="text"
                value="<?php echo systemOwnerEscape($loginValue); ?>"
                autocomplete="username"
                required
            >

            <label for="password">Kata laluan</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <label for="property_id">
                Property ID (kosongkan untuk System Owner)
            </label>
            <input
                id="property_id"
                name="property_id"
                type="number"
                min="1"
                value="<?php echo systemOwnerEscape($propertyIdValue); ?>"
            >

            <button type="submit">Run Authentication Test</button>
        </form>

        <p class="note">
            Halaman ini tidak menukar login produksi dan tidak menyimpan
            kata laluan yang diuji.
        </p>
    </section>
</main>
</body>
</html>
