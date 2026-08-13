import '../domain/attendance_models.dart';

abstract class AttendanceRepository {
  Future<AttendanceStatus> fetchStatus();

  /// Sends the device GPS fix to CPMSPro; the SERVER decides whether the
  /// coordinates fall inside the property's configured geofence and
  /// whether clock-in is therefore allowed (section 20) — the client never
  /// makes that call itself, it only reports [GeofenceResult] back.
  Future<GeofenceResult> clockIn({required double lat, required double lng});
  Future<GeofenceResult> clockOut({required double lat, required double lng});

  Future<MonthlyAttendanceSummary> fetchHistory({required int year, required int month});
}
