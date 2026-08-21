<?php
declare(strict_types=1);

return [
    'key' => '20260727_0006_unified_identity_foundation',
    'name' => 'CPMS v3.0.0 unified identity foundation',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS system_users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NULL,
                username VARCHAR(100) NOT NULL,
                email VARCHAR(190) NULL,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(190) NOT NULL,
                phone VARCHAR(50) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                last_login_at DATETIME NULL,
                source_table VARCHAR(64) NULL,
                source_id INT UNSIGNED NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_system_users_username (username),
                UNIQUE KEY uq_system_users_email (email),
                UNIQUE KEY uq_system_users_legacy_source
                    (source_table, source_id),
                KEY idx_system_users_property (property_id),
                KEY idx_system_users_status (status),
                KEY idx_system_users_created_by (created_by_user_id),
                CONSTRAINT fk_system_users_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_system_users_created_by
                    FOREIGN KEY (created_by_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS roles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                role_code VARCHAR(80) NOT NULL,
                role_name VARCHAR(120) NOT NULL,
                scope VARCHAR(30) NOT NULL DEFAULT 'property',
                is_system TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_roles_code (role_code),
                KEY idx_roles_scope_status (scope, status)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS permissions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                permission_code VARCHAR(150) NOT NULL,
                permission_name VARCHAR(190) NOT NULL,
                module_name VARCHAR(100) NOT NULL,
                description VARCHAR(255) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_permissions_code (permission_code),
                KEY idx_permissions_module_status (module_name, status)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS user_roles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                system_user_id INT UNSIGNED NOT NULL,
                role_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NULL,
                assigned_by_user_id INT UNSIGNED NULL,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_roles_assignment
                    (system_user_id, role_id, property_id),
                KEY idx_user_roles_role (role_id),
                KEY idx_user_roles_property (property_id),
                KEY idx_user_roles_assigned_by (assigned_by_user_id),
                KEY idx_user_roles_status (status),
                CONSTRAINT fk_user_roles_user
                    FOREIGN KEY (system_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_user_roles_role
                    FOREIGN KEY (role_id)
                    REFERENCES roles (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_user_roles_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_user_roles_assigned_by
                    FOREIGN KEY (assigned_by_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS role_permissions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                role_id INT UNSIGNED NOT NULL,
                permission_id INT UNSIGNED NOT NULL,
                granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_role_permissions
                    (role_id, permission_id),
                KEY idx_role_permissions_permission (permission_id),
                CONSTRAINT fk_role_permissions_role
                    FOREIGN KEY (role_id)
                    REFERENCES roles (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_role_permissions_permission
                    FOREIGN KEY (permission_id)
                    REFERENCES permissions (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO roles
                (role_code, role_name, scope, is_system)
             VALUES
                ('system_owner', 'System Owner', 'global', 1),
                ('property_admin', 'Property Admin', 'property', 1),
                ('manager', 'Manager', 'property', 1),
                ('clerk', 'Clerk', 'property', 1),
                ('staff', 'Staff', 'property', 1),
                ('security', 'Security', 'property', 1),
                ('resident', 'Resident', 'property', 1),
                ('contractor', 'Contractor', 'property', 1),
                ('vendor', 'Vendor', 'property', 1)",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name)
             VALUES
                ('dashboard.view', 'View dashboard', 'dashboard'),
                ('users.view', 'View users', 'users'),
                ('users.create', 'Create users', 'users'),
                ('users.update', 'Update users', 'users'),
                ('users.assign_role', 'Assign user roles', 'users'),
                ('complaints.view', 'View complaints', 'complaints'),
                ('complaints.create', 'Create complaints', 'complaints'),
                ('complaints.update', 'Update complaints', 'complaints'),
                ('work_orders.view', 'View work orders', 'work_orders'),
                ('work_orders.create', 'Create work orders', 'work_orders'),
                ('work_orders.update', 'Update work orders', 'work_orders'),
                ('assets.view', 'View assets', 'assets'),
                ('assets.create', 'Create assets', 'assets'),
                ('assets.update', 'Update assets', 'assets'),
                ('assets.delete', 'Delete assets', 'assets'),
                ('inspection.view', 'View inspections', 'inspection'),
                ('inspection.create', 'Create inspections', 'inspection'),
                ('inspection.approve', 'Approve inspections', 'inspection'),
                ('security.patrol', 'Record security patrols', 'security'),
                ('visitor.manage', 'Manage visitors', 'visitor'),
                ('reports.view', 'View reports', 'reports'),
                ('reports.export', 'Export reports', 'reports'),
                ('audit.view', 'View audit trail', 'audit')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.0.0', 'Unified Identity Foundation',
                 'Additive identity, role and permission tables. Legacy login remains active.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.0.0'",
            'DROP TABLE IF EXISTS role_permissions',
            'DROP TABLE IF EXISTS user_roles',
            'DROP TABLE IF EXISTS permissions',
            'DROP TABLE IF EXISTS roles',
            'DROP TABLE IF EXISTS system_users',
        ];
    },
];
