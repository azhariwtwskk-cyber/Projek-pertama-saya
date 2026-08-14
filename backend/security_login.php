<?php
declare(strict_types=1);

/*
 * CPMS v3.6.1 - Legacy Security Login Retired
 * All Security users must use Unified Login so cpms_user_id,
 * cpms_user_role and cpms_property_id are created correctly.
 */

$target = 'cpms/login.php';

if (isset($_GET['expired'])) {
    $target .= '?expired=1';
}

header('Location: ' . $target, true, 302);
exit;
