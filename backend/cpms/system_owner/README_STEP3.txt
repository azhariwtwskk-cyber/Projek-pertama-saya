CPMS SYSTEM OWNER — STEP 3 PROPERTY MANAGEMENT

UPLOAD LOCATION:
/htdocs/cpms/system_owner/

FILES:
- Replace dashboard.php
- Add properties.php
- Add add_property.php

DO NOT replace:
- config.php
- auth.php
- login.php
- register.php
- logout.php
- assets/portal.css

TEST:
1. Login:
   /cpms/system_owner/login.php

2. Click:
   + Tambah Property

3. Create a test property:
   Code: TEST01
   Name: CPMS Test Residence
   Company: Demo Property Management
   Default language: Bahasa Melayu

4. Confirm the new property appears in:
   /cpms/system_owner/properties.php

This step does not create a Property Admin account yet.
