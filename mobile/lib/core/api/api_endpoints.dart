import '../config/app_config.dart';

/// Mirrors the REST contract in the CPMSPro Staff Mobile App spec (section
/// 32). Keeping every path in one place means a backend change is a
/// one-line diff, and it documents exactly what the mobile app expects
/// from the existing CPMSPro platform.
///
/// Stage 1 (see mobile/docs/BACKEND_INTEGRATION_AUDIT.md): the auth/profile
/// paths below have been corrected to match the real backend, which is
/// deployed at `<host>/cpms/api/v1/...php` — not the `/api/v1/...`
/// (no `cpms/` prefix, no `.php`) paths this file originally assumed.
/// Every other endpoint below is still the pre-Stage-1 placeholder path
/// and has NOT been verified or corrected yet; that happens stage by
/// stage as each feature is migrated (see the audit's staged plan).
class ApiEndpoints {
  const ApiEndpoints._();

  static const String _v = AppConfig.apiVersion;

  static const String login = '/cpms/api/$_v/auth/login.php';
  static const String logout = '/cpms/api/$_v/auth/logout.php';
  static const String refreshToken = '/cpms/api/$_v/auth/refresh.php';

  static const String staffProfile = '/cpms/api/$_v/me.php';
  static const String staffDashboard = '/api/$_v/staff/dashboard';
  static const String staffTasks = '/api/$_v/staff/tasks';
  static String staffTask(String id) => '/api/$_v/staff/tasks/$id';
  static String staffTaskAccept(String id) => '/api/$_v/staff/tasks/$id/accept';
  static String staffTaskStart(String id) => '/api/$_v/staff/tasks/$id/start';
  static String staffTaskEvidence(String id) => '/api/$_v/staff/tasks/$id/evidence';
  static String staffTaskComplete(String id) => '/api/$_v/staff/tasks/$id/complete';

  static const String dailyWork = '/api/$_v/staff/daily-work';

  static const String attendanceClockIn = '/api/$_v/attendance/clock-in';
  static const String attendanceClockOut = '/api/$_v/attendance/clock-out';
  static const String attendanceHistory = '/api/$_v/attendance/history';

  static const String pmTasks = '/api/$_v/pm/tasks';
  static String pmTask(String id) => '/api/$_v/pm/tasks/$id';
  static String pmTaskComplete(String id) => '/api/$_v/pm/tasks/$id/complete';

  static String asset(String id) => '/api/$_v/assets/$id';

  static const String notifications = '/api/$_v/notifications';
  static String notificationRead(String id) => '/api/$_v/notifications/$id/read';
  static const String notificationDeviceRegister = '/api/$_v/notifications/devices';

  static const String appConfig = '/api/$_v/app/config';
}
