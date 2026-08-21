import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../auth/application/auth_providers.dart';
import '../data/api_pm_repository.dart';
import '../data/mock_pm_repository.dart';
import '../data/pm_repository.dart';
import '../domain/pm_models.dart';

final pmRepositoryProvider = Provider<PmRepository>((ref) {
  if (AppConfig.useMockApi) return MockPmRepository();
  return ApiPmRepository(ref.watch(apiClientProvider));
});

final pmTasksProvider = FutureProvider.autoDispose<List<PmTask>>((ref) {
  return ref.watch(pmRepositoryProvider).fetchTasks();
});

final pmTaskDetailProvider =
    FutureProvider.autoDispose.family<PmTask, String>((ref, id) {
  return ref.watch(pmRepositoryProvider).fetchTask(id);
});

class PmActionsController {
  PmActionsController(this._ref);
  final Ref _ref;

  Future<void> toggleChecklistItem(
      String taskId, String itemId, bool checked) async {
    await _ref
        .read(pmRepositoryProvider)
        .toggleChecklistItem(taskId, itemId, checked);
    _ref.invalidate(pmTaskDetailProvider(taskId));
  }

  Future<void> complete(String taskId,
      {required List<File> evidencePhotos, String? notes}) async {
    await _ref
        .read(pmRepositoryProvider)
        .completeTask(taskId, evidencePhotos: evidencePhotos, notes: notes);
    _ref.invalidate(pmTaskDetailProvider(taskId));
    _ref.invalidate(pmTasksProvider);
  }
}

final pmActionsControllerProvider = Provider((ref) => PmActionsController(ref));
