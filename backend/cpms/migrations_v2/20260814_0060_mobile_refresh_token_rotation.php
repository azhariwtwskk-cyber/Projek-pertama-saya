<?php
declare(strict_types=1);

/*
 * Stage 1 of the CPMSPro Staff Mobile App backend integration
 * (see mobile/docs/BACKEND_INTEGRATION_AUDIT.md).
 *
 * Adds hashed refresh-token storage to the existing cpms_api_tokens
 * table so the Flutter workforce app can silently refresh an expiring
 * access token instead of forcing staff to log in again every few
 * hours. Purely additive: two new nullable columns plus one unique
 * index on an existing, already-live table. No existing column is
 * renamed, retyped or dropped, and no existing row is modified by
 * this migration — rows created before this migration simply have
 * refresh_token_hash/refresh_expires_at = NULL until that session's
 * next login or refresh.
 *
 * Rollback (see 'down' below) drops the two new columns and their
 * index. This is safe with respect to every OTHER table (no foreign
 * keys point at these columns), but any refresh token issued after
 * this migration was applied stops being usable for a silent refresh
 * once rolled back — affected sessions simply fall back to a normal
 * re-login, which is the pre-Stage-1 behaviour.
 */

return [
    'key' => '20260814_0060_mobile_refresh_token_rotation',
    'name' => 'CPMS v4.1.0 Mobile Workforce refresh-token rotation',
    'up' => static function (mysqli $db): array {
        return [
            "ALTER TABLE cpms_api_tokens
                ADD COLUMN refresh_token_hash CHAR(64) NULL AFTER token_hash,
                ADD COLUMN refresh_expires_at DATETIME NULL AFTER expires_at,
                ADD UNIQUE KEY uq_cpms_api_refresh_token_hash (refresh_token_hash)",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('4.1.0','Mobile Workforce Refresh Token Rotation',
                'Adds hashed refresh token storage + rotation to cpms_api_tokens for the Flutter Staff app (Stage 1 auth).')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='4.1.0'",
            "ALTER TABLE cpms_api_tokens
                DROP KEY uq_cpms_api_refresh_token_hash,
                DROP COLUMN refresh_token_hash,
                DROP COLUMN refresh_expires_at",
        ];
    },
];
