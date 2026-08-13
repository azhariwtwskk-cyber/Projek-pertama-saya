import 'dart:io';

import '../domain/task_models.dart';

abstract class TasksRepository {
  Future<List<StaffTask>> fetchTasks();
  Future<StaffTask> fetchTask(String id);
  Future<StaffTask> acceptTask(String id);
  Future<StaffTask> startTask(String id);

  /// Uploads (or, if offline, queues) an AFTER evidence photo and links it
  /// to [taskId] / optionally [beforePhotoId] (section 12/13). Returns the
  /// resulting [EvidencePhoto] — with [EvidencePhoto.isLocalPending] true
  /// when it was written to the offline outbox instead of the network.
  Future<EvidencePhoto> uploadEvidence({
    required String taskId,
    required File file,
    String? beforePhotoId,
    double? gpsLat,
    double? gpsLng,
  });

  Future<StaffTask> completeTask({
    required String taskId,
    required String remarks,
    String? materialsUsed,
    int? timeSpentMinutes,
  });
}
