<?php
declare(strict_types=1);

/**
 * CPMS Foundation Bootstrap — hosting-safe version.
 * Registers error handling before loading the database so bootstrap errors
 * are logged instead of becoming an unexplained blank HTTP 500 response.
 */

if (defined('CPMS_FOUNDATION_BOOTSTRAPPED')) {
    return;
}

define('CPMS_FOUNDATION_BOOTSTRAPPED', true);

date_default_timezone_set('Asia/Kuala_Lumpur');


/**
 * Log foundation/bootstrap errors.
 */
function cpmsFoundationLog(Throwable|string $error): void
{
    $logDirectory = dirname(__DIR__) . '/logs';

    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0755, true);
    }

    $message = $error instanceof Throwable
        ? (string) $error
        : $error;

    @error_log(
        '[' . date('Y-m-d H:i:s') . '] '
        . $message
        . PHP_EOL,
        3,
        $logDirectory . '/foundation-error.log'
    );
}


/**
 * Display a safe public error page.
 */
function cpmsFoundationErrorPage(
    string $publicMessage = 'The request could not be completed.'
): never {
    if (!headers_sent()) {
        http_response_code(500);
    }

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>CPMS System Error</title>';

    echo '<style>';
    echo 'body{';
    echo 'margin:0;';
    echo 'background:#f4f7fb;';
    echo 'color:#18212f;';
    echo 'font-family:Arial,sans-serif;';
    echo 'display:grid;';
    echo 'place-items:center;';
    echo 'min-height:100vh;';
    echo 'padding:20px;';
    echo 'box-sizing:border-box';
    echo '}';

    echo '.card{';
    echo 'max-width:560px;';
    echo 'background:#fff;';
    echo 'border:1px solid #dfe6ef;';
    echo 'border-radius:14px;';
    echo 'padding:28px;';
    echo 'box-shadow:0 12px 35px rgba(15,23,42,.08)';
    echo '}';

    echo 'h1{';
    echo 'font-size:22px;';
    echo 'margin:0 0 10px';
    echo '}';

    echo 'p{';
    echo 'line-height:1.6;';
    echo 'color:#5c6878;';
    echo 'margin:0';
    echo '}';
    echo '</style>';

    echo '</head>';
    echo '<body>';
    echo '<section class="card">';
    echo '<h1>CPMS could not process this request</h1>';
    echo '<p>';
    echo htmlspecialchars(
        $publicMessage,
        ENT_QUOTES,
        'UTF-8'
    );
    echo '</p>';
    echo '</section>';
    echo '</body>';
    echo '</html>';

    exit;
}


/**
 * Global exception handler.
 */
set_exception_handler(
    static function (Throwable $exception): void {
        cpmsFoundationLog($exception);

        cpmsFoundationErrorPage(
            'Please try again. The technical error has been recorded in '
            . 'cpms/logs/foundation-error.log.'
        );
    }
);


/**
 * Start secure session.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}


/**
 * Load database connection.
 */
$cpmsRoot = dirname(__DIR__);
$projectRoot = dirname($cpmsRoot);
$databaseFile = $projectRoot . '/db.php';

if (!is_file($databaseFile)) {
    throw new RuntimeException(
        'CPMS database configuration could not be found at: '
        . $databaseFile
    );
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once $databaseFile;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException(
        'CPMS database connection is unavailable.'
    );
}

if (!$conn->set_charset('utf8mb4')) {
    throw new RuntimeException(
        'CPMS could not set the database character set.'
    );
}


/**
 * Check whether the properties table exists.
 */
function cpmsPropertiesTableExists(mysqli $conn): bool
{
    $result = $conn->query(
        "SHOW TABLES LIKE 'cpms_properties'"
    );

    return $result instanceof mysqli_result
        && $result->num_rows > 0;
}


/**
 * Find a property using its property code.
 */
