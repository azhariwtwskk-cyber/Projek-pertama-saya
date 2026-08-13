import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../../core/network/connectivity_service.dart';
import '../../auth/application/auth_providers.dart';
import '../../sync/application/sync_providers.dart';
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

final taskInboxTabProvider = StateProvider<TaskInboxTab>((ref) => TaskInboxTab.all);
final taskSearchQueryProvider = StateProvider<String>((ref) => '');

final tasksListProvider = FutureProvider.autoDispose<List<StaffTask>>((ref) async {
  return ref.watch(tasksRepositoryProvider).fetchTasks();
});

final filteredTasksProvider = Provider.autoDispose<AsyncValue<List<StaffTask>>>((ref) {
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
          return t.status == TaskStatus.accepted || t.status == TaskStatus.inProgress;
        case TaskInboxTab.completed:
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

final taskDetailProvider = FutureProvider.autoDispose.family<StaffTask, String>((ref, id) async {
  return ref.watch(tasksRepositoryProvider).fetchTask(id);
});

class TaskActionsController {
  TaskActionsController(this._ref);
  final Ref _ref;

  TasksRepository get _repo => _ref.read(tasksRepositoryProvider);

  Future<void> accept(String id) async {
    await _repo.acceptTask(id);
    _invalidate(id);
  }

  Future<void> start(String id) async {
    await _repo.startTask(id);
    _invalidate(id);
  }

  Future<void> uploadEvidence({
    required String taskId,
    required File file,
    String? beforePhotoId,
    double? gpsLat,
    double? gpsLng,
  }) async {
    await _repo.uploadEvidence(taskId: taskId, file: file, beforePhotoId: beforePhotoId, gpsLat: gpsLat, gpsLng: gpsLng);
    _invalidate(taskId);
  }

  Future<void> complete({
    required String taskId,
    required String remarks,
    String? materialsUsed,
    int? timeSpentMinutes,
  }) async {
    await _repo.completeTask(
      taskId: taskId,
      remarks: remarks,
      materialsUsed: materialsUsed,
      timeSpentMinutes: timeSpentMinutes,
    );
    _invalidate(taskId);
  }

  void _invalidate(String id) {
    _ref.invalidate(taskDetailProvider(id));
    _ref.invalidate(tasksListProvider);
  }
}

final taskActionsControllerProvider = Provider((ref) => TaskActionsController(ref));
