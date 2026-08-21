# CPMSPro Full-System Audit Report

**Scope:** end-to-end trace of the Staff mobile workflow — Flutter UI → provider/repository →
`cpms/api/v1` PHP endpoint → database → JSON response → Flutter parser → UI — plus a pass over
Android release configuration, dead/mock code, and PHP syntax across the whole `backend/` tree.

**Branch:** `claude/cpmspro-full-system-audit`
**Method:** every finding below was confirmed by reading the actual PHP source in `backend/` and
the actual Dart source in `mobile/lib/`, not inferred from documentation. Fixes were implemented
directly, not just described.

> **Update (real-device follow-up pass):** two HIGH-severity issues were reported after installing
> the release APK on a real Android phone — Work Order History's Before/After photos still didn't
> render, and a submitted task appeared to vanish. Both were re-audited from scratch (the previous
> Work Order History fix was **not** assumed correct) and root-caused by source inspection. See
> **§7** for the full trace, root causes, and fixes — this is the most load-bearing addition in
> this document if you only have time to read one section.

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

---

## 7. Real-device follow-up: two HIGH-severity issues (this pass)

Reported after installing and testing the release APK on a real Android phone. Both were
re-audited end-to-end from source — the previous Work Order History fix (§2, H-3/H-4) was **not**
assumed correct, per this pass's explicit instruction.

### 7.1 Before/After images still not showing — ROOT CAUSE FOUND

**Traced the real, live path:** Flutter submit → PHP multipart upload → filesystem → `daily_work_images`
row → `daily_work_logs.work_order_id` → `work-history.php` → JSON → Flutter model → `Image.network`.

**Root cause: two different, disagreeing upload code paths write the same `daily_work_images`
table, and Work Order History/Daily Work List only ever checked one of them.**

Confirmed by reading three independent, currently-live PHP files, not guessed:

1. **`backend/staff_work_submit.php`** — the legacy Staff Web Portal's own Daily Work submit
   endpoint (linked from `staff_dashboard.php` → "Add Daily Work", still an active part of
   production, not dead code). Stores photos at
   `<site_root>/uploads/daily_work/property_<id>/<random>.ext` (a **property-specific subfolder**,
   **not** under `cpms/`) and populates `daily_work_images.image_path` with that exact relative
   path whenever the column exists (`insertDailyWorkImages()`).
2. **`backend/cpms/api/v1/staff/daily-work/submit.php`** — this mobile app's own endpoint. Stores
   photos **flat**, at `<site_root>/cpms/uploads/daily_work/<random>.ext` (**no** property
   subfolder), and — before this fix — never populated `image_path` at all.
3. **`backend/cpms/property_portal/daily_work_review.php`** (`dailyWorkImageUrl()`) and
   **`backend/staff_work_history.php`** (`staffHistoryImageUrl()`) — the Property Admin review page
   and the legacy staff portal's own history page. Both already know about this exact ambiguity:
   each checks `image_path` first, then falls back through **four** candidate paths (with/without
   `property_<id>/`, with/without `cpms/` prefix), verifying each with a real `is_file()` check
   against the filesystem before returning a URL.

**But** `cpms/api/v1/staff/work-history.php` and `cpms/api/v1/staff/daily-work/list.php` (both
written in the prior pass) only ever built the URL from `basename(image_name)` under a
**hardcoded, flat** `cpms/uploads/daily_work/` path — i.e., exactly the mobile app's own upload
convention, and *only* that one. Any work order whose Daily Work evidence was submitted through the
still-live legacy Staff Web Portal (property-subfolder layout, `image_path` populated) resolved to
a URL where no file exists at that path → the app correctly received a URL, `Image.network`
correctly 404'd, and the error-builder correctly showed a broken-image placeholder. That is the
literal, confirmed mechanism behind "images not shown" on a real device with real data.

A secondary, compounding UX bug: `WorkHistoryScreen`'s photo rows were rendered only when the card
was tapped open (`if (_expanded)`), so even a correctly-resolving photo wasn't visible without an
extra, undiscoverable tap — worsening the "images just aren't there" perception.

