<?php
declare(strict_types=1);

function cpmsRegisterErrorHandler(): void
{
    $debug = (bool) cpmsConfig('debug', false);
    $environment = (string) cpmsConfig(
        'environment',
        'production'
    );

    error_reporting(E_ALL);
    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('log_errors', '1');

    $logDirectory = dirname(__DIR__) . '/logs';

    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0755, true);
    }

    ini_set(
        'error_log',
        $logDirectory . '/cpms-error.log'
    );

    set_exception_handler(
        static function (Throwable $exception) use (
            $debug,
            $environment
        ): void {
            error_log((string) $exception);
            http_response_code(500);

            if ($debug || $environment === 'development') {
                echo '<pre>'
                    . cpmsEscape((string) $exception)
                    . '</pre>';
                return;
            }

            echo '<!doctype html>';
            echo '<html lang="en"><head>';
            echo '<meta charset="utf-8">';
            echo '<meta name="viewport" ';
            echo 'content="width=device-width,initial-scale=1">';
            echo '<title>CPMS System Error</title>';
            echo '</head><body>';
            echo '<h2>CPMS could not process this request.</h2>';
            echo '<p>The technical error has been recorded.</p>';
            echo '</body></html>';
        }
    );
}
