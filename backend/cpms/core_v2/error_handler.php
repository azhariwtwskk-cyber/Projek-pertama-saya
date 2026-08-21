<?php
declare(strict_types=1);

if (defined('CPMS_V2_ERROR_HANDLER_LOADED')) {
    return;
}
define('CPMS_V2_ERROR_HANDLER_LOADED', true);

function cpmsV2LogError($error): void
{
    $directory = dirname(__DIR__) . '/logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }

    $message = $error instanceof Throwable ? (string) $error : (string) $error;
    @error_log(
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        3,
        $directory . '/core-v2-error.log'
    );
}

function cpmsV2RenderError(string $message = 'The request could not be completed.'): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>CPMS System Error</title>';
    echo '<style>body{margin:0;padding:24px;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033}.card{max-width:620px;margin:10vh auto;background:#fff;border:1px solid #dfe7f0;border-radius:14px;padding:28px;box-shadow:0 12px 34px rgba(15,23,42,.08)}h1{font-size:22px;margin:0 0 12px}p{line-height:1.6;color:#5e6a7c}</style>';
    echo '</head><body><section class="card"><h1>CPMS could not process this request</h1><p>';
    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '</p></section></body></html>';
    exit;
}

set_exception_handler(function (Throwable $exception): void {
    cpmsV2LogError($exception);
    cpmsV2RenderError('Please try again. The technical error has been recorded in cpms/logs/core-v2-error.log.');
});
