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

function resolveImageSource(array $image): string
{
    foreach (['image_name', 'file_name', 'filename'] as $column) {
        if (!empty($image[$column])) {
            return 'uploads/security_patrol/' . ltrim((string)$image[$column], '/');
        }
    }

    foreach (['image_path', 'file_path', 'photo_path'] as $column) {
        if (!empty($image[$column])) {
            return (string)$image[$column];
        }
    }

    return '';
}

$month = (string)($_GET['month'] ?? date('Y-m'));

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

$guardOnly = isset($_SESSION['security_guard_id']) && !isset($_SESSION['admin_id']);
$guardId = (int)($_SESSION['security_guard_id'] ?? 0);

$where = " WHERE patrol_date BETWEEN ? AND ?";
$types = 'ss';
$params = [$monthStart, $monthEnd];

if ($guardOnly) {
    $where .= " AND guard_id = ?";
    $types .= 'i';
    $params[] = $guardId;
}

$totalRow = fetchOne(
    $conn,
    "SELECT
        COUNT(*) AS total_patrol,
        SUM(CASE WHEN issue_found = 1 THEN 1 ELSE 0 END) AS total_issues,
        SUM(CASE WHEN work_order_required = 1 THEN 1 ELSE 0 END) AS total_work_orders
     FROM security_patrols
     {$where}",
    $types,
    $params
);

$dayNightRows = fetchAll(
    $conn,
    "SELECT
        CASE
            WHEN HOUR(started_at) >= 6 AND HOUR(started_at) < 18 THEN 'Patrol Siang'
            ELSE 'Patrol Malam'
        END AS label,
        COUNT(*) AS total
     FROM security_patrols
     {$where}
     GROUP BY label",
    $types,
    $params
);

$categoryRows = fetchAll(
    $conn,
    "SELECT
        COALESCE(NULLIF(issue_category, ''), 'Tidak ditetapkan') AS label,
        COUNT(*) AS total
     FROM security_patrols
     {$where}
       AND issue_found = 1
     GROUP BY label
     ORDER BY total DESC
     LIMIT 10",
    $types,
    $params
);

$priorityRows = fetchAll(
    $conn,
    "SELECT
        COALESCE(NULLIF(issue_priority, ''), 'Tidak ditetapkan') AS label,
        COUNT(*) AS total
     FROM security_patrols
     {$where}
       AND issue_found = 1
     GROUP BY label
     ORDER BY total DESC",
    $types,
    $params
);

$dailyRows = fetchAll(
    $conn,
    "SELECT
        DATE_FORMAT(patrol_date, '%d') AS day_label,
        COUNT(*) AS total
     FROM security_patrols
     {$where}
     GROUP BY patrol_date
     ORDER BY patrol_date ASC",
    $types,
    $params
);

$recentPatrols = fetchAll(
    $conn,
    "SELECT
        id,
        patrol_reference,
        patrol_date,
        patrol_type,
        duty_type,
        started_at,
        completed_at,
        issue_found,
        issue_category,
        issue_priority,
        work_order_id,
        patrol_status
     FROM security_patrols
     {$where}
     ORDER BY patrol_date DESC, created_at DESC
     LIMIT 20",
    $types,
    $params
);

$guardPerformance = [];

if (!$guardOnly && tableExists($conn, 'security_guards')) {
    $guardNameColumn = 'id';

    foreach (['guard_name', 'name', 'full_name', 'security_name'] as $candidate) {
        if (columnExists($conn, 'security_guards', $candidate)) {
            $guardNameColumn = $candidate;
            break;
        }
    }

    $guardPerformance = fetchAll(
        $conn,
        "SELECT
            p.guard_id,
            COALESCE(g.`{$guardNameColumn}`, CONCAT('Guard #', p.guard_id)) AS guard_name,
            COUNT(*) AS patrol_total,
            SUM(CASE WHEN p.issue_found = 1 THEN 1 ELSE 0 END) AS issue_total,
            SUM(CASE WHEN p.work_order_required = 1 THEN 1 ELSE 0 END) AS wo_total
         FROM security_patrols p
         LEFT JOIN security_guards g ON g.id = p.guard_id
         WHERE p.patrol_date BETWEEN ? AND ?
         GROUP BY p.guard_id, guard_name
         ORDER BY patrol_total DESC",
        'ss',
        [$monthStart, $monthEnd]
    );
}

