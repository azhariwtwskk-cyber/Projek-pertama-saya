import 'dart:io';
import 'package:dio/dio.dart';
import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/config/app_config.dart';
import '../../../core/utils/image_utils.dart';
import '../domain/daily_work_models.dart';
import 'daily_work_repository.dart';

class ApiDailyWorkRepository implements DailyWorkRepository {
  ApiDailyWorkRepository(this._client);
  final ApiClient _client;

  DailyWorkCategory _category(String value) {
    final v = value.toLowerCase();
    return DailyWorkCategory.values.firstWhere(
      (c) => c.name.toLowerCase() == v.replaceAll(' ', ''),
      orElse: () => DailyWorkCategory.other,
    );
  }

  DailyWorkStatus _status(Map<String, dynamic> j) {
    final raw = (j['status'] ?? '').toString();
    if (raw == 'Rejected') return DailyWorkStatus.rejected;
    if (raw == 'Verified' || j['verified'] == true) {
      return DailyWorkStatus.verified;
    }
    return DailyWorkStatus.submitted;
  }

  DailyWorkEntry _fromJson(Map<String, dynamic> j) {
    final date =
        DateTime.tryParse((j['date'] ?? j['work_date'] ?? '').toString()) ??
            DateTime.now();
    final images = (j['images'] as List<dynamic>?) ?? const [];
    final photoUrls = images
        .whereType<Map>()
        .map((e) => AppConfig.resolveUrl((e['url'] ?? '').toString()))
        .where((u) => u.isNotEmpty)
        .toList();
    final remarks = (j['supervisor_remarks'] ?? '').toString().trim();
    return DailyWorkEntry(
      id: (j['id'] ?? j['reference'] ?? '').toString(),
      title: (j['title'] ?? j['reference'] ?? 'Daily Work').toString(),
      category: _category((j['category'] ?? '').toString()),
      location: (j['location'] ?? '').toString(),
      description: (j['description'] ?? '').toString(),
      startTime: date,
      completionTime: null,
      photoCount: photoUrls.length,
      photoUrls: photoUrls,
      status: _status(j),
      supervisorRemarks: remarks.isEmpty ? null : remarks,
    );
  }

  @override
  Future<List<DailyWorkEntry>> fetchEntries({DateTime? from, DateTime? to}) {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.dailyWorkList),
      (data) {
        final root =
            data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
        final rows = root['logs'] is List ? root['logs'] as List : const [];
        return rows
            .whereType<Map>()
            .map((e) => _fromJson(Map<String, dynamic>.from(e)))
            .toList();
      },
    );
  }

  @override
  Future<DailyWorkEntry> createEntry({
    required String title,
    required DailyWorkCategory category,
    required String location,
    required String description,
    required DateTime startTime,
    DateTime? completionTime,
    required List<File> photos,
    String? remarks,
  }) async {
    final files = <MultipartFile>[];
    for (final photo in photos.take(3)) {
      final compressed = await ImageUtils.compressForUpload(photo);
      files.add(await MultipartFile.fromFile(compressed.path));
    }
    final parts = location.split('-');
    final form = FormData.fromMap({
      'work_date': startTime.toIso8601String().split('T').first,
      'work_category': category.label,
      'block_location':
          parts.first.trim().isEmpty ? location : parts.first.trim(),
      'specific_location':
          parts.length > 1 ? parts.sublist(1).join('-').trim() : '',
      'start_time':
          '${startTime.hour.toString().padLeft(2, '0')}:${startTime.minute.toString().padLeft(2, '0')}',
      'end_time': completionTime == null
          ? ''
          : '${completionTime.hour.toString().padLeft(2, '0')}:${completionTime.minute.toString().padLeft(2, '0')}',
      'work_description': description.isEmpty ? title : description,
      'materials_used': '',
      'issue_notes': remarks ?? '',
      'work_status': completionTime != null ? 'Completed' : 'In Progress',
      if (files.isNotEmpty) 'after_images[]': files,
    });
    return _client.request(
      (dio) => dio.post(ApiEndpoints.dailyWorkSubmit, data: form),
      (data) {
        final root =
            data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
        return DailyWorkEntry(
          id: (root['reference'] ?? '').toString(),
          title: title,
          category: category,
          location: location,
          description: description,
          startTime: startTime,
          completionTime: completionTime,
          photoCount: photos.length,
          remarks: remarks,
          status: DailyWorkStatus.submitted,
        );
      },
    );
  }
}
