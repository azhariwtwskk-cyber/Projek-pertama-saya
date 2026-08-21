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

function cpmsFoundationLog(Throwable|string $error): void
{
    $logDirectory = dirname(__DIR__) . '/logs';
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0755, true);
    }

    $message = $error instanceof Throwable ? (string) $error : $error;
    @error_log(
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        3,
        $logDirectory . '/foundation-error.log'
    );
}

function cpmsFoundationErrorPage(
    string $publicMessage = 'The request could not be completed.'
): never {
    if (!headers_sent()) {
        http_response_code(500);
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>CPMS System Error</title><style>';
    echo 'body{margin:0;background:#f4f7fb;color:#18212f;font-family:Arial,sans-serif;display:grid;place-items:center;min-height:100vh;padding:20px;box-sizing:border-box}';
    echo '.card{max-width:560px;background:#fff;border:1px solid #dfe6ef;border-radius:14px;padding:28px;box-shadow:0 12px 35px rgba(15,23,42,.08)}';
    echo 'h1{font-size:22px;margin:0 0 10px}p{line-height:1.6;color:#5c6878;margin:0}';
    echo '</style></head><body><section class="card">';
    echo '<h1>CPMS could not process this request</h1><p>';
    echo htmlspecialchars($publicMessage, ENT_QUOTES, 'UTF-8');
    echo '</p></section></body></html>';
    exit;
}

set_exception_handler(
    static function (Throwable $exception): void {
        cpmsFoundationLog($exception);
        cpmsFoundationErrorPage(
            'Please try again. The technical error has been recorded in cpms/logs/foundation-error.log.'
        );
    }
);

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

$cpmsRoot = dirname(__DIR__);
$projectRoot = dirname($cpmsRoot);
$databaseFile = $projectRoot . '/db.php';

if (!is_file($databaseFile)) {
    throw new RuntimeException(
        'CPMS database configuration could not be found at: ' . $databaseFile
    );
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once $databaseFile;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException('CPMS database connection is unavailable.');
}

if (!$conn->set_charset('utf8mb4')) {
    throw new RuntimeException('CPMS could not set the database character set.');
}
