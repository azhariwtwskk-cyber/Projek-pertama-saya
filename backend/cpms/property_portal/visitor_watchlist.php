<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
cpmsRequire('visitor.watchlist.view', $conn);
if (!cpmsModuleEnabled('visitor_management', $currentPropertyModules)) {
    http_response_code(403);
    exit('Visitor Management is not enabled for this property.');
}

function cpmsWatchlistEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsWatchlistRedirect(): void
{
    header('Location: visitor_watchlist.php');
    exit;
}

$canManageWatchlist = cpmsCan('visitor.watchlist.manage', $conn);
$actorUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canManageWatchlist) {
            throw new RuntimeException('Anda tiada kebenaran mengurus watchlist.');
        }
        if (!propertyPortalVerifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Token keselamatan tidak sah.');
        }
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'add') {
            $personName = trim((string) ($_POST['person_name'] ?? ''));
            $visitorPhone = trim((string) ($_POST['visitor_phone'] ?? ''));
            $vehicleNo = strtoupper(trim((string) ($_POST['vehicle_no'] ?? '')));
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $riskLevel = trim((string) ($_POST['risk_level'] ?? 'Medium'));
            if (
                ($personName === '' && $visitorPhone === '' && $vehicleNo === '')
                || $reason === ''
                || strlen($personName) > 180
                || strlen($visitorPhone) > 40
                || strlen($vehicleNo) > 30
                || strlen($reason) > 500
                || !in_array($riskLevel, ['Low', 'Medium', 'High'], true)
            ) {
                throw new RuntimeException(
                    'Masukkan sekurang-kurangnya nama, telefon atau kenderaan serta alasan yang sah.'
                );
            }
            $stmt = $conn->prepare(
                "INSERT INTO cpms_visitor_watchlist (
                    property_id,person_name,visitor_phone,vehicle_no,
                    reason,risk_level,created_by_system_user_id,
                    updated_by_system_user_id
                 ) VALUES (
                    ?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),?,?,
                    NULLIF(?,0),NULLIF(?,0)
                 )"
            );
            if (!$stmt) {
                throw new RuntimeException('Watchlist insert failed.');
            }
            $stmt->bind_param(
                'isssssii',
                $currentPropertyId,
                $personName,
                $visitorPhone,
                $vehicleNo,
                $reason,
                $riskLevel,
                $actorUserId,
                $actorUserId
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Watchlist insert failed.');
            }
            $stmt->close();
            $_SESSION['visitor_watchlist_flash'] = [
                'type' => 'success',
                'message' => 'Rekod watchlist telah ditambah.',
            ];
        } elseif ($action === 'toggle') {
            $entryId = (int) ($_POST['entry_id'] ?? 0);
            $newActive = (int) ($_POST['new_active'] ?? -1);
            if ($entryId < 1 || !in_array($newActive, [0, 1], true)) {
                throw new RuntimeException('Tindakan watchlist tidak sah.');
            }
            $stmt = $conn->prepare(
                "UPDATE cpms_visitor_watchlist SET active=?,
                    updated_by_system_user_id=NULLIF(?,0),
                    deactivated_at=CASE WHEN ?=1 THEN NULL ELSE NOW() END
                 WHERE id=? AND property_id=?"
            );
            if (!$stmt) {
                throw new RuntimeException('Watchlist update failed.');
            }
            $stmt->bind_param(
                'iiiii',
                $newActive,
                $actorUserId,
                $newActive,
                $entryId,
                $currentPropertyId
            );
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Rekod watchlist tidak ditemui.');
            }
            $stmt->close();
            $_SESSION['visitor_watchlist_flash'] = [
                'type' => 'success',
                'message' => $newActive === 1
                    ? 'Rekod watchlist diaktifkan semula.'
                    : 'Rekod watchlist dinyahaktifkan.',
            ];
        } else {
            throw new RuntimeException('Tindakan watchlist tidak sah.');
        }
    } catch (Throwable $exception) {
        $_SESSION['visitor_watchlist_flash'] = [
            'type' => 'danger',
            'message' => $exception->getMessage(),
        ];
    }
    cpmsWatchlistRedirect();
}

$filter = trim((string) ($_GET['status'] ?? 'Active'));
if (!in_array($filter, ['Active', 'Inactive', 'All'], true)) {
    $filter = 'Active';
}
$search = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT * FROM cpms_visitor_watchlist WHERE property_id=?";
$hasStatus = $filter !== 'All';
$hasSearch = $search !== '';
if ($hasStatus) {
    $sql .= ' AND active=?';
    $activeValue = $filter === 'Active' ? 1 : 0;
}
if ($hasSearch) {
    $sql .= " AND (person_name LIKE ? OR visitor_phone LIKE ?
                    OR vehicle_no LIKE ? OR reason LIKE ?)";
    $like = '%' . $search . '%';
}
$sql .= " ORDER BY active DESC,FIELD(risk_level,'High','Medium','Low'),
           updated_at DESC LIMIT 500";