function cpmsFindPropertyByCode(
    mysqli $conn,
    string $propertyCode
): ?array {
    $propertyCode = strtoupper(trim($propertyCode));

    if ($propertyCode === '') {
        return null;
    }

    $statement = $conn->prepare(
        "
        SELECT *
        FROM cpms_properties
        WHERE UPPER(property_code) = ?
          AND is_active = 1
        LIMIT 1
        "
    );

    if (!$statement) {
        throw new RuntimeException(
            'Unable to prepare property code query: '
            . $conn->error
        );
    }

    $statement->bind_param(
        's',
        $propertyCode
    );

    $statement->execute();

    $result = $statement->get_result();

    $property = $result->fetch_assoc();

    $statement->close();

    return is_array($property)
        ? $property
        : null;
}


/**
 * Find a property using its database ID.
 */
function cpmsFindPropertyById(
    mysqli $conn,
    int $propertyId
): ?array {
    if ($propertyId <= 0) {
        return null;
    }

    $statement = $conn->prepare(
        "
        SELECT *
        FROM cpms_properties
        WHERE id = ?
          AND is_active = 1
        LIMIT 1
        "
    );

    if (!$statement) {
        throw new RuntimeException(
            'Unable to prepare property ID query: '
            . $conn->error
        );
    }

    $statement->bind_param(
        'i',
        $propertyId
    );

    $statement->execute();

    $result = $statement->get_result();

    $property = $result->fetch_assoc();

    $statement->close();

    return is_array($property)
        ? $property
        : null;
}


/**
 * Save active property into session.
 */
function cpmsSetActiveProperty(array $property): void
{
    $propertyId = (int) (
        $property['id']
        ?? $property['property_id']
        ?? 0
    );

    $propertyCode = strtoupper(
        trim(
            (string) (
                $property['property_code']
                ?? ''
            )
        )
    );

    $propertyName = trim(
        (string) (
            $property['property_name']
            ?? ''
        )
    );

    $_SESSION['cpms_property_id'] = $propertyId;
    $_SESSION['cpms_property_code'] = $propertyCode;
    $_SESSION['cpms_property_name'] = $propertyName;
}


/**
 * Remove invalid active property session.
 */
function cpmsClearActiveProperty(): void
{
    unset(
        $_SESSION['cpms_property_id'],
        $_SESSION['cpms_property_code'],
        $_SESSION['cpms_property_name']
    );
}


/**
 * Load active property.
 *
 * Supported URL:
 *
 * index.php?property=V23
 * index.php?property=TMJ
 * complaint_form.php?property=TMJ
 */
$activeProperty = null;

