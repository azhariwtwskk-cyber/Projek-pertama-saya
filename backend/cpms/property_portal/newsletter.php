<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';

if (
    !cpmsCan('reports.view', $conn)
    && !cpmsCan('resident.announcement.manage', $conn)
    && !cpmsCan('settings.manage', $conn)
) {
    http_response_code(403);
    exit('Access denied. This account is not allowed to manage the Monthly Newsletter.');
}

function cpmsNewsletterEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsNewsletterTableExists(mysqli $conn, string $table): bool
{
    $allowed = [
        'cpms_newsletters',
        'cpms_newsletter_media',
        'complaints',
        'work_orders',
        'daily_work_logs',
        'staff',
        'cpms_pm_schedules',
    ];
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function cpmsNewsletterColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    if (!cpmsNewsletterTableExists($conn, $table)) {
        return false;
    }
    $allowedColumns = [
        'property_id', 'date_created', 'created_at', 'status',
        'work_date', 'staff_id', 'due_date', 'next_due_date',
        'include_in_newsletter', 'work_status',
    ];
    if (!in_array($column, $allowedColumns, true)) {
        return false;
    }
    $escaped = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escaped}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function cpmsNewsletterMonthBounds(int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    return [$start, $end];
}

function cpmsNewsletterCount(
    mysqli $conn,
    string $table,
    int $propertyId,
    string $dateColumn,
    string $start,
    string $end,
    ?array $statuses = null
): int {
    if (
        !cpmsNewsletterColumnExists($conn, $table, 'property_id')
        || !cpmsNewsletterColumnExists($conn, $table, $dateColumn)
    ) {
        return 0;
    }

    $sql = "SELECT COUNT(*) AS total FROM `{$table}` WHERE property_id=? "
        . "AND `{$dateColumn}`>=? AND `{$dateColumn}`<?";
    $types = 'iss';
    $params = [$propertyId, $start, $end];

    if (
        $statuses !== null
        && cpmsNewsletterColumnExists($conn, $table, 'status')
        && $statuses
    ) {
        $normalized = array_values(array_filter(array_map(
            static function ($value): string {
                return strtolower(trim((string) $value));
            },
            $statuses
        )));
        if ($normalized) {
            $sql .= ' AND LOWER(TRIM(COALESCE(status,\'\'))) IN ('
                . implode(',', array_fill(0, count($normalized), '?')) . ')';
            $types .= str_repeat('s', count($normalized));
            $params = array_merge($params, $normalized);
        }
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
}

function cpmsNewsletterDailyWorkCount(
    mysqli $conn,
    int $propertyId,
    string $start,
    string $end
): int {
    if (
        !cpmsNewsletterTableExists($conn, 'daily_work_logs')
        || !cpmsNewsletterColumnExists($conn, 'daily_work_logs', 'work_date')
    ) {
        return 0;
    }

    $where = 'd.work_date>=? AND d.work_date<?';
    $types = 'ss';
    $params = [$start, $end];
    $join = '';

    if (cpmsNewsletterColumnExists($conn, 'daily_work_logs', 'property_id')) {
        $where .= ' AND d.property_id=?';
        $types .= 'i';
        $params[] = $propertyId;
    } elseif (
        cpmsNewsletterTableExists($conn, 'staff')
        && cpmsNewsletterColumnExists($conn, 'daily_work_logs', 'staff_id')
        && cpmsNewsletterColumnExists($conn, 'staff', 'property_id')
    ) {
        $join = ' INNER JOIN staff s ON s.id=d.staff_id';
        $where .= ' AND s.property_id=?';
        $types .= 'i';
        $params[] = $propertyId;
    } else {
        return 0;
    }

    if (cpmsNewsletterColumnExists($conn, 'daily_work_logs', 'include_in_newsletter')) {
        $where .= ' AND d.include_in_newsletter=1';
    }
    if (cpmsNewsletterColumnExists($conn, 'daily_work_logs', 'work_status')) {
        $where .= " AND d.work_status='Verified'";
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM daily_work_logs d{$join} WHERE {$where}"
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
}

function cpmsNewsletterStats(
    mysqli $conn,
    int $propertyId,
    int $year,
    int $month
): array {
    [$start, $end] = cpmsNewsletterMonthBounds($year, $month);
    $complaints = cpmsNewsletterCount(
        $conn, 'complaints', $propertyId, 'date_created', $start, $end
    );
    $resolved = cpmsNewsletterCount(
        $conn,
        'complaints',
        $propertyId,
        'date_created',
        $start,
        $end,
        ['resolved', 'completed', 'closed', 'selesai']
    );
    $workOrders = cpmsNewsletterCount(
        $conn, 'work_orders', $propertyId, 'created_at', $start, $end
    );

    $pm = 0;
    if (cpmsNewsletterTableExists($conn, 'cpms_pm_schedules')) {
        $pmDateColumn = cpmsNewsletterColumnExists(
            $conn, 'cpms_pm_schedules', 'next_due_date'
        ) ? 'next_due_date' : (
            cpmsNewsletterColumnExists($conn, 'cpms_pm_schedules', 'due_date')
                ? 'due_date'
                : ''
        );
        if ($pmDateColumn !== '') {
            $pm = cpmsNewsletterCount(
                $conn,
                'cpms_pm_schedules',
                $propertyId,
                $pmDateColumn,
                $start,
                $end
            );
        }
    }

    return [
        'complaints' => $complaints,
        'resolved' => $resolved,
        'work_orders' => $workOrders,
        'daily_work' => cpmsNewsletterDailyWorkCount(
            $conn, $propertyId, $start, $end
        ),
        'pm' => $pm,
    ];
}

function cpmsNewsletterCsrf(): string
{
    if (empty($_SESSION['cpms_newsletter_csrf'])) {
        $_SESSION['cpms_newsletter_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cpms_newsletter_csrf'];
}

function cpmsNewsletterVerifyCsrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals(cpmsNewsletterCsrf(), $token)) {
        http_response_code(419);
        exit('The form session has expired. Please go back and try again.');
    }
}

function cpmsNewsletterUploadImage(
    array $file,
    int $propertyId,
    int $year,
    int $month
): ?array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Maximum image size is 5 MB.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    $allowed = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];
    $type = (int) ($info[2] ?? 0);
    if (!isset($allowed[$type])) {
        throw new RuntimeException('Image format must be JPG, PNG or WEBP.');
    }
    [$extension, $mime] = $allowed[$type];
    $relativeDir = 'uploads/newsletters/property_' . $propertyId
        . '/' . sprintf('%04d-%02d', $year, $month);
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true)) {
        throw new RuntimeException('The newsletter image folder could not be created.');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $extension;
    $absolute = $absoluteDir . '/' . $name;
    if (!move_uploaded_file($tmp, $absolute)) {
        throw new RuntimeException('The image could not be saved.');
    }
    return [
        'path' => $relativeDir . '/' . $name,
        'mime' => $mime,
    ];
}

