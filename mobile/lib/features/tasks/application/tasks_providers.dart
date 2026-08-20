import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../../core/network/connectivity_service.dart';
import '../../auth/application/auth_providers.dart';
import '../../dashboard/application/dashboard_providers.dart';
import '../../sync/application/sync_providers.dart';
import '../../work_history/application/work_history_providers.dart';
import '../data/api_tasks_repository.dart';
import '../data/mock_tasks_repository.dart';
import '../data/tasks_repository.dart';
import '../domain/task_models.dart';

final tasksRepositoryProvider = Provider<TasksRepository>((ref) {
  if (AppConfig.useMockApi) {
    return MockTasksRepository(
      connectivity: ref.watch(connectivityServiceProvider),
      enqueue: ref.read(syncQueueControllerProvider.notifier).enqueue,
    );
  }
  return ApiTasksRepository(ref.watch(apiClientProvider));
});

enum TaskInboxTab { all, newTasks, inProgress, completed, overdue }

final taskInboxTabProvider =
    StateProvider<TaskInboxTab>((ref) => TaskInboxTab.all);
final taskSearchQueryProvider = StateProvider<String>((ref) => '');

final tasksListProvider =
    FutureProvider.autoDispose<List<StaffTask>>((ref) async {
  return ref.watch(tasksRepositoryProvider).fetchTasks();
});

final filteredTasksProvider =
    Provider.autoDispose<AsyncValue<List<StaffTask>>>((ref) {
  final tab = ref.watch(taskInboxTabProvider);
  final query = ref.watch(taskSearchQueryProvider).toLowerCase();
  final tasksAsync = ref.watch(tasksListProvider);

  return tasksAsync.whenData((tasks) {
    var filtered = tasks.where((t) {
      switch (tab) {
        case TaskInboxTab.all:
          return true;
        case TaskInboxTab.newTasks:
          return t.status == TaskStatus.newTask;
        case TaskInboxTab.inProgress:
          return t.status == TaskStatus.accepted ||
              t.status == TaskStatus.inProgress;
        case TaskInboxTab.completed:
          // The real backend collapses "Completed" and "Verified" work
          // orders into one `completed` bucket (taskStatusFromString maps
          // it to TaskStatus.verified) — workCompleted/pendingVerification
          // never occur for a real work order but are matched too so mock
          // demo data still filters correctly.
          return t.status == TaskStatus.workCompleted ||
              t.status == TaskStatus.pendingVerification ||
              t.status == TaskStatus.verified;
        case TaskInboxTab.overdue:
          return t.isOverdue;
      }
    }).toList();

    if (query.isNotEmpty) {
      filtered = filtered
          .where((t) =>
              t.title.toLowerCase().contains(query) ||
              t.taskNumber.toLowerCase().contains(query) ||
              t.location.toLowerCase().contains(query))
          .toList();
    }
    filtered.sort((a, b) => a.dueDate.compareTo(b.dueDate));
    return filtered;
  });
});

final taskDetailProvider =
    FutureProvider.autoDispose.family<StaffTask, String>((ref, id) async {
  return ref.watch(tasksRepositoryProvider).fetchTask(id);
});

class TaskActionsController {
  TaskActionsController(this._ref);
  final Ref _ref;

  TasksRepository get _repo => _ref.read(tasksRepositoryProvider);

  Future<EvidencePhoto> uploadEvidence({
    required String taskId,
    required File file,
    required String imageType,
  }) async {
    final photo = await _repo.uploadEvidence(
        taskId: taskId, file: file, imageType: imageType);
    _invalidate(taskId);
    return photo;
  }

  Future<void> complete({
    required StaffTask task,
    required String workDescription,
    required String workStatus,
    String? materialsUsed,
    String? issueNotes,
    required List<File> afterPhotos,
    List<File> beforePhotos = const [],
    List<File> duringPhotos = const [],
  }) async {
    await _repo.completeTask(
      task: task,
      workDescription: workDescription,
      workStatus: workStatus,
      materialsUsed: materialsUsed,
      issueNotes: issueNotes,
      afterPhotos: afterPhotos,
      beforePhotos: beforePhotos,
      duringPhotos: duringPhotos,
    );
    _invalidate(task.id);
  }

  /// A successful submit must never leave stale cached UI behind — this
  /// work order's own status can change (the active task queue), its
  /// verification record now exists or has changed (Work Order History),
  /// and Home's KPI counts/priority card read from the same backend rows
  /// (dashboard.php's `tasks[]` is the same query as `staff/tasks.php`).
  /// Missing any one of these three is exactly what made a just-submitted
  /// task look like it had vanished on a real device.
  void _invalidate(String id) {
    _ref.invalidate(taskDetailProvider(id));
    _ref.invalidate(tasksListProvider);
    _ref.invalidate(workHistoryProvider);
    _ref.invalidate(dashboardDataProvider);
  }
}

final taskActionsControllerProvider =
    Provider((ref) => TaskActionsController(ref));
