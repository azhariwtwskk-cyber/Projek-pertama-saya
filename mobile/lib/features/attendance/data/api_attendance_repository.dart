import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/api/api_exception.dart';
import '../domain/attendance_models.dart';
import 'attendance_repository.dart';

/// Talks to the real, single `attendance/clock.php` endpoint — one URL,
/// discriminated by `action: 'clock_in' | 'clock_out'` in the body, never
/// separate clock-in/clock-out paths (see
/// mobile/docs/INTEGRATION_REPAIR_REPORT.md).
class ApiAttendanceRepository implements AttendanceRepository {
  ApiAttendanceRepository(this._client);
  final ApiClient _client;

  // Phase M1: `GET attendance/status.php` is a real, working backend
  // endpoint (confirmed live — see the CPMSPro Mobile Attendance History
  // Forensic Audit); this is the authoritative persisted clock state,
  // read on every screen load/refresh. AttendanceController's
  // `_localClockStatusProvider` overlay still exists on top of this for
  // the immediate, optimistic update right after a clock-in/out response
  // — this fetch is what makes that state survive a cold restart instead
  // of resetting to "not clocked in" once the in-memory overlay is gone.
  @override
  Future<AttendanceStatus> fetchStatus() => _client.request(
        (dio) => dio.get(ApiEndpoints.attendanceStatus),
        (data) {
          final json = data is Map
              ? Map<String, dynamic>.from(data)
              : <String, dynamic>{};
          return AttendanceStatus.fromJson(json);
        },
      );

  @override
  Future<GeofenceResult> clockIn(
          {required double lat, required double lng, double? accuracy}) =>
      _clock('clock_in', lat, lng, accuracy);

  @override
  Future<GeofenceResult> clockOut(
          {required double lat, required double lng, double? accuracy}) =>
      _clock('clock_out', lat, lng, accuracy);

  Future<GeofenceResult> _clock(
      String action, double lat, double lng, double? accuracy) async {
    try {
      return await _client.request(
        (dio) => dio.post(ApiEndpoints.attendanceClock, data: {
          'action': action,
          'latitude': lat,
          'longitude': lng,
          // Real device accuracy in metres — the backend compares this
          // against the property geofence's `maximum_accuracy_m` and
          // rejects with POOR_GPS_ACCURACY if it's worse. A hard-coded
          // placeholder here would make that check meaningless.
          'accuracy': accuracy ?? 9999.0,
        }),
        (data) {
          final json = data is Map
              ? Map<String, dynamic>.from(data)
              : <String, dynamic>{};
          final accepted = json['accepted'] == true;
          final state = (json['attendance_state'] as String?) == 'in'
              ? ClockStatus.clockedIn
              : ClockStatus.clockedOut;
          return GeofenceResult(
            allowed: accepted,
            message: accepted
                ? (action == 'clock_in'
                    ? 'Clock in successful.'
                    : 'Clock out successful.')
                : 'Attendance request was not accepted.',
            distanceMeters: (json['distance_m'] as num?)?.toDouble(),
            resultingStatus: accepted ? state : null,
          );
        },
      );
    } on ApiException catch (e) {
      // ALREADY_CLOCKED_IN / NOT_CLOCKED_IN are expected 409s that tell
      // the app the real current state — surface them as a clear,
      // specific message and correct the locally-known clock status
      // instead of a generic error.
      if (e.code == 'ALREADY_CLOCKED_IN') {
        return const GeofenceResult(
          allowed: false,
          message: 'You are already clocked in.',
          resultingStatus: ClockStatus.clockedIn,
        );
      }
      if (e.code == 'NOT_CLOCKED_IN') {
        return const GeofenceResult(
          allowed: false,
          message: 'You are not currently clocked in.',
          resultingStatus: ClockStatus.clockedOut,
        );
      }
      if (e.code == 'OUTSIDE_GEOFENCE') {
        return const GeofenceResult(
            allowed: false,
            message:
                'You are outside the work location. Move closer and try again.');
      }
      if (e.code == 'POOR_GPS_ACCURACY') {
        return const GeofenceResult(
            allowed: false,
            message:
                'GPS accuracy is too low. Move to an open area and try again.');
      }
      if (e.code == 'GEOFENCE_NOT_CONFIGURED') {
        return const GeofenceResult(
            allowed: false,
            message:
                'This property has no work location configured yet. Contact your Property Admin.');
      }
      // Any other server-provided message (already in the user's
      // language) is still better than a generic fallback.
      return GeofenceResult(allowed: false, message: e.message);
    }
  }

  // Phase M1: `GET attendance/history.php` is a real, working backend
  // endpoint — `year`/`month` are sent exactly as the caller passes them
  // (Dart's DateTime.month is already 1-based, matching the backend's
  // 1-based month clamp; no adjustment applied). Deliberately no
  // try/catch here: an HTTP failure or a malformed response must
  // propagate as a thrown exception to the caller (surfacing the
  // existing error/retry UI state) rather than being swallowed into a
  // false "no records this month" empty summary.
  @override
  Future<MonthlyAttendanceSummary> fetchHistory(
          {required int year, required int month}) =>
      _client.request(
        (dio) => dio.get(
          ApiEndpoints.attendanceHistory,
          queryParameters: {'year': year, 'month': month},
        ),
        (data) {
          final json = data is Map
              ? Map<String, dynamic>.from(data)
              : <String, dynamic>{};
          return MonthlyAttendanceSummary.fromJson(json);
        },
      );
}
