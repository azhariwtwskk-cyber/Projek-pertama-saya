import 'dart:io';

import 'package:dio/dio.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/utils/image_utils.dart';
import '../domain/task_models.dart';
import 'tasks_repository.dart';

/// Real CPMSPro-backed implementation. Task JSON parsing lives in
/// [StaffTask.fromJson] because the same shape is returned by list,
/// detail, accept, start, complete and the dashboard endpoints.
class ApiTasksRepository implements TasksRepository {
  ApiTasksRepository(this._client);

  final ApiClient _client;

  @override
  Future<List<StaffTask>> fetchTasks() {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.staffTasks),
      (data) => ((data as Map<String, dynamic>)['tasks'] as List<dynamic>)
          .map((e) => StaffTask.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }

  @override
  Future<StaffTask> fetchTask(String id) {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.staffTask(id)),
      (data) => StaffTask.fromJson(data as Map<String, dynamic>),
    );
  }

  @override
  Future<StaffTask> acceptTask(String id) {
    return _client.request(
      (dio) => dio.post(ApiEndpoints.staffTaskAccept(id)),
      (data) => StaffTask.fromJson(data as Map<String, dynamic>),
    );
  }

  @override
  Future<StaffTask> startTask(String id) {
    return _client.request(
      (dio) => dio.post(ApiEndpoints.staffTaskStart(id)),
      (data) => StaffTask.fromJson(data as Map<String, dynamic>),
    );
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
    final formData = FormData.fromMap({
      'before_photo_id': beforePhotoId,
      'gps_lat': gpsLat,
      'gps_lng': gpsLng,
      'device_timestamp': DateTime.now().toIso8601String(),
      'file': await MultipartFile.fromFile(compressed.path),
    });
    return _client.request(
      (dio) => dio.post(ApiEndpoints.staffTaskEvidence(taskId), data: formData),
      (data) => EvidencePhoto.fromJson(data as Map<String, dynamic>),
    );
  }

  @override
  Future<StaffTask> completeTask({
    required String taskId,
    required String remarks,
    String? materialsUsed,
    int? timeSpentMinutes,
  }) {
    return _client.request(
      (dio) => dio.post(ApiEndpoints.staffTaskComplete(taskId), data: {
        'remarks': remarks,
        'materials_used': materialsUsed,
        'time_spent_minutes': timeSpentMinutes,
      }),
      (data) => StaffTask.fromJson(data as Map<String, dynamic>),
    );
  }
}
