enum ClockStatus { clockedOut, clockedIn }

class AttendanceStatus {
  const AttendanceStatus({
    required this.status,
    this.clockInTime,
    this.clockOutTime,
    this.shiftLabel,
    this.isLate = false,
    this.overtimeMinutes = 0,
    required this.propertyName,
  });

  final ClockStatus status;
  final DateTime? clockInTime;
  final DateTime? clockOutTime;
  final String? shiftLabel;
  final bool isLate;
  final int overtimeMinutes;
  final String propertyName;

  Duration get workedDuration {
    if (clockInTime == null) return Duration.zero;
    final end = clockOutTime ?? DateTime.now();
    return end.difference(clockInTime!);
  }
}

class GeofenceResult {
  const GeofenceResult({
    required this.allowed,
    required this.message,
    this.distanceMeters,
    this.resultingStatus,
  });
  final bool allowed;
  final String message;
  final double? distanceMeters;

  /// The clock status CPMSPro actually confirms after this call — set on
  /// both success and on an `ALREADY_CLOCKED_IN`/`NOT_CLOCKED_IN`
  /// conflict, since either way the server just told the app the real
  /// current state. Null only when the request failed for an unrelated
  /// reason (network error, GPS rejected, etc.) and the previous known
  /// state should be left alone.
  final ClockStatus? resultingStatus;
}

class AttendanceRecord {
  const AttendanceRecord({
    required this.date,
    this.clockIn,
    this.clockOut,
    required this.hoursWorked,
    required this.isLate,
    required this.overtimeMinutes,
  });

  final DateTime date;
  final DateTime? clockIn;
  final DateTime? clockOut;
  final double hoursWorked;
  final bool isLate;
  final int overtimeMinutes;
}

class MonthlyAttendanceSummary {
  const MonthlyAttendanceSummary({
    required this.daysWorked,
    required this.totalHours,
    required this.lateArrivals,
    required this.overtimeHours,
    required this.records,
  });

  final int daysWorked;
  final double totalHours;
  final int lateArrivals;
  final double overtimeHours;
  final List<AttendanceRecord> records;
}
