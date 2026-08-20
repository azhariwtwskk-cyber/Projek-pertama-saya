import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../../core/theme/app_theme.dart';
import '../../../../shared/widgets/section_header.dart';
import '../../../../shared/widgets/status_badge.dart';
import '../../domain/task_models.dart';

class TaskCard extends StatelessWidget {
  const TaskCard({super.key, required this.task, required this.onTap});

  final StaffTask task;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isOverdue = task.isOverdue;
    final dueLabel = isOverdue
        ? 'Overdue • ${DateFormat('d MMM, h:mm a').format(task.dueDate)}'
        : 'Due Today • ${DateFormat('h:mm a').format(task.dueDate)}';

    return AppSectionCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  task.taskNumber,
                  style: const TextStyle(
                      fontWeight: FontWeight.w700,
                      color: AppColors.textSecondary,
                      fontSize: 12.5),
                ),
              ),
              StatusBadge(status: task.status),
            ],
          ),
          const SizedBox(height: 6),
          Text(task.title,
              style: Theme.of(context)
                  .textTheme
                  .titleSmall
                  ?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Row(
            children: [
              const Icon(Icons.place_outlined,
                  size: 14, color: AppColors.textSecondary),
              const SizedBox(width: 4),
              Expanded(
                child: Text(task.location,
                    style: const TextStyle(
                        color: AppColors.textSecondary, fontSize: 12.5)),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              PriorityBadge(priority: task.priority, dense: true),
              Text(
                dueLabel,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: isOverdue ? AppColors.danger : AppColors.textSecondary,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
