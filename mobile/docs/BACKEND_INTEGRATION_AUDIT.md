# CPMSPro Staff Mobile App — Backend Integration Audit

**Status: Re-audit complete (Phase 2). No backend or Flutter implementation has been performed as part of this pass.** This document supersedes the first audit (originally written when `/backend` did not exist in this repository). The real CPMSPro PHP backend has since been added under `backend/`, and this document reflects what was actually found there, verified by reading the source — not assumed.

---

## 1. Executive Summary

**The headline finding: the backend team already anticipated this exact integration.** Under `backend/cpms/api/v1/` there is a purpose-built, token-based, versioned REST API (self-described internally as "CPMS Workforce Android production API foundation", schema version `4.0.1`) that is materially more mature than a typical greenfield audit turns up: bearer-token authentication with hashed tokens and expiry, property-scoped authorization enforced from the token (never from client input), server-side GPS geofence validation with distance/accuracy checks, disciplined file-upload handling (magic-byte MIME validation, random server-generated filenames, size caps, transactional writes with cleanup on failure), and a real audit-log table. This is running **alongside** an older, separate, session-cookie-based PWA staff portal (`staff_dashboard.php`, `staff_work_orders.php`, etc. at the repo root) that is still the live production staff experience today — the two are not in conflict, but Stage work must not touch the PWA portal (Phase 10 of the brief) while wiring the Flutter app to the new API.

**None of this means the Flutter app can point at it today without changes.** The API was evidently built to its own (reasonable, internally consistent) contract, not to `mobile/lib/core/api/api_endpoints.dart`'s contract, because the two were built independently. Every endpoint below is one of `EXISTS` (works as Flutter currently expects), `PARTIAL` (endpoint exists, shape/fields differ enough to need adapting), `MISMATCH` (endpoint exists but the path, method, or fundamental request/response model differs), or `MISSING` (no backend equivalent at all). The short version: **most of the *capability* Flutter needs already exists server-side; almost none of the exact *contracts* match**, and one workflow (mandatory PM checklists) plus one infrastructure piece (FCM push) are genuinely not built yet.

The single most important structural finding, because it reshapes Stage 4 rather than being a field-rename: **the real backend has no "Accept Task" / "Start Task" endpoints and no PM checklist model.** Work order status advances as a *side effect* of staff submitting a Daily Work log entry (which optionally links to a work order), not through discrete accept/start/complete calls. And the "mandatory checklist" concept the Flutter app built for Preventive Maintenance actually exists server-side for a *different* feature — QR-triggered **Asset Inspection** — where it's a real, category-specific, working checklist. PM schedules (`cpms_pm_schedules`) have no checklist at all, just free-text notes and one photo. §5 and §11 detail this; §14's Stage 4/7/8 recommendations are written around it.

---

## 2. What Existing CPMSPro Functionality Can Be Reused

This is substantial, and worth stating plainly before the mismatch details, because it's the part that de-risks the whole integration:

