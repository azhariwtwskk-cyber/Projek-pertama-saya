<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['security_guard_id']) && !isset($_SESSION['admin_id'])) {
    header('Location: cpms/login.php');
    exit();
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function fetchOne(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        $params = [$types];
        foreach ($values as $key => &$value) {
            $params[] = &$value;
        }
        call_user_func_array([$stmt, 'bind_param'], $params);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $row;
}

function fetchAll(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        $params = [$types];
        foreach ($values as $key => &$value) {
            $params[] = &$value;
        }
        call_user_func_array([$stmt, 'bind_param'], $params);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

$guardOnly = isset($_SESSION['security_guard_id']) && !isset($_SESSION['admin_id']);
$guardId = (int)($_SESSION['security_guard_id'] ?? 0);

$where = '';
$types = '';
$params = [];

if ($guardOnly) {
    $where = ' WHERE guard_id = ?';
    $types = 'i';
    $params = [$guardId];
}

$totalRow = fetchOne(
    $conn,
    "SELECT COUNT(*) AS total FROM security_patrols{$where}",
    $types,
    $params
);

$monthWhere = $where === ''
    ? " WHERE DATE_FORMAT(patrol_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
    : $where . " AND DATE_FORMAT(patrol_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";

$monthRow = fetchOne(
    $conn,
    "SELECT COUNT(*) AS total FROM security_patrols{$monthWhere}",
    $types,
    $params
);

$issueWhere = $where === ''
    ? ' WHERE issue_found = 1'
    : $where . ' AND issue_found = 1';

$issueRow = fetchOne(
    $conn,
    "SELECT COUNT(*) AS total FROM security_patrols{$issueWhere}",
    $types,
    $params
);

$woWhere = $where === ''
    ? ' WHERE work_order_required = 1'
    : $where . ' AND work_order_required = 1';

$woRow = fetchOne(
    $conn,
    "SELECT COUNT(*) AS total FROM security_patrols{$woWhere}",
    $types,
    $params
);

$verifiedCount = 0;

if (columnExists($conn, 'security_patrols', 'patrol_status')) {
    $verifiedWhere = $where === ''
        ? " WHERE patrol_status = 'Verified'"
        : $where . " AND patrol_status = 'Verified'";

    $verifiedRow = fetchOne(
        $conn,
        "SELECT COUNT(*) AS total FROM security_patrols{$verifiedWhere}",
        $types,
        $params
    );

    $verifiedCount = (int)($verifiedRow['total'] ?? 0);
}

$monthlyRows = fetchAll(
    $conn,
    "SELECT DATE_FORMAT(patrol_date, '%Y-%m') AS month_key,
            DATE_FORMAT(patrol_date, '%b %Y') AS month_label,
            COUNT(*) AS total
     FROM security_patrols
     {$where}
     GROUP BY month_key, month_label
     ORDER BY month_key DESC
     LIMIT 6",
    $types,
    $params
);

$monthlyRows = array_reverse($monthlyRows);

$categoryWhere = $where === ''
    ? " WHERE issue_found = 1"
    : $where . " AND issue_found = 1";

$categoryRows = fetchAll(
    $conn,
    "SELECT COALESCE(NULLIF(issue_category, ''), 'Tidak ditetapkan') AS label,
            COUNT(*) AS total
     FROM security_patrols
     {$categoryWhere}
     GROUP BY label
     ORDER BY total DESC
     LIMIT 8",
    $types,
    $params
);

$priorityRows = fetchAll(
    $conn,
    "SELECT COALESCE(NULLIF(issue_priority, ''), 'Tidak ditetapkan') AS label,
            COUNT(*) AS total
     FROM security_patrols
     {$categoryWhere}
     GROUP BY label
     ORDER BY total DESC",
    $types,
    $params
);

$statusRows = [];

if (columnExists($conn, 'security_patrols', 'patrol_status')) {
    $statusRows = fetchAll(
        $conn,
        "SELECT patrol_status AS label, COUNT(*) AS total
         FROM security_patrols
         {$where}
         GROUP BY patrol_status
         ORDER BY total DESC",
        $types,
        $params
    );
}

$recentRows = fetchAll(
    $conn,
    "SELECT id, patrol_reference, patrol_date, patrol_type,
            issue_found, issue_priority, patrol_status, work_order_id
     FROM security_patrols
     {$where}
     ORDER BY created_at DESC
     LIMIT 10",
    $types,
    $params
);

$guardPerformance = [];

if (!$guardOnly) {
    $guardTable = tableExists($conn, 'security_guards');

    if ($guardTable) {
        $guardNameColumn = 'id';

        foreach (['guard_name', 'name', 'full_name', 'security_name'] as $candidate) {
            if (columnExists($conn, 'security_guards', $candidate)) {
                $guardNameColumn = $candidate;
                break;
            }
        }

        $guardPerformance = fetchAll(
            $conn,
            "SELECT p.guard_id,
                    COALESCE(g.`{$guardNameColumn}`, CONCAT('Guard #', p.guard_id)) AS guard_name,
                    COUNT(*) AS patrol_total,
                    SUM(CASE WHEN p.issue_found = 1 THEN 1 ELSE 0 END) AS issue_total,
                    SUM(CASE WHEN p.work_order_required = 1 THEN 1 ELSE 0 END) AS wo_total
             FROM security_patrols p
             LEFT JOIN security_guards g ON g.id = p.guard_id
             GROUP BY p.guard_id, guard_name
             ORDER BY patrol_total DESC
             LIMIT 10"
        );
    }
}

$totalPatrol = (int)($totalRow['total'] ?? 0);
$totalMonth = (int)($monthRow['total'] ?? 0);
$totalIssues = (int)($issueRow['total'] ?? 0);
$totalWO = (int)($woRow['total'] ?? 0);

$issueRate = $totalPatrol > 0
    ? round(($totalIssues / $totalPatrol) * 100, 1)
    : 0;

$monthlyLabels = array_column($monthlyRows, 'month_label');
$monthlyValues = array_map('intval', array_column($monthlyRows, 'total'));
$categoryLabels = array_column($categoryRows, 'label');
$categoryValues = array_map('intval', array_column($categoryRows, 'total'));
$priorityLabels = array_column($priorityRows, 'label');
$priorityValues = array_map('intval', array_column($priorityRows, 'total'));

$conn->close();
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Security Analytics</title>
    <link rel="stylesheet" href="css/security_analytics.css?v=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head>
<body>
<div class="analytics-shell">
    <header class="analytics-header">
        <div>
            <p class="eyebrow">CPMS SECURITY MODULE</p>
            <h1>Security Analytics Dashboard</h1>
            <p class="subtext">
                Ringkasan rondaan, isu keselamatan dan Work Order.
            </p>
        </div>

        <div class="header-actions">
            <?php if ($guardOnly): ?>
                <a class="button secondary" href="security_dashboard.php">Dashboard</a>
                <a class="button" href="security_patrol_form.php">Start Patrol</a>
            <?php else: ?>
                <a class="button secondary" href="admin_dashboard.php">Admin Dashboard</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="kpi-grid">
        <article class="kpi-card">
            <span>Jumlah Patrol</span>
            <strong><?= $totalPatrol ?></strong>
            <small>Semua rekod</small>
        </article>

        <article class="kpi-card">
            <span>Patrol Bulan Ini</span>
            <strong><?= $totalMonth ?></strong>
            <small><?= e(date('F Y')) ?></small>
        </article>

        <article class="kpi-card">
            <span>Isu Ditemui</span>
            <strong><?= $totalIssues ?></strong>
            <small>Kadar <?= e($issueRate) ?>%</small>
        </article>

        <article class="kpi-card">
            <span>Work Order</span>
            <strong><?= $totalWO ?></strong>
            <small>Daripada patrol</small>
        </article>

        <article class="kpi-card">
            <span>Patrol Verified</span>
            <strong><?= $verifiedCount ?></strong>
            <small>Disahkan penyelia</small>
        </article>
    </section>

    <section class="chart-grid">
        <article class="panel wide">
            <div class="panel-heading">
                <div>
                    <h2>Trend Patrol 6 Bulan</h2>
                    <p>Jumlah rondaan setiap bulan</p>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="monthlyChart"></canvas>
            </div>
        </article>

        <article class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Isu Mengikut Kategori</h2>
                    <p>Kategori paling kerap dilaporkan</p>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="categoryChart"></canvas>
            </div>
        </article>

        <article class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Keutamaan Isu</h2>
                    <p>Taburan priority isu</p>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="priorityChart"></canvas>
            </div>
        </article>
    </section>

    <?php if ($statusRows): ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Status Patrol</h2>
                    <p>Ringkasan status semasa</p>
                </div>
            </div>

            <div class="status-grid">
                <?php foreach ($statusRows as $status): ?>
                    <div class="status-card">
                        <span><?= e($status['label']) ?></span>
                        <strong><?= (int)$status['total'] ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($guardPerformance): ?>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Prestasi Pengawal</h2>
                    <p>Jumlah rondaan dan isu mengikut pengawal</p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Pengawal</th>
                        <th>Jumlah Patrol</th>
                        <th>Isu</th>
                        <th>Work Order</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($guardPerformance as $guard): ?>
                        <tr>
                            <td><?= e($guard['guard_name']) ?></td>
                            <td><?= (int)$guard['patrol_total'] ?></td>
                            <td><?= (int)$guard['issue_total'] ?></td>
                            <td><?= (int)$guard['wo_total'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Patrol Terkini</h2>
                <p>10 rekod rondaan terbaru</p>
            </div>

            <a class="text-link" href="security_patrol_history.php">
                Lihat semua →
            </a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Rujukan</th>
                    <th>Tarikh</th>
                    <th>Jenis</th>
                    <th>Isu</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>WO</th>
                    <th>Tindakan</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$recentRows): ?>
                    <tr>
                        <td colspan="8">Belum ada rekod patrol.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentRows as $row): ?>
                        <tr>
                            <td>
                                <strong><?= e($row['patrol_reference'] ?? '-') ?></strong>
                            </td>
                            <td>
                                <?= !empty($row['patrol_date'])
                                    ? e(date('d/m/Y', strtotime((string)$row['patrol_date'])))
                                    : '-' ?>
                            </td>
                            <td><?= e($row['patrol_type'] ?? '-') ?></td>
                            <td><?= (int)($row['issue_found'] ?? 0) === 1 ? 'Ya' : 'Tidak' ?></td>
                            <td><?= e($row['issue_priority'] ?? '-') ?></td>
                            <td><?= e($row['patrol_status'] ?? 'Submitted') ?></td>
                            <td><?= !empty($row['work_order_id']) ? '#' . (int)$row['work_order_id'] : '-' ?></td>
                            <td>
                                <a class="mini-button" href="security_patrol_details.php?id=<?= (int)$row['id'] ?>">
                                    View
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

<script>
const monthlyLabels = <?= json_encode($monthlyLabels, JSON_UNESCAPED_UNICODE) ?>;
const monthlyValues = <?= json_encode($monthlyValues) ?>;
const categoryLabels = <?= json_encode($categoryLabels, JSON_UNESCAPED_UNICODE) ?>;
const categoryValues = <?= json_encode($categoryValues) ?>;
const priorityLabels = <?= json_encode($priorityLabels, JSON_UNESCAPED_UNICODE) ?>;
const priorityValues = <?= json_encode($priorityValues) ?>;

new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: monthlyLabels,
        datasets: [{
            label: 'Jumlah Patrol',
            data: monthlyValues,
            borderWidth: 3,
            tension: 0.3,
            fill: false
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 }
            }
        }
    }
});

new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: categoryLabels,
        datasets: [{
            label: 'Jumlah Isu',
            data: categoryValues,
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 }
            }
        }
    }
});

new Chart(document.getElementById('priorityChart'), {
    type: 'doughnut',
    data: {
        labels: priorityLabels,
        datasets: [{
            data: priorityValues,
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});
</script>
</body>
</html>
