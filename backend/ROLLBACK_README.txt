CPMSPro Rollback — Rollout 4.2

Purpose:
Restore Resident/Facility/Visitor pages to the known-good state from well-known (3).zip.

Upload:
Extract this ZIP and upload the cpms folder contents to the matching server paths.
Choose overwrite/replace for the included PHP files.

Important:
- This rollback does NOT include database files.
- It does NOT modify authentication, session, property_id, unit assignment, APIs or services.
- The two Rollout 4.2-only files can be deleted from the server if present:
  cpms/includes/resident_master_shell.php
  cpms/assets/genesis/resident-master-shell.css

After upload:
1. Ctrl + F5
2. Open /cpms/resident_dashboard.php
3. Open /cpms/resident_requests.php
4. Open /cpms/visitor_passes.php
