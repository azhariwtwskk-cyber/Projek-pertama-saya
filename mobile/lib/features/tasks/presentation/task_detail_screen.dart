import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/utils/evidence_picker.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../../../shared/widgets/status_badge.dart';
import '../application/tasks_providers.dart';
import '../domain/task_models.dart';
import 'widgets/complete_task_sheet.dart';
import 'widgets/photo_evidence_grid.dart';
import 'widgets/task_workflow_stepper.dart';

/// Work order detail + the real CPMSPro workflow: capture Before/During/
/// After evidence any time via `staff/task-photo.php`, then complete the
/// task by submitting a linked Daily Work log (see
/// mobile/docs/INTEGRATION_REPAIR_REPORT.md). There is no accept/start
/// step on the real backend, so this screen never shows one.
class TaskDetailScreen extends ConsumerStatefulWidget {
  const TaskDetailScreen({super.key, required this.taskId});

  final String taskId;

  @override
  ConsumerState<TaskDetailScreen> createState() => _TaskDetailScreenState();
}

class _TaskDetailScreenState extends ConsumerState<TaskDetailScreen> {
  bool _busy = false;

  /// Evidence photos uploaded to `staff/task-photo.php` this session,
  /// grouped by image type. The backend has no endpoint to list a work
  /// order's full photo history, so this in-memory cache — plus
  /// [StaffTask.existingImageUrl]'s single latest thumbnail — is the
  /// only gallery the app can show, and it is purely additive: nothing
  /// here is ever deleted or overwritten, matching the backend's
  /// append-only `work_order_images` table.
  final Map<String, List<EvidencePhoto>> _sessionEvidence = {};

  Future<void> _runAction(Future<void> Function() action,
      {String? successMessage}) async {
    setState(() => _busy = true);
    try {
      await action();
      if (successMessage != null && mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(successMessage)));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(_friendlyError(e)),
              backgroundColor: AppColors.danger),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _friendlyError(Object e) {
    final message = e.toString().replaceFirst('ApiException', '').trim();
    return message.isEmpty
        ? 'Something went wrong. Please try again.'
        : message.replaceAll(RegExp(r'^\([^)]*\):\s*'), '');
  }

