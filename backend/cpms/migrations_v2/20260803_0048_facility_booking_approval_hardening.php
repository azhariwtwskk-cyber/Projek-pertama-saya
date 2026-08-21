<?php
declare(strict_types=1);

return [
    'key' => '20260803_0048_facility_booking_approval_hardening',
    'name' => 'CPMS v3.5.8 facility booking approval hardening',
    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('facilities.view','View facilities','facilities',
                 'View facilities within the active property'),
                ('facilities.book','Book facilities','facilities',
                 'Create resident facility bookings'),
                ('facilities.manage','Manage facilities','facilities',
                 'Manage property facilities and booking settings'),
                ('facility.booking.approve','Approve facility booking',
                 'facilities','Approve or reject resident facility bookings'),
                ('facility.booking.cancel','Cancel facility booking',
                 'facilities','Review approved-booking cancellation requests'),
                ('facility.booking.calendar','View facility booking calendar',
                 'facilities','View the property facility booking calendar'),
                ('resident.notifications.view','View resident notifications',
                 'resident_portal','View property-scoped resident notifications')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND r.status='active' AND p.status='active'
               AND p.permission_code IN (
                    'facilities.view','facilities.manage',
                    'facility.booking.approve','facility.booking.cancel',
                    'facility.booking.calendar'
               )",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='resident' AND r.status='active'
               AND p.status='active' AND p.permission_code IN (
                    'facilities.view','facilities.book',
                    'facility.booking.calendar','resident.notifications.view'
               )",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.5.8','Facility Booking and Approval Hardening',
                'CSRF, serialized slot checks, property-scoped approval, controlled cancellation and bilingual resident facility workflow.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.5.8'",
        ];
    },
];
