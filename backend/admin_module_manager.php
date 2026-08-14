<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RC1.5 — Legacy global administration route retired
|--------------------------------------------------------------------------
| Global CPMS controls now belong exclusively to the System Owner portal.
| This file intentionally does not trust the legacy `admin` session.
*/

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: cpms/system_owner/login.php?restricted=1');
exit;
