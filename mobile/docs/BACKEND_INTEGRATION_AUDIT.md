# CPMSPro Staff Mobile App — Backend Integration Audit

**Status:** Audit complete. No backend implementation has been performed. This document is the deliverable for Phases 1–12 of the backend-integration-audit task; it does not itself integrate anything.

**Scope of this repository at audit time:** `mobile/` (the Flutter app) and a one-line root `README.md`. No backend code of any kind exists in this repository (see §6).

---

## 1. Executive Summary

The Flutter app is structurally complete and internally consistent: every feature has a repository interface, a working mock implementation, and a real `Dio`-backed implementation that already targets a documented REST contract (`lib/core/api/api_endpoints.dart`). `flutter analyze` reports 0 issues and the widget test suite passes.

**The one fact that governs everything else in this audit: there is no CPMSPro backend anywhere in this repository.** Every endpoint in the integration matrix below is `MISSING` in the strict sense the task defines — nothing can be marked `EXISTS` because there is no server-side code to verify it against. This isn't a defect in the Flutter app; it's the actual state of the project, and this audit reports it plainly rather than assuming a Laravel (or any other) backend that isn't there.

Beyond "no backend exists," this audit also found several **Flutter-side gaps that matter for integration planning**, independent of backend availability:

- The offline sync queue (the outbox that's supposed to protect evidence photos, daily work, attendance, and PM completions from lost connectivity) is wired into exactly one code path: `MockTasksRepository.uploadEvidence`. None of the real `Api*Repository` classes check connectivity or enqueue anything, and no other mock repository (daily work, attendance, PM) does either. The Sync Centre UI and the `PendingSyncType` enum imply four types of offline-safe operations; only one is actually offline-safe today.
- Token refresh is scaffolded (storage fields, an endpoint constant, a `ApiClient.onTokenRefreshNeeded` hook) but never wired up. A 401 today always ends the session; it never attempts a refresh.
- `GET /api/v1/app/config` and `POST /api/v1/notifications/devices` are defined as constants but never called by any repository.
- No request in the app carries a client-generated idempotency key, so any retry (manual or automatic, once the offline queue is extended) risks duplicate writes on the backend.
- Firebase Cloud Messaging is a pubspec dependency but is never initialized or referenced from `lib/` — push is entirely unimplemented, only the local-notification/deep-link plumbing exists.

None of this blocks starting backend work. It does mean "flip `useMockApi` to `false` and it just works offline-first" is not true yet — that needs Stage 11 work (§15) alongside the backend build-out.

---

## 2. Flutter Architecture

Feature-based, Riverpod + GoRouter + Dio, per `mobile/README.md`:

```
lib/
  core/
    api/            ApiClient (Dio wrapper), ApiEndpoints, ApiException, mock fixtures
    config/         AppConfig (base URL, mock flag, timeouts), AppBranding
    database/       AppDatabase — sqflite: `cache` table (unused) + `pending_sync_items` outbox
    location/       LocationService (geolocator wrapper, on-demand only)
    network/        ConnectivityService (connectivity_plus stream)
    notifications/  PushNotificationService (local notifications + deep-link stream; no FCM wiring)
    permissions/    StaffPermission constants + StaffPermissions.can()
    router/         GoRouter config (StatefulShellRoute bottom-nav shell) + auth redirect guard
    storage/        SecureStorageService (flutter_secure_storage wrapper)
    theme/          AppTheme / AppPalette (built from AppBranding at runtime)
    utils/          Formatters, ImageUtils (JPEG re-encode + resize before upload)
  features/
    auth/ dashboard/ tasks/ attendance/ daily_work/ preventive_maintenance/
    assets/ notifications/ profile/ sync/
    each with: domain/ (models), data/ (repository interface + mock + api impl),
               application/ (Riverpod providers), presentation/ (screens/widgets)
  shared/           Cross-feature widgets (cards, badges, empty/error states, skeletons)
```

State management: `flutter_riverpod` (`StateNotifierProvider`, `FutureProvider.autoDispose`, plain `Provider`). Navigation: `go_router` with a `StatefulShellRoute.indexedStack` for the five bottom-nav tabs (Home/Tasks/Attendance/Alerts/Profile) and every other screen pushed on the root navigator. Networking: a single shared `Dio` instance inside `ApiClient`. Local storage: `flutter_secure_storage` for the session, `sqflite` for the offline outbox.

**Mock/real switch:** every feature's `application/*_providers.dart` does:
```dart
final xRepositoryProvider = Provider<XRepository>((ref) {
  if (AppConfig.useMockApi) return MockXRepository(...);
  return ApiXRepository(ref.watch(apiClientProvider));
});
```
`AppConfig.useMockApi` defaults to `true` and is a compile-time flag (`bool.fromEnvironment('USE_MOCK_API')`), so switching to the real backend is `--dart-define=USE_MOCK_API=false --dart-define=CPMSPRO_API_BASE_URL=https://...` — no code change required, confirmed present in all 8 features (auth, dashboard, tasks, attendance, daily_work, preventive_maintenance, assets, notifications).

---

## 3. Existing Features

All present and navigable, backed by mock data by default:

| Feature | Screens | Notes |
|---|---|---|
| Auth | Login | Show/hide password, Remember Me, Forgot Password (static dialog, no backend call) |
| Dashboard | Home | Greeting header, attendance quick-card, KPI row, priority task, quick actions (permission-gated), recent tasks, announcements |
| Tasks | Inbox, Detail | Unified inbox (All/New/In Progress/Completed/Overdue tabs + search), full workflow stepper, before/after evidence, complete-task sheet, rejection banner |
| Attendance | Dashboard, History | GPS clock in/out, monthly history + summary |
| Daily Work | Add, History | Category picker, camera/gallery photos, Today/Week/Month filters |
| Preventive Maintenance | List, Detail | Today/Upcoming/Overdue/Completed tabs, mandatory checklist gate before submission |
| Assets | QR Scanner, Detail | `mobile_scanner`-based scan, asset info + shortcut actions |
| Notifications | Centre | List with read/unread state, deep-link routing on tap |
| Profile | Profile | Info display + settings list — **Edit Profile, Change Password, Language, Notification Settings, App Settings, Help & Support, Privacy, About CPMSPro are all no-op stubs** (`onTap: () {}`), no screens or endpoints exist for them yet |
| Sync | Sync Centre | Pending-item list, manual retry, last-synced time |

---

## 4. Repository Architecture

Every feature follows the same three-file pattern under `data/`:

| Feature | Interface | Mock impl | API impl |
|---|---|---|---|
| Auth | `auth_repository.dart` | `mock_auth_repository.dart` | `api_auth_repository.dart` |
| Dashboard | `dashboard_repository.dart` | `mock_dashboard_repository.dart` | `api_dashboard_repository.dart` |
| Tasks | `tasks_repository.dart` | `mock_tasks_repository.dart` | `api_tasks_repository.dart` |
| Attendance | `attendance_repository.dart` | `mock_attendance_repository.dart` | `api_attendance_repository.dart` |
| Daily Work | `daily_work_repository.dart` | `mock_daily_work_repository.dart` | `api_daily_work_repository.dart` |
| Preventive Maintenance | `pm_repository.dart` | `mock_pm_repository.dart` | `api_pm_repository.dart` |
| Assets | `assets_repository.dart` | `mock_assets_repository.dart` | `api_assets_repository.dart` |
| Notifications | `notifications_repository.dart` | `mock_notifications_repository.dart` | `api_notifications_repository.dart` |

All mock repositories share one in-memory fixture set: `lib/core/api/mock/mock_fixtures.dart` (`MockFixtures.instance`), which is the single source of the demo staff user (`staff_id: STF-2031`, `property_id: PROP-V23`), demo tasks, PM tasks, notifications, assets and attendance history. This keeps demo data consistent across screens and is exactly what a backend team can use as the reference payload shape for seeding a staging environment.

**Sync feature is architecturally separate** (`features/sync/`): `SyncQueueController` (Riverpod `StateNotifier`) owns `AppDatabase`'s `pending_sync_items` table, listens to `ConnectivityService`, and drains the queue via `SyncHandlers.dispatch()` — a generic replayer that reconstructs the original HTTP request from the stored `endpoint`/`method`/`payload`/`filePaths`. This generic-replay design is sound, but as noted in §1 and detailed in §10, only one call site (`MockTasksRepository.uploadEvidence`) ever populates the queue.

---

## 5. Complete API Integration Matrix

Source: `lib/core/api/api_endpoints.dart` cross-referenced against every `api_*_repository.dart`. "Backend Status" is `MISSING` for every row because no backend exists in this repository (§6) — there is nothing to be `PARTIAL` or `EXISTS` against yet. Where the Flutter-side contract itself is incomplete or ambiguous, that's called out in the Notes column so it isn't silently inherited by whoever builds the backend.

| Feature | Endpoint | Method | Request | Expected Response | Auth | Backend Status | Notes |
|---|---|---|---|---|---|---|---|
| Authentication | `/api/v1/auth/login` | POST | `{username, password, device_session_id}` | Flat object: `user_id, staff_id, property_id, staff_name, role, employee_id?, phone?, email?, profile_image?, permissions[], property_branding{}, access_token, refresh_token, expires_at, device_session_id}` | None (issues the token) | MISSING | Client generates `device_session_id` in the request but the response is expected to echo one back too — confirm these are meant to be the same value or two different session identifiers. |
| Logout | `/api/v1/auth/logout` | POST | (empty; bearer token identifies session) | 2xx, body ignored | Bearer | MISSING | Client clears local session even if this call fails. |
| Token Refresh | `/api/v1/auth/refresh` | POST | Not implemented client-side | — | — | MISSING | Endpoint constant exists (`ApiEndpoints.refreshToken`); **no repository ever calls it**. `ApiClient.onTokenRefreshNeeded` hook exists but is never assigned. A 401 today goes straight to forced logout. |
| Current Staff Profile | `/api/v1/staff/profile` | GET | — | Same shape as login's user fields (no tokens) | Bearer | MISSING | Used both after login-restore and (in theory) for a manual refresh; no manual refresh entry point exists in the UI yet. |
| Property Branding | `/api/v1/app/config` | GET | — | `{cpmspro_logo_url, property_logo_url, property_name, management_company_name, primary_color, secondary_color}` | Bearer? | MISSING | **Endpoint constant defined, never called.** Branding today is parsed only from `property_branding` embedded in the login/profile response. Decide whether a standalone config endpoint is still wanted (e.g. for pre-login branding by subdomain) or should be dropped. |
| Dashboard | `/api/v1/staff/dashboard` | GET | — | `{today_overview: {total_tasks, completed, pending, overdue}, priority_task?, recent_tasks[], announcements[]}` | Bearer | MISSING | Client does `json['today_overview'] as Map` with no null fallback — a response missing that key will throw a cast error client-side, not fail gracefully. |
| Task Inbox | `/api/v1/staff/tasks` | GET | — | `{tasks: [StaffTask...]}` | Bearer | MISSING | No pagination params sent or expected. |
| Task Detail | `/api/v1/staff/tasks/{id}` | GET | — | `StaffTask` (see field list below) | Bearer | MISSING | |
| Accept Task | `/api/v1/staff/tasks/{id}/accept` | POST | — | Updated `StaffTask` | Bearer | MISSING | |
| Start Task | `/api/v1/staff/tasks/{id}/start` | POST | — | Updated `StaffTask` | Bearer | MISSING | |
| Before Evidence | *(read-only, embedded)* | — | — | Delivered as `inspection_issue.before_photos[]` inside the task detail payload, not a separate endpoint | Bearer | MISSING | Confirms the spec's intent — staff only ever *view* before photos, uploaded by Inspector/Admin through the web portal. |
| After Evidence | `/api/v1/staff/tasks/{id}/evidence` | POST (multipart) | `before_photo_id?, gps_lat?, gps_lng?, device_timestamp, file=<binary>` | `EvidencePhoto {id, url, uploaded_at, uploaded_by_role?, gps_lat?, gps_lng?}` | Bearer | MISSING | **Field-name inconsistency**: the online path sends the photo under multipart field `file` (singular); the offline-replay path (`SyncHandlers.dispatch`) sends it under `files[0]` because it reconstructs a generic multi-file `FormData`. The real backend needs to accept whichever one is chosen — recommend picking one and fixing the Flutter side to match (see §10). |
| Complete Task | `/api/v1/staff/tasks/{id}/complete` | POST | `{remarks, materials_used?, time_spent_minutes?}` | Updated `StaffTask`, expected `status: pending_verification` | Bearer | MISSING | No idempotency key (§7). |
| Submit Task | *(same call as Complete Task)* | — | — | — | — | — | The spec's "Complete Task" and "Submit for Verification" are one client action, one API call. |
| Rejected Task | *(delivered via task detail's `rejection_reason` field, plus a notification)* | GET | — | `StaffTask.rejection_reason` non-null when `status: rejected` | Bearer | MISSING | No dedicated "reject" endpoint from the staff side — rejection is something Property Admin/Inspector does on the web portal; staff only read the result. |
| Resubmission | `/api/v1/staff/tasks/{id}/evidence` then `/complete` | POST | Same as After Evidence / Complete Task | Same | Bearer | MISSING | Re-uses the same two endpoints; old rejected evidence must remain in the response's photo list (audit trail — see §9). |
| Attendance Clock In | `/api/v1/attendance/clock-in` | POST | `{lat, lng}` | `{allowed: bool, message, distance_meters?}` | Bearer | MISSING | **No accuracy or client timestamp sent** — only raw lat/lng. See §8. |
| Attendance Clock Out | `/api/v1/attendance/clock-out` | POST | `{lat, lng}` | `{allowed: bool, message}` | Bearer | MISSING | Same gap as clock-in. |
| Attendance History | `/api/v1/attendance/history` | GET | `?today=true` **or** `?year=&month=` | Two different shapes from the same endpoint (see below) | Bearer | MISSING | **Contract smell**: `fetchStatus()` expects a flat `{status, clock_in_time, clock_out_time, shift_label, is_late, overtime_minutes, property_name}` object when called with `today=true`; `fetchHistory()` expects `{records: [...], days_worked, total_hours, late_arrivals, overtime_hours}` when called with `year`/`month`. Recommend splitting into `GET /attendance/today` (or `/status`) and `GET /attendance/history` as two distinct endpoints. |
| Daily Work | `/api/v1/staff/daily-work` | GET | `?from=&to=` (ISO 8601) | `{entries: [DailyWorkEntry...]}` | Bearer | MISSING | |
| Daily Work (create) | `/api/v1/staff/daily-work` | POST (multipart) | `{title, category, location, description, start_time, completion_time?, remarks?, photos[0..n]}` | `DailyWorkEntry` | Bearer | MISSING | `category` is sent as the Dart enum's `.name` (e.g. `generalWork`, camelCase) — inconsistent with every other field's snake_case convention. Recommend the backend accept the exact set: `cleaning, maintenance, electrical, plumbing, landscaping, generalWork, inspectionSupport, other`, or the Flutter side should be changed to send snake_case (`general_work`) before backend contracts are finalized. |
| Preventive Maintenance (list) | `/api/v1/pm/tasks` | GET | — | `{tasks: [PmTask...]}` | Bearer | MISSING | |
| PM Detail | `/api/v1/pm/tasks/{id}` | GET | — | `PmTask` incl. `checklist[]` | Bearer | MISSING | |
| PM Checklist | *(no dedicated endpoint)* | — | — | — | Bearer | MISSING | Checklist ticks are held client-side only and submitted as part of PM Complete; there is no per-item "toggle" API call. Confirm this is acceptable (a crash mid-checklist loses ticks) or add a lightweight autosave endpoint. |
| PM Complete | `/api/v1/pm/tasks/{id}/complete` | POST (multipart) | `{notes?, checklist: [{id, is_checked}...], photos[0..n]}` | Updated `PmTask` | Bearer | MISSING | No idempotency key. |
| Assets | `/api/v1/assets/{id}` | GET | — | `{id, name, property_name, location, status, last_maintenance_date?, next_maintenance_date?}` | Bearer | MISSING | |
| QR Asset Lookup | *(client-side only — no lookup endpoint)* | — | QR payload parsed as `CPMSPRO:ASSET:<id>` or a bare ID, then routes to Asset Detail | — | — | UNKNOWN | This QR payload format is an assumption made while building the Flutter app, **not confirmed against any real CPMSPro asset label format.** Must be verified with whoever generates/prints the physical QR labels before this ships. |
| Notifications | `/api/v1/notifications` | GET | — | `{notifications: [{id, type, title, body, created_at, deep_link_route?, is_read}...]}` | Bearer | MISSING | No pagination. `deep_link_route` is expected to be a literal Flutter route string (e.g. `/tasks/WO-2026-0082`) supplied by the backend — tightly couples the backend to the app's GoRouter paths. Recommend the backend instead send structured `{entity_type, entity_id}` and let the client build the route, so route refactors don't require backend changes. |
| Notification Read Status | `/api/v1/notifications/{id}/read` | POST | — | 2xx | Bearer | MISSING | |
| Notification Device Registration | `/api/v1/notifications/devices` | POST | Not implemented client-side | — | — | MISSING | Endpoint constant defined (`ApiEndpoints.notificationDeviceRegister`), never called — no FCM token to register yet (§1, §14 Stage 12). |
| Offline Sync | *(generic replay of whichever endpoint/method was queued)* | varies | Whatever was captured at enqueue time | Whatever that endpoint normally returns | Bearer | MISSING | Only evidence-photo uploads are ever queued today (§1, §10). No idempotency key is attached to queued items. |

`StaffTask` field list (for reference, from `StaffTask.fromJson`): `id, task_number, title, description, category, priority, status, property_name, location, assigned_by, assigned_date, due_date, completion_remarks?, materials_used?, time_spent_minutes?, rejection_reason?, requires_evidence?, after_photos[], inspection_issue?{issue, location, severity, inspector_remark, before_photos[]}`.

---

## 6. Backend Detection Results

**Searched the entire repository** (everything outside `mobile/`, plus a full-repo scan for common backend signatures) for: `composer.json`, `artisan`, `routes/api.php`, `routes/web.php`, any `*.php`, any `*.sql`, `wp-config.php`.

**Result: zero matches.** The repository root contains only `README.md` (21 bytes, just the project name) and the `mobile/` Flutter app. There is:

- No PHP of any kind.
- No Laravel project (no `composer.json`, no `artisan`, no `app/Http/Controllers`).
- No routes files, no middleware, no migrations, no SQL.
- No database configuration.
- No existing REST API implementation in any language.

**Conclusion: the CPMSPro backend does not exist in this repository.** This audit does **not** assume Laravel, plain PHP, or any other stack — the Flutter app's REST expectations (documented in §5) are stack-agnostic and can be implemented in whatever the real CPMSPro production backend actually runs on. If a CPMSPro backend repository exists elsewhere (a separate repo, a different host), it is out of this session's scope until it is explicitly attached — this audit cannot see it and has not assumed anything about it.

---

## 7. Authentication Requirements

**Login request:** `POST /api/v1/auth/login` — `{username, password, device_session_id}` (see `mobile/lib/features/auth/data/api_auth_repository.dart`).

**Login response (expected):** a single flat JSON object combining staff identity and session:
```json
{
  "user_id": "...", "staff_id": "...", "property_id": "...",
  "staff_name": "...", "role": "...", "employee_id": "...",
  "phone": "...", "email": "...", "profile_image": "...",
  "permissions": ["staff.dashboard.view", "..."],
  "property_branding": { "cpmspro_logo_url": "...", "property_logo_url": "...",
                          "property_name": "...", "management_company_name": "...",
                          "primary_color": "#RRGGBB", "secondary_color": "#RRGGBB" },
  "access_token": "...", "refresh_token": "...",
  "expires_at": "ISO-8601", "device_session_id": "..."
}
```

**Access token:** sent as `Authorization: Bearer <token>` on every request (`ApiClient._onRequest`). Also attaches `X-Device-Session-Id` if one is stored.

**Refresh token:** stored (`SecureStorageService.refreshToken`, `.tokenExpiry`) but **never used**. `ApiClient` exposes `onTokenRefreshNeeded` specifically so a refresh flow can be plugged in without touching call sites, but nothing assigns it today. **This is the biggest functional gap in the auth layer** — see Stage 1 recommendation below.

**Secure storage:** `flutter_secure_storage` (`SecureStorageService`) — Keychain on iOS, `EncryptedSharedPreferences` on Android. Stores access token, refresh token, expiry, device session id, and (separately, for the "Remember Me" checkbox) the last-used username. `property_id`/`staff_id`/`role`/`permissions` are **not** persisted here directly — they live only in the in-memory `StaffUser` held by `AuthController`'s Riverpod state.

**Logout:** `POST /api/v1/auth/logout`, then clears secure storage regardless of whether that call succeeded (best-effort network call, always-succeeds local cleanup — this is deliberate so a broken network never traps a user in a logged-in state they can't exit).

**Session restoration (app cold start):** `AuthController._restoreSession()` reads the stored access token; if present, calls `GET /staff/profile` to reconstruct the full `StaffUser` (including `property_id`). **Any failure at all** — expired token, no network, corrupted secure storage — currently results in the same outcome: clear the session and show the login screen. There is no distinction between "token is genuinely invalid" and "we're offline and can't verify it," so a staff member who opens the app with no signal will be logged out rather than shown cached data. This is intentional-by-omission (no profile cache exists — see §10) rather than a deliberate security decision, and is worth revisiting once offline caching is built.

**Expired session behaviour:** `ApiClient._onError` intercepts any 401; since no refresh callback is wired, it calls `onSessionExpired` (assigned in `auth_providers.dart` to `AuthController.forceLogout()`), which clears storage and flips `AuthState` to `unauthenticated`. `GoRouter`'s redirect (`app_router.dart`) reacts immediately via a `ChangeNotifier` bridge and routes to `/login`.

**Property context:** `property_id` is read once at login/profile-fetch and held read-only in `StaffUser` — there is no setter anywhere in the codebase (verified: no repository, provider, or screen ever sends `property_id` or `staff_id` as a request parameter; grepped across every `api_*_repository.dart`). The client only ever *displays* the property context it was issued; it cannot request a different one.

**Permission handling:** `StaffPermissions` (`core/permissions/staff_permissions.dart`) wraps the flat `permissions[]` list from login and exposes `.can('staff.pm.view')` etc. Used only to hide/show quick actions on the dashboard (`_QuickActionsGrid`). **This is UI convenience only** — nothing in the Flutter app enforces permissions beyond hiding a button, which is correct per the spec (server must independently enforce the same permission on every mutating endpoint) but worth stating explicitly: a modified APK or a direct API call bypasses all client-side permission checks trivially.

### Recommended compatibility approach

1. Do not try to make the mobile login "look like" the existing web portal's session/cookie auth. Use a separate token-based auth path (`/api/v1/auth/login` issuing a bearer token) that internally validates against the same staff/credentials table the web portal already uses. This is additive, not a replacement (§10).
2. Implement real refresh-token rotation server-side before shipping, and wire `ApiClient.onTokenRefreshNeeded` client-side to call it — today a token expiring mid-shift silently logs the staff member out with whatever work was in progress on-screen (queued evidence photos are safe; anything not yet queued is not).
3. Keep `property_id`/`role`/`permissions` computed server-side from the authenticated session on every request, never trust anything the client sends (see §8, already followed correctly in the current Flutter code).

---

## 8. Multi-Property Security

**Current Flutter-side posture is correct**: verified by grep that no repository, provider, or screen ever includes `property_id` or `staff_id` in a request body or query string. Every "real" repository relies entirely on the bearer token for the backend to determine scope. This matches spec §30/31 exactly and needs no Flutter-side change.

**What the backend must do (cannot be verified — no backend exists):**
- Every endpoint in §5 must derive the authenticated staff's `property_id` from the session/token server-side and filter *all* data access by it — tasks, evidence, attendance, assets, PM, notifications.
- IDOR risk is concentrated in the `:id`-parameterized endpoints: `GET/POST /staff/tasks/{id}/*`, `GET/POST /pm/tasks/{id}/*`, `GET /assets/{id}`, `POST /notifications/{id}/read`. Each of these must verify the referenced task/asset/notification actually belongs to the authenticated staff's property before returning or mutating anything — a sequential or guessable ID scheme without that check is a direct cross-property data leak (Staff at `property_id=3` requesting `/staff/tasks/{a task belonging to property_id=1}` must get 403/404, not the record).
- The QR asset lookup (§5) resolves `assets/{id}` the same way — scanning a QR code from a different property (deliberately or by a mislabeled sticker) must not leak that asset's data.
- No endpoint should accept `property_id` as a client-supplied parameter for authorization purposes, even as a "default" or "override" — only ever for read-only display echoes, if at all.

---

## 9. Photo Evidence Architecture

**Before photos:** read-only, delivered embedded in the task detail response (`inspection_issue.before_photos[]`). Staff can view/zoom (`FullScreenImageViewer`) but never upload or modify them. Correct per spec §11.

**After photos — capture:** `EvidencePicker` (`shared/utils/evidence_picker.dart`) offers Camera (`CameraCaptureScreen` — take/retake/use/flash/gallery-jump) or Gallery (`image_picker`). Both paths return a local `File`.

**Compression:** `ImageUtils.compressForUpload` (`core/utils/image_utils.dart`) always re-encodes to JPEG, capping the long edge at 1600px and quality 78 (`AppConfig.evidenceImageMaxDimension/evidenceImageQuality`). This is applied identically for task evidence, daily work photos, and PM evidence. If the source file fails to decode (corrupt/unsupported format), the **original, unmodified file** is silently uploaded instead — worth hardening with an explicit rejection + user-facing error rather than a silent fallback.

**MIME/file size validation:** no explicit client-side MIME sniffing or max-file-size check beyond what compression naturally produces (compression always outputs `image/jpeg`; if compression is skipped due to decode failure, whatever the source file actually is gets uploaded as-is). **The backend must independently validate MIME type and enforce a max size regardless of what the client claims** — never trust the client's re-encoding as a security boundary.

**Metadata sent with each after-photo:** `before_photo_id?` (links after→before), `gps_lat?`, `gps_lng?` (best-effort — silently omitted if location isn't available, never blocks the upload), `device_timestamp` (client clock, not server-verified). **Not sent**: `task_id` is implicit in the URL path, not a separate body field for the online path — but *is* included as a `task_id` field in the queued/offline payload (`mock_tasks_repository.dart`), an inconsistency worth resolving so the backend sees one consistent shape regardless of path.

**Server filename handling:** entirely server's responsibility — the client sends the file as multipart binary with no filename significance beyond what Dio auto-assigns.

**Duplicate upload protection:** **none.** No idempotency key, no client-side "already uploaded" guard beyond the in-memory task state. If a request times out after the server already received and stored the file, retrying (manual or automatic) creates a second `EvidencePhoto` record. See §10 for the recommended fix.

**Evidence relationships:** `task_id` (path), `before_photo_id` (optional body field, links resubmission to the original inspection photo), `gps_lat/lng` (optional). **`property_id` and `staff_id` are correctly never sent** — the backend must derive both from the authenticated session, consistent with §8.

**Audit preservation after rejection — this is correctly designed on the Flutter side:** `StaffTask.rejectionReason` is a separate field from `afterPhotos`; when a task is resubmitted, `MockTasksRepository.completeTask` clears `rejectionReason` but the mock model has **no delete/overwrite path for `afterPhotos` at all** — new evidence is always appended (`copyWith(afterPhotos: [...existing, newPhoto])`), never replacing prior entries. `PhotoEvidenceGrid` renders every photo in the list, so a rejected-then-resubmitted task shows the full history in the UI. **The backend must mirror this exactly**: `POST /staff/tasks/{id}/evidence` must always create a new evidence row/version, never overwrite or soft/hard-delete a prior one, even across a reject→resubmit cycle. This is the single most important data-integrity requirement in the whole matrix for audit/compliance purposes.

---

## 10. Offline Sync Architecture

**What exists:** `AppDatabase` (`core/database/app_database.dart`, sqflite) defines two tables:
- `cache` (`putCache`/`getCache`) — intended per the original spec to hold last-known-good profile/tasks/PM-checklist JSON for offline viewing. **Defined, never called from anywhere else in the codebase.** No screen reads cached data when offline; a `FutureProvider` with no connectivity just surfaces the error state.
- `pending_sync_items` — the actual outbox. `SyncQueueController` (`features/sync/application/sync_providers.dart`) loads/watches it, listens for connectivity returning, and drains it via `SyncHandlers.dispatch()`.

**What's actually wired to write to the outbox:** exactly one call site — `MockTasksRepository.uploadEvidence`, and only when `AppConfig.useMockApi` is true. Concretely:
- `ApiTasksRepository.uploadEvidence` (the real-backend path) does **not** check connectivity and does **not** enqueue on failure — it will simply throw `ApiException(noConnection)` up to the UI.
- `MockAttendanceRepository`, `MockDailyWorkRepository`, `MockPmRepository` (and their `Api*` counterparts) have **no offline handling at all**, despite `PendingSyncType` defining `attendance`, `dailyWork`, and `pmCompletion` as if they were supported, and the Sync Centre UI rendering icons for all four types.

**This must be treated as a Flutter-side implementation task, not a backend task**, before the app can honestly claim the offline guarantees in spec §26/27. The fix shape is already right (generic `PendingSyncItem` + `SyncHandlers.dispatch` replay) — it just needs to be:
1. Extracted out of `MockTasksRepository` into a shared helper usable by both mock and real repositories.
2. Applied consistently to `ApiTasksRepository.uploadEvidence`, `Api/MockAttendanceRepository.clockIn/clockOut`, `Api/MockDailyWorkRepository.createEntry`, `Api/MockPmRepository.completeTask`.
3. Given the read-side cache (`AppDatabase.putCache/getCache`) an actual write path (populate on every successful fetch) and read path (serve as fallback when a `FutureProvider` fails due to `ApiFailureType.noConnection`), so cold-start-while-offline shows something instead of an error card.

**Idempotency (§7 of the task brief):** no request anywhere in the app carries a client-generated key today. Recommended strategy once the above is built:
- Add a client-generated UUID (`idempotency_key` or similar) to every `PendingSyncItem` at *creation* time (not at replay time, so a retried replay reuses the same key).
- Attach it as a header (`Idempotency-Key: <uuid>`) or body field on: evidence upload, daily work creation, task completion, PM completion, and attendance clock-in/out.
- Backend stores `(idempotency_key)` per staff and, on a duplicate, returns the original result (200 with the existing record) instead of creating a second one. Standard pattern; the client-side change is small (generate the UUID once, store it alongside the queued payload) — the bulk of the work is backend-side.

---

## 11. GPS Attendance Architecture

**Flow implemented:** `LocationService.getCurrentPosition()` (`core/location/location_service.dart`, wraps `geolocator`) → `AttendanceController.clockIn()/clockOut()` → repository. GPS is requested **on-demand only** at the moment of clock-in/out — there is no background or continuous location tracking anywhere in the app (correct per spec §34/§20).

**What's sent to the backend:** `{lat, lng}` only (`ApiAttendanceRepository.clockIn/clockOut`). `Position.accuracy` and a device timestamp are available from `geolocator` but are **not currently forwarded** — worth adding, since accuracy in particular matters for a backend geofence check (a low-accuracy fix near a boundary is a materially different trust signal than a high-accuracy one).

**What's returned:** `{allowed: bool, message, distance_meters?}` — the mock repository (`MockAttendanceRepository`) simulates this by computing `Geolocator.distanceBetween()` against a hardcoded demo geofence center/radius (`250m`, purely for demo purposes) and returning allow/deny + human-readable message accordingly. **This client-side simulation must never be mistaken for real geofence enforcement** — it exists solely so the mock UX (the "Location Verified ✓" / "Unable to Clock In" dialog) can be demoed without a backend.

**Backend validation requirements (none of this exists yet — server-side only):**
- Receive `lat, lng` (recommend adding `accuracy_meters`, `device_timestamp`) per clock-in/out request.
- Look up the authenticated staff's assigned `property_id` server-side (never trust a client-sent property_id).
- Look up that property's configured geofence (center point + radius, presumably an admin-configurable value in the CPMSPro property record — not present in this repo, needs the backend/database team to confirm where that lives).
- Compute distance server-side; return `allowed: false` with a clear `message` if outside the radius, `allowed: true` if inside.
- Only write the attendance record if `allowed: true` — the client must never be able to force a clock-in by sending fabricated coordinates that merely *look* plausible; the server's own distance computation is the sole authority, exactly as the spec requires ("Never trust the mobile device alone for geofence validation").
- Reject implausible fixes if desired (e.g. `accuracy_meters` beyond some threshold, or a timestamp too far from server time) — this needs a product decision, not just an engineering one.

---

## 12. Missing Backend APIs

Every endpoint in §5 is missing (no backend exists — §6). Restating as a priority-ordered punch list rather than repeating the full matrix:

**Must exist before any real-device testing is possible (Stage 1–4 blockers):**
1. `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/staff/profile`
2. `GET /api/v1/staff/dashboard`
3. `GET /api/v1/staff/tasks`, `GET /api/v1/staff/tasks/{id}`
4. `POST /api/v1/staff/tasks/{id}/accept`, `/start`, `/evidence`, `/complete`

**Needed for the remaining core workflow (Stage 5–9):**
5. `POST /api/v1/attendance/clock-in`, `/clock-out`, `GET /api/v1/attendance/history` (recommend splitting into `/status` + `/history` — see §5)
6. `GET /api/v1/staff/daily-work`, `POST /api/v1/staff/daily-work`
7. `GET /api/v1/pm/tasks`, `GET /api/v1/pm/tasks/{id}`, `POST /api/v1/pm/tasks/{id}/complete`
8. `GET /api/v1/assets/{id}`

**Needed before push/offline claims are accurate (Stage 10–11):**
9. `GET /api/v1/notifications`, `POST /api/v1/notifications/{id}/read`, `POST /api/v1/notifications/devices` (for FCM token registration — needs Stage 12 first)
10. Idempotency support (header or field) on every write endpoint listed above (§10)

**Explicitly out of scope for now, per the task brief's own Stage list:** Firebase FCM server-side push sending, server-configurable watermark config delivery, full localization — these are Stage 12–14 and shouldn't block Stage 1–11 backend work.

**Not yet defined anywhere (needs a product/backend decision before Stage-appropriate work starts):**
- `POST` endpoints for Edit Profile / Change Password (§3 — currently UI stubs with no contract at all).
- Where a property's geofence center/radius is configured and how the attendance endpoints read it (§11).
- Whether `GET /api/v1/app/config` is still wanted for pre-login branding, given branding is currently delivered via the login/profile payload instead (§5).

---

## 13. Database Requirements

No database exists in this repository to audit. Based purely on what the Flutter app's contracts imply, a backend will need (at minimum) tables/entities for: staff accounts (with `property_id`, `role`, hashed credentials), properties (with branding fields and geofence config), permissions (flat strings, staff-to-permission mapping), tasks (work orders / inspection corrective actions / PM / daily assignments / supervisor tasks — unified in the mobile UI but the backend is free to keep them as separate source tables that get merged into the `/staff/tasks` response), task evidence (before photos from inspectors, after photos from staff, versioned/append-only per §9), attendance records (clock in/out timestamps, computed lateness/overtime, geofence-check result), daily work entries, PM tasks + checklists + completions, assets, notifications (with read state per staff), and a device-token table for FCM registration. This is inferred entirely from the Flutter contracts in §5 and must be validated against whatever CPMSPro's actual production schema already looks like — this audit has no visibility into it.

---

## 14. Security Risks

1. **IDOR on every `:id` endpoint** unless the backend enforces property-scoping server-side on each one individually (§8) — the single highest-priority risk, since the entire multi-property security model depends on getting every one of these right, not just most of them.
2. **No token refresh** means either (a) sessions expire and staff are logged out mid-shift with no graceful recovery, or (b) whoever implements the backend is tempted to issue very-long-lived access tokens to work around the missing refresh flow — which would be worse. Fix client + server together (§7).
3. **No idempotency keys** on write endpoints — once the offline queue is extended to attendance/daily-work/PM (§10), automatic retries on flaky connections will create duplicate records without this.
4. **Photo evidence has no server-independent MIME/size validation described** — must not rely on the client's JPEG re-encoding as a security boundary (§9).
5. **QR asset payload format is unverified** (§5) — if the real physical QR labels use a different encoding than `CPMSPRO:ASSET:<id>`, this fails silently or, worse, mis-parses; needs confirmation before physical rollout, not a security risk per se but a correctness/trust risk (staff scanning a QR and reaching the wrong asset).
6. **`deep_link_route` supplied verbatim by the backend** (§5) means a compromised or buggy backend could send an arbitrary in-app route string; GoRouter will attempt to navigate to whatever string it's given. Low severity (no known route in this app performs a destructive action on navigation alone) but worth a basic allow-list validation either client- or server-side before this ships.
7. **Client-side permission checks are cosmetic only** (§7) — confirmed no enforcement gap on the Flutter side (it never claims to enforce), but this needs the backend team to explicitly commit to checking every one of the `staff.*` permission strings server-side, not just using them for UI.

---

## 15. Recommended Implementation Order

See §16 for the numbered stage plan (Phase 12 of the task). At a glance, the dependency chain is: **Auth → Profile/Branding → Dashboard/Tasks (read) → Task workflow (write) → Evidence upload → Attendance → Daily Work/PM/Assets (read+write) → Notifications → Offline sync hardening → Push → Watermark → Localization → Device testing.** Each stage is independently testable against the mock repositories today (already true) and against a real backend once that stage's endpoints exist — no stage requires a later stage's endpoints to be functional.

---

## 16. Testing Strategy

- **Per-stage backend contract tests**: for each endpoint group in §12, write integration tests against a real (staging) backend that assert the exact response shape in §5 — the Flutter parsers (`StaffTask.fromJson` etc.) are strict (`as String`, `as Map<String,dynamic>` with no defensive fallback in several places) and will throw on missing required fields rather than degrading gracefully. Treat §5's "Notes" column as a checklist of shape details a contract test must pin down before the Flutter side is pointed at it.
- **Flip the switch, don't rewrite**: once a stage's endpoints are live on staging, test by running the existing app with `--dart-define=USE_MOCK_API=false --dart-define=CPMSPRO_API_BASE_URL=<staging>` — no Flutter code changes should be needed for a correctly-implemented endpoint, which is itself the test that the contract in §5 was followed.
- **Property-isolation tests are non-negotiable**: for every `:id` endpoint, a specific test must attempt cross-property access (Staff A at property 1 requesting Staff B's task/asset/attendance record at property 2) and assert 403/404, not the record.
- **Idempotency replay tests**: once §10's client-generated keys exist, a specific test must submit the same evidence-upload/completion request twice with the same key and assert exactly one record is created.
- **Offline drills**: airplane-mode a real device mid-task, capture evidence, complete the task, then re-enable connectivity and confirm the Sync Centre drains to zero and the backend ends up with exactly the records a fully-online run would have produced — this exercises §9 (append-only evidence) and §10 (idempotency) together.
- **`flutter analyze` and `flutter test` must stay green** through every stage — both already pass; regressions should be caught immediately, not accumulated.

---

## 17. Production Deployment Strategy

1. Stand up the backend behind `/api/v1/...` on its own subdomain or path prefix, kept structurally separate from any existing HTML web-portal routes (§ "API Versioning" in the task brief) — the mobile app should never receive an HTML response where JSON is expected.
2. Build and validate each stage (§16) against a staging backend before touching production data.
3. Ship the mobile app to internal/TestFlight-equivalent distribution first, still pointed at staging, with a handful of real staff accounts across at least two different properties specifically to exercise the property-isolation tests in §16.
4. Only after property-isolation and idempotency tests pass on staging, point a release build at production (`--dart-define=CPMSPRO_API_BASE_URL=<production>` at build time, not runtime-switchable, so a build artifact's target is always auditable from its build command).
5. Roll out Firebase/FCM (Stage 12) and the watermark config (Stage 13) after the core workflow is confirmed stable in production — both are additive and shouldn't block the core release.
6. Confirm, before wide rollout, that nothing in the backend work broke the existing CPMSPro web portal (Property Admin / Staff Web / Security / Resident / System Owner) — since this repository contains no web portal code, that verification has to happen against the actual production backend/portal codebase, wherever it lives, not against anything in this repository.

---

## Phase 12 — Numbered Implementation Plan

1. **Stage 1 — Authentication + Session + Property Context.** Backend: `POST /auth/login`, `POST /auth/logout`, `GET /staff/profile`, real refresh-token issuance/rotation, `POST /auth/refresh`. Flutter: wire `ApiClient.onTokenRefreshNeeded` to actually call the refresh endpoint (currently unwired — §7).
2. **Stage 2 — Staff Profile + Dynamic Property Branding.** Confirm whether branding stays embedded in login/profile (current Flutter assumption) or moves to a standalone `GET /app/config` call (defined, unused today — §5); implement whichever is decided.
3. **Stage 3 — Dashboard + Task Inbox.** `GET /staff/dashboard`, `GET /staff/tasks`, `GET /staff/tasks/{id}`. Add the `today_overview` null-fallback robustness fix noted in §5 while this is being built.
4. **Stage 4 — Task Detail + Status Workflow.** `POST /staff/tasks/{id}/accept`, `/start`, `/complete`. Confirm rejection/verification are Property-Admin/web-portal actions only (current Flutter assumption — §5).
5. **Stage 5 — Before/After Evidence Upload.** `POST /staff/tasks/{id}/evidence`. Resolve the `file` vs `files[0]` multipart field-name inconsistency (§5, §9) before backend work starts — recommend standardizing on a single field name and fixing the offline-replay path to match the online path. Enforce append-only, versioned evidence storage server-side (§9) — this is the top data-integrity requirement in this whole audit.
6. **Stage 6 — GPS Attendance.** `POST /attendance/clock-in`, `/clock-out`. Add `accuracy_meters`/`device_timestamp` to the client request (currently only `lat`/`lng` — §11). Split `GET /attendance/history` into a `today`/`status` endpoint and a `history` endpoint rather than overloading one endpoint with two response shapes (§5).
7. **Stage 7 — Daily Work.** `GET`/`POST /staff/daily-work`. Resolve the `category` casing convention (Dart camelCase `.name` vs the rest of the API's snake_case — §5) before finalizing the contract.
8. **Stage 8 — Preventive Maintenance.** `GET /pm/tasks`, `GET /pm/tasks/{id}`, `POST /pm/tasks/{id}/complete`. Decide whether checklist ticks need a lightweight autosave endpoint or stay client-side-only-until-submit (§5).
9. **Stage 9 — Asset QR.** `GET /assets/{id}`. **Before backend work here**, confirm the real QR label payload format with whoever prints them — the current `CPMSPRO:ASSET:<id>` assumption is unverified (§5, §14).
10. **Stage 10 — Notifications.** `GET /notifications`, `POST /notifications/{id}/read`. Decide on `deep_link_route` (backend-supplied literal route, current assumption) vs `{entity_type, entity_id}` (client-constructed route, recommended for resilience — §5, §14).
11. **Stage 11 — Offline Synchronisation (Flutter-side work, not backend).** Extract the offline-queue logic out of `MockTasksRepository` into a shared helper; wire it into `ApiTasksRepository.uploadEvidence` and all of attendance/daily-work/PM's real+mock repositories (§10 — currently only one of four intended flows is actually offline-safe). Add client-generated idempotency keys to every queued item (§10, §14) and require the backend to honor them. Wire up `AppDatabase`'s unused `cache` table so a cold start while offline shows cached data instead of an error state.
12. **Stage 12 — Firebase FCM.** Add `google-services.json`/`GoogleService-Info.plist`, call `Firebase.initializeApp()`, wire `firebase_messaging` onMessage/onMessageOpenedApp/background handlers into the existing `PushNotificationService.showLocalNotification`/`onDeepLink` plumbing (already built and ready — §1), implement `POST /notifications/devices` for token registration.
13. **Stage 13 — Server-configurable Photo Watermark.** Backend: deliver a watermark on/off + content config (likely via the Stage 2 branding/config mechanism). Flutter: implement actual pixel watermarking in `CameraCaptureScreen`/`ImageUtils` (not started — currently the camera screen has no watermark rendering at all).
14. **Stage 14 — Full English / Bahasa Melayu Localization.** `lib/l10n/app_en.arb` and `app_ms.arb` already define the ARB structure and `flutter_localizations` is wired in `main.dart`; the remaining work is extracting the many literal English strings across screens into `AppLocalizations.of(context)!.*` calls (not done — most screens still use literals, per `mobile/README.md`'s own stated gap).
15. **Stage 15 — Production APK Testing.** Real-device testing per §16/§17: property-isolation tests, idempotency replay tests, offline drills, staged rollout from internal → staging-backend → production-backend, with `flutter analyze`/`flutter test` gating every stage's merge.

---

## Stop-Condition Checklist (per task brief)

1. **What backend was actually found:** none (§6).
2. **Which APIs already exist:** none can be marked `EXISTS` — no backend exists to verify against (§5, §6).
3. **Which APIs are missing:** all of them (§5, §12).
4. **Mismatches between Flutter and the (nonexistent) CPMSPro backend:** documented per-endpoint in §5's Notes column and consolidated in §1 — the `file`/`files[0]` evidence field-name inconsistency, the overloaded `/attendance/history` endpoint, the `category` casing convention, the unused `/app/config` and `/notifications/devices` endpoints, and the never-wired refresh-token flow are the concrete ones.
5. **Security concerns:** §14, ranked by priority — IDOR/property-scoping is the top concern precisely because it can't be checked from the Flutter side alone; it requires backend enforcement on every `:id` route.
6. **Proposed integration architecture:** §2 (existing, unchanged) + §5 (the contract) + §10 (the offline-queue fix needed before that architecture's offline claims are true).
7. **Recommended first implementation stage:** **Stage 1 — Authentication + Session + Property Context**, specifically starting with `POST /auth/login` + `GET /staff/profile` + real refresh-token support, since every other stage requires a working authenticated session and the refresh-token gap is the single highest-leverage fix in the current Flutter auth layer.
8. **Waiting for approval before major backend implementation:** confirmed — no backend code has been written as part of this audit. The only changes made were two documentation-accuracy fixes to existing comments (`app_branding.dart`) and this document itself; see the commit for the exact diff.
