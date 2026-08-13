import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../features/tasks/presentation/widgets/camera_capture_screen.dart';

/// Shared "Take Photo / Choose from Gallery" flow used by task evidence,
/// PM checklist evidence and daily work photos alike (sections 12/17/22),
/// so every capture entry point behaves identically.
class EvidencePicker {
  const EvidencePicker._();

  static Future<File?> _pickFromGallery() async {
    final picker = ImagePicker();
    final picked = await picker.pickImage(source: ImageSource.gallery, imageQuality: 90);
    return picked == null ? null : File(picked.path);
  }

  static Future<File?> captureViaCamera(BuildContext context) {
    return Navigator.of(context).push<File?>(
      MaterialPageRoute(
        builder: (_) => const CameraCaptureScreen(onPickFromGallery: _pickFromGallery),
        fullscreenDialog: true,
      ),
    );
  }

  static Future<File?> pickFromGallery(BuildContext context) => _pickFromGallery();

  /// Bottom sheet offering both options — used where a full-screen camera
  /// launch isn't warranted (e.g. profile photo).
  static Future<File?> showPickerSheet(BuildContext context) async {
    return showModalBottomSheet<File?>(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (sheetContext) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 12),
            ListTile(
              leading: const Icon(Icons.camera_alt_outlined),
              title: const Text('Take Photo'),
              onTap: () async {
                final file = await captureViaCamera(context);
                if (sheetContext.mounted) Navigator.of(sheetContext).pop(file);
              },
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Choose from Gallery'),
              onTap: () async {
                final file = await _pickFromGallery();
                if (sheetContext.mounted) Navigator.of(sheetContext).pop(file);
              },
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }
}
