import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/utils/evidence_picker.dart';
import '../application/daily_work_providers.dart';
import '../domain/daily_work_models.dart';

/// Section 17: Add Daily Work module.
class AddDailyWorkScreen extends ConsumerStatefulWidget {
  const AddDailyWorkScreen({super.key});

  @override
  ConsumerState<AddDailyWorkScreen> createState() => _AddDailyWorkScreenState();
}

class _AddDailyWorkScreenState extends ConsumerState<AddDailyWorkScreen> {
  final _formKey = GlobalKey<FormState>();
  final _titleController = TextEditingController();
  final _locationController = TextEditingController();
  final _descriptionController = TextEditingController();
  final _remarksController = TextEditingController();
  DailyWorkCategory _category = DailyWorkCategory.generalWork;
  TimeOfDay _startTime = TimeOfDay.now();
  TimeOfDay? _completionTime;
  final List<File> _photos = [];
  bool _submitting = false;

  @override
  void dispose() {
    _titleController.dispose();
    _locationController.dispose();
    _descriptionController.dispose();
    _remarksController.dispose();
    super.dispose();
  }

  Future<void> _pickTime({required bool isStart}) async {
    final picked = await showTimePicker(context: context, initialTime: isStart ? _startTime : (_completionTime ?? TimeOfDay.now()));
    if (picked == null) return;
    setState(() => isStart ? _startTime = picked : _completionTime = picked);
  }

  Future<void> _addPhoto() async {
    final file = await EvidencePicker.showPickerSheet(context);
    if (file != null) setState(() => _photos.add(file));
  }

  DateTime _combine(TimeOfDay time) {
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day, time.hour, time.minute);
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _submitting = true);
    try {
      await ref.read(dailyWorkControllerProvider).submit(
            title: _titleController.text.trim(),
            category: _category,
            location: _locationController.text.trim(),
            description: _descriptionController.text.trim(),
            startTime: _combine(_startTime),
            completionTime: _completionTime == null ? null : _combine(_completionTime!),
            photos: _photos,
            remarks: _remarksController.text.trim().isEmpty ? null : _remarksController.text.trim(),
          );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Daily work recorded.')));
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Failed to save. Please try again.'), backgroundColor: AppColors.danger));
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Add Daily Work')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
          children: [
            const _Label('Work Title'),
            TextFormField(
              controller: _titleController,
              decoration: const InputDecoration(hintText: 'e.g. Cleaned Block A lobby'),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Enter a work title' : null,
            ),
            const SizedBox(height: 16),
            const _Label('Work Category'),
            DropdownButtonFormField<DailyWorkCategory>(
              initialValue: _category,
              items: DailyWorkCategory.values
                  .map((c) => DropdownMenuItem(value: c, child: Text(c.label)))
                  .toList(),
              onChanged: (v) => setState(() => _category = v!),
            ),
            const SizedBox(height: 16),
            const _Label('Location'),
            TextFormField(
              controller: _locationController,
              decoration: const InputDecoration(hintText: 'e.g. Block A — Lobby'),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Enter a location' : null,
            ),
            const SizedBox(height: 16),
            const _Label('Description'),
            TextFormField(
              controller: _descriptionController,
              maxLines: 3,
              decoration: const InputDecoration(hintText: 'Describe the work performed'),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Enter a description' : null,
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: _TimePickerField(label: 'Start Time', time: _startTime, onTap: () => _pickTime(isStart: true)),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _TimePickerField(label: 'Completion Time', time: _completionTime, onTap: () => _pickTime(isStart: false)),
                ),
              ],
            ),
            const SizedBox(height: 16),
            const _Label('Photos'),
            Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                for (final photo in _photos)
                  ClipRRect(
                    borderRadius: BorderRadius.circular(14),
                    child: Image.file(photo, width: 84, height: 84, fit: BoxFit.cover),
                  ),
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
            const SizedBox(height: 16),
            const _Label('Remarks (optional)'),
            TextFormField(controller: _remarksController, maxLines: 2),
            const SizedBox(height: 28),
            ElevatedButton(
              onPressed: _submitting ? null : _submit,
              child: _submitting
                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                  : const Text('Save Daily Work'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Label extends StatelessWidget {
  const _Label(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(text, style: const TextStyle(fontWeight: FontWeight.w600)),
      );
}

class _TimePickerField extends StatelessWidget {
  const _TimePickerField({required this.label, required this.time, required this.onTap});
  final String label;
  final TimeOfDay? time;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _Label(label),
        InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: InputDecorator(
            decoration: const InputDecoration(),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(time == null ? '—' : DateFormat('h:mm a').format(DateTime(0, 0, 0, time!.hour, time!.minute))),
                const Icon(Icons.access_time_rounded, size: 18, color: AppColors.textSecondary),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
