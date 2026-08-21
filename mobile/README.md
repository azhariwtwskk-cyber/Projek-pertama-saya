# CPMSPro Workforce

A premium Flutter mobile app for the CPMSPro Staff / Maintenance / Cleaner /
Technician workforce module. CPMSPro's existing web system stays the
backend and system of record; this app talks to it over the REST API
described in [`lib/core/api/api_endpoints.dart`](lib/core/api/api_endpoints.dart).

## Status

This repository had no existing CPMSPro backend or database to map against
(it started as an empty project), so the app ships against a documented
REST contract with two interchangeable data layers:

- **Mock mode** (`AppConfig.useMockApi = true`, the default) — an
  in-memory fixture backend (`lib/core/api/mock/mock_fixtures.dart`) so the
  full UX — login, dashboard, task workflow, evidence capture, attendance,
  PM, notifications, offline sync — can be run and demoed today.
- **Live mode** (`--dart-define=USE_MOCK_API=false --dart-define=CPMSPRO_API_BASE_URL=...`)
  — a Dio-backed implementation of the same repository interfaces, ready to
  point at the real CPMSPro API once those endpoints exist. Every
  `data/api_*_repository.dart` file is the integration point; nothing in
  `presentation/` or `application/` needs to change to switch.

No Flutter SDK was available in the environment this was built in, so the
code has not been run through `flutter analyze` / `flutter test` /
`flutter build`. Before shipping, run:

```bash
flutter create .          # generates android/, ios/, and other platform folders
flutter pub get
flutter gen-l10n          # or just `flutter run`, which does this automatically
flutter analyze
flutter test
```

## Architecture

Feature-based, matching section 33 of the spec:

```
lib/
  core/            # api client, auth/session, config, database (offline
                    # outbox), network, notifications, permissions,
                    # storage, theme, location, router, utils
  features/
    auth/          # login, session state
    dashboard/     # home screen
    tasks/         # unified task inbox + the ACCEPT -> START -> BEFORE
                    # EVIDENCE -> WORK -> AFTER EVIDENCE -> COMPLETE ->
                    # SUBMIT -> VERIFY/REJECT workflow
    attendance/    # GPS-gated clock in/out + history
    daily_work/    # ad-hoc daily work logging + history
    preventive_maintenance/
    assets/        # QR scan + asset detail
    notifications/ # notification centre + push deep linking
    profile/
    sync/          # offline outbox / Sync Centre
  shared/          # cross-feature widgets (cards, badges, empty states,
                    # skeleton loaders, full-screen image viewer)
```

State management is Riverpod (`flutter_riverpod`), navigation is GoRouter
with a `StatefulShellRoute` bottom-nav shell, networking is Dio, tokens
live in `flutter_secure_storage`, and the offline outbox is a small
`sqflite` table (`core/database/app_database.dart`).

## Design decisions worth knowing

- **property_id / staff_id / role / permissions are never client-writable.**
  They arrive once at login (`StaffUser.fromJson`) and are otherwise only
  ever read from server responses — see section 30/31 of the spec.
  Permission checks in the UI (`StaffPermissions.can(...)`) are a
  convenience for hiding buttons; the same permission must be re-checked
  server-side on every mutating endpoint.
- **Offline evidence is never lost.** `TasksRepository.uploadEvidence`
  compresses the photo, and if the device is offline, copies it to
  permanent app storage and writes a `PendingSyncItem` to the local outbox
  *before* returning — the UI shows a "PENDING SYNC" badge on the photo
  immediately. `SyncQueueController` drains the outbox automatically the
  moment connectivity returns, and the Sync Centre screen exposes a manual
  "Retry Sync".
- **Attendance geofencing is server-authoritative.** The app only ever
  sends a GPS fix to `/attendance/clock-in`; whether that fix falls inside
  the property's configured geofence is a server decision
  (`GeofenceResult`), never computed on-device.
- **Branding is fully dynamic.** `AppBranding`/`AppPalette` come from the
  login/dashboard payload, not a compile-time constant, so one build serves
  every CPMSPro property.
- **Localization is scaffolded, not fully wired.** `lib/l10n/app_en.arb`
  and `app_ms.arb` define the ARB structure and a representative string
  set (`l10n.yaml` + `flutter_localizations` are already configured in
  `pubspec.yaml`/`main.dart`); most screens still use literal English
  strings and would need extraction to `AppLocalizations.of(context)!.*`
  before Bahasa Malaysia is actually selectable end-to-end.

## Not yet wired (documented, intentionally out of scope for this pass)

- Firebase project config (`google-services.json` /
  `GoogleService-Info.plist`) — `PushNotificationService` is written
  against `flutter_local_notifications` and ready for `firebase_messaging`
  once a project exists; FCM itself needs `Firebase.initializeApp()` added
  to `main.dart` plus platform config.
- Server-configurable photo watermarking (section 14) — the camera capture
  screen is ready for it; the actual pixel watermarking + the
  `/app/config` flag that turns it on/off is not implemented.
- The section-40 "future ready" items (messaging, supervisor dashboard, AI
  features, NFC, digital signatures, incident reporting) — deliberately
  not started, per the spec's own instruction not to activate them yet.
