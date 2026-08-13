import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../../../shared/widgets/skeleton.dart';
import '../application/notifications_providers.dart';
import '../domain/notification_models.dart';

/// Section 24: Notification Centre with deep linking (section 25) — tapping
/// a card marks it read and routes straight to the relevant task/PM item.
class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notificationsAsync = ref.watch(notificationsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Notifications')),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(notificationsProvider),
        child: notificationsAsync.when(
          loading: () => const Padding(padding: EdgeInsets.all(16), child: SkeletonList()),
          error: (e, _) => Center(child: AppStateView.error(onRetry: () => ref.invalidate(notificationsProvider))),
          data: (items) {
            if (items.isEmpty) {
              return const Center(
                child: AppStateView(
                  icon: Icons.notifications_none_rounded,
                  title: 'No Notifications',
                  message: 'You will see work orders, PM reminders and announcements here.',
                ),
              );
            }
            return ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: items.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (context, i) => _NotificationCard(
                notification: items[i],
                onTap: () {
                  ref.read(notificationsControllerProvider).markRead(items[i].id);
                  final route = items[i].deepLinkRoute;
                  if (route != null) context.push(route);
                },
              ),
            );
          },
        ),
      ),
    );
  }
}

class _NotificationCard extends StatelessWidget {
  const _NotificationCard({required this.notification, required this.onTap});
  final AppNotification notification;
  final VoidCallback onTap;

  IconData get _icon {
    switch (notification.type) {
      case NotificationType.newWorkOrder:
      case NotificationType.newAssignment:
        return Icons.assignment_outlined;
      case NotificationType.urgentTask:
        return Icons.bolt_rounded;
      case NotificationType.inspectionCorrectiveAction:
        return Icons.fact_check_outlined;
      case NotificationType.pmDue:
      case NotificationType.pmOverdue:
        return Icons.build_circle_outlined;
      case NotificationType.taskRejected:
        return Icons.error_outline_rounded;
      case NotificationType.taskVerified:
        return Icons.verified_rounded;
      case NotificationType.attendanceReminder:
        return Icons.schedule_rounded;
      case NotificationType.managementAnnouncement:
        return Icons.campaign_outlined;
    }
  }

  @override
  Widget build(BuildContext context) {
    final isUrgent = notification.type.isUrgent;
    final color = isUrgent ? AppColors.danger : Theme.of(context).colorScheme.primary;

    return AppSectionCard(
      onTap: onTap,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Stack(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(color: color.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(12)),
                child: Icon(_icon, color: color, size: 20),
              ),
              if (!notification.isRead)
                Positioned(
                  right: 0,
                  top: 0,
                  child: Container(width: 9, height: 9, decoration: const BoxDecoration(color: AppColors.danger, shape: BoxShape.circle)),
                ),
            ],
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (isUrgent)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Text('URGENT', style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 11, letterSpacing: 0.4)),
                  ),
                Text(notification.title, style: TextStyle(fontWeight: notification.isRead ? FontWeight.w600 : FontWeight.w800)),
                const SizedBox(height: 4),
                Text(notification.body, style: const TextStyle(color: AppColors.textSecondary, fontSize: 13), maxLines: 2, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 6),
                Text(AppFormatters.timeAgo(notification.createdAt), style: const TextStyle(color: AppColors.textSecondary, fontSize: 11)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
