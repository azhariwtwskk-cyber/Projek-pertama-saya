CPMS V24 COMMERCIAL — LANGKAH 6.1 CORE FRAMEWORK
=================================================

TUJUAN
------
Pakej ini menambah Core Framework tanpa membuang modul V23 sedia ada.

SEBELUM PEMASANGAN
------------------
1. Backup semua fail CPMS.
2. Backup database.
3. Uji pada salinan sistem dahulu.

PEMASANGAN
----------
1. Extract ZIP.

2. Upload folder berikut ke root sistem CPMS:
   core/
   config/
   logs/
   sql/

3. Upload fail contoh jika mahu membuat ujian:
   example_dashboard.php
   example_usage.php

4. Pastikan root sistem mempunyai:
   db.php

   db.php mesti menyediakan:
   $conn = new mysqli(...);

5. Import:
   sql/01_cpms_v24_core.sql

6. Buka:
   config/app.php

   Ubah:
   base_url
   debug
   environment

7. Uji:
   example_dashboard.php

CARA GUNA PADA FAIL SEDIA ADA
-----------------------------
Gantikan kod berulang seperti:

session_start();
require_once 'db.php';

dengan:

require_once __DIR__ . '/core/bootstrap.php';

Jika fail berada dalam folder admin:

require_once dirname(__DIR__) . '/core/bootstrap.php';

Selepas itu boleh gunakan:

cpmsRequireLogin();
cpmsRequireProperty();
cpmsRequirePermission('dashboard.view');

can('work_order.manage');

cpmsCurrentUserId();
cpmsCurrentUserRole();
cpmsCurrentPropertyId();

cpmsLog($conn, ...);

cpmsEscape($value);
cpmsRedirect('dashboard.php');
cpmsCsrfField();
cpmsVerifyCsrf();

PERINGATAN
----------
Jangan terus gantikan semua modul sekaligus.
Migrasi satu halaman dahulu, uji, kemudian teruskan halaman berikutnya.

LANGKAH SETERUSNYA
------------------
6.2 Theme Engine Commercial
6.3 Database Permission Engine
6.4 Activity Log & Audit Trail UI
