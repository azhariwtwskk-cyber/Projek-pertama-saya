# CPMSPro Full-System Audit Report

**Scope:** end-to-end trace of the Staff mobile workflow — Flutter UI → provider/repository →
`cpms/api/v1` PHP endpoint → database → JSON response → Flutter parser → UI — plus a pass over
Android release configuration, dead/mock code, and PHP syntax across the whole `backend/` tree.

**Branch:** `claude/cpmspro-full-system-audit`
**Method:** every finding below was confirmed by reading the actual PHP source in `backend/` and
the actual Dart source in `mobile/lib/`, not inferred from documentation. Fixes were implemented
directly, not just described.

---

## 1. Executive summary

The Staff mobile app's foundation (auth, token refresh, envelope unwrapping, most repositories)
was already correctly wired against the real backend from prior work. This pass found and fixed
**one severity-CRITICAL bug that silently disabled entire features for every real user**, a
**broken evidence/completion contract** that made Task workflow non-functional, a **genuinely
missing backend capability** (Work Order verification history), and a long tail of smaller
contract mismatches, dead code, and misleading claims (Firebase push). All fixes are implemented
on this branch, not just documented.

## 2. Issues found, by severity

### CRITICAL

**C-1. `StaffPermissions.can()` denied every permission-gated feature for every real login.**
- **File:** `mobile/lib/core/permissions/staff_permissions.dart`
- **Root cause:** `POST auth/login.php` / `GET me.php` never return a `permissions[]` array (no
  such field exists in the real response — confirmed by reading both endpoints). `StaffUser.fromJson`
  therefore always built `StaffPermissions.fromList(const [])`. `can(permission)` was
  `_granted.contains(permission)`, which is `false` for an empty set — so **every**
  `permissions.can(...)` check on the Home screen's Quick Actions grid evaluated to `false` for
  every real (non-mock) staff account.
