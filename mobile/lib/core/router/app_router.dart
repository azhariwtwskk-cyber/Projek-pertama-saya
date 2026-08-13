import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/assets/presentation/asset_detail_screen.dart';
import '../../features/assets/presentation/qr_scanner_screen.dart';
import '../../features/attendance/presentation/attendance_history_screen.dart';
import '../../features/attendance/presentation/attendance_screen.dart';
import '../../features/auth/application/auth_providers.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/daily_work/presentation/add_daily_work_screen.dart';
import '../../features/daily_work/presentation/daily_work_history_screen.dart';
import '../../features/dashboard/presentation/home_screen.dart';
import '../../features/notifications/presentation/notifications_screen.dart';
import '../../features/preventive_maintenance/presentation/pm_detail_screen.dart';
import '../../features/preventive_maintenance/presentation/pm_list_screen.dart';
import '../../features/profile/presentation/profile_screen.dart';
import '../../features/sync/presentation/sync_centre_screen.dart';
import '../../features/tasks/presentation/task_detail_screen.dart';
import '../../features/tasks/presentation/task_inbox_screen.dart';
import 'app_shell.dart';

final _rootNavigatorKey = GlobalKey<NavigatorState>();

/// Every screen behind login lives outside the five bottom-nav tabs as a
/// normal pushed route (task detail, PM detail, daily work, asset detail,
/// sync centre, attendance history) so back-navigation and deep links from
/// push notifications behave exactly like a native app.
final appRouterProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    navigatorKey: _rootNavigatorKey,
    initialLocation: '/home',
    refreshListenable: _AuthListenable(ref),
    redirect: (context, state) {
      final authState = ref.read(authControllerProvider);
      final loggingIn = state.matchedLocation == '/login';

      if (authState.status == AuthStatus.unknown) return null;
      if (authState.status != AuthStatus.authenticated) {
        return loggingIn ? null : '/login';
      }
      if (loggingIn) return '/home';
      return null;
    },
    routes: [
      GoRoute(path: '/login', builder: (context, state) => const LoginScreen()),
      StatefulShellRoute.indexedStack(
        builder: (context, state, navigationShell) => AppShell(navigationShell: navigationShell),
        branches: [
          StatefulShellBranch(routes: [
            GoRoute(path: '/home', builder: (context, state) => const HomeScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/tasks', builder: (context, state) => const TaskInboxScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/attendance', builder: (context, state) => const AttendanceScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/notifications', builder: (context, state) => const NotificationsScreen()),
          ]),
          StatefulShellBranch(routes: [
            GoRoute(path: '/profile', builder: (context, state) => const ProfileScreen()),
          ]),
        ],
      ),
      GoRoute(
        path: '/tasks/:id',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (context, state) => TaskDetailScreen(taskId: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/attendance/history',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (context, state) => const AttendanceHistoryScreen(),
      ),
      GoRoute(
        path: '/daily-work/add',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (context, state) => const AddDailyWorkScreen(),
      ),
      GoRoute(
        path: '/daily-work/history',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (context, state) => const DailyWorkHistoryScreen(),
      ),
      GoRoute(
        path: '/pm',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (context, state) => const PmListScreen(),
      ),
      GoRoute(
        path: '/pm/:id',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (context, state) => PmDetailScreen(taskId: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/assets/scan',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (context, state) => const QrScannerScreen(),
      ),
      GoRoute(
        path: '/assets/:id',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (context, state) => AssetDetailScreen(assetId: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/sync',
        parentNavigatorKey: _rootNavigatorKey,
        builder: (context, state) => const SyncCentreScreen(),
      ),
    ],
  );
});

/// Bridges Riverpod's [AuthState] into a [Listenable] so GoRouter refreshes
/// its redirect decision the instant login/logout happens.
class _AuthListenable extends ChangeNotifier {
  _AuthListenable(this._ref) {
    _ref.listen(authControllerProvider, (previous, next) {
      if (previous?.status != next.status) notifyListeners();
    });
  }

  final Ref _ref;
}
