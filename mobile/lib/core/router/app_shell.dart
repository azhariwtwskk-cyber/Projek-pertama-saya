import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/notifications/application/notifications_providers.dart';
import '../../shared/widgets/offline_banner.dart';

/// Premium bottom navigation shell (section 3): Home, Tasks, Attendance,
/// Notifications, Profile. Wraps every top-level tab so the offline banner
/// is always visible regardless of which tab is active.
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.navigationShell});

  final StatefulNavigationShell navigationShell;

  static const _tabs = [
    (Icons.home_outlined, Icons.home_rounded, 'Home'),
    (Icons.assignment_outlined, Icons.assignment_rounded, 'Tasks'),
    (Icons.schedule_outlined, Icons.schedule_rounded, 'Attendance'),
    (Icons.notifications_none_rounded, Icons.notifications_rounded, 'Alerts'),
    (Icons.person_outline_rounded, Icons.person_rounded, 'Profile'),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unreadCount = ref.watch(unreadNotificationCountProvider);

    return Scaffold(
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(child: navigationShell),
        ],
      ),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: navigationShell.currentIndex,
        onTap: (index) => navigationShell.goBranch(index, initialLocation: index == navigationShell.currentIndex),
        items: [
          for (var i = 0; i < _tabs.length; i++)
            BottomNavigationBarItem(
              icon: i == 3 && unreadCount > 0
                  ? Badge(label: Text('$unreadCount'), child: Icon(_tabs[i].$1))
                  : Icon(_tabs[i].$1),
              activeIcon: Icon(_tabs[i].$2),
              label: _tabs[i].$3,
            ),
        ],
      ),
    );
  }
}
