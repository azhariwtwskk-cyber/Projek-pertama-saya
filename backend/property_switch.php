<?php

declare(strict_types=1);

session_start();

/*
 * Property switching is not permitted from the legacy V23 operations portal.
 * Cross-property access belongs exclusively to the System Owner portal.
 */
if (!isset($_SESSION["admin"])) {
    header("Location: admin_login.php");
    exit();
}

http_response_code(403);
header("Location: admin_dashboard.php?property_switch=denied");
exit();
