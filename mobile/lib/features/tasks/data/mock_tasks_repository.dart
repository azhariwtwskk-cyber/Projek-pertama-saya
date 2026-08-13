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
    if (i == -1) throw const ApiException(ApiFailureType.notFound, 'Task not found.');
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
  Future<StaffTask> acceptTask(String id) async {
    await Future.delayed(const Duration(milliseconds: 400));
    final i = _indexOf(id);
    _tasks[i] = _tasks[i].copyWith(status: TaskStatus.accepted);
    return _tasks[i];
  }

  @override
  Future<StaffTask> startTask(String id) async {
    await Future.delayed(const Duration(milliseconds: 400));
    final i = _indexOf(id);
    _tasks[i] = _tasks[i].copyWith(status: TaskStatus.inProgress);
    return _tasks[i];
  }

  @override
  Future<EvidencePhoto> uploadEvidence({
    required String taskId,
    required File file,
    String? beforePhotoId,
    double? gpsLat,
    double? gpsLng,
  }) async {
    final compressed = await ImageUtils.compressForUpload(file);
    final online = await _connectivity.isOnline;
    final i = _indexOf(taskId);

    if (!online) {
      // Persist the compressed file permanently (not just in the OS temp
      // dir) so it survives even if the app is killed before connectivity
      // returns, then queue the upload — never lose the staff photo.
      final docsDir = await getApplicationDocumentsDirectory();
      final permanentPath = p.join(docsDir.path, 'pending_evidence', p.basename(compressed.path));
      await Directory(p.dirname(permanentPath)).create(recursive: true);
      final permanentFile = await compressed.copy(permanentPath);

      final photo = EvidencePhoto(
        id: 'local_${const Uuid().v4()}',
        url: permanentFile.path,
        uploadedAt: DateTime.now(),
        gpsLat: gpsLat,
        gpsLng: gpsLng,
        isLocalPending: true,
        localPath: permanentFile.path,
      );
      _tasks[i] = _tasks[i].copyWith(afterPhotos: [..._tasks[i].afterPhotos, photo]);

      await _enqueue(PendingSyncItem(
        id: photo.id,
        type: PendingSyncType.photoEvidence,
        summary: '${_tasks[i].taskNumber} — after photo',
        endpoint: '/api/v1/staff/tasks/$taskId/evidence',
        method: 'POST',
        payload: {
          'task_id': taskId,
          'before_photo_id': beforePhotoId,
          'gps_lat': gpsLat,
          'gps_lng': gpsLng,
          'device_timestamp': DateTime.now().toIso8601String(),
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
      gpsLat: gpsLat,
      gpsLng: gpsLng,
    );
    _tasks[i] = _tasks[i].copyWith(afterPhotos: [..._tasks[i].afterPhotos, photo]);
    return photo;
  }

  @override
  Future<StaffTask> completeTask({
    required String taskId,
    required String remarks,
    String? materialsUsed,
    int? timeSpentMinutes,
  }) async {
    await Future.delayed(const Duration(milliseconds: 700));
    final i = _indexOf(taskId);
    if (_tasks[i].requiresEvidence && _tasks[i].afterPhotos.isEmpty) {
      throw const ApiException(
        ApiFailureType.validation,
        'Please upload completion evidence before completing this task.',
      );
    }
    _tasks[i] = _tasks[i].copyWith(
      status: TaskStatus.pendingVerification,
      completionRemarks: remarks,
      materialsUsed: materialsUsed,
      timeSpentMinutes: timeSpentMinutes,
      rejectionReason: '',
    );
    return _tasks[i];
  }
}