$images = [];

if (tableExists($conn, 'security_patrol_images')) {
    $images = fetchAll(
        $conn,
        "SELECT i.*, p.patrol_reference, p.patrol_date
         FROM security_patrol_images i
         INNER JOIN security_patrols p ON p.id = i.patrol_id
         WHERE p.patrol_date BETWEEN ? AND ?
         " . ($guardOnly ? "AND p.guard_id = ?" : "") . "
         ORDER BY p.patrol_date DESC, i.id DESC
         LIMIT 12",
        $types,
        $params
    );
}

$workOrders = [];

if (tableExists($conn, 'work_orders') && columnExists($conn, 'work_orders', 'security_patrol_id')) {
    $woSql = "SELECT
                w.id,
                w.work_order_reference,
                w.title,
                w.category,
                w.priority,
                w.status,
                w.created_at,
                p.patrol_reference
              FROM work_orders w
              INNER JOIN security_patrols p ON p.id = w.security_patrol_id
              WHERE p.patrol_date BETWEEN ? AND ?";

    $woTypes = 'ss';
    $woParams = [$monthStart, $monthEnd];

    if ($guardOnly) {
        $woSql .= " AND p.guard_id = ?";
        $woTypes .= 'i';
        $woParams[] = $guardId;
    }

    $woSql .= " ORDER BY w.created_at DESC";

    $workOrders = fetchAll($conn, $woSql, $woTypes, $woParams);
}

$propertyName = 'V23 Malawa Ria Apartment';
$companyName = '';
$propertyAddress = '';
$propertyLogo = '';

if (tableExists($conn, 'system_settings')) {
    $settingsResult = $conn->query("SELECT * FROM system_settings LIMIT 1");
    $settings = $settingsResult ? ($settingsResult->fetch_assoc() ?: []) : [];

    $propertyName = trim((string)(
        $settings['property_name']
        ?? $settings['system_name']
        ?? $propertyName
    ));

    $companyName = trim((string)(
        $settings['company_name']
        ?? $settings['company']
        ?? ''
    ));

    $propertyAddress = trim((string)(
        $settings['address']
        ?? $settings['property_address']
        ?? ''
    ));

    $propertyLogo = trim((string)(
        $settings['logo']
        ?? $settings['logo_path']
        ?? ''
    ));
}

$totalPatrol = (int)($totalRow['total_patrol'] ?? 0);
$totalIssues = (int)($totalRow['total_issues'] ?? 0);
$totalWorkOrders = (int)($totalRow['total_work_orders'] ?? 0);

$dayNightMap = [
    'Patrol Siang' => 0,
    'Patrol Malam' => 0
];

foreach ($dayNightRows as $row) {
    $dayNightMap[(string)$row['label']] = (int)$row['total'];
}

$issueRate = $totalPatrol > 0
    ? round(($totalIssues / $totalPatrol) * 100, 1)
    : 0;

$dailyLabels = array_column($dailyRows, 'day_label');
$dailyValues = array_map('intval', array_column($dailyRows, 'total'));
$categoryLabels = array_column($categoryRows, 'label');
$categoryValues = array_map('intval', array_column($categoryRows, 'total'));
$priorityLabels = array_column($priorityRows, 'label');
$priorityValues = array_map('intval', array_column($priorityRows, 'total'));

$isPrint = isset($_GET['print']) && $_GET['print'] === '1';

$conn->close();
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Monthly Security Report - <?= e($monthLabel) ?></title>
    <link rel="stylesheet" href="css/security_monthly_report.css?v=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="<?= $isPrint ? 'print-mode' : '' ?>">
