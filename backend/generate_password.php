<?php

/*
 * CLI-only dev utility. Blocked over HTTP via the root .htaccess (see
 * mobile/docs/BACKEND_INTEGRATION_AUDIT.md, finding S-H1) -- run it from
 * a terminal instead: `php generate_password.php`.
 */

echo password_hash('Admin@123', PASSWORD_DEFAULT);