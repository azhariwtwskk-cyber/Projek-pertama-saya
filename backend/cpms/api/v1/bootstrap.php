<?php
declare(strict_types=1);

if (defined('CPMS_WORKFORCE_API_BOOTSTRAPPED')) {
    return;
}

define('CPMS_WORKFORCE_API_BOOTSTRAPPED', true);
define('CPMS_WORKFORCE_API_VERSION', '4.1.0');

// Stage 1 auth (see mobile/docs/BACKEND_INTEGRATION_AUDIT.md): access
// tokens stay short-lived, the refresh token is what keeps staff signed
// in across a shift without repeated logins.
define('CPMS_API_ACCESS_TOKEN_TTL_SECONDS', 3600);
define('CPMS_API_REFRESH_TOKEN_TTL_DAYS', 30);

date_default_timezone_set('Asia/Kuala_Lumpur');

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, X-CPMS-Authorization, Content-Type, Accept, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 600');
header('X-Content-Type-Options: nosniff');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function cpmsApiRoot(): string
{
    return dirname(__DIR__, 2);
}

function cpmsApiRespond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        ['ok' => true, 'data' => $data],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function cpmsApiError(
    string $code,
    string $message,
    int $status = 400,
    array $details = []
): void {
    http_response_code($status);
    $error = ['code' => $code, 'message' => $message];
    if ($details) {
        $error['details'] = $details;
    }
    echo json_encode(
        ['ok' => false, 'error' => $error],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

set_exception_handler(static function (Throwable $exception): void {
    error_log('[CPMS Workforce API] ' . $exception->getMessage());
    cpmsApiError(
        'SERVER_ERROR',
        'Server CPMS tidak dapat memproses permintaan ini.',
        500
    );
});

function cpmsApiMethod(string $expected): void
{
    $actual = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($actual !== strtoupper($expected)) {
        header('Allow: ' . strtoupper($expected));
        cpmsApiError('METHOD_NOT_ALLOWED', 'Kaedah permintaan tidak dibenarkan.', 405);
    }
}

function cpmsApiInput(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'multipart/form-data') !== false) {
        return is_array($_POST) ? $_POST : [];
    }

    if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
        $form = is_array($_POST) ? $_POST : [];
        $payloadJson = trim((string) ($form['payload_json'] ?? ''));
        if ($payloadJson === '') {
            return $form;
        }

        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            cpmsApiError('INVALID_JSON', 'Format data JSON tidak sah.', 400);
        }
        return $decoded;
    }

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 1048576) {
        cpmsApiError('PAYLOAD_TOO_LARGE', 'Saiz permintaan terlalu besar.', 413);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        cpmsApiError('INVALID_JSON', 'Format data JSON tidak sah.', 400);
    }
    return $decoded;
}

function cpmsApiClientIp(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function cpmsApiUserAgent(): string
{
    return substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500);
}

function cpmsApiBearerToken(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '') {
        $header = trim((string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }
    if ($header === '') {
        $header = trim((string) ($_SERVER['HTTP_X_CPMS_AUTHORIZATION'] ?? ''));
    }
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0
                    || strcasecmp((string) $name, 'X-CPMS-Authorization') === 0) {
                    $header = trim((string) $value);
                    break;
                }
            }
        }
    }
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,160})$/i', $header, $matches)) {
        cpmsApiError('AUTH_REQUIRED', 'Sila log masuk semula.', 401);
    }
    return (string) $matches[1];
}

