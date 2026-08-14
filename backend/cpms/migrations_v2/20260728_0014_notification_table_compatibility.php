<?php
declare(strict_types=1);

return [
    'key' => '20260728_0014_notification_table_compatibility',
    'name' => 'CPMS v3.2.2.1 notification table compatibility hotfix',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_user_notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                recipient_system_user_id INT UNSIGNED NOT NULL,
                notification_key VARCHAR(190) NOT NULL,
                notification_type VARCHAR(60) NOT NULL,
                title VARCHAR(190) NOT NULL,
                message VARCHAR(1000) NOT NULL,
                target_url VARCHAR(500) NULL,
                severity VARCHAR(20) NOT NULL DEFAULT 'info',
                related_type VARCHAR(60) NULL,
                related_id BIGINT UNSIGNED NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cpms_user_notification
                    (recipient_system_user_id, notification_key),
                KEY idx_cpms_user_notification_inbox
                    (property_id, recipient_system_user_id,
                     is_read, created_at),
                KEY idx_cpms_user_notification_related
                    (related_type, related_id),
                CONSTRAINT fk_cpms_user_notification_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_cpms_user_notification_user
                    FOREIGN KEY (recipient_system_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.2.1',
                 'Notification Table Compatibility Hotfix',
                 'Uses cpms_user_notifications without modifying the legacy cpms_notifications table.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS cpms_user_notifications',
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.2.1'",
        ];
    },
];
