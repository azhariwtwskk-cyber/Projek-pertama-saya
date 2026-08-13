import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
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

/// Section 10-16: the full ASSIGNED -> ACCEPT -> START -> BEFORE EVIDENCE
/// -> WORK -> AFTER EVIDENCE -> COMPLETE -> SUBMIT -> VERIFY/REJECT loop —
/// the single most important screen in the app per the spec's closing
/// section.
class TaskDetailScreen extends ConsumerStatefulWidget {
  const TaskDetailScreen({super.key, required this.taskId});

  final String taskId;

  @override
  ConsumerState<TaskDetailScreen> createState() => _TaskDetailScreenState();
}

class _TaskDetailScreenState extends ConsumerState<TaskDetailScreen> {
  bool _busy = false;

  Future<void> _runAction(Future<void> Function() action, {String? successMessage}) async {
    setState(() => _busy = true);
    try {
      await action();
      if (successMessage != null && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(successMessage)));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(_friendlyError(e)), backgroundColor: AppColors.danger),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _friendlyError(Object e) {
    final message = e.toString().replaceFirst('ApiException', '').trim();
    return message.isEmpty ? 'Something went wrong. Please try again.' : message.replaceAll(RegExp(r'^\([^)]*\):\s*'), '');
  }

  Future<void> _addAfterPhoto(StaffTask task) async {
    final file = await EvidencePicker.showPickerSheet(context);
    if (file == null) return;

    double? lat, lng;
    try {
      final permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.always || permission == LocationPermission.whileInUse) {
        final pos = await Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.medium);
        lat = pos.latitude;
        lng = pos.longitude;
      }
    } catch (_) {
      // GPS is best-effort metadata on evidence photos, not a hard
      // requirement — never block the upload if location can't be read.
    }

    await _runAction(() => ref.read(taskActionsControllerProvider).uploadEvidence(
          taskId: task.id,
          file: file,
          beforePhotoId: task.inspectionIssue?.beforePhotos.isNotEmpty == true
              ? task.inspectionIssue!.beforePhotos.first.id
              : null,
          gpsLat: lat,
          gpsLng: lng,
        ));
  }

  Future<void> _completeTask(StaffTask task) async {
    final result = await CompleteTaskSheet.show(context);
    if (result == null) return;
    await _runAction(
      () => ref.read(taskActionsControllerProvider).complete(
            taskId: task.id,
            remarks: result.remarks,
            materialsUsed: result.materialsUsed,
            timeSpentMinutes: result.timeSpentMinutes,
          ),
      successMessage: 'Submitted for verification.',
    );
  }

  @override
  Widget build(BuildContext context) {
    final taskAsync = ref.watch(taskDetailProvider(widget.taskId));

    return Scaffold(
      appBar: AppBar(title: Text(widget.taskId)),
      body: taskAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => AppStateView.error(onRetry: () => ref.invalidate(taskDetailProvider(widget.taskId))),
        data: (task) => _buildContent(context, task),
      ),
    );
  }

  Widget _buildContent(BuildContext context, StaffTask task) {
    final hasRejection = task.status == TaskStatus.rejected && (task.rejectionReason?.isNotEmpty ?? false);

    return Stack(
      children: [
        ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
          children: [
            if (hasRejection) _RejectionBanner(reason: task.rejectionReason!),
            if (hasRejection) const SizedBox(height: 16),
            AppSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(task.taskNumber,
                            style: const TextStyle(color: AppColors.textSecondary, fontWeight: FontWeight.w700)),
                      ),
                      StatusBadge(status: task.status),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(task.title, style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 8),
                  Text(task.description, style: const TextStyle(color: AppColors.textSecondary, height: 1.4)),
                  const SizedBox(height: 16),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    PriorityBadge(priority: task.priority),
                    Chip(label: Text(task.category.label), visualDensity: VisualDensity.compact),
                  ]),
                  const Divider(height: 32),
                  _InfoRow(icon: Icons.place_outlined, label: 'Location', value: task.location),
                  _InfoRow(icon: Icons.person_outline_rounded, label: 'Assigned By', value: task.assignedBy),
                  _InfoRow(icon: Icons.event_outlined, label: 'Assigned Date', value: DateFormat('d MMM yyyy, h:mm a').format(task.assignedDate)),
                  _InfoRow(
                    icon: Icons.timer_outlined,
                    label: 'Due Date',
                    value: DateFormat('d MMM yyyy, h:mm a').format(task.dueDate),
                    valueColor: task.isOverdue ? AppColors.danger : null,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),
            AppSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Workflow', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 16),
                  TaskWorkflowStepper(status: task.status),
                ],
              ),
            ),
            if (task.inspectionIssue != null) ...[
              const SizedBox(height: 20),
              _BeforeEvidenceSection(issue: task.inspectionIssue!),
            ],
            const SizedBox(height: 20),
            AppSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SectionHeader(title: 'AFTER'),
                  if (task.afterPhotos.isEmpty && !_canEditEvidence(task.status))
                    const Text('No completion evidence uploaded yet.', style: TextStyle(color: AppColors.textSecondary))
                  else
                    PhotoEvidenceGrid(
                      photos: task.afterPhotos,
                      onAddPhoto: _canEditEvidence(task.status) ? () => _addAfterPhoto(task) : null,
                    ),
                ],
              ),
            ),
            if (task.completionRemarks != null && task.completionRemarks!.isNotEmpty) ...[
              const SizedBox(height: 20),
              AppSectionCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Completion Notes', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 10),
                    Text(task.completionRemarks!, style: const TextStyle(height: 1.4)),
                    if (task.materialsUsed != null && task.materialsUsed!.isNotEmpty) ...[
                      const SizedBox(height: 10),
                      Text('Materials: ${task.materialsUsed}', style: const TextStyle(color: AppColors.textSecondary, fontSize: 13)),
                    ],
                    if (task.timeSpentMinutes != null) ...[
                      const SizedBox(height: 4),
                      Text('Time Spent: ${task.timeSpentMinutes} minutes', style: const TextStyle(color: AppColors.textSecondary, fontSize: 13)),
                    ],
                  ],
                ),
              ),
            ],
          ],
        ),
        Positioned(left: 0, right: 0, bottom: 0, child: _buildActionBar(task)),
      ],
    );
  }

  bool _canEditEvidence(TaskStatus status) =>
      status == TaskStatus.accepted || status == TaskStatus.inProgress || status == TaskStatus.rejected;

  Widget _buildActionBar(StaffTask task) {
    Widget? primary;
    switch (task.status) {
      case TaskStatus.newTask:
        primary = ElevatedButton.icon(
          onPressed: _busy ? null : () => _runAction(() => ref.read(taskActionsControllerProvider).accept(task.id)),
          icon: const Icon(Icons.check_circle_outline_rounded, size: 18),
          label: const Text('Accept Task'),
        );
        break;
      case TaskStatus.accepted:
        primary = ElevatedButton.icon(
          onPressed: _busy ? null : () => _runAction(() => ref.read(taskActionsControllerProvider).start(task.id)),
          icon: const Icon(Icons.play_arrow_rounded, size: 20),
          label: const Text('Start Task'),
        );
        break;
      case TaskStatus.inProgress:
      case TaskStatus.rejected:
        primary = ElevatedButton.icon(
          onPressed: _busy ? null : () => _completeTask(task),
          icon: const Icon(Icons.task_alt_rounded, size: 18),
          label: Text(task.status == TaskStatus.rejected ? 'Resubmit Task' : 'Complete Task'),
        );
        break;
      case TaskStatus.workCompleted:
      case TaskStatus.pendingVerification:
        primary = OutlinedButton.icon(
          onPressed: null,
          icon: const Icon(Icons.hourglass_top_rounded, size: 18),
          label: const Text('Awaiting Verification'),
        );
        break;
      case TaskStatus.verified:
        primary = OutlinedButton.icon(
          onPressed: null,
          icon: const Icon(Icons.verified_rounded, size: 18, color: AppColors.success),
          label: const Text('Verified', style: TextStyle(color: AppColors.success)),
        );
        break;
      case TaskStatus.overdue:
        primary = ElevatedButton.icon(
          onPressed: _busy ? null : () => _completeTask(task),
          icon: const Icon(Icons.task_alt_rounded, size: 18),
          label: const Text('Complete Task'),
        );
        break;
    }

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      decoration: BoxDecoration(
        color: Theme.of(context).scaffoldBackgroundColor,
        boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 16, offset: Offset(0, -4))],
      ),
      child: SafeArea(
        top: false,
        child: _busy
            ? const SizedBox(height: 54, child: Center(child: CircularProgressIndicator(strokeWidth: 2.4)))
            : primary,
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({required this.icon, required this.label, required this.value, this.valueColor});
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
          Text('$label: ', style: const TextStyle(color: AppColors.textSecondary, fontWeight: FontWeight.w600)),
          Expanded(child: Text(value, style: TextStyle(color: valueColor, fontWeight: FontWeight.w600))),
        ],
      ),
    );
  }
}

