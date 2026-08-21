<?php

declare(strict_types=1);

final class CPMS
{
    private static ?mysqli $connection = null;
    private static ?array $settings = null;
    private static ?array $currentProperty = null;
    private static ?int $currentPropertyId = null;

    public static function boot(
        mysqli $connection,
        array $settings = []
    ): void {
        self::$connection = $connection;
        self::$settings = $settings;
        self::$currentProperty = null;
        self::$currentPropertyId = null;
    }

    public static function db(): mysqli
    {
        if (!self::$connection instanceof mysqli) {
            throw new RuntimeException(
                "CPMS Service Layer belum dimulakan."
            );
        }

        return self::$connection;
    }

    public static function settings(): array
    {
        return self::$settings ?? [];
    }

    public static function setting(
        string $key,
        string $default = ""
    ): string {
        $settings = self::settings();

        return isset($settings[$key])
            ? (string) $settings[$key]
            : $default;
    }

    public static function propertyId(): int
    {
        if (self::$currentPropertyId !== null) {
            return self::$currentPropertyId;
        }

        if (!function_exists("cpmsCurrentPropertyId")) {
            throw new RuntimeException(
                "Property Engine belum dimuatkan."
            );
        }

        $propertyId =
            cpmsCurrentPropertyId(
                self::db()
            );

        if ($propertyId < 1) {
            throw new RuntimeException(
                "Tiada property aktif dipilih."
            );
        }

        self::$currentPropertyId =
            $propertyId;

        return $propertyId;
    }

    public static function property(): array
    {
        if (self::$currentProperty !== null) {
            return self::$currentProperty;
        }

        if (!function_exists("cpmsCurrentProperty")) {
            throw new RuntimeException(
                "Property Engine belum dimuatkan."
            );
        }

        $property =
            cpmsCurrentProperty(
                self::db()
            );

        if (!$property) {
            throw new RuntimeException(
                "Profil property semasa tidak dijumpai."
            );
        }

        self::$currentProperty =
            $property;

        return $property;
    }

    public static function moduleEnabled(
        string $moduleKey
    ): bool {
        if (!function_exists("cpmsModuleEnabled")) {
            return false;
        }

        return cpmsModuleEnabled(
            self::db(),
            $moduleKey
        );
    }

    public static function audit(
        string $moduleName,
        string $actionName,
        string|int|null $recordId = null,
        ?string $referenceNo = null,
        ?string $remarks = null,
        array|string|null $oldValues = null,
        array|string|null $newValues = null,
        ?string $userName = null,
        ?string $userRole = null
    ): bool {
        if (!function_exists("cpmsAudit")) {
            error_log(
                "CPMS Audit Engine belum dimuatkan."
            );

            return false;
        }

        if ($userName === null) {
            $userName =
                (string) (
                    $_SESSION["admin"] ??
                    $_SESSION["staff_name"] ??
                    "System"
                );
        }

        if ($userRole === null) {
            $userRole =
                isset($_SESSION["admin"])
                    ? "Administrator"
                    : (
                        isset($_SESSION["staff_name"])
                            ? "Staff"
                            : "System"
                    );
        }

        return cpmsAudit(
            self::db(),
            self::propertyId(),
            $moduleName,
            $actionName,
            $recordId,
            $referenceNo,
            $userName,
            $userRole,
            $remarks,
            $oldValues,
            $newValues
        );
    }

    public static function propertyName(): string
    {
        $property = self::property();

        return
            (string) (
                $property["name"] ??
                self::setting(
                    "property_name",
                    "Property"
                )
            );
    }

    public static function propertyCode(): string
    {
        $property = self::property();

        return
            (string) (
                $property["code"] ??
                "CPMS"
            );
    }

    public static function logo(): string
    {
        $property = self::property();

        return
            (string) (
                $property["logo_path"] ??
                self::setting(
                    "logo_path",
                    "images/logo.png"
                )
            );
    }

    public static function primaryColor(): string
    {
        $property = self::property();

        return
            (string) (
                $property["primary_color"] ??
                self::setting(
                    "primary_color",
                    "#3a2419"
                )
            );
    }

    public static function secondaryColor(): string
    {
        $property = self::property();

        return
            (string) (
                $property["secondary_color"] ??
                self::setting(
                    "secondary_color",
                    "#b59b20"
                )
            );
    }
}
