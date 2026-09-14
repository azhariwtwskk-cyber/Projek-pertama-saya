enum ClockStatus { clockedOut, clockedIn }

/// Safe numeric coercion for backend JSON: PHP's `round()` serializes a
/// whole-number result as a JSON integer (e.g. `18`, not `18.0`) and a
/// fractional one as a JSON double — both must parse into a Dart double.
double _asDouble(dynamic value, [double fallback = 0]) {
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? fallback;
  return fallback;
}

int _asInt(dynamic value, [int fallback = 0]) {
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value) ?? fallback;
  return fallback;
}

/// `history.php`/`status.php` return `clock_in`/`clock_out`/`clock_in_at`
/// as `"YYYY-MM-DD HH:MM:SS"` (space-separated, no timezone) or `null`.
/// `DateTime.tryParse` accepts that space-separated form directly, so no
/// custom format string is needed — this just adds the null/blank/garbage
/// safety `tryParse` alone doesn't give a `String?` source value.
DateTime? _tryParseDateTime(dynamic value) {
  if (value == null) return null;
  final text = value.toString().trim();
  if (text.isEmpty) return null;
  return DateTime.tryParse(text);
}

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

  /// Parses the real `GET attendance/status.php` response:
  /// `{attendance_state:"in"|"out", clock_in_at, shift_label, property_name}`.
  /// The backend doesn't return `is_late`/`overtime_minutes` for the
  /// current open session, so those keep their constructor defaults.
  factory AttendanceStatus.fromJson(Map<String, dynamic> json) {
    final state = (json['attendance_state'] as String?)?.trim().toLowerCase();
    return AttendanceStatus(
      status: state == 'in' ? ClockStatus.clockedIn : ClockStatus.clockedOut,
      clockInTime: _tryParseDateTime(json['clock_in_at']),
      shiftLabel: json['shift_label'] as String?,
      propertyName: (json['property_name'] as String?) ?? '',
    );
  }

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

  /// Parses one `history.php` `records[]` entry. `date` is a real, always-
  /// present `NOT NULL` DB column server-side, so it's parsed directly
  /// (a genuinely malformed/missing date throws, same as any other
  /// structurally-broken response — see [MonthlyAttendanceSummary.fromJson]).
  /// `clock_in`/`clock_out` are legitimately null for an open session and
  /// are parsed defensively; `hours_worked` safely accepts either a JSON
  /// int or double.
  factory AttendanceRecord.fromJson(Map<String, dynamic> json) {
    return AttendanceRecord(
      date: DateTime.parse(json['date'] as String),
      clockIn: _tryParseDateTime(json['clock_in']),
      clockOut: _tryParseDateTime(json['clock_out']),
      hoursWorked: _asDouble(json['hours_worked']),
      isLate: json['is_late'] == true,
      overtimeMinutes: _asInt(json['overtime_minutes']),
    );
  }

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

  /// Parses the real `GET attendance/history.php?year=&month=` response:
  /// `{summary:{days_worked,total_hours,late_arrivals,overtime_hours},
  /// records:[...]}`. `records` must actually be present as a list — a
  /// legitimately empty month is `records: []` (the backend always
  /// includes the key), so a missing/wrong-typed `records` throws instead
  /// of silently becoming an empty summary, per the "never swallow a
  /// malformed response into empty history" requirement. Individual
  /// `summary` numbers are coerced safely since PHP's int/double JSON
  /// encoding for a whole-number result can vary.
  factory MonthlyAttendanceSummary.fromJson(Map<String, dynamic> json) {
    final summary =
        (json['summary'] as Map?)?.cast<String, dynamic>() ?? const {};
    final recordsJson = json['records'] as List;
    return MonthlyAttendanceSummary(
      daysWorked: _asInt(summary['days_worked']),
      totalHours: _asDouble(summary['total_hours']),
      lateArrivals: _asInt(summary['late_arrivals']),
      overtimeHours: _asDouble(summary['overtime_hours']),
      records: recordsJson
          .map((r) =>
              AttendanceRecord.fromJson((r as Map).cast<String, dynamic>()))
          .toList(),
    );
  }

  final int daysWorked;
  final double totalHours;
  final int lateArrivals;
  final double overtimeHours;
  final List<AttendanceRecord> records;
}
