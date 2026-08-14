<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
cpmsRequire('resident.announcement.manage', $conn);

function announcementManageFlash(string $type, string $message): void
{
    $_SESSION['announcement_manage_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function announcementManageRedirect(): void
{
    header('Location: announcements_manage.php');
    exit;
}

function announcementManageDateTime(
    string $value,
    bool $required
): ?string {
    $value = trim($value);
    if ($value === '') {
        if ($required) {
            throw new RuntimeException('Tarikh mula penerbitan diperlukan.');
        }
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new RuntimeException('Format tarikh tidak sah.');
    }
    return date('Y-m-d H:i:s', $timestamp);
}

$allowedNoticeTypes = [
    'General',
    'Maintenance',
    'Event',
    'Emergency',
];
$allowedPriorities = ['Low', 'Normal', 'High', 'Critical'];
$allowedStatuses = ['Draft', 'Published', 'Archived'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!propertyPortalVerifyCsrf(
        (string) ($_POST['csrf_token'] ?? '')
    )) {
        announcementManageFlash(
            'danger',
            'Token keselamatan tidak sah. Sila cuba semula.'
        );
        announcementManageRedirect();
    }

    try {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'create') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));
            $noticeType = trim(
                (string) ($_POST['notice_type'] ?? 'General')
            );
            $priority = trim(
                (string) ($_POST['priority'] ?? 'Normal')
            );
            $status = trim(
                (string) ($_POST['status'] ?? 'Draft')
            );
            $requiresConfirmation = isset(
                $_POST['requires_confirmation']
            ) ? 1 : 0;
            $publishFrom = announcementManageDateTime(
                (string) ($_POST['publish_from'] ?? ''),
                true
            );
            $publishUntil = announcementManageDateTime(
                (string) ($_POST['publish_until'] ?? ''),
                false
            );

            if (
                $title === ''
                || strlen($title) > 180
                || $message === ''
                || strlen($message) > 10000
                || !in_array($noticeType, $allowedNoticeTypes, true)
                || !in_array($priority, $allowedPriorities, true)
                || !in_array($status, ['Draft', 'Published'], true)
            ) {
                throw new RuntimeException(
                    'Maklumat announcement tidak lengkap atau tidak sah.'
                );
            }

            if (
                $publishUntil !== null
                && $publishUntil < (string) $publishFrom
            ) {
                throw new RuntimeException(
                    'Tarikh tamat mesti selepas tarikh mula.'
                );
            }

            $actorUserId = (int) (
                $_SESSION['cpms_user_id'] ?? 0
            );
            $stmt = $conn->prepare(
                "INSERT INTO cpms_resident_announcements (
                    property_id,title,message,notice_type,priority,
                    requires_confirmation,publish_from,publish_until,
                    status,created_by_system_user_id
                 ) VALUES (
                    ?,?,?,?,?,?,?,NULLIF(?,''),?,NULLIF(?,0)
                 )"
            );
            if (!$stmt) {
                throw new RuntimeException(
                    'Announcement tidak dapat disediakan.'
                );
            }
            $publishUntilValue = $publishUntil ?? '';
            $stmt->bind_param(
                'issssisssi',
                $currentPropertyId,
                $title,
                $message,
                $noticeType,
                $priority,
                $requiresConfirmation,
                $publishFrom,
                $publishUntilValue,
                $status,
                $actorUserId
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException(
                    'Announcement tidak dapat disimpan.'
                );
            }
            $stmt->close();
            announcementManageFlash(
                'success',
                $status === 'Published'
                    ? 'Announcement telah diterbitkan.'
                    : 'Announcement telah disimpan sebagai Draft.'
            );
            announcementManageRedirect();
        }

        if ($action === 'change_status') {
            $announcementId = (int) (
                $_POST['announcement_id'] ?? 0
            );
            $status = trim((string) ($_POST['status'] ?? ''));
            if (
                $announcementId <= 0
                || !in_array($status, $allowedStatuses, true)
            ) {
                throw new RuntimeException('Status announcement tidak sah.');
            }

            $stmt = $conn->prepare(
                "UPDATE cpms_resident_announcements
                 SET status = ?
                 WHERE id = ? AND property_id = ?"
            );
            if (!$stmt) {
                throw new RuntimeException('Status tidak dapat disediakan.');
            }
            $stmt->bind_param(
                'sii',
                $status,
                $announcementId,
                $currentPropertyId
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Status tidak dapat disimpan.');
            }
            if ($stmt->affected_rows < 1) {
                $check = $conn->prepare(
                    "SELECT id FROM cpms_resident_announcements
                     WHERE id=? AND property_id=? LIMIT 1"
                );
                if (!$check) {
                    $stmt->close();
                    throw new RuntimeException(
                        'Announcement tidak dapat disahkan.'
                    );
                }
                $check->bind_param(
                    'ii',
                    $announcementId,
                    $currentPropertyId
                );
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                $check->close();
                if (!$exists) {
                    $stmt->close();
                    throw new RuntimeException(
                        'Announcement tidak dijumpai dalam property ini.'
                    );
                }
            }
            $stmt->close();
            announcementManageFlash(
                'success',
                'Status announcement telah ditukar kepada ' . $status . '.'
            );
            announcementManageRedirect();
        }

        throw new RuntimeException('Tindakan announcement tidak sah.');
    } catch (Throwable $exception) {
        announcementManageFlash('danger', $exception->getMessage());
        announcementManageRedirect();
    }
}