if (cpmsPropertiesTableExists($conn)) {
    $requestedPropertyCode = trim(
        (string) (
            $_GET['property']
            ?? ''
        )
    );

    if ($requestedPropertyCode === '') {
        $requestPath = (string) parse_url(
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            PHP_URL_PATH
        );

        $requestPath = rawurldecode($requestPath);

        $segments = array_values(
            array_filter(
                explode('/', trim($requestPath, '/')),
                static fn (string $segment): bool => $segment !== ''
            )
        );

        /*
         * Friendly portal URLs are always rooted at the project root:
         * /V23/, /TMJ/, /V23/complaint_form.php, and so on.
         */
        $reservedSegments = [
            'cpms',
            'css',
            'images',
            'uploads',
            'assets',
            'admin',
            'resident',
            'staff',
            'security',
            'api',
        ];

        if (
            isset($segments[0])
            && !in_array(
                strtolower($segments[0]),
                $reservedSegments,
                true
            )
            && !str_contains($segments[0], '.')
            && preg_match(
                '/^[A-Za-z0-9_-]{1,40}$/',
                $segments[0]
            ) === 1
        ) {
            $requestedPropertyCode = $segments[0];
        }
    }

    if ($requestedPropertyCode !== '') {
        $activeProperty = cpmsFindPropertyByCode(
            $conn,
            $requestedPropertyCode
        );

        if ($activeProperty === null) {
            cpmsClearActiveProperty();

            if (!headers_sent()) {
                http_response_code(404);
            }

            echo '<!doctype html>';
            echo '<html lang="en">';
            echo '<head>';
            echo '<meta charset="utf-8">';
            echo '<meta name="viewport" ';
            echo 'content="width=device-width,initial-scale=1">';
            echo '<title>Property Not Found</title>';

            echo '<style>';
            echo 'body{';
            echo 'margin:0;';
            echo 'background:#f4f7fb;';
            echo 'font-family:Arial,sans-serif;';
            echo 'display:grid;';
            echo 'place-items:center;';
            echo 'min-height:100vh;';
            echo 'padding:20px;';
            echo 'box-sizing:border-box;';
            echo 'color:#18212f';
            echo '}';

            echo '.card{';
            echo 'width:100%;';
            echo 'max-width:520px;';
            echo 'background:#fff;';
            echo 'border:1px solid #dfe6ef;';
            echo 'border-radius:16px;';
            echo 'padding:28px;';
            echo 'box-shadow:0 12px 35px rgba(15,23,42,.08);';
            echo 'text-align:center';
            echo '}';

            echo 'h1{';
            echo 'font-size:24px;';
            echo 'margin:0 0 12px';
            echo '}';

            echo 'p{';
            echo 'color:#5c6878;';
            echo 'line-height:1.6;';
            echo 'margin:0';
            echo '}';
            echo '</style>';

            echo '</head>';
            echo '<body>';
            echo '<section class="card">';
            echo '<h1>Property not found</h1>';
            echo '<p>The requested property code is invalid or unavailable.</p>';
            echo '</section>';
            echo '</body>';
            echo '</html>';

            exit;
        }

        cpmsSetActiveProperty(
            $activeProperty
        );
    } elseif (
        !empty($_SESSION['cpms_property_id'])
    ) {
        $activeProperty = cpmsFindPropertyById(
            $conn,
            (int) $_SESSION['cpms_property_id']
        );

        if ($activeProperty === null) {
            cpmsClearActiveProperty();
        } else {
            cpmsSetActiveProperty(
                $activeProperty
            );
        }
    }
}


/**
 * Global active property values.
 */
$GLOBALS['cpms_active_property'] = $activeProperty;

$cpmsActiveProperty = $activeProperty;

$cpmsPropertyId = (int) (
    $activeProperty['id']
    ?? $activeProperty['property_id']
    ?? 0
);

$cpmsPropertyCode = strtoupper(
    trim(
        (string) (
            $activeProperty['property_code']
            ?? ''
        )
    )
);

$cpmsPropertyName = trim(
    (string) (
        $activeProperty['property_name']
        ?? ''
    )
);


/**
 * Public helper to get active property.
 */
function cpmsActiveProperty(): ?array
{
    $property = $GLOBALS['cpms_active_property']
        ?? null;

    return is_array($property)
        ? $property
        : null;
}


/**
 * Public helper to get active property ID.
 */
function cpmsActivePropertyId(): int
{
    $property = cpmsActiveProperty();

    return (int) (
        $property['id']
        ?? $property['property_id']
        ?? 0
    );
}


/**
 * Public helper to get active property code.
 */
function cpmsActivePropertyCode(): string
{
    $property = cpmsActiveProperty();

    return strtoupper(
        trim(
            (string) (
                $property['property_code']
                ?? ''
            )
        )
    );
}


/**
 * Public helper to get active property name.
 */
function cpmsActivePropertyName(): string
{
    $property = cpmsActiveProperty();

    return trim(
        (string) (
            $property['property_name']
            ?? ''
        )
    );
}


/**
 * Add active property code to an internal URL.
 */
function cpmsPropertyUrl(string $path = ''): string
{
    $propertyCode = cpmsActivePropertyCode();
    if ($propertyCode === '') {
        return $path;
    }
    if (preg_match('#^(https?:)?//#i', $path)) {
        return $path;
    }
    $baseUrl = function_exists('cpmsProjectBaseUrl') ? cpmsProjectBaseUrl() : '/';
    $baseUrl = rtrim($baseUrl, '/');
    $cleanPath = ltrim($path, '/');
    $portalUrl = $baseUrl . '/' . rawurlencode($propertyCode);
    if ($cleanPath !== '') {
        $portalUrl .= '/' . $cleanPath;
    }
    return $portalUrl;
}
