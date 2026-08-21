<?php
declare(strict_types=1);

/**
 * Production hotfix (real-device forensic-trace follow-up): a live cPanel
 * deployment logged "Call to undefined function cpmsApiColumnExists()"
 * from cpmsApiTaskRows() below. bootstrap.php DOES define cpmsApiColumnExists()/
 * cpmsApiTableExists() (see cpms/api/v1/bootstrap.php) and every endpoint
 * that calls into this file reaches it only via bootstrap.php's own
 * `require_once __DIR__ . '/services.php'`, so in a fully up-to-date
 * deployment both would already be defined before this file ever runs.
 * The fatal error means the live bootstrap.php on that server is an
 * older copy that predates those two helpers — confirmed by searching
 * the whole repo for any equivalent under another name: every other
 * legacy page (staff_work_submit.php's staffWorkColumnExists(),
 * staff_work_history.php's staffHistoryColumnExists(),
 * daily_work_review.php's dailyWorkColumnExists(), admin_daily_work.php's
 * adminDailyWorkColumnExists()) already independently defines its own
 * private copy for exactly this reason — this codebase's own established
 * pattern is "don't assume a shared helper is loaded; guard it" (see
 * cpmsApiBrandingAssetUrl()'s own doc comment below). Rather than require
 * re-deploying bootstrap.php (an unrelated, unverified production file
 * this pass was never asked to touch), guarantee both helpers exist here
 * too, guarded so they never conflict with bootstrap.php's own versions
 * when it does have them.
 */
