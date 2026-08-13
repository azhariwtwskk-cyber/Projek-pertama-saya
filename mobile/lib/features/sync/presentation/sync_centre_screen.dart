import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/database/app_database.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../application/sync_providers.dart';

/// Section 27: Sync Status screen — shows what's still queued, allows a
/// manual retry, and reports the last successful sync.
class SyncCentreScreen extends ConsumerStatefulWidget {
  const SyncCentreScreen({super.key});

  @override
  ConsumerState<SyncCentreScreen> createState() => _SyncCentreScreenState();
}

class _SyncCentreScreenState extends ConsumerState<SyncCentreScreen> {
  bool _retrying = false;

  Future<void> _retry() async {
    setState(() => _retrying = true);
    await ref.read(syncQueueControllerProvider.notifier).retrySync();
    if (mounted) setState(() => _retrying = false);
  }

  IconData _iconFor(PendingSyncType type) {
    switch (type) {
      case PendingSyncType.photoEvidence:
        return Icons.photo_camera_outlined;
      case PendingSyncType.dailyWork:
        return Icons.work_outline_rounded;
      case PendingSyncType.taskUpdate:
        return Icons.assignment_outlined;
      case PendingSyncType.pmCompletion:
        return Icons.build_circle_outlined;
      case PendingSyncType.attendance:
        return Icons.schedule_rounded;
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = ref.watch(syncQueueControllerProvider);
    final lastSynced = ref.watch(syncQueueControllerProvider.notifier).lastSyncedAt;
    final allSynced = items.isEmpty;

    return Scaffold(
      appBar: AppBar(title: const Text('Sync Centre')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          AppSectionCard(
            child: Column(
              children: [
                Icon(
                  allSynced ? Icons.check_circle_rounded : Icons.sync_problem_rounded,
                  color: allSynced ? AppColors.success : AppColors.warning,
                  size: 36,
                ),
                const SizedBox(height: 10),
                Text(
                  allSynced ? 'All Synced' : '${items.length} Item${items.length == 1 ? '' : 's'} Pending',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 17, color: allSynced ? AppColors.success : AppColors.warning),
                ),
                const SizedBox(height: 6),
                Text(
                  lastSynced == null ? 'No sync yet this session' : 'Last synced ${AppFormatters.timeAgo(lastSynced)}',
                  style: const TextStyle(color: AppColors.textSecondary, fontSize: 12.5),
                ),
                if (!allSynced) ...[
                  const SizedBox(height: 16),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      onPressed: _retrying ? null : _retry,
                      icon: _retrying
                          ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : const Icon(Icons.refresh_rounded, size: 18),
                      label: const Text('Retry Sync'),
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 20),
          if (items.isNotEmpty) ...[
            const SectionHeader(title: 'Pending Items'),
            for (final item in items)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: AppSectionCard(
                  child: Row(
                    children: [
                      Icon(_iconFor(item.type), color: AppColors.warning),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(item.summary, style: const TextStyle(fontWeight: FontWeight.w700)),
                            const SizedBox(height: 2),
                            Text(AppFormatters.timeAgo(item.createdAt), style: const TextStyle(color: AppColors.textSecondary, fontSize: 12)),
                            if (item.lastError != null)
                              Padding(
                                padding: const EdgeInsets.only(top: 4),
                                child: Text('Retry ${item.retryCount} · ${item.lastError}', style: const TextStyle(color: AppColors.danger, fontSize: 11)),
                              ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ] else
            const AppStateView(
              icon: Icons.cloud_done_outlined,
              title: 'Nothing to Sync',
              message: 'Everything you have submitted is safely stored on CPMSPro.',
            ),
        ],
      ),
    );
  }
}
