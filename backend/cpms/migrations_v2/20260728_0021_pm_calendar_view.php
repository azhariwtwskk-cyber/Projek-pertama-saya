<?php
declare(strict_types=1);

return [
    'key' => '20260728_0021_pm_calendar_view',
    'name' => 'CPMS v3.3.3 preventive maintenance calendar',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.3',
                 'Preventive Maintenance Calendar & Monthly Schedule View',
                 'Property-scoped monthly calendar with scheduled, due-soon, overdue and completed maintenance events.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.3'",
        ];
    },
];
