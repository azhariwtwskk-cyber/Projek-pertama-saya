import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/permissions/staff_permissions.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../../../shared/widgets/skeleton.dart';
import '../../../shared/widgets/status_badge.dart';
import '../../attendance/application/attendance_providers.dart';
import '../../attendance/domain/attendance_models.dart';
import '../../auth/application/auth_providers.dart';
import '../../auth/domain/staff_user.dart';
import '../../notifications/application/notifications_providers.dart';
import '../../tasks/domain/task_models.dart';
import '../application/dashboard_providers.dart';
import '../domain/dashboard_models.dart';

/// Section 5-8 & 37: the full Home Dashboard layout — greeting header,
/// attendance card, today's KPIs, today's priority, quick actions, recent
/// tasks, announcements.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentStaffUserProvider);
    if (user == null) return const SizedBox.shrink();
    final dashboardAsync = ref.watch(dashboardDataProvider);
    final unreadCount = ref.watch(unreadNotificationCountProvider);

    return Scaffold(
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(dashboardDataProvider);
            ref.invalidate(attendanceStatusProvider);
            ref.invalidate(notificationsProvider);
          },
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
            children: [
              _Header(user: user, unreadCount: unreadCount),
              const SizedBox(height: 20),
              const _AttendanceQuickCard(),
              const SizedBox(height: 24),
              dashboardAsync.when(
                loading: () => const _DashboardSkeleton(),
                error: (e, _) => AppStateView.error(
                    onRetry: () => ref.invalidate(dashboardDataProvider)),
                data: (data) => _DashboardContent(
                    data: data, permissions: user.permissions),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.user, required this.unreadCount});
  final StaffUser user;
  final int unreadCount;

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    return Row(
      children: [
        CircleAvatar(
          radius: 26,
          backgroundColor:
              Theme.of(context).colorScheme.primary.withValues(alpha: 0.12),
          backgroundImage: user.profileImageUrl.isNotEmpty
              ? NetworkImage(user.profileImageUrl)
              : null,
          child: user.profileImageUrl.isEmpty
              ? Text(user.name.isNotEmpty ? user.name[0] : '?',
                  style: TextStyle(
                      fontWeight: FontWeight.w800,
                      color: Theme.of(context).colorScheme.primary))
              : null,
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('${AppFormatters.greetingForNow(now)}, ${user.name}',
                  style: const TextStyle(
                      fontWeight: FontWeight.w800, fontSize: 17)),
              const SizedBox(height: 2),
              Row(
                children: [
                  const Icon(Icons.apartment_rounded,
                      size: 13, color: AppColors.textSecondary),
                  const SizedBox(width: 4),
                  Flexible(
                    child: Text(user.branding.propertyName,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            color: AppColors.textSecondary, fontSize: 12.5)),
                  ),
                  const Text('  ·  ',
                      style: TextStyle(color: AppColors.textSecondary)),
                  Text(AppFormatters.friendlyDate.format(now),
                      style: const TextStyle(
                          color: AppColors.textSecondary, fontSize: 12.5)),
                ],
              ),
            ],
          ),
        ),
        Stack(
          clipBehavior: Clip.none,
          children: [
            IconButton(
              icon: const Icon(Icons.notifications_none_rounded, size: 26),
              onPressed: () => context.go('/notifications'),
            ),
            if (unreadCount > 0)
              Positioned(
                right: 6,
                top: 6,
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                  decoration: BoxDecoration(
                      color: AppColors.danger,
                      borderRadius: BorderRadius.circular(999)),
                  child: Text('$unreadCount',
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 10,
                          fontWeight: FontWeight.w700)),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _AttendanceQuickCard extends ConsumerStatefulWidget {
  const _AttendanceQuickCard();

  @override
  ConsumerState<_AttendanceQuickCard> createState() =>
      _AttendanceQuickCardState();
}

class _AttendanceQuickCardState extends ConsumerState<_AttendanceQuickCard> {
  bool _busy = false;

  Future<void> _toggleClock(bool clockIn) async {
    setState(() => _busy = true);
    try {
      final result = clockIn
          ? await ref.read(attendanceControllerProvider).clockIn()
          : await ref.read(attendanceControllerProvider).clockOut();
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(result.message)));
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Unable to reach CPMSPro. Please try again.'),
              backgroundColor: AppColors.danger),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final statusAsync = ref.watch(attendanceStatusProvider);
    return statusAsync.when(
      loading: () => const SkeletonCard(height: 88),
      error: (_, __) => const SizedBox.shrink(),
      data: (status) {
        final isClockedIn = status.status == ClockStatus.clockedIn;
        return AppSectionCard(
          onTap: () => context.push('/attendance'),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: (isClockedIn
                          ? AppColors.success
                          : AppColors.textSecondary)
                      .withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                    isClockedIn
                        ? Icons.check_circle_rounded
                        : Icons.schedule_rounded,
                    color: isClockedIn
                        ? AppColors.success
                        : AppColors.textSecondary),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(isClockedIn ? 'CLOCKED IN' : 'NOT CLOCKED IN',
                        style: TextStyle(
                            fontWeight: FontWeight.w800,
                            color: isClockedIn
                                ? AppColors.success
                                : AppColors.textSecondary,
                            letterSpacing: 0.3)),
                    if (isClockedIn && status.clockInTime != null)
                      Text(AppFormatters.time12h.format(status.clockInTime!),
                          style: const TextStyle(fontWeight: FontWeight.w600)),
                  ],
                ),
              ),
              _busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2))
                  : ElevatedButton(
                      onPressed: () => _toggleClock(!isClockedIn),
                      style: ElevatedButton.styleFrom(
                        minimumSize: const Size(0, 40),
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        backgroundColor: isClockedIn ? AppColors.danger : null,
                      ),
                      child: Text(isClockedIn ? 'Clock Out' : 'Clock In'),
                    ),
            ],
          ),
        );
      },
    );
  }
}

