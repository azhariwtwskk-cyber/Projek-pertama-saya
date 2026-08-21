import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/full_screen_image_viewer.dart';
import '../../../shared/widgets/section_header.dart';
import '../../../shared/widgets/skeleton.dart';
import '../application/work_history_providers.dart';
import '../domain/work_history_models.dart';

/// Work Order History — the real management verification record for a
/// staff member's completed work orders. This is a distinct screen and
/// route (`/work-history`) from Daily Work History (`/daily-work/history`,
/// the staff member's own log) and must never be merged with it (see
/// mobile/docs/INTEGRATION_REPAIR_REPORT.md).
class WorkHistoryScreen extends ConsumerWidget {
  const WorkHistoryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final historyAsync = ref.watch(workHistoryProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Work Order History')),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(workHistoryProvider),
        child: historyAsync.when(
          loading: () =>
              const Padding(padding: EdgeInsets.all(16), child: SkeletonList()),
          error: (e, _) => Center(
              child: AppStateView.error(
                  onRetry: () => ref.invalidate(workHistoryProvider))),
          data: (items) {
            if (items.isEmpty) {
              return const Center(
                child: AppStateView(
                  icon: Icons.fact_check_outlined,
                  title: 'No Work Order History',
                  message:
                      'Completed work orders and their verification status will appear here.',
                ),
              );
            }
            return ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
              itemCount: items.length,
              separatorBuilder: (_, __) => const SizedBox(height: 12),
              itemBuilder: (context, i) =>
                  _WorkOrderHistoryCard(item: items[i]),
            );
          },
        ),
      ),
    );
  }
}

class _VerificationStyle {
  const _VerificationStyle(this.label, this.color, this.icon);
  final String label;
  final Color color;
  final IconData icon;
}

_VerificationStyle _styleFor(WorkOrderVerificationStatus status) {
  switch (status) {
    case WorkOrderVerificationStatus.verified:
      return const _VerificationStyle(
          'Verified by Management', AppColors.success, Icons.verified_rounded);
    case WorkOrderVerificationStatus.rejected:
      return const _VerificationStyle(
          'Rejected – Action Required', AppColors.danger, Icons.error_rounded);
    case WorkOrderVerificationStatus.pendingVerification:
      return const _VerificationStyle('Pending Verification', AppColors.warning,
          Icons.hourglass_top_rounded);
    case WorkOrderVerificationStatus.inProgress:
      return const _VerificationStyle(
          'In Progress', AppColors.info, Icons.autorenew_rounded);
  }
}

class _WorkOrderHistoryCard extends StatefulWidget {
  const _WorkOrderHistoryCard({required this.item});
  final WorkOrderHistoryItem item;

  @override
  State<_WorkOrderHistoryCard> createState() => _WorkOrderHistoryCardState();
}

class _WorkOrderHistoryCardState extends State<_WorkOrderHistoryCard> {
  bool _expanded = false;

