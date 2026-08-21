import 'package:flutter/material.dart';

import '../../../../core/theme/app_theme.dart';
import '../../domain/task_models.dart';

/// Visualises the real work-order lifecycle. `staff/tasks.php` only ever
/// reports three buckets — pending, in_progress, completed (which itself
/// covers both `Completed` and `Verified` server-side) — there is no
/// discrete accept/start step and no staff-facing rejection state for
/// work orders, so the stepper mirrors exactly that instead of a richer
/// workflow the backend doesn't implement.
class TaskWorkflowStepper extends StatelessWidget {
  const TaskWorkflowStepper({super.key, required this.status});

  final TaskStatus status;

  static const _steps = [
    (TaskStatus.newTask, 'New'),
    (TaskStatus.inProgress, 'In Progress'),
  ];

  int get _currentIndex {
    if (status == TaskStatus.verified) return _steps.length;
    if (status == TaskStatus.newTask) return 0;
    // Everything else (accepted / workCompleted / pendingVerification /
    // rejected / overdue) only ever appears in mock/demo data — treat it
    // as "in progress" for stepper purposes.
    return 1;
  }

  @override
  Widget build(BuildContext context) {
    final current = _currentIndex;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < _steps.length; i++)
          _StepRow(
            label: _steps[i].$2,
            state: i < current
                ? _StepState.done
                : (i == current ? _StepState.current : _StepState.upcoming),
            isLast: false,
          ),
        _StepRow(
          label: 'Completed',
          state:
              current >= _steps.length ? _StepState.done : _StepState.upcoming,
          isLast: true,
          subtitle: 'Advances automatically once a Daily Work log is submitted',
        ),
      ],
    );
  }
}

enum _StepState { done, current, upcoming }

class _StepRow extends StatelessWidget {
  const _StepRow(
      {required this.label,
      required this.state,
      required this.isLast,
      this.subtitle});

  final String label;
  final _StepState state;
  final bool isLast;
  final String? subtitle;

  Color get _color {
    switch (state) {
      case _StepState.done:
        return AppColors.success;
      case _StepState.current:
        return AppColors.info;
      case _StepState.upcoming:
        return AppColors.border;
    }
  }

  @override
  Widget build(BuildContext context) {
    final color = _color;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Column(
            children: [
              Container(
                width: 22,
                height: 22,
                decoration: BoxDecoration(
                  color: state == _StepState.upcoming ? Colors.white : color,
                  shape: BoxShape.circle,
                  border: Border.all(color: color, width: 2),
                ),
                child: state == _StepState.done
                    ? const Icon(Icons.check, size: 14, color: Colors.white)
                    : null,
              ),
              if (!isLast)
                Expanded(
                    child: Container(
                        width: 2,
                        color: state == _StepState.upcoming
                            ? AppColors.border
                            : color)),
            ],
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: isLast ? 0 : 20, top: 1),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    label,
                    style: TextStyle(
                      fontWeight: state == _StepState.upcoming
                          ? FontWeight.w500
                          : FontWeight.w700,
                      color: state == _StepState.upcoming
                          ? AppColors.textSecondary
                          : AppColors.textPrimary,
                    ),
                  ),
                  if (subtitle != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(subtitle!,
                          style: const TextStyle(
                              fontSize: 12, color: AppColors.textSecondary)),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
