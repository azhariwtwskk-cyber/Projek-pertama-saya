<?php

/*
 * CLI-only dev utility for generating a password_hash() value to paste
 * into the database by hand. Blocked over HTTP via the root .htaccess
 * (see mobile/docs/BACKEND_INTEGRATION_AUDIT.md, finding S-H1) — run it
 * from a terminal instead: `php make_password.php` after editing the
 * value below.
 */

$password = 'CHANGE_ME';

echo '<h3>Password:</h3>';
echo $password;

echo '<br><br><h3>Hash:</h3>';
echo password_hash($password, PASSWORD_DEFAULT);