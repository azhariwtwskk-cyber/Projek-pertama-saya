import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/skeleton.dart';
import '../application/tasks_providers.dart';
import 'widgets/task_card.dart';

/// Unified Staff Task Inbox (section 9): combines work orders, inspection
/// corrective actions, PM, daily assignments and supervisor tasks into one
/// list, since that's how staff actually think about "my work today" —
/// not which CPMSPro module a task originated from.
class TaskInboxScreen extends ConsumerStatefulWidget {
  const TaskInboxScreen({super.key});

  @override
  ConsumerState<TaskInboxScreen> createState() => _TaskInboxScreenState();
}

class _TaskInboxScreenState extends ConsumerState<TaskInboxScreen> {
  final _searchController = TextEditingController();

  static const _tabs = [
    (TaskInboxTab.all, 'All'),
    (TaskInboxTab.newTasks, 'New'),
    (TaskInboxTab.inProgress, 'In Progress'),
    (TaskInboxTab.completed, 'Completed'),
    (TaskInboxTab.overdue, 'Overdue'),
  ];

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final selectedTab = ref.watch(taskInboxTabProvider);
    final filtered = ref.watch(filteredTasksProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('My Tasks'),
        actions: [
          IconButton(
            icon: const Icon(Icons.fact_check_outlined),
            tooltip: 'Work Order History',
            onPressed: () => context.push('/work-history'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(tasksListProvider),
        child: CustomScrollView(
          slivers: [
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: TextField(
                  controller: _searchController,
                  onChanged: (v) =>
                      ref.read(taskSearchQueryProvider.notifier).state = v,
                  decoration: const InputDecoration(
                    hintText: 'Search by task ID, title or location',
                    prefixIcon: Icon(Icons.search_rounded),
                  ),
                ),
              ),
            ),
            SliverToBoxAdapter(
              child: SizedBox(
                height: 44,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  itemCount: _tabs.length,
                  separatorBuilder: (_, __) => const SizedBox(width: 8),
                  itemBuilder: (context, i) {
                    final (tab, label) = _tabs[i];
                    final selected = tab == selectedTab;
                    return ChoiceChip(
                      label: Text(label),
                      selected: selected,
                      onSelected: (_) =>
                          ref.read(taskInboxTabProvider.notifier).state = tab,
                      selectedColor: Theme.of(context).colorScheme.primary,
                      labelStyle: TextStyle(
                        color:
                            selected ? Colors.white : AppColors.textSecondary,
                        fontWeight: FontWeight.w600,
                      ),
                      backgroundColor: Theme.of(context).cardTheme.color,
                      side: BorderSide(
                          color:
                              selected ? Colors.transparent : AppColors.border),
                    );
                  },
                ),
              ),
            ),
            const SliverToBoxAdapter(child: SizedBox(height: 12)),
            filtered.when(
              loading: () => const SliverPadding(
                padding: EdgeInsets.symmetric(horizontal: 16),
                sliver: SliverToBoxAdapter(child: SkeletonList(count: 4)),
              ),
              error: (e, _) => SliverFillRemaining(
                child: Center(
                    child: AppStateView.error(
                        onRetry: () => ref.invalidate(tasksListProvider))),
              ),
              data: (tasks) {
                if (tasks.isEmpty) {
                  return SliverFillRemaining(
                    child: Center(child: AppStateView.noTasksToday()),
                  );
                }
                return SliverPadding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                  sliver: SliverList.separated(
                    itemCount: tasks.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 12),
                    itemBuilder: (context, i) {
                      final task = tasks[i];
                      return TaskCard(
                          task: task,
                          onTap: () => context.push('/tasks/${task.id}'));
                    },
                  ),
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}
