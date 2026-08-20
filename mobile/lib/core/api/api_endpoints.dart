import '../config/app_config.dart';

class ApiEndpoints {
  const ApiEndpoints._();
  static const String _v = AppConfig.apiVersion;

  static const String login = '/cpms/api/$_v/auth/login.php';
  static const String logout = '/cpms/api/$_v/auth/logout.php';
  static const String refreshToken = '/cpms/api/$_v/auth/refresh.php';
  static const String staffProfile = '/cpms/api/$_v/me.php';
  static const String staffDashboard = '/cpms/api/$_v/dashboard.php';

  static const String staffTasks = '/cpms/api/$_v/staff/tasks.php';
  static String staffTaskEvidence(String id) =>
      '/cpms/api/$_v/staff/task-photo.php';
  // The real backend has no by-ID detail endpoint and no discrete
  // accept/start/complete endpoints for work orders — completion happens
  // by submitting a Daily Work log (dailyWorkSubmit) linked via
  // work_order_id (see mobile/docs/INTEGRATION_REPAIR_REPORT.md).
  static const String staffWorkHistory = '/cpms/api/$_v/staff/work-history.php';

  static const String dailyWorkList = '/cpms/api/$_v/staff/daily-work/list.php';
  static const String dailyWorkOptions =
      '/cpms/api/$_v/staff/daily-work/options.php';
  static const String dailyWorkSubmit =
      '/cpms/api/$_v/staff/daily-work/submit.php';
  static const String dailyWork = dailyWorkList;

  static const String attendanceClock = '/cpms/api/$_v/attendance/clock.php';
  static const String attendanceClockIn = attendanceClock;
  static const String attendanceClockOut = attendanceClock;
  // No dedicated history endpoint exists in the supplied backend package.
  static const String attendanceHistory = '';

  static const String pmTasks = '/cpms/api/$_v/staff/maintenance/list.php';
  static String pmTask(String id) =>
      '/cpms/api/$_v/staff/maintenance/detail.php';
  static String pmTaskComplete(String id) =>
      '/cpms/api/$_v/staff/maintenance/complete.php';

  static const String assetLookup =
      '/cpms/api/$_v/staff/asset-inspection/lookup.php';
  static const String assetSubmit =
      '/cpms/api/$_v/staff/asset-inspection/submit.php';
  static String asset(String id) => assetLookup;

  static const String notifications = '/cpms/api/$_v/notifications.php';
  static const String notificationMarkRead =
      '/cpms/api/$_v/notifications/mark-read.php';
  static const String notificationMarkAllRead =
      '/cpms/api/$_v/notifications/mark-all-read.php';
  static String notificationRead(String id) => notificationMarkRead;

  // Push device registration/app config are not present in supplied API package.
  static const String notificationDeviceRegister = '';
  static const String appConfig = '';
}