if (!function_exists('cpmsApiTableExists')) {
    function cpmsApiTableExists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        $result = $db->query("SHOW TABLES LIKE '{$safe}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}

if (!function_exists('cpmsApiColumnExists')) {
    function cpmsApiColumnExists(mysqli $db, string $table, string $column): bool
    {
        $safeTable = $db->real_escape_string($table);
        $safeColumn = $db->real_escape_string($column);
        $result = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}

/**
 * Turns a cpms_properties.logo_path value (stored relative, e.g.
 * "images/logo.png" or "uploads/branding/xyz.png") into a root-relative
 * URL the mobile app can load directly, using the same "/cpms/<path>"
 * convention this API already uses for evidence photo URLs
 * (see cpmsApiSaveImage() callers). Deliberately self-contained rather
 * than reusing cpms/core/branding.php's cpmsBrandingAssetUrl() helper,
 * since that module isn't guaranteed to be loaded on every request path
 * (see mobile/docs/BACKEND_INTEGRATION_AUDIT.md, S-M3-adjacent note).
 */
function cpmsApiBrandingAssetUrl(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return '/cpms/' . ltrim($path, '/');
}

function cpmsApiLocation(array $input): array
{
    $location = isset($input['location']) && is_array($input['location'])
        ? $input['location'] : $input;
    return [
        'latitude' => isset($location['latitude'])
            ? (float) $location['latitude'] : 999.0,
        'longitude' => isset($location['longitude'])
            ? (float) $location['longitude'] : 999.0,
        'accuracy' => isset($location['accuracy'])
            ? (float) $location['accuracy'] : 0.0,
    ];
}

function cpmsApiCoordinatesValid(array $location): bool
{
    return $location['latitude'] >= -90.0
        && $location['latitude'] <= 90.0
        && $location['longitude'] >= -180.0
        && $location['longitude'] <= 180.0
        && $location['accuracy'] > 0.0
        && $location['accuracy'] <= 5000.0;
}

function cpmsApiDistance(
    float $latitude1,
    float $longitude1,
    float $latitude2,
    float $longitude2
): float {
    $earthRadius = 6371000.0;
    $latDelta = deg2rad($latitude2 - $latitude1);
    $lngDelta = deg2rad($longitude2 - $longitude1);
    $value = sin($latDelta / 2) * sin($latDelta / 2)
        + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2))
        * sin($lngDelta / 2) * sin($lngDelta / 2);
    $value = min(1.0, max(0.0, $value));
    return $earthRadius * 2 * atan2(sqrt($value), sqrt(1 - $value));
}

function cpmsApiValidateWorkLocation(
    mysqli $db,
    int $propertyId,
    array $input,
    bool $enforce = true
): array {
    $location = cpmsApiLocation($input);
    if (!cpmsApiCoordinatesValid($location)) {
        cpmsApiError('INVALID_LOCATION', 'Bacaan lokasi GPS tidak sah.', 422);
    }

    $stmt = $db->prepare(
        'SELECT latitude,longitude,radius_m,maximum_accuracy_m,
                enforcement_enabled
         FROM cpms_property_geofences WHERE property_id=? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare geofence lookup.');
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $geofence = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($geofence) && !$enforce) {
        $location['distance'] = null;
        $location['radius'] = null;
        $location['maximum_accuracy'] = null;
        return $location;
    }
    if (!is_array($geofence)) {
        cpmsApiError(
            'GEOFENCE_NOT_CONFIGURED',
            'Lokasi tempat kerja belum ditetapkan oleh Property Admin.',
            409
        );
    }

    $distance = cpmsApiDistance(
        (float) $location['latitude'],
        (float) $location['longitude'],
        (float) $geofence['latitude'],
        (float) $geofence['longitude']
    );
    $location['distance'] = round($distance, 2);
    $location['radius'] = (int) $geofence['radius_m'];
    $location['maximum_accuracy'] = (int) $geofence['maximum_accuracy_m'];

    if ($enforce && (int) $geofence['enforcement_enabled'] === 1) {
        if ($location['accuracy'] > (float) $geofence['maximum_accuracy_m']) {
            cpmsApiError(
                'POOR_GPS_ACCURACY',
                'Ketepatan GPS terlalu rendah. Bergerak ke kawasan terbuka dan cuba semula.',
                422,
                ['accuracy_m' => round($location['accuracy'], 2)]
            );
        }
        if ($distance > (float) $geofence['radius_m']) {
            cpmsApiError(
                'OUTSIDE_GEOFENCE',
                'Anda berada di luar kawasan tempat kerja.',
                422,
                ['distance_m' => round($distance, 2)]
            );
        }
    }
    return $location;
}

function cpmsApiAttendanceAudit(
    mysqli $db,
    array $identity,
    string $action,
    bool $accepted,
    string $reason,
    array $location
): void {
    $propertyId = (int) $identity['property_id'];
    $userId = (int) $identity['system_user_id'];
    $acceptedValue = $accepted ? 1 : 0;
    $ip = cpmsApiClientIp();
    $agent = cpmsApiUserAgent();
    $latitude = (float) $location['latitude'];
    $longitude = (float) $location['longitude'];
    $accuracy = (float) $location['accuracy'];
    $distance = isset($location['distance'])
        ? (float) $location['distance'] : null;
    $stmt = $db->prepare(
        'INSERT INTO cpms_attendance_events
         (property_id,system_user_id,action_type,accepted,reason_code,
          latitude,longitude,accuracy_m,distance_m,ip_address,user_agent)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'iisisddddss',
        $propertyId,
        $userId,
        $action,
        $acceptedValue,
        $reason,
        $latitude,
        $longitude,
        $accuracy,
        $distance,
        $ip,
        $agent
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsApiResolveShift(mysqli $db, array $identity): ?array
{
    $service = cpmsApiRoot() . '/cpms/includes/attendance_shift_service.php';
    if (is_file($service)) {
        require_once $service;
    }
    if (!function_exists('cpmsShiftResolve')) {
        return null;
    }
    return cpmsShiftResolve(
        $db,
        (int) $identity['property_id'],
        (int) $identity['system_user_id'],
        date('Y-m-d')
    );
}

function cpmsApiShiftLabel(mysqli $db, array $identity): string
{
    $shift = cpmsApiResolveShift($db, $identity);
    if (!is_array($shift)) {
        return 'Tiada syif ditetapkan';
    }
    $start = date('g:i A', strtotime((string) $shift['start_time']));
    $end = date('g:i A', strtotime((string) $shift['end_time']));
    return $start . ' – ' . $end;
}

function cpmsApiAttendanceState(mysqli $db, array $identity): string
{
    $propertyId = (int) $identity['property_id'];
    $userId = (int) $identity['system_user_id'];
    $stmt = $db->prepare(
        "SELECT id FROM cpms_attendance_sessions
         WHERE property_id=? AND system_user_id=? AND status='Open'
         LIMIT 1"
    );
    if (!$stmt) {
        return 'out';
    }
    $stmt->bind_param('ii', $propertyId, $userId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $found ? 'in' : 'out';
}

function cpmsApiStaffId(array $identity): int
{
    if ((string) $identity['source_table'] !== 'staff'
        || (int) $identity['source_id'] < 1) {
        cpmsApiError(
            'STAFF_PROFILE_MISSING',
            'Profil Staff belum dipadankan dengan akaun CPMS.',
            409
        );
    }
    return (int) $identity['source_id'];
}

function cpmsApiGuardId(array $identity): int
{
    if ((string) $identity['source_table'] !== 'security_guards'
        || (int) $identity['source_id'] < 1) {
        cpmsApiError(
            'GUARD_PROFILE_MISSING',
            'Profil Security belum dipadankan dengan akaun CPMS.',
            409
        );
    }
    return (int) $identity['source_id'];
}

function cpmsApiTaskStatus(string $status): string
{
    $value = strtolower(trim($status));
    if ($value === 'in progress') {
        return 'in_progress';
    }
    if ($value === 'completed' || $value === 'verified') {
        return 'completed';
    }
    return 'pending';
}

function cpmsApiTaskRows(mysqli $db, array $identity, int $limit = 20): array
{
    $propertyId = (int) $identity['property_id'];
    $staffId = cpmsApiStaffId($identity);
    $limit = max(1, min(100, $limit));

    // Surface the *reason* a work order that looks "back in progress" was
    // actually reopened — the Property Admin's Reject decision now flips
    // work_orders.status back to "In Progress" (see
    // cpms/property_portal/daily_work_review.php) rather than leaving it
    // stuck as "Completed" forever with no way for staff to act on it.
    // Without this, a rejected-then-reopened work order would look
    // identical to any other in-progress task, and the reason would only
    // ever be visible in the separate Work Order History screen.
    $hasWorkOrderId = cpmsApiColumnExists($db, 'daily_work_logs', 'work_order_id');
    $rejectionSelect = "NULL AS rejection_reason,";
    if ($hasWorkOrderId) {
        $hasSupervisorRemarks = cpmsApiColumnExists($db, 'daily_work_logs', 'supervisor_remarks');
        $remarksExpr = $hasSupervisorRemarks ? 'dw.supervisor_remarks' : 'NULL';
        $rejectionSelect = "(SELECT {$remarksExpr}
                 FROM daily_work_logs dw
                 WHERE dw.work_order_id = work_orders.id
                 ORDER BY dw.id DESC LIMIT 1
                ) AS latest_dw_remarks,
                (SELECT dw.work_status
                 FROM daily_work_logs dw
                 WHERE dw.work_order_id = work_orders.id
                 ORDER BY dw.id DESC LIMIT 1
                ) AS latest_dw_status,";
    }

    $stmt = $db->prepare(
        "SELECT id,work_order_reference,title,block_location,
                specific_location,due_date,status,priority,
                {$rejectionSelect}
                (SELECT work_order_images.image_name
                 FROM work_order_images
                 WHERE work_order_images.work_order_id=work_orders.id
                 ORDER BY work_order_images.id DESC LIMIT 1) AS latest_image
         FROM work_orders
         WHERE property_id=? AND assigned_staff_id=?
           AND status NOT IN ('Verified','Cancelled')
         ORDER BY
           CASE status WHEN 'In Progress' THEN 1 WHEN 'Assigned' THEN 2
                       WHEN 'Open' THEN 3 WHEN 'Completed' THEN 4 ELSE 5 END,
           COALESCE(due_date,'9999-12-31'),id DESC
         LIMIT {$limit}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Staff task list.');
    }
    $stmt->bind_param('ii', $propertyId, $staffId);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = [];
    while ($row = $result->fetch_assoc()) {
        $location = trim((string) $row['block_location']);
        $specific = trim((string) ($row['specific_location'] ?? ''));
        if ($specific !== '' && strcasecmp($specific, $location) !== 0) {
            $location .= ' – ' . $specific;
        }
        $due = trim((string) ($row['due_date'] ?? ''));
        $imagePath = ltrim(trim((string) ($row['latest_image'] ?? '')), '/');
        // Only surface the rejection reason while it's actually the
        // *latest* decision on this work order — a subsequent successful
        // resubmission naturally clears it (latest_dw_status moves on).
        $latestDwStatus = (string) ($row['latest_dw_status'] ?? '');
        $rejectionReason = $latestDwStatus === 'Rejected'
            ? trim((string) ($row['latest_dw_remarks'] ?? ''))
            : '';
        $tasks[] = [
            'database_id' => (int) $row['id'],
            'id' => (string) $row['work_order_reference'],
            'title' => (string) $row['title'],
            'location' => $location,
            'due' => $due !== '' ? date('d/m/Y', strtotime($due)) : '-',
            'status' => cpmsApiTaskStatus((string) $row['status']),
            'priority' => (string) $row['priority'],
            'image_url' => $imagePath !== '' ? '/cpms/' . $imagePath : '',
            'rejection_reason' => $rejectionReason !== '' ? $rejectionReason : null,
        ];
    }
    $stmt->close();
    return $tasks;
}

function cpmsApiActivePatrol(mysqli $db, array $identity): ?array
{
    $propertyId = (int) $identity['property_id'];
    $guardId = cpmsApiGuardId($identity);
    $stmt = $db->prepare(
        "SELECT id,patrol_reference,started_at
         FROM cpms_workforce_patrol_sessions
         WHERE property_id=? AND guard_id=? AND session_status='Active'
         ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare patrol session lookup.');
    }
    $stmt->bind_param('ii', $propertyId, $guardId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

function cpmsApiPatrolReference(): string
{
    return 'SP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function cpmsApiSaveImage(
    array $file,
    string $relativeDirectory,
    int $maximumBytes = 5242880
): string {
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Saiz gambar melebihi had upload server.',
            UPLOAD_ERR_FORM_SIZE => 'Saiz gambar melebihi had borang.',
            UPLOAD_ERR_PARTIAL => 'Upload gambar tidak lengkap. Cuba semula.',
            UPLOAD_ERR_NO_FILE => 'Tiada gambar diterima oleh server.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara server tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis fail gambar.',
            UPLOAD_ERR_EXTENSION => 'Upload gambar dihentikan oleh konfigurasi server.',
        ];
        cpmsApiError(
            'IMAGE_UPLOAD_FAILED',
            $messages[$uploadError] ?? 'Gambar tidak dapat dimuat naik.',
            422,
            ['upload_error' => $uploadError]
        );
    }
    if ((int) ($file['size'] ?? 0) < 1
        || (int) $file['size'] > $maximumBytes) {
        cpmsApiError('IMAGE_SIZE_INVALID', 'Saiz gambar maksimum ialah 5 MB.', 422);
    }
    $temporary = (string) ($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($temporary);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        cpmsApiError('IMAGE_TYPE_INVALID', 'Gunakan gambar JPG, PNG atau WebP.', 422);
    }
    $relativeDirectory = trim($relativeDirectory, '/');
    $directory = cpmsApiRoot() . '/' . $relativeDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException('Unable to create image upload directory.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($temporary, $directory . '/' . $filename)) {
        throw new RuntimeException('Unable to store uploaded image.');
    }
    return $relativeDirectory . '/' . $filename;
}

/**
 * Canonical, mobile-safe absolute-path resolver for any uploaded evidence
 * image. Given an ordered list of candidate paths (each relative to the
 * site root — one level ABOVE cpmsApiRoot()/"cpms/", matching how
 * cpms/property_portal/daily_work_review.php's dailyWorkImageUrl() and
 * staff_work_history.php's staffHistoryImageUrl() already resolve real
 * production photos), this verifies each candidate against the real
 * filesystem with is_file() and returns the first one that actually
 * exists, encoded as a root-relative URL path (e.g.
 * "/cpms/uploads/daily_work/xxx.jpg" or "/uploads/daily_work/property_5/xxx.jpg").
 *
 * Every cpms/api/v1 endpoint that turns a stored image row into a URL for
 * Flutter MUST go through this (plus a per-table candidate builder below)
 * instead of hand-building a path — that duplication is exactly what
 * caused Work Order History's Before/After photos to 404 on real devices:
 * daily_work_images is written by two different upload flows with two
 * different physical layouts (see cpmsApiDailyWorkImageCandidates below),
 * and only one of them was ever checked.
 */
function cpmsApiResolveUploadedImageUrl(array $candidates): string
{
    $siteRoot = dirname(cpmsApiRoot());
    $clean = [];
    foreach ($candidates as $candidate) {
        $candidate = ltrim(trim((string) $candidate), '/');
        if ($candidate !== '') {
            $clean[] = $candidate;
        }
    }
    if (!$clean) {
        return '';
    }
    $chosen = null;
    foreach ($clean as $candidate) {
        if (is_file($siteRoot . '/' . $candidate)) {
            $chosen = $candidate;
            break;
        }
    }
    // No candidate exists on disk (e.g. a staging DB without the real
    // upload tree) — fall back to the first, most-likely guess rather than
    // silently dropping the photo, matching the legacy pages' own
    // behaviour (they return their first candidate too).
    $chosen ??= $clean[0];
    return '/' . implode('/', array_map('rawurlencode', explode('/', $chosen)));
}

/**
 * Candidate physical paths for a daily_work_images row, in priority order.
 * Confirmed by reading both real upload code paths, not guessed:
 *  - staff_work_submit.php (legacy Staff Web Portal, still live in
 *    production — linked from staff_dashboard.php's "Add Daily Work") saves
 *    to <site_root>/uploads/daily_work/property_<id>/<name> and populates
 *    image_path with that exact relative path whenever the column exists.
 *  - cpms/api/v1/staff/daily-work/submit.php (this mobile app's own
 *    endpoint) saves to <site_root>/cpms/uploads/daily_work/<name> — flat,
 *    no property subfolder — and (before this fix) never populated
 *    image_path at all.
 * A Work Order History screen that only ever checked the second layout
 * would 404 on every photo submitted through the still-active legacy web
 * flow — which is the confirmed root cause of the real-device bug report.
 */
function cpmsApiDailyWorkImageCandidates(array $image, int $propertyId): array
{
    $imagePath = trim((string) ($image['image_path'] ?? ''));
    $name = basename((string) ($image['image_name'] ?? ''));
    $candidates = [];
    if ($imagePath !== '') {
        $candidates[] = $imagePath;
    }
    if ($name !== '') {
        $candidates[] = 'uploads/daily_work/property_' . $propertyId . '/' . $name;
        $candidates[] = 'uploads/daily_work/' . $name;
        $candidates[] = 'cpms/uploads/daily_work/property_' . $propertyId . '/' . $name;
        $candidates[] = 'cpms/uploads/daily_work/' . $name;
    }
    return $candidates;
}

function cpmsApiDailyWorkImageUrl(array $image, int $propertyId): string
{
    return cpmsApiResolveUploadedImageUrl(cpmsApiDailyWorkImageCandidates($image, $propertyId));
}

/**
 * work_order_images.image_name already stores the FULL relative path
 * returned by cpmsApiSaveImage() (e.g. "uploads/work_orders/property_5/
 * xxx.jpg"), relative to cpmsApiRoot() ("cpms/") — there is only one
 * writer (staff/task-photo.php), confirmed by a repository-wide search, so
 * there is no dual-layout ambiguity here. Still routed through the same
 * canonical resolver so every endpoint agrees on one verification path.
 */
function cpmsApiWorkOrderImageUrl(array $image): string
{
    $name = trim((string) ($image['image_name'] ?? ''), '/');
    if ($name === '') {
        return '';
    }
    return cpmsApiResolveUploadedImageUrl(['cpms/' . $name]);
}