  Future<void> _pickImageType(BuildContext context) {
    return showModalBottomSheet<String>(
      context: context,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (sheetContext) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 12),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 20),
              child: Align(
                  alignment: Alignment.centerLeft,
                  child: Text('Add evidence photo',
                      style: TextStyle(fontWeight: FontWeight.w700))),
            ),
            const SizedBox(height: 8),
            for (final type in const [
              'Before',
              'During',
              'After',
              'Supporting'
            ])
              ListTile(
                leading: const Icon(Icons.photo_camera_outlined),
                title: Text(type),
                onTap: () async {
                  Navigator.of(sheetContext).pop();
                  await _addEvidence(type);
                },
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  Future<void> _addEvidence(String imageType) async {
    final file = await EvidencePicker.showPickerSheet(context);
    if (file == null) return;

    await _runAction(
      () async {
        final photo =
            await ref.read(taskActionsControllerProvider).uploadEvidence(
                  taskId: widget.taskId,
                  file: file,
                  imageType: imageType,
                );
        setState(() {
          _sessionEvidence.putIfAbsent(widget.taskId, () => []).add(photo);
        });
      },
      successMessage: '$imageType photo uploaded.',
    );
  }

  Future<void> _completeTask(StaffTask task) async {
    final result = await CompleteTaskSheet.show(context);
    if (result == null) return;
    await _runAction(
      () => ref.read(taskActionsControllerProvider).complete(
            task: task,
            workDescription: result.workDescription,
            workStatus: result.workStatus,
            materialsUsed: result.materialsUsed,
            issueNotes: result.issueNotes,
            afterPhotos: result.afterPhotos,
            beforePhotos: result.beforePhotos,
            duringPhotos: result.duringPhotos,
          ),
      successMessage: result.workStatus == 'Completed'
          ? 'Task completed and submitted to CPMSPro.'
          : 'Daily Work log submitted.',
    );
  }

  @override
  Widget build(BuildContext context) {
    final taskAsync = ref.watch(taskDetailProvider(widget.taskId));

    return Scaffold(
      appBar: AppBar(title: Text(widget.taskId)),
      body: taskAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => AppStateView.error(
            onRetry: () => ref.invalidate(taskDetailProvider(widget.taskId))),
        data: (task) => _buildContent(context, task),
      ),
    );
  }

  Widget _buildContent(BuildContext context, StaffTask task) {
    final sessionPhotos = _sessionEvidence[task.id] ?? const [];
    final allEvidence = <EvidencePhoto>[
      if (task.existingImageUrl != null)
        EvidencePhoto(
            id: 'existing',
            url: task.existingImageUrl!,
            uploadedAt: task.assignedDate),
      ...sessionPhotos,
    ];
    final canEdit = task.status == TaskStatus.newTask ||
        task.status == TaskStatus.inProgress;

    return Stack(
      children: [
        ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
          children: [
            AppSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(task.taskNumber,
                            style: const TextStyle(
                                color: AppColors.textSecondary,
                                fontWeight: FontWeight.w700)),
                      ),
                      StatusBadge(status: task.status),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(task.title,
                      style: Theme.of(context)
                          .textTheme
                          .titleLarge
                          ?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 8),
                  Text(task.description,
                      style: const TextStyle(
                          color: AppColors.textSecondary, height: 1.4)),
                  const SizedBox(height: 16),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    PriorityBadge(priority: task.priority),
                    Chip(
                        label: Text(task.category.label),
                        visualDensity: VisualDensity.compact),
                  ]),
                  const Divider(height: 32),
                  _InfoRow(
                      icon: Icons.place_outlined,
                      label: 'Location',
                      value: task.location),
                  _InfoRow(
                      icon: Icons.person_outline_rounded,
                      label: 'Assigned By',
                      value: task.assignedBy),
                  _InfoRow(
                      icon: Icons.event_outlined,
                      label: 'Assigned Date',
                      value: DateFormat('d MMM yyyy, h:mm a')
                          .format(task.assignedDate)),
                  _InfoRow(
                    icon: Icons.timer_outlined,
                    label: 'Due Date',
                    value:
                        DateFormat('d MMM yyyy, h:mm a').format(task.dueDate),
                    valueColor: task.isOverdue ? AppColors.danger : null,
                  ),
                ],
              ),
            ),
            if (task.rejectionReason != null &&
                task.rejectionReason!.isNotEmpty) ...[
              const SizedBox(height: 20),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: AppColors.danger.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(
                      color: AppColors.danger.withValues(alpha: 0.25)),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(Icons.error_rounded,
                        size: 20, color: AppColors.danger),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('Rejected – Action Required',
                              style: TextStyle(
                                  fontWeight: FontWeight.w800,
                                  color: AppColors.danger)),
                          const SizedBox(height: 4),
                          Text(task.rejectionReason!,
                              style: const TextStyle(
                                  fontSize: 12.5, color: AppColors.danger)),
                          const SizedBox(height: 4),
                          const Text(
                            'This work order was sent back by Property Admin. Submit corrective work below.',
                            style: TextStyle(
                                fontSize: 11.5, color: AppColors.textSecondary),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 20),
            AppSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Workflow',
                      style: Theme.of(context)
                          .textTheme
                          .titleSmall
                          ?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 16),
                  TaskWorkflowStepper(status: task.status),
                ],
              ),
            ),
            const SizedBox(height: 20),
            AppSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SectionHeader(title: 'Evidence Photos'),
                  const SizedBox(height: 4),
                  const Text(
                    'Before / during / after photos are attached directly to this work order and are never overwritten.',
                    style: TextStyle(
                        color: AppColors.textSecondary, fontSize: 12.5),
                  ),
                  const SizedBox(height: 12),
                  if (allEvidence.isEmpty && !canEdit)
                    const Text('No evidence uploaded yet.',
                        style: TextStyle(color: AppColors.textSecondary))
                  else
                    PhotoEvidenceGrid(
                      photos: allEvidence,
                      addLabel: 'Add Photo',
                      onAddPhoto:
                          canEdit ? () => _pickImageType(context) : null,
                    ),
                ],
              ),
            ),
            if (task.completionRemarks != null &&
                task.completionRemarks!.isNotEmpty) ...[
              const SizedBox(height: 20),
              AppSectionCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Completion Notes',
                        style: Theme.of(context)
                            .textTheme
                            .titleSmall
                            ?.copyWith(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 10),
                    Text(task.completionRemarks!,
                        style: const TextStyle(height: 1.4)),
                    if (task.materialsUsed != null &&
                        task.materialsUsed!.isNotEmpty) ...[
                      const SizedBox(height: 10),
                      Text('Materials: ${task.materialsUsed}',
                          style: const TextStyle(
                              color: AppColors.textSecondary, fontSize: 13)),
                    ],
                  ],
                ),
              ),
            ],
          ],
        ),
        Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: _buildActionBar(task, canEdit)),
      ],
    );
  }

  Widget _buildActionBar(StaffTask task, bool canEdit) {
    Widget primary;
    if (task.status == TaskStatus.verified) {
      primary = const OutlinedButton(
        onPressed: null,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.verified_rounded, size: 18, color: AppColors.success),
            SizedBox(width: 8),
            Text('Completed', style: TextStyle(color: AppColors.success)),
          ],
        ),
      );
    } else {
      primary = ElevatedButton.icon(
        onPressed: _busy ? null : () => _completeTask(task),
        icon: const Icon(Icons.task_alt_rounded, size: 18),
        label: const Text('Complete Task'),
      );
    }

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      decoration: BoxDecoration(
        color: Theme.of(context).scaffoldBackgroundColor,
        boxShadow: const [
          BoxShadow(
              color: Color(0x14000000), blurRadius: 16, offset: Offset(0, -4))
        ],
      ),
      child: SafeArea(
        top: false,
        child: _busy
            ? const SizedBox(
                height: 54,
                child:
                    Center(child: CircularProgressIndicator(strokeWidth: 2.4)))
            : primary,
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow(
      {required this.icon,
      required this.label,
      required this.value,
      this.valueColor});
  final IconData icon;
  final String label;
  final String value;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: AppColors.textSecondary),
          const SizedBox(width: 10),
          Text('$label: ',
              style: const TextStyle(
                  color: AppColors.textSecondary, fontWeight: FontWeight.w600)),
          Expanded(
              child: Text(value,
                  style: TextStyle(
                      color: valueColor, fontWeight: FontWeight.w600))),
        ],
      ),
    );
  }
}
