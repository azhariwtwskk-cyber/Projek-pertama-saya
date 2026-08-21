# CPMSPro Full-System Audit — Changelog

Branch: `claude/cpmspro-full-system-audit`. All fixes described in `AUDIT_REPORT.md`; full
request/response contract in `API_CONTRACT.md`; validation results in `TEST_REPORT.md`.

## New files

| File | Purpose |
|---|---|
| `backend/cpms/api/v1/staff/work-history.php` | New, additive, read-only endpoint reconciling `work_orders` + `daily_work_logs` verification (AUDIT_REPORT H-3) |
| `mobile/lib/features/work_history/domain/work_history_models.dart` | Work Order History domain models |
| `mobile/lib/features/work_history/data/work_history_repository.dart` | Repository interface |
| `mobile/lib/features/work_history/data/api_work_history_repository.dart` | Real API implementation |
| `mobile/lib/features/work_history/data/mock_work_history_repository.dart` | Demo/mock implementation |
| `mobile/lib/features/work_history/application/work_history_providers.dart` | Riverpod providers |
| `mobile/lib/features/work_history/presentation/work_history_screen.dart` | `/work-history` screen — grouped Before/During/After photos, verification banners, rejection reason |
| `AUDIT_REPORT.md`, `API_CONTRACT.md`, `TEST_REPORT.md`, `CHANGELOG_CPMSPRO_AUDIT.md` | This audit's deliverables |

## Modified files with functional changes

Backend:
- `backend/cpms/api/v1/staff/daily-work/list.php` — added `images[]` (joined from
  `daily_work_images`, real resolved URLs), `supervisor_remarks`, `work_order_reference`,
  `verified_by`/`verified_at` passthrough. Additive only; existing fields unchanged.

Mobile — core:
- `lib/core/api/api_endpoints.dart` — removed dead `staffTaskAccept/Start/Complete` constants
  (pointed at a GET-only endpoint, never actually reachable); added `staffWorkHistory`.
- `lib/core/api/api_client.dart` — extract `error.code` from the backend's error envelope into
  `ApiException.code`; map HTTP 409 to a new `ApiFailureType.conflict`.
- `lib/core/api/api_exception.dart` — added `code` field and `conflict` failure type.
- `lib/core/config/app_config.dart` — added `AppConfig.resolveUrl()` (root-relative → absolute
  URL, consolidating logic duplicated in `AppBranding`) and `appVersion`.
- `lib/core/config/app_branding.dart` — now uses `AppConfig.resolveUrl()` instead of a private
  duplicate `_resolveAssetUrl`.
- `lib/core/permissions/staff_permissions.dart` — **critical fix**: `can()` no longer denies
  everything when the server sends an empty permissions list (AUDIT_REPORT C-1).
- `lib/core/notifications/push_notification_service.dart` — corrected doc comment (no FCM wiring
  exists; local notifications only — AUDIT_REPORT M-6).
- `lib/core/location/location_service.dart` — reformatted only (no functional change).
- `lib/core/router/app_router.dart` — added `/work-history` route (parallel to, not merged with,
  `/daily-work/history`).

Mobile — tasks feature (work order workflow rebuild, AUDIT_REPORT H-1/H-2):
- `lib/features/tasks/domain/task_models.dart` — added `databaseId`, `existingImageUrl`;
  `taskStatusFromString` now maps the real backend's `pending`/`completed` values;
  `EvidencePhoto.fromJson` hardened against missing fields; added
  `EvidencePhoto.fromUploadResponse` for `task-photo.php`'s real response shape; URLs resolved via
  `AppConfig.resolveUrl`.
- `lib/features/tasks/data/tasks_repository.dart` — interface: removed `acceptTask`/`startTask`;
  `uploadEvidence` now takes `imageType` instead of the invented `beforePhotoId`; `completeTask`
  rebuilt around submitting a linked Daily Work log.
- `lib/features/tasks/data/api_tasks_repository.dart` — rewritten against the real
  `staff/tasks.php` (list + client-side find-by-id, no detail endpoint), `staff/task-photo.php`
  (correct field names), and `staff/daily-work/submit.php` (real completion path).
- `lib/features/tasks/data/mock_tasks_repository.dart` — updated to match the new interface,
  offline-queue payload now carries the correct multipart field name.
