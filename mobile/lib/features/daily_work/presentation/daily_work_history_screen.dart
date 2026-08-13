import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../../../shared/widgets/skeleton.dart';
import '../application/daily_work_providers.dart';
import '../domain/daily_work_models.dart';

/// Section 18: professional work history with Today/Week/Month/Custom
/// filters. Verified entries render read-only.
class DailyWorkHistoryScreen extends ConsumerWidget {
  const DailyWorkHistoryScreen({super.key});

  static const _filters = [
    (DailyWorkFilter.today, 'Today'),
    (DailyWorkFilter.thisWeek, 'This Week'),
    (DailyWorkFilter.thisMonth, 'This Month'),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selected = ref.watch(dailyWorkFilterProvider);
    final entriesAsync = ref.watch(dailyWorkEntriesProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Work History'),
        actions: [
          IconButton(
            icon: const Icon(Icons.add_rounded),
            onPressed: () => context.push('/daily-work/add'),
          ),
        ],
      ),
      body: Column(
        children: [
          SizedBox(
            height: 48,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              itemCount: _filters.length,
              separatorBuilder: (_, __) => const SizedBox(width: 8),
              itemBuilder: (context, i) {
                final (filter, label) = _filters[i];
                final isSelected = filter == selected;
                return ChoiceChip(
                  label: Text(label),
                  selected: isSelected,
                  onSelected: (_) => ref.read(dailyWorkFilterProvider.notifier).state = filter,
                  selectedColor: Theme.of(context).colorScheme.primary,
                  labelStyle: TextStyle(color: isSelected ? Colors.white : AppColors.textSecondary, fontWeight: FontWeight.w600),
                );
              },
            ),
          ),
          Expanded(
            child: entriesAsync.when(
              loading: () => const Padding(padding: EdgeInsets.all(16), child: SkeletonList()),
              error: (e, _) => Center(child: AppStateView.error(onRetry: () => ref.invalidate(dailyWorkEntriesProvider))),
              data: (entries) {
                if (entries.isEmpty) {
                  return const Center(
                    child: AppStateView(
                      icon: Icons.work_history_outlined,
                      title: 'No Work Recorded',
                      message: 'Entries you add will appear here for this period.',
                    ),
                  );
                }
                return ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                  itemCount: entries.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (context, i) => _DailyWorkTile(entry: entries[i]),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _DailyWorkTile extends StatelessWidget {
  const _DailyWorkTile({required this.entry});
  final DailyWorkEntry entry;

  @override
  Widget build(BuildContext context) {
    return AppSectionCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(entry.title, style: const TextStyle(fontWeight: FontWeight.w700))),
              if (entry.isReadOnly)
                const Icon(Icons.lock_outline_rounded, size: 15, color: AppColors.textSecondary),
            ],
          ),
          const SizedBox(height: 6),
          Text('${entry.category.label} · ${entry.location}', style: const TextStyle(fontSize: 12.5, color: AppColors.textSecondary)),
          const SizedBox(height: 10),
          Row(
            children: [
              Text(DateFormat('d MMM, h:mm a').format(entry.startTime), style: const TextStyle(fontSize: 12)),
              const Spacer(),
              if (entry.duration != null)
                Text('${entry.duration!.inHours}h ${entry.duration!.inMinutes.remainder(60)}m', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
              const SizedBox(width: 12),
              const Icon(Icons.image_outlined, size: 14, color: AppColors.textSecondary),
              const SizedBox(width: 2),
              Text('${entry.photoCount}', style: const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
            ],
          ),
        ],
      ),
    );
  }
}