**Fix (backend, canonical helper — task's explicit ask, "do not duplicate conflicting path logic
across endpoints"):**
- Added `cpmsApiResolveUploadedImageUrl()` in `cpms/api/v1/services.php` — one shared, `is_file()`-
  verifying resolver, anchored at the real site root (`dirname(cpmsApiRoot())`, matching exactly
  where the legacy pages anchor their own checks), used by every endpoint that turns a stored image
  row into a URL.
- `cpmsApiDailyWorkImageCandidates()` builds the same four-candidate list the legacy pages already
  use (now read from `image_path` first when the column/value exists, then property-subfolder,
  then flat, with and without `cpms/`), feeding the shared resolver.
- `cpmsApiWorkOrderImageUrl()` — `work_order_images.image_name` already stores the correct full
  path (single writer, confirmed via a repository-wide search), routed through the same resolver
  for consistency, not because it was broken.
- `staff/work-history.php` and `staff/daily-work/list.php` now call these instead of hand-building
  a path; both now also select `image_path` (guarded by `cpmsApiColumnExists()`, matching this
  codebase's existing defensive style for schema drift).
- `staff/daily-work/submit.php` now **also populates `image_path`** for its own future uploads
  (`cpms/uploads/daily_work/<file>`, guarded by column-existence) — so, going forward, both write
  paths agree and neither read path has to guess.
- Added temporary, safe (no tokens/secrets) `error_log()` counts in both `work-history.php` and
  `daily-work/submit.php` — `work_order_id`, `daily_work_id`, images found/stored — to make the
  next real-device run independently verifiable from server logs.

**Fix (Flutter):** `WorkHistoryScreen`'s Before/During/After/Supporting photo rows now render
unconditionally whenever `item.photos` is non-empty (previously gated behind `_expanded`); an
explicit "No evidence photos found for this work order" line replaces silence when a work order
genuinely has none. Tap-to-enlarge (`FullScreenImageViewer`) is unchanged.

### 7.2 Task disappears after staff submits — ROOT CAUSE FOUND

**Traced the real status lifecycle**, per the task's instruction not to assume `work_orders.status`
is the only source of truth:

- `work_orders.status` is set by staff via `daily-work/submit.php` (`Open/Assigned` → `In
  Progress/Pending Material/Pending Contractor` → `Completed`). Confirmed (again, this pass) that
  nothing else ever sets it to `Verified`/`Rejected` — `daily_work_review.php` only ever wrote to
  `daily_work_logs.work_status`/`supervisor_remarks`/`verified_by`/`verified_at`, **never**
  `work_orders`. `staff/tasks.php` (`cpmsApiTaskRows()`) keeps returning a `Completed` work order
  (its `WHERE status NOT IN ('Verified','Cancelled')` clause includes `Completed`) — so a
  just-submitted task does **not** vanish from the raw active-task query.

**Root cause was not one bug but three compounding gaps, each confirmed by reading the actual
code, not assumed:**

1. **Stale caches after submit.** `TaskActionsController.complete()`/`.uploadEvidence()`
   (`tasks_providers.dart`) only ever invalidated `taskDetailProvider` and `tasksListProvider`.
   `workHistoryProvider` (Work Order History) and `dashboardDataProvider` (Home's KPI row,
   Priority Task card, Recent Tasks) were **never** invalidated. A staff member who had already
   opened Home or Work Order History this session would see stale data immediately after
   submitting — looking exactly like "my task disappeared into nothing," since the one place that
   *did* refresh (My Tasks) isn't where most staff naturally check next (Home).
2. **No route back to completed work.** The only path to Work Order History was a small,
   easy-to-miss app-bar icon on My Tasks — no segmented control, no CTA from an empty state, per
   the task brief's own framing. My Tasks' and Home's "Recent Tasks" empty states both used the
   same generic `AppStateView.noTasksToday()` with **no** button at all.
3. **Rejected work had no way back to the staff member.** Since `work_orders.status` was never
   touched by the reject decision, a rejected work order stayed `status='Completed'` forever;
   `taskStatusFromString()` collapses both real terminal states (`completed`/`verified`) into
   `TaskStatus.verified`, which `task_detail_screen.dart`'s action bar renders as a **permanently
   disabled** "Completed" button — staff had no in-task way to discover a rejection or resubmit,
   only a same-session grep through the (still read-only) Work Order History screen.

**Fix (backend — smallest safe sync, "do not destroy historical evidence"):**
- `daily_work_review.php`: when a decision is `Rejected` **and** the linked work order's current
  status is `Completed`/`Verified`, the work order is reopened to **`In Progress`** — an existing,
  already-supported status (confirmed against `admin_work_orders.php`'s own status list; no new
  enum value invented) — plus one new `work_order_history` row documenting the reopen. Nothing in
  `daily_work_logs` (remarks, images, `verified_by`/`verified_at`) is touched or deleted; the
  rejection decision and reason remain fully in the historical record. A work order already
  `Cancelled`, or still legitimately mid-flow, is left untouched.
- `cpmsApiTaskRows()` (`staff/tasks.php`, also feeds `dashboard.php`) now additionally joins the
  *latest* `daily_work_logs` entry per work order and returns `rejection_reason` (only while that
  latest entry's `work_status` is still `Rejected` — a later successful resubmission naturally
  clears it), so Task Detail can show *why* a reopened task came back without a second round trip.

**Fix (Flutter):**
- `TaskActionsController._invalidate()` now also invalidates `workHistoryProvider` and
  `dashboardDataProvider` — a successful submit now refreshes every screen that reads this work
  order, immediately, per the task brief's "Completion Guarantee."
- `task_inbox_screen.dart`: the app-bar action is now a labelled `History` button (icon + text),
  not a bare icon.
- New `AppStateView.noActiveTasks({onViewHistory})` — "No Active Tasks — Completed work is
  available in Work Order History" + a "View Work Order History" button — replaces the generic
  empty state in both My Tasks and Home's Recent Tasks section.
- `task_detail_screen.dart` now shows a "Rejected – Action Required" banner (reason + a note that
  the existing "Complete Task" button is the resubmission action) whenever
  `task.rejectionReason` is present — the field already existed on `StaffTask` for mock data; it
  now gets populated for real work orders too.
- `api_dashboard_repository.dart`'s `priorityTask` fallback now skips already-terminal
  (`TaskStatus.verified`) tasks when picking the first task to feature — the raw `tasks[]` list
  can still contain a `Completed` order (see above), so blindly taking `tasks.first` could
  previously surface an already-done order under a "Start Task" button.

### 7.3 Verified end-to-end with a concrete example

Confirmed via the new unit tests (`test/work_history_parsing_test.dart`) that a completed work
order with a linked `daily_work_logs` row and `daily_work_images` rows produces a
`WorkOrderHistoryItem` with non-empty, absolute (`http...`) photo URLs for `Completed` →
`pending_verification`, `Verified`, and `Rejected` (with `rejection_reason` populated) shapes — see
`TEST_REPORT.md` for the full run. This does not replace a real-device retest, which is still the
authoritative check (noted in §5).

### 7.4 Production fatal after deploying the §7.1/§7.2 fix — `cpmsApiColumnExists()` undefined

Deploying the six PHP files for §7.1/§7.2 to the live cPanel server produced a fatal: `Call to
undefined function cpmsApiColumnExists()`. Root cause: this helper (and its sibling
`cpmsApiTableExists()`) is defined only in `cpms/api/v1/bootstrap.php` — a file that was never
part of any deployment manifest given for this fix, on the assumption the live server's copy
already had it. It didn't; the live `bootstrap.php` predates both helpers. Searched the whole
repo for an equivalent under another name before adding anything: every other legacy page already
carries its own private column-exists helper for exactly this reason (`staff_work_submit.php`'s
`staffWorkColumnExists()`, `staff_work_history.php`'s `staffHistoryColumnExists()`,
`daily_work_review.php`'s `dailyWorkColumnExists()`, `admin_daily_work.php`'s own
`adminDailyWorkColumnExists()`) — there is no shared one already reachable from `cpms/api/v1/*`.
**Fix:** `cpmsApiTableExists()`/`cpmsApiColumnExists()` are now also defined in `services.php`,
each guarded by `function_exists()` so they never collide with `bootstrap.php`'s own versions when
it does have them — matching its exact signature/behavior. `services.php` is required by every
endpoint in this fix via `bootstrap.php`'s own require chain, so this closes the gap everywhere
it's called from without needing to redeploy `bootstrap.php` itself.

### 7.5 Real-device production verification — PASSED

After deploying the hotfix in §7.4 (`services.php` only, no APK rebuild), the real Android device
test was re-run and **passed**: Work Order History loads, Before/During/After images render
correctly for both the legacy Staff Web Portal's property-subfolder layout and this mobile app's
own flat layout, and the `cpmsApiColumnExists()` fatal is gone. Both original real-device issues
(§7.1 images, §7.2 disappearing tasks) are now confirmed fixed in production, not just in this
sandbox.

### 7.6 Cleanup pass — forensic/debug instrumentation removed

§7.1's forensic-trace pass deliberately added temporary `error_log()`/`debugPrint()`
instrumentation to get a ground-truth answer without guessing further (see §7.4's discovery for
why that was the right call). With production verification passed, that instrumentation has now
been removed — it served its purpose and had no reason to stay:

- `staff/work-history.php`: removed the `HANDLER_VERSION` marker + column-existence log, the
  per-image resolution trace (`daily_work_images` and `work_order_images` loops), the "zero rows
  linked"/"zero images found" diagnostic lines, and the aggregate per-order summary log. The file
  is now identical to its state right after the original §7.1 fix, functionally.
- `staff/daily-work/submit.php`: removed the temporary `error_log()` counting
  `daily_work_id`/`work_order_id`/`work_status`/images stored on submit.
- `mobile/lib/features/work_history/data/api_work_history_repository.dart`: reverted to its
  pre-forensic-pass state — no `debugPrint()` calls; a malformed work-order row is once again
  silently skipped rather than logged (matching this codebase's existing "one bad row must never
  take down the whole screen" pattern from before this investigation).

**Confirmed still present after cleanup** (the real fixes, none of which were touched):
`cpmsApiResolveUploadedImageUrl()`, `cpmsApiDailyWorkImageUrl()`, `cpmsApiWorkOrderImageUrl()`,
the `function_exists()`-guarded `cpmsApiTableExists()`/`cpmsApiColumnExists()` fallbacks,
`image_path` support (read and write) on both legacy and mobile upload layouts, Work Order History
evidence merging, `workHistoryProvider`/`dashboardDataProvider` invalidation after a successful
submit, the empty-active-tasks CTA into Work Order History, the rejected-work-order reopen/sync,
the "Rejected – Action Required" banner, the labelled History navigation button, and the Admin
Daily Work image-resolver fix. `backend/cpms/api/v1/image_url_resolver_test.php` is kept in the
repo (CI/local verification only, explicitly documented in its own header as **not** for
production deployment) and still passes 7/7 assertions.
