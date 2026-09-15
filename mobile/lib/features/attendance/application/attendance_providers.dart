import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../../core/location/location_service.dart';
import '../../auth/application/auth_providers.dart';
import '../data/api_attendance_repository.dart';
import '../data/attendance_repository.dart';
import '../data/mock_attendance_repository.dart';
import '../domain/attendance_models.dart';

final attendanceRepositoryProvider = Provider<AttendanceRepository>((ref) {
  if (AppConfig.useMockApi) return MockAttendanceRepository();
  return ApiAttendanceRepository(ref.watch(apiClientProvider));
});

final locationServiceProvider =
    Provider<LocationService>((ref) => const LocationService());

/// The real backend has no attendance status/history GET endpoint (see
/// mobile/docs/INTEGRATION_REPAIR_REPORT.md), so the app cannot ask
/// "am I clocked in?" on cold start — it can only learn the answer from
/// a clock-in/clock-out response (including an `ALREADY_CLOCKED_IN` /
/// `NOT_CLOCKED_IN` 409, which is the server confirming the real current
/// state just as much as a 200 would). This holds that last-confirmed
/// state for the session; it starts `null` (unknown) rather than
/// guessing "clocked out", so the UI can show a neutral state until the
/// first clock action confirms one way or the other.
final _localClockStatusProvider = StateProvider<ClockStatus?>((ref) => null);

final attendanceStatusProvider =
    FutureProvider.autoDispose<AttendanceStatus>((ref) async {
  final local = ref.watch(_localClockStatusProvider);
  final fallback = await ref.watch(attendanceRepositoryProvider).fetchStatus();
  if (local == null) return fallback;
  return AttendanceStatus(
    status: local,
    clockInTime: local == ClockStatus.clockedIn
        ? (fallback.clockInTime ?? DateTime.now())
        : null,
    shiftLabel: fallback.shiftLabel,
    // The local overlay only ever overrides `status`/`clockInTime` — it
    // has no opinion on lateness/overtime, so the server's own fetched
    // values must be carried through here rather than silently falling
    // back to AttendanceStatus's false/0 defaults (Phase M2 finding F1).
    isLate: fallback.isLate,
    overtimeMinutes: fallback.overtimeMinutes,
    propertyName: fallback.propertyName,
  );
});

final attendanceHistoryProvider = FutureProvider.autoDispose
    .family<MonthlyAttendanceSummary, ({int year, int month})>((ref, key) {
  return ref
      .watch(attendanceRepositoryProvider)
      .fetchHistory(year: key.year, month: key.month);
});

class AttendanceController {
  AttendanceController(this._ref);
  final Ref _ref;

  Future<GeofenceResult> clockIn() => _clock(isClockIn: true);
  Future<GeofenceResult> clockOut() => _clock(isClockIn: false);

  Future<GeofenceResult> _clock({required bool isClockIn}) async {
    final position =
        await _ref.read(locationServiceProvider).getCurrentPosition();
    final repo = _ref.read(attendanceRepositoryProvider);
    final result = isClockIn
        ? await repo.clockIn(
            lat: position.latitude,
            lng: position.longitude,
            accuracy: position.accuracy)
        : await repo.clockOut(
            lat: position.latitude,
            lng: position.longitude,
            accuracy: position.accuracy);
    if (result.resultingStatus != null) {
      _ref.read(_localClockStatusProvider.notifier).state =
          result.resultingStatus;
      // Phase M2 finding F3: a successful clock action (or a recovered
      // ALREADY_CLOCKED_IN/NOT_CLOCKED_IN conflict — both set
      // resultingStatus too, since either way the server just confirmed
      // the real state) changes this month's attendance data, so the
      // "This Month" preview must not keep showing pre-action totals.
      // Scoped to the current month only — never invalidate historical
      // months, and skip this entirely on an outright rejection (GPS/
      // geofence/etc.) where resultingStatus stays null and nothing
      // actually changed server-side.
      final now = DateTime.now();
      _ref.invalidate(
          attendanceHistoryProvider((year: now.year, month: now.month)));
    }
    _ref.invalidate(attendanceStatusProvider);
    return result;
  }
}

final attendanceControllerProvider =
    Provider((ref) => AttendanceController(ref));
