CPMS STEP 4C — PROPERTY ADMIN LOGIN + DASHBOARD FRAMEWORK

UPLOAD LOCATION:
Upload the entire folder named property_portal into:

/htdocs/cpms/

FINAL STRUCTURE:
/htdocs/cpms/property_portal/
    config.php
    auth.php
    login.php
    dashboard.php
    logout.php
    assets/portal.css

TEST LOGIN:
Open:
https://YOUR-DOMAIN/cpms/property_portal/login.php

Use the Property Admin account created in Step 4B.

Expected result:
- Login succeeds.
- Dashboard displays the correct property name.
- Property ID is shown.
- V23 Admin login remains unchanged.
- System Owner login remains unchanged.

IMPORTANT:
This portal does not yet open the legacy complaint/work-order modules.
The menu items are intentionally disabled until property scoping is audited.
