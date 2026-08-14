<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';
require_once dirname(__DIR__) . '/core_v2/migration_engine.php';

$user = cpmsV2User();
if (!$user || (string) ($user['role'] ?? '') !== 'system_owner') {
    http_response_code(403);
    exit('System Owner access required.');
}

$engine = new CpmsV2MigrationEngine(
    cpmsV2Database(),
    dirname(__DIR__) . '/migrations_v2',
    dirname(__DIR__) . '/storage/migration_snapshots'
);

header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode(
        [
            'ok' => true,
            'health' => $engine->health(),
            'status' => $engine->status(),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(
        ['ok' => false, 'error' => $exception->getMessage()],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
}