- `lib/features/tasks/application/tasks_providers.dart` — controller: removed accept/start;
  `uploadEvidence` returns the uploaded `EvidencePhoto`; `complete` takes the full Daily Work form.
- `lib/features/tasks/presentation/task_detail_screen.dart` — removed Accept/Start actions; added
  Before/During/After/Supporting evidence capture at the correct workflow stage; session-scoped
  evidence gallery (backend has no full-gallery endpoint, see API_CONTRACT.md); wired the new
  Complete Task flow.
- `lib/features/tasks/presentation/widgets/complete_task_sheet.dart` — rebuilt: work status
  dropdown, Before/During/After photo capture (camera or gallery) with preview + removal, After
  required when marking Completed (client-side mirror of the backend's own rule).
- `lib/features/tasks/presentation/widgets/task_workflow_stepper.dart` — simplified to the three
  states the real backend can report (New → In Progress → Completed).
- `lib/features/tasks/presentation/task_inbox_screen.dart` — added a Work Order History entry
  point in the app bar.

Mobile — attendance (AUDIT_REPORT M-1/M-2):
- `lib/features/attendance/data/api_attendance_repository.dart` — real device GPS accuracy sent
  (was hard-coded 25.0); branches on the backend's real error codes for a specific message per
  failure mode; reports the resulting clock state back to the caller.
- `lib/features/attendance/domain/attendance_models.dart` — `GeofenceResult.resultingStatus`.
- `lib/features/attendance/application/attendance_providers.dart` — session-scoped
  last-confirmed-clock-state tracking (no backend status endpoint exists).
- `lib/features/attendance/data/attendance_repository.dart`,
  `lib/features/attendance/data/mock_attendance_repository.dart` — interface/signature updates.

Mobile — daily work (AUDIT_REPORT H-4/L-4/L-5/M-5):
- `lib/features/daily_work/domain/daily_work_models.dart` — added `rejected` status,
  `photoUrls`, `supervisor_remarks`; category enum values corrected to match the real backend list.
- `lib/features/daily_work/data/api_daily_work_repository.dart` — parses the newly-returned
  `images[]`/`supervisor_remarks`/real `status` string.
- `lib/features/daily_work/presentation/daily_work_history_screen.dart` — relabelled "Daily Work
  History" (was ambiguously "Work History"); shows real photo thumbnails (tap to enlarge),
  verified/rejected badges, and the supervisor's rejection remarks.
- `lib/features/daily_work/presentation/add_daily_work_screen.dart` — photo picker now supports
  removing a picked photo before submit; default category fixed after enum rename.

Mobile — assets/QR (AUDIT_REPORT M-3):
- `lib/features/assets/presentation/qr_scanner_screen.dart` — parses the real `token` query
  parameter from a scanned portal URL instead of an invented `CPMSPRO:ASSET:` prefix.

Mobile — PM (AUDIT_REPORT M-8):
- `lib/features/preventive_maintenance/presentation/pm_detail_screen.dart` — checklist card only
  renders when the backend actually supplies items; photo picker supports removal.

Mobile — notifications (AUDIT_REPORT M-7):
- `lib/features/notifications/data/api_notifications_repository.dart` — no longer treats
  `action_url` (a web-portal URL) as a Flutter route; per-row parsing hardened against malformed
  rows; added `markAllRead`.
- `lib/features/notifications/data/notifications_repository.dart`,
  `lib/features/notifications/data/mock_notifications_repository.dart`,
  `lib/features/notifications/application/notifications_providers.dart` — `markAllRead` plumbed
  through.
- `lib/features/notifications/presentation/notifications_screen.dart` — "Mark all read" action;
  removed the broken route-push on tap.

Mobile — profile/settings (AUDIT_REPORT M-4):
- `lib/features/profile/presentation/profile_screen.dart` — every settings item audited; dead
  buttons now either wired (Sync Centre, About, Help & Support, Privacy) or visibly disabled with
  a reason (Edit Profile, Change Password, Language, Notification Settings); "Employee ID"
  relabelled "Staff ID".

Mobile — dashboard:
- `lib/features/dashboard/data/api_dashboard_repository.dart` — defensive fallback mapping for
  the real `stats.workOrders`/`pmTasks` field names so the KPI row degrades gracefully instead of
  always showing 0.
- `lib/features/dashboard/presentation/home_screen.dart` — "Work Order History" vs. "Daily Work
  History" quick actions clearly separated (was one ambiguous "Work History" action); removed a
  dead "Camera Evidence" quick action that only routed to `/tasks` under a misleading label.

Mobile — dependencies:
- `mobile/pubspec.yaml` — removed unused `firebase_core`/`firebase_messaging` (AUDIT_REPORT M-6);
  `pubspec.lock` updated.

## Removed

- `mobile/CPMSPRO_API_PATCH_README.txt` — stale notes from an earlier, superseded patch pass;
  superseded by `AUDIT_REPORT.md`/`API_CONTRACT.md`.

## Formatting-only changes (no functional difference)

`dart format .` was run per the task's instructions. Many files outside the list above show a
full-file diff in `git diff` — these are confirmed via `git diff --ignore-all-space
--ignore-blank-lines` (zero output) to be **whitespace/line-ending normalization only** (a bulk
source-tree sync earlier in this session introduced inconsistent line endings across the `lib/`
and `test/` trees; `dart format` and this pass's cleanup normalized them). This includes
`mobile/test/*.dart`, `mobile/lib/l10n/*.arb`, and several files in `dashboard/`, `assets/`,
`preventive_maintenance/`, `sync/` not otherwise mentioned above.

---

# Real-device follow-up pass

Two HIGH-severity issues reported after installing the release APK on a real Android phone:
Before/After evidence images still not rendering in Work Order History, and a submitted task
appearing to vanish with no way for staff to find it. Root causes and fixes documented in full in
`AUDIT_REPORT.md` §7; request/response shape changes in `API_CONTRACT.md`; test run in
`TEST_REPORT.md`. The previous Work Order History fix was **not** assumed correct — everything was
re-traced from source.

## New files

| File | Purpose |
|---|---|
| `mobile/test/work_history_parsing_test.dart` | Unit coverage: `WorkOrderHistoryItem.fromJson` image parsing + Completed/Verified/Rejected verification-status mapping |
| `mobile/test/empty_active_tasks_test.dart` | Widget coverage: the "no active tasks" empty state surfaces a working CTA into Work Order History |

## Modified files with functional changes

Backend — canonical image URL resolution (root cause of the missing Before/After images):
- `backend/cpms/api/v1/services.php` — added `cpmsApiResolveUploadedImageUrl()` (the one shared,
  `is_file()`-verifying resolver, anchored at the real site root), `cpmsApiDailyWorkImageCandidates()`
  + `cpmsApiDailyWorkImageUrl()` (the confirmed four-candidate list matching the legacy pages'
  own resolution logic), and `cpmsApiWorkOrderImageUrl()`. `cpmsApiTaskRows()` also now joins the
  latest linked `daily_work_logs` entry per work order and returns `rejection_reason`.
- `backend/cpms/api/v1/staff/work-history.php` — Daily Work and Work Order image URLs now resolved
  via the shared helpers instead of hand-built flat paths; `daily_work_images` query now also
  selects `image_path` (guarded); added temporary, safe `error_log()` counts
  (`work_order_id`/`daily_work_id`/images found) for on-device verification.
- `backend/cpms/api/v1/staff/daily-work/list.php` — same image-URL fix as above.
- `backend/cpms/api/v1/staff/daily-work/submit.php` — now also populates `daily_work_images.image_path`
  for its own future uploads (guarded by column-existence), so this write path agrees with the
  legacy Staff Web Portal's own convention instead of leaving read-side endpoints to guess a flat
  layout; added the same temporary `error_log()` counts on submit.

Backend — reject flow (root cause of "rejected work never becomes actionable again"):
- `backend/cpms/property_portal/daily_work_review.php` — a `Rejected` decision on a
  `Completed`/`Verified` work order now also reopens `work_orders.status` to `In Progress` (an
  existing status) and writes one `work_order_history` row; `daily_work_logs` itself
  (remarks/images/verified_by/verified_at) is never touched or deleted.

Mobile — provider invalidation (root cause of stale "task disappeared" caches):
- `lib/features/tasks/application/tasks_providers.dart` — `TaskActionsController._invalidate()`
  now also invalidates `workHistoryProvider` and `dashboardDataProvider`, not just
  `taskDetailProvider`/`tasksListProvider`, after a successful complete/upload.

Mobile — task disappearing / findability UX:
- `lib/shared/widgets/empty_state.dart` — new `AppStateView.noActiveTasks({onViewHistory})`
  factory: "No Active Tasks — Completed work is available in Work Order History" + a
  "View Work Order History" button.
- `lib/features/tasks/presentation/task_inbox_screen.dart` — app-bar action changed from a bare
  icon to a labelled `History` button; empty state now uses `AppStateView.noActiveTasks`.
- `lib/features/dashboard/presentation/home_screen.dart` — Recent Tasks' empty state now uses
  `AppStateView.noActiveTasks` too, wired to `/work-history`.
- `lib/features/dashboard/data/api_dashboard_repository.dart` — `priorityTask` fallback now skips
  an already-terminal (`TaskStatus.verified`) task instead of always taking `tasks.first`
  (`staff/tasks.php` can still include `Completed` work orders).
- `lib/features/tasks/presentation/task_detail_screen.dart` — new "Rejected – Action Required"
  banner (reason + guidance) rendered whenever `task.rejectionReason` is present.

Mobile — image visibility (compounding UX bug on top of the URL fix):
- `lib/features/work_history/presentation/work_history_screen.dart` — Before/During/After/Supporting
  photo rows now render unconditionally whenever photos exist, instead of being hidden behind an
  extra tap-to-expand; an explicit "No evidence photos found" line replaces silence when a work
  order genuinely has none.

---

# Production hotfix + cleanup pass

**Real-device production verification: PASSED.** Work Order History loads, Before/During/After
images are visible on the actual device, and the production fatal below is fixed. Full detail in
`AUDIT_REPORT.md` §7.4–§7.6; test run in `TEST_REPORT.md`.

## Hotfix: `cpmsApiColumnExists()` undefined in production

`backend/cpms/api/v1/services.php` — added `function_exists()`-guarded fallback definitions of
`cpmsApiTableExists()`/`cpmsApiColumnExists()`, matching `bootstrap.php`'s own signature/behavior
exactly. Root cause: both helpers are defined only in `bootstrap.php`, a file never included in
any deployment manifest for this fix, on the (wrong) assumption the live server's copy already
defined them. **No APK rebuild was required** — this was a backend-only defect and a
backend-only, one-file fix.

## Cleanup: forensic/debug instrumentation removed after successful verification

The forensic-trace pass deliberately added temporary logging to get a ground-truth answer instead
of guessing further. With production verification passed, it has been removed:

- `backend/cpms/api/v1/staff/work-history.php` — removed the `HANDLER_VERSION` + column-existence
  marker log, the per-image resolution trace for both `daily_work_images` and `work_order_images`,
  the "zero rows linked"/"zero images found" diagnostic lines, and the aggregate per-order summary
  log. Now functionally identical to its state immediately after the original image-URL fix.
- `backend/cpms/api/v1/staff/daily-work/submit.php` — removed the temporary submit-time
  `error_log()` counter.
- `mobile/lib/features/work_history/data/api_work_history_repository.dart` — reverted byte-for-byte
  to its state before the forensic pass (no `debugPrint()`; malformed rows are silently skipped
  again, matching this codebase's pre-existing "one bad row never takes down the screen" pattern).
- `backend/cpms/api/v1/image_url_resolver_test.php` — kept (useful for CI/local verification), but
  its header comment was corrected (it was inaccurately describing a "fake site root under
  sys_get_temp_dir()" when the actual implementation uses the real repo `backend/` tree with an
  implausible fake property id) and now explicitly states **DO NOT DEPLOY TO PRODUCTION CPANEL** —
  it is dev/CI-only, not part of any deployment manifest, and has no route in production.

## What was NOT touched (verified still present, unchanged)

`cpmsApiResolveUploadedImageUrl()`, `cpmsApiDailyWorkImageUrl()`, `cpmsApiWorkOrderImageUrl()`, the
`cpmsApiTableExists()`/`cpmsApiColumnExists()` guarded fallbacks (the hotfix itself), `image_path`
read/write support on both legacy and mobile upload layouts, Work Order History's evidence
merging, `workHistoryProvider`/`dashboardDataProvider` invalidation after a successful submit, the
empty-active-tasks CTA into Work Order History, the rejected-work-order reopen/sync in
`daily_work_review.php`, the "Rejected – Action Required" banner, the labelled History navigation
button, and the Admin Daily Work image-resolver fix. `git diff` against the commit right after the
original image-URL fix confirms `work-history.php` and `daily-work/submit.php` are now identical
except for the removed debug blocks — no functional line was touched.
