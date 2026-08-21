<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$properties = [];

$result = $conn->query("
    SELECT
        id,
        property_code,
        property_name,
        company_name,
        address,
        phone,
        email,
        website,
        logo_path,
        primary_color,
        secondary_color,
        default_language
    FROM cpms_properties
    ORDER BY property_name ASC
");

if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengurusan Property | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        .so-page-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }

        .so-page-actions a {
            display: inline-flex;
            min-height: 42px;
            align-items: center;
            padding: 9px 14px;
            border-radius: 9px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
        }

        .so-back {
            background: #e9eef5;
            color: #334155;
        }

        .so-add {
            background: var(--so-accent);
            color: #fff;
        }

        .so-property-name {
            font-weight: 800;
        }

        .so-property-code {
            display: inline-block;
            padding: 4px 7px;
            border-radius: 7px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 800;
        }

        .so-color-preview {
            display: inline-block;
            width: 18px;
            height: 18px;
            margin-right: 5px;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            vertical-align: middle;
        }

        .so-action-cell {
            white-space: nowrap;
        }

        .so-open-property {
            display: inline-flex;
            min-height: 36px;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 8px;
            background: #0f766e;
            color: #fff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }

        .so-open-property:hover,
        .so-open-property:focus {
            background: #115e59;
        }
    </style>
</head>
<body>
<div class="so-dashboard">
    <header class="so-topbar">
        <div>
            <strong>Pengurusan Property</strong><br>
            <small>System Owner Portal</small>
        </div>

        <a href="logout.php">Log Keluar</a>
    </header>

    <?php if (isset($_GET['created'])): ?>
        <div class="so-alert so-alert-success">
            Property baharu berjaya ditambah.
        </div>
    <?php endif; ?>

    <div class="so-page-actions">
        <a class="so-back" href="dashboard.php">← Dashboard</a>
        <a class="so-add" href="add_property.php">+ Tambah Property</a>
    </div>

    <section class="so-panel">
        <h2>Semua Property</h2>
        <p>
            Jumlah property:
            <strong><?php echo count($properties); ?></strong>
        </p>

        <div class="so-table-wrap">
            <table class="so-table">
                <thead>
                <tr>
                    <th>Kod</th>
                    <th>Property</th>
                    <th>Syarikat</th>
                    <th>Hubungan</th>
                    <th>Tema</th>
                    <th>Bahasa</th>
                    <th>Tindakan</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$properties): ?>
                    <tr>
                        <td colspan="7">Tiada property ditemui.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($properties as $property): ?>
                        <tr>
                            <td>
                                <span class="so-property-code">
                                    <?php echo systemOwnerEscape($property['property_code']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="so-property-name">
                                    <?php echo systemOwnerEscape($property['property_name']); ?>
                                </div>
                                <small>
                                    <?php echo systemOwnerEscape($property['address']); ?>
                                </small>
                            </td>
                            <td>
                                <?php echo systemOwnerEscape($property['company_name']); ?>
                            </td>
                            <td>
                                <div><?php echo systemOwnerEscape($property['phone']); ?></div>
                                <small><?php echo systemOwnerEscape($property['email']); ?></small>
                            </td>
                            <td>
                                <span
                                    class="so-color-preview"
                                    style="background:<?php echo systemOwnerEscape($property['primary_color']); ?>"
                                ></span>
                                <span
                                    class="so-color-preview"
                                    style="background:<?php echo systemOwnerEscape($property['secondary_color']); ?>"
                                ></span>
                            </td>
                            <td>
                                <?php echo systemOwnerEscape($property['default_language']); ?>
                            </td>
                            <td class="so-action-cell">
                                <a
                                    class="so-open-property"
                                    href="../property_portal/login.php?property=<?php
                                    echo rawurlencode(
                                        (string) $property['property_code']
                                    );
                                    ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Buka Portal ↗
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</body>
</html>
