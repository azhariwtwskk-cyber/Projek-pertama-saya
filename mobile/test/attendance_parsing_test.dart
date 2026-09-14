import 'package:flutter_test/flutter_test.dart';

import 'package:cpmspro_workforce/features/attendance/domain/attendance_models.dart';

/// Phase M1 (Attendance History & Server Status Integration) regression
/// coverage: `history.php`/`status.php` were confirmed live and working
/// during the forensic audit — the bug was that the Flutter repository
/// never called them at all. These tests pin `fromJson` against the real
/// response shapes captured from that audit, including the int/double and
/// nullable-field quirks actually observed live.
void main() {
  group('MonthlyAttendanceSummary.fromJson — history.php', () {
    test(
        'TEST 1: September response with one completed record parses '
        'daysWorked/totalHours and the record correctly', () {
      final summary = MonthlyAttendanceSummary.fromJson({
        'summary': {
          'days_worked': 1,
          'total_hours': 0.03,
          'late_arrivals': 0,
          'overtime_hours': 0,
        },
        'records': [
          {
            'date': '2026-09-14',
            'clock_in': '2026-09-14 12:09:00',
            'clock_out': '2026-09-14 12:11:00',
            'hours_worked': 0.03,
            'is_late': false,
            'overtime_minutes': 0,
          },
        ],
      });

      expect(summary.daysWorked, 1);
      expect(summary.totalHours, 0.03);
      expect(summary.lateArrivals, 0);
      expect(summary.overtimeHours, 0);
      expect(summary.records, hasLength(1));
      final record = summary.records.single;
      expect(record.date, DateTime.parse('2026-09-14'));
      expect(record.clockIn, DateTime.parse('2026-09-14 12:09:00'));
      expect(record.clockOut, DateTime.parse('2026-09-14 12:11:00'));
      expect(record.hoursWorked, 0.03);
      expect(record.isLate, isFalse);
      expect(record.overtimeMinutes, 0);
    });

    test('TEST 2: multiple sessions on the same date both parse', () {
      final summary = MonthlyAttendanceSummary.fromJson({
        'summary': {
          'days_worked': 1,
          'total_hours': 0.05,
          'late_arrivals': 0,
          'overtime_hours': 0,
        },
        'records': [
          {
            'date': '2026-09-14',
            'clock_in': '2026-09-14 12:12:00',
            'clock_out': '2026-09-14 12:13:00',
            'hours_worked': 0.02,
            'is_late': false,
            'overtime_minutes': 0,
          },
          {
            'date': '2026-09-14',
            'clock_in': '2026-09-14 12:09:00',
            'clock_out': '2026-09-14 12:11:00',
            'hours_worked': 0.03,
            'is_late': false,
            'overtime_minutes': 0,
          },
        ],
      });

      expect(summary.records, hasLength(2));
      expect(summary.records[0].clockIn, DateTime.parse('2026-09-14 12:12:00'));
      expect(summary.records[1].clockIn, DateTime.parse('2026-09-14 12:09:00'));
      // days_worked is keyed by distinct work_date server-side, so two
      // sessions on one date still report a single worked day.
      expect(summary.daysWorked, 1);
    });

    test(
        'TEST 3: a record with null clock_out does not crash the parser '
        '(still-open/overnight session)', () {
      final summary = MonthlyAttendanceSummary.fromJson({
        'summary': {
          'days_worked': 1,
          'total_hours': 0,
          'late_arrivals': 0,
          'overtime_hours': 0,
        },
        'records': [
          {
            'date': '2026-09-14',
            'clock_in': '2026-09-14 09:00:00',
            'clock_out': null,
            'hours_worked': 0,
            'is_late': false,
            'overtime_minutes': 0,
          },
        ],
      });

      final record = summary.records.single;
      expect(record.clockIn, isNotNull);
      expect(record.clockOut, isNull);
    });

    test(
        'TEST 4: integer JSON values where Dart expects double are safely '
        'converted (PHP round() serializes whole numbers as ints)', () {
      final summary = MonthlyAttendanceSummary.fromJson({
        // Observed live: an 18-hour session serialized "hours_worked":18
        // (a bare JSON integer), not 18.0.
        'summary': {
          'days_worked': 2,
          'total_hours': 18,
          'late_arrivals': 0,
          'overtime_hours': 0,
        },
        'records': [
          {
            'date': '2026-09-13',
            'clock_in': '2026-09-13 16:28:27',
            'clock_out': '2026-09-14 10:28:27',
            'hours_worked': 18,
            'is_late': false,
            'overtime_minutes': 0,
          },
        ],
      });

      expect(summary.totalHours, isA<double>());
      expect(summary.totalHours, 18.0);
      expect(summary.records.single.hoursWorked, isA<double>());
      expect(summary.records.single.hoursWorked, 18.0);
    });

    test(
        'TEST 7: an empty month (summary all zero, records: []) parses to '
        'an empty, non-crashing summary', () {
      final summary = MonthlyAttendanceSummary.fromJson({
        'summary': {
          'days_worked': 0,
          'total_hours': 0,
          'late_arrivals': 0,
          'overtime_hours': 0,
        },
        'records': <dynamic>[],
      });

      expect(summary.daysWorked, 0);
      expect(summary.totalHours, 0);
      expect(summary.lateArrivals, 0);
      expect(summary.overtimeHours, 0);
      expect(summary.records, isEmpty);
    });

    test(
        'a response missing the records key entirely throws rather than '
        'silently parsing as an empty summary', () {
      expect(
        () => MonthlyAttendanceSummary.fromJson({
          'summary': {
            'days_worked': 0,
            'total_hours': 0,
            'late_arrivals': 0,
            'overtime_hours': 0,
          },
        }),
        throwsA(anything),
      );
    });
  });

  group('AttendanceStatus.fromJson — status.php', () {
    test('TEST 5: CLOCKED_OUT response parses the correct state', () {
      final status = AttendanceStatus.fromJson({
        'attendance_state': 'out',
        'clock_in_at': null,
        'shift_label': 'Tiada syif ditetapkan',
        'property_name': 'Property A',
      });

      expect(status.status, ClockStatus.clockedOut);
      expect(status.clockInTime, isNull);
      expect(status.propertyName, 'Property A');
    });

    test(
        'TEST 6: CLOCKED_IN response parses the clockInTime and status '
        'correctly', () {
      final status = AttendanceStatus.fromJson({
        'attendance_state': 'in',
        'clock_in_at': '2026-09-14 12:09:00',
        'shift_label': '8:00 AM - 5:00 PM',
        'property_name': 'Property A',
      });

      expect(status.status, ClockStatus.clockedIn);
      expect(status.clockInTime, DateTime.parse('2026-09-14 12:09:00'));
      expect(status.shiftLabel, '8:00 AM - 5:00 PM');
      expect(status.propertyName, 'Property A');
    });
  });
}
