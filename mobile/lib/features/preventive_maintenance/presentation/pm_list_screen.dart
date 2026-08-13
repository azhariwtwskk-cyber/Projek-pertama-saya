import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../../../shared/widgets/skeleton.dart';
import '../application/pm_providers.dart';
import '../domain/pm_models.dart';

/// Section 22: PM module — Today / Upcoming / Overdue / Completed.
class PmListScreen extends ConsumerStatefulWidget {
  const PmListScreen({super.key});

  @override
  ConsumerState<PmListScreen> createState() => _PmListScreenState();
}

class _PmListScreenState extends ConsumerState<PmListScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabController = TabController(length: 4, vsync: this);

  static const _statuses = [PmStatus.today, PmStatus.upcoming, PmStatus.overdue, PmStatus.completed];

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tasksAsync = ref.watch(pmTasksProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Preventive Maintenance'),
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          tabs: const [Tab(text: 'Today'), Tab(text: 'Upcoming'), Tab(text: 'Overdue'), Tab(text: 'Completed')],
        ),
      ),
      body: tasksAsync.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: SkeletonList()),
        error: (e, _) => Center(child: AppStateView.error(onRetry: () => ref.invalidate(pmTasksProvider))),
        data: (tasks) => TabBarView(
          controller: _tabController,
          children: _statuses.map((status) {
            final filtered = tasks.where((t) => t.status == status).toList();
            if (filtered.isEmpty) {
              return const Center(
                child: AppStateView(
                  icon: Icons.build_circle_outlined,
                  title: 'No PM Tasks',
                  message: 'Nothing here right now.',
                ),
              );
            }
            return RefreshIndicator(
              onRefresh: () async => ref.invalidate(pmTasksProvider),
              child: ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: filtered.length,
                separatorBuilder: (_, __) => const SizedBox(height: 12),
                itemBuilder: (context, i) => _PmCard(task: filtered[i], onTap: () => context.push('/pm/${filtered[i].id}')),
              ),
            );
          }).toList(),
        ),
      ),
    );
  }
}

class _PmCard extends StatelessWidget {
  const _PmCard({required this.task, required this.onTap});
  final PmTask task;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final doneCount = task.checklist.where((c) => c.isChecked).length;
    return AppSectionCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(task.assetName, style: const TextStyle(fontWeight: FontWeight.w700))),
              Text(task.assetId, style: const TextStyle(color: AppColors.textSecondary, fontSize: 12)),
            ],
          ),
          const SizedBox(height: 4),
          Text('${task.pmType} · ${task.location}', style: const TextStyle(fontSize: 12.5, color: AppColors.textSecondary)),
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(DateFormat('d MMM, h:mm a').format(task.scheduledDate), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
              Text('$doneCount/${task.checklist.length} checklist', style: const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
            ],
          ),
        ],
      ),
    );
  }
}