- **Token-based auth is already built and secure** (`cpms/api/v1/auth/login.php`, `logout.php`, `bootstrap.php`) — SHA-256-hashed bearer tokens in a dedicated `cpms_api_tokens` table, 8-hour expiry, per-token revocation, login rate-limiting (5 failed attempts / 15 min / IP+username), and it reuses the *same* underlying credential store (`system_users`, `password_verify`/bcrypt) as every other CPMSPro portal via a shared `cpmsUnifiedAuthenticate()` function — so a staff member's existing CPMSPro password works immediately, no parallel credential system needed.
- **Property scoping is done correctly at the source.** `property_id` is resolved once, server-side, from the authenticated token (joined through `cpms_api_tokens` → `system_users` → `user_roles`/`roles`), and is re-verified against `user_roles` on every single authenticated request (`cpmsApiAuth()`). No endpoint reviewed accepts a client-supplied `property_id` for authorization purposes.
- **GPS geofencing is fully implemented server-side**, per-property, with a real `cpms_property_geofences` table (center point, radius, max accepted GPS accuracy, an enforcement on/off flag), proper Haversine distance calculation, and rejection of both out-of-radius and low-accuracy fixes — this is exactly the "backend must validate the attendance request" requirement from the original spec, already done (`cpms/api/v1/services.php::cpmsApiValidateWorkLocation`).
- **File upload handling is production-grade and consistent** everywhere it's used (task photos, daily work photos, PM evidence, asset inspection photos): server-side magic-byte MIME detection (`finfo`, cross-checked with `getimagesize()` in the daily-work and asset-inspection paths), a 5MB cap, server-generated random filenames (no client filename ever trusted, no path traversal surface), and transactional DB writes with automatic file cleanup on failure.
- **Evidence photos are genuinely append-only** — no code path reviewed updates or deletes an uploaded evidence image row. `work_order_images`, `daily_work_images`, `asset_inspection_images` are all insert-only. This satisfies the audit-preservation requirement (§9) without any backend change needed.
- **A real audit log exists** (`cpms_api_audit_logs`) and is written to on every significant action (login, logout, task photo, corrective-action status change, PM completion, daily work submission, asset inspection) with property/user/IP/user-agent/metadata — a genuine compliance trail already in place.
- **A working inspection corrective-action review workflow exists**, including a rule that staff can only set status to `In Progress` or `Rectified` — never `Verified`/`Closed` — enforced server-side with an explicit comment confirming the intent (`cpms/api/v1/staff/corrective-actions/update.php`): "Staff hanya boleh tetapkan status... bukan Verified/Closed - itu untuk admin." This is precisely spec §10's "staff cannot mark management verification themselves," already correct.
- **Per-property branding data already exists in the database** — `cpms_properties` has `logo_path`, `primary_color`, `secondary_color` columns (confirmed via `cpms/includes/property_theme.php` and the Property Admin's `branding_settings.php`) — the mobile API's `login.php`/`me.php` simply don't `SELECT` them yet. This is a small, low-risk addition, not new schema work.
- **A permission engine exists in the schema** (`permissions`, `role_permissions` tables, with entries like `workforce.api.use`) that could eventually back Flutter's granular `StaffPermission.*` gating — it isn't wired into the mobile API's authorization check yet (which currently checks only the coarse `role_code`, staff vs security), but the foundation is there for Stage 11.
- **Push infrastructure exists — but it's Web Push (VAPID) for the browser-based PWA, not Firebase Cloud Messaging.** `pwa_push_setup.php`/`_subscribe.php`/`_dispatch.php` are a complete, working VAPID web-push system for `workforce-app`/the PWA staff portal. This cannot be reused as-is for the Flutter app's `firebase_messaging` dependency, but the underlying event source it dispatches from (`cpms_notifications`) is exactly what the mobile API's `notifications.php` already reads, so an FCM channel added later can share that same table rather than needing new business logic.
- **Categories, locations, and status enums for Daily Work are backend-defined and endpoint-fetchable** (`cpms/api/v1/staff/daily-work/options.php`) rather than needing to be hardcoded in Flutter at all — this is *better* than what Flutter currently does (hardcodes its own, different, list) and should simply be adopted.

---

## 3. Real Backend Architecture

- **Stack:** Plain PHP 8.2 (cPanel `ea-php82` handler), no framework, no Composer, raw `mysqli` with prepared statements used almost everywhere (62 `->prepare()` calls vs. 2 `->query()` calls across the entire `api/v1/` tree, and both of the latter are on hardcoded/escaped internal strings, never user input — see §10).
- **Two parallel staff-facing systems, both real, neither to be broken:**
  - **Legacy/current production PWA** — root-level `staff_*.php` (session-cookie auth via `$_SESSION['staff_id']`, `staff_pwa_bootstrap.php`, `staff_manifest.php`), server-rendered HTML, installable as a home-screen PWA (`workforce-app/`). `staff_login.php` at the repo root is now just a redirect stub to the unified login — the comment literally says "Legacy staff login is retired. All staff must use Unified Login" — but the *rest* of the PWA (`staff_dashboard.php`, `staff_work_orders.php`, etc.) is still live and session-based, still serving real staff today.
  - **New token-based REST API** (`cpms/api/v1/`) — the integration target for the Flutter app. Purpose-built, versioned, JSON-only, bearer-token auth. Both systems share the same underlying `system_users`/`staff`/`work_orders`/etc. tables via the same `cpmsUnifiedAuthenticate()` engine, so a staff account works identically in both.
- **DB credentials are correctly never committed.** Only `db.example.php` templates exist at both `backend/db.example.php` and `backend/cpms/db.example.php`; the real `db.php` is expected outside the web-servable tree (`foundation_bootstrap.php` looks one level above the deployed app root) or at `backend/db.php` / `cpms/db.php` depending on which of two bootstrap generations a given script uses (see §10, finding S-M1 — this dual expectation is itself a small hardening item).
- **Response envelope (applies to every `api/v1` endpoint):** `{"ok": true, "data": {...}}` on success, `{"ok": false, "error": {"code", "message", "details"?}}` on failure, with a matching HTTP status code (200/201/400/401/403/404/409/422/429/500/503). Flutter's `ApiClient.request()` currently parses `response.data` directly as the payload — **every real repository implementation needs to unwrap `response.data['data']`** before the existing `fromJson` parsers will work. This is one small, centralized change (in `ApiClient.request` or each repository's parse callback), not a per-endpoint rewrite.
- **CORS is wide open** (`Access-Control-Allow-Origin: *`) — irrelevant for a native mobile client (CORS is a browser mechanism) but noted in §10 as unusually permissive if anything browser-based ever calls this API directly.
- **Notable repo cleanliness item (not a security bug):** `backend/cpms/cpms/` is a duplicate, partially-stale copy of `backend/cpms/includes/` (confirmed `unified_auth.php` *differs* between the two copies; `mobile_task_inbox_service.php` is identical). The live code path (verified by tracing `bootstrap.php`'s actual `require_once`) uses `backend/cpms/includes/`, not the duplicate. Worth a cleanup pass before production but does not affect the integration plan below.

### Confirmed table names (from code, not guessed)

`system_users`, `user_roles`, `roles`, `permissions`, `role_permissions`, `staff` (`id, full_name, username, password, role, phone, account_status` — **no email, no photo, no distinct employee number**), `security_guards`, `cpms_properties` (`id, property_code, property_name, timezone_name, is_active, logo_path, primary_color, secondary_color`), `cpms_property_geofences`, `cpms_api_tokens`, `cpms_api_login_attempts`, `cpms_api_audit_logs`, `work_orders` (`assigned_staff_id`), `work_order_images`, `work_order_history`, `inspection_reports`, `inspection_corrective_actions` (`assigned_system_user_id` — note: a *different* FK convention than `work_orders`), `cpms_pm_schedules` (`assigned_system_user_id`, no checklist columns), `assets` (`public_token`, `asset_category`, `asset_status`), `asset_inspections`, `asset_inspection_images`, `daily_work_logs`, `daily_work_images`, `cpms_attendance_sessions`, `cpms_attendance_events`, `cpms_attendance_shifts`, `cpms_leave_balances`, `cpms_leave_requests`, `cpms_notifications`, `cpms_workforce_patrol_sessions`, `cpms_workforce_checkpoint_events`, `cpms_security_incidents`, `security_checkpoints`.

---

## 4. Complete API Comparison Matrix

| Flutter Endpoint (`api_endpoints.dart`) | Status | Real Backend Endpoint | Notes |
|---|---|---|---|
| `POST /api/v1/auth/login` | **MISMATCH** | `POST cpms/api/v1/auth/login.php` | Exists and works, but: different base path (`/cpms/api/v1/...`, `.php` extension — no clean-URL rewrite configured), envelope-wrapped response, and a completely different payload shape (§5.1). No `refresh_token` in the response at all. |
| `POST /api/v1/auth/logout` | **EXISTS** | `POST cpms/api/v1/auth/logout.php` | Revokes the token server-side. Response shape (`{logged_out:true}` inside the envelope) is compatible with Flutter's fire-and-forget usage. |
| `POST /api/v1/auth/refresh` | **MISSING** | — | No refresh endpoint anywhere in `api/v1`. Tokens simply expire after 8 hours and require a fresh login. See §5.1/§14 Stage 1. |
| `GET /api/v1/staff/profile` | **MISMATCH** | `GET cpms/api/v1/me.php` | Different path, and returns far less than Flutter's `StaffUser.fromJson` needs — no `permissions[]`, no `property_branding`, no `email`/`profile_image`/`employee_id` (columns don't exist on `staff` at all). See §5.2. |
| `GET /api/v1/app/config` | **MISSING** | — | No standalone branding/config endpoint exists. Branding data exists in the DB (§2) but isn't exposed via any `api/v1` endpoint yet. |
| `GET /api/v1/staff/dashboard` | **MISMATCH** | `GET cpms/api/v1/dashboard.php` | Exists, but a fundamentally different shape: attendance/leave/shift-centric (`shift`, `attendanceState`, `stats:{workOrders,pmTasks,attendanceDays,leaveDays}`), not task-KPI-centric. No `today_overview{total,completed,pending,overdue}`, no `announcements`, no single `priority_task`. See §5.3. |
| `GET /api/v1/staff/tasks` | **PARTIAL** | `GET cpms/api/v1/staff/tasks.php` | Exists and returns work orders only — no corrective actions, PM, or daily assignments merged in (those are separate endpoints, §5.4). Field shapes also differ (`due` is a pre-formatted `d/m/Y` string, `status` collapses to 3 buckets). |
| `GET /api/v1/staff/tasks/{id}` | **MISSING** | — | No single-work-order detail-by-ID endpoint found in `api/v1`. `staff/tasks.php`'s list response is the only way to read work order fields today. |
| `POST /api/v1/staff/tasks/{id}/accept` | **MISSING** | — | No accept/acknowledge endpoint exists for work orders anywhere in `api/v1`. |
| `POST /api/v1/staff/tasks/{id}/start` | **MISSING** | — | Same — no discrete "start" action. Status only advances via the Daily Work submission flow (§5.4, §14 Stage 4). |
| `POST /api/v1/staff/tasks/{id}/evidence` | **MISMATCH** | `POST cpms/api/v1/staff/task-photo.php` | Exists, works, is append-only and property/staff-scoped correctly — but is a flat endpoint keyed by `work_order_reference` (POST field) + `image_type` enum (`Before/During/After/Supporting`), not a per-ID nested path with a `before_photo_id` link. No GPS/device-timestamp metadata captured on the photo at all. Field name is `photo`, not `file`. See §5.5. |
| `POST /api/v1/staff/tasks/{id}/complete` | **MISSING (as a discrete action)** | `POST cpms/api/v1/staff/daily-work/submit.php` (side effect) | Completion is not a dedicated call — it's an emergent side effect of submitting a Daily Work log with `work_status=Completed` and `work_order_id` set. See §5.4, §14 Stage 4. |
| `GET /api/v1/staff/daily-work` | **PARTIAL** | `GET cpms/api/v1/staff/daily-work/list.php` *(file present, not yet read in detail — same directory pattern as `submit.php`/`options.php`)* | Present; verify exact response shape during Stage 6 implementation. |
| `POST /api/v1/staff/daily-work` | **MISMATCH** | `POST cpms/api/v1/staff/daily-work/submit.php` | Exists, well-built, but a completely different category/location enum than Flutter's hardcoded lists, image field names are grouped (`before_images[]`/`during_images[]`/`after_images[]`, max 3 each), and — importantly — this same endpoint is also the work-order status-transition mechanism (§5.4). |
| *(no Flutter equivalent yet)* | **EXISTS, unused by Flutter** | `GET cpms/api/v1/staff/daily-work/options.php` | Server-authoritative categories/locations/statuses. Flutter should call this instead of hardcoding `DailyWorkCategory`. |
| `GET /api/v1/pm/tasks` | **MISMATCH** | `GET cpms/api/v1/staff/maintenance/list.php` | Exists (`cpms_pm_schedules`), different path, no way to list *completed* PM (hardcoded `status='active'` filter), and **no checklist field at all** — see §5.6. |
| `GET /api/v1/pm/tasks/{id}` | **PARTIAL** | `GET cpms/api/v1/staff/maintenance/detail.php` *(present, not yet read line-by-line)* | Present; confirm exact shape during Stage 8. |
| `POST /api/v1/pm/tasks/{id}/complete` | **MISMATCH** | `POST cpms/api/v1/staff/maintenance/complete.php` | Exists — `schedule_id`, `completed_date`, `work_notes`, single `evidence` file. **No checklist submission of any kind.** See §5.6. |
| *(no Flutter equivalent)* | **EXISTS, feature belongs to Assets not PM** | `GET cpms/api/v1/staff/asset-inspection/lookup.php`, `POST .../submit.php` | This is where the real, working, category-specific mandatory checklist lives (`Water Pump`, `Boom Gate`, `Fire Extinguisher`, etc. — 10 predefined sets + a generic fallback). Triggered by QR scan (`?token=<public_token>`). See §5.7/§14 Stage 9. |
| `GET /api/v1/assets/{id}` | **MISMATCH** | `GET cpms/api/v1/staff/asset-inspection/lookup.php?token=` | Assets are looked up by an opaque `public_token` string embedded in a **full URL** in the physical QR code (confirmed via `admin_asset_qr.php`), not by numeric ID and not by a `CPMSPRO:ASSET:<id>` prefix as Flutter's `QrScannerScreen` currently assumes. See §5.7. |
| `GET /api/v1/notifications` | **MISMATCH** | `GET cpms/api/v1/notifications.php` | Exists, property/role/user-targeted correctly. `action_url` is a **web portal URL**, not a Flutter route string — Flutter's `deep_link_route` assumption doesn't hold; recommend deriving the in-app route client-side from `type`/`category`/`reference` instead (§5.8). |
| `POST /api/v1/notifications/{id}/read` | **PARTIAL** | `POST cpms/api/v1/notifications/mark-read.php` (+ `mark-all-read.php`, which Flutter has no equivalent for) | Path differs (flat endpoint + ID likely as a body/query field, not path segment — confirm exact call shape in Stage 10). |
| `POST /api/v1/notifications/devices` | **MISSING** | — | No FCM device-token registration endpoint. The existing push system is VAPID/web-push for the PWA, not FCM (§2, §14 Stage 12). |
| Offline sync (generic replay) | **N/A — Flutter-side gap, not backend** | — | Every endpoint above supports being called after the fact (nothing is inherently non-replay-safe at the HTTP level), but **no endpoint has an idempotency-key mechanism**, and — separately, purely on the Flutter side — only task-evidence upload is ever actually queued for offline retry today (carried over from the first audit, §7 below). |

