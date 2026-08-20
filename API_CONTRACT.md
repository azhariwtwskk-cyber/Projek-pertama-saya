# CPMSPro Staff Mobile — API Contract

Confirmed by reading the actual PHP source under `backend/cpms/api/v1/`, not assumed. Every
endpoint is authenticated with `Authorization: Bearer <token>` (except `auth/login.php` and
`auth/refresh.php`) via `cpmsApiAuth()`/`cpmsApiRequireRole()`, which resolves `property_id` and
`staff_id` from the token server-side — the client never sends either as authorization context.
Every response is wrapped `{"ok":true,"data":{...}}` / `{"ok":false,"error":{"code","message","details"?}}`;
`ApiClient.request()` unwraps `data` once, centrally.

| # | Flutter operation | HTTP | PHP endpoint | Request fields | Response fields (inside `data`) | DB source |
|---|---|---|---|---|---|---|
| 1 | Login | POST | `cpms/api/v1/auth/login.php` | `username`, `password`, `device:{platform}` | `access_token`, `expires_in`, `refresh_token`, `refresh_expires_in`, `user:{id,name,role}`, `property:{id,code,name,company_name,logo_url,primary_color,secondary_color}` | `system_users`, `cpms_properties`, `cpms_api_tokens` (insert) |
| 2 | Token refresh | POST | `cpms/api/v1/auth/refresh.php` | `refresh_token` | `access_token`, `expires_in`, `refresh_token`, `refresh_expires_in` | `cpms_api_tokens` (rotate) |
| 3 | Logout | POST | `cpms/api/v1/auth/logout.php` | — (bearer only) | `logged_out: true` | `cpms_api_tokens.revoked_at` |
| 4 | Fetch profile | GET | `cpms/api/v1/me.php` | — | `user:{id,name,username,role}`, `property:{id,code,name}` | `cpms_api_tokens` join `system_users`/`cpms_properties` |
| 5 | Dashboard | GET | `cpms/api/v1/dashboard.php` | — | role-dependent; staff: `shift`,`attendanceState`,`withinLocation`,`stats:{workOrders,pmTasks,attendanceDays,leaveDays}`,`tasks[]`,`pm_tasks[]` | `work_orders`, `cpms_pm_schedules`, `cpms_attendance_sessions`, `cpms_leave_*` |
| 6 | Task list | GET | `cpms/api/v1/staff/tasks.php` | — | `tasks:[{database_id,id,title,location,due,status,priority,image_url}]` — `status` collapsed to `pending`/`in_progress`/`completed` | `work_orders` (+ latest `work_order_images` thumbnail) |
| 7 | Task detail | — | **none** — client re-fetches list and finds by reference | — | — | — |
| 8 | Upload task evidence | POST multipart | `cpms/api/v1/staff/task-photo.php` | `work_order_reference`, `image_type` (`Before`\|`During`\|`After`\|`Supporting`), `photo` (file) | `uploaded:true`, `image_id`, `work_order_reference`, `image_url` | `work_order_images` (insert, append-only) |
| 9 | Complete task | POST multipart | `cpms/api/v1/staff/daily-work/submit.php` (**no discrete complete endpoint — see Work Order Workflow note below**) | `work_order_id`, `work_date`, `work_category`, `block_location`, `specific_location?`, `work_description`, `materials_used?`, `issue_notes?`, `work_status`, `before_images[]?`, `during_images[]?`, `after_images[]?` (after required when status=`Completed`) | `accepted:true`, `reference` | `daily_work_logs` (insert), `daily_work_images` (insert), side-effect `UPDATE work_orders SET status=...`, `work_order_history` (insert) |
| 10 | Daily Work list | GET | `cpms/api/v1/staff/daily-work/list.php` | `status?` | `logs:[{id,reference,date,category,location,description,status,verified,verified_by,verified_at,supervisor_remarks,work_order_reference,images:[{type,url}]}]` | `daily_work_logs` join `daily_work_images`, `work_orders` |
| 11 | Daily Work options | GET | `cpms/api/v1/staff/daily-work/options.php` | — | `categories[]`, `locations[]`, `statuses[]` | hard-coded server-side list (not DB-backed) |
| 12 | **Work Order History (NEW this pass)** | GET | `cpms/api/v1/staff/work-history.php` | — | `work_orders:[{id,reference,title,location,priority,status,completion_notes,completed_at,verification_status,verified_by,verified_at,rejection_reason,daily_work_entries:[...],images:[{type,url}]}]` | `work_orders` + linked `daily_work_logs` (verification decision — see root-cause note below) + `work_order_images` + `daily_work_images` |
| 13 | Attendance clock in/out | POST | `cpms/api/v1/attendance/clock.php` | `action` (`clock_in`\|`clock_out`), `latitude`, `longitude`, `accuracy` | clock_in: `accepted`,`attendance_state:'in'`,`session_id`,`recorded_at`,`distance_m`; clock_out: `attendance_state:'out'`,`worked_minutes`,`overtime_minutes` | `cpms_attendance_sessions`, `cpms_property_geofences` (validation only), `cpms_attendance_events` (audit) |
| 14 | Attendance status/history | — | **none** — no GET endpoint exists | — | — | — |
| 15 | PM list | GET | `cpms/api/v1/staff/maintenance/list.php` | — | `schedules:[{id,schedule_name,asset_name,next_due_date,last_completed_date,priority,frequency,due_status}]` — active schedules only, no completed filter | `cpms_pm_schedules` |
| 16 | PM detail | GET | `cpms/api/v1/staff/maintenance/detail.php` | `id` (query) | `schedule:{...,instructions}`, `history:[...]` — **no checklist field** | `cpms_pm_schedules` + `cpmsPmHistory()` |
| 17 | PM complete | POST multipart | `cpms/api/v1/staff/maintenance/complete.php` | `schedule_id`, `completed_date`, `work_notes`, `evidence` (1 file) | `accepted:true`, `work_log_id` | via `cpmsPmComplete()` service |
| 18 | Asset QR lookup | GET | `cpms/api/v1/staff/asset-inspection/lookup.php` | `token` (query — the QR's `public_token`, parsed from the scanned URL, not the asset's numeric id) | `asset:{name,code,category,location}`, `checklist_items[]` (real, category-specific) | `assets` |
| 19 | Asset inspection submit | POST multipart | `cpms/api/v1/staff/asset-inspection/submit.php` | `asset_token`, `condition_result`, `findings`, `action_taken`, `work_order_required`, `checklist[]`, `inspection_images[]` (1–5 files) | `accepted:true`, `inspection_reference` | `asset_inspections`, `asset_inspection_images` |
| 20 | Notifications list | GET | `cpms/api/v1/notifications.php` | — | `notifications:[{id,type,category,priority,title,message,reference,action_url,is_read,created_at}]` | `cpms_notifications` |
| 21 | Mark notification read | POST | `cpms/api/v1/notifications/mark-read.php` | `id` | `accepted:true` | `cpms_notifications.is_read` |
| 22 | Mark all read | POST | `cpms/api/v1/notifications/mark-all-read.php` | — | `accepted:true` | `cpms_notifications.is_read` (bulk) |
| 23 | Corrective actions list/detail/update | GET/GET/POST | `staff/corrective-actions/{list,detail,update}.php` | — | — | `inspection_corrective_actions` | **Not wired into Flutter yet** — read during this audit but out of scope to add as a new UI surface this pass (see AUDIT_REPORT §6). |

