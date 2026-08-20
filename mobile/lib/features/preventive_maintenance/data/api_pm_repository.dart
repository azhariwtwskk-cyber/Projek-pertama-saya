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
  final Map<String, PmTask> _cache = {};

  PmStatus _status(String value, DateTime due) {
    if (value.toLowerCase().contains('overdue') ||
        due.isBefore(DateTime.now())) {
      return PmStatus.overdue;
    }
    final today = DateTime.now();
    if (due.year == today.year &&
        due.month == today.month &&
        due.day == today.day) {
      return PmStatus.today;
    }
    return PmStatus.upcoming;
  }

  PmTask _fromList(Map<String, dynamic> j) {
    final due = DateTime.tryParse((j['next_due_date'] ?? '').toString()) ??
        DateTime.now();
    final task = PmTask(
      id: (j['id'] ?? '').toString(),
      assetName: (j['asset_name'] ?? '').toString(),
      assetId: (j['id'] ?? '').toString(),
      location: '',
      pmType: (j['schedule_name'] ?? 'Preventive Maintenance').toString(),
      scheduledDate: due,
      instructions: '',
      status: _status((j['due_status'] ?? '').toString(), due),
      checklist: const [],
      requiresPhotoEvidence: true,
    );
    _cache[task.id] = task;
    return task;
  }

  @override
  Future<List<PmTask>> fetchTasks() => _client.request(
        (dio) => dio.get(ApiEndpoints.pmTasks),
        (data) {
          final root = data is Map
              ? Map<String, dynamic>.from(data)
              : <String, dynamic>{};
          final rows =
              root['schedules'] is List ? root['schedules'] as List : const [];
          return rows
              .whereType<Map>()
              .map((e) => _fromList(Map<String, dynamic>.from(e)))
              .toList();
        },
      );

  @override
  Future<PmTask> fetchTask(String id) => _client.request(
        (dio) => dio.get(ApiEndpoints.pmTask(id), queryParameters: {'id': id}),
        (data) {
          final root = data is Map
              ? Map<String, dynamic>.from(data)
              : <String, dynamic>{};
          final j = root['schedule'] is Map
              ? Map<String, dynamic>.from(root['schedule'] as Map)
              : root;
          final due =
              DateTime.tryParse((j['next_due_date'] ?? '').toString()) ??
                  DateTime.now();
          final task = PmTask(
            id: (j['id'] ?? id).toString(),
            assetName: (j['asset_name'] ?? '').toString(),
            assetId: (j['id'] ?? id).toString(),
            location: '',
            pmType: (j['schedule_name'] ?? 'Preventive Maintenance').toString(),
            scheduledDate: due,
            instructions: (j['instructions'] ?? '').toString(),
            status: _status('', due),
            checklist: const [],
            requiresPhotoEvidence: true,
          );
          _cache[id] = task;
          return task;
        },
      );

  @override
  Future<PmTask> toggleChecklistItem(
          String taskId, String itemId, bool checked) async =>
      _cache[taskId] ?? await fetchTask(taskId);

  @override
  Future<PmTask> completeTask(String taskId,
      {required List<File> evidencePhotos, String? notes}) async {
    final form = FormData.fromMap({
      'schedule_id': taskId,
      'completed_date': DateTime.now().toIso8601String().split('T').first,
      'work_notes': (notes == null || notes.trim().isEmpty)
          ? 'Completed via CPMSPro Workforce'
          : notes.trim(),
      'result': 'Completed',
    });
    if (evidencePhotos.isNotEmpty) {
      final compressed =
          await ImageUtils.compressForUpload(evidencePhotos.first);
      form.files.add(
          MapEntry('evidence', await MultipartFile.fromFile(compressed.path)));
    }
    await _client.request(
      (dio) => dio.post(ApiEndpoints.pmTaskComplete(taskId), data: form),
      (_) => true,
    );
    return fetchTask(taskId);
  }
}