$announcements = [];
$stmt = $conn->prepare(
    "SELECT id,title,message,notice_type,priority,
            requires_confirmation,publish_from,publish_until,status,
            created_at
     FROM cpms_resident_announcements
     WHERE property_id = ?
     ORDER BY created_at DESC,id DESC
     LIMIT 150"
);
if ($stmt) {
    $stmt->bind_param('i', $currentPropertyId);
    $stmt->execute();
    $announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$flash = $_SESSION['announcement_manage_flash'] ?? null;
unset($_SESSION['announcement_manage_flash']);

$pageTitle = 'Announcement Management';
$activeMenu = 'announcements';
$pageStyles = ['assets/delegated-access.css?v=3604'];

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<?php if (is_array($flash)): ?>
    <div class="alert alert-<?php echo propertyPortalEscape(
        (string) ($flash['type'] ?? 'success')
    ); ?>">
        <?php echo propertyPortalEscape(
            (string) ($flash['message'] ?? '')
        ); ?>
    </div>
<?php endif; ?>

<section class="page-heading">
    <div>
        <span class="section-label">RESIDENT COMMUNICATION</span>
        <h1>Announcement Management</h1>
        <p>
            Cipta makluman untuk resident
            <strong><?php echo propertyPortalEscape(
                $currentPropertyName
            ); ?></strong>. Announcement Published boleh dibaca tanpa login.
        </p>
    </div>
    <div class="record-total">
        <span>Records</span>
        <strong><?php echo count($announcements); ?></strong>
    </div>
</section>

