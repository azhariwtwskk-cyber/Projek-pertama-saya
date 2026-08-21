<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/permission_engine.php';

cpmsRequire('dashboard.view', $conn);

$propertyCount = systemOwnerPropertyCount($conn);

$propertyAdminCount = 0;
$hqInspectorCount = 0;

$countResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM property_admins
");

if ($countResult instanceof mysqli_result) {
    $countRow = $countResult->fetch_assoc();
    $propertyAdminCount = (int) ($countRow['total'] ?? 0);
}

$hqTable = $conn->query("SHOW TABLES LIKE 'hq_inspectors'");
if ($hqTable instanceof mysqli_result && $hqTable->num_rows === 1) {
    $hqCountResult = $conn->query("SELECT COUNT(*) AS total FROM hq_inspectors");
    if ($hqCountResult instanceof mysqli_result) {
        $hqCountRow = $hqCountResult->fetch_assoc();
        $hqInspectorCount = (int) ($hqCountRow['total'] ?? 0);
    }
}

$properties = [];

$result = $conn->query("
    SELECT
        id,
        property_code,
        property_name,
        company_name,
        primary_color,
        default_language
    FROM cpms_properties
    ORDER BY id DESC
    LIMIT 10
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
    <title>System Owner Dashboard | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        .so-action-row {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:22px;
        }

        .so-action {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:43px;
            padding:10px 15px;
            border-radius:9px;
            text-decoration:none;
            font-size:13px;
            font-weight:800;
        }

        .so-action-primary {
            background:var(--so-accent);
            color:#fff;
        }

        .so-action-secondary {
            background:#e9eef5;
            color:#334155;
        }
    </style>
</head>
<body class="so-genesis-shell">
<div class="so-dashboard so-genesis-dashboard">
    <header class="so-topbar">
        <div>
            <strong>CPMSPro System Owner</strong><br>
            <small>
                Welcome,
                <?php echo systemOwnerEscape($systemOwnerUser['full_name']); ?>
            </small>
        </div>

        <a href="logout.php">Sign Out</a>
    </header>

    <div class="so-action-row so-genesis-nav">
        <?php if (cpmsCan('properties.manage', $conn)): ?>
        <a class="so-action so-action-primary" href="add_property.php">
            + Add Property
        </a>
        <?php endif; ?>

        <?php if (cpmsCan('users.create', $conn)): ?>
        <a class="so-action so-action-primary" href="add_property_admin.php">
            + Create Property Admin
        </a>
        <?php endif; ?>

        <?php if (cpmsCan('properties.view', $conn)): ?>
        <a class="so-action so-action-secondary" href="properties.php">
            Properties
        </a>
        <?php endif; ?>

        <?php if (cpmsCan('users.view', $conn)): ?>
        <a class="so-action so-action-secondary" href="property_admins.php">
            Property Admins
        </a>
        <?php endif; ?>

        <?php if (cpmsCan('inspection.view', $conn)): ?>
        <a class="so-action so-action-secondary" href="hq_inspectors.php">
            HQ Inspectors
        </a>
        <?php endif; ?>

        <?php if (cpmsCan('settings.manage', $conn)): ?>
        <a class="so-action so-action-secondary" href="demo_data.php">
            Demo Data
        </a>
        <?php endif; ?>

        <?php if (cpmsCan('audit.view', $conn)): ?>
        <a class="so-action so-action-secondary" href="health_check.php">
            System Health
        </a>
        <?php endif; ?>

        <?php if (cpmsCan('settings.manage', $conn)): ?>
        <a class="so-action so-action-secondary" href="modules.php">
            Module Manager
        </a>

        <a class="so-action so-action-secondary" href="system_settings.php">
            Global Settings
        </a>
        <?php endif; ?>

        <?php if (cpmsCan('permissions.manage', $conn)): ?>
        <a class="so-action so-action-secondary" href="permission_health.php">
            Permission Engine
        </a>
        <?php endif; ?>

        <?php if (cpmsCan('audit.view', $conn)): ?>
        <a class="so-action so-action-secondary" href="auth_audit.php">
            Login Audit
        </a>
        <?php endif; ?>
    </div>

    <section class="so-kpis">
        <article class="so-kpi">
            <span>Total Properties</span>
            <strong><?php echo $propertyCount; ?></strong>
        </article>

        <article class="so-kpi">
            <span>Property Admin</span>
            <strong><?php echo $propertyAdminCount; ?></strong>
        </article>

        <article class="so-kpi">
            <span>HQ Inspector</span>
            <strong><?php echo $hqInspectorCount; ?></strong>
        </article>

        <article class="so-kpi">
            <span>System Status</span>
            <strong style="font-size:21px;color:#15803d">Active</strong>
        </article>
    </section>

    <section class="so-panel so-genesis-panel">
        <h2>Property Portfolio</h2>

        <div class="so-table-wrap">
            <table class="so-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Kod</th>
                    <th>Nama Property</th>
                    <th>Syarikat</th>
                    <th>Bahasa</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$properties): ?>
                    <tr>
                        <td colspan="5">Tiada property ditemui.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($properties as $property): ?>
                        <tr>
                            <td><?php echo (int) $property['id']; ?></td>
                            <td><?php echo systemOwnerEscape($property['property_code']); ?></td>
                            <td><?php echo systemOwnerEscape($property['property_name']); ?></td>
                            <td><?php echo systemOwnerEscape($property['company_name']); ?></td>
                            <td><?php echo systemOwnerEscape($property['default_language']); ?></td>
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
