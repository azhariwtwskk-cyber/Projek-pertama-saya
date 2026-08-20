import 'dart:io';

import 'package:dio/dio.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/utils/image_utils.dart';
import '../domain/task_models.dart';
import 'tasks_repository.dart';

/// Talks to the real CPMSPro work-order endpoints
/// (`staff/tasks.php`, `staff/task-photo.php`,
/// `staff/daily-work/submit.php`) — see
/// mobile/docs/INTEGRATION_REPAIR_REPORT.md for the confirmed contract.
/// There is no by-ID work-order detail endpoint and no accept/start/
/// complete endpoints on the real backend, so this class never invents
/// calls to paths that don't exist.
class ApiTasksRepository implements TasksRepository {
  ApiTasksRepository(this._client);

  final ApiClient _client;

  @override
  Future<List<StaffTask>> fetchTasks() async {
    return _client.request<List<StaffTask>>(
      (dio) => dio.get(ApiEndpoints.staffTasks),
      (data) {
        final root = _asMap(data);
        final rows = _asList(root['tasks']);
        final tasks = <StaffTask>[];
        for (final item in rows) {
          if (item is! Map) continue;
          try {
            tasks.add(StaffTask.fromJson(Map<String, dynamic>.from(item)));
          } catch (_) {
            // One malformed work-order row must never take down the
            // whole task list.
            continue;
          }
        }
        return tasks;
      },
    );
  }

  @override
  Future<StaffTask> fetchTask(String id) async {
    // The real backend has no `GET staff/tasks.php?id=` (or similar)
    // detail endpoint — `staff/tasks.php` always returns the full list.
    // Rather than invent a detail call, re-fetch the list and find the
    // matching work order by its reference (StaffTask.id).
    final tasks = await fetchTasks();
    for (final task in tasks) {
      if (task.id == id) return task;
    }
    throw const ApiException(ApiFailureType.notFound, 'Task not found.');
  }

  @override
  Future<EvidencePhoto> uploadEvidence({
    required String taskId,
    required File file,
    required String imageType,
  }) async {
    final compressed = await ImageUtils.compressForUpload(file);
    final formData = FormData.fromMap({
      'work_order_reference': taskId,
      'image_type': imageType,
      'photo': await MultipartFile.fromFile(compressed.path),
    });

    return _client.request(
      (dio) => dio.post(ApiEndpoints.staffTaskEvidence(taskId), data: formData),
      (data) => EvidencePhoto.fromUploadResponse(_asMap(data)),
    );
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
    final workOrderId = task.databaseId;
    if (workOrderId == null) {
      throw const ApiException(
        ApiFailureType.validation,
        'This task cannot be linked to a Daily Work record (missing work order id).',
      );
    }

    Future<List<MultipartFile>> compressAll(List<File> files) async {
      final out = <MultipartFile>[];
      for (final f in files.take(3)) {
        final compressed = await ImageUtils.compressForUpload(f);
        out.add(await MultipartFile.fromFile(compressed.path));
      }
      return out;
    }

    final beforeParts = await compressAll(beforePhotos);
    final duringParts = await compressAll(duringPhotos);
    final afterParts = await compressAll(afterPhotos);

    final locationParts = task.location.split('–');
    final blockLocation = locationParts.first.trim().isEmpty
        ? task.location
        : locationParts.first.trim();
    final specificLocation = locationParts.length > 1
        ? locationParts.sublist(1).join('–').trim()
        : '';
    final now = DateTime.now();

    final form = FormData.fromMap({
      'work_order_id': workOrderId,
      'work_date':
          '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}',
      // The linked work-order flow always uses "Maintenance" — the real
      // category list (`staff/daily-work/options.php`) is free text with
      // no server-side enum enforcement, but picking a category here
      // would be a new UI surface beyond this repair's scope; staff can
      // still add a fuller category-specific Daily Work entry separately
      // if needed.
      'work_category': 'Maintenance',
      'block_location': blockLocation,
      if (specificLocation.isNotEmpty) 'specific_location': specificLocation,
      'work_description': workDescription,
      if (materialsUsed != null && materialsUsed.isNotEmpty)
        'materials_used': materialsUsed,
      if (issueNotes != null && issueNotes.isNotEmpty)
        'issue_notes': issueNotes,
      'work_status': workStatus,
      if (beforeParts.isNotEmpty) 'before_images[]': beforeParts,
      if (duringParts.isNotEmpty) 'during_images[]': duringParts,
      if (afterParts.isNotEmpty) 'after_images[]': afterParts,
    });

    await _client.request(
      (dio) => dio.post(ApiEndpoints.dailyWorkSubmit, data: form),
      (_) => null,
    );
  }

  static Map<String, dynamic> _asMap(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return <String, dynamic>{};
  }

  static List<dynamic> _asList(dynamic value) =>
      value is List ? value : const <dynamic>[];
}