$stmt = $conn->prepare($sql);
$entries = [];
if ($stmt) {
    if ($hasStatus && $hasSearch) {
        $stmt->bind_param(
            'iissss',
            $currentPropertyId,
            $activeValue,
            $like,
            $like,
            $like,
            $like
        );
    } elseif ($hasStatus) {
        $stmt->bind_param('ii', $currentPropertyId, $activeValue);
    } elseif ($hasSearch) {
        $stmt->bind_param(
            'issss',
            $currentPropertyId,
            $like,
            $like,
            $like,
            $like
        );
    } else {
        $stmt->bind_param('i', $currentPropertyId);
    }
    if ($stmt->execute()) {
        $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

$flash = $_SESSION['visitor_watchlist_flash'] ?? null;
unset($_SESSION['visitor_watchlist_flash']);
$pageTitle = 'Visitor Watchlist';
$activeMenu = 'visitors';
$pageStyles = ['assets/visitor-management.css?v=3600'];
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<section class="visitor-admin-heading"><div><span class="visitor-admin-label">RESTRICTED · PROPERTY SCOPED</span><h1>Visitor Watchlist</h1><p>Amaran ini hanya digunakan oleh pengurusan dan Security untuk <?php echo cpmsWatchlistEscape($currentPropertyName); ?>.</p></div><a href="visitors.php">← Visitor Register</a></section>
<?php if (is_array($flash)): ?><div class="visitor-admin-alert visitor-admin-alert--<?php echo cpmsWatchlistEscape((string) ($flash['type'] ?? 'success')); ?>"><?php echo cpmsWatchlistEscape((string) ($flash['message'] ?? '')); ?></div><?php endif; ?>
<?php if ($canManageWatchlist): ?>
<section class="visitor-admin-panel visitor-watchlist-create"><div class="visitor-admin-panel-head"><div><span class="visitor-admin-label">NEW ALERT</span><h2>Tambah Rekod Watchlist</h2></div></div><form method="post" class="visitor-watchlist-form"><input type="hidden" name="csrf_token" value="<?php echo cpmsWatchlistEscape(propertyPortalCsrfToken()); ?>"><input type="hidden" name="action" value="add"><label>Nama<input name="person_name" maxlength="180"></label><label>Telefon<input name="visitor_phone" maxlength="40"></label><label>No. Kenderaan<input name="vehicle_no" maxlength="30"></label><label>Tahap Risiko<select name="risk_level"><option>Low</option><option selected>Medium</option><option>High</option></select></label><label class="visitor-watchlist-wide">Alasan *<textarea name="reason" maxlength="500" rows="3" required></textarea></label><button>Tambah Watchlist</button></form><p class="visitor-watchlist-privacy">Gunakan maklumat minimum. Jangan simpan nombor IC penuh. Alasan tidak dipaparkan kepada resident.</p></section>
<?php endif; ?>
<form class="visitor-admin-filter" method="get"><label>Status<select name="status"><option <?php echo $filter === 'Active' ? 'selected' : ''; ?>>Active</option><option <?php echo $filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option><option <?php echo $filter === 'All' ? 'selected' : ''; ?>>All</option></select></label><label>Carian<input name="q" value="<?php echo cpmsWatchlistEscape($search); ?>" placeholder="Nama, telefon, kenderaan atau alasan"></label><button>Papar</button></form>
<section class="visitor-admin-panel"><div class="visitor-admin-panel-head"><div><span class="visitor-admin-label"><?php echo cpmsWatchlistEscape(strtoupper($filter)); ?></span><h2>Rekod Watchlist</h2></div><span><?php echo count($entries); ?> rekod</span></div><?php if (!$entries): ?><div class="visitor-admin-empty">Tiada rekod watchlist bagi pilihan ini.</div><?php else: ?><div class="visitor-watchlist-list"><?php foreach ($entries as $entry): ?><article class="visitor-watchlist-card risk-<?php echo cpmsWatchlistEscape(strtolower((string) $entry['risk_level'])); ?> <?php echo (int) $entry['active'] === 1 ? 'is-active' : 'is-inactive'; ?>"><div><span><?php echo cpmsWatchlistEscape($entry['risk_level']); ?> RISK</span><h3><?php echo cpmsWatchlistEscape($entry['person_name'] ?: 'Nama tidak direkod'); ?></h3><p><?php echo cpmsWatchlistEscape($entry['vehicle_no'] ?: ($entry['visitor_phone'] ?: '-')); ?></p></div><p class="visitor-watchlist-reason"><?php echo cpmsWatchlistEscape($entry['reason']); ?></p><small><?php echo (int) $entry['active'] === 1 ? 'ACTIVE' : 'INACTIVE'; ?> · <?php echo cpmsWatchlistEscape(date('d/m/Y H:i', strtotime((string) $entry['updated_at']))); ?></small><?php if ($canManageWatchlist): ?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo cpmsWatchlistEscape(propertyPortalCsrfToken()); ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="entry_id" value="<?php echo (int) $entry['id']; ?>"><input type="hidden" name="new_active" value="<?php echo (int) $entry['active'] === 1 ? 0 : 1; ?>"><button><?php echo (int) $entry['active'] === 1 ? 'Nyahaktif' : 'Aktifkan Semula'; ?></button></form><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
