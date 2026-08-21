<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/modules.php';

$successMessage = '';
$errorMessage = '';
$csrfToken = systemOwnerCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!systemOwnerVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Permintaan tidak sah. Sila muat semula halaman.';
    } else {
        $modules = cpmsLoadModules($conn);

        $stmt = $conn->prepare(
            'UPDATE cpms_modules SET is_enabled = ? WHERE module_key = ?'
        );

        if (!$stmt) {
            $errorMessage = 'Modul tidak dapat disediakan.';
        } else {
            $conn->begin_transaction();

            try {
                foreach ($modules as $moduleKey => $module) {
                    $enabled = isset($_POST['modules'][$moduleKey]) ? 1 : 0;

                    $stmt->bind_param('is', $enabled, $moduleKey);

                    if (!$stmt->execute()) {
                        throw new RuntimeException(
                            'Module update failed for: ' . $moduleKey
                        );
                    }
                }

                $conn->commit();
                $successMessage = 'Tetapan modul global berjaya dikemas kini.';
            } catch (Throwable $error) {
                $conn->rollback();
                $errorMessage = 'Tetapan modul gagal disimpan.';

                if (function_exists('cpmsFoundationLog')) {
                    cpmsFoundationLog(
                        'System Owner module update failed: ' .
                        $error->getMessage()
                    );
                }
            }

            $stmt->close();
        }
    }
}

$modules = cpmsLoadModules($conn);
$groups = [];

foreach ($modules as $module) {
    $groupName = trim((string) ($module['group'] ?? ''));

    if ($groupName === '') {
        $groupName = 'Lain-lain';
    }

    $groups[$groupName][] = $module;
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Module Manager | CPMS System Owner</title>

    <link rel="stylesheet" href="assets/portal.css">

    <style>
        .so-wrap {
            max-width: 1050px;
            margin: auto;
            padding: 24px;
        }

        .so-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }

        .so-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 9px;
            padding: 11px 16px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }

        .primary {
            background: var(--so-accent);
            color: #fff;
        }

        .secondary {
            background: #e9eef5;
            color: #334155;
        }

        .page-description {
            color: #475569;
            line-height: 1.6;
            margin-bottom: 22px;
        }

        .group {
            background: #fff;
            border: 1px solid #dfe5ec;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .group h2 {
            margin-top: 0;
            margin-bottom: 14px;
            color: #0f172a;
            font-size: 1.15rem;
        }

        .mods {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 11px;
        }

        .mod {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
        }

        .mod:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }

        .mod input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            flex: 0 0 auto;
        }

        .mod-content {
            min-width: 0;
        }

        .mod-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #0f172a;
            line-height: 1.4;
        }

        .mod-icon {
            font-size: 1.15rem;
        }

        .mod-description {
            display: block;
            margin-top: 5px;
            color: #64748b;
            line-height: 1.45;
        }

        .notice {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 14px;
        }

        .ok {
            background: #dcfce7;
            color: #166534;
        }

        .err {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-state {
            padding: 20px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            background: #f8fafc;
            color: #475569;
        }

        .form-footer {
            margin-top: 18px;
        }

        @media (max-width: 700px) {
            .so-wrap {
                padding: 16px;
            }

            .mods {
                grid-template-columns: 1fr;
            }

            .so-actions {
                flex-direction: column;
            }

            .so-btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
<div class="so-wrap">

    <div class="so-actions">
        <a class="so-btn secondary" href="dashboard.php">
            ← Dashboard
        </a>

        <a class="so-btn secondary" href="system_settings.php">
            Tetapan Global
        </a>
    </div>

    <h1>Module Manager Global</h1>

    <p class="page-description">
        Hanya System Owner boleh menentukan modul yang tersedia pada platform.
        Pengaktifan mengikut property hendaklah dibuat melalui portal property
        atau konfigurasi property.
    </p>

    <?php if ($successMessage !== ''): ?>
        <div class="notice ok">
            <?php echo systemOwnerEscape($successMessage); ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="notice err">
            <?php echo systemOwnerEscape($errorMessage); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($groups)): ?>
        <div class="empty-state">
            Tiada modul dijumpai dalam jadual
            <strong>cpms_modules</strong>.
        </div>
    <?php else: ?>
        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo systemOwnerEscape($csrfToken); ?>"
            >

            <?php foreach ($groups as $groupName => $items): ?>
                <section class="group">
                    <h2>
                        <?php echo systemOwnerEscape($groupName); ?>
                    </h2>

                    <div class="mods">
                        <?php foreach ($items as $module): ?>
                            <?php
                            $key = trim(
                                (string) ($module['key'] ?? '')
                            );

                            if ($key === '') {
                                continue;
                            }

                            $moduleName = trim(
                                (string) ($module['name_ms'] ?? '')
                            );

                            if ($moduleName === '') {
                                $moduleName = trim(
                                    (string) ($module['name_en'] ?? '')
                                );
                            }

                            if ($moduleName === '') {
                                $moduleName = $key;
                            }

                            $moduleDescription = trim(
                                (string) (
                                    $module['description_ms'] ?? ''
                                )
                            );

                            if ($moduleDescription === '') {
                                $moduleDescription = trim(
                                    (string) (
                                        $module['description_en'] ?? ''
                                    )
                                );
                            }

                            $moduleIcon = trim(
                                (string) ($module['icon'] ?? '')
                            );

                            if ($moduleIcon === '') {
                                $moduleIcon = '📦';
                            }

                            $isEnabled =
                                !empty($module['enabled']);
                            ?>

                            <label class="mod">
                                <input
                                    type="checkbox"
                                    name="modules[<?php
                                        echo systemOwnerEscape($key);
                                    ?>]"
                                    value="1"
                                    <?php echo $isEnabled
                                        ? 'checked'
                                        : ''; ?>
                                >

                                <span class="mod-content">
                                    <strong class="mod-title">
                                        <span
                                            class="mod-icon"
                                            aria-hidden="true"
                                        >
                                            <?php
                                            echo systemOwnerEscape(
                                                $moduleIcon
                                            );
                                            ?>
                                        </span>

                                        <span>
                                            <?php
                                            echo systemOwnerEscape(
                                                $moduleName
                                            );
                                            ?>
                                        </span>
                                    </strong>

                                    <?php if (
                                        $moduleDescription !== ''
                                    ): ?>
                                        <small
                                            class="mod-description"
                                        >
                                            <?php
                                            echo systemOwnerEscape(
                                                $moduleDescription
                                            );
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="form-footer">
                <button
                    class="so-btn primary"
                    type="submit"
                >
                    Simpan Tetapan Modul
                </button>
            </div>
        </form>
    <?php endif; ?>

</div>
</body>
</html>
