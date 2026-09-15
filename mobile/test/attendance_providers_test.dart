// Phase M2A (Attendance Reliability Fix) coverage for the two provider-level
// findings from the M2 forensic audit:
//  - F1: the local/optimistic status overlay must preserve server-fetched
//    isLate/overtimeMinutes rather than silently defaulting them to
//    false/0 once any clock action has happened this session.
//  - F3: a successful (or recovered-via-conflict) Clock In/Out must
//    invalidate the CURRENT month's attendanceHistoryProvider so the "This
//    Month" preview doesn't keep showing pre-action totals — but must not
//    touch it on an outright rejection where nothing changed server-side.
//
// Uses a fake AttendanceRepository/LocationService (no real network, no
// platform channels) so these are pure, fast provider-logic tests.

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';

import 'package:cpmspro_workforce/features/attendance/application/attendance_providers.dart';
import 'package:cpmspro_workforce/features/attendance/data/attendance_repository.dart';
import 'package:cpmspro_workforce/features/attendance/domain/attendance_models.dart';
import 'package:cpmspro_workforce/core/location/location_service.dart';

class _FakeLocationService implements LocationService {
  const _FakeLocationService();

  @override
  Future<Position> getCurrentPosition() async => Position(
        latitude: 3.1390,
        longitude: 101.6869,
        timestamp: DateTime(2026, 9, 15),
        accuracy: 10,
        altitude: 0,
        altitudeAccuracy: 0,
        heading: 0,
        headingAccuracy: 0,
        speed: 0,
        speedAccuracy: 0,
      );
}

class _FakeAttendanceRepository implements AttendanceRepository {
  AttendanceStatus statusToReturn = const AttendanceStatus(
    status: ClockStatus.clockedOut,
    propertyName: 'Property A',
  );
  GeofenceResult clockResultToReturn = const GeofenceResult(
    allowed: true,
    message: 'ok',
    resultingStatus: ClockStatus.clockedIn,
  );

  int fetchHistoryCallCount = 0;
  final List<({int year, int month})> fetchHistoryCalls = [];

  @override
  Future<AttendanceStatus> fetchStatus() async => statusToReturn;

  @override
  Future<GeofenceResult> clockIn(
          {required double lat, required double lng, double? accuracy}) async =>
      clockResultToReturn;

  @override
  Future<GeofenceResult> clockOut(
          {required double lat, required double lng, double? accuracy}) async =>
      clockResultToReturn;

  @override
  Future<MonthlyAttendanceSummary> fetchHistory(
      {required int year, required int month}) async {
    fetchHistoryCallCount++;
    fetchHistoryCalls.add((year: year, month: month));
    return const MonthlyAttendanceSummary(
      daysWorked: 0,
      totalHours: 0,
      lateArrivals: 0,
      overtimeHours: 0,
      records: [],
    );
  }
}

