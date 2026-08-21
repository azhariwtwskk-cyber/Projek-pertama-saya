CPMS SYSTEM OWNER PORTAL — INTEGRATED VERSION

UPLOAD LOCATION:
Upload the entire folder named "system_owner" into:

/htdocs/cpms/

FINAL STRUCTURE:
/htdocs/cpms/system_owner/
    config.php
    auth.php
    register.php
    login.php
    dashboard.php
    logout.php
    assets/portal.css

DO NOT upload it to:
/htdocs/system_owner/
/htdocs/cpms/includes/

FIRST TEST:
1. Open:
   https://YOUR-DOMAIN/cpms/system_owner/register.php

2. Register the first System Owner.

3. Login at:
   https://YOUR-DOMAIN/cpms/system_owner/login.php

4. Confirm dashboard shows:
   - Total property
   - V23 Malawa Ria Apartment in the property list

IMPORTANT:
- The portal uses /cpms/includes/cpms_bootstrap.php.
- Existing V23 files are not changed.
- register.php becomes unavailable after the first owner account exists.
