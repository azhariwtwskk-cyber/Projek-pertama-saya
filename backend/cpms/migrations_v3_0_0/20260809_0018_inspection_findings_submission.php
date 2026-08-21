<?php
declare(strict_types=1);

return [
    'key' => '20260809_0018_inspection_findings_submission',
    'name' => 'CPMS v3.2.6 HQ inspection findings and submission delivery',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS inspection_finding_master (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NULL,
                finding_code VARCHAR(80) NOT NULL,
                category VARCHAR(120) NOT NULL,
                finding_name VARCHAR(190) NOT NULL,
                default_severity VARCHAR(20) NOT NULL DEFAULT 'Medium',
                recommendation_template VARCHAR(1000) NULL,
                display_order INT UNSIGNED NOT NULL DEFAULT 100,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_inspection_finding_code (finding_code),
                KEY idx_finding_master_lookup
                    (property_id, status, category, display_order),
                CONSTRAINT fk_finding_master_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_findings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inspection_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                finding_master_id INT UNSIGNED NULL,
                category VARCHAR(120) NOT NULL,
                finding_name VARCHAR(190) NOT NULL,
                severity VARCHAR(20) NOT NULL DEFAULT 'Medium',
                location VARCHAR(190) NOT NULL,
                remarks VARCHAR(2000) NULL,
                recommendation VARCHAR(2000) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Open',
                created_by_hq_inspector_id INT UNSIGNED NULL,
                created_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_inspection_finding_report
                    (inspection_id, property_id, status),
                KEY idx_inspection_finding_severity
                    (property_id, severity, status),
                CONSTRAINT fk_inspection_finding_report
                    FOREIGN KEY (inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_inspection_finding_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_inspection_finding_master
                    FOREIGN KEY (finding_master_id)
                    REFERENCES inspection_finding_master (id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_finding_images (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                finding_id BIGINT UNSIGNED NOT NULL,
                inspection_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                inspection_image_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_finding_image (finding_id, inspection_image_id),
                KEY idx_finding_image_report (inspection_id, property_id),
                CONSTRAINT fk_finding_image_finding
                    FOREIGN KEY (finding_id)
                    REFERENCES inspection_findings (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_finding_image_report
                    FOREIGN KEY (inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_finding_image_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_finding_image_source
                    FOREIGN KEY (inspection_image_id)
                    REFERENCES inspection_images (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_delivery_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inspection_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                channel VARCHAR(30) NOT NULL,
                trigger_type VARCHAR(30) NOT NULL DEFAULT 'initial',
                recipient VARCHAR(255) NOT NULL,
                recipient_name VARCHAR(190) NULL,
                recipient_role VARCHAR(80) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Pending',
                attempt_no INT UNSIGNED NOT NULL DEFAULT 1,
                last_error VARCHAR(1000) NULL,
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_inspection_delivery_report
                    (inspection_id, property_id, channel, status),
                KEY idx_inspection_delivery_recipient
                    (recipient, created_at),
                CONSTRAINT fk_inspection_delivery_report
                    FOREIGN KEY (inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_inspection_delivery_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO inspection_finding_master
                (property_id, finding_code, category, finding_name,
                 default_severity, recommendation_template, display_order)
             VALUES
                (NULL,'BLD-CRACK','Building','Crack','Medium',
                 'Inspect the affected area and carry out appropriate repair.',10),
                (NULL,'BLD-WATER-LEAK','Building','Water Leakage','Medium',
                 'Identify the source of leakage and rectify it promptly.',20),
                (NULL,'BLD-CEILING','Building','Ceiling Damage','Medium',
                 'Inspect and repair or replace the damaged ceiling section.',30),
                (NULL,'BLD-WALL','Building','Wall Damage','Medium',
                 'Repair the damaged wall and make good the affected finish.',40),
                (NULL,'ELEC-LIGHT','Electrical','Light Not Working','Medium',
                 'Check the fitting, wiring and supply; replace defective components.',50),
                (NULL,'ELEC-WIRING','Electrical','Exposed Wiring','High',
                 'Isolate the hazard where required and arrange urgent electrical rectification.',60),
                (NULL,'ELEC-DB','Electrical','DB Problem','High',
                 'Arrange inspection and rectification by a competent electrical person.',70),
                (NULL,'FIRE-EXT-EXP','Fire Safety','Expired Extinguisher','High',
                 'Replace or service the extinguisher and update the service record.',80),
                (NULL,'FIRE-EXT-MISS','Fire Safety','Missing Extinguisher','High',
                 'Provide the required extinguisher at the designated location.',90),
                (NULL,'FIRE-OBSTRUCT','Fire Safety','Obstruction','High',
                 'Remove the obstruction and keep the fire safety route/equipment accessible.',100),
                (NULL,'PLB-PIPE','Plumbing','Pipe Leakage','Medium',
                 'Repair the leaking pipe and verify that leakage has stopped.',110),
                (NULL,'PLB-PUMP','Plumbing','Pump Problem','High',
                 'Inspect the pump system and arrange repair by the responsible party.',120),
                (NULL,'PLB-PRESSURE','Plumbing','Low Water Pressure','Medium',
                 'Check supply, valves and pump performance; rectify the cause.',130),
                (NULL,'CLN-RUBBISH','Cleaning','Rubbish','Low',
                 'Remove rubbish and restore the area to the required cleanliness standard.',140),
                (NULL,'CLN-STAIR','Cleaning','Dirty Staircase','Low',
                 'Clean the staircase and include the area in routine monitoring.',150),
                (NULL,'CLN-DRAIN','Cleaning','Blocked Drain','Medium',
                 'Clear the blockage and inspect drainage flow after rectification.',160),
                (NULL,'SEC-CCTV','Security','CCTV Fault','High',
                 'Check CCTV equipment/connectivity and restore monitoring coverage.',170),
                (NULL,'SEC-BOOM','Security','Boom Gate Fault','Medium',
                 'Inspect and repair the boom gate system.',180),
                (NULL,'SEC-EQUIP','Security','Damaged Equipment','Medium',
                 'Repair or replace the damaged security equipment.',190),
                (NULL,'PLY-BROKEN','Playground','Broken Equipment','High',
                 'Restrict access if unsafe and repair or replace the equipment.',200),
                (NULL,'PLY-LOOSE','Playground','Loose Component','High',
                 'Secure or replace the loose component before normal use resumes.',210),
                (NULL,'PLY-HAZARD','Playground','Safety Hazard','Critical',
                 'Restrict access immediately and arrange urgent rectification.',220),
                (NULL,'OTH-OTHER','Others','Other Finding','Medium',
                 'Assess the issue and record the required rectification.',999)",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('inspection.finding.manage_master',
                 'Manage Inspection Finding Master', 'inspection',
                 'Manage selectable findings, default severity and recommendation templates.')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','compliance_manager')
               AND p.permission_code = 'inspection.finding.manage_master'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.6',
                 'HQ Inspection Findings & Auto Notification',
                 'Photo-linked selectable findings, default severity, submission notifications and email delivery log.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS inspection_delivery_log',
            'DROP TABLE IF EXISTS inspection_finding_images',
            'DROP TABLE IF EXISTS inspection_findings',
            'DROP TABLE IF EXISTS inspection_finding_master',
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code = 'inspection.finding.manage_master'",
            "DELETE FROM permissions
             WHERE permission_code = 'inspection.finding.manage_master'",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.6'",
        ];
    },
];
