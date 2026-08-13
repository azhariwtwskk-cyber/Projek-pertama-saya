import 'package:flutter/material.dart';

import '../../../../core/theme/app_theme.dart';

class CompleteTaskResult {
  const CompleteTaskResult({required this.remarks, this.materialsUsed, this.timeSpentMinutes});
  final String remarks;
  final String? materialsUsed;
  final int? timeSpentMinutes;
}

/// Completion form shown when staff presses "Complete Task" (section 15):
/// remarks, materials used, time spent, then "Submit for Verification".
class CompleteTaskSheet extends StatefulWidget {
  const CompleteTaskSheet({super.key});

  static Future<CompleteTaskResult?> show(BuildContext context) {
    return showModalBottomSheet<CompleteTaskResult>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (_) => const CompleteTaskSheet(),
    );
  }

  @override
  State<CompleteTaskSheet> createState() => _CompleteTaskSheetState();
}

class _CompleteTaskSheetState extends State<CompleteTaskSheet> {
  final _formKey = GlobalKey<FormState>();
  final _remarksController = TextEditingController();
  final _materialsController = TextEditingController();
  final _timeController = TextEditingController();

  @override
  void dispose() {
    _remarksController.dispose();
    _materialsController.dispose();
    _timeController.dispose();
    super.dispose();
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    Navigator.of(context).pop(CompleteTaskResult(
      remarks: _remarksController.text.trim(),
      materialsUsed: _materialsController.text.trim().isEmpty ? null : _materialsController.text.trim(),
      timeSpentMinutes: int.tryParse(_timeController.text.trim()),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
          child: Form(
            key: _formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(color: AppColors.border, borderRadius: BorderRadius.circular(4)),
                  ),
                ),
                const SizedBox(height: 16),
                Text('Complete Task', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
                const SizedBox(height: 4),
                const Text('Add completion details before submitting for verification.',
                    style: TextStyle(color: AppColors.textSecondary)),
                const SizedBox(height: 20),
                const Text('Completion Remarks', style: TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 8),
                TextFormField(
                  controller: _remarksController,
                  maxLines: 3,
                  decoration: const InputDecoration(hintText: 'Describe the work completed'),
                  validator: (v) => (v == null || v.trim().isEmpty) ? 'Please describe the completed work' : null,
                ),
                const SizedBox(height: 16),
                const Text('Materials Used (optional)', style: TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 8),
                TextFormField(
                  controller: _materialsController,
                  decoration: const InputDecoration(hintText: 'e.g. Rubber washer x1, PTFE tape'),
                ),
                const SizedBox(height: 16),
                const Text('Time Spent — minutes (optional)', style: TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 8),
                TextFormField(
                  controller: _timeController,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(hintText: 'e.g. 45'),
                ),
                const SizedBox(height: 24),
                ElevatedButton(
                  onPressed: _submit,
                  child: const Text('Submit for Verification'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
