import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/utils/evidence_picker.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../application/pm_providers.dart';
import '../domain/pm_models.dart';

/// Section 22: PM Task Detail — mandatory checklist must all be ticked
/// before submission, matching "Staff must complete mandatory checklist
/// items before submission."
class PmDetailScreen extends ConsumerStatefulWidget {
  const PmDetailScreen({super.key, required this.taskId});
  final String taskId;

  @override
  ConsumerState<PmDetailScreen> createState() => _PmDetailScreenState();
}

class _PmDetailScreenState extends ConsumerState<PmDetailScreen> {
  final List<File> _evidencePhotos = [];
  bool _busy = false;

  Future<void> _addPhoto() async {
    final file = await EvidencePicker.showPickerSheet(context);
    if (file != null) setState(() => _evidencePhotos.add(file));
  }

  Future<void> _submit(PmTask task) async {
    if (!task.allMandatoryChecked) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please complete all mandatory checklist items first.'), backgroundColor: AppColors.danger),
      );
      return;
    }
    setState(() => _busy = true);
    try {
      await ref.read(pmActionsControllerProvider).complete(task.id, evidencePhotos: _evidencePhotos);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('PM task completed.')));
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString()), backgroundColor: AppColors.danger));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final taskAsync = ref.watch(pmTaskDetailProvider(widget.taskId));
    return Scaffold(
      appBar: AppBar(title: const Text('PM Task')),
      body: taskAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: AppStateView.error(onRetry: () => ref.invalidate(pmTaskDetailProvider(widget.taskId)))),
        data: (task) => _buildContent(task),
      ),
    );
  }

  Widget _buildContent(PmTask task) {
    final isCompleted = task.status == PmStatus.completed;

    return Stack(
      children: [
        ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
          children: [
            AppSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(task.assetName, style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  Text(task.assetId, style: const TextStyle(color: AppColors.textSecondary)),
                  const Divider(height: 28),
                  _InfoLine(label: 'Location', value: task.location),
                  _InfoLine(label: 'PM Type', value: task.pmType),
                  _InfoLine(label: 'Scheduled Date', value: DateFormat('d MMM yyyy, h:mm a').format(task.scheduledDate)),
                  const SizedBox(height: 8),
                  const Text('Instructions', style: TextStyle(fontWeight: FontWeight.w600, color: AppColors.textSecondary, fontSize: 12)),
                  const SizedBox(height: 4),
                  Text(task.instructions, style: const TextStyle(height: 1.4)),
                ],
              ),
            ),
            const SizedBox(height: 20),
            AppSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SectionHeader(title: 'Checklist'),
                  for (final item in task.checklist)
                    CheckboxListTile(
                      value: item.isChecked,
                      onChanged: isCompleted
                          ? null
                          : (v) => ref.read(pmActionsControllerProvider).toggleChecklistItem(task.id, item.id, v ?? false),
                      contentPadding: EdgeInsets.zero,
                      controlAffinity: ListTileControlAffinity.leading,
                      title: Text(item.label + (item.isMandatory ? ' *' : '')),
                    ),
                ],
              ),
            ),
            if (!isCompleted) ...[
              const SizedBox(height: 20),
              AppSectionCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const SectionHeader(title: 'Evidence Photo'),
                    Wrap(
                      spacing: 10,
                      runSpacing: 10,
                      children: [
                        for (final photo in _evidencePhotos)
                          ClipRRect(borderRadius: BorderRadius.circular(14), child: Image.file(photo, width: 84, height: 84, fit: BoxFit.cover)),
                        GestureDetector(
                          onTap: _addPhoto,
                          child: Container(
                            width: 84,
                            height: 84,
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(14),
                              border: Border.all(color: Theme.of(context).colorScheme.primary),
                              color: Theme.of(context).colorScheme.primary.withValues(alpha: 0.05),
                            ),
                            child: Icon(Icons.add_a_photo_outlined, color: Theme.of(context).colorScheme.primary),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
        if (!isCompleted)
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: Container(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
              decoration: BoxDecoration(
                color: Theme.of(context).scaffoldBackgroundColor,
                boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 16, offset: Offset(0, -4))],
              ),
              child: SafeArea(
                top: false,
                child: ElevatedButton(
                  onPressed: _busy ? null : () => _submit(task),
                  child: _busy
                      ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                      : const Text('Submit PM Task'),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _InfoLine extends StatelessWidget {
  const _InfoLine({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          Text('$label: ', style: const TextStyle(color: AppColors.textSecondary, fontWeight: FontWeight.w600)),
          Expanded(child: Text(value, style: const TextStyle(fontWeight: FontWeight.w600))),
        ],
      ),
    );
  }
}