class _RejectionBanner extends StatelessWidget {
  const _RejectionBanner({required this.reason});
  final String reason;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.danger.withValues(alpha: 0.25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.error_outline_rounded, color: AppColors.danger),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Task Requires Attention', style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.danger)),
                const SizedBox(height: 4),
                Text('Completion evidence was rejected. Reason:', style: TextStyle(color: AppColors.danger.withValues(alpha: 0.85), fontSize: 13)),
                const SizedBox(height: 4),
                Text('"$reason"', style: const TextStyle(fontStyle: FontStyle.italic, fontSize: 13)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _BeforeEvidenceSection extends StatelessWidget {
  const _BeforeEvidenceSection({required this.issue});
  final InspectionIssue issue;

  @override
  Widget build(BuildContext context) {
    return AppSectionCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionHeader(title: 'BEFORE'),
          PhotoEvidenceGrid(photos: issue.beforePhotos),
          const SizedBox(height: 16),
          _IssueField(label: 'Issue', value: issue.issue),
          _IssueField(label: 'Location', value: issue.location),
          _IssueField(label: 'Severity', value: issue.severity),
          _IssueField(label: 'Inspector Remark', value: issue.inspectorRemark),
        ],
      ),
    );
  }
}

class _IssueField extends StatelessWidget {
  const _IssueField({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(color: AppColors.textSecondary, fontSize: 12, fontWeight: FontWeight.w600)),
          const SizedBox(height: 2),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}
