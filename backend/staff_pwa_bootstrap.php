<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CPMS v3.4.1 Staff PWA Bootstrap
|--------------------------------------------------------------------------
| - PHP 7.4 compatible
| - Uses the existing CPMS session and database connection
| - Detects staff.property_id when available
| - Loads property branding safely when cpms_properties is available
|--------------------------------------------------------------------------
*/

if (!function_exists('cpmsStaffPwaColumnExists')) {
    function cpmsStaffPwaColumnExists(
        mysqli $conn,
        string $table,
        string $column
    ): bool {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('cpmsStaffPwaTableExists')) {
    function cpmsStaffPwaTableExists(
        mysqli $conn,
        string $table
    ): bool {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('cpmsStaffPwaHex')) {
    function cpmsStaffPwaHex(
        ?string $value,
        string $fallback
    ): string {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value)
            ? $value
            : $fallback;
    }
}

if (!function_exists('cpmsStaffPwaAsset')) {
    function cpmsStaffPwaAsset(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return 'images/logo.png';
        }

        if (
            preg_match('#^https?://#i', $path) ||
            strpos($path, '/') === 0
        ) {
            return $path;
        }

        return ltrim($path, './');
    }
}

if (!function_exists('cpmsStaffPwaBranding')) {
    function cpmsStaffPwaBranding(mysqli $conn): array
    {
        $branding = [
            'property_id' => 0,
            'property_name' => 'CPMS Property',
            'system_name' => 'CPMS Staff',
            'company_name' => '',
            'logo_path' => 'images/logo.png',
            'primary_color' => '#6f3f2c',
            'secondary_color' => '#d4a85f',
            'background_path' => '',
            'footer_text' => 'CPMS Staff Portal',
        ];

        $staffId = (int) ($_SESSION['staff_id'] ?? 0);
        $propertyId = (int) ($_SESSION['staff_property_id'] ?? 0);

        if (
            $staffId > 0 &&
            $propertyId < 1 &&
            cpmsStaffPwaColumnExists($conn, 'staff', 'property_id')
        ) {
            $stmt = $conn->prepare(
                "SELECT property_id
                 FROM staff
                 WHERE id = ?
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('i', $staffId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $propertyId = (int) ($row['property_id'] ?? 0);

                if ($propertyId > 0) {
                    $_SESSION['staff_property_id'] = $propertyId;
                }
            }
        }

        $branding['property_id'] = $propertyId;

        if (
            $propertyId > 0 &&
            cpmsStaffPwaTableExists($conn, 'cpms_properties')
        ) {
            $select = ['id', 'property_name'];

            $optionalColumns = [
                'system_name',
                'company_name',
                'logo_path',
                'primary_color',
                'secondary_color',
                'background_path',
                'footer_text',
            ];

            foreach ($optionalColumns as $column) {
                if (
                    cpmsStaffPwaColumnExists(
                        $conn,
                        'cpms_properties',
                        $column
                    )
                ) {
                    $select[] = $column;
                }
            }

            $sql = "SELECT " . implode(', ', $select) .
                " FROM cpms_properties WHERE id = ? LIMIT 1";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param('i', $propertyId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (is_array($row)) {
                    foreach ($row as $key => $value) {
                        if ($value !== null && trim((string) $value) !== '') {
                            $branding[$key] = (string) $value;
                        }
                    }
                }
            }
        }

        $branding['primary_color'] = cpmsStaffPwaHex(
            (string) $branding['primary_color'],
            '#6f3f2c'
        );
        $branding['secondary_color'] = cpmsStaffPwaHex(
            (string) $branding['secondary_color'],
            '#d4a85f'
        );
        $branding['logo_path'] = cpmsStaffPwaAsset(
            (string) $branding['logo_path']
        );

        return $branding;
    }
}

if (!function_exists('cpmsStaffPwaHead')) {
    function cpmsStaffPwaHead(array $branding): string
    {
        $theme = htmlspecialchars(
            (string) $branding['primary_color'],
            ENT_QUOTES,
            'UTF-8'
        );

        return
            '<meta name="theme-color" content="' . $theme . '">' .
            '<meta name="mobile-web-app-capable" content="yes">' .
            '<meta name="apple-mobile-web-app-capable" content="yes">' .
            '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' .
            '<meta name="apple-mobile-web-app-title" content="CPMS Staff">' .
            '<link rel="manifest" href="staff_manifest.php">' .
            '<link rel="apple-touch-icon" href="pwa/icons/apple-touch-icon.png">' .
            '<link rel="stylesheet" href="pwa/staff/staff-mobile.css?v=2-gallery">' .
            '<link rel="stylesheet" href="pwa/cpms-mobile.css?v=345">';
    }
}

if (!function_exists('cpmsStaffPwaStyle')) {
    function cpmsStaffPwaStyle(array $branding): string
    {
        $primary = htmlspecialchars(
            (string) $branding['primary_color'],
            ENT_QUOTES,
            'UTF-8'
        );
        $secondary = htmlspecialchars(
            (string) $branding['secondary_color'],
            ENT_QUOTES,
            'UTF-8'
        );

        return '<style>:root{--cpms-primary:' . $primary .
            ';--cpms-secondary:' . $secondary . ';}</style>';
    }
}

if (!function_exists('cpmsStaffPwaScripts')) {
    function cpmsStaffPwaScripts(): string
    {
        return
            '<script src="pwa/staff/staff-pwa.js?v=2-gallery" defer></script>' .
            '<script src="pwa/cpms-mobile.js?v=345" defer></script>';
    }
}
