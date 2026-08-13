import 'package:flutter/material.dart';

import '../../../../core/theme/app_theme.dart';
import '../../domain/task_models.dart';

/// Visualises the mandated workflow (section 10):
/// NEW -> ACCEPTED -> IN PROGRESS -> WORK COMPLETED -> PENDING VERIFICATION
/// -> VERIFIED / REJECTED. Staff can drive everything up to "submitted for
/// verification"; the final VERIFIED/REJECTED step is always drawn as
/// management-controlled, never something staff can set themselves.
class TaskWorkflowStepper extends StatelessWidget {
  const TaskWorkflowStepper({super.key, required this.status});

  final TaskStatus status;

  static const _steps = [
    (TaskStatus.newTask, 'New'),
    (TaskStatus.accepted, 'Accepted'),
    (TaskStatus.inProgress, 'In Progress'),
    (TaskStatus.workCompleted, 'Work Completed'),
    (TaskStatus.pendingVerification, 'Pending Verification'),
  ];

  int get _currentIndex {
    if (status == TaskStatus.verified || status == TaskStatus.rejected) return _steps.length;
    final i = _steps.indexWhere((s) => s.$1 == status);
    return i == -1 ? 0 : i;
  }

  @override
  Widget build(BuildContext context) {
    final isRejected = status == TaskStatus.rejected;
    final isVerified = status == TaskStatus.verified;
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
          label: isRejected ? 'Rejected' : 'Verified',
          state: isRejected
              ? _StepState.rejected
              : (isVerified ? _StepState.done : _StepState.upcoming),
          isLast: true,
          subtitle: 'Confirmed by Property Admin / Inspector',
        ),
      ],
    );
  }
}

enum _StepState { done, current, upcoming, rejected }

class _StepRow extends StatelessWidget {
  const _StepRow({required this.label, required this.state, required this.isLast, this.subtitle});

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
      case _StepState.rejected:
        return AppColors.danger;
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
                    : state == _StepState.rejected
                        ? const Icon(Icons.close, size: 14, color: Colors.white)
                        : null,
              ),
              if (!isLast) Expanded(child: Container(width: 2, color: state == _StepState.upcoming ? AppColors.border : color)),
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
                      fontWeight: state == _StepState.upcoming ? FontWeight.w500 : FontWeight.w700,
                      color: state == _StepState.upcoming ? AppColors.textSecondary : AppColors.textPrimary,
                    ),
                  ),
                  if (subtitle != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(subtitle!, style: const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
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