<div class="report-shell">
    <?php if (!$isPrint): ?>
        <div class="toolbar no-print">
            <form method="get" class="month-form">
                <label for="month">Pilih Bulan</label>
                <input type="month" id="month" name="month" value="<?= e($month) ?>">
                <button type="submit">Jana Laporan</button>
            </form>

            <div class="toolbar-actions">
                <a href="security_analytics.php" class="button secondary">Analytics</a>
                <a
                    href="security_monthly_report.php?month=<?= e($month) ?>&print=1"
                    class="button"
                    target="_blank"
                >Print / Save PDF</a>
            </div>
        </div>
    <?php endif; ?>

    <article class="report">
        <header class="report-header">
            <div class="brand">
                <?php if ($propertyLogo !== ''): ?>
                    <img class="logo" src="<?= e($propertyLogo) ?>" alt="Logo">
                <?php endif; ?>

                <div>
                    <h1><?= e($propertyName) ?></h1>

                    <?php if ($companyName !== ''): ?>
                        <p class="company"><?= e($companyName) ?></p>
                    <?php endif; ?>

                    <?php if ($propertyAddress !== ''): ?>
                        <p><?= e($propertyAddress) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="report-title">
                <strong>MONTHLY SECURITY REPORT</strong>
                <span><?= e($monthLabel) ?></span>
            </div>
        </header>

        <section class="kpi-grid">
            <div>
                <span>Jumlah Patrol</span>
                <strong><?= $totalPatrol ?></strong>
            </div>
            <div>
                <span>Patrol Siang</span>
                <strong><?= (int)$dayNightMap['Patrol Siang'] ?></strong>
            </div>
            <div>
                <span>Patrol Malam</span>
                <strong><?= (int)$dayNightMap['Patrol Malam'] ?></strong>
            </div>
            <div>
                <span>Jumlah Isu</span>
                <strong><?= $totalIssues ?></strong>
            </div>
            <div>
                <span>Work Order</span>
                <strong><?= $totalWorkOrders ?></strong>
            </div>
            <div>
                <span>Kadar Isu</span>
                <strong><?= e($issueRate) ?>%</strong>
            </div>
        </section>

        <section class="section chart-section">
            <h2>Trend Patrol Harian</h2>
            <div class="chart-box">
                <canvas id="dailyChart"></canvas>
            </div>
        </section>

        <section class="two-column">
            <div class="section chart-section">
                <h2>Isu Mengikut Kategori</h2>
                <div class="chart-box small">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <div class="section chart-section">
                <h2>Isu Mengikut Priority</h2>
                <div class="chart-box small">
                    <canvas id="priorityChart"></canvas>
                </div>
            </div>
        </section>

        <section class="section">
            <h2>Ringkasan Patrol</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Rujukan</th>
                        <th>Tarikh</th>
                        <th>Jenis</th>
                        <th>Masa</th>
                        <th>Isu</th>
                        <th>Priority</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentPatrols): ?>
                        <tr>
                            <td colspan="7">Tiada rekod untuk bulan ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentPatrols as $row): ?>
                            <tr>
                                <td><?= e($row['patrol_reference'] ?? '-') ?></td>
                                <td>
                                    <?= !empty($row['patrol_date'])
                                        ? e(date('d/m/Y', strtotime((string)$row['patrol_date'])))
                                        : '-' ?>
                                </td>
                                <td><?= e($row['patrol_type'] ?? '-') ?></td>
                                <td>
                                    <?= !empty($row['started_at'])
                                        ? e(date('h:i A', strtotime((string)$row['started_at'])))
                                        : '-' ?>
                                </td>
                                <td><?= (int)($row['issue_found'] ?? 0) === 1 ? 'Ya' : 'Tidak' ?></td>
                                <td><?= e($row['issue_priority'] ?? '-') ?></td>
                                <td><?= e($row['patrol_status'] ?? 'Submitted') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($guardPerformance): ?>
            <section class="section">
                <h2>Prestasi Pengawal</h2>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Nama Pengawal</th>
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

        <section class="section">
            <h2>Gambar Patrol Bulan Ini</h2>

            <?php if (!$images): ?>
                <p class="empty">Tiada gambar patrol untuk bulan ini.</p>
            <?php else: ?>
                <div class="photo-grid">
                    <?php foreach ($images as $index => $image): ?>
                        <?php
                        $source = resolveImageSource($image);
                        $label = trim((string)(
                            $image['location_label']
                            ?? $image['photo_location']
                            ?? $image['location']
                            ?? ''
                        ));
                        ?>
                        <figure class="photo-card">
                            <?php if ($source !== ''): ?>
                                <img src="<?= e($source) ?>" alt="Gambar <?= $index + 1 ?>">
                            <?php else: ?>
                                <div class="missing-image">Gambar tidak dijumpai</div>
                            <?php endif; ?>

                            <figcaption>
                                <strong><?= e($label !== '' ? $label : 'Patrol Photo') ?></strong>
                                <span><?= e($image['patrol_reference'] ?? '-') ?></span>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="section">
            <h2>Ringkasan Work Order</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Work Order</th>
                        <th>Sumber Patrol</th>
                        <th>Tajuk</th>
                        <th>Kategori</th>
                        <th>Priority</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$workOrders): ?>
                        <tr>
                            <td colspan="6">Tiada Work Order daripada patrol untuk bulan ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($workOrders as $workOrder): ?>
                            <tr>
                                <td><?= e($workOrder['work_order_reference'] ?? '-') ?></td>
                                <td><?= e($workOrder['patrol_reference'] ?? '-') ?></td>
                                <td><?= e($workOrder['title'] ?? '-') ?></td>
                                <td><?= e($workOrder['category'] ?? '-') ?></td>
                                <td><?= e($workOrder['priority'] ?? '-') ?></td>
                                <td><?= e($workOrder['status'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section conclusion">
            <h2>Kesimpulan Automatik</h2>
            <p>
                Sebanyak <strong><?= $totalPatrol ?></strong> rondaan keselamatan telah direkodkan
                bagi bulan <strong><?= e($monthLabel) ?></strong>.
                Daripada jumlah tersebut, <strong><?= $totalIssues ?></strong> isu telah ditemui
                dengan kadar isu sebanyak <strong><?= e($issueRate) ?>%</strong>.
                Sebanyak <strong><?= $totalWorkOrders ?></strong> Work Order telah dikenal pasti
                atau dijana daripada rondaan keselamatan.
            </p>
        </section>

        <footer class="report-footer">
            <div class="signature">
                <span>Disediakan oleh</span>
                <strong><?= e(
                    $_SESSION['security_guard_name']
                    ?? $_SESSION['admin_name']
                    ?? 'CPMS User'
                ) ?></strong>
                <small>CPMS Security Module</small>
            </div>

            <div class="signature">
                <span>Tarikh laporan dijana</span>
                <strong><?= e(date('d/m/Y h:i A')) ?></strong>
                <small><?= e($monthLabel) ?></small>
            </div>
        </footer>
    </article>
</div>

<script>
const dailyLabels = <?= json_encode($dailyLabels, JSON_UNESCAPED_UNICODE) ?>;
const dailyValues = <?= json_encode($dailyValues) ?>;
const categoryLabels = <?= json_encode($categoryLabels, JSON_UNESCAPED_UNICODE) ?>;
const categoryValues = <?= json_encode($categoryValues) ?>;
const priorityLabels = <?= json_encode($priorityLabels, JSON_UNESCAPED_UNICODE) ?>;
const priorityValues = <?= json_encode($priorityValues) ?>;

new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: dailyLabels,
        datasets: [{
            label: 'Jumlah Patrol',
            data: dailyValues,
            borderWidth: 3,
            tension: 0.25,
            fill: false
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
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
        animation: false,
        indexAxis: 'y',
        plugins: {
            legend: { display: false }
        },
        scales: {
            x: {
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
        maintainAspectRatio: false,
        animation: false
    }
});

<?php if ($isPrint): ?>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 700);
});
<?php endif; ?>
</script>
</body>
</html>
