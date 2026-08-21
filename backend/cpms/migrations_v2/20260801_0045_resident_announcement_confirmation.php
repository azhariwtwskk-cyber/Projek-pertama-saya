<?php
declare(strict_types=1);

return [
    'key' => '20260801_0045_resident_announcement_confirmation',
    'name' => 'CPMS v3.5.4 resident announcement emergency notice and read confirmation',
    'up' => static function (mysqli $db): array {
        return [
            "ALTER TABLE cpms_resident_announcements
             ADD COLUMN IF NOT EXISTS notice_type VARCHAR(25)
             NOT NULL DEFAULT 'Announcement' AFTER message",
            "ALTER TABLE cpms_resident_announcements
             ADD COLUMN IF NOT EXISTS priority VARCHAR(20)
             NOT NULL DEFAULT 'Normal' AFTER notice_type",
            "ALTER TABLE cpms_resident_announcements
             ADD COLUMN IF NOT EXISTS requires_confirmation TINYINT(1)
             NOT NULL DEFAULT 0 AFTER priority",
            "CREATE TABLE IF NOT EXISTS cpms_resident_announcement_reads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                announcement_id BIGINT UNSIGNED NOT NULL,
                resident_id INT NOT NULL,
                viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                confirmed_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_announcement_resident (announcement_id,resident_id),
                KEY idx_announcement_read_property
                    (property_id,announcement_id,confirmed_at),
                CONSTRAINT fk_announcement_read_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_announcement_read_notice
                    FOREIGN KEY (announcement_id)
                    REFERENCES cpms_resident_announcements(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",
            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('resident.announcement.confirm','Confirm resident notice',
                 'resident','Confirm that a property notice has been read'),
                ('resident.announcement.read_report','Announcement read report',
                 'resident','View resident notice read and confirmation statistics'),
                ('resident.emergency_notice.manage','Manage emergency notice',
                 'resident','Publish property emergency notices')",
            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='resident'
               AND p.permission_code='resident.announcement.confirm'",
            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND p.permission_code IN
                ('resident.announcement.read_report',
                 'resident.emergency_notice.manage')",
            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.5.4',
                'Resident Announcement Emergency Notice and Read Confirmation',
                'Property notices, emergency alerts, resident view tracking and explicit read confirmation.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.5.4'",
            "DELETE rp FROM role_permissions rp JOIN permissions p
             ON p.id=rp.permission_id WHERE p.permission_code IN
                ('resident.announcement.confirm',
                 'resident.announcement.read_report',
                 'resident.emergency_notice.manage')",
            "DELETE FROM permissions WHERE permission_code IN
                ('resident.announcement.confirm',
                 'resident.announcement.read_report',
                 'resident.emergency_notice.manage')",
            "DROP TABLE IF EXISTS cpms_resident_announcement_reads",
        ];
    },
];