$newsletterReady = cpmsNewsletterTableExists($conn, 'cpms_newsletters')
    && cpmsNewsletterTableExists($conn, 'cpms_newsletter_media');

$notice = '';
$error = '';
$selectedId = max(0, (int) ($_GET['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cpmsNewsletterVerifyCsrf();
    if (!$newsletterReady) {
        $error = 'The Monthly Newsletter database has not been installed.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            $newsletterId = max(0, (int) ($_POST['newsletter_id'] ?? 0));

            if ($action === 'save') {
                $year = max(2020, min(2100, (int) ($_POST['year'] ?? date('Y'))));
                $month = max(1, min(12, (int) ($_POST['month'] ?? date('n'))));
                $title = trim((string) ($_POST['title'] ?? ''));
                if ($title === '') {
                    $title = date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)))
                        . ' Monthly Newsletter';
                }
                $managerMessage = trim((string) ($_POST['manager_message'] ?? ''));
                $highlights = trim((string) ($_POST['highlights'] ?? ''));
                $upcoming = trim((string) ($_POST['upcoming_activities'] ?? ''));
                $stats = cpmsNewsletterStats(
                    $conn, $currentPropertyId, $year, $month
                );
                $statsJson = json_encode($stats, JSON_UNESCAPED_SLASHES);
                if (!is_string($statsJson)) {
                    $statsJson = '{}';
                }

                if ($newsletterId > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE cpms_newsletters
                         SET newsletter_year=?, newsletter_month=?, title=?,
                             manager_message=?, highlights=?, upcoming_activities=?,
                             statistics_json=?, updated_by=?, updated_at=NOW()
                         WHERE id=? AND property_id=? AND status IN ('draft','review')"
                    );
                    $userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
                    $stmt->bind_param(
                        'iisssssiii',
                        $year,
                        $month,
                        $title,
                        $managerMessage,
                        $highlights,
                        $upcoming,
                        $statsJson,
                        $userId,
                        $newsletterId,
                        $currentPropertyId
                    );
                    $stmt->execute();
                    $stmt->close();
                    $selectedId = $newsletterId;
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO cpms_newsletters
                         (property_id,newsletter_year,newsletter_month,title,
                          manager_message,highlights,upcoming_activities,
                          statistics_json,status,created_by,updated_by)
                         VALUES (?,?,?,?,?,?,?,?,'draft',?,?)
                         ON DUPLICATE KEY UPDATE
                            title=VALUES(title), manager_message=VALUES(manager_message),
                            highlights=VALUES(highlights),
                            upcoming_activities=VALUES(upcoming_activities),
                            statistics_json=VALUES(statistics_json),
                            updated_by=VALUES(updated_by), updated_at=NOW()"
                    );
                    $userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
                    $stmt->bind_param(
                        'iiisssssii',
                        $currentPropertyId,
                        $year,
                        $month,
                        $title,
                        $managerMessage,
                        $highlights,
                        $upcoming,
                        $statsJson,
                        $userId,
                        $userId
                    );
                    $stmt->execute();
                    $stmt->close();
                    $find = $conn->prepare(
                        'SELECT id FROM cpms_newsletters WHERE property_id=? '
                        . 'AND newsletter_year=? AND newsletter_month=? LIMIT 1'
                    );
                    $find->bind_param('iii', $currentPropertyId, $year, $month);
                    $find->execute();
                    $row = $find->get_result()->fetch_assoc();
                    $find->close();
                    $selectedId = (int) ($row['id'] ?? 0);
                }
                $notice = 'Monthly Newsletter draft saved successfully.';
            } elseif ($action === 'status' && $newsletterId > 0) {
                $status = (string) ($_POST['status'] ?? 'draft');
                if (!in_array($status, ['draft', 'review', 'published', 'archived'], true)) {
                    throw new RuntimeException('Invalid newsletter status.');
                }
                $publishedSql = $status === 'published'
                    ? ', published_at=COALESCE(published_at,NOW()), published_by=?'
                    : '';
                $sql = "UPDATE cpms_newsletters SET status=?{$publishedSql}, updated_at=NOW() "
                    . 'WHERE id=? AND property_id=?';
                $stmt = $conn->prepare($sql);
                if ($status === 'published') {
                    $userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
                    $stmt->bind_param(
                        'siii', $status, $userId, $newsletterId, $currentPropertyId
                    );
                } else {
                    $stmt->bind_param('sii', $status, $newsletterId, $currentPropertyId);
                }
                $stmt->execute();
                $stmt->close();
                $selectedId = $newsletterId;
                $notice = 'Newsletter status updated successfully.';
            } elseif ($action === 'upload' && $newsletterId > 0) {
                $owner = $conn->prepare(
                    'SELECT newsletter_year,newsletter_month FROM cpms_newsletters '
                    . 'WHERE id=? AND property_id=? LIMIT 1'
                );
                $owner->bind_param('ii', $newsletterId, $currentPropertyId);
                $owner->execute();
                $newsletter = $owner->get_result()->fetch_assoc();
                $owner->close();
                if (!$newsletter) {
                    throw new RuntimeException('The newsletter was not found for this property.');
                }
                $upload = cpmsNewsletterUploadImage(
                    $_FILES['newsletter_image'] ?? [],
                    $currentPropertyId,
                    (int) $newsletter['newsletter_year'],
                    (int) $newsletter['newsletter_month']
                );
                if ($upload !== null) {
                    $caption = trim((string) ($_POST['caption'] ?? ''));
                    $userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
                    $stmt = $conn->prepare(
                        'INSERT INTO cpms_newsletter_media '
                        . '(newsletter_id,property_id,file_path,mime_type,caption,uploaded_by) '
                        . 'VALUES (?,?,?,?,?,?)'
                    );
                    $stmt->bind_param(
                        'iisssi',
                        $newsletterId,
                        $currentPropertyId,
                        $upload['path'],
                        $upload['mime'],
                        $caption,
                        $userId
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = 'Newsletter image uploaded successfully.';
                }
                $selectedId = $newsletterId;
            }
        } catch (mysqli_sql_exception $exception) {
            $error = ((int) $exception->getCode() === 1062)
                ? 'A newsletter already exists for that month.'
                : 'The database could not save the Newsletter changes.';
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$newsletters = [];
$selected = null;
$media = [];

if ($newsletterReady) {
    $stmt = $conn->prepare(
        "SELECT id,newsletter_year,newsletter_month,title,status,statistics_json,
                published_at,updated_at
         FROM cpms_newsletters
         WHERE property_id=?
         ORDER BY newsletter_year DESC,newsletter_month DESC,id DESC"
    );
    $stmt->bind_param('i', $currentPropertyId);
    $stmt->execute();
    $newsletters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($selectedId > 0) {
        $stmt = $conn->prepare(
            'SELECT * FROM cpms_newsletters WHERE id=? AND property_id=? LIMIT 1'
        );
        $stmt->bind_param('ii', $selectedId, $currentPropertyId);
        $stmt->execute();
        $selected = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($selected) {
            $stmt = $conn->prepare(
                'SELECT id,file_path,caption,created_at FROM cpms_newsletter_media '
                . 'WHERE newsletter_id=? AND property_id=? ORDER BY sort_order,id'
            );
            $stmt->bind_param('ii', $selectedId, $currentPropertyId);
            $stmt->execute();
            $media = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}

$formYear = (int) ($selected['newsletter_year'] ?? date('Y'));
$formMonth = (int) ($selected['newsletter_month'] ?? date('n'));
$liveStats = cpmsNewsletterStats(
    $conn, $currentPropertyId, $formYear, $formMonth
);

$pageTitle = 'Monthly Newsletter';
$activeMenu = 'newsletter';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<link rel="stylesheet" href="assets/newsletter-manager.css?v=1">

<section class="newsletter-page">
    <header class="newsletter-hero">
        <div>
            <span class="newsletter-kicker">RESIDENT COMMUNICATION</span>
            <h1>Monthly Newsletter</h1>
            <p>Create the monthly publication for <strong><?php echo cpmsNewsletterEscape($currentPropertyName); ?></strong>. All data is isolated by property.</p>
        </div>
        <div class="newsletter-property-pill"><?php echo cpmsNewsletterEscape($currentPropertyCode); ?></div>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="newsletter-notice success"><?php echo cpmsNewsletterEscape($notice); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="newsletter-notice error"><?php echo cpmsNewsletterEscape($error); ?></div>
    <?php endif; ?>
    <?php if (!$newsletterReady): ?>
        <div class="newsletter-notice error">The Monthly Newsletter database is not installed. Run the Phase 1 installer first.</div>
    <?php endif; ?>

    <div class="newsletter-stat-grid">
        <article><small>Complaints</small><strong><?php echo $liveStats['complaints']; ?></strong></article>
        <article><small>Resolved</small><strong><?php echo $liveStats['resolved']; ?></strong></article>
        <article><small>Work Orders</small><strong><?php echo $liveStats['work_orders']; ?></strong></article>
        <article><small>Daily Work</small><strong><?php echo $liveStats['daily_work']; ?></strong></article>
        <article><small>PM This Month</small><strong><?php echo $liveStats['pm']; ?></strong></article>
    </div>

    <div class="newsletter-layout">
        <section class="newsletter-panel">
            <div class="newsletter-panel-head">
                <div>
                    <span class="newsletter-kicker">EDITOR</span>
                    <h2><?php echo $selected ? 'Update Newsletter' : 'Create Newsletter'; ?></h2>
                </div>
                <?php if ($selected): ?><a class="newsletter-link" href="newsletter.php">+ Baru</a><?php endif; ?>
            </div>
            <form method="post" class="newsletter-form">
                <input type="hidden" name="csrf_token" value="<?php echo cpmsNewsletterEscape(cpmsNewsletterCsrf()); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="newsletter_id" value="<?php echo (int) ($selected['id'] ?? 0); ?>">
                <div class="newsletter-row">
                    <label>Year<input type="number" name="year" min="2020" max="2100" value="<?php echo $formYear; ?>" required></label>
                    <label>Month<select name="month" required>
                        <?php for ($month = 1; $month <= 12; $month++): ?>
                            <option value="<?php echo $month; ?>" <?php echo $formMonth === $month ? 'selected' : ''; ?>><?php echo cpmsNewsletterEscape(date('F', mktime(0,0,0,$month,1))); ?></option>
                        <?php endfor; ?>
                    </select></label>
                </div>
                <label>Title<input type="text" name="title" maxlength="220" value="<?php echo cpmsNewsletterEscape($selected['title'] ?? ''); ?>" placeholder="Example: V23 Monthly Newsletter - August 2026"></label>
                <label>Property Manager Message<textarea name="manager_message" rows="5" placeholder="A short message to residents..."><?php echo cpmsNewsletterEscape($selected['manager_message'] ?? ''); ?></textarea></label>
                <label>Monthly Highlights<textarea name="highlights" rows="6" placeholder="Completed work, activities, improvements and key achievements..."><?php echo cpmsNewsletterEscape($selected['highlights'] ?? ''); ?></textarea></label>
                <label>Upcoming Activities / Plans<textarea name="upcoming_activities" rows="5" placeholder="Activities and work planned for the coming month..."><?php echo cpmsNewsletterEscape($selected['upcoming_activities'] ?? ''); ?></textarea></label>
                <button class="newsletter-primary" type="submit">Simpan Draft</button>
            </form>

            <?php if ($selected): ?>
                <div class="newsletter-actions">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo cpmsNewsletterEscape(cpmsNewsletterCsrf()); ?>">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="newsletter_id" value="<?php echo (int) $selected['id']; ?>">
                        <button type="button" onclick="window.open('newsletter_review.php?id=<?php echo (int) $selected['id']; ?>', '_blank', 'noopener')">Preview / Review PDF</button>
                        <button name="status" value="review">Send for Review</button>
                        <button class="publish" name="status" value="published">Publish</button>
                        <button name="status" value="archived">Archive</button>
                    </form>
                </div>

                <div class="newsletter-media-box">
                    <h3>Newsletter Photos</h3>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo cpmsNewsletterEscape(cpmsNewsletterCsrf()); ?>">
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="newsletter_id" value="<?php echo (int) $selected['id']; ?>">
                        <input type="file" name="newsletter_image" accept="image/jpeg,image/png,image/webp" required>
                        <input type="text" name="caption" maxlength="255" placeholder="Photo caption">
                        <button type="submit">Upload Photo</button>
                    </form>
                    <?php if ($media): ?>
                        <div class="newsletter-gallery">
                            <?php foreach ($media as $image): ?>
                                <figure>
                                    <img src="../<?php echo cpmsNewsletterEscape($image['file_path']); ?>" alt="Newsletter photo">
                                    <figcaption><?php echo cpmsNewsletterEscape($image['caption']); ?></figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <aside class="newsletter-panel newsletter-library">
            <div class="newsletter-panel-head">
                <div><span class="newsletter-kicker">ARCHIVE</span><h2>Property Newsletters</h2></div>
            </div>
            <?php if (!$newsletters): ?>
                <div class="newsletter-empty">No Monthly Newsletter has been created for this property yet.</div>
            <?php else: ?>
                <?php foreach ($newsletters as $item): ?>
                    <a class="newsletter-item <?php echo (int) $item['id'] === $selectedId ? 'active' : ''; ?>" href="newsletter.php?id=<?php echo (int) $item['id']; ?>">
                        <span class="newsletter-month"><?php echo cpmsNewsletterEscape(date('M', mktime(0,0,0,(int)$item['newsletter_month'],1))); ?></span>
                        <span class="newsletter-item-copy"><strong><?php echo cpmsNewsletterEscape($item['title']); ?></strong><small><?php echo (int) $item['newsletter_year']; ?> · <?php echo cpmsNewsletterEscape(ucfirst($item['status'])); ?></small></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
