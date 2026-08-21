import 'dart:io';

import 'package:flutter/material.dart';

import '../../../../core/theme/app_theme.dart';
import '../../../../shared/utils/evidence_picker.dart';

class CompleteTaskResult {
  const CompleteTaskResult({
    required this.workDescription,
    required this.workStatus,
    this.materialsUsed,
    this.issueNotes,
    required this.afterPhotos,
    required this.beforePhotos,
    required this.duringPhotos,
  });

  final String workDescription;
  final String workStatus;
  final String? materialsUsed;
  final String? issueNotes;
  final List<File> afterPhotos;
  final List<File> beforePhotos;
  final List<File> duringPhotos;
}

/// The real work-order completion mechanism: submitting a Daily Work log
/// linked to this task (`work_order_id`) — see
/// `staff/daily-work/submit.php` in mobile/docs/INTEGRATION_REPAIR_REPORT.md.
/// The backend rejects `work_status=Completed` with no AFTER photo
/// (`AFTER_IMAGE_REQUIRED`), so this sheet enforces the same rule
/// client-side before the network round trip.
class CompleteTaskSheet extends StatefulWidget {
  const CompleteTaskSheet({super.key});

  static Future<CompleteTaskResult?> show(BuildContext context) {
    return showModalBottomSheet<CompleteTaskResult>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (_) => const CompleteTaskSheet(),
    );
  }

  @override
  State<CompleteTaskSheet> createState() => _CompleteTaskSheetState();
}

/// The backend's allowed `work_status` values (`staff/daily-work/submit.php`
/// / `staff/daily-work/options.php`) — kept in sync manually since this
/// sheet only needs the completion-flow subset.
const _kWorkStatuses = [
  'Completed',
  'Pending Material',
  'Pending Contractor',
  'Unable to Complete'
];

class _CompleteTaskSheetState extends State<CompleteTaskSheet> {
  final _formKey = GlobalKey<FormState>();
  final _descriptionController = TextEditingController();
  final _materialsController = TextEditingController();
  final _issueController = TextEditingController();
  String _workStatus = 'Completed';
  final List<File> _beforePhotos = [];
  final List<File> _duringPhotos = [];
  final List<File> _afterPhotos = [];
  String? _evidenceError;

  @override
  void dispose() {
    _descriptionController.dispose();
    _materialsController.dispose();
    _issueController.dispose();
    super.dispose();
  }

