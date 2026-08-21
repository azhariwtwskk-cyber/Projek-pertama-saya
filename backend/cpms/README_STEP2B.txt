CPMS STEP 2B — CORE ENGINE

1. Backup folder /htdocs/includes/ dan /htdocs/lang/.
2. Upload folder includes ke /htdocs/includes/.
3. Upload folder lang ke /htdocs/lang/.
4. Upload cpms_core_test.php ke /htdocs/.
5. Buka https://domain-anda/cpms_core_test.php

Pastikan:
- Nama property muncul
- Logo muncul
- Warna ikut System Settings
- BM/EN berfungsi
- Footer CPMS muncul

Untuk halaman PHP lain, tambah:
require_once __DIR__ . "/includes/cpms_bootstrap.php";

Kemudian gunakan:
cpmsPropertyName()
cpmsSystemName()
cpmsLogo()
cpmsPrimaryColor()
cpmsSecondaryColor()
cpmsContactPhone()
cpmsContactEmail()
cpmsAddress()
cpmsFooter()
cpmsT("key")
