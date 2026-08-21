import 'package:geolocator/geolocator.dart';

import '../../../core/api/mock/mock_fixtures.dart';
import '../domain/attendance_models.dart';
import 'attendance_repository.dart';

/// V23 Malawa Ria's simulated attendance geofence centre + radius, purely
/// for the demo build. A real deployment reads this from CPMSPro property
/// configuration server-side — the mobile client never holds the geofence
/// boundary itself, it only receives allow/deny back (section 20).
class MockAttendanceRepository implements AttendanceRepository {
  static const double _geofenceLat = 3.1390;
  static const double _geofenceLng = 101.6869;
  static const double _geofenceRadiusMeters = 250;

  @override
  Future<AttendanceStatus> fetchStatus() async {
    await Future.delayed(const Duration(milliseconds: 300));
    return MockFixtures.instance.attendanceStatus;
  }

  double _distanceMeters(double lat1, double lng1, double lat2, double lng2) =>
      Geolocator.distanceBetween(lat1, lng1, lat2, lng2);

  @override
  Future<GeofenceResult> clockIn(
      {required double lat, required double lng, double? accuracy}) async {
    await Future.delayed(const Duration(milliseconds: 900));
    final distance = _distanceMeters(lat, lng, _geofenceLat, _geofenceLng);
    final propertyName = MockFixtures.instance.branding.propertyName;

    if (distance > _geofenceRadiusMeters) {
      return GeofenceResult(
        allowed: false,
        message: 'You are outside the authorised property attendance area.',
        distanceMeters: distance,
      );
    }

    MockFixtures.instance.attendanceStatus = AttendanceStatus(
      status: ClockStatus.clockedIn,
      clockInTime: DateTime.now(),
      shiftLabel: '8:00 AM - 5:00 PM',
      isLate: DateTime.now().hour >= 8 && DateTime.now().minute > 15,
      propertyName: propertyName,
    );
    return GeofenceResult(
        allowed: true,
        message: 'You are within the $propertyName attendance area.',
        distanceMeters: distance);
  }

  @override
  Future<GeofenceResult> clockOut(
      {required double lat, required double lng, double? accuracy}) async {
    await Future.delayed(const Duration(milliseconds: 900));
    final current = MockFixtures.instance.attendanceStatus;
    final overtime = current.clockInTime != null &&
            DateTime.now().difference(current.clockInTime!).inHours >= 9
        ? DateTime.now().difference(current.clockInTime!).inMinutes - (9 * 60)
        : 0;
    MockFixtures.instance.attendanceStatus = AttendanceStatus(
      status: ClockStatus.clockedOut,
      clockInTime: current.clockInTime,
      clockOutTime: DateTime.now(),
      shiftLabel: current.shiftLabel,
      isLate: current.isLate,
      overtimeMinutes: overtime > 0 ? overtime : 0,
      propertyName: current.propertyName,
    );
    return const GeofenceResult(
        allowed: true, message: 'Clocked out successfully.');
  }

  @override
  Future<MonthlyAttendanceSummary> fetchHistory(
      {required int year, required int month}) async {
    await Future.delayed(const Duration(milliseconds: 400));
    final records = MockFixtures.instance.attendanceHistory
        .where((r) => r.date.year == year && r.date.month == month)
        .toList();
    final worked = records.where((r) => r.clockIn != null).toList();
    return MonthlyAttendanceSummary(
      daysWorked: worked.length,
      totalHours: worked.fold(0.0, (sum, r) => sum + r.hoursWorked),
      lateArrivals: worked.where((r) => r.isLate).length,
      overtimeHours: worked.fold(0.0, (sum, r) => sum + r.overtimeMinutes / 60),
      records: records,
    );
  }
}