  Future<void> _addPhoto(List<File> target) async {
    final file = await EvidencePicker.showPickerSheet(context);
    if (file == null) return;
    setState(() {
      target.add(file);
      _evidenceError = null;
    });
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    if (_workStatus == 'Completed' && _afterPhotos.isEmpty) {
      setState(() => _evidenceError =
          'At least one AFTER photo is required to mark this task Completed.');
      return;
    }
    Navigator.of(context).pop(CompleteTaskResult(
      workDescription: _descriptionController.text.trim(),
      workStatus: _workStatus,
      materialsUsed: _materialsController.text.trim().isEmpty
          ? null
          : _materialsController.text.trim(),
      issueNotes: _issueController.text.trim().isEmpty
          ? null
          : _issueController.text.trim(),
      afterPhotos: _afterPhotos,
      beforePhotos: _beforePhotos,
      duringPhotos: _duringPhotos,
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding:
          EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
          child: Form(
            key: _formKey,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Center(
                    child: Container(
                      width: 40,
                      height: 4,
                      decoration: BoxDecoration(
                          color: AppColors.border,
                          borderRadius: BorderRadius.circular(4)),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text('Complete Task',
                      style: Theme.of(context)
                          .textTheme
                          .titleLarge
                          ?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  const Text(
                    'This submits a Daily Work log linked to this work order — CPMSPro uses it to move the work order forward.',
                    style: TextStyle(color: AppColors.textSecondary),
                  ),
                  const SizedBox(height: 20),
                  const Text('Work Status',
                      style: TextStyle(fontWeight: FontWeight.w600)),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    initialValue: _workStatus,
                    items: _kWorkStatuses
                        .map((s) => DropdownMenuItem(value: s, child: Text(s)))
                        .toList(),
                    onChanged: (v) =>
                        setState(() => _workStatus = v ?? 'Completed'),
                  ),
                  const SizedBox(height: 16),
                  const Text('Work Description',
                      style: TextStyle(fontWeight: FontWeight.w600)),
                  const SizedBox(height: 8),
                  TextFormField(
                    controller: _descriptionController,
                    maxLines: 3,
                    decoration: const InputDecoration(
                        hintText: 'Describe the work completed'),
                    validator: (v) => (v == null || v.trim().isEmpty)
                        ? 'Please describe the completed work'
                        : null,
                  ),
                  const SizedBox(height: 16),
                  const Text('Materials Used (optional)',
                      style: TextStyle(fontWeight: FontWeight.w600)),
                  const SizedBox(height: 8),
                  TextFormField(
                    controller: _materialsController,
                    decoration: const InputDecoration(
                        hintText: 'e.g. Rubber washer x1, PTFE tape'),
                  ),
                  const SizedBox(height: 16),
                  const Text('Issue Notes (optional)',
                      style: TextStyle(fontWeight: FontWeight.w600)),
                  const SizedBox(height: 8),
                  TextFormField(
                    controller: _issueController,
                    decoration: const InputDecoration(
                        hintText: 'Anything blocking full completion'),
                  ),
                  const SizedBox(height: 20),
                  _PhotoGroupField(
                    label: 'BEFORE (optional)',
                    photos: _beforePhotos,
                    onAdd: () => _addPhoto(_beforePhotos),
                    onRemove: (f) => setState(() => _beforePhotos.remove(f)),
                  ),
                  const SizedBox(height: 16),
                  _PhotoGroupField(
                    label: 'DURING (optional)',
                    photos: _duringPhotos,
                    onAdd: () => _addPhoto(_duringPhotos),
                    onRemove: (f) => setState(() => _duringPhotos.remove(f)),
                  ),
                  const SizedBox(height: 16),
                  _PhotoGroupField(
                    label: _workStatus == 'Completed'
                        ? 'AFTER (required)'
                        : 'AFTER (optional)',
                    photos: _afterPhotos,
                    onAdd: () => _addPhoto(_afterPhotos),
                    onRemove: (f) => setState(() => _afterPhotos.remove(f)),
                  ),
                  if (_evidenceError != null) ...[
                    const SizedBox(height: 8),
                    Text(_evidenceError!,
                        style: const TextStyle(
                            color: AppColors.danger, fontSize: 12.5)),
                  ],
                  const SizedBox(height: 24),
                  ElevatedButton(
                    onPressed: _submit,
                    child: const Text('Submit Daily Work'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _PhotoGroupField extends StatelessWidget {
  const _PhotoGroupField(
      {required this.label,
      required this.photos,
      required this.onAdd,
      required this.onRemove});

  final String label;
  final List<File> photos;
  final VoidCallback onAdd;
  final ValueChanged<File> onRemove;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 8),
        Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final photo in photos)
              Stack(
                clipBehavior: Clip.none,
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(14),
                    child: Image.file(photo,
                        width: 84, height: 84, fit: BoxFit.cover),
                  ),
                  Positioned(
                    top: -6,
                    right: -6,
                    child: GestureDetector(
                      onTap: () => onRemove(photo),
                      child: Container(
                        width: 22,
                        height: 22,
                        decoration: const BoxDecoration(
                            color: AppColors.danger, shape: BoxShape.circle),
                        child: const Icon(Icons.close_rounded,
                            size: 14, color: Colors.white),
                      ),
                    ),
                  ),
                ],
              ),
            GestureDetector(
              onTap: onAdd,
              child: Container(
                width: 84,
                height: 84,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(14),
                  border:
                      Border.all(color: Theme.of(context).colorScheme.primary),
                  color: Theme.of(context)
                      .colorScheme
                      .primary
                      .withValues(alpha: 0.05),
                ),
                child: Icon(Icons.add_a_photo_outlined,
                    color: Theme.of(context).colorScheme.primary),
              ),
            ),
          ],
        ),
      ],
    );
  }
}
