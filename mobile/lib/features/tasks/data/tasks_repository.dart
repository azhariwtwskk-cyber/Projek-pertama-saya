import 'dart:io';

import '../domain/task_models.dart';

abstract class TasksRepository {
  Future<List<StaffTask>> fetchTasks();
  Future<StaffTask> fetchTask(String id);

  /// Uploads (or, if offline, queues) an evidence photo tied to a work
  /// order. [imageType] must be one of the real backend's
  /// `staff/task-photo.php` values: `Before`, `During`, `After` or
  /// `Supporting`. There is no `before_photo_id` link on the real
  /// backend — photos are grouped purely by [imageType], never linked to
  /// a specific earlier photo (see docs/INTEGRATION_REPAIR_REPORT.md).
  Future<EvidencePhoto> uploadEvidence({
    required String taskId,
    required File file,
    required String imageType,
  });

  /// Completes a work order the real way: by submitting a Daily Work log
  /// linked to it (`work_order_id`), which is what actually advances
  /// `work_orders.status` server-side — there is no discrete "complete"
  /// endpoint. [afterPhotos] must contain at least one file when
  /// [workStatus] is `Completed` (the backend enforces this too).
  Future<void> completeTask({
    required StaffTask task,
    required String workDescription,
    required String workStatus,
    String? materialsUsed,
    String? issueNotes,
    required List<File> afterPhotos,
    List<File> beforePhotos,
    List<File> duringPhotos,
  });
}