- **Impact:** "Add Daily Work", "Scan QR", "PM Tasks" (and this repair's new "Work Order History")
  quick actions were **completely invisible** on Home for real users. Only "My Tasks" was still
  reachable, via the bottom tab bar (which is not permission-gated). Daily Work, PM, and QR
  scanning were effectively unreachable features, even though the underlying screens and API
  calls worked correctly.
- **Fix:** `can()` now treats an *empty* granted set as "the server hasn't told us anything" (→
  allow), only restricting when the list is genuinely non-empty (mock/demo data, or a future
  backend that does return real permissions). Server-side authorization is unaffected — every
  endpoint already independently re-checks role/property/staff ownership regardless of this
  client-side UI gate (see the class's own pre-existing doc comment).

### HIGH

**H-1. Task Accept/Start called `POST staff/tasks.php`, a GET-only endpoint → `METHOD_NOT_ALLOWED`.**
- **Files:** `mobile/lib/features/tasks/data/api_tasks_repository.dart`,
  `mobile/lib/core/api/api_endpoints.dart`, `.../tasks_providers.dart`, `.../task_detail_screen.dart`,
  `.../widgets/task_workflow_stepper.dart`
- **Root cause:** `ApiEndpoints.staffTaskAccept/staffTaskStart/staffTaskComplete` all pointed at
  `staff/tasks.php`, which only implements `GET`. The real backend has **no** accept/start/complete
  endpoints for work orders at all — status only ever advances as a side effect of submitting a
  Daily Work log (`staff/daily-work/submit.php`) with `work_order_id` set.
- **Impact:** tapping "Accept Task" or "Start Task" always failed with the literal backend message
  `"Kaedah permintaan tidak dibenarkan."` (405) — exactly the symptom reported.
- **Fix:** removed Accept/Start entirely (no fake calls). "Complete Task" now opens a form that
  submits a real Daily Work log linked to the work order (`work_order_id`), matching how the
  backend actually advances status. The workflow stepper was simplified to New → In Progress →
  Completed (the only three states `staff/tasks.php` can ever report), with mock/demo data
  unaffected. See §4 for the product-decision rationale (adapt Flutter vs. add backend endpoints).

**H-2. Evidence photo upload sent the wrong multipart field name and fields the backend ignores.**
- **File:** `mobile/lib/features/tasks/data/api_tasks_repository.dart`
- **Root cause:** sent `file` (backend expects `photo`), `before_photo_id`/`gps_lat`/`gps_lng`/
  `device_timestamp` (none of which `staff/task-photo.php` reads or stores), and omitted the
  required `work_order_reference` + `image_type` fields entirely.
- **Impact:** every evidence upload failed with `PHOTO_REQUIRED` (422).
- **Fix:** rebuilt to send `work_order_reference` (the work order's reference string),
  `image_type` (Before/During/After/Supporting, staff-selectable), and the file under `photo`.
  Dropped the invented `before_photo_id` relationship — the real backend has no such link, only a
  type enum (task's own explicit rule: "do not invent before_photo_id relationships if the
  backend does not support them").

**H-3. Work Order History did not exist — the real management verification decision was invisible to staff.**
- **Files (new):** `backend/cpms/api/v1/staff/work-history.php`,
  `mobile/lib/features/work_history/**`
- **Files (extended):** `backend/cpms/api/v1/staff/daily-work/list.php`
- **Root cause:** traced the actual Property Admin "Daily Work Review" page
  (`backend/cpms/property_portal/daily_work_review.php`) and confirmed the Verify/Reject decision
  is written to **`daily_work_logs.work_status`/`supervisor_remarks`/`verified_by`/`verified_at`**
  — **never** to `work_orders.status`, which the review page does not touch at all.
  `work_orders.status` only ever reaches `Completed` (set by the staff's own Daily Work
  submission); it is never flipped to `Verified`/`Rejected` by anything. No endpoint returned this
  reconciled view to the mobile app, and no Flutter screen showed it — staff had no way to see
  whether their completed work was verified or rejected, or why.
- **Impact:** matches the task brief's description almost exactly — "Pending Verification /
  Verified / Rejected" had no real data source in the app, and rejected work had no visible reason
  or path back to the staff member.
- **Fix:** new, additive, read-only `GET staff/work-history.php` reconciles `work_orders` with
  their linked `daily_work_logs` entries (most recent entry's decision is authoritative — handles
  a resubmission-after-rejection correctly) and returns verification status, rejection reason,
  verified-by/at, and grouped Before/During/After/Supporting photos (both `work_order_images` and
  `daily_work_images`, with real, verified filesystem-backed URLs — see H-4). New Flutter feature
  `work_history/` renders this as `/work-history`, a route **deliberately separate** from
  `/daily-work/history` (see §5).

**H-4. Daily Work images were never returned to Flutter, and `logo_url`/evidence URLs were root-relative — undisplayable as-is.**
- **Files:** `backend/cpms/api/v1/staff/daily-work/list.php` (extended),
  `mobile/lib/core/config/app_config.dart` (new `resolveUrl`), applied throughout
  `tasks/domain/task_models.dart`, `daily_work/data/api_daily_work_repository.dart`,
  `work_history/domain/work_history_models.dart`.
- **Root cause:** `staff/daily-work/list.php` never selected or returned `daily_work_images` at
  all. Separately, every backend endpoint that *does* return a photo URL (`task-photo.php`'s
  `image_url`, and the new endpoints) returns it **root-relative** (e.g.
  `/cpms/uploads/daily_work/xxx.jpg`) by the same convention `cpmsApiSaveImage()`/`daily_work_review.php`
  already use server-side — but `FullScreenImageViewer` decides "local file vs. remote URL" purely
  by checking `startsWith('http')`, so a root-relative path was silently treated as a **local file
  path** and would never load.
- **Fix:** `daily-work/list.php` now joins `daily_work_images` and returns each entry's photos
  (grouped by type). A single `AppConfig.resolveUrl()` helper (resolving against `apiBaseUrl`,
  matching the pattern `AppBranding` already used privately for `logo_url` — that duplicate was
  removed and consolidated, see L-3) is applied at every point a raw backend path becomes a
  displayed image. Verified the real, working filesystem path for daily-work photos directly from
  `daily-work/submit.php`'s actual upload directory and confirmed it against the Property Admin's
  own review page's image-resolution logic — not guessed.

### MEDIUM

**M-1. Attendance always sent a hard-coded GPS accuracy (25.0) and never reflected a successful clock-in in the UI.**
- **File:** `mobile/lib/features/attendance/data/api_attendance_repository.dart`,
  `.../application/attendance_providers.dart`
- **Root cause:** `accuracy` was a hard-coded literal instead of the device's real
  `Position.accuracy`, defeating the backend's `POOR_GPS_ACCURACY` check. Separately,
  `fetchStatus()` always returned `clockedOut` unconditionally — after a *successful* clock-in the
  UI still showed "NOT CLOCKED IN".
- **Fix:** real device accuracy is now sent. The real backend has no attendance status/history GET
  endpoint at all (confirmed absent from `cpms/api/v1/attendance/`), so a session-scoped "last
  confirmed state" provider now tracks the true state from clock action responses — including
  correctly reinterpreting an `ALREADY_CLOCKED_IN`/`NOT_CLOCKED_IN` 409 as the server telling the
  app the real state, rather than a generic error.

**M-2. Server error messages/codes for GPS/geofence failures were swallowed into a generic toast.**
- **Files:** `mobile/lib/core/api/api_exception.dart`, `.../api_client.dart`,
  `.../attendance/data/api_attendance_repository.dart`
- **Root cause:** `ApiException` had no `code` field, and the attendance screen's generic
  `catch (e)` collapsed every non-location error (including the backend's specific, real Malay
  error messages for `OUTSIDE_GEOFENCE`/`POOR_GPS_ACCURACY`/`GEOFENCE_NOT_CONFIGURED`) into
  "Unable to reach CPMSPro. Please try again."
- **Fix:** `ApiException.code` now carries the backend's `error.code`; added `ApiFailureType.conflict`
  for 409s; the attendance repository now branches on the real code and surfaces a specific,
  correct message for each of: permission denied, GPS disabled, poor accuracy, outside geofence,
  geofence not configured, already clocked in, not clocked in.

**M-3. QR scanner parsed the wrong payload format.**
- **File:** `mobile/lib/features/assets/presentation/qr_scanner_screen.dart`
- **Root cause:** assumed a `CPMSPRO:ASSET:<id>` prefix or bare ID. The real backend
  (`staff/asset-inspection/lookup.php?token=`) looks assets up by an opaque `public_token`, and
  the physically printed QR code (confirmed in the admin QR-generation code) encodes a **full
  portal URL** with `token` as a query parameter.
- **Fix:** parses `token` from the scanned value as a URL query parameter, falling back to the raw
  value only if it isn't a URL.

**M-4. Settings screen: 6 of 9 items were dead `onTap: () {}` buttons.**
- **File:** `mobile/lib/features/profile/presentation/profile_screen.dart`
- **Fix:** audited every item against the real backend. Edit Profile / Change Password / Language
  / Notification Settings have no backend support at all — shown visibly disabled with an
  "Unavailable" label and an explanatory snackbar on tap, never a silent no-op. Help & Support /
  Privacy / About are legitimate local, static content (no backend needed) and are now wired to
  real dialogs. "Employee ID" was relabelled "Staff ID" — the `staff` table has no distinct
  employee-number column, so the account id was being presented under a misleading label.

**M-5. Daily Work History and Work Order History were conflated in the UI copy.**
- **Files:** `mobile/lib/features/daily_work/presentation/daily_work_history_screen.dart`,
  `.../dashboard/presentation/home_screen.dart`
- **Root cause:** the Daily Work History screen's app bar literally said **"Work History"**, and
  Home's quick action labelled **"Work History"** routed to `/daily-work/history` — the same name
  used for two different records (a staff member's own log vs. management's verification of a
  work order), which is exactly the confusion the task brief warned against.
- **Fix:** relabelled to "Daily Work History" (route unchanged), added a distinct "Work Order
  History" quick action → the new `/work-history` route (H-3), and added a history icon to the
  Task Inbox app bar as a second, natural entry point.

**M-6. Firebase push was claimed but never implemented; unused dependencies shipped.**
- **Files:** `mobile/pubspec.yaml`, `mobile/lib/core/notifications/push_notification_service.dart`
- **Root cause:** the class doc comment claimed to "wrap Firebase Cloud Messaging + local
  notifications", but `Firebase.initializeApp()` is never called anywhere, there is no
  `google-services.json`/`GoogleService-Info.plist`, no `com.google.gms.google-services` Gradle
  plugin, and no backend device-registration endpoint. `firebase_core`/`firebase_messaging` were
  declared in `pubspec.yaml` and never imported anywhere outside that one file's stale comment.
- **Fix:** removed the two unused Firebase dependencies. Rewrote the doc comment to state plainly
  that this is **local, on-device notification display only**, and that the real, working
  notification list is the in-app Notification Centre driven by `GET notifications.php` — per the
  task's explicit instruction not to pretend push works.

**M-7. Notification tap tried to `context.push()` a web-portal URL as a Flutter route.**
- **File:** `mobile/lib/features/notifications/data/api_notifications_repository.dart`,
  `.../presentation/notifications_screen.dart`
- **Root cause:** `deep_link_route` fell back to the real backend's `action_url`, which is a
  `cpms/property_portal/...` web URL, not a GoRouter path — pushing it would throw or 404 inside
  the app.
- **Fix:** `deepLinkRoute` is no longer derived from `action_url`; tapping a notification marks it
  read without attempting a broken in-app navigation. (Deriving a real in-app route from
  `type`/`reference` is a reasonable follow-up but was out of this pass's scope — noted in §6.)
- Also added the previously-unused `notifications/mark-all-read.php` endpoint to the app (a "Mark
  all read" action), and made per-row parsing resilient so one malformed notification can no
  longer crash the Alerts screen.

**M-8. PM Task detail rendered an empty "Checklist" section for every real PM schedule.**
- **File:** `mobile/lib/features/preventive_maintenance/presentation/pm_detail_screen.dart`
- **Root cause:** `cpms_pm_schedules` has no checklist columns at all (confirmed —
  `maintenance/list.php`/`detail.php`/`complete.php` never return one); the real checklist concept
  lives on Asset Inspection instead. The mandatory-check gate was vacuously satisfied (`[].every`
  on an empty list is `true`), so this never blocked submission, but the empty "Checklist" card
  still rendered for nothing.
- **Fix:** the checklist card only renders when the backend actually supplied items.

### LOW

**L-1. Android release signing does not exist yet.** No `.jks` keystore, no `key.properties`, and
`android/app/build.gradle.kts` signs release builds with the **debug** key
(`signingConfig = signingConfigs.getByName("debug")`, with a `// TODO: Add your own signing
config` comment already in the scaffold). This audit did **not** generate a keystore — per the
task's own rule, and because a signing key is a one-way, business-critical decision (Play Store
app identity is tied to it permanently) that must be made by the CPMSPro team, not fabricated
here. `android/.gitignore` already correctly excludes `key.properties`/`**/*.jks` should a real
one be added later. `flutter build apk --release` will succeed today (debug-signed) but the
resulting APK is **not suitable for production distribution** until real signing is configured.

**L-2. Pre-existing PHP syntax error, unrelated to the Staff mobile workflow.**
`backend/cpms/property_portal/property_portal_v4_check.php:30` has invalid string interpolation
(`"$var['key']"` without braces) and fails `php -l`. This file is a standalone diagnostic/self-test
script with zero inbound references from any other file in the repository (confirmed via
repository-wide grep) — it appears to be dead or manually-invoked-only code, not on any live
request path. Left unfixed as out of scope for this pass; flagged here per the "continue auditing,
don't stop at first error" instruction.

**L-3. Duplicated root-relative-URL-resolution logic.** `AppBranding._resolveAssetUrl` and the
new `AppConfig.resolveUrl` did the same thing. Consolidated into the single `AppConfig.resolveUrl`
helper; the private duplicate was removed.

**L-4. Daily Work categories included two values the real backend's own option list doesn't use.**
`Landscaping` (backend says `Landscape`) and `Inspection Support`/`General Work` (not in
`staff/daily-work/options.php`'s list at all) — technically still accepted by `submit.php` (no
server-side enum enforcement), but inconsistent with what the CPMSPro web portal's own dropdown
writes, degrading the Property Admin's filtering/reporting. Relabelled to match the real list
exactly (`Landscape`, `Security`, `Fire Safety` replacing the two invented values).

**L-5. Photo pickers had no way to remove a photo before submitting.** `add_daily_work_screen.dart`
and `pm_detail_screen.dart`'s photo grids only supported adding — per the task's explicit
requirement ("Show preview and allow removal before upload"), both now support tapping a small ✕
to remove a picked-but-not-yet-uploaded photo. The new Complete Task sheet was built with removal
support from the start.

---

## 3. What was already correct (not re-litigated)

- Bearer-token auth, refresh-token rotation, single-flight refresh, and the `{ok,data}`/`{ok,false,error}`
  envelope unwrap in `ApiClient` — verified against the real `auth/login.php`/`refresh.php`/`me.php`
  and covered by `test/auth_stage1_test.dart` (21 tests, all passing).
- `property_id`/`staff_id` are never sent by the client for authorization on any request; every
  server endpoint resolves them from the bearer token, never from client input (confirmed across
  every endpoint read in `cpms/api/v1/`).
- Evidence photos are genuinely append-only server-side (`work_order_images`, `daily_work_images`
  are insert-only tables) — nothing in this repair changes that; the app only ever adds photos,
  never a delete/overwrite call.
- Attendance's single `clock.php` endpoint with an `action` discriminator was already correctly
  modelled (no separate clock-in/out URLs invented).

## 4. Product decision already made, not deferred

The task brief asked: if the backend has no accept/start endpoints, either adapt Flutter or add
the smallest safe backend addition. **Decision: adapt Flutter (no new backend endpoints for
accept/start).** Rationale: the real backend already advances work order status correctly via
Daily Work submission, that mechanism is live, audited, and already used by the production PWA —
adding a parallel accept/start status model would fork the state machine into two systems that
could disagree. The workflow stepper and action bar now reflect exactly the three states
`staff/tasks.php` can report.

## 5. Remaining limitations / needs a product decision

1. **Notification deep-linking** currently does nothing beyond marking read (M-7) — deriving a
   real in-app route from `type`/`reference` is worth doing but needs someone to define the
   `type` → route mapping; not fabricated here.
2. **PM has no checklist** (M-8) — the real checklist lives on Asset Inspection. Whether to add a
   checklist schema to `cpms_pm_schedules` or simply document PM as notes+photo-only is a product
   decision, not something this pass should decide unilaterally.
3. **Android release signing (L-1)** must be set up by the CPMSPro team before any Play Store
   submission — this pass explicitly did not create one.
4. **`flutter build apk --release`/`--debug`** could not be executed in this sandbox: the Android
   SDK is not pre-installed here, and `dl.google.com` (the only source for the Android
   command-line tools) is blocked by this session's organizational network egress policy — a
   403 policy denial, not a transient failure, so per that policy it was not retried or routed
   around. `flutter pub get`, `dart format`, `flutter analyze`, and `flutter test` (which do not
   need the Android SDK) all ran for real and passed — see `TEST_REPORT.md`. This should be run
   in a CI environment or local machine with the Android SDK installed before release.
5. **Dashboard KPI numbers** — `dashboard.php`'s per-role stats already existed and were left
   untouched (out of the "Staff journey" scope this pass focused on); a best-effort field mapping
   was added defensively (`stats.workOrders`/`pmTasks` fallback) so the KPI row degrades gracefully
   rather than always showing 0, but this was not a primary target of this audit.
