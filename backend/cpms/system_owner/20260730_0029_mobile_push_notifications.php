<?php
declare(strict_types=1);

return [
    'key' => '20260730_0029_mobile_push_notifications',
    'name' => 'CPMS v3.4.1 mobile push notifications and task reminders',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_push_settings (
                id TINYINT UNSIGNED NOT NULL DEFAULT 1,
                public_key VARCHAR(255) NOT NULL,
                private_key_pem TEXT NOT NULL,
                subject VARCHAR(255) NOT NULL,
                dispatch_token CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cpms_push_dispatch_token (dispatch_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_push_subscriptions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                system_user_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NULL,
                role_code VARCHAR(60) NOT NULL,
                endpoint TEXT NOT NULL,
                endpoint_hash CHAR(64) NOT NULL,
                p256dh VARCHAR(255) NULL,
                auth_secret VARCHAR(255) NULL,
                user_agent VARCHAR(500) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                last_seen_at DATETIME NULL,
                last_push_at DATETIME NULL,
                failure_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cpms_push_endpoint (endpoint_hash),
                KEY idx_cpms_push_user
                    (system_user_id, property_id, status),
                CONSTRAINT fk_cpms_push_user FOREIGN KEY (system_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_cpms_push_property FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_push_deliveries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                notification_id BIGINT UNSIGNED NOT NULL,
                subscription_id BIGINT UNSIGNED NOT NULL,
                delivery_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                http_status SMALLINT UNSIGNED NULL,
                attempted_at DATETIME NULL,
                delivered_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cpms_push_delivery
                    (notification_id, subscription_id),
                KEY idx_cpms_push_delivery_status
                    (delivery_status, created_at),
                CONSTRAINT fk_cpms_push_delivery_notification
                    FOREIGN KEY (notification_id)
                    REFERENCES cpms_user_notifications (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_cpms_push_delivery_subscription
                    FOREIGN KEY (subscription_id)
                    REFERENCES cpms_push_subscriptions (id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.1',
                'Mobile Push Notification and Task Reminder',
                'Web Push subscriptions, VAPID configuration and per-user delivery tracking.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS cpms_push_deliveries',
            'DROP TABLE IF EXISTS cpms_push_subscriptions',
            'DROP TABLE IF EXISTS cpms_push_settings',
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '3.4.1'",
        ];
    },
];
