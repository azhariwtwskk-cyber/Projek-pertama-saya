import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../../../../core/theme/app_theme.dart';
import '../../../../shared/widgets/full_screen_image_viewer.dart';
import '../../domain/task_models.dart';

class PhotoEvidenceGrid extends StatelessWidget {
  const PhotoEvidenceGrid({
    super.key,
    required this.photos,
    this.onAddPhoto,
    this.addLabel = 'Add Photo',
  });

  final List<EvidencePhoto> photos;
  final VoidCallback? onAddPhoto;
  final String addLabel;

  @override
  Widget build(BuildContext context) {
    final urls = photos.map((p) => p.displaySource).toList();
    return Wrap(
      spacing: 10,
      runSpacing: 10,
      children: [
        for (var i = 0; i < photos.length; i++)
          _PhotoThumb(
              photo: photos[i],
              onTap: () =>
                  FullScreenImageViewer.open(context, urls, initialIndex: i)),
        if (onAddPhoto != null)
          _AddPhotoTile(label: addLabel, onTap: onAddPhoto!),
      ],
    );
  }
}

class _PhotoThumb extends StatelessWidget {
  const _PhotoThumb({required this.photo, required this.onTap});
  final EvidencePhoto photo;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isLocal = photo.isLocalPending;
    return GestureDetector(
      onTap: onTap,
      child: Stack(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(14),
            child: isLocal
                ? Image.file(File(photo.displaySource),
                    width: 96,
                    height: 96,
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => _localFileFallback())
                : CachedNetworkImage(
                    imageUrl: photo.url,
                    width: 96,
                    height: 96,
                    fit: BoxFit.cover,
                    placeholder: (_, __) => Container(
                        width: 96, height: 96, color: AppColors.border),
                    errorWidget: (_, __, ___) => Container(
                        width: 96,
                        height: 96,
                        color: AppColors.border,
                        child: const Icon(Icons.broken_image_outlined)),
                  ),
          ),
          if (isLocal)
            Positioned(
              bottom: 4,
              left: 4,
              right: 4,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(
                    color: AppColors.warning,
                    borderRadius: BorderRadius.circular(6)),
                child: const Text('PENDING SYNC',
                    style: TextStyle(
                        fontSize: 8,
                        color: Colors.white,
                        fontWeight: FontWeight.w700)),
              ),
            ),
        ],
      ),
    );
  }

  Widget _localFileFallback() => Container(
      width: 96,
      height: 96,
      color: AppColors.border,
      child: const Icon(Icons.image_outlined));
}

class _AddPhotoTile extends StatelessWidget {
  const _AddPhotoTile({required this.label, required this.onTap});
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: 96,
        height: 96,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
              color: Theme.of(context).colorScheme.primary,
              style: BorderStyle.solid,
              width: 1.4),
          color: Theme.of(context).colorScheme.primary.withValues(alpha: 0.05),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.add_a_photo_outlined,
                color: Theme.of(context).colorScheme.primary, size: 22),
            const SizedBox(height: 4),
            Text(label,
                textAlign: TextAlign.center,
                style: TextStyle(
                    fontSize: 10,
                    color: Theme.of(context).colorScheme.primary,
                    fontWeight: FontWeight.w600)),
          ],
        ),
      ),
    );
  }
}
