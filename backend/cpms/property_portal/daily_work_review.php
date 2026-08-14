<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cpmsPropertyRequire('work_orders.view');

function dailyWorkEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dailyWorkTableExists(mysqli $conn, string $table): bool
{
    $allowed = ['daily_work_logs', 'daily_work_images', 'staff', 'work_orders'];
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function dailyWorkColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!dailyWorkTableExists($conn, $table)) {
        $cache[$key] = false;
        return false;
    }
    $allowed = [
        'id', 'property_id', 'staff_id', 'work_order_id', 'work_reference',
        'work_date', 'work_category', 'block_location', 'specific_location',
        'start_time', 'end_time', 'work_description', 'materials_used',
        'issue_notes', 'work_status', 'include_in_newsletter',
        'include_in_monthly_report', 'supervisor_remarks', 'verified_by',
        'verified_at', 'created_at', 'image_name', 'image_path', 'image_type',
        'full_name', 'role', 'work_order_reference', 'title',
    ];
    if (!in_array($column, $allowed, true)) {
        $cache[$key] = false;
        return false;
    }
    $escaped = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escaped}'");
    $cache[$key] = $result instanceof mysqli_result && $result->num_rows > 0;
    return $cache[$key];
}

function dailyWorkCsrf(): string
{
    if (empty($_SESSION['daily_work_review_csrf'])) {
        $_SESSION['daily_work_review_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['daily_work_review_csrf'];
}

function dailyWorkImageUrl(array $image, int $propertyId): string
{
    $path = trim((string) ($image['image_path'] ?? ''));
    $name = basename((string) ($image['image_name'] ?? ''));
    $candidates = [];
    if ($path !== '') {
        $candidates[] = $path;
    }
    if ($name !== '') {
        $candidates[] = 'uploads/daily_work/property_' . $propertyId . '/' . $name;
        $candidates[] = 'uploads/daily_work/' . $name;
        $candidates[] = 'cpms/uploads/daily_work/property_' . $propertyId . '/' . $name;
        $candidates[] = 'cpms/uploads/daily_work/' . $name;
    }
    foreach ($candidates as $candidate) {
        $candidate = ltrim($candidate, '/');
        $absolute = dirname(__DIR__, 2) . '/' . $candidate;
        if (is_file($absolute)) {
            return '../../' . implode('/', array_map('rawurlencode', explode('/', $candidate)));
        }
    }
    return '';
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals(dailyWorkCsrf(), $token)) {
        $error = 'Sesi borang tamat. Sila cuba sekali lagi.';
    } else {
        $workId = max(0, (int) ($_POST['work_id'] ?? 0));
        $decision = (string) ($_POST['decision'] ?? '');
        $remarks = trim((string) ($_POST['supervisor_remarks'] ?? ''));
        $newsletter = isset($_POST['include_in_newsletter']) ? 1 : 0;
        $monthly = isset($_POST['include_in_monthly_report']) ? 1 : 0;

        if ($workId < 1) {
            $error = 'Rekod kerja tidak sah.';
        } elseif ($decision === 'Rejected' && $remarks === '') {
            $error = 'Catatan diperlukan jika kerja ditolak.';
        } else {
            $ownerSql = 'SELECT id FROM daily_work_logs WHERE id=?';
            $ownerTypes = 'i';
            $ownerParams = [$workId];
            if (dailyWorkColumnExists($conn, 'daily_work_logs', 'property_id')) {
                $ownerSql .= ' AND property_id=?';
                $ownerTypes .= 'i';
                $ownerParams[] = $currentPropertyId;
            } else {
                $ownerSql .= ' AND staff_id IN (SELECT id FROM staff WHERE property_id=?)';
                $ownerTypes .= 'i';
                $ownerParams[] = $currentPropertyId;
            }
            $ownerStmt = $conn->prepare($ownerSql . ' LIMIT 1');
            $owned = false;
            if ($ownerStmt) {
                $ownerStmt->bind_param($ownerTypes, ...$ownerParams);
                $ownerStmt->execute();
                $owned = (bool) $ownerStmt->get_result()->fetch_assoc();
                $ownerStmt->close();
            }

            if (!$owned) {
                $error = 'Rekod ini bukan di bawah property anda.';
            } else {
                $sets = [];
                $types = '';
                $params = [];
                if (in_array($decision, ['Verified', 'Rejected', 'Completed', 'In Progress'], true)) {
                    $sets[] = 'work_status=?';
                    $types .= 's';
                    $params[] = $decision;
                }
                if (dailyWorkColumnExists($conn, 'daily_work_logs', 'supervisor_remarks')) {
                    $sets[] = 'supervisor_remarks=?';
                    $types .= 's';
                    $params[] = $remarks;
                }
                if (dailyWorkColumnExists($conn, 'daily_work_logs', 'verified_by')
                    && in_array($decision, ['Verified', 'Rejected'], true)) {
                    $sets[] = 'verified_by=?';
                    $types .= 's';
                    $params[] = (string) ($_SESSION['property_admin_name'] ?? 'Property Portal');
                }
                if (dailyWorkColumnExists($conn, 'daily_work_logs', 'verified_at')
                    && in_array($decision, ['Verified', 'Rejected'], true)) {
                    $sets[] = 'verified_at=NOW()';
                }
                if (dailyWorkColumnExists($conn, 'daily_work_logs', 'include_in_newsletter')) {
                    $sets[] = 'include_in_newsletter=?';
                    $types .= 'i';
                    $params[] = $newsletter;
                }
                if (dailyWorkColumnExists($conn, 'daily_work_logs', 'include_in_monthly_report')) {
                    $sets[] = 'include_in_monthly_report=?';
                    $types .= 'i';
                    $params[] = $monthly;
                }

                if ($sets) {
                    $params[] = $workId;
                    $types .= 'i';
                    $stmt = $conn->prepare(
                        'UPDATE daily_work_logs SET ' . implode(', ', $sets) . ' WHERE id=?'
                    );
                    if ($stmt) {
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        $stmt->close();
                        $notice = 'Rekod kerja harian berjaya dikemas kini.';
                    }
                }
            }
        }
    }
}

$status = trim((string) ($_GET['status'] ?? ''));
$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$start = $month . '-01';
$end = date('Y-m-d', strtotime($start . ' +1 month'));
$allowedStatuses = ['', 'Completed', 'Verified', 'Rejected', 'In Progress', 'Pending Material', 'Pending Contractor', 'Unable to Complete'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$rows = [];
$imagesByWork = [];

if (dailyWorkTableExists($conn, 'daily_work_logs')) {
    $where = 'd.work_date>=? AND d.work_date<?';
    $types = 'ss';
    $params = [$start, $end];
    if (dailyWorkColumnExists($conn, 'daily_work_logs', 'property_id')) {
        if (dailyWorkColumnExists($conn, 'staff', 'property_id')) {
            $joinStaff = ' INNER JOIN staff s ON s.id=d.staff_id';
            $where .= ' AND (d.property_id=? OR (COALESCE(d.property_id,0)=0 AND s.property_id=?))';
            $types .= 'ii';
            $params[] = $currentPropertyId;
            $params[] = $currentPropertyId;
        } else {
            $joinStaff = ' LEFT JOIN staff s ON s.id=d.staff_id';
            $where .= ' AND d.property_id=?';
            $types .= 'i';
            $params[] = $currentPropertyId;
        }
    } elseif (dailyWorkColumnExists($conn, 'staff', 'property_id')) {
        $joinStaff = ' INNER JOIN staff s ON s.id=d.staff_id AND s.property_id=?';
        $types = 'i' . $types;
        array_unshift($params, $currentPropertyId);
    } elseif (!dailyWorkColumnExists($conn, 'staff', 'property_id')) {
        $joinStaff = ' LEFT JOIN staff s ON s.id=d.staff_id';
        $where .= ' AND 1=0';
    }
    if ($status !== '') {
        $where .= ' AND d.work_status=?';
        $types .= 's';
        $params[] = $status;
    }
    $selectImageFlags = dailyWorkColumnExists($conn, 'daily_work_logs', 'include_in_monthly_report')
        ? 'd.include_in_monthly_report,'
        : '0 AS include_in_monthly_report,';

    $sql = "SELECT d.*, {$selectImageFlags}
                   s.full_name, s.role,
                   w.work_order_reference, w.title AS work_order_title
            FROM daily_work_logs d
            {$joinStaff}
            LEFT JOIN work_orders w ON w.id=d.work_order_id
            WHERE {$where}
            ORDER BY d.work_date DESC, d.created_at DESC, d.id DESC
            LIMIT 200";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    }
}

if ($rows && dailyWorkTableExists($conn, 'daily_work_images')) {
    $ids = array_map(function (array $row): int {
        return (int) $row['id'];
    }, $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $pathSelect = dailyWorkColumnExists($conn, 'daily_work_images', 'image_path')
        ? 'image_path,'
        : "'' AS image_path,";
    $stmt = $conn->prepare(
        "SELECT daily_work_id, image_name, {$pathSelect} image_type
         FROM daily_work_images
         WHERE daily_work_id IN ({$placeholders})
         ORDER BY id ASC"
    );
    if ($stmt) {
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($image = $result->fetch_assoc()) {
            $imagesByWork[(int) $image['daily_work_id']][] = $image;
        }
        $stmt->close();
    }
}

$pageTitle = 'Daily Work Review';
$activeMenu = 'daily_work';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.daily-work-grid{display:grid;gap:18px}.daily-work-card{border:1px solid #d8e1ef;border-radius:8px;background:#fff;padding:18px;box-shadow:0 8px 24px rgba(15,23,42,.05)}.daily-work-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.daily-work-ref{font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase}.daily-work-title{margin:4px 0 6px;font-size:20px;color:#0f172a}.daily-work-meta{display:flex;flex-wrap:wrap;gap:8px;color:#64748b;font-size:13px}.daily-work-badge{padding:6px 10px;border-radius:999px;background:#e0f2fe;color:#075985;font-weight:800;font-size:12px}.daily-work-badge.Verified{background:#dcfce7;color:#166534}.daily-work-badge.Rejected{background:#fee2e2;color:#991b1b}.daily-work-body{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:16px;margin-top:14px}.daily-work-text{display:grid;gap:10px}.daily-work-text div{border:1px solid #edf2f7;border-radius:8px;padding:10px}.daily-work-text strong{display:block;font-size:12px;text-transform:uppercase;color:#64748b;margin-bottom:4px}.daily-work-photos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.daily-work-photos a{display:block;border:1px solid #d8e1ef;border-radius:8px;overflow:hidden;min-height:110px;background:#f8fafc}.daily-work-photos img{width:100%;height:120px;object-fit:cover;display:block}.daily-work-missing{padding:14px;color:#991b1b;font-size:13px}.daily-work-form{margin-top:16px;border-top:1px solid #edf2f7;padding-top:14px;display:grid;gap:10px}.daily-work-checks{display:flex;flex-wrap:wrap;gap:14px}.daily-work-actions{display:flex;flex-wrap:wrap;gap:8px}.daily-work-actions button{border:0;border-radius:8px;padding:10px 14px;font-weight:800;cursor:pointer}.daily-work-actions .verify{background:#16a34a;color:#fff}.daily-work-actions .reject{background:#dc2626;color:#fff}.daily-work-actions .save{background:#0f172a;color:#fff}.daily-work-form textarea{width:100%;min-height:82px;border:1px solid #cbd5e1;border-radius:8px;padding:10px}.daily-work-filter form{display:flex;flex-wrap:wrap;gap:12px;align-items:end}.daily-work-filter label{display:grid;gap:5px;font-weight:700;color:#334155}@media(max-width:900px){.daily-work-body{grid-template-columns:1fr}.daily-work-head{display:block}}
</style>
<section class="page-heading">
    <div>
        <span class="section-label">STAFF OPERATIONS</span>
        <h1>Daily Work Review</h1>
        <p>Semak kerja harian staff, sahkan gambar kerja, dan pilih item untuk Newsletter atau Monthly Management Report.</p>
    </div>
    <div class="record-total"><span>Records</span><strong><?php echo count($rows); ?></strong></div>
</section>
<?php if ($notice !== ''): ?><section class="panel"><div class="notice success"><?php echo dailyWorkEscape($notice); ?></div></section><?php endif; ?>
<?php if ($error !== ''): ?><section class="panel"><div class="notice danger"><?php echo dailyWorkEscape($error); ?></div></section><?php endif; ?>
<section class="panel daily-work-filter">
    <form method="get">
        <label>Month <input type="month" name="month" value="<?php echo dailyWorkEscape($month); ?>"></label>
        <label>Status <select name="status">
            <?php foreach ($allowedStatuses as $option): ?>
                <option value="<?php echo dailyWorkEscape($option); ?>" <?php echo $status === $option ? 'selected' : ''; ?>><?php echo $option === '' ? 'All Statuses' : dailyWorkEscape($option); ?></option>
            <?php endforeach; ?>
        </select></label>
        <button class="button button-primary">Filter</button>
        <a class="button button-secondary" href="daily_work_review.php">Reset</a>
    </form>
</section>
<section class="daily-work-grid">
<?php if (!$rows): ?>
    <article class="daily-work-card"><strong>Tiada rekod kerja harian.</strong><p>Belum ada upload staff untuk tapisan ini.</p></article>
<?php endif; ?>
<?php foreach ($rows as $row): $workId = (int) $row['id']; ?>
    <article class="daily-work-card">
        <div class="daily-work-head">
            <div>
                <div class="daily-work-ref"><?php echo dailyWorkEscape($row['work_reference'] ?? ('DW-' . $workId)); ?></div>
                <h2 class="daily-work-title"><?php echo dailyWorkEscape($row['work_category'] ?? 'Daily Work'); ?></h2>
                <div class="daily-work-meta">
                    <span><?php echo dailyWorkEscape($row['work_date'] ?? ''); ?></span>
                    <span><?php echo dailyWorkEscape($row['full_name'] ?? 'Staff'); ?></span>
                    <span><?php echo dailyWorkEscape(trim((string) (($row['block_location'] ?? '') . ' ' . ($row['specific_location'] ?? '')))); ?></span>
                </div>
            </div>
            <span class="daily-work-badge <?php echo dailyWorkEscape($row['work_status'] ?? ''); ?>"><?php echo dailyWorkEscape($row['work_status'] ?? 'Pending'); ?></span>
        </div>
        <div class="daily-work-body">
            <div class="daily-work-text">
                <div><strong>Kerja Dilakukan</strong><?php echo nl2br(dailyWorkEscape($row['work_description'] ?? '-')); ?></div>
                <div><strong>Bahan / Alat</strong><?php echo nl2br(dailyWorkEscape($row['materials_used'] ?? '-')); ?></div>
                <div><strong>Masalah / Tindakan Susulan</strong><?php echo nl2br(dailyWorkEscape($row['issue_notes'] ?? '-')); ?></div>
                <?php if (!empty($row['work_order_reference'])): ?><div><strong>Work Order</strong><?php echo dailyWorkEscape($row['work_order_reference'] . ' - ' . ($row['work_order_title'] ?? '')); ?></div><?php endif; ?>
            </div>
            <div class="daily-work-photos">
                <?php $photos = $imagesByWork[$workId] ?? []; ?>
                <?php if (!$photos): ?><div class="daily-work-missing">Tiada gambar dilampirkan.</div><?php endif; ?>
                <?php foreach ($photos as $photo): $url = dailyWorkImageUrl($photo, (int) ($row['property_id'] ?? $currentPropertyId)); ?>
                    <?php if ($url !== ''): ?><a href="<?php echo dailyWorkEscape($url); ?>" target="_blank"><img src="<?php echo dailyWorkEscape($url); ?>" alt="<?php echo dailyWorkEscape($photo['image_type'] ?? 'Work photo'); ?>"></a>
                    <?php else: ?><div class="daily-work-missing">Gambar tidak dijumpai: <?php echo dailyWorkEscape($photo['image_name'] ?? ''); ?></div><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <form method="post" class="daily-work-form">
            <input type="hidden" name="csrf_token" value="<?php echo dailyWorkEscape(dailyWorkCsrf()); ?>">
            <input type="hidden" name="work_id" value="<?php echo $workId; ?>">
            <textarea name="supervisor_remarks" placeholder="Catatan pengesahan / sebab reject"><?php echo dailyWorkEscape($row['supervisor_remarks'] ?? ''); ?></textarea>
            <div class="daily-work-checks">
                <label><input type="checkbox" name="include_in_newsletter" value="1" <?php echo !empty($row['include_in_newsletter']) ? 'checked' : ''; ?>> Masukkan Newsletter</label>
                <label><input type="checkbox" name="include_in_monthly_report" value="1" <?php echo !empty($row['include_in_monthly_report']) ? 'checked' : ''; ?>> Masukkan Monthly Report</label>
            </div>
            <div class="daily-work-actions">
                <button class="verify" name="decision" value="Verified">Verify</button>
                <button class="reject" name="decision" value="Rejected">Reject</button>
                <button class="save" name="decision" value="">Simpan Pilihan</button>
            </div>
        </form>
    </article>
<?php endforeach; ?>
</section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