**Read but not yet exhaustively detailed above** (confirmed to exist, contract not fully pinned down in this pass — flag for the relevant implementation stage): `staff/corrective-actions/list.php`/`detail.php`/`update.php`/`upload-image.php` (all read and detailed in §5.9/§11), `staff/maintenance/detail.php`, `staff/daily-work/list.php`, `notifications/mark-all-read.php`, `security/*` (out of scope — security-guard role, not staff).

---

## 5. Mismatch Detail — Request / Expected / Actual / Recommendation

### 5.1 Login

- **Flutter request:** `{username, password, device_session_id}`
- **Flutter expects:** flat `{user_id, staff_id, property_id, staff_name, role, employee_id?, phone?, email?, profile_image?, permissions[], property_branding{}, access_token, refresh_token, expires_at, device_session_id}`
- **Actual backend:** `POST cpms/api/v1/auth/login.php` takes `{username, password, device:{platform?, app_version?}}`; on success responds (inside the `{ok,data}` envelope) with `{access_token, expires_in: 28800, user:{id,name,role}, property:{id,code,name}}`. **No `refresh_token` field exists at all** — 8-hour fixed expiry, re-login only.
- **Recommendation:** Adapt `StaffUser.fromJson`/`LoginResult` on the Flutter side to this real shape (make `refreshToken` optional/nullable, since re-login-on-expiry is a legitimate and simple design choice already made server-side); drop the `device_session_id` field Flutter currently invents in favor of the real `device{platform,app_version}` object. Do not add a refresh endpoint to the backend unless a product decision is made that 8-hour re-login is unacceptable UX — that's a real tradeoff, not an oversight (see §14 Stage 1 for both options).

### 5.2 Profile

