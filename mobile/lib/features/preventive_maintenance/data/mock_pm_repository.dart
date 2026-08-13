import 'dart:io';

import '../../../core/api/api_exception.dart';
import '../../../core/api/mock/mock_fixtures.dart';
import '../domain/pm_models.dart';
import 'pm_repository.dart';

class MockPmRepository implements PmRepository {
  final List<PmTask> _tasks = MockFixtures.instance.pmTasks;

  int _indexOf(String id) {
    final i = _tasks.indexWhere((t) => t.id == id);
    if (i == -1) throw const ApiException(ApiFailureType.notFound, 'PM task not found.');
    return i;
  }

  @override
  Future<List<PmTask>> fetchTasks() async {
    await Future.delayed(const Duration(milliseconds: 400));
    return List.unmodifiable(_tasks);
  }

  @override
  Future<PmTask> fetchTask(String id) async {
    await Future.delayed(const Duration(milliseconds: 300));
    return _tasks[_indexOf(id)];
  }

  @override
  Future<PmTask> toggleChecklistItem(String taskId, String itemId, bool checked) async {
    final i = _indexOf(taskId);
    final task = _tasks[i];
    final updatedChecklist = task.checklist.map((c) => c.id == itemId ? c.copyWith(isChecked: checked) : c).toList();
    _tasks[i] = PmTask(
      id: task.id,
      assetName: task.assetName,
      assetId: task.assetId,
      location: task.location,
      pmType: task.pmType,
      scheduledDate: task.scheduledDate,
      instructions: task.instructions,
      status: task.status,
      checklist: updatedChecklist,
      requiresPhotoEvidence: task.requiresPhotoEvidence,
    );
    return _tasks[i];
  }

  @override
  Future<PmTask> completeTask(String taskId, {required List<File> evidencePhotos, String? notes}) async {
    await Future.delayed(const Duration(milliseconds: 700));
    final i = _indexOf(taskId);
    final task = _tasks[i];
    if (!task.allMandatoryChecked) {
      throw const ApiException(ApiFailureType.validation, 'Please complete all mandatory checklist items first.');
    }
    if (task.requiresPhotoEvidence && evidencePhotos.isEmpty) {
      throw const ApiException(ApiFailureType.validation, 'Please attach evidence photos before completing this PM task.');
    }
    _tasks[i] = PmTask(
      id: task.id,
      assetName: task.assetName,
      assetId: task.assetId,
      location: task.location,
      pmType: task.pmType,
      scheduledDate: task.scheduledDate,
      instructions: task.instructions,
      status: PmStatus.completed,
      checklist: task.checklist,
      requiresPhotoEvidence: task.requiresPhotoEvidence,
    );
    return _tasks[i];
  }
}
