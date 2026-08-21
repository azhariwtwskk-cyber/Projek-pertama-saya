import 'dart:io';

import '../domain/pm_models.dart';

abstract class PmRepository {
  Future<List<PmTask>> fetchTasks();
  Future<PmTask> fetchTask(String id);
  Future<PmTask> toggleChecklistItem(
      String taskId, String itemId, bool checked);
  Future<PmTask> completeTask(String taskId,
      {required List<File> evidencePhotos, String? notes});
}