- **Flutter expects:** same flat shape as login, re-fetchable via `GET /staff/profile`.
- **Actual backend:** `GET cpms/api/v1/me.php` → `{user:{id,name,username,role}, property:{id,code,name}}`. No `permissions[]`, no branding, no `staff_id` distinct from the RBAC `system_user_id`, no `email`/`profile_image`/`employee_id` (these columns don't exist on `staff` at all — a genuine schema gap, not just an API omission).
- **Recommendation:** Extend `me.php` (and `login.php`) to also `SELECT` and return `cpms_properties.logo_path/primary_color/secondary_color` (trivial — data already exists) and the resolved `staff.id`/`staff.phone` (already selectable). `email`, `profile_image`, and a distinct `employee_id` need new columns on `staff` if the product wants them — until then, Flutter's existing `?? ''` fallbacks already degrade gracefully, so this can be deferred past Stage 1/2 without blocking anything.

### 5.3 Dashboard

- **Flutter expects:** `{today_overview:{total_tasks,completed,pending,overdue}, priority_task?, recent_tasks[], announcements[]}`.
- **Actual backend:** `{shift, attendanceState, withinLocation, stats:{workOrders,pmTasks,attendanceDays,leaveDays}, tasks:[...12 max, work orders only], pm_tasks:[...]}`.
- **Recommendation:** This needs a genuine Flutter-side redesign of `DashboardData`/`_DashboardContent`, not a parser tweak — the backend's dashboard is attendance/leave-first, Flutter's mock was task-KPI-first. Recommend keeping the real shape and reshaping `HomeScreen`'s KPI row around what the backend actually computes (`workOrders`, `pmTasks`, `attendanceDays`, `leaveDays`) rather than inventing a `completed`/`pending`/`overdue` breakdown the backend doesn't calculate. No `announcements` concept exists server-side at all — either drop that section from Stage 2/3 or scope a new small backend feature for it later.

### 5.4 Task list, detail, and status transitions — the central architectural mismatch

- **Flutter expects:** `GET /staff/tasks` (all task types merged), `GET /staff/tasks/{id}` (detail), then discrete `POST .../accept`, `.../start`, `.../complete` calls driving an 8-state status enum (`newTask→accepted→inProgress→workCompleted→pendingVerification→verified/rejected`).
- **Actual backend:** `GET staff/tasks.php` returns **work orders only** (`Open/Assigned/In Progress/Completed/Verified/Cancelled`, collapsed to 3 buckets in the API response: `pending/in_progress/completed`). There is no work-order detail-by-ID endpoint, no accept/start endpoint. Status advances only as a side effect of `POST staff/daily-work/submit.php` when `work_order_id` is set and `work_status` is `Completed`/`Pending Material`/etc. — that same call also creates a `work_order_history` row. Corrective actions (a separate resource, `inspection_corrective_actions`) *do* have their own list/detail/update endpoints with a real (though different: `Open→In Progress→Rectified→[Verified/Closed by admin]`) status machine, and a rejection concept (`supervisor_status='Rejected'`) exists in the admin-side review flow but **is not yet exposed through the staff-facing `corrective-actions/detail.php` response** — confirmed no `rejection_reason` field returned there today.
- **Recommendation — this is a product decision, present both options rather than picking silently:**
  1. **Adapt Flutter to the real workflow.** Build the "unified task inbox" by calling `staff/tasks.php` + `staff/corrective-actions/list.php` + `staff/maintenance/list.php` + `staff/daily-work/list.php` in parallel and merging client-side (the existing repository-interface pattern supports this cleanly — `TasksRepository.fetchTasks()` becomes a fan-out). Drop the discrete Accept/Start actions for work orders (there's nothing for them to call); "starting" a work order becomes implicit in submitting the first Daily Work entry against it. Extend `corrective-actions/detail.php` server-side to also return `supervisor_status`/rejection notes so Flutter's rejection banner has real data.
  2. **Add lightweight new backend endpoints** (`staff/tasks/{id}.php`, `staff/tasks/{id}/accept.php`, `staff/tasks/{id}/start.php`) that just flip `work_orders.status` without requiring a full Daily Work log entry, if the product wants the simpler four-button Flutter UX to survive unchanged.
  
  Recommend **option 1** — it requires zero new backend endpoints, reuses a workflow that's already live, tested, and audited in production (via the PWA), and the "unified inbox = fan-out + merge" pattern is something the Flutter architecture was already built to do cleanly. Flag this explicitly for user sign-off before Stage 4 starts, since it changes the on-screen task-detail workflow, not just a data shape.

### 5.5 Evidence upload

- **Flutter sends:** multipart to `/staff/tasks/{id}/evidence`, field `file`, plus `before_photo_id?`, `gps_lat?`, `gps_lng?`, `device_timestamp`.
- **Actual backend:** multipart to `POST staff/task-photo.php`, field `photo`, plus `work_order_reference` (not the DB id — the human reference string), `image_type` (`Before/During/After/Supporting` — an explicit type field, not a link to a specific before-photo). No GPS or device-timestamp columns captured at all for task photos.
- **Recommendation:** Rework `TasksRepository.uploadEvidence` to send `work_order_reference` + `image_type=After` instead of `before_photo_id`; drop the GPS/timestamp fields for this call (they're accepted silently server-side today only in the sense that unknown POST fields are simply ignored, not stored) or propose adding `latitude/longitude/accuracy` columns to `work_order_images` as a small Stage 5 backend addition if photo geotagging is wanted. Also fix the internal Flutter inconsistency noted in the first audit — the offline-replay path used `files[0]` while the online path used `file`; once this endpoint's real field name (`photo`) is adopted, make sure the offline-replay `SyncHandlers` path uses the same field name too.

### 5.6 Preventive Maintenance — no checklist exists

- **Flutter expects:** `PmTask.checklist: List<PmChecklistItem>` with mandatory/optional items, ticked server-side or at least submitted as part of completion.
- **Actual backend:** `cpms_pm_schedules` has no checklist columns; `maintenance/complete.php` accepts only `schedule_id, completed_date, work_notes`, and a single `evidence` photo. `maintenance/list.php` also has no `status=completed` filter option (hardcoded to active schedules only).
- **Recommendation:** This is genuinely **MISSING**, not a mismatch — see §14 Stage 8 for the real options (add checklist schema to PM, or point Flutter's checklist UI at Asset Inspection instead, per §5.7/§2).

### 5.7 Assets / QR

- **Flutter expects:** `assets/{id}` by numeric/string ID; QR payload assumed to be `CPMSPRO:ASSET:<id>` or a bare ID.
- **Actual backend:** Assets are looked up by `public_token` (`asset-inspection/lookup.php?token=`), and the physical QR code encodes a **full URL** with that token as a query parameter (confirmed in `admin_asset_qr.php`'s QR-generation code — `$base . rawurlencode($publicToken)`). Scanning returns `{asset:{name,code,category,location}, checklist_items:[...]}` — a real, working, category-specific mandatory checklist (10 predefined sets: Lamp Post, Water Pump, Pump Control Panel, Boom Gate, Solar CCTV, Water Tank, Fire Hydrant, Fire Extinguisher, Playground, Drainage; generic fallback otherwise). Submission (`asset-inspection/submit.php`) takes `asset_token, condition_result (enum), findings, action_taken, work_order_required, checklist[] (array of checked-item strings), inspection_images[] (1-5 files)`.
- **Recommendation:** Rewrite `QrScannerScreen._extractAssetId` to parse a `token` query parameter out of a scanned URL (not a `CPMSPRO:ASSET:` prefix), and repoint the whole "PM checklist" UX Flutter already built (`PmDetailScreen`, `PmChecklistItem`) at this Asset Inspection flow instead of `cpms_pm_schedules` — it's a much closer match to what Flutter's UI already expects than PM schedules are. This single change resolves both the QR-format `UNKNOWN` from the first audit and the missing-checklist gap from §5.6 at once.

### 5.8 Notifications

- **Flutter expects:** `deep_link_route` as a literal Flutter/GoRouter path string, backend-supplied.
- **Actual backend:** `action_url` is a web-portal URL (property_portal path), plus `type`, `category`, `reference` fields.
- **Recommendation:** As recommended in the first audit — derive the in-app route client-side from `type`/`reference` (e.g. a `type: 'work_order'` + `reference: 'WO-2026-0082'` maps to `/tasks/WO-2026-0082`) rather than trying to reuse `action_url` directly. This also happens to be the more resilient design regardless of backend availability.

### 5.9 Daily Work categories/locations

- **Flutter hardcodes:** 8 camelCase categories (`cleaning, maintenance, electrical, plumbing, landscaping, generalWork, inspectionSupport, other`), free-text location.
- **Actual backend:** 16 Title Case categories and a fixed 13-item location list, both served from `daily-work/options.php`.
- **Recommendation:** Delete Flutter's hardcoded `DailyWorkCategory` enum values in favor of calling `options.php` at screen-open time and populating both dropdowns dynamically. This is the correct fix regardless — it means new categories/locations added by CPMSPro admins in the future need zero Flutter app updates.

---

## 6. Multi-Property Security

**This is the strongest part of the real backend.** `cpmsApiAuth()` (`bootstrap.php`) resolves `property_id` exclusively from the joined `cpms_api_tokens` → `system_users` → `cpms_properties` chain, and independently re-verifies an active `user_roles` assignment for that exact `(system_user_id, property_id, role_code)` triple on every request — not just at login. Every endpoint read in this audit that accepts a resource ID (`corrective-actions/detail.php`, `.../update.php`, `maintenance/complete.php`, `task-photo.php`, `daily-work/submit.php`'s linked-work-order lookup) includes `property_id=?` (and usually `assigned_staff_id=?`/`assigned_system_user_id=?`) in its `WHERE` clause and returns a generic 404 (`NOT_FOUND`) rather than leaking existence of another property's record. No endpoint reviewed accepts a client-supplied `property_id` for authorization purposes anywhere.

**No cross-property IDOR was found in the endpoints read.** This should still be verified with an actual property-isolation test suite before production (§14, Stage 11 — Test Criteria), since a code-reading audit cannot substitute for a live cross-account test, but nothing in the source suggests a gap here. The one thing worth double-checking during Stage 4/9 implementation specifically: confirm `staff/maintenance/detail.php` and `staff/daily-work/list.php` (present but not yet read line-by-line in this pass) follow the same `property_id + assigned_*_id` filtering pattern as every other detail/list endpoint — flag this as a verification task, not a known gap.

**Recommendation:** keep doing exactly what's already being done — resolve `property_id` from the token, filter every query by it, never accept it from the client. Flutter's existing behavior (confirmed in the first audit: no repository ever sends `property_id`/`staff_id` in a request) is already correctly aligned with this and needs no change.

---

## 7. Authentication Compatibility — Session vs. Token

**Recommendation: use the existing `cpms/api/v1` bearer-token layer as-is. Do not attempt to make the mobile app share the web portal's session cookies.** This isn't a new recommendation the audit is introducing — it's confirming that the backend team already built and reached the same conclusion: `cpms/api/v1/bootstrap.php` is a completely separate, stateless, token-based auth path that happens to authenticate against the *same* underlying `system_users`/`staff` credential store as the cookie-based web portals (`cpmsUnifiedAuthenticate()` is shared). This is the textbook-correct approach — session cookies are a poor fit for a native mobile client (cookie jar management, CSRF concerns, no clean multi-device story), and building a parallel token system that shares credentials rather than sessions avoids duplicating the password/account model.

The only real gap in this layer is the missing refresh-token mechanism (§5.1) — that's a UX/session-lifetime decision, not an architecture problem: the token model itself (hashed, expiring, revocable, auditable) is sound and should not be redesigned.

---

## 8. Photo/Evidence Upload Review

- **Storage locations (confirmed from code):** `cpms/uploads/work_orders/property_<id>/`, `uploads/daily_work/` (note: this one path resolves *outside* `cpms/`, at `dirname(__DIR__,4)/uploads/...` from `daily-work/submit.php` — worth double-checking this lands in the intended directory during Stage 6, it's a slightly different relative-path convention than the other three upload endpoints use), `cpms/uploads/preventive_maintenance/`, `uploads/asset_inspections/`.
- **Database relationships:** `work_order_images.work_order_id`, `daily_work_images.daily_work_id`, `asset_inspection_images.inspection_id` — all simple insert-only child tables, no soft-delete/versioning columns because none are ever needed (nothing ever updates or deletes a row).
- **MIME/size validation:** real, server-side, magic-byte-based (`finfo`), 5MB cap, JPEG/PNG/WebP only — confirmed correct and consistent across all four upload endpoints (daily-work and asset-inspection additionally cross-check with `getimagesize()`; task-photo and PM-complete do not do the second check — a minor inconsistency worth normalizing, not a vulnerability, since `finfo` alone is already a real magic-byte check, not a trust-the-client one).
- **Before/after mapping:** handled via an explicit `image_type` enum (task photos: `Before/During/After/Supporting`; daily work: grouped `before_images[]/during_images[]/after_images[]` fields) rather than an explicit "this after-photo links to that before-photo" foreign key. Flutter's `beforePhotoId` linking concept has no backend equivalent and should be dropped in favor of the type-enum model (§5.5).
- **Rejection evidence preservation:** confirmed append-only everywhere (§2) — nothing to fix.
- **Offline replay compatibility:** every upload endpoint is a standard multipart POST with no session-specific state beyond the bearer token, so a queued-while-offline photo can be replayed later exactly as designed in Flutter's `SyncHandlers.dispatch()` generic replayer — **once the field-name mismatches in §5.5 are fixed** so the replayed request matches what the endpoint actually expects.
- **Gap carried over from the first audit, now confirmed against the real endpoint too:** no endpoint accepts or returns an idempotency key, so a replayed-after-timeout upload has no server-side duplicate guard beyond "a human notices two nearly-identical photos in the list." See §9.

---

## 9. Offline Sync Design vs. Real Backend

The first audit's Flutter-side finding stands and is now confirmed compatible with (not contradicted by) the real backend: **every** endpoint reviewed is a plain, stateless HTTP call with no server-side session/sequence requirement, so the existing `PendingSyncItem` + generic `SyncHandlers.dispatch()` replay design will work against the real backend once the per-endpoint field/path mismatches in §5 are corrected. Nothing about the real backend requires a redesign of the offline-queue *mechanism* — only its *targets*.

What must change to safely support all four flows, now with real endpoint specifics:

- **Task evidence** (`staff/task-photo.php`): already the one flow Flutter's offline queue supports (mock-only today, per the first audit) — needs its field names/path corrected per §5.5, and needs extending from `MockTasksRepository` into `ApiTasksRepository` (currently has zero offline handling — first audit finding, still true, backend availability doesn't change it).
- **Attendance** (`attendance/clock.php`): note this is a **single combined endpoint** with an `action:'clock_in'|'clock_out'` discriminator, not two separate endpoints as Flutter's `ApiEndpoints.attendanceClockIn/attendanceClockOut` currently assume — fix that mismatch first. The backend already has strong *natural* duplicate protection here (a `FOR UPDATE` row lock + transaction + `ALREADY_CLOCKED_IN`/`NOT_CLOCKED_IN` 409 state checks), so a naive retry-after-timeout is fairly safe today even without a formal idempotency key — worth noting as lower relative risk than the other three flows, though a key is still recommended for consistency (§14 Stage 10).
- **Daily work** (`daily-work/submit.php`): no natural duplicate guard at all — a retried submission after a timeout creates a second `daily_work_logs` row (and, if linked to a work order, a second `work_order_history` entry). Highest-priority target for an idempotency key.
- **PM completion** (`maintenance/complete.php`): same — no duplicate guard, needs an idempotency key.

**Idempotency strategy recommendation (unchanged in shape from the first audit, now backend-verified as feasible):** add a client-generated UUID to every queued `PendingSyncItem` at creation time, sent as a header (`Idempotency-Key`) or body field. Backend-side, add a small `cpms_api_idempotency_keys` table (`key_hash, system_user_id, endpoint, response_json, created_at`, unique on `key_hash`) checked at the top of `daily-work/submit.php` and `maintenance/complete.php` specifically (and `task-photo.php`/`attendance/clock.php` for completeness) — on a duplicate key, return the stored response instead of re-executing the write. This is additive, touches no existing table, and can be built as a shared helper function in `services.php` reused across the four write endpoints.

---

## 10. Security Audit

Ranked **Critical / High / Medium / Low**. Per the task instructions, no discovered secret *values* are reproduced here — only file/location and issue type.

### Critical
*None found.* No hardcoded production credentials, no SQL injection in any code path read (62 prepared statements vs. 2 raw `->query()` calls in `api/v1/`, both on hardcoded/escaped internal strings — no user input reaches an unparameterized query anywhere reviewed, including a broader sweep across `cpms/includes/*service*.php` and the older root-level scripts), no authentication bypass found.

### High
- **S-H1 — Unauthenticated password-hash utility scripts reachable in the deployed tree.** `backend/make_password.php` and `backend/generate_password.php` are plain PHP files with **no session check, no auth gate of any kind** — either echoes `password_hash()` of a placeholder/example password directly to any unauthenticated visitor. `generate_password.php` specifically hardcodes a plausible-looking default password pattern and serves as a public password-hash oracle. Neither file is covered by the root `.htaccess`'s deny-list (which only covers `login_backup.php`, `config_error.php`, `config_wrong_system_owner.php`, `backup_before*.php`, `README*.txt`). **Recommendation:** delete both before any production deployment, or at minimum add them to the `.htaccess` deny-list immediately; treat their presence as a pre-launch blocker, not a someday cleanup item.

### Medium
- **S-M1 — Two different bootstrap generations expect the DB credentials file at two different paths.** `backend/foundation_bootstrap.php` expects `db.php` one level *above* the deployed repo root (a shared-hosting "outside the web root" pattern); `backend/includes/cpms_bootstrap.php` expects it at `backend/db.php` directly. Both are real, active files. This isn't exploitable by itself, but it's a deployment-configuration foot-gun — whichever path is *actually* used in production needs to be the one kept web-inaccessible, and having two conventions increases the odds of a misconfiguration during a future redeploy. **Recommendation:** confirm which convention production actually uses, then either delete the other bootstrap generation's `db.php` expectation or make both agree.
- **S-M2 — Uploaded evidence photos are served as plain static files with no access control beyond an unguessable filename.** All four upload endpoints generate `bin2hex(random_bytes(16))` filenames (128 bits of randomness — not brute-forceable), but once a URL is known (e.g. leaked via a screenshot, a referrer header, or a shared link) it's servable to anyone, authenticated or not, forever. This is a common and often-accepted tradeoff, but for evidence photos that may show unit interiors, residents, or security-sensitive areas, it's worth a product decision: either accept the obscurity-based model (document it as intentional) or add an authenticated proxy/redirect endpoint that checks the requester's property/role before streaming the file. Not a code defect — a design tradeoff worth surfacing rather than silently inheriting.
- **S-M3 — Diagnostic/health-check scripts with verbose error display reachable post-auth.** `monthly_report_diagnostic.php` sets `display_errors=1` and `error_reporting(E_ALL)` before its (correct) `$_SESSION['admin']` check — if that session check is ever weakened or bypassed by a future change, this script would leak stack traces/query details. Low likelihood given the current gate is correct, but the blast radius if it regresses is real. **Recommendation:** remove the `display_errors`/`error_reporting` overrides from this and similar `*_diagnostic.php`/`*_health.php` scripts, relying on the site-wide error-log configuration instead (which `foundation_bootstrap.php` already does correctly for the main app).
- **S-M4 — No idempotency key on any write endpoint** (§9). Not exploitable as a classic vulnerability, but a real data-integrity risk once offline retry is extended beyond task evidence, and worth tracking alongside the security findings since duplicate financial/attendance/compliance records have real-world consequences.

### Low
- **S-L1 — Wide-open CORS** (`Access-Control-Allow-Origin: *`) on `api/v1`. Irrelevant to the native app; only matters if a browser-based client is ever pointed at this API.
- **S-L2 — Legacy plaintext-password migration path still active.** `cpms/includes/unified_auth.php::cpmsUnifiedAuthVerifyPassword()` has a fallback (`hash_equals($storedPassword, $plainPassword)`) for accounts whose `password_hash` column doesn't look like a recognized hash — i.e., some legacy accounts may still have plaintext-stored passwords that only get rehashed to bcrypt on their *next successful login*. Not exploitable without already knowing a correct password, but any such row is a plaintext-password-at-rest exposure until that member's next login. **Recommendation:** a one-time audit query to count remaining legacy-format rows in `system_users`/`staff`/`security_guards`, and consider forcing a password reset for any found rather than waiting for organic re-login.
- **S-L3 — Duplicate/stale code tree** (`backend/cpms/cpms/`, §3) is dead weight and a maintenance-confusion risk, not a live vulnerability, since the active bootstrap path doesn't reference it. Recommend deleting during a cleanup pass, not urgent.

---

## 11. Recommended Staged Integration Plan

Each stage lists the backend files/endpoints involved (built or to-be-built), the Flutter files that change, DB changes if any, the security controls that apply, and test criteria. Stages are ordered so each is independently demoable against the real backend without needing a later stage's work.

### Stage 1 — Authentication + Session + Property Context
- **Backend:** `cpms/api/v1/auth/login.php`, `logout.php`, `me.php` — already exist; extend `login.php`/`me.php`'s `SELECT` to include `logo_path, primary_color, secondary_color` from `cpms_properties` (small, additive). Decide and implement the refresh-token question (§5.1, §7) — either add a `POST auth/refresh.php` backed by a new `refresh_token` column/table, or formally accept 8-hour-expiry-then-re-login as the product behavior.
- **Flutter files:** `lib/features/auth/data/api_auth_repository.dart`, `lib/features/auth/domain/staff_user.dart` (rewrite `fromJson` to the real nested `{user:{},property:{}}` shape and make `refreshToken` nullable), `lib/core/api/api_client.dart` (unwrap `response.data['data']`), `lib/core/api/api_endpoints.dart` (fix base path to include `cpms/api/v1` + `.php`, or add server-side clean-URL rewriting instead — see test criteria).
- **DB changes:** none required for MVP; optional `refresh_token`/expiry columns on `cpms_api_tokens` if refresh is chosen.
- **Security controls:** verify rate-limiting (`cpms_api_login_attempts`) still triggers correctly from the real Flutter client; confirm token is only ever stored in `flutter_secure_storage`, never logged.
- **Test criteria:** real login round-trip on a real device against staging; confirm `property_id` in the parsed `StaffUser` matches the account's actual assignment; confirm a second device logging in doesn't silently invalidate the first (tokens are independent per §"login.php" — no single-session-per-user enforcement observed, confirm this is intended); confirm logout revokes the token server-side (verify a second call with the same token now 401s).

### Stage 2 — Dashboard + Profile
- **Backend:** `cpms/api/v1/dashboard.php`, `me.php` (extended per Stage 1).
- **Flutter files:** `lib/features/dashboard/domain/dashboard_models.dart`, `dashboard_repository.dart`/`api_dashboard_repository.dart` (rewrite around the real `{shift,attendanceState,stats,tasks,pm_tasks}` shape — see §5.3), `home_screen.dart` KPI row.
- **DB changes:** none.
- **Security controls:** none new — same auth as Stage 1.
- **Test criteria:** dashboard renders real work-order/PM counts for a staging staff account with known seeded data; confirm numbers match a manual DB query.

### Stage 3 — Unified Task Inbox
- **Backend:** `staff/tasks.php`, `staff/corrective-actions/list.php`, `staff/maintenance/list.php`, `staff/daily-work/list.php` (all exist; confirm `daily-work/list.php`'s exact shape, not yet read line-by-line).
- **Flutter files:** `lib/features/tasks/data/tasks_repository.dart`/`api_tasks_repository.dart` (rewritten as a fan-out+merge across 3-4 list calls per §5.4 option 1), `task_models.dart` (category/status enums reconciled with real values).
- **DB changes:** none for option 1; new lightweight status columns not needed.
- **Security controls:** confirm each of the 3-4 underlying list calls independently enforces property scoping (already confirmed for `tasks.php` and `corrective-actions/list.php`; verify `maintenance/list.php` and `daily-work/list.php` too).
- **Test criteria:** inbox shows the correct merged count matching `workOrders + corrective actions + active PM + recent daily work` for a seeded staging account; tab filters (New/In Progress/Completed/Overdue) map sensibly onto the real, differing status enums per source.

### Stage 4 — Task Workflow + Evidence
- **Backend:** `staff/task-photo.php` (evidence), `staff/daily-work/submit.php` (the real completion mechanism), `staff/corrective-actions/update.php` (In Progress/Rectified), `staff/corrective-actions/upload-image.php`. **Requires the product decision in §5.4 before starting** (adapt Flutter to the real workflow vs. add new accept/start endpoints) — flagged for explicit user sign-off.
- **Flutter files:** `task_detail_screen.dart`, `tasks_providers.dart`, `complete_task_sheet.dart`, `task_workflow_stepper.dart` (workflow states reconciled with the real, differing state machines for work orders vs. corrective actions), `photo_evidence_grid.dart`/evidence upload field names (§5.5).
- **DB changes:** if option 2 chosen (§5.4), new `staff/tasks/{id}/accept.php`/`start.php` endpoints and possibly a status column addition; if option 1, add `supervisor_status`/rejection fields to `corrective-actions/detail.php`'s response (no schema change, the data already exists per §5.4).
- **Security controls:** re-confirm the existing "staff cannot self-verify" server-side check (§2) survives whichever option is chosen — this is the single most important behavior to protect in this stage.
- **Test criteria:** full accept→work→evidence→complete→(admin verifies via existing PWA/portal, out of scope)→staff sees Verified cycle, exercised against staging with a real reviewing admin account; rejected-then-resubmitted evidence visibly preserves the original rejected photo (§8).

### Stage 5 — Attendance + GPS
- **Backend:** `attendance/clock.php` (single combined endpoint — §5, §9), `cpms/includes/attendance_shift_service.php`.
- **Flutter files:** `attendance_repository.dart`/`api_attendance_repository.dart` (merge `clockIn`/`clockOut` into one call with an `action` field; rename `lat/lng` → `latitude/longitude`; add `accuracy` — **currently never sent, will cause every real clock-in to fail** `INVALID_LOCATION` until fixed — this is a blocking bug for this stage, not a nice-to-have), `location_service.dart` (surface `Position.accuracy`), `attendance_screen.dart` (map `POOR_GPS_ACCURACY`/`OUTSIDE_GEOFENCE`/`GEOFENCE_NOT_CONFIGURED` error codes to the existing "Unable to Clock In" dialog instead of a generic error toast).
- **DB changes:** none — `cpms_property_geofences` already exists; confirm staging properties have a row configured (`GEOFENCE_NOT_CONFIGURED` is a real 409 if not).
- **Security controls:** none new — geofence enforcement is already server-authoritative (§2); just make sure Flutter never short-circuits the dialog on a client-side-only distance check.
- **Test criteria:** clock-in from inside and outside a staging property's configured geofence radius, both with good and deliberately-degraded GPS accuracy, confirm all four backend outcomes (`accepted`, `OUTSIDE_GEOFENCE`, `POOR_GPS_ACCURACY`, `GEOFENCE_NOT_CONFIGURED`) render distinct, correct UI states.

### Stage 6 — Daily Work
- **Backend:** `staff/daily-work/submit.php`, `list.php`, `options.php`.
- **Flutter files:** `daily_work_repository.dart`/`api_daily_work_repository.dart` (grouped `before/during/after_images[]` fields, max 3 each; real category/location enums fetched from `options.php` rather than hardcoded — §5.9), `add_daily_work_screen.dart` dropdowns wired to `options.php`.
- **DB changes:** none.
- **Security controls:** confirm the optional `work_order_id` linkage re-validates `assigned_staff_id` server-side (already confirmed, §5.4's read of `submit.php`).
- **Test criteria:** submission with and without a linked work order; confirm linking one advances its status and creates a `work_order_history` row as expected; confirm the `AFTER_IMAGE_REQUIRED` validation (mandatory "After" photo when `work_status=Completed`) surfaces as a clear Flutter-side validation message before submission, not just a server error.

### Stage 7 — Preventive Maintenance
- **Backend:** `staff/maintenance/list.php`, `detail.php`, `complete.php`. **No checklist backend exists (§5.6)** — this stage ships PM as free-text-notes-plus-one-photo only, matching what the backend actually supports today.
- **Flutter files:** `pm_repository.dart`/`api_pm_repository.dart`, `pm_detail_screen.dart` (remove or hide the checklist UI for PM specifically, since there's nothing to back it — reserve the checklist UI component for Stage 9's Asset Inspection instead).
- **DB changes:** none for this stage (checklist schema work, if wanted for PM specifically rather than reusing Asset Inspection, is a separate future decision — not recommended, see §5.7).
- **Security controls:** none new.
- **Test criteria:** PM list/detail/complete round-trip against staging; confirm `status=completed` PM schedules are handled gracefully in Flutter even though the current list endpoint can't return them (either hide the "Completed" tab for PM or note it as a known limitation until `maintenance/list.php` gets a status filter param added).

### Stage 8 — Assets + QR
- **Backend:** `staff/asset-inspection/lookup.php`, `submit.php`.
- **Flutter files:** `qr_scanner_screen.dart` (parse `token` from a scanned URL, not a `CPMSPRO:ASSET:` prefix — §5.7), `assets_repository.dart`/`api_assets_repository.dart` (repoint at `asset-inspection/lookup.php?token=`), `asset_detail_screen.dart`, and **reuse the existing `PmDetailScreen`/`PmChecklistItem` checklist UI here** instead of (or in addition to) PM, since this is where a real mandatory checklist lives.
- **DB changes:** none.
- **Security controls:** confirm `public_token` lookups are property-scoped (already confirmed, §5.7's read of `lookup.php`).
- **Test criteria:** scan a real staging QR label end-to-end (physical print → camera scan → lookup → checklist → submit with 1-5 photos → confirm `asset_inspections`/`asset_inspection_images` rows created); confirm the 10 category-specific checklists render correctly for each asset category, and the generic fallback for anything else.

### Stage 9 — Notifications *(renumbered from the brief's Stage 9 "Assets+QR"/Stage 10 "Notifications" split — Assets is Stage 8 above since it now carries the checklist work)*
- **Backend:** `notifications.php`, `notifications/mark-read.php`, `mark-all-read.php`.
- **Flutter files:** `notifications_repository.dart`/`api_notifications_repository.dart`, `notification_models.dart` (drop `deep_link_route` reliance, derive route from `type`/`reference` client-side — §5.8).
- **DB changes:** none.
- **Security controls:** confirm `notifications.php`'s `target_role`/`target_user` filtering can't be bypassed by requesting another user's notifications directly (re-read `mark-read.php`'s row-ownership check specifically during this stage — not yet verified in this pass).
- **Test criteria:** seeded notifications targeted at role/user/broadcast all appear correctly scoped for a staging staff account; tapping one navigates to the correct in-app screen via the client-derived route.

### Stage 10 — Offline Sync + Idempotency
- **Backend:** new shared idempotency-key helper + `cpms_api_idempotency_keys` table (§9), applied to `daily-work/submit.php` and `maintenance/complete.php` first (highest risk), then `task-photo.php` and `attendance/clock.php`.
- **Flutter files:** `AppDatabase`/`PendingSyncItem` (add a UUID generated at enqueue time), `SyncHandlers.dispatch` (send it as a header), and — carried over from the first audit as still true — extract the offline-queue logic out of `MockTasksRepository` into a shared helper usable by all four real `Api*Repository` classes (currently only the mock task-evidence path has any offline handling at all).
- **DB changes:** new `cpms_api_idempotency_keys` table.
- **Security controls:** idempotency keys should be scoped per-`system_user_id` (not globally unique) to prevent one staff member's key colliding with another's.
- **Test criteria:** the offline-drill test from the first audit's testing strategy, now against the real backend: airplane-mode a device mid-task, capture evidence, complete the task, re-enable connectivity, confirm the Sync Centre drains to zero and the backend has exactly the records a fully-online run would produce (not two).

### Stage 11 — Security Hardening + Production Readiness
- **Backend:** resolve S-H1 (delete/deny `make_password.php`, `generate_password.php`), S-M1 (reconcile the two bootstrap `db.php` path conventions), S-M3 (remove verbose error display from diagnostic scripts), consider S-M2 (evidence-photo access control) as a product decision, S-L2 (audit remaining legacy-plaintext accounts).
- **Flutter files:** none specific — this stage is backend-only plus the cross-cutting property-isolation test suite.
- **DB changes:** optional `cpms_api_idempotency_keys` cleanup job (expire old rows); optional `staff.email`/`staff.profile_image_path` columns if Stage 1/2's profile gap is being closed at this point.
- **Security controls:** full pass over §10's findings; a dedicated property-isolation test suite (two staging properties, two staff accounts, attempt cross-property access on every `:id`/`:token`-parameterized endpoint, assert 403/404 on all of them).
- **Test criteria:** `flutter analyze`/`flutter test` still green (unchanged from the first audit's baseline); every S-H/S-M finding has a closed-out remediation or an explicit accepted-risk note; property-isolation suite passes with zero leaks.

---

## Stop-Condition Checklist (per task brief)

1. **What existing CPMSPro functionality can be reused:** §2 — a genuinely large amount: the entire token-auth layer, property-scoping model, GPS geofencing, file-upload security pattern, append-only evidence storage, the corrective-action review/rejection workflow, the audit log, per-property branding data, and the permission-engine schema foundation.
2. **What APIs already exist:** §4's matrix — every Flutter-needed capability except work-order accept/start, PM checklists, app-config/branding-as-a-standalone-call, notification device registration, and refresh tokens has a real backend endpoint, though almost all need contract adaptation (§5).
3. **What APIs must be created:** an `auth/refresh.php` (if the product wants it over re-login), an idempotency-key mechanism (§9), an FCM device-registration + dispatch path (§2, Stage 12 — out of scope for this pass per the brief), and — only if the "adapt the backend instead of Flutter" path is chosen for §5.4 — new work-order accept/start endpoints.
4. **Critical mismatches:** the work-order accept/start/complete workflow model (§5.4 — needs a product decision, not just a fix), the PM-checklist-doesn't-exist-but-Asset-Inspection-has-one swap (§5.6/§5.7), the QR payload format (§5.7), the response-envelope unwrap needed everywhere (§3), and the attendance GPS `accuracy` field Flutter never sends today (§14 Stage 5 — this one is a blocking bug, every real clock-in will fail until it's fixed).
5. **Security risks ranked:** §10 — no Critical findings; High: S-H1 (unauthenticated password-hash utility scripts); Medium: S-M1 through S-M4 (dual db.php path convention, static evidence-photo access control, verbose diagnostic error display, missing idempotency keys); Low: S-L1 through S-L3 (open CORS, legacy plaintext-password migration path, duplicate stale code tree).
6. **Recommended Stage 1 implementation:** Authentication + Session + Property Context (§14 Stage 1) — extend `login.php`/`me.php` to include branding columns, resolve the refresh-token product decision, and update Flutter's `StaffUser`/`LoginResult`/`ApiClient` to the real response envelope and shape. Everything else depends on a working authenticated session.
7. **Exact files proposed for Stage 1:**
   - Backend: `backend/cpms/api/v1/auth/login.php` (extend `SELECT`/response), `backend/cpms/api/v1/me.php` (same), optionally a new `backend/cpms/api/v1/auth/refresh.php` + a migration adding refresh-token support to `cpms_api_tokens` (pending the product decision).
   - Flutter: `mobile/lib/core/api/api_client.dart` (unwrap envelope), `mobile/lib/core/api/api_endpoints.dart` (fix base path/extension), `mobile/lib/features/auth/domain/staff_user.dart` (real shape, nullable refresh token), `mobile/lib/features/auth/data/api_auth_repository.dart` (real request/response fields).

**Waiting for approval before implementation**, per the brief — no backend or Flutter code has been changed as part of this pass; this document is the only artifact produced.
