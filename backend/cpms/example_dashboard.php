<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

cpmsRequireLogin();
cpmsRequireProperty();
cpmsRequirePermission('dashboard.view');

cpmsLog(
    $conn,
    'dashboard_viewed',
    'dashboard',
    null,
    'Pengguna membuka dashboard.'
);
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >

    <title>
        <?= cpmsEscape($cpmsTheme['property_name']) ?>
    </title>

    <style>
        :root {
            --primary:
                <?= cpmsEscape(
                    $cpmsTheme['primary_color']
                ) ?>;
            --secondary:
                <?= cpmsEscape(
                    $cpmsTheme['secondary_color']
                ) ?>;
        }

        body {
            margin: 0;
            background: #f4f7fb;
            color: #1f2937;
            font-family: Arial, sans-serif;
        }

        .dashboard {
            width: min(1100px, calc(100% - 24px));
            margin: 28px auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: center;
            padding: 24px;
            border-radius: 18px;
            background:
                linear-gradient(
                    135deg,
                    var(--primary),
                    var(--secondary)
                );
            color: #fff;
        }

        .header h1 {
            margin: 0 0 5px;
        }

        .header p {
            margin: 0;
            opacity: .8;
        }

        .user {
            text-align: right;
        }

        .card {
            margin-top: 18px;
            padding: 22px;
            border-radius: 16px;
            background: #fff;
            box-shadow:
                0 8px 25px rgba(15, 23, 42, .08);
        }
    </style>
</head>
<body>
<main class="dashboard">
    <header class="header">
        <div>
            <h1>
                <?= cpmsEscape(
                    $cpmsTheme['property_name']
                ) ?>
            </h1>

            <p>
                CPMS V24 Commercial Framework
            </p>
        </div>

        <div class="user">
            <strong>
                <?= cpmsEscape($cpmsUser['name']) ?>
            </strong>

            <div>
                <?= cpmsEscape($cpmsUser['role']) ?>
            </div>
        </div>
    </header>

    <section class="card">
        <h2>Core Framework berjaya dipasang</h2>

        <p>
            Property ID:
            <?= $cpmsPropertyId ?>
        </p>

        <p>
            Versi:
            <?= cpmsEscape(cpmsConfig('version')) ?>
        </p>

        <p>
            Permission dashboard:
            <?= can('dashboard.view') ? 'Dibenarkan' : 'Ditolak' ?>
        </p>
    </section>
</main>
</body>
</html>
