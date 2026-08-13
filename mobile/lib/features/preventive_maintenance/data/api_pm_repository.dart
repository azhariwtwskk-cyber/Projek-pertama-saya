import 'dart:io';

import 'package:dio/dio.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/utils/image_utils.dart';
import '../domain/pm_models.dart';
import 'pm_repository.dart';

class ApiPmRepository implements PmRepository {
  ApiPmRepository(this._client);
  final ApiClient _client;

  // Local checklist-tick state is kept client-side and only submitted as
  // part of `complete` — there is no per-item CPMSPro endpoint in section
  // 32 — so toggles just re-fetch and patch in memory.
  final Map<String, PmTask> _cache = {};

  PmTask _fromJson(Map<String, dynamic> json) {
    final task = PmTask(
      id: json['id'] as String,
      assetName: json['asset_name'] as String? ?? '',
      assetId: json['asset_id'] as String? ?? '',
      location: json['location'] as String? ?? '',
      pmType: json['pm_type'] as String? ?? '',
      scheduledDate: DateTime.parse(json['scheduled_date'] as String),
      instructions: json['instructions'] as String? ?? '',
      status: PmStatus.values.firstWhere((s) => s.name == json['status'], orElse: () => PmStatus.upcoming),
      checklist: ((json['checklist'] as List<dynamic>?) ?? [])
          .map((e) => PmChecklistItem(
                id: e['id'] as String,
                label: e['label'] as String,
                isMandatory: e['is_mandatory'] as bool? ?? true,
                isChecked: e['is_checked'] as bool? ?? false,
              ))
          .toList(),
      requiresPhotoEvidence: json['requires_photo_evidence'] as bool? ?? true,
    );
    _cache[task.id] = task;
    return task;
  }

  @override
  Future<List<PmTask>> fetchTasks() {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.pmTasks),
      (data) => ((data as Map<String, dynamic>)['tasks'] as List<dynamic>).map((e) => _fromJson(e as Map<String, dynamic>)).toList(),
    );
  }

  @override
  Future<PmTask> fetchTask(String id) {
    return _client.request((dio) => dio.get(ApiEndpoints.pmTask(id)), (data) => _fromJson(data as Map<String, dynamic>));
  }

  @override
  Future<PmTask> toggleChecklistItem(String taskId, String itemId, bool checked) async {
    final task = _cache[taskId] ?? await fetchTask(taskId);
    final updated = PmTask(
      id: task.id,
      assetName: task.assetName,
      assetId: task.assetId,
      location: task.location,
      pmType: task.pmType,
      scheduledDate: task.scheduledDate,
      instructions: task.instructions,
      status: task.status,
      checklist: task.checklist.map((c) => c.id == itemId ? c.copyWith(isChecked: checked) : c).toList(),
      requiresPhotoEvidence: task.requiresPhotoEvidence,
    );
    _cache[taskId] = updated;
    return updated;
  }

  @override
  Future<PmTask> completeTask(String taskId, {required List<File> evidencePhotos, String? notes}) async {
    final task = _cache[taskId] ?? await fetchTask(taskId);
    final compressedPaths = <String>[];
    for (final photo in evidencePhotos) {
      compressedPaths.add((await ImageUtils.compressForUpload(photo)).path);
    }
    final formData = FormData.fromMap({
      'notes': notes,
      'checklist': task.checklist.map((c) => {'id': c.id, 'is_checked': c.isChecked}).toList(),
      for (var i = 0; i < compressedPaths.length; i++) 'photos[$i]': await MultipartFile.fromFile(compressedPaths[i]),
    });
    return _client.request(
      (dio) => dio.post(ApiEndpoints.pmTaskComplete(taskId), data: formData),
      (data) => _fromJson(data as Map<String, dynamic>),
    );
  }
}