## Work Order Workflow — the authoritative verification source

This is the single most important contract fact in this document, because guessing it wrong is
exactly what produced the original "Work Order History is broken" symptom:

- `work_orders.status` is set by **staff**, as a side effect of `daily-work/submit.php`
  (`Open`/`Assigned` → `In Progress`/`Pending Material`/`Pending Contractor` → `Completed`). It is
  **never** set to `Verified` or `Rejected` by anything.
- The Property Admin's **Verify**/**Reject** decision (`cpms/property_portal/daily_work_review.php`)
  writes to **`daily_work_logs.work_status`** (`'Verified'`/`'Rejected'`), plus
  `daily_work_logs.supervisor_remarks`, `verified_by`, `verified_at`. It does not touch
  `work_orders` at all.
- Therefore the real "Pending Verification / Verified / Rejected" state for a work order is
  reconciled from its **most recent linked `daily_work_logs` row** (via `work_order_id`), not from
  `work_orders.status` alone. `staff/work-history.php` (new) does exactly this reconciliation
  server-side so Flutter never has to guess it client-side.

## Image URL contract

Every uploaded-file URL returned by any endpoint above (`image_url`, `images[].url`) is
**root-relative** (e.g. `/cpms/uploads/daily_work/xxxxxxxxxxxxxxxx.jpg`), following the same
convention `cpmsApiSaveImage()` and the Property Admin's own review page use server-side. Flutter
resolves these against `AppConfig.apiBaseUrl` via `AppConfig.resolveUrl()` before ever handing a
URL to an image widget — this is required, not optional, because `FullScreenImageViewer`
distinguishes "local file" vs. "remote URL" purely by whether the string starts with `http`.

Confirmed real filesystem locations (traced from the actual upload code, not guessed):
- Work order evidence (`task-photo.php`): `cpms/uploads/work_orders/property_<id>/<random>.{jpg,png,webp}`
- Daily Work photos (`daily-work/submit.php`): `cpms/uploads/daily_work/<random>.{jpg,png,webp}` (flat, no property subfolder)
- PM evidence: `cpms/uploads/preventive_maintenance/...`
- Asset inspection photos: `uploads/asset_inspections/...` (outside `cpms/` — a pre-existing
  inconsistency in the backend, not introduced by this pass; noted for awareness only since Flutter
  doesn't currently render an inspection photo gallery).

## Endpoints intentionally NOT created

Per the task's explicit instruction not to create unnecessary duplicate endpoints:
- **No** `staff/tasks/{id}.php`, `.../accept.php`, `.../start.php`, `.../complete.php` — Flutter
  adapts to the real Daily-Work-driven workflow instead (see AUDIT_REPORT §4).
- **No** attendance status/history endpoint — the app tracks last-confirmed clock state from
  clock action responses instead (AUDIT_REPORT M-1).
- **No** FCM device-registration endpoint — no FCM client exists to register (AUDIT_REPORT M-6).
