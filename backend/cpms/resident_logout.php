<?php
declare(strict_types=1);
require_once __DIR__ . '/cpms/includes/resident_session.php';
cpmsResidentSessionStart();
cpmsResidentSessionClear();
header('Location: resident_login.php?logout=1');
exit;