class _DashboardSkeleton extends StatelessWidget {
  const _DashboardSkeleton();
  @override
  Widget build(BuildContext context) {
    return const Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SkeletonBox(width: 120, height: 16),
        SizedBox(height: 12),
        SkeletonList(count: 3),
      ],
    );
  }
}

class _DashboardContent extends StatelessWidget {
  const _DashboardContent({required this.data, required this.permissions});
  final DashboardData data;
  final StaffPermissions permissions;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SectionHeader(title: 'Today'),
        _KpiRow(overview: data.overview),
        const SizedBox(height: 24),
        if (data.priorityTask != null) ...[
          const SectionHeader(title: "Today's Priority"),
          _PriorityTaskCard(task: data.priorityTask!),
          const SizedBox(height: 24),
        ],
        const SectionHeader(title: 'Quick Actions'),
        _QuickActionsGrid(permissions: permissions),
        const SizedBox(height: 24),
        SectionHeader(
            title: 'Recent Tasks',
            actionLabel: 'View All',
            onAction: () => context.push('/tasks')),
        if (data.recentTasks.isEmpty)
          AppStateView.noActiveTasks(
            onViewHistory: () => context.push('/work-history'),
          )
        else
          ...data.recentTasks.map((t) => _RecentTaskTile(task: t)),
        if (data.announcements.isNotEmpty) ...[
          const SizedBox(height: 24),
          const SectionHeader(title: 'Announcements'),
          ...data.announcements.map((a) => _AnnouncementTile(announcement: a)),
        ],
      ],
    );
  }
}

class _KpiRow extends StatelessWidget {
  const _KpiRow({required this.overview});
  final TodayOverview overview;

  @override
  Widget build(BuildContext context) {
    final items = [
      ("Today's Tasks", overview.totalTasks, AppColors.info),
      ('Completed', overview.completed, AppColors.success),
      ('Pending', overview.pending, AppColors.warning),
      ('Overdue', overview.overdue, AppColors.danger),
    ];
    return Row(
      children: [
        for (var i = 0; i < items.length; i++) ...[
          if (i != 0) const SizedBox(width: 10),
          Expanded(
            child: AppSectionCard(
              padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
              child: Column(
                children: [
                  Text('${items[i].$2}',
                      style: TextStyle(
                          fontSize: 22,
                          fontWeight: FontWeight.w800,
                          color: items[i].$3)),
                  const SizedBox(height: 4),
                  Text(items[i].$1,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                          fontSize: 11,
                          color: AppColors.textSecondary,
                          fontWeight: FontWeight.w600)),
                ],
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class _PriorityTaskCard extends StatelessWidget {
  const _PriorityTaskCard({required this.task});
  final StaffTask task;

  @override
  Widget build(BuildContext context) {
    final isUrgent = task.priority == TaskPriority.urgent;
    return AppSectionCard(
      onTap: () => context.push('/tasks/${task.id}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                  child: Text(task.title,
                      style: const TextStyle(
                          fontWeight: FontWeight.w800, fontSize: 16))),
              PriorityBadge(priority: task.priority),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              const Icon(Icons.schedule_rounded,
                  size: 15, color: AppColors.textSecondary),
              const SizedBox(width: 4),
              Text('Due: ${AppFormatters.time12h.format(task.dueDate)}',
                  style: const TextStyle(
                      fontSize: 12.5, color: AppColors.textSecondary)),
              const SizedBox(width: 14),
              const Icon(Icons.place_outlined,
                  size: 15, color: AppColors.textSecondary),
              const SizedBox(width: 4),
              Expanded(
                  child: Text(task.location,
                      style: const TextStyle(
                          fontSize: 12.5, color: AppColors.textSecondary),
                      overflow: TextOverflow.ellipsis)),
            ],
          ),
          const SizedBox(height: 14),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              onPressed: () => context.push('/tasks/${task.id}'),
              style: isUrgent
                  ? ElevatedButton.styleFrom(backgroundColor: AppColors.urgent)
                  : null,
              child: const Text('Start Task'),
            ),
          ),
        ],
      ),
    );
  }
}

