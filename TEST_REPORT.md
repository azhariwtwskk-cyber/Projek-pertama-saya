# CPMSPro Full-System Audit — Test Report

Environment: sandboxed Linux session, no pre-installed Flutter/Android SDK — both installed
during this pass (Flutter 3.47.0 stable / Dart 3.13.0; Android SDK could not be installed, see
§5). PHP 8.4.19 CLI was already present.

## 1. `flutter pub get`

```
Got dependencies!
```
**Result: PASS.** Ran cleanly after removing the unused `firebase_core`/`firebase_messaging`
dependencies (AUDIT_REPORT M-6); `pubspec.lock` updated accordingly.

## 2. `dart format lib`

```
Formatted 96 files (0 changed) in 0.33 seconds.
```
**Result: PASS.** Run to convergence — an earlier pass reformatted 79 files (mostly line-ending
normalization from a bulk source sync plus real edits); this final run shows zero further changes
needed.

## 3. `flutter analyze`

```
Analyzing mobile...
No issues found! (ran in 3.2s)
```
**Result: PASS — 0 errors, 0 warnings, 0 info-level lints.** (An intermediate run surfaced 11
`curly_braces_in_flow_control_structures` info-level lints from pre-existing single-line `if`
statements in files this pass touched; all 11 were fixed rather than left as noise.)

## 4. `flutter test`

```
00:00 +0: loading /home/user/Projek-pertama-saya/mobile/test/auth_stage1_test.dart
... (21 tests)
00:02 +21: All tests passed!
```
**Result: PASS — 21/21 tests passed, 0 failed.**

Coverage: `test/auth_stage1_test.dart` (19 tests) exercises the `AuthController` state machine
(login, session restore, refresh rotation incl. single-flight and reuse-rejection, forced logout),
`StaffUser`/`AppBranding` parsing against the real backend response shape, and `ApiClient`'s
401→refresh→retry interceptor logic against a scripted HTTP adapter (no real network). This suite
was written before this pass and continues to pass unmodified in behavior (only whitespace
reformatted). `test/widget_test.dart` (1 test) verifies the app boots to the login screen when
unauthenticated.

No new automated tests were added for the Work Order History feature or the other fixes in this
pass, since the existing suite's pattern (a scripted Dio adapter) would need to be extended
per-repository and the highest-value remaining verification is a real device/emulator run against
a staging backend — noted as a follow-up in AUDIT_REPORT §6.

## 5. `flutter build apk --release` / `--debug`

**Result: NOT RUN — blocked by network policy, not by any code defect.**

This sandbox has no pre-installed Android SDK. The Android command-line tools are only
distributed from `dl.google.com`, and this session's outbound network policy returns
`403` (`gateway answered 403 to CONNECT (policy denial or upstream failure)`) for that host —
confirmed via the agent proxy's own status endpoint, which explicitly instructs: *"Do not retry or
route around it — report the blocked host."* Per that instruction, no workaround (alternate
mirror, VPN, etc.) was attempted.

Everything checkable **without** the Android SDK was checked and passed (`pub get`, `dart format`,
`flutter analyze`, `flutter test`, all above). The Gradle/Kotlin configuration itself
(`android/app/build.gradle.kts`, `android/build.gradle.kts`) was read and is unchanged by this
pass. This should be run in CI or on a machine with the Android SDK installed before shipping;
report the outcome back into this file at that time.

## 6. PHP syntax validation (`php -l`)

Ran across the **entire** `backend/` tree (612 `.php` files), not just modified files:

```
TOTAL_FILES=612
FAILED=1
```

- **Modified/created files — both clean:**
  - `backend/cpms/api/v1/staff/daily-work/list.php` → `No syntax errors detected`
  - `backend/cpms/api/v1/staff/work-history.php` (new) → `No syntax errors detected`
- **One pre-existing failure, unrelated to this pass:**
  `backend/cpms/property_portal/property_portal_v4_check.php:30` — invalid string interpolation
  syntax. Confirmed zero inbound references anywhere else in the repository (standalone diagnostic
  script). Not fixed — out of scope for the Staff mobile workflow this pass targeted; flagged in
  AUDIT_REPORT.md (L-2) rather than silently left unmentioned.

## Summary

| Check | Result |
|---|---|
| `flutter pub get` | ✅ PASS |
| `dart format .` | ✅ PASS (0 changes needed) |
| `flutter analyze` | ✅ PASS (0 issues) |
| `flutter test` | ✅ PASS (21/21) |
| `flutter build apk --release` | ⛔ Not run — Android SDK unavailable, `dl.google.com` blocked by session network policy |
| `php -l` (whole `backend/` tree, 612 files) | ✅ 611/612 clean; 1 pre-existing, unrelated, unfixed failure documented |