void main() {
  late _FakeAttendanceRepository fakeRepo;
  late ProviderContainer container;

  setUp(() {
    fakeRepo = _FakeAttendanceRepository();
    container = ProviderContainer(overrides: [
      attendanceRepositoryProvider.overrideWithValue(fakeRepo),
      locationServiceProvider.overrideWithValue(const _FakeLocationService()),
    ]);
    addTearDown(container.dispose);
  });

  group('F1 — local status overlay preserves server-fetched fields', () {
    test('preserves isLate=true from the server after a successful clock-in',
        () async {
      fakeRepo.statusToReturn = const AttendanceStatus(
        status: ClockStatus.clockedOut,
        propertyName: 'Property A',
        isLate: true,
        overtimeMinutes: 0,
      );
      fakeRepo.clockResultToReturn = const GeofenceResult(
        allowed: true,
        message: 'Clock in successful.',
        resultingStatus: ClockStatus.clockedIn,
      );

      await container.read(attendanceControllerProvider).clockIn();
      final status = await container.read(attendanceStatusProvider.future);

      expect(status.status, ClockStatus.clockedIn);
      expect(status.isLate, isTrue,
          reason: 'the overlay must carry the server\'s isLate through, '
              'not silently reset it to the AttendanceStatus default');
    });

    test('preserves overtimeMinutes from the server after a successful '
        'clock-in', () async {
      fakeRepo.statusToReturn = const AttendanceStatus(
        status: ClockStatus.clockedOut,
        propertyName: 'Property A',
        isLate: false,
        overtimeMinutes: 45,
      );
      fakeRepo.clockResultToReturn = const GeofenceResult(
        allowed: true,
        message: 'Clock in successful.',
        resultingStatus: ClockStatus.clockedIn,
      );

      await container.read(attendanceControllerProvider).clockIn();
      final status = await container.read(attendanceStatusProvider.future);

      expect(status.overtimeMinutes, 45);
    });
  });

  group('F3 — clock action invalidates the current-month history provider',
      () {
    test('Clock In invalidates the current month\'s attendanceHistoryProvider',
        () async {
      final now = DateTime.now();
      final key = (year: now.year, month: now.month);

      await container.read(attendanceHistoryProvider(key).future);
      expect(fakeRepo.fetchHistoryCallCount, 1);

      fakeRepo.clockResultToReturn = const GeofenceResult(
        allowed: true,
        message: 'Clock in successful.',
        resultingStatus: ClockStatus.clockedIn,
      );
      await container.read(attendanceControllerProvider).clockIn();

      await container.read(attendanceHistoryProvider(key).future);
      expect(fakeRepo.fetchHistoryCallCount, 2,
          reason: 'a successful clock-in must invalidate (force a refetch '
              'of) the current month\'s history, not leave it cached');
      expect(fakeRepo.fetchHistoryCalls.last, key);
    });

    test('Clock Out invalidates the current month\'s attendanceHistoryProvider',
        () async {
      final now = DateTime.now();
      final key = (year: now.year, month: now.month);

      await container.read(attendanceHistoryProvider(key).future);
      expect(fakeRepo.fetchHistoryCallCount, 1);

      fakeRepo.clockResultToReturn = const GeofenceResult(
        allowed: true,
        message: 'Clock out successful.',
        resultingStatus: ClockStatus.clockedOut,
      );
      await container.read(attendanceControllerProvider).clockOut();

      await container.read(attendanceHistoryProvider(key).future);
      expect(fakeRepo.fetchHistoryCallCount, 2);
    });

    test(
        'a recovered ALREADY_CLOCKED_IN/NOT_CLOCKED_IN conflict (still sets '
        'resultingStatus) also invalidates history', () async {
      final now = DateTime.now();
      final key = (year: now.year, month: now.month);

      await container.read(attendanceHistoryProvider(key).future);
      expect(fakeRepo.fetchHistoryCallCount, 1);

      // Mirrors what ApiAttendanceRepository._clock() returns for a real
      // ALREADY_CLOCKED_IN 409: allowed:false, but resultingStatus set,
      // since the server just confirmed the real current state.
      fakeRepo.clockResultToReturn = const GeofenceResult(
        allowed: false,
        message: 'You are already clocked in.',
        resultingStatus: ClockStatus.clockedIn,
      );
      await container.read(attendanceControllerProvider).clockIn();

      await container.read(attendanceHistoryProvider(key).future);
      expect(fakeRepo.fetchHistoryCallCount, 2);
    });

    test('an outright rejection (resultingStatus null) does NOT invalidate '
        'history — no unnecessary network request', () async {
      final now = DateTime.now();
      final key = (year: now.year, month: now.month);

      await container.read(attendanceHistoryProvider(key).future);
      expect(fakeRepo.fetchHistoryCallCount, 1);

      // Mirrors OUTSIDE_GEOFENCE/POOR_GPS_ACCURACY/etc: nothing changed
      // server-side, so resultingStatus stays null.
      fakeRepo.clockResultToReturn = const GeofenceResult(
        allowed: false,
        message: 'You are outside the work location.',
      );
      await container.read(attendanceControllerProvider).clockIn();

      await container.read(attendanceHistoryProvider(key).future);
      expect(fakeRepo.fetchHistoryCallCount, 1,
          reason: 'an outright rejection must not trigger a redundant '
              'history refetch');
    });
  });
}
