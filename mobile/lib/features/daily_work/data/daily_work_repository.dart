import 'dart:io';

import '../domain/daily_work_models.dart';

abstract class DailyWorkRepository {
  Future<List<DailyWorkEntry>> fetchEntries({DateTime? from, DateTime? to});

  Future<DailyWorkEntry> createEntry({
    required String title,
    required DailyWorkCategory category,
    required String location,
    required String description,
    required DateTime startTime,
    DateTime? completionTime,
    required List<File> photos,
    String? remarks,
  });
}
