import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../domain/attendance_models.dart';
import 'attendance_repository.dart';

class ApiAttendanceRepository implements AttendanceRepository {
  ApiAttendanceRepository(this._client);
  final ApiClient _client;

  AttendanceStatus _statusFromJson(Map<String, dynamic> json) => AttendanceStatus(
        status: json['status'] == 'clocked_in' ? ClockStatus.clockedIn : ClockStatus.clockedOut,
        clockInTime: json['clock_in_time'] == null ? null : DateTime.parse(json['clock_in_time'] as String),
        clockOutTime: json['clock_out_time'] == null ? null : DateTime.parse(json['clock_out_time'] as String),
        shiftLabel: json['shift_label'] as String?,
        isLate: json['is_late'] as bool? ?? false,
        overtimeMinutes: json['overtime_minutes'] as int? ?? 0,
        propertyName: json['property_name'] as String? ?? '',
      );

  @override
  Future<AttendanceStatus> fetchStatus() {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.attendanceHistory, queryParameters: {'today': true}),
      (data) => _statusFromJson(data as Map<String, dynamic>),
    );
  }

  @override
  Future<GeofenceResult> clockIn({required double lat, required double lng}) {
    return _client.request(
      (dio) => dio.post(ApiEndpoints.attendanceClockIn, data: {'lat': lat, 'lng': lng}),
      (data) {
        final json = data as Map<String, dynamic>;
        return GeofenceResult(
          allowed: json['allowed'] as bool? ?? false,
          message: json['message'] as String? ?? '',
          distanceMeters: (json['distance_meters'] as num?)?.toDouble(),
        );
      },
    );
  }

  @override
  Future<GeofenceResult> clockOut({required double lat, required double lng}) {
    return _client.request(
      (dio) => dio.post(ApiEndpoints.attendanceClockOut, data: {'lat': lat, 'lng': lng}),
      (data) {
        final json = data as Map<String, dynamic>;
        return GeofenceResult(allowed: json['allowed'] as bool? ?? true, message: json['message'] as String? ?? '');
      },
    );
  }

  @override
  Future<MonthlyAttendanceSummary> fetchHistory({required int year, required int month}) {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.attendanceHistory, queryParameters: {'year': year, 'month': month}),
      (data) {
        final json = data as Map<String, dynamic>;
        final records = (json['records'] as List<dynamic>)
            .map((e) => AttendanceRecord(
                  date: DateTime.parse(e['date'] as String),
                  clockIn: e['clock_in'] == null ? null : DateTime.parse(e['clock_in'] as String),
                  clockOut: e['clock_out'] == null ? null : DateTime.parse(e['clock_out'] as String),
                  hoursWorked: (e['hours_worked'] as num?)?.toDouble() ?? 0,
                  isLate: e['is_late'] as bool? ?? false,
                  overtimeMinutes: e['overtime_minutes'] as int? ?? 0,
                ))
            .toList();
        return MonthlyAttendanceSummary(
          daysWorked: json['days_worked'] as int? ?? 0,
          totalHours: (json['total_hours'] as num?)?.toDouble() ?? 0,
          lateArrivals: json['late_arrivals'] as int? ?? 0,
          overtimeHours: (json['overtime_hours'] as num?)?.toDouble() ?? 0,
          records: records,
        );
      },
    );
  }
}
