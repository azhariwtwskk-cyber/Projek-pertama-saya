<?php
function cpmsPropertyName(): string {
    global $cpmsSettings;
    return setting($cpmsSettings, "property_name", "Property");
}
function cpmsSystemName(): string {
    global $cpmsSettings;
    return setting($cpmsSettings, "system_name", "Commercial Property Management System");
}
function cpmsShortName(): string {
    global $cpmsSettings;
    return setting($cpmsSettings, "system_short_name", "CPMS");
}
function cpmsLogo(): string {
    global $cpmsSettings;
    return setting($cpmsSettings, "logo_path", "images/logo.png");
}
function cpmsPrimaryColor(): string {
    global $cpmsSettings;
    return setting($cpmsSettings, "primary_color", "#3a2419");
}
function cpmsSecondaryColor(): string {
    global $cpmsSettings;
    return setting($cpmsSettings, "secondary_color", "#b59b20");
}
function cpmsContactPhone(): string {
    global $cpmsSettings;
    return setting($cpmsSettings, "contact_phone");
}
function cpmsContactEmail(): string {
    global $cpmsSettings;
    return setting($cpmsSettings, "contact_email");
}
function cpmsAddress(): string {
    global $cpmsSettings;
    return setting($cpmsSettings, "address");
}
function cpmsFooter(): string {
    global $cpmsSettings;
    return cpmsShortName() . " Version " .
        setting($cpmsSettings, "software_version", "1.0.0") .
        " · Powered by " .
        setting($cpmsSettings, "powered_by", "Azhari Technologies");
}
