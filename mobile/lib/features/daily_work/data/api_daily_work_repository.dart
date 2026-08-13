import 'dart:io';

import 'package:dio/dio.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/utils/image_utils.dart';
import '../domain/daily_work_models.dart';
import 'daily_work_repository.dart';

class ApiDailyWorkRepository implements DailyWorkRepository {
  ApiDailyWorkRepository(this._client);
  final ApiClient _client;

  DailyWorkEntry _fromJson(Map<String, dynamic> json) => DailyWorkEntry(
        id: json['id'] as String,
        title: json['title'] as String,
        category: DailyWorkCategory.values.firstWhere(
          (c) => c.name == json['category'],
          orElse: () => DailyWorkCategory.other,
        ),
        location: json['location'] as String? ?? '',
        description: json['description'] as String? ?? '',
        startTime: DateTime.parse(json['start_time'] as String),
        completionTime: json['completion_time'] == null ? null : DateTime.parse(json['completion_time'] as String),
        photoCount: json['photo_count'] as int? ?? 0,
        remarks: json['remarks'] as String?,
        status: json['status'] == 'verified' ? DailyWorkStatus.verified : DailyWorkStatus.submitted,
      );

  @override
  Future<List<DailyWorkEntry>> fetchEntries({DateTime? from, DateTime? to}) {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.dailyWork, queryParameters: {
        if (from != null) 'from': from.toIso8601String(),
        if (to != null) 'to': to.toIso8601String(),
      }),
      (data) => ((data as Map<String, dynamic>)['entries'] as List<dynamic>)
          .map((e) => _fromJson(e as Map<String, dynamic>))
          .toList(),
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
    final compressedPaths = <String>[];
    for (final photo in photos) {
      compressedPaths.add((await ImageUtils.compressForUpload(photo)).path);
    }
    final formData = FormData.fromMap({
      'title': title,
      'category': category.name,
      'location': location,
      'description': description,
      'start_time': startTime.toIso8601String(),
      'completion_time': completionTime?.toIso8601String(),
      'remarks': remarks,
      for (var i = 0; i < compressedPaths.length; i++) 'photos[$i]': await MultipartFile.fromFile(compressedPaths[i]),
    });
    return _client.request(
      (dio) => dio.post(ApiEndpoints.dailyWork, data: formData),
      (data) => _fromJson(data as Map<String, dynamic>),
    );
  }
}