function cpmsApiTableExists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$safe}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function cpmsApiColumnExists(mysqli $db, string $table, string $column): bool
{
    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    $result = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function cpmsApiTablesReady(mysqli $db): bool
{
    foreach (['cpms_api_tokens', 'cpms_api_login_attempts',
              'cpms_api_audit_logs', 'cpms_workforce_patrol_sessions',
              'cpms_security_incidents'] as $table) {
        if (!cpmsApiTableExists($db, $table)) {
            return false;
        }
    }
    // Stage 1 (refresh tokens): migration 20260814_0060 adds this column
    // to the already-live cpms_api_tokens table. Treat it the same as a
    // missing table so an un-migrated server fails with a clear
    // API_NOT_INSTALLED error instead of a raw SQL error from login.php
    // or refresh.php trying to write a column that doesn't exist yet.
    if (!cpmsApiColumnExists($db, 'cpms_api_tokens', 'refresh_token_hash')) {
        return false;
    }
    return true;
}

function cpmsApiAudit(
    mysqli $db,
    ?array $identity,
    string $action,
    string $outcome = 'success',
    ?string $entityType = null,
    ?int $entityId = null,
    array $metadata = []
): void {
    if (!cpmsApiTableExists($db, 'cpms_api_audit_logs')) {
        return;
    }
    $propertyId = isset($identity['property_id'])
        ? (int) $identity['property_id'] : null;
    $userId = isset($identity['system_user_id'])
        ? (int) $identity['system_user_id'] : null;
    $ip = cpmsApiClientIp();
    $agent = cpmsApiUserAgent();
    $json = $metadata ? json_encode(
        $metadata,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) : null;
    $stmt = $db->prepare(
        'INSERT INTO cpms_api_audit_logs
         (property_id,system_user_id,action_name,outcome,entity_type,
          entity_id,ip_address,user_agent,metadata_json)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'iisssisss',
        $propertyId,
        $userId,
        $action,
        $outcome,
        $entityType,
        $entityId,
        $ip,
        $agent,
        $json
    );
    $stmt->execute();
    $stmt->close();
}

$cpmsApiDatabaseFile = cpmsApiRoot() . '/db.php';
$cpmsApiUnifiedAuthFile = cpmsApiRoot() . '/includes/unified_auth.php';

if (!is_file($cpmsApiDatabaseFile) || !is_file($cpmsApiUnifiedAuthFile)) {
    cpmsApiError(
        'CPMS_BOOTSTRAP_MISSING',
        'Fail asas CPMS tidak dijumpai pada server.',
        500
    );
}

require_once $cpmsApiDatabaseFile;
require_once $cpmsApiUnifiedAuthFile;

if (!isset($conn) || !($conn instanceof mysqli)) {
    cpmsApiError('DATABASE_UNAVAILABLE', 'Database CPMS tidak tersedia.', 503);
}

$conn->set_charset('utf8mb4');

function cpmsApiDatabase(): mysqli
{
    global $conn;
    return $conn;
}

function cpmsApiAuth(): array
{
    static $identity = null;
    if (is_array($identity)) {
        return $identity;
    }

    $db = cpmsApiDatabase();
    if (!cpmsApiTablesReady($db)) {
        cpmsApiError(
            'API_NOT_INSTALLED',
            'Jalankan migration CPMS Workforce v4.1.0 terlebih dahulu.',
            503
        );
    }

    $token = cpmsApiBearerToken();
    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare(
        "SELECT t.id AS token_id,t.system_user_id,t.property_id,t.role_name,
                t.expires_at,su.username,su.full_name,su.status AS user_status,
                su.source_table,su.source_id,p.property_code,p.property_name,
                p.company_name,p.logo_path,p.primary_color,p.secondary_color,
                p.timezone_name,p.is_active AS property_active
         FROM cpms_api_tokens t
         JOIN system_users su ON su.id=t.system_user_id
         JOIN cpms_properties p ON p.id=t.property_id
         WHERE t.token_hash=? AND t.revoked_at IS NULL
           AND t.expires_at>NOW()
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare API token lookup.');
    }
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)
        || (string) $row['user_status'] !== 'active'
        || (int) $row['property_active'] !== 1) {
        cpmsApiError('SESSION_EXPIRED', 'Sesi telah tamat. Sila log masuk semula.', 401);
    }

    $userId = (int) $row['system_user_id'];
    $propertyId = (int) $row['property_id'];
    $role = strtolower((string) $row['role_name']);
    if (!in_array($role, ['staff', 'security'], true)) {
        cpmsApiError('ROLE_NOT_ALLOWED', 'Peranan ini tidak dibenarkan.', 403);
    }

    $roleStmt = $db->prepare(
        "SELECT 1
         FROM user_roles ur
         JOIN roles r ON r.id=ur.role_id
         WHERE ur.system_user_id=? AND ur.property_id=?
           AND ur.status='active' AND r.status='active' AND r.role_code=?
           AND (ur.expires_at IS NULL OR ur.expires_at>NOW())
         LIMIT 1"
    );
    if (!$roleStmt) {
        throw new RuntimeException('Unable to verify API role assignment.');
    }
    $roleStmt->bind_param('iis', $userId, $propertyId, $role);
    $roleStmt->execute();
    $roleValid = $roleStmt->get_result()->fetch_row();
    $roleStmt->close();
    if (!$roleValid) {
        cpmsApiError('ACCESS_REVOKED', 'Akses aplikasi telah dibatalkan.', 403);
    }

    $tokenId = (int) $row['token_id'];
    $update = $db->prepare(
        'UPDATE cpms_api_tokens SET last_used_at=NOW() WHERE id=?'
    );
    if ($update) {
        $update->bind_param('i', $tokenId);
        $update->execute();
        $update->close();
    }

    $timezone = trim((string) ($row['timezone_name'] ?? ''));
    if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
        date_default_timezone_set($timezone);
    }

    $identity = [
        'token_id' => $tokenId,
        'system_user_id' => $userId,
        'property_id' => $propertyId,
        'role' => $role,
        'username' => (string) $row['username'],
        'name' => (string) $row['full_name'],
        'source_table' => (string) ($row['source_table'] ?? ''),
        'source_id' => (int) ($row['source_id'] ?? 0),
        'property' => [
            'id' => $propertyId,
            'code' => (string) $row['property_code'],
            'name' => (string) $row['property_name'],
            'company_name' => (string) ($row['company_name'] ?? ''),
            'logo_url' => cpmsApiBrandingAssetUrl((string) ($row['logo_path'] ?? '')),
            'primary_color' => (string) ($row['primary_color'] ?? ''),
            'secondary_color' => (string) ($row['secondary_color'] ?? ''),
        ],
    ];
    return $identity;
}

function cpmsApiRequireRole(array $roles): array
{
    $identity = cpmsApiAuth();
    if (!in_array((string) $identity['role'], $roles, true)) {
        cpmsApiError('ROLE_NOT_ALLOWED', 'Peranan ini tidak dibenarkan.', 403);
    }
    return $identity;
}

require_once __DIR__ . '/services.php';
