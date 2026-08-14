<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$rows = [];

$result = $conn->query("
    SELECT
        pa.id,
        pa.full_name,
        pa.username,
        pa.email,
        pa.phone,
        pa.position_title,
        pa.role,
        pa.status,
        pa.last_login_at,
        pa.created_at,
        p.property_code,
        p.property_name
    FROM property_admins pa
    INNER JOIN cpms_properties p
        ON p.id = pa.property_id
    ORDER BY p.property_name ASC, pa.full_name ASC
");

if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Property Admin | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        .so-page-actions {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:22px;
        }

        .so-page-actions a {
            display:inline-flex;
            min-height:42px;
            align-items:center;
            padding:9px 14px;
            border-radius:9px;
            text-decoration:none;
            font-size:13px;
            font-weight:800;
        }

        .so-back {
            background:#e9eef5;
            color:#334155;
        }

        .so-add {
            background:var(--so-accent);
            color:#fff;
        }

        .badge {
            display:inline-block;
            padding:4px 8px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
        }

        .badge-active {
            background:#dcfce7;
            color:#166534;
        }

        .badge-inactive {
            background:#fee2e2;
            color:#991b1b;
        }
    </style>
</head>
<body>
<div class="so-dashboard">
    <header class="so-topbar">
        <div>
            <strong>Property Admin</strong><br>
            <small>System Owner Portal</small>
        </div>

        <a href="logout.php">Log Keluar</a>
    </header>

    <?php if (isset($_GET['created'])): ?>
        <div class="so-alert so-alert-success">
            Akaun Property Admin berjaya dicipta.
        </div>
    <?php endif; ?>

    <div class="so-page-actions">
        <a class="so-back" href="dashboard.php">← Dashboard</a>
        <a class="so-add" href="add_property_admin.php">+ Cipta Property Admin</a>
    </div>

    <section class="so-panel">
        <h2>Senarai Property Admin</h2>
        <p>
            Akaun ini berasingan daripada jadual admin V23 lama.
        </p>

        <div class="so-table-wrap">
            <table class="so-table">
                <thead>
                <tr>
                    <th>Property</th>
                    <th>Nama</th>
                    <th>Username</th>
                    <th>Jawatan</th>
                    <th>Hubungan</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6">
                            Belum ada Property Admin.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <strong>
                                    <?php echo systemOwnerEscape($row['property_name']); ?>
                                </strong><br>
                                <small>
                                    <?php echo systemOwnerEscape($row['property_code']); ?>
                                </small>
                            </td>
                            <td>
                                <?php echo systemOwnerEscape($row['full_name']); ?>
                            </td>
                            <td>
                                <?php echo systemOwnerEscape($row['username']); ?>
                            </td>
                            <td>
                                <?php echo systemOwnerEscape($row['position_title']); ?><br>
                                <small>
                                    <?php echo systemOwnerEscape($row['role']); ?>
                                </small>
                            </td>
                            <td>
                                <?php echo systemOwnerEscape($row['phone']); ?><br>
                                <small>
                                    <?php echo systemOwnerEscape($row['email']); ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge <?php echo $row['status'] === 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo systemOwnerEscape($row['status']); ?>
                                </span>
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