<section class="administration-grid">
    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">NEW ANNOUNCEMENT</span>
                <h2>Cipta Announcement</h2>
            </div>
        </div>

        <form method="post" class="announcement-form">
            <input type="hidden"
                   name="csrf_token"
                   value="<?php echo propertyPortalEscape(
                       propertyPortalCsrfToken()
                   ); ?>">
            <input type="hidden" name="action" value="create">

            <div class="field-group">
                <label for="announcement_title">Tajuk</label>
                <input id="announcement_title"
                       name="title"
                       maxlength="180"
                       required>
            </div>

            <div class="field-group">
                <label for="announcement_message">Maklumat</label>
                <textarea id="announcement_message"
                          name="message"
                          maxlength="10000"
                          required></textarea>
            </div>

            <div class="announcement-grid">
                <div class="field-group">
                    <label for="notice_type">Jenis</label>
                    <select id="notice_type" name="notice_type">
                        <?php foreach ($allowedNoticeTypes as $type): ?>
                            <option><?php echo propertyPortalEscape(
                                $type
                            ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <?php foreach ($allowedPriorities as $priority): ?>
                            <option <?php echo $priority === 'Normal'
                                ? 'selected'
                                : ''; ?>>
                                <?php echo propertyPortalEscape(
                                    $priority
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group">
                    <label for="publish_from">Mula Dipaparkan</label>
                    <input id="publish_from"
                           type="datetime-local"
                           name="publish_from"
                           value="<?php echo date('Y-m-d\TH:i'); ?>"
                           required>
                </div>

                <div class="field-group">
                    <label for="publish_until">Tamat (pilihan)</label>
                    <input id="publish_until"
                           type="datetime-local"
                           name="publish_until">
                </div>

                <div class="field-group">
                    <label for="announcement_status">Status</label>
                    <select id="announcement_status" name="status">
                        <option value="Draft">Draft</option>
                        <option value="Published">Published</option>
                    </select>
                </div>

                <label class="announcement-checkbox">
                    <input type="checkbox"
                           name="requires_confirmation"
                           value="1">
                    Minta resident sahkan telah dibaca
                </label>
            </div>

            <button class="button button-primary" type="submit">
                Simpan Announcement
            </button>
        </form>
    </article>

    <article class="panel">
        <div class="panel-heading">
            <div>
                <span class="section-label">WORKFLOW</span>
                <h2>Cara Penggunaan</h2>
            </div>
        </div>
        <div class="role-guide">
            <div>
                <strong>Draft</strong>
                <p>Sediakan dahulu dan semak sebelum dipaparkan.</p>
            </div>
            <div>
                <strong>Published</strong>
                <p>Resident boleh baca dari halaman Announcement awam.</p>
            </div>
            <div>
                <strong>Archived</strong>
                <p>Sembunyikan announcement tanpa memadam rekod.</p>
            </div>
        </div>
    </article>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="section-label">ANNOUNCEMENTS</span>
            <h2>Senarai Terkini</h2>
        </div>
    </div>

    <?php if (!$announcements): ?>
        <div class="delegated-empty">Belum ada announcement.</div>
    <?php else: ?>
        <div class="announcement-list">
            <?php foreach ($announcements as $announcement): ?>
                <article class="announcement-card <?php echo
                    $announcement['notice_type'] === 'Emergency'
                        ? 'emergency'
                        : ''; ?>">
                    <div class="announcement-card-head">
                        <div>
                            <div class="announcement-meta">
                                <span class="announcement-tag">
                                    <?php echo propertyPortalEscape(
                                        (string) $announcement['status']
                                    ); ?>
                                </span>
                                <span class="announcement-tag">
                                    <?php echo propertyPortalEscape(
                                        (string) $announcement['notice_type']
                                    ); ?>
                                </span>
                                <span class="announcement-tag">
                                    <?php echo propertyPortalEscape(
                                        (string) $announcement['priority']
                                    ); ?>
                                </span>
                            </div>
                            <h3><?php echo propertyPortalEscape(
                                (string) $announcement['title']
                            ); ?></h3>
                        </div>
                        <small>
                            <?php echo propertyPortalEscape(
                                date(
                                    'd/m/Y H:i',
                                    strtotime(
                                        (string) $announcement['publish_from']
                                    )
                                )
                            ); ?>
                        </small>
                    </div>

                    <p><?php echo nl2br(propertyPortalEscape(
                        (string) $announcement['message']
                    )); ?></p>

                    <div class="announcement-actions">
                        <form method="post">
                            <input type="hidden"
                                   name="csrf_token"
                                   value="<?php echo propertyPortalEscape(
                                       propertyPortalCsrfToken()
                                   ); ?>">
                            <input type="hidden"
                                   name="action"
                                   value="change_status">
                            <input type="hidden"
                                   name="announcement_id"
                                   value="<?php echo (int) $announcement['id']; ?>">
                            <select name="status">
                                <?php foreach ($allowedStatuses as $status): ?>
                                    <option <?php echo
                                        $announcement['status'] === $status
                                            ? 'selected'
                                            : ''; ?>>
                                        <?php echo propertyPortalEscape(
                                            $status
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="button button-secondary"
                                    type="submit">
                                Tukar Status
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
