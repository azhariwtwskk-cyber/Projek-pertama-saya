<?php
function loadSystemSettings(mysqli $conn): array
{
    $settings = [
        "system_name" => "Commercial Property Management System",
        "system_short_name" => "CPMS",
        "property_name" => "V23 Malawa Ria Apartment",
        "company_name" => "Property Management",
        "primary_color" => "#3a2419",
        "secondary_color" => "#b59b20",
        "contact_phone" => "",
        "contact_email" => "",
        "address" => "",
        "logo_path" => "images/logo.png",
        "software_version" => "1.0.0",
        "powered_by" => "Azhari Technologies",
        "default_language" => "ms"
    ];

    $result = $conn->query(
        "SELECT setting_key, setting_value FROM system_settings"
    );

    if (!$result) return $settings;

    while ($row = $result->fetch_assoc()) {
        $key = (string)$row["setting_key"];
        if (array_key_exists($key, $settings)) {
            $settings[$key] = (string)($row["setting_value"] ?? "");
        }
    }

    return $settings;
}

function setting(array $settings, string $key, string $default = ""): string
{
    return isset($settings[$key]) ? (string)$settings[$key] : $default;
}