  void _openPhotos(List<WorkHistoryPhoto> photos, int index) {
    FullScreenImageViewer.open(
      context,
      photos.map((p) => p.url).toList(),
      initialIndex: index,
      captions: photos.map((p) => p.type).toList(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    final style = _styleFor(item.verificationStatus);

    return AppSectionCard(
      onTap: () => setState(() => _expanded = !_expanded),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(item.reference,
                    style: const TextStyle(
                        fontWeight: FontWeight.w700,
                        color: AppColors.textSecondary,
                        fontSize: 12.5)),
              ),
              Icon(style.icon, size: 16, color: style.color),
              const SizedBox(width: 6),
              Text(style.label,
                  style: TextStyle(
                      color: style.color,
                      fontWeight: FontWeight.w700,
                      fontSize: 12.5)),
            ],
          ),
          const SizedBox(height: 6),
          Text(item.title,
              style: Theme.of(context)
                  .textTheme
                  .titleSmall
                  ?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Row(
            children: [
              const Icon(Icons.place_outlined,
                  size: 14, color: AppColors.textSecondary),
              const SizedBox(width: 4),
              Expanded(
                  child: Text(item.location,
                      style: const TextStyle(
                          color: AppColors.textSecondary, fontSize: 12.5))),
            ],
          ),
          if (item.completedAt != null) ...[
            const SizedBox(height: 4),
            Text(
              'Completed ${DateFormat('d MMM yyyy, h:mm a').format(item.completedAt!)}',
              style:
                  const TextStyle(color: AppColors.textSecondary, fontSize: 12),
            ),
          ],
          if (item.verificationStatus == WorkOrderVerificationStatus.rejected &&
              item.rejectionReason != null) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: AppColors.danger.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(10),
                border:
                    Border.all(color: AppColors.danger.withValues(alpha: 0.25)),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.info_outline_rounded,
                      size: 16, color: AppColors.danger),
                  const SizedBox(width: 8),
                  Expanded(
                      child: Text(item.rejectionReason!,
                          style: const TextStyle(
                              fontSize: 12.5, color: AppColors.danger))),
                ],
              ),
            ),
          ],
          if (item.verificationStatus == WorkOrderVerificationStatus.verified &&
              item.verifiedBy != null) ...[
            const SizedBox(height: 6),
            Text('Verified by ${item.verifiedBy}',
                style: const TextStyle(
                    fontSize: 12, color: AppColors.textSecondary)),
          ],
          // Evidence photos are the whole point of this screen — they must
          // always be visible, not hidden behind an extra tap-to-expand
          // (that's what made real-device testers report "images not
          // showing" even after the backend URL fix).
          if (item.photos.isNotEmpty) ...[
            const SizedBox(height: 10),
            for (final type in const [
              'Before',
              'During',
              'After',
              'Supporting'
            ])
              if (item.photosOfType(type).isNotEmpty)
                _PhotoRow(
                    label: type.toUpperCase(),
                    photos: item.photosOfType(type),
                    onTap: _openPhotos),
          ] else ...[
            const SizedBox(height: 10),
            Row(
              children: [
                const Icon(Icons.image_not_supported_outlined,
                    size: 14, color: AppColors.textSecondary),
                const SizedBox(width: 6),
                Text('No evidence photos found for this work order',
                    style: TextStyle(
                        fontSize: 11.5,
                        color: AppColors.textSecondary.withValues(alpha: 0.8))),
              ],
            ),
          ],
          if (_expanded) ...[
            const Divider(height: 24),
            if (item.completionNotes != null) ...[
              const Text('Staff Remarks',
                  style: TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 12,
                      color: AppColors.textSecondary)),
              const SizedBox(height: 4),
              Text(item.completionNotes!, style: const TextStyle(height: 1.4)),
              const SizedBox(height: 14),
            ],
            if (item.dailyWorkEntries.isNotEmpty) ...[
              const SizedBox(height: 6),
              const Text('Daily Work Submissions',
                  style: TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 12,
                      color: AppColors.textSecondary)),
              const SizedBox(height: 6),
              for (final entry in item.dailyWorkEntries)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                      '${entry.reference} · ${entry.date} · ${entry.status}',
                      style: const TextStyle(fontSize: 12.5)),
                ),
            ],
          ] else if (item.completionNotes != null ||
              item.dailyWorkEntries.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text('Tap to view remarks and submission history',
                  style: TextStyle(
                      fontSize: 11.5,
                      color: AppColors.textSecondary.withValues(alpha: 0.8))),
            ),
        ],
      ),
    );
  }
}

class _PhotoRow extends StatelessWidget {
  const _PhotoRow(
      {required this.label, required this.photos, required this.onTap});
  final String label;
  final List<WorkHistoryPhoto> photos;
  final void Function(List<WorkHistoryPhoto> photos, int index) onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label,
              style: const TextStyle(
                  fontWeight: FontWeight.w700,
                  fontSize: 12,
                  color: AppColors.textSecondary)),
          const SizedBox(height: 8),
          SizedBox(
            height: 80,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: photos.length,
              separatorBuilder: (_, __) => const SizedBox(width: 8),
              itemBuilder: (context, i) => GestureDetector(
                onTap: () => onTap(photos, i),
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(12),
                  child: Image.network(photos[i].url,
                      width: 80,
                      height: 80,
                      fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => Container(
                          width: 80,
                          height: 80,
                          color: AppColors.border,
                          child: const Icon(Icons.broken_image_outlined))),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
