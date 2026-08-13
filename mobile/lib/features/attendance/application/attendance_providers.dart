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

final locationServiceProvider = Provider<LocationService>((ref) => const LocationService());

final attendanceStatusProvider = FutureProvider.autoDispose<AttendanceStatus>((ref) {
  return ref.watch(attendanceRepositoryProvider).fetchStatus();
});

final attendanceHistoryProvider = FutureProvider.autoDispose.family<MonthlyAttendanceSummary, ({int year, int month})>((ref, key) {
  return ref.watch(attendanceRepositoryProvider).fetchHistory(year: key.year, month: key.month);
});

class AttendanceController {
  AttendanceController(this._ref);
  final Ref _ref;

  Future<GeofenceResult> clockIn() async {
    final position = await _ref.read(locationServiceProvider).getCurrentPosition();
    final result = await _ref.read(attendanceRepositoryProvider).clockIn(lat: position.latitude, lng: position.longitude);
    _ref.invalidate(attendanceStatusProvider);
    return result;
  }

  Future<GeofenceResult> clockOut() async {
    final position = await _ref.read(locationServiceProvider).getCurrentPosition();
    final result = await _ref.read(attendanceRepositoryProvider).clockOut(lat: position.latitude, lng: position.longitude);
    _ref.invalidate(attendanceStatusProvider);
    return result;
  }
}

final attendanceControllerProvider = Provider((ref) => AttendanceController(ref));
