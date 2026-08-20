import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';

/// Polished empty/error/offline placeholder (sections 35/36). One widget
/// covers all three so every screen renders them consistently instead of
/// ad-hoc "No data" text.
class AppStateView extends StatelessWidget {
  const AppStateView({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
    this.iconColor,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;
  final Color? iconColor;

  factory AppStateView.noTasksToday() => const AppStateView(
        icon: Icons.task_alt_rounded,
        title: 'No Tasks Today',
        message:
            "You're all caught up. New assignments will appear here automatically.",
      );

  /// Shown wherever an "active work" list can legitimately hit zero
  /// because everything assigned has already been submitted — a
  /// just-completed task moves out of the active queue by design, but
  /// staff must never be left looking at a plain "No tasks" screen with
  /// no way to find what they just did. Always pass [onViewHistory] here.
  factory AppStateView.noActiveTasks({required VoidCallback onViewHistory}) =>
      AppStateView(
        icon: Icons.task_alt_rounded,
        title: 'No Active Tasks',
        message: 'Completed work is available in Work Order History.',
        actionLabel: 'View Work Order History',
        onAction: onViewHistory,
      );

  factory AppStateView.offline({VoidCallback? onRetry}) => AppStateView(
        icon: Icons.cloud_off_rounded,
        title: 'No Internet Connection',
        message:
            'Unable to connect to CPMSPro. Your work has been saved and will sync automatically when connection returns.',
        actionLabel: onRetry != null ? 'Retry' : null,
        onAction: onRetry,
        iconColor: AppColors.warning,
      );

  factory AppStateView.error({String? message, VoidCallback? onRetry}) =>
      AppStateView(
        icon: Icons.error_outline_rounded,
        title: 'Something Went Wrong',
        message: message ?? 'Please try again.',
        actionLabel: onRetry != null ? 'Retry' : null,
        onAction: onRetry,
        iconColor: AppColors.danger,
      );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 40),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 76,
            height: 76,
            decoration: BoxDecoration(
              color: (iconColor ?? theme.colorScheme.primary)
                  .withValues(alpha: 0.1),
              shape: BoxShape.circle,
            ),
            child: Icon(icon,
                size: 36, color: iconColor ?? theme.colorScheme.primary),
          ),
          const SizedBox(height: 20),
          Text(title,
              style: theme.textTheme.titleMedium
                  ?.copyWith(fontWeight: FontWeight.w700),
              textAlign: TextAlign.center),
          const SizedBox(height: 8),
          Text(
            message,
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium
                ?.copyWith(color: AppColors.textSecondary),
          ),
          if (actionLabel != null) ...[
            const SizedBox(height: 20),
            OutlinedButton(onPressed: onAction, child: Text(actionLabel!)),
          ],
        ],
      ),
    );
  }
}
