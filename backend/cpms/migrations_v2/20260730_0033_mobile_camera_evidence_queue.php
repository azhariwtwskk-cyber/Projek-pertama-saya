<?php
declare(strict_types=1);

return [
    'key' => '20260730_0033_mobile_camera_evidence_queue',
    'name' => 'CPMS v3.4.4 mobile camera evidence and offline upload queue',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_mobile_evidence (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                task_type VARCHAR(50) NOT NULL,
                task_id BIGINT UNSIGNED NOT NULL,
                client_upload_id CHAR(36) NOT NULL,
                evidence_phase VARCHAR(30) NOT NULL DEFAULT 'Progress',
                file_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size INT UNSIGNED NOT NULL DEFAULT 0,
                caption VARCHAR(500) NULL,
                captured_at DATETIME NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_mobile_evidence_client (client_upload_id),
                KEY idx_mobile_evidence_task
                    (property_id, task_type, task_id),
                KEY idx_mobile_evidence_user
                    (system_user_id, uploaded_at),
                CONSTRAINT fk_mobile_evidence_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id),
                CONSTRAINT fk_mobile_evidence_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES ('mobile_evidence.create',
                'Upload mobile task evidence', 'mobile',
                'Capture or select task evidence and queue uploads offline')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('staff', 'security')
               AND p.permission_code = 'mobile_evidence.create'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.4',
                'Mobile Camera Evidence and Offline Upload Queue',
                'Property-scoped evidence with compression, IndexedDB queue, retry and duplicate protection.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id
             WHERE p.permission_code = 'mobile_evidence.create'
               AND r.role_code IN ('staff', 'security')",
            "DELETE FROM permissions
             WHERE permission_code = 'mobile_evidence.create'",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.4.4'",
            "DROP TABLE IF EXISTS cpms_mobile_evidence",
        ];
    },
];