class _QuickActionsGrid extends StatelessWidget {
  const _QuickActionsGrid({required this.permissions});
  final StaffPermissions permissions;

  @override
  Widget build(BuildContext context) {
    // "Work Order History" (/work-history) and "Daily Work History"
    // (/daily-work/history) are two genuinely different records — a
    // work order's verification decision vs. a staff member's own daily
    // log — and must stay separately labelled/routed, never merged
    // (see mobile/docs/INTEGRATION_REPAIR_REPORT.md, "Work Order History").
    final actions = <(IconData, String, String, String)>[
      if (permissions.can(StaffPermission.workOrderView))
        (
          Icons.assignment_outlined,
          'My Tasks',
          '/tasks',
          StaffPermission.workOrderView
        ),
      if (permissions.can(StaffPermission.dailyWorkCreate))
        (
          Icons.add_task_rounded,
          'Add Daily Work',
          '/daily-work/add',
          StaffPermission.dailyWorkCreate
        ),
      if (permissions.can(StaffPermission.assetView))
        (
          Icons.qr_code_scanner_rounded,
          'Scan QR',
          '/assets/scan',
          StaffPermission.assetView
        ),
      if (permissions.can(StaffPermission.workOrderView))
        (
          Icons.fact_check_outlined,
          'Work Order History',
          '/work-history',
          StaffPermission.workOrderView
        ),
      if (permissions.can(StaffPermission.pmView))
        (
          Icons.build_circle_outlined,
          'PM Tasks',
          '/pm',
          StaffPermission.pmView
        ),
      (
        Icons.work_history_outlined,
        'Daily Work History',
        '/daily-work/history',
        ''
      ),
    ];

    return GridView.count(
      crossAxisCount: 3,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      childAspectRatio: 0.95,
      children: actions.map((a) {
        final (icon, label, route, _) = a;
        return _QuickActionButton(
            icon: icon, label: label, onTap: () => context.push(route));
      }).toList(),
    );
  }
}

class _QuickActionButton extends StatelessWidget {
  const _QuickActionButton(
      {required this.icon, required this.label, required this.onTap});
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return AppSectionCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
                color: Theme.of(context)
                    .colorScheme
                    .primary
                    .withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(12)),
            child: Icon(icon,
                color: Theme.of(context).colorScheme.primary, size: 20),
          ),
          const SizedBox(height: 8),
          Text(label,
              textAlign: TextAlign.center,
              style:
                  const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

class _RecentTaskTile extends StatelessWidget {
  const _RecentTaskTile({required this.task});
  final StaffTask task;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppSectionCard(
        onTap: () => context.push('/tasks/${task.id}'),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(task.taskNumber,
                      style: const TextStyle(
                          fontSize: 11,
                          color: AppColors.textSecondary,
                          fontWeight: FontWeight.w700)),
                  const SizedBox(height: 2),
                  Text(task.title,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis),
                ],
              ),
            ),
            StatusBadge(status: task.status),
          ],
        ),
      ),
    );
  }
}

class _AnnouncementTile extends StatelessWidget {
  const _AnnouncementTile({required this.announcement});
  final Announcement announcement;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppSectionCard(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.campaign_outlined, color: AppColors.info),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(announcement.title,
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text(announcement.body,
                      style: const TextStyle(
                          color: AppColors.textSecondary, fontSize: 12.5)),
                  const SizedBox(height: 4),
                  Text(AppFormatters.timeAgo(announcement.postedAt),
                      style: const TextStyle(
                          color: AppColors.textSecondary, fontSize: 11)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
