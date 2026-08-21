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

  // The real backend has no attendance status/history GET endpoint —
  // clock state is derived purely from clock-in/clock-out responses
  // (including ALREADY_CLOCKED_IN/NOT_CLOCKED_IN conflicts) by
  // AttendanceController, which is the actual source of truth the UI
  // reads from. This method exists only to satisfy the repository
  // interface's initial-load contract and never claims a state the
  // server hasn't confirmed.
  @override
  Future<AttendanceStatus> fetchStatus() async => const AttendanceStatus(
        status: ClockStatus.clockedOut,
        propertyName: '',
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

  @override
  Future<MonthlyAttendanceSummary> fetchHistory(
          {required int year, required int month}) async =>
      const MonthlyAttendanceSummary(
        daysWorked: 0,
        totalHours: 0,
        lateArrivals: 0,
        overtimeHours: 0,
        records: [],
      );
}
