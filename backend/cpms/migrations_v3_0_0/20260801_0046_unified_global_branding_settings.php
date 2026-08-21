<?php

declare(strict_types=1);

return [
    'key' => '20260801_0046_unified_global_branding_settings',
    'name' => 'CPMS v3.4.11 Unified Global Branding Settings',
    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO system_settings (setting_key, setting_value)
             VALUES
                ('system_tagline','Unified Property Operations'),
                ('favicon_path',''),
                ('background_path',''),
                ('login_secure_label_ms','Akses selamat CPMS'),
                ('login_secure_label_en','Secure CPMS access'),
                ('login_eyebrow_ms','OPERASI HARTANAH BERSEPADU'),
                ('login_eyebrow_en','UNIFIED PROPERTY OPERATIONS'),
                ('login_brand_title_ms','Satu akaun.\nSemua akses.'),
                ('login_brand_title_en','One account.\nEvery access.'),
                ('login_brand_description_ms','Akses ruang kerja CPMS anda melalui satu pintu masuk yang selamat dan profesional.'),
                ('login_brand_description_en','Access your CPMS workspace through one secure and professional entry point.'),
                ('login_title_ms','Log masuk ke CPMS'),
                ('login_title_en','Sign in to CPMS'),
                ('login_description_ms','Masukkan nama pengguna dan kata laluan anda untuk meneruskan.'),
                ('login_description_en','Enter your username and password to continue.'),
                ('login_footer_ms','Commercial Property Management System'),
                ('login_footer_en','Commercial Property Management System')",

            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES ('software_version','3.4.11')
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.4.11','Unified Global Branding Settings',
                'Database-driven CPMS identity, bilingual Unified Login content, global colours, logo, favicon and login background uploads. Property branding remains separate.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.4.11'",
            "DELETE FROM system_settings WHERE setting_key IN (
                'system_tagline','favicon_path',
                'login_secure_label_ms','login_secure_label_en',
                'login_eyebrow_ms','login_eyebrow_en',
                'login_brand_title_ms','login_brand_title_en',
                'login_brand_description_ms','login_brand_description_en',
                'login_title_ms','login_title_en',
                'login_description_ms','login_description_en',
                'login_footer_ms','login_footer_en'
            )",
        ];
    },
];
