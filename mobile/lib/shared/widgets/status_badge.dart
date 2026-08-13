import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';
import '../../features/tasks/domain/task_models.dart';

class PriorityBadge extends StatelessWidget {
  const PriorityBadge({super.key, required this.priority, this.dense = false});

  final TaskPriority priority;
  final bool dense;

  Color get _color {
    switch (priority) {
      case TaskPriority.low:
        return AppColors.priorityLow;
      case TaskPriority.normal:
        return AppColors.priorityNormal;
      case TaskPriority.high:
        return AppColors.priorityHigh;
      case TaskPriority.urgent:
        return AppColors.priorityUrgent;
    }
  }

  String get _label {
    switch (priority) {
      case TaskPriority.low:
        return 'LOW';
      case TaskPriority.normal:
        return 'NORMAL';
      case TaskPriority.high:
        return 'HIGH PRIORITY';
      case TaskPriority.urgent:
        return 'URGENT';
    }
  }

  @override
  Widget build(BuildContext context) {
    final color = _color;
    return Container(
      padding: EdgeInsets.symmetric(horizontal: dense ? 8 : 10, vertical: dense ? 3 : 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
        border: priority == TaskPriority.urgent ? Border.all(color: color.withValues(alpha: 0.4)) : null,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (priority == TaskPriority.urgent) ...[
            Icon(Icons.bolt_rounded, size: dense ? 11 : 13, color: color),
            const SizedBox(width: 3),
          ],
          Text(
            _label,
            style: TextStyle(
              color: color,
              fontWeight: FontWeight.w700,
              fontSize: dense ? 10 : 11,
              letterSpacing: 0.3,
            ),
          ),
        ],
      ),
    );
  }
}

class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.status});

  final TaskStatus status;

  (Color, String) get _spec {
    switch (status) {
      case TaskStatus.newTask:
        return (AppColors.info, 'NEW');
      case TaskStatus.accepted:
        return (const Color(0xFF7A5AF8), 'ACCEPTED');
      case TaskStatus.inProgress:
        return (AppColors.warning, 'IN PROGRESS');
      case TaskStatus.workCompleted:
        return (const Color(0xFF06AED4), 'WORK COMPLETED');
      case TaskStatus.pendingVerification:
        return (const Color(0xFF7A5AF8), 'PENDING VERIFICATION');
      case TaskStatus.verified:
        return (AppColors.success, 'VERIFIED');
      case TaskStatus.rejected:
        return (AppColors.danger, 'REJECTED');
      case TaskStatus.overdue:
        return (AppColors.danger, 'OVERDUE');
    }
  }

  @override
  Widget build(BuildContext context) {
    final (color, label) = _spec;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(999)),
      child: Text(
        label,
        style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 11, letterSpacing: 0.2),
      ),
    );
  }
}
