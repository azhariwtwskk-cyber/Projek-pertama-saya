import 'dart:io';

import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/mock/mock_fixtures.dart';
import '../../../core/database/app_database.dart';
import '../../../core/network/connectivity_service.dart';
import '../../../core/utils/image_utils.dart';
import '../domain/task_models.dart';
import 'tasks_repository.dart';

class MockTasksRepository implements TasksRepository {
  MockTasksRepository({
    required ConnectivityService connectivity,
    required Future<void> Function(PendingSyncItem) enqueue,
  })  : _connectivity = connectivity,
        _enqueue = enqueue;

  final ConnectivityService _connectivity;
  final Future<void> Function(PendingSyncItem) _enqueue;
  final List<StaffTask> _tasks = MockFixtures.instance.tasks;

  int _indexOf(String id) {
    final i = _tasks.indexWhere((t) => t.id == id);
    if (i == -1) {
      throw const ApiException(ApiFailureType.notFound, 'Task not found.');
    }
    return i;
  }

  @override
  Future<List<StaffTask>> fetchTasks() async {
    await Future.delayed(const Duration(milliseconds: 500));
    return List.unmodifiable(_tasks);
  }

  @override
  Future<StaffTask> fetchTask(String id) async {
    await Future.delayed(const Duration(milliseconds: 350));
    return _tasks[_indexOf(id)];
  }

  @override
  Future<EvidencePhoto> uploadEvidence({
    required String taskId,
    required File file,
    required String imageType,
  }) async {
    final compressed = await ImageUtils.compressForUpload(file);
    final online = await _connectivity.isOnline;
    final i = _indexOf(taskId);

    if (!online) {
      // Persist the compressed file permanently (not just in the OS temp
      // dir) so it survives even if the app is killed before connectivity
      // returns, then queue the upload — never lose the staff photo.
      final docsDir = await getApplicationDocumentsDirectory();
      final permanentPath =
          p.join(docsDir.path, 'pending_evidence', p.basename(compressed.path));
      await Directory(p.dirname(permanentPath)).create(recursive: true);
      final permanentFile = await compressed.copy(permanentPath);

      final photo = EvidencePhoto(
        id: 'local_${const Uuid().v4()}',
        url: permanentFile.path,
        uploadedAt: DateTime.now(),
        isLocalPending: true,
        localPath: permanentFile.path,
      );
      _tasks[i] =
          _tasks[i].copyWith(afterPhotos: [..._tasks[i].afterPhotos, photo]);

      await _enqueue(PendingSyncItem(
        id: photo.id,
        type: PendingSyncType.photoEvidence,
        summary: '${_tasks[i].taskNumber} — $imageType photo',
        endpoint: '/cpms/api/v1/staff/task-photo.php',
        method: 'POST',
        payload: {
          'work_order_reference': taskId,
          'image_type': imageType,
          // Read by SyncHandlers to pick the correct multipart field
          // name for this endpoint (`photo`, not the generic `files[0]`).
          '_file_field': 'photo',
        },
        filePaths: [permanentFile.path],
        createdAt: DateTime.now(),
      ));
      return photo;
    }

    await Future.delayed(const Duration(milliseconds: 900));
    final photo = EvidencePhoto(
      id: 'evd_${const Uuid().v4()}',
      url: compressed.path,
      uploadedAt: DateTime.now(),
    );
    _tasks[i] =
        _tasks[i].copyWith(afterPhotos: [..._tasks[i].afterPhotos, photo]);
    return photo;
  }

  @override
  Future<void> completeTask({
    required StaffTask task,
    required String workDescription,
    required String workStatus,
    String? materialsUsed,
    String? issueNotes,
    required List<File> afterPhotos,
    List<File> beforePhotos = const [],
    List<File> duringPhotos = const [],
  }) async {
    await Future.delayed(const Duration(milliseconds: 700));
    final i = _indexOf(task.id);
    if (workStatus == 'Completed' &&
        afterPhotos.isEmpty &&
        _tasks[i].afterPhotos.isEmpty) {
      throw const ApiException(
        ApiFailureType.validation,
        'Please attach at least one AFTER photo before marking this task Completed.',
      );
    }
    _tasks[i] = _tasks[i].copyWith(
      status: workStatus == 'Completed'
          ? TaskStatus.verified
          : TaskStatus.inProgress,
      completionRemarks: workDescription,
      materialsUsed: materialsUsed,
      rejectionReason: '',
    );
  }
}
