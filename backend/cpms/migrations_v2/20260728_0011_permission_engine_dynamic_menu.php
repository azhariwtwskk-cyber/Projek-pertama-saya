<?php
declare(strict_types=1);

return [
    'key' => '20260728_0011_permission_engine_dynamic_menu',
    'name' => 'CPMS v3.1.0 permission engine and dynamic menu',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('dashboard.view', 'View dashboard', 'dashboard', 'Open the role dashboard'),
                ('users.view', 'View users', 'users', 'View property and system users'),
                ('users.create', 'Create users', 'users', 'Create users within the permitted scope'),
                ('users.update', 'Update users', 'users', 'Update users within the permitted scope'),
                ('users.assign_role', 'Assign roles', 'users', 'Assign roles within the permitted scope'),
                ('properties.view', 'View properties', 'properties', 'View property records'),
                ('properties.manage', 'Manage properties', 'properties', 'Create and update property records'),
                ('complaints.view', 'View complaints', 'complaints', 'View complaints within the property'),
                ('complaints.create', 'Create complaints', 'complaints', 'Create a complaint'),
                ('complaints.update', 'Update complaints', 'complaints', 'Update complaint status and details'),
                ('complaints.delete', 'Delete complaints', 'complaints', 'Delete complaints'),
                ('work_orders.view', 'View work orders', 'work_orders', 'View work orders'),
                ('work_orders.create', 'Create work orders', 'work_orders', 'Create and assign work orders'),
                ('work_orders.update', 'Update work orders', 'work_orders', 'Update assigned work orders'),
                ('work_orders.approve', 'Approve work orders', 'work_orders', 'Verify or approve completed work'),
                ('assets.view', 'View assets', 'assets', 'View property assets'),
                ('assets.create', 'Create assets', 'assets', 'Register property assets'),
                ('assets.update', 'Update assets', 'assets', 'Update property assets'),
                ('assets.delete', 'Delete assets', 'assets', 'Delete property assets'),
                ('inspection.view', 'View inspections', 'inspection', 'View inspection records'),
                ('inspection.create', 'Create inspections', 'inspection', 'Create inspection records'),
                ('inspection.update', 'Update inspections', 'inspection', 'Update inspection records'),
                ('inspection.approve', 'Approve inspections', 'inspection', 'Approve inspection findings'),
                ('security.view', 'View security records', 'security', 'View security operations'),
                ('security.patrol', 'Record security patrols', 'security', 'Create security patrol records'),
                ('visitor.view', 'View visitors', 'visitor', 'View visitor records'),
                ('visitor.manage', 'Manage visitors', 'visitor', 'Register and update visitors'),
                ('residents.view', 'View residents', 'residents', 'View residents within the property'),
                ('residents.manage', 'Manage residents', 'residents', 'Create and update resident records'),
                ('staff.view', 'View staff', 'staff', 'View property staff'),
                ('staff.manage', 'Manage staff', 'staff', 'Create and update property staff'),
                ('daily_work.view', 'View daily work', 'daily_work', 'View daily work submissions'),
                ('daily_work.create', 'Submit daily work', 'daily_work', 'Create daily work submissions'),
                ('daily_work.approve', 'Approve daily work', 'daily_work', 'Verify daily work submissions'),
                ('facilities.view', 'View facilities', 'facilities', 'View available facilities'),
                ('facilities.book', 'Book facilities', 'facilities', 'Create facility bookings'),
                ('facilities.manage', 'Manage facilities', 'facilities', 'Manage facilities and bookings'),
                ('notices.view', 'View notices', 'notices', 'View announcements and notices'),
                ('notices.manage', 'Manage notices', 'notices', 'Create and update notices'),
                ('reports.view', 'View reports', 'reports', 'View operational reports'),
                ('reports.export', 'Export reports', 'reports', 'Export and print reports'),
                ('audit.view', 'View audit trail', 'audit', 'View system and authentication audit'),
                ('settings.manage', 'Manage settings', 'settings', 'Manage property or system settings'),
                ('permissions.manage', 'Manage permissions', 'permissions', 'Manage role permission assignments'),
                ('profile.view', 'View profile', 'profile', 'View own profile'),
                ('profile.update', 'Update profile', 'profile', 'Update own profile')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             CROSS JOIN permissions p
             WHERE r.role_code = 'system_owner'
               AND r.status = 'active'
               AND p.status = 'active'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             JOIN permissions p ON p.permission_code IN (
                'dashboard.view',
                'users.view','users.create','users.update','users.assign_role',
                'properties.view',
                'complaints.view','complaints.create','complaints.update','complaints.delete',
                'work_orders.view','work_orders.create','work_orders.update','work_orders.approve',
                'assets.view','assets.create','assets.update','assets.delete',
                'inspection.view','inspection.create','inspection.update','inspection.approve',
                'security.view','security.patrol',
                'visitor.view','visitor.manage',
                'residents.view','residents.manage',
                'staff.view','staff.manage',
                'daily_work.view','daily_work.create','daily_work.approve',
                'facilities.view','facilities.book','facilities.manage',
                'notices.view','notices.manage',
                'reports.view','reports.export',
                'audit.view','settings.manage',
                'profile.view','profile.update'
             )
             WHERE r.role_code = 'property_admin'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             JOIN permissions p ON p.permission_code IN (
                'dashboard.view','users.view','properties.view',
                'complaints.view','complaints.create','complaints.update',
                'work_orders.view','work_orders.create','work_orders.update','work_orders.approve',
                'assets.view','assets.create','assets.update',
                'inspection.view','inspection.create','inspection.update','inspection.approve',
                'security.view','visitor.view',
                'residents.view','staff.view',
                'daily_work.view','daily_work.approve',
                'facilities.view','facilities.manage',
                'notices.view','notices.manage',
                'reports.view','reports.export',
                'profile.view','profile.update'
             )
             WHERE r.role_code = 'manager'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             JOIN permissions p ON p.permission_code IN (
                'dashboard.view',
                'complaints.view','complaints.create','complaints.update',
                'work_orders.view',
                'visitor.view','visitor.manage',
                'residents.view','residents.manage',
                'facilities.view','facilities.book',
                'notices.view',
                'profile.view','profile.update'
             )
             WHERE r.role_code = 'clerk'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             JOIN permissions p ON p.permission_code IN (
                'dashboard.view',
                'work_orders.view','work_orders.update',
                'assets.view',
                'inspection.view','inspection.create',
                'daily_work.view','daily_work.create',
                'notices.view',
                'profile.view','profile.update'
             )
             WHERE r.role_code = 'staff'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             JOIN permissions p ON p.permission_code IN (
                'dashboard.view',
                'security.view','security.patrol',
                'visitor.view','visitor.manage',
                'notices.view',
                'profile.view','profile.update'
             )
             WHERE r.role_code = 'security'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             JOIN permissions p ON p.permission_code IN (
                'dashboard.view',
                'complaints.view','complaints.create',
                'facilities.view','facilities.book',
                'notices.view',
                'profile.view','profile.update'
             )
             WHERE r.role_code = 'resident'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             JOIN permissions p ON p.permission_code IN (
                'dashboard.view',
                'work_orders.view','work_orders.update',
                'daily_work.view','daily_work.create',
                'notices.view',
                'profile.view','profile.update'
             )
             WHERE r.role_code = 'contractor'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             JOIN permissions p ON p.permission_code IN (
                'dashboard.view',
                'work_orders.view',
                'assets.view',
                'notices.view',
                'profile.view','profile.update'
             )
             WHERE r.role_code = 'vendor'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.1.0', 'Permission Engine & Dynamic Menu',
                 'Database-driven role permissions, cpmsCan access checks and dynamic role menus.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code IN (
                'properties.view','properties.manage',
                'complaints.delete',
                'work_orders.approve',
                'inspection.update',
                'security.view',
                'visitor.view',
                'residents.view','residents.manage',
                'staff.view','staff.manage',
                'daily_work.view','daily_work.create','daily_work.approve',
                'facilities.view','facilities.book','facilities.manage',
                'notices.view','notices.manage',
                'settings.manage','permissions.manage',
                'profile.view','profile.update'
             )",
            "DELETE FROM permissions
             WHERE permission_code IN (
                'properties.view','properties.manage',
                'complaints.delete',
                'work_orders.approve',
                'inspection.update',
                'security.view',
                'visitor.view',
                'residents.view','residents.manage',
                'staff.view','staff.manage',
                'daily_work.view','daily_work.create','daily_work.approve',
                'facilities.view','facilities.book','facilities.manage',
                'notices.view','notices.manage',
                'settings.manage','permissions.manage',
                'profile.view','profile.update'
             )",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.1.0'",
        ];
    },
];
