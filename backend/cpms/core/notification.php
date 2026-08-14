<?php
declare(strict_types=1);

$notificationHelper = dirname(__DIR__)
    . '/includes/notification_helper.php';

if (is_file($notificationHelper)) {
    require_once $notificationHelper;
}
